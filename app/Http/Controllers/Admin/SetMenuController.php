<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionDestination;
use App\Models\SetMenu;
use App\Models\SetMenuSection;
use App\Models\SetMenuSectionItem;
use App\Support\Translation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SetMenuController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/SetMenus', [
            'menus' => SetMenu::query()->with('sections.items')->orderBy('sort')->get()->map(fn (SetMenu $m) => [
                'id' => $m->id,
                'name' => $m->name,
                'includes' => $m->includes,
                'price' => $m->price,
                'vatRate' => $m->vat_rate,
                'color' => $m->color,
                'scheduleType' => $m->schedule_type,
                'weekdays' => $m->weekdays ?? [],
                'startsOn' => $m->starts_on?->toDateString(),
                'endsOn' => $m->ends_on?->toDateString(),
                'active' => $m->active,
                'availableToday' => $m->isAvailableOn(Carbon::now()),
                'sections' => $m->sections->map(fn (SetMenuSection $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'choices' => $s->choices,
                    'course' => $s->course,
                    'items' => $s->items->map(fn (SetMenuSectionItem $i) => [
                        'id' => $i->id,
                        'productId' => $i->product_id,
                        'name' => $i->name,
                        'destinationId' => $i->production_destination_id,
                        'supplement' => $i->supplement,
                    ])->values()->all(),
                ])->values()->all(),
            ]),
            'products' => Product::query()->where('active', true)->orderBy('sort')->get(['id', 'name', 'category_id']),
            'destinations' => ProductionDestination::query()->orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $menu = new SetMenu;
            $menu->sort = (int) SetMenu::query()->max('sort') + 1;
            $this->save($menu, $data);
        });

        return $this->changed();
    }

    public function update(Request $request, SetMenu $menu): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(fn () => $this->save($menu, $data));

        return $this->changed();
    }

    public function duplicate(SetMenu $menu): RedirectResponse
    {
        DB::transaction(function () use ($menu) {
            $copy = $menu->replicate();
            $copy->name = ['ca' => ($menu->name['ca'] ?? '').' (còpia)', 'es' => ($menu->name['es'] ?? '').' (copia)'];
            $copy->active = false;
            $copy->sort = (int) SetMenu::query()->max('sort') + 1;
            $copy->save();

            foreach ($menu->sections()->with('items')->get() as $section) {
                $newSection = $copy->sections()->create($section->only(['name', 'choices', 'course', 'sort']));

                foreach ($section->items as $item) {
                    $newSection->items()->create($item->only(['product_id', 'name', 'production_destination_id', 'supplement', 'sort']));
                }
            }
        });

        return $this->changed(__('tpv.duplicated'));
    }

    public function destroy(SetMenu $menu): RedirectResponse
    {
        $menu->delete();

        return $this->changed(__('tpv.deleted'));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function save(SetMenu $menu, array $data): void
    {
        $menu->fill([
            'name' => Translation::normalize($data['name']),
            'includes' => isset($data['includes']) ? Translation::normalize($data['includes']) : null,
            'price' => (int) $data['price'],
            'vat_rate' => (float) $data['vatRate'],
            'color' => $data['color'] ?? null,
            'schedule_type' => $data['scheduleType'],
            'weekdays' => $data['scheduleType'] === 'weekdays' ? array_values(array_map('intval', $data['weekdays'] ?? [])) : null,
            'starts_on' => $data['scheduleType'] === 'dates' ? ($data['startsOn'] ?? null) : null,
            'ends_on' => $data['scheduleType'] === 'dates' ? ($data['endsOn'] ?? null) : null,
            'active' => $data['active'] ?? true,
        ])->save();

        $keepSections = [];

        foreach (array_values($data['sections'] ?? []) as $sort => $row) {
            $section = isset($row['id']) ? $menu->sections()->whereKey($row['id'])->first() : null;
            $section ??= $menu->sections()->make();
            $section->fill([
                'name' => Translation::normalize($row['name']),
                'choices' => max(1, (int) ($row['choices'] ?? 1)),
                'course' => isset($row['course']) ? (int) $row['course'] : null,
                'sort' => $sort,
            ])->save();
            $keepSections[] = $section->id;

            $keepItems = [];

            foreach (array_values($row['items'] ?? []) as $itemSort => $itemRow) {
                $item = isset($itemRow['id']) ? $section->items()->whereKey($itemRow['id'])->first() : null;
                $item ??= $section->items()->make();
                $hasName = isset($itemRow['name']) && trim(($itemRow['name']['ca'] ?? '').($itemRow['name']['es'] ?? '')) !== '';
                $item->fill([
                    'product_id' => $itemRow['productId'] ?? null,
                    'name' => $hasName ? Translation::normalize($itemRow['name']) : null,
                    'production_destination_id' => $itemRow['destinationId'] ?? null,
                    'supplement' => max(0, (int) ($itemRow['supplement'] ?? 0)),
                    'sort' => $itemSort,
                ])->save();
                $keepItems[] = $item->id;
            }

            $section->items()->whereNotIn('id', $keepItems)->delete();
        }

        $menu->sections()->whereNotIn('id', $keepSections)->delete();
        $menu->touch();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:80'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:80'],
            'includes' => ['nullable', 'array'],
            'includes.ca' => ['nullable', 'string', 'max:200'],
            'includes.es' => ['nullable', 'string', 'max:200'],
            'price' => ['required', 'integer', 'min:0'],
            'vatRate' => ['required', 'numeric', Rule::in([0, 4, 5, 10, 21])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'scheduleType' => ['required', Rule::in(['always', 'weekdays', 'dates'])],
            'weekdays' => ['array'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'startsOn' => ['nullable', 'date'],
            'endsOn' => ['nullable', 'date', 'after_or_equal:startsOn'],
            'active' => ['boolean'],
            'sections' => ['array'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.name' => ['required', 'array'],
            'sections.*.choices' => ['nullable', 'integer', 'min:1', 'max:10'],
            'sections.*.course' => ['nullable', 'integer', 'between:1,3'],
            'sections.*.items' => ['array'],
            'sections.*.items.*.id' => ['nullable', 'integer'],
            'sections.*.items.*.productId' => ['nullable', 'integer', 'exists:products,id', 'required_without:sections.*.items.*.name'],
            'sections.*.items.*.name' => ['nullable', 'array'],
            'sections.*.items.*.destinationId' => ['nullable', 'integer', 'exists:production_destinations,id'],
            'sections.*.items.*.supplement' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    private function changed(string $message = ''): RedirectResponse
    {
        TpvChanged::notify(['catalog']);
        $this->toast($message === '' ? __('tpv.saved') : $message);

        return back();
    }
}
