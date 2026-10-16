<?php

namespace App\Services\Assistant;

use App\Models\CashSession;
use App\Models\Category;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Reservation;
use App\Models\SupplierInvoice;
use App\Models\Ticket;
use App\Models\TicketLine;
use App\Models\User;
use App\Services\TimeTracking\WorkedTime;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Read-only look at the business for the administrators' assistant: sales reports, tickets,
 * cash closings, staff hours, what is happening now, reservations, voids and supplier
 * spending. Nothing here writes. Every figure is computed here and shown as a report card,
 * so the model only comments on numbers it did not invent.
 */
class BusinessTools
{
    private const MAX_DAYS = 800;

    public function __construct(
        private readonly string $locale,
        private readonly WorkedTime $worked,
    ) {}

    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['live_status', 'sales_report', 'search_tickets', 'get_ticket', 'cash_closings', 'staff_hours', 'reservations_report', 'voids_and_discounts', 'supplier_spending'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $range = [
            'from' => ['type' => 'string', 'description' => 'Fecha inicial YYYY-MM-DD (incluida). Por defecto hoy.'],
            'to' => ['type' => 'string', 'description' => 'Fecha final YYYY-MM-DD (incluida). Por defecto igual que from.'],
        ];

        return [
            self::fn('live_status', 'Qué pasa ahora mismo en el local: mesas abiertas con su importe y tiempo, ventas de hoy, quién está trabajando, reservas de hoy y comandas pendientes en cocina.', []),
            self::fn('sales_report', 'Informe de ventas (tiquets cobrados) agrupado como se pida. Sale como tarjeta con gráfico y tabla.', $range + [
                'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month', 'hour', 'weekday', 'product', 'family', 'waiter', 'payment_method', 'table', 'zone'], 'description' => 'Cómo agrupar: day, week, month, hour (franja horaria), weekday (lunes…domingo), product, family, waiter (camarero), payment_method (efectivo/tarjeta), table, zone.'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de filas (top N) para product/family/table. Por defecto 15.'],
            ], ['group_by']),
            self::fn('search_tickets', 'Busca tiquets cobrados. Todos los filtros son opcionales.', $range + [
                'text' => ['type' => 'string', 'description' => 'Número de tiquet, mesa o producto que contiene.'],
                'min_total' => ['type' => 'number', 'description' => 'Importe mínimo en euros.'],
                'max_total' => ['type' => 'number', 'description' => 'Importe máximo en euros.'],
                'payment_method' => ['type' => 'string', 'enum' => ['cash', 'card']],
                'waiter' => ['type' => 'string', 'description' => 'Nombre (o parte) del camarero.'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo 50. Por defecto 20.'],
            ]),
            self::fn('get_ticket', 'Detalle completo de un tiquet: líneas, pagos, IVA, mesa y camarero.', [
                'number' => ['type' => 'string', 'description' => 'Número del tiquet (completo como T1-000123 o solo el número).'],
            ], ['number']),
            self::fn('cash_closings', 'Cierres de caja (Z): apertura, cierre, ventas, efectivo esperado y contado, y descuadre.', $range),
            self::fn('staff_hours', 'Horas trabajadas por persona según los fichajes (y las planificadas), y quién está trabajando ahora.', $range + [
                'person' => ['type' => 'string', 'description' => 'Nombre (o parte) de una persona; vacío = todo el equipo.'],
            ]),
            self::fn('reservations_report', 'Reservas del periodo: personas, estado, mesa y notas.', $range),
            self::fn('voids_and_discounts', 'Líneas anuladas y descuentos aplicados en el periodo, con importes y quién lo hizo.', $range),
            self::fn('supplier_spending', 'Gasto en facturas de proveedores, por proveedor.', $range + [
                'supplier' => ['type' => 'string', 'description' => 'Nombre (o parte) del proveedor.'],
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return ['type' => 'function', 'function' => ['name' => $name, 'description' => $description, 'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required]]];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview?: array<string, mixed>}
     */
    public function run(string $tool, array $args): array
    {
        try {
            return match ($tool) {
                'live_status' => $this->liveStatus(),
                'sales_report' => $this->sales($args),
                'search_tickets' => $this->searchTickets($args),
                'get_ticket' => $this->ticket($args),
                'cash_closings' => $this->cashClosings($args),
                'staff_hours' => $this->staffHours($args),
                'reservations_report' => $this->reservations($args),
                'voids_and_discounts' => $this->voids($args),
                'supplier_spending' => $this->suppliers($args),
                default => throw new MenuToolError("Herramienta desconocida: {$tool}"),
            };
        } catch (MenuToolError $e) {
            return ['result' => ['error' => $e->getMessage()]];
        }
    }

    // ------------------------------------------------------------------ now

    /**
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function liveStatus(): array
    {
        $now = CarbonImmutable::now();
        $today = $now->startOfDay();
        $orders = Order::query()->with(['table.zone', 'lines'])->whereIn('status', Order::ACTIVE_STATUSES)->orderBy('opened_at')->get();
        $sales = (int) Ticket::query()->where('issued_at', '>=', $today)->sum('total');
        $ticketCount = Ticket::query()->where('issued_at', '>=', $today)->count();
        $working = User::query()->where('active', true)->orderBy('name')->get()
            ->map(fn (User $u) => ['name' => $u->name, 'state' => $this->worked->clockState($u)['state']])
            ->filter(fn (array $u) => $u['state'] !== 'out')->values();
        $reservations = Reservation::query()->whereBetween('reserved_at', [$today, $today->endOfDay()])->whereIn('status', ['confirmed', 'seated'])->get();
        $kitchen = KitchenTicket::query()->whereIn('status', ['pending', 'preparing'])->where('held', false)->count();

        $rows = $orders->map(fn (Order $o) => [
            'table' => $o->table ? $o->table->label.' · '.($o->table->zone->name[$this->locale] ?? $o->table->zone->name['ca'] ?? '') : ($o->label ?: $this->t('Barra', 'Barra')),
            'since' => $o->opened_at->diffForHumans($now, CarbonInterface::DIFF_ABSOLUTE, true),
            'guests' => $o->guests,
            'status' => $o->status === 'bill_requested' ? $this->t('Compte demanat', 'Cuenta pedida') : $this->t('Oberta', 'Abierta'),
            'total' => $this->pendingTotal($o),
        ])->values()->all();

        return [
            'result' => [
                'open_tables' => $rows,
                'sales_today' => $sales / 100,
                'tickets_today' => $ticketCount,
                'working_now' => $working->all(),
                'reservations_today' => $reservations->map(fn (Reservation $r) => ['name' => $r->name, 'time' => $r->reserved_at->format('H:i'), 'people' => $r->party_size])->all(),
                'kitchen_pending' => $kitchen,
            ],
            'preview' => $this->report(
                $this->t('Ara mateix', 'Ahora mismo'),
                $now->isoFormat('dddd D MMMM, HH:mm'),
                [
                    ['label' => $this->t('Vendes d\'avui', 'Ventas de hoy'), 'value' => $sales, 'format' => 'eur'],
                    ['label' => $this->t('Tiquets', 'Tiquets'), 'value' => $ticketCount, 'format' => 'int'],
                    ['label' => $this->t('Taules obertes', 'Mesas abiertas'), 'value' => count($rows), 'format' => 'int'],
                    ['label' => $this->t('Pendent a cuina', 'Pendiente en cocina'), 'value' => $kitchen, 'format' => 'int'],
                    ['label' => $this->t('Treballant', 'Trabajando'), 'value' => $working->count(), 'format' => 'int', 'hint' => $working->pluck('name')->implode(', ')],
                    ['label' => $this->t('Reserves avui', 'Reservas hoy'), 'value' => (int) $reservations->sum('party_size'), 'format' => 'int', 'hint' => $this->t('persones', 'personas')],
                ],
                null,
                [
                    ['key' => 'table', 'label' => $this->t('Taula', 'Mesa')],
                    ['key' => 'since', 'label' => $this->t('Fa', 'Hace')],
                    ['key' => 'status', 'label' => $this->t('Estat', 'Estado')],
                    ['key' => 'total', 'label' => $this->t('Pendent', 'Pendiente'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
            ),
        ];
    }

    private function pendingTotal(Order $order): int
    {
        return (int) $order->lines->whereNull('parent_line_id')->whereNull('voided_at')
            ->sum(fn (OrderLine $l) => $l->quantity > 0 ? (int) round($l->netTotal() * ($l->quantity - $l->paid_quantity) / $l->quantity) : 0);
    }

    // ------------------------------------------------------------------ sales

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function sales(array $args): array
    {
        [$from, $to] = $this->range($args);
        $group = (string) ($args['group_by'] ?? 'day');
        $limit = max(1, min(50, (int) ($args['limit'] ?? 15)));
        $tickets = Ticket::query()->whereBetween('issued_at', [$from, $to])->get(['id', 'order_id', 'issued_at', 'total', 'waiter_id', 'table_label']);
        $total = (int) $tickets->sum('total');
        $count = $tickets->count();

        $rows = match ($group) {
            'product', 'family' => $this->byProduct($tickets, $group, $limit),
            'payment_method' => Payment::query()->whereIn('ticket_id', $tickets->pluck('id'))->get()->groupBy('method')
                ->map(fn (Collection $p, string $method) => ['label' => $method === 'card' ? $this->t('Targeta', 'Tarjeta') : $this->t('Efectiu', 'Efectivo'), 'value' => (int) $p->sum('amount'), 'count' => $p->pluck('ticket_id')->unique()->count()])
                ->sortByDesc('value')->values()->all(),
            default => $this->byTicketGroup($tickets, $group, $from, $to, $limit),
        };

        $labels = [
            'day' => $this->t('per dia', 'por día'), 'week' => $this->t('per setmana', 'por semana'), 'month' => $this->t('per mes', 'por mes'),
            'hour' => $this->t('per franja horària', 'por franja horaria'), 'weekday' => $this->t('per dia de la setmana', 'por día de la semana'),
            'product' => $this->t('per producte', 'por producto'), 'family' => $this->t('per família', 'por familia'), 'waiter' => $this->t('per cambrer', 'por camarero'),
            'payment_method' => $this->t('per forma de pagament', 'por forma de pago'), 'table' => $this->t('per taula', 'por mesa'), 'zone' => $this->t('per zona', 'por zona'),
        ];
        $quantity = in_array($group, ['product', 'family'], true);
        $columns = [['key' => 'label', 'label' => $labels[$group] ?? '']];
        $columns[0]['label'] = match ($group) {
            'product' => $this->t('Producte', 'Producto'), 'family' => $this->t('Família', 'Familia'), 'waiter' => $this->t('Cambrer', 'Camarero'),
            'payment_method' => $this->t('Forma de pagament', 'Forma de pago'), 'table' => $this->t('Taula', 'Mesa'), 'zone' => $this->t('Zona', 'Zona'),
            default => $this->t('Període', 'Periodo'),
        };
        $columns[] = $quantity ? ['key' => 'quantity', 'label' => $this->t('Unitats', 'Unidades'), 'format' => 'qty', 'align' => 'right'] : ['key' => 'count', 'label' => $this->t('Tiquets', 'Tiquets'), 'format' => 'int', 'align' => 'right'];
        $columns[] = ['key' => 'value', 'label' => $this->t('Import', 'Importe'), 'format' => 'eur', 'align' => 'right'];

        return [
            'result' => [
                'period' => [$from->toDateString(), $to->toDateString()],
                'total' => $total / 100,
                'tickets' => $count,
                'average_ticket' => $count ? round($total / $count / 100, 2) : 0,
                'rows' => array_map(fn (array $r) => ['label' => $r['label'], 'amount' => $r['value'] / 100] + array_intersect_key($r, array_flip(['count', 'quantity'])), $rows),
            ],
            'preview' => $this->report(
                $this->t('Vendes ', 'Ventas ').($labels[$group] ?? ''),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Total', 'Total'), 'value' => $total, 'format' => 'eur'],
                    ['label' => $this->t('Tiquets', 'Tiquets'), 'value' => $count, 'format' => 'int'],
                    ['label' => $this->t('Tiquet mitjà', 'Tiquet medio'), 'value' => $count ? (int) round($total / $count) : 0, 'format' => 'eur'],
                ],
                ['kind' => in_array($group, ['day', 'week', 'month', 'hour', 'weekday'], true) ? 'column' : 'bar', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'value'), 'format' => 'eur'],
                $columns,
                $rows,
                [['label' => $this->t('Obre els tiquets', 'Abrir los tiquets'), 'url' => '/gestion/tiquets']],
            ),
        ];
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{label: string, value: int, count: int}>
     */
    private function byTicketGroup(Collection $tickets, string $group, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $weekdays = $this->locale === 'es' ? ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'] : ['Dilluns', 'Dimarts', 'Dimecres', 'Dijous', 'Divendres', 'Dissabte', 'Diumenge'];
        $users = User::query()->whereIn('id', $tickets->pluck('waiter_id')->filter()->unique())->pluck('name', 'id');
        $zones = $group === 'zone' ? $this->zonesByOrder($tickets) : collect();

        $key = fn (Ticket $t): string => match ($group) {
            'week' => $t->issued_at->startOfWeek()->format('Y-m-d'),
            'month' => $t->issued_at->format('Y-m'),
            'hour' => $t->issued_at->format('H'),
            'weekday' => (string) $t->issued_at->dayOfWeekIso,
            'waiter' => (string) ($users[$t->waiter_id] ?? $this->t('Sense cambrer', 'Sin camarero')),
            'table' => (string) ($t->table_label ?: $this->t('Barra / emportar', 'Barra / llevar')),
            'zone' => (string) ($zones[$t->order_id] ?? $this->t('Barra / emportar', 'Barra / llevar')),
            default => $t->issued_at->format('Y-m-d'),
        };

        $groups = $tickets->groupBy($key);

        // Time groups show every slot, also the empty ones, in order.
        if (in_array($group, ['day', 'week', 'month', 'hour', 'weekday'], true)) {
            $slots = match ($group) {
                'hour' => $groups->keys()->sort()->values()->all(),
                'weekday' => ['1', '2', '3', '4', '5', '6', '7'],
                default => $this->slots($group, $from, $to),
            };

            return array_map(function (string $slot) use ($groups, $group, $weekdays) {
                $rows = $groups->get($slot) ?? collect();
                $label = match ($group) {
                    'hour' => $slot.':00–'.$slot.':59',
                    'weekday' => $weekdays[(int) $slot - 1],
                    'week' => $this->t('Setmana del ', 'Semana del ').CarbonImmutable::parse($slot)->format('d/m'),
                    'month' => CarbonImmutable::parse($slot.'-01')->settings(['locale' => $this->locale])->isoFormat('MMMM YYYY'),
                    default => CarbonImmutable::parse($slot)->settings(['locale' => $this->locale])->isoFormat('ddd D/M'),
                };

                return ['label' => $label, 'value' => (int) $rows->sum('total'), 'count' => $rows->count()];
            }, $slots);
        }

        return $groups->map(fn (Collection $rows, string $label) => ['label' => $label, 'value' => (int) $rows->sum('total'), 'count' => $rows->count()])
            ->sortByDesc('value')->take($limit)->values()->all();
    }

    /**
     * @return list<string>
     */
    private function slots(string $group, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $slots = [];
        $cursor = match ($group) {
            'week' => $from->startOfWeek(),
            'month' => $from->startOfMonth(),
            default => $from->startOfDay(),
        };

        while ($cursor <= $to && count($slots) < 400) {
            $slots[] = $group === 'month' ? $cursor->format('Y-m') : $cursor->format('Y-m-d');
            $cursor = match ($group) {
                'week' => $cursor->addWeek(),
                'month' => $cursor->addMonth(),
                default => $cursor->addDay(),
            };
        }

        return $slots;
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return Collection<int, string>
     */
    private function zonesByOrder(Collection $tickets): Collection
    {
        return Order::query()->with('table.zone')->whereIn('id', $tickets->pluck('order_id')->filter()->unique())->get()
            ->mapWithKeys(fn (Order $o) => [$o->id => $o->table ? ($o->table->zone->name[$this->locale] ?? $o->table->zone->name['ca'] ?? '') : '']);
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{label: string, value: int, quantity: float}>
     */
    private function byProduct(Collection $tickets, string $group, int $limit): array
    {
        $lines = TicketLine::query()->whereIn('ticket_id', $tickets->pluck('id'))->get(['order_line_id', 'name', 'quantity', 'total']);
        $families = collect();

        if ($group === 'family') {
            $productIds = OrderLine::query()->whereIn('id', $lines->pluck('order_line_id')->filter()->unique())->pluck('product_id', 'id');
            $categoryIds = Product::withTrashed()->whereIn('id', $productIds->filter()->unique())->pluck('category_id', 'id');
            $categories = Category::withTrashed()->get()->keyBy('id');
            $root = function (?int $id) use ($categories): string {
                $category = $id ? $categories->get($id) : null;
                $guard = 0;

                while ($category && $category->parent_id && $guard++ < 10) {
                    $category = $categories->get($category->parent_id) ?? $category;
                }

                return $category ? ($category->name[$this->locale] ?? $category->name['ca'] ?? '') : '';
            };
            $families = $productIds->map(fn ($productId) => $root($productId ? ($categoryIds[$productId] ?? null) : null));
        }

        return $lines->groupBy(fn (TicketLine $l): string => $group === 'family'
            ? (($l->order_line_id ? ($families[$l->order_line_id] ?? '') : '') ?: $this->t('Altres', 'Otros'))
            : ($l->name[$this->locale] ?? $l->name['ca'] ?? $l->name['es'] ?? '?'))
            ->map(fn (Collection $rows, string $label) => ['label' => $label, 'value' => (int) $rows->sum('total'), 'quantity' => round((float) $rows->sum('quantity'), 2)])
            ->sortByDesc('value')->take($limit)->values()->all();
    }

    // ------------------------------------------------------------------ tickets

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function searchTickets(array $args): array
    {
        $hasRange = isset($args['from']) || isset($args['to']);
        [$from, $to] = $hasRange ? $this->range($args) : [CarbonImmutable::now()->subDays(30)->startOfDay(), CarbonImmutable::now()->endOfDay()];
        $limit = max(1, min(50, (int) ($args['limit'] ?? 20)));
        $query = Ticket::query()->with(['payments', 'waiter', 'lines'])->whereBetween('issued_at', [$from, $to])->latest('issued_at');

        if (is_numeric($args['min_total'] ?? null)) {
            $query->where('total', '>=', (int) round((float) $args['min_total'] * 100));
        }

        if (is_numeric($args['max_total'] ?? null)) {
            $query->where('total', '<=', (int) round((float) $args['max_total'] * 100));
        }

        if (in_array($args['payment_method'] ?? null, ['cash', 'card'], true)) {
            $query->whereHas('payments', fn ($q) => $q->where('method', $args['payment_method']));
        }

        if (is_string($args['waiter'] ?? null) && trim($args['waiter']) !== '') {
            $ids = User::query()->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($args['waiter'])).'%'])->pluck('id');
            $query->whereIn('waiter_id', $ids);
        }

        $text = is_string($args['text'] ?? null) ? mb_strtolower(trim($args['text'])) : '';
        $tickets = $query->limit(2000)->get()->filter(function (Ticket $t) use ($text) {
            if ($text === '') {
                return true;
            }

            $haystack = mb_strtolower($t->full_number.' '.$t->table_label.' '.$t->lines->map(fn (TicketLine $l) => implode(' ', $l->name))->implode(' '));

            return str_contains($haystack, $text);
        });
        $found = $tickets->count();
        $tickets = $tickets->take($limit)->values();

        $rows = $tickets->map(fn (Ticket $t) => [
            'number' => $t->full_number,
            'date' => $t->issued_at->format('d/m H:i'),
            'table' => $t->table_label ?: '—',
            'waiter' => $t->waiter->name ?? '—',
            'paid' => $t->payments->map(fn (Payment $p) => $p->method === 'card' ? $this->t('Targeta', 'Tarjeta') : $this->t('Efectiu', 'Efectivo'))->unique()->implode(' + '),
            'value' => $t->total,
            'url' => '/gestion/tiquets/'.$t->id,
        ])->all();

        return [
            'result' => [
                'found' => $found,
                'shown' => count($rows),
                'tickets' => array_map(fn (array $r) => array_diff_key($r, ['url' => 1]) + ['total' => $r['value'] / 100], $rows),
            ],
            'preview' => $this->report(
                $this->t('Tiquets trobats', 'Tiquets encontrados'),
                $this->periodLabel($from, $to).($found > count($rows) ? ' · '.$this->t('es mostren ', 'se muestran ').count($rows).' / '.$found : ''),
                [
                    ['label' => $this->t('Trobats', 'Encontrados'), 'value' => $found, 'format' => 'int'],
                    ['label' => $this->t('Import (mostrats)', 'Importe (mostrados)'), 'value' => (int) $tickets->sum('total'), 'format' => 'eur'],
                ],
                null,
                [
                    ['key' => 'number', 'label' => $this->t('Tiquet', 'Tiquet'), 'link' => 'url'],
                    ['key' => 'date', 'label' => $this->t('Data', 'Fecha')],
                    ['key' => 'table', 'label' => $this->t('Taula', 'Mesa')],
                    ['key' => 'waiter', 'label' => $this->t('Cambrer', 'Camarero')],
                    ['key' => 'paid', 'label' => $this->t('Pagament', 'Pago')],
                    ['key' => 'value', 'label' => $this->t('Total', 'Total'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function ticket(array $args): array
    {
        $number = trim((string) ($args['number'] ?? ''));
        $ticket = Ticket::query()->with(['lines', 'payments', 'waiter', 'invoice'])
            ->where(fn ($q) => $q->where('full_number', $number)->orWhere('full_number', 'like', '%-'.str_pad(ltrim($number, '0') ?: '0', 6, '0', STR_PAD_LEFT)))
            ->latest('issued_at')->first() ?? throw new MenuToolError('No he trobat aquest tiquet.');

        $rows = $ticket->lines->map(fn (TicketLine $l) => [
            'label' => ($l->name[$this->locale] ?? $l->name['ca'] ?? ''),
            'quantity' => round($l->quantity, 2),
            'value' => $l->total,
            'discount' => $l->discount_amount,
        ])->all();

        return [
            'result' => [
                'number' => $ticket->full_number,
                'issued_at' => $ticket->issued_at->toIso8601String(),
                'table' => $ticket->table_label,
                'waiter' => $ticket->waiter?->name,
                'total' => $ticket->total / 100,
                'discount' => $ticket->discount_total / 100,
                'lines' => array_map(fn (array $r) => ['name' => $r['label'], 'quantity' => $r['quantity'], 'total' => $r['value'] / 100], $rows),
                'payments' => $ticket->payments->map(fn (Payment $p) => ['method' => $p->method, 'amount' => $p->amount / 100])->all(),
                'invoice' => $ticket->invoice?->full_number,
            ],
            'preview' => $this->report(
                $this->t('Tiquet ', 'Tiquet ').$ticket->full_number,
                $ticket->issued_at->format('d/m/Y H:i').' · '.($ticket->table_label ?: $this->t('Barra', 'Barra')).($ticket->waiter ? ' · '.$ticket->waiter->name : ''),
                array_values(array_filter([
                    ['label' => $this->t('Total', 'Total'), 'value' => $ticket->total, 'format' => 'eur'],
                    $ticket->discount_total ? ['label' => $this->t('Descomptes', 'Descuentos'), 'value' => $ticket->discount_total, 'format' => 'eur'] : null,
                    ['label' => $this->t('Pagament', 'Pago'), 'value' => $ticket->payments->map(fn (Payment $p) => ($p->method === 'card' ? $this->t('Targeta', 'Tarjeta') : $this->t('Efectiu', 'Efectivo')).' '.number_format($p->amount / 100, 2, ',', '.').' €')->implode(' + '), 'format' => 'text'],
                    $ticket->invoice ? ['label' => $this->t('Factura', 'Factura'), 'value' => $ticket->invoice->full_number, 'format' => 'text'] : null,
                ])),
                null,
                [
                    ['key' => 'quantity', 'label' => '#', 'format' => 'qty'],
                    ['key' => 'label', 'label' => $this->t('Producte', 'Producto')],
                    ['key' => 'value', 'label' => $this->t('Import', 'Importe'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
                [
                    ['label' => $this->t('Obre el tiquet', 'Abrir el tiquet'), 'url' => '/gestion/tiquets/'.$ticket->id],
                    ['label' => 'PDF', 'url' => '/gestion/tiquets/'.$ticket->id.'/pdf'],
                ],
            ),
        ];
    }

    // ------------------------------------------------------------------ cash, staff, reservations, voids, suppliers

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function cashClosings(array $args): array
    {
        [$from, $to] = $this->range($args);
        $sessions = CashSession::query()->with(['device', 'closer'])->whereBetween('opened_at', [$from, $to])->orderBy('opened_at')->get();

        $rows = $sessions->map(fn (CashSession $s) => [
            'label' => $s->z_number ? 'Z '.$s->z_number : $this->t('Oberta', 'Abierta'),
            'device' => $s->device->name ?? '—',
            'opened' => $s->opened_at->format('d/m H:i'),
            'closed' => $s->closed_at?->format('d/m H:i') ?? '—',
            'value' => (int) ($s->summary['total'] ?? 0),
            'expected' => $s->expected_cash,
            'counted' => $s->counted_cash,
            'difference' => $s->difference,
            'url' => $s->closed_at ? '/gestion/cierres/'.$s->id.'/pdf' : null,
        ])->all();
        $differences = (int) $sessions->sum('difference');

        return [
            'result' => ['closings' => array_map(fn (array $r) => array_diff_key($r, ['url' => 1]), $rows), 'total_difference' => $differences / 100],
            'preview' => $this->report(
                $this->t('Tancaments de caixa', 'Cierres de caja'),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Tancaments', 'Cierres'), 'value' => $sessions->whereNotNull('closed_at')->count(), 'format' => 'int'],
                    ['label' => $this->t('Vendes', 'Ventas'), 'value' => (int) array_sum(array_column($rows, 'value')), 'format' => 'eur'],
                    ['label' => $this->t('Descuadre total', 'Descuadre total'), 'value' => $differences, 'format' => 'eur', 'tone' => $differences === 0 ? 'good' : 'bad'],
                ],
                null,
                [
                    ['key' => 'label', 'label' => 'Z', 'link' => 'url'],
                    ['key' => 'device', 'label' => $this->t('Caixa', 'Caja')],
                    ['key' => 'opened', 'label' => $this->t('Obertura', 'Apertura')],
                    ['key' => 'closed', 'label' => $this->t('Tancament', 'Cierre')],
                    ['key' => 'value', 'label' => $this->t('Vendes', 'Ventas'), 'format' => 'eur', 'align' => 'right'],
                    ['key' => 'expected', 'label' => $this->t('Esperat', 'Esperado'), 'format' => 'eur', 'align' => 'right'],
                    ['key' => 'counted', 'label' => $this->t('Comptat', 'Contado'), 'format' => 'eur', 'align' => 'right'],
                    ['key' => 'difference', 'label' => $this->t('Descuadre', 'Descuadre'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
                [['label' => $this->t('Obre els tancaments', 'Abrir los cierres'), 'url' => '/gestion/cierres']],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function staffHours(array $args): array
    {
        [$from, $to] = $this->range($args);
        $query = User::query()->where('active', true)->orderBy('name');

        if (is_string($args['person'] ?? null) && trim($args['person']) !== '') {
            $query->whereRaw('lower(name) like ?', ['%'.mb_strtolower(trim($args['person'])).'%']);
        }

        $rows = [];

        foreach ($query->get() as $user) {
            $report = $this->worked->report($user, $from, $to);
            $state = $this->worked->clockState($user)['state'];

            if ($report['totals']['worked'] === 0 && $report['totals']['planned'] === 0 && $state === 'out') {
                continue;
            }

            $rows[] = [
                'label' => $user->name,
                'value' => $report['totals']['worked'],
                'planned' => $report['totals']['planned'],
                'overtime' => $report['totals']['overtime'],
                'alerts' => count($report['alerts']),
                'now' => match ($state) {
                    'in' => $this->t('Treballant', 'Trabajando'),
                    'break' => $this->t('En pausa', 'En pausa'),
                    default => '—',
                },
            ];
        }

        usort($rows, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return [
            'result' => ['people' => array_map(fn (array $r) => ['name' => $r['label'], 'worked_hours' => round($r['value'] / 60, 2), 'planned_hours' => round($r['planned'] / 60, 2), 'overtime_hours' => round($r['overtime'] / 60, 2), 'alerts' => $r['alerts'], 'now' => $r['now']], $rows)],
            'preview' => $this->report(
                $this->t('Hores treballades', 'Horas trabajadas'),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Total', 'Total'), 'value' => (int) array_sum(array_column($rows, 'value')), 'format' => 'minutes'],
                    ['label' => $this->t('Planificades', 'Planificadas'), 'value' => (int) array_sum(array_column($rows, 'planned')), 'format' => 'minutes'],
                    ['label' => $this->t('Avisos', 'Avisos'), 'value' => (int) array_sum(array_column($rows, 'alerts')), 'format' => 'int', 'tone' => array_sum(array_column($rows, 'alerts')) ? 'bad' : 'good'],
                ],
                ['kind' => 'bar', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'value'), 'format' => 'minutes'],
                [
                    ['key' => 'label', 'label' => $this->t('Persona', 'Persona')],
                    ['key' => 'value', 'label' => $this->t('Treballat', 'Trabajado'), 'format' => 'minutes', 'align' => 'right'],
                    ['key' => 'planned', 'label' => $this->t('Planificat', 'Planificado'), 'format' => 'minutes', 'align' => 'right'],
                    ['key' => 'overtime', 'label' => $this->t('Extres', 'Extras'), 'format' => 'minutes', 'align' => 'right'],
                    ['key' => 'now', 'label' => $this->t('Ara', 'Ahora')],
                ],
                $rows,
                [['label' => $this->t('Obre els fitxatges', 'Abrir los fichajes'), 'url' => '/gestion/registro-horario']],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function reservations(array $args): array
    {
        [$from, $to] = $this->range($args);
        $reservations = Reservation::query()->with('table')->whereBetween('reserved_at', [$from, $to])->orderBy('reserved_at')->get();
        $states = ['confirmed' => $this->t('Confirmada', 'Confirmada'), 'seated' => $this->t('Asseguts', 'Sentados'), 'completed' => $this->t('Acabada', 'Terminada'), 'cancelled' => $this->t('Cancel·lada', 'Cancelada'), 'no_show' => $this->t('No han vingut', 'No han venido')];

        $rows = $reservations->map(fn (Reservation $r) => [
            'date' => $r->reserved_at->format('d/m H:i'),
            'label' => $r->name,
            'people' => $r->party_size,
            'table' => $r->table->label ?? '—',
            'status' => $states[$r->status] ?? $r->status,
            'notes' => $r->notes ?? '',
        ])->all();
        $active = $reservations->whereNotIn('status', ['cancelled']);

        return [
            'result' => ['reservations' => $rows],
            'preview' => $this->report(
                $this->t('Reserves', 'Reservas'),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Reserves', 'Reservas'), 'value' => $active->count(), 'format' => 'int'],
                    ['label' => $this->t('Persones', 'Personas'), 'value' => (int) $active->sum('party_size'), 'format' => 'int'],
                    ['label' => $this->t('No han vingut', 'No han venido'), 'value' => $reservations->where('status', 'no_show')->count(), 'format' => 'int'],
                ],
                null,
                [
                    ['key' => 'date', 'label' => $this->t('Quan', 'Cuándo')],
                    ['key' => 'label', 'label' => $this->t('Nom', 'Nombre')],
                    ['key' => 'people', 'label' => $this->t('Pers.', 'Pers.'), 'format' => 'int', 'align' => 'right'],
                    ['key' => 'table', 'label' => $this->t('Taula', 'Mesa')],
                    ['key' => 'status', 'label' => $this->t('Estat', 'Estado')],
                    ['key' => 'notes', 'label' => $this->t('Notes', 'Notas')],
                ],
                $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function voids(array $args): array
    {
        [$from, $to] = $this->range($args);
        $voided = OrderLine::query()->whereBetween('voided_at', [$from, $to])->whereNull('parent_line_id')->get();
        $discounted = OrderLine::query()->whereNotNull('discount_type')->whereBetween('updated_at', [$from, $to])->whereNull('voided_at')->get();
        $users = User::query()->whereIn('id', $voided->pluck('voided_by')->merge($discounted->pluck('discounted_by'))->filter()->unique())->pluck('name', 'id');

        $rows = $voided->map(fn (OrderLine $l) => [
            'date' => $l->voided_at?->format('d/m H:i') ?? '',
            'type' => $this->t('Anul·lació', 'Anulación'),
            'label' => $l->quantity.' × '.($l->name[$this->locale] ?? $l->name['ca'] ?? ''),
            'who' => $users[$l->voided_by] ?? '—',
            'reason' => $l->void_reason ?? '',
            'value' => $l->unitTotal() * $l->quantity,
        ])->merge($discounted->map(fn (OrderLine $l) => [
            'date' => $l->updated_at?->format('d/m H:i') ?? '',
            'type' => $this->t('Descompte', 'Descuento'),
            'label' => $l->quantity.' × '.($l->name[$this->locale] ?? $l->name['ca'] ?? ''),
            'who' => $users[$l->discounted_by] ?? '—',
            'reason' => $l->discount_reason ?? '',
            'value' => $l->discountAmount(),
        ]))->sortByDesc('date')->values()->all();

        return [
            'result' => ['items' => $rows],
            'preview' => $this->report(
                $this->t('Anul·lacions i descomptes', 'Anulaciones y descuentos'),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Anul·lat', 'Anulado'), 'value' => (int) $voided->sum(fn (OrderLine $l) => $l->unitTotal() * $l->quantity), 'format' => 'eur'],
                    ['label' => $this->t('Línies anul·lades', 'Líneas anuladas'), 'value' => $voided->count(), 'format' => 'int'],
                    ['label' => $this->t('Descomptes', 'Descuentos'), 'value' => (int) $discounted->sum(fn (OrderLine $l) => $l->discountAmount()), 'format' => 'eur'],
                ],
                null,
                [
                    ['key' => 'date', 'label' => $this->t('Quan', 'Cuándo')],
                    ['key' => 'type', 'label' => $this->t('Tipus', 'Tipo')],
                    ['key' => 'label', 'label' => $this->t('Línia', 'Línea')],
                    ['key' => 'who', 'label' => $this->t('Qui', 'Quién')],
                    ['key' => 'reason', 'label' => $this->t('Motiu', 'Motivo')],
                    ['key' => 'value', 'label' => $this->t('Import', 'Importe'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, preview: array<string, mixed>}
     */
    private function suppliers(array $args): array
    {
        [$from, $to] = $this->range($args);
        $query = SupplierInvoice::query()->whereBetween('invoice_date', [$from->toDateString(), $to->toDateString()]);

        if (is_string($args['supplier'] ?? null) && trim($args['supplier']) !== '') {
            $query->whereRaw('lower(supplier) like ?', ['%'.mb_strtolower(trim($args['supplier'])).'%']);
        }

        $invoices = $query->get();
        $rows = $invoices->groupBy(fn (SupplierInvoice $i) => $i->supplier ?: $this->t('Sense nom', 'Sin nombre'))
            ->map(fn (Collection $group, string $label) => ['label' => $label, 'value' => (int) $group->sum('amount'), 'count' => $group->count()])
            ->sortByDesc('value')->values()->all();

        return [
            'result' => ['suppliers' => array_map(fn (array $r) => ['supplier' => $r['label'], 'amount' => $r['value'] / 100, 'invoices' => $r['count']], $rows)],
            'preview' => $this->report(
                $this->t('Despesa en proveïdors', 'Gasto en proveedores'),
                $this->periodLabel($from, $to),
                [
                    ['label' => $this->t('Total', 'Total'), 'value' => (int) $invoices->sum('amount'), 'format' => 'eur'],
                    ['label' => $this->t('Factures', 'Facturas'), 'value' => $invoices->count(), 'format' => 'int'],
                ],
                ['kind' => 'bar', 'labels' => array_column($rows, 'label'), 'values' => array_column($rows, 'value'), 'format' => 'eur'],
                [
                    ['key' => 'label', 'label' => $this->t('Proveïdor', 'Proveedor')],
                    ['key' => 'count', 'label' => $this->t('Factures', 'Facturas'), 'format' => 'int', 'align' => 'right'],
                    ['key' => 'value', 'label' => $this->t('Import', 'Importe'), 'format' => 'eur', 'align' => 'right'],
                ],
                $rows,
                [['label' => $this->t('Obre els proveïdors', 'Abrir los proveedores'), 'url' => '/gestion/proveedores']],
            ),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Report card for the chat.
     *
     * @param  list<array<string, mixed>>  $kpis
     * @param  array<string, mixed>|null  $chart
     * @param  list<array<string, mixed>>  $columns
     * @param  array<int, array<string, mixed>>  $rows
     * @param  list<array{label: string, url: string}>  $links
     * @return array<string, mixed>
     */
    private function report(string $title, string $subtitle, array $kpis, ?array $chart, array $columns, array $rows, array $links = []): array
    {
        if ($chart !== null && (count($chart['values']) < 2 || max(array_map('abs', $chart['values']) ?: [0]) === 0)) {
            $chart = null;
        }

        return [
            'type' => 'report',
            'id' => substr(md5($title.$subtitle.microtime()), 0, 10),
            'title' => $title,
            'subtitle' => $subtitle,
            'kpis' => $kpis,
            'chart' => $chart,
            'columns' => $columns,
            'rows' => array_slice(array_values($rows), 0, 400),
            'links' => $links,
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function range(array $args): array
    {
        try {
            $from = isset($args['from']) && is_string($args['from']) && $args['from'] !== '' ? CarbonImmutable::parse($args['from'])->startOfDay() : CarbonImmutable::today();
            $to = isset($args['to']) && is_string($args['to']) && $args['to'] !== '' ? CarbonImmutable::parse($args['to'])->endOfDay() : $from->endOfDay();
        } catch (\Throwable) {
            throw new MenuToolError('Fechas no válidas: usa YYYY-MM-DD.');
        }

        if ($to < $from) {
            [$from, $to] = [$to->startOfDay(), $from->endOfDay()];
        }

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            throw new MenuToolError('El periodo es demasiado largo (máximo unos dos años).');
        }

        return [$from, $to];
    }

    private function periodLabel(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->isSameDay($to) ? $from->settings(['locale' => $this->locale])->isoFormat('dddd D MMMM YYYY') : $from->format('d/m/Y').' – '.$to->format('d/m/Y');
    }

    private function t(string $ca, string $es): string
    {
        return $this->locale === 'es' ? $es : $ca;
    }
}
