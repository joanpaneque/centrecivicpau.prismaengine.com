<?php

namespace App\Services\Sync;

use App\Enums\DeviceType;
use App\Models\CashSession;
use App\Models\Category;
use App\Models\Device;
use App\Models\DiningTable;
use App\Models\FloorElement;
use App\Models\KitchenTicket;
use App\Models\KitchenTicketItem;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Printer;
use App\Models\PrintJob;
use App\Models\Product;
use App\Models\ProductionDestination;
use App\Models\Reservation;
use App\Models\SetMenu;
use App\Models\SetMenuSectionItem;
use App\Models\Shift;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Zone;
use App\Services\TimeTracking\ClockQrService;
use App\Services\TimeTracking\WorkedTime;
use App\Support\AppSettings;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Builds what a device needs to work offline. A pull returns only rows touched since the
 * cursor (minus an overlap to cover transactions that committed late); the client upserts,
 * so receiving a row twice is harmless.
 */
class SnapshotBuilder
{
    public const OVERLAP_SECONDS = 15;

    public function __construct(
        private readonly ClockQrService $clockQr,
        private readonly WorkedTime $workedTime,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, ?Device $device, ?CarbonImmutable $since = null): array
    {
        $now = CarbonImmutable::now();
        $from = $since?->subSeconds(self::OVERLAP_SECONDS);

        return [
            'full' => $since === null,
            'serverTime' => $now->toIso8601String(),
            'cursor' => $now->toIso8601String(),
            'me' => $this->me($user),
            'device' => $this->device($device),
            'settings' => AppSettings::forDevices(),
            'staff' => $this->staff(),
            'zones' => $this->changed(Zone::withTrashed(), $from)->orderBy('sort')->get()->map(fn (Zone $z) => $this->zone($z))->all(),
            'tables' => $this->changed(DiningTable::withTrashed(), $from)->orderBy('sort')->get()->map(fn (DiningTable $t) => $this->table($t))->all(),
            'floorElements' => $this->changed(FloorElement::withTrashed(), $from)->orderBy('sort')->get()->map(fn (FloorElement $e) => $this->floorElement($e))->all(),
            'destinations' => ProductionDestination::query()->with('printers:id')->orderBy('sort')->get()->map(fn (ProductionDestination $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'code' => $d->code,
                'mode' => $d->mode,
                'printerIds' => $d->printers->pluck('id')->all(),
            ])->all(),
            'printers' => Printer::query()->with('destinations:id')->orderBy('name')->get()->map(fn (Printer $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'type' => $p->type,
                'active' => $p->active,
                'isTicketPrinter' => $p->is_ticket_printer,
                'paperWidth' => $p->paper_width,
                'systemName' => $p->system_name,
                'destinationIds' => $p->destinations->pluck('id')->all(),
            ])->all(),
            'printJobs' => $device?->type === DeviceType::Cashier ? $this->pendingPrintJobs($device) : null,
            'categories' => $this->changed(Category::withTrashed()->with('modifierGroups:id'), $from)->orderBy('sort')->get()->map(fn (Category $c) => $this->category($c))->all(),
            'products' => $this->changed(Product::withTrashed()->with('modifierGroups:id'), $from)->orderBy('sort')->get()->map(fn (Product $p) => $this->product($p))->all(),
            'modifierGroups' => $this->changed(ModifierGroup::withTrashed()->with('modifiers'), $from)->orderBy('sort')->get()->map(fn (ModifierGroup $g) => $this->modifierGroup($g))->all(),
            'setMenus' => $this->changed(SetMenu::withTrashed()->with('sections.items.product'), $from)->orderBy('sort')->get()->map(fn (SetMenu $m) => $this->setMenu($m))->all(),
            'orders' => $this->orders($from),
            'kitchenTickets' => $this->kitchenTickets($from),
            'reservations' => $this->reservations($from),
            'cashier' => $device?->type === DeviceType::Cashier ? $this->cashier($device) : null,
            'myShifts' => $this->shifts($user),
            'myClock' => $this->workedTime->clockState($user),
            'clock' => $device && ($device->type === DeviceType::Clock || $device->type === DeviceType::Cashier) ? [
                'secret' => AppSettings::clockSecret(),
                'seconds' => $this->clockQr->seconds(),
                'url' => url('/tpv').'#/fitxar',
            ] : null,
        ];
    }

    /**
     * Pending (and this device's in-progress) jobs for the cashier print station.
     *
     * @return list<array<string, mixed>>
     */
    private function pendingPrintJobs(Device $device): array
    {
        $stale = now()->subMinutes(PrintJob::STALE_CLAIM_MINUTES);

        $jobs = PrintJob::query()
            ->with('printer:id,system_name,paper_width')
            ->where(function ($query) use ($device, $stale) {
                $query->where('status', 'pending')
                    ->orWhere(function ($query) use ($device, $stale) {
                        $query->where('status', 'printing')
                            ->where(function ($query) use ($device, $stale) {
                                $query->where('claimed_by_device_id', $device->id)
                                    ->orWhere('updated_at', '<', $stale);
                            });
                    });
            })
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(fn (PrintJob $job): array => [
                'uuid' => $job->uuid,
                'printerId' => $job->printer_id,
                'printerName' => $job->printer_name,
                'systemName' => $job->printer?->system_name,
                'kind' => $job->kind,
                'title' => $job->title,
                'document' => $job->document,
                'paperWidth' => $job->printer?->paper_width,
                'createdAt' => $job->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return array_values($jobs);
    }

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function changed(Builder $query, ?CarbonImmutable $from): Builder
    {
        if ($from === null) {
            return $query->where(fn (Builder $q) => $q->whereNull($q->getModel()->qualifyColumn('deleted_at'))->orWhere($q->getModel()->qualifyColumn('deleted_at'), '>=', now()->subDays(1)));
        }

        return $query->where($query->getModel()->qualifyColumn('updated_at'), '>=', $from);
    }

    /**
     * @return array<string, mixed>
     */
    public function me(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'role' => $user->role->value,
            'locale' => $user->locale,
            'color' => $user->color,
            'email' => $user->email,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function device(?Device $device): ?array
    {
        if ($device === null) {
            return null;
        }

        return [
            'uuid' => $device->uuid,
            'name' => $device->name,
            'type' => $device->type->value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function staff(): array
    {
        return User::query()->where('active', true)->orderBy('name')->get()->map(fn (User $u) => [
            'id' => $u->id,
            'name' => $u->name,
            'role' => $u->role->value,
            'color' => $u->color,
            'locale' => $u->locale,
            'pinDigest' => $u->pin_digest,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function zone(Zone $zone): array
    {
        return [
            'id' => $zone->id,
            'name' => $zone->name,
            'slug' => $zone->slug,
            'appliesTerraceSurcharge' => $zone->applies_terrace_surcharge,
            'isBar' => $zone->is_bar,
            'sort' => $zone->sort,
            'deleted' => $zone->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function floorElement(FloorElement $element): array
    {
        return [
            'id' => $element->id,
            'zoneId' => $element->zone_id,
            'type' => $element->type,
            'label' => $element->label,
            'x' => $element->x,
            'y' => $element->y,
            'width' => $element->width,
            'height' => $element->height,
            'rotation' => $element->rotation,
            'color' => $element->color,
            'sort' => $element->sort,
            'deleted' => $element->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function table(DiningTable $table): array
    {
        return [
            'id' => $table->id,
            'zoneId' => $table->zone_id,
            'label' => $table->label,
            'seats' => $table->seats,
            'x' => $table->x,
            'y' => $table->y,
            'width' => $table->width,
            'height' => $table->height,
            'rotation' => $table->rotation,
            'shape' => $table->shape,
            'isAuxiliary' => $table->is_auxiliary,
            'sort' => $table->sort,
            'deleted' => $table->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function category(Category $category): array
    {
        return [
            'id' => $category->id,
            'parentId' => $category->parent_id,
            'name' => $category->name,
            'color' => $category->color,
            'destinationId' => $category->production_destination_id,
            'isMenu' => $category->is_menu,
            'active' => $category->active,
            'sort' => $category->sort,
            'modifierGroupIds' => $category->modifierGroups->pluck('id')->all(),
            'deleted' => $category->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(Product $product): array
    {
        return [
            'id' => $product->id,
            'categoryId' => $product->category_id,
            'name' => $product->name,
            'price' => $product->price,
            'vatRate' => $product->vat_rate,
            'photoUrl' => $product->photoUrl(),
            'color' => $product->color,
            'allergens' => $product->allergens ?? [],
            'destinationId' => $product->production_destination_id,
            'active' => $product->active,
            'soldOut' => $product->sold_out,
            'sort' => $product->sort,
            'orderCount' => $product->order_count,
            'modifierGroupIds' => $product->modifierGroups->pluck('id')->all(),
            'deleted' => $product->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function modifierGroup(ModifierGroup $group): array
    {
        return [
            'id' => $group->id,
            'name' => $group->name,
            'multiple' => $group->multiple,
            'required' => $group->required,
            'sort' => $group->sort,
            'modifiers' => $group->modifiers->map(fn ($m) => [
                'id' => $m->id,
                'name' => $m->name,
                'priceDelta' => $m->price_delta,
            ])->values()->all(),
            'deleted' => $group->deleted_at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function setMenu(SetMenu $menu): array
    {
        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'includes' => $menu->includes,
            'price' => $menu->price,
            'vatRate' => $menu->vat_rate,
            'color' => $menu->color,
            'scheduleType' => $menu->schedule_type,
            'weekdays' => $menu->weekdays ?? [],
            'startsOn' => $menu->starts_on?->toDateString(),
            'endsOn' => $menu->ends_on?->toDateString(),
            'active' => $menu->active,
            'sort' => $menu->sort,
            'sections' => $menu->sections->map(fn ($section) => [
                'id' => $section->id,
                'name' => $section->name,
                'choices' => $section->choices,
                'course' => $section->course,
                'items' => $section->items->map(fn (SetMenuSectionItem $item) => [
                    'id' => $item->id,
                    'productId' => $item->product_id,
                    'name' => $item->displayName(),
                    'destinationId' => $item->effectiveDestinationId(),
                    'supplement' => $item->supplement,
                ])->values()->all(),
            ])->values()->all(),
            'deleted' => $menu->deleted_at !== null,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function orders(?CarbonImmutable $from): array
    {
        $query = Order::query()->with(['lines', 'table']);
        $from === null
            ? $query->whereIn('status', Order::ACTIVE_STATUSES)
            : $query->where('updated_at', '>=', $from);

        /** @var Collection<int, Order> $orders */
        $orders = $query->orderBy('opened_at')->get();
        $mergedTargets = Order::query()->whereIn('id', $orders->pluck('merged_into_id')->filter())->pluck('uuid', 'id');

        return $orders->map(fn (Order $order) => $this->order($order, $mergedTargets->get((int) $order->merged_into_id)))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function order(Order $order, ?string $mergedIntoUuid = null): array
    {
        $lineUuids = $order->lines->pluck('uuid', 'id');

        return [
            'uuid' => $order->uuid,
            'tableId' => $order->dining_table_id,
            'status' => $order->status,
            'guests' => $order->guests,
            'label' => $order->label,
            'openedBy' => $order->opened_by,
            'openedAt' => $order->opened_at->toIso8601String(),
            'billRequestedAt' => $order->bill_requested_at?->toIso8601String(),
            'closedAt' => $order->closed_at?->toIso8601String(),
            'mergedIntoUuid' => $mergedIntoUuid,
            'reservationUuid' => $order->reservation_uuid,
            'lines' => $order->lines->sortBy('id')->map(fn (OrderLine $line) => [
                'uuid' => $line->uuid,
                'parentUuid' => $line->parent_line_id ? $lineUuids->get($line->parent_line_id) : null,
                'productId' => $line->product_id,
                'setMenuId' => $line->set_menu_id,
                'destinationId' => $line->production_destination_id,
                'name' => $line->name,
                'quantity' => $line->quantity,
                'unitPrice' => $line->unit_price,
                'vatRate' => $line->vat_rate,
                'modifiers' => array_map(fn (array $m) => ['id' => $m['id'], 'name' => $m['name'], 'priceDelta' => $m['price_delta']], $line->modifiers ?? []),
                'note' => $line->note,
                'course' => $line->course,
                'discountType' => $line->discount_type,
                'discountValue' => $line->discount_type === 'percent' ? $line->discount_value / 100 : $line->discount_value,
                'discountReason' => $line->discount_reason,
                'voided' => $line->voided_at !== null,
                'voidReason' => $line->void_reason,
                'paidQuantity' => $line->paid_quantity,
                'createdBy' => $line->created_by,
                'sentAt' => $line->sent_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function kitchenTickets(?CarbonImmutable $from): array
    {
        $query = KitchenTicket::query()->with(['items', 'order:id,uuid']);

        $from === null
            ? $query->where(fn (Builder $q) => $q->where('status', '!=', 'served')->orWhere('served_at', '>=', now()->subHour()))
            : $query->where('updated_at', '>=', $from);

        return $query->orderBy('sent_at')->get()->map(function (KitchenTicket $ticket) {
            $lineUuids = OrderLine::query()->whereIn('id', $ticket->items->pluck('order_line_id')->filter())->pluck('uuid', 'id');

            return [
                'uuid' => $ticket->uuid,
                'orderUuid' => $ticket->order->uuid,
                'destinationId' => $ticket->production_destination_id,
                'tableLabel' => $ticket->table_label,
                'course' => $ticket->course,
                'held' => $ticket->held,
                'status' => $ticket->status,
                'createdBy' => $ticket->created_by,
                'sentAt' => $ticket->sent_at->toIso8601String(),
                'startedAt' => $ticket->started_at?->toIso8601String(),
                'readyAt' => $ticket->ready_at?->toIso8601String(),
                'servedAt' => $ticket->served_at?->toIso8601String(),
                'items' => $ticket->items->map(fn (KitchenTicketItem $item) => [
                    'lineUuid' => $item->order_line_id ? $lineUuids->get($item->order_line_id) : null,
                    'name' => $item->name,
                    'quantity' => $item->quantity,
                    'modifiers' => array_map(fn (array $m) => ['id' => $m['id'] ?? null, 'name' => $m['name'] ?? [], 'priceDelta' => $m['price_delta'] ?? 0], $item->modifiers ?? []),
                    'note' => $item->note,
                    'voided' => $item->voided,
                ])->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function reservations(?CarbonImmutable $from): array
    {
        $query = Reservation::withTrashed();

        $from === null
            ? $query->whereNull('deleted_at')->whereBetween('reserved_at', [now()->startOfDay()->subDay(), now()->addDays(30)])
            : $query->where('updated_at', '>=', $from);

        return $query->orderBy('reserved_at')->get()->map(fn (Reservation $r) => [
            'uuid' => $r->uuid,
            'name' => $r->name,
            'phone' => $r->phone,
            'partySize' => $r->party_size,
            'reservedAt' => $r->reserved_at->toIso8601String(),
            'durationMinutes' => $r->duration_minutes,
            'zoneId' => $r->zone_id,
            'tableId' => $r->dining_table_id,
            'notes' => $r->notes,
            'status' => $r->status,
            'source' => $r->source,
            'orderUuid' => $r->order_uuid,
            'deleted' => $r->deleted_at !== null,
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function cashier(Device $device): array
    {
        $series = $device->ticketSeries;
        $session = CashSession::query()->where('device_id', $device->id)->whereNull('closed_at')->latest('opened_at')->first();

        return [
            'series' => $series ? ['code' => $series->code, 'lastNumber' => $series->last_number] : null,
            'session' => $session ? [
                'uuid' => $session->uuid,
                'openedAt' => $session->opened_at->toIso8601String(),
                'openingFloat' => $session->opening_float,
                'openedBy' => $session->opened_by,
            ] : null,
            'tickets' => $session
                ? Ticket::query()->with('payments')->where('cash_session_id', $session->id)->orderBy('number')->get()->map(fn (Ticket $t) => $this->ticketSummary($t))->all()
                : [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function ticketSummary(Ticket $ticket): array
    {
        return [
            'uuid' => $ticket->uuid,
            'fullNumber' => $ticket->full_number,
            'number' => $ticket->number,
            'issuedAt' => $ticket->issued_at->toIso8601String(),
            'tableLabel' => $ticket->table_label,
            'total' => $ticket->total,
            'discountTotal' => $ticket->discount_total,
            'surchargeAmount' => $ticket->surcharge_amount,
            'vatBreakdown' => $ticket->vat_breakdown,
            'payments' => $ticket->payments->map(fn ($p) => ['method' => $p->method, 'amount' => $p->amount])->values()->all(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function shifts(User $user): array
    {
        return Shift::query()
            ->with('template')
            ->where('user_id', $user->id)
            ->whereBetween('date', [now()->subDays(7)->toDateString(), now()->addDays(31)->toDateString()])
            ->orderBy('date')->orderBy('start_time')
            ->get()
            ->map(fn (Shift $s) => [
                'id' => $s->id,
                'date' => $s->date->toDateString(),
                'startTime' => substr($s->start_time, 0, 5),
                'endTime' => substr($s->end_time, 0, 5),
                'breakMinutes' => $s->break_minutes,
                'name' => $s->template?->name,
                'color' => $s->template->color ?? '#00056a',
                'notes' => $s->notes,
            ])->values()->all();
    }
}
