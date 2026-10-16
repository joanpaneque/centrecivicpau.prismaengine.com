<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\ProductionDestination;
use App\Models\SetMenu;
use App\Models\SetMenuSection;
use App\Models\SetMenuSectionItem;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\MenuDocument;
use App\Services\Catalog\SetMenuWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
                'template' => $m->template,
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

    /**
     * Printable menu: one A4 page per requested language, with the chosen design. Missing
     * translations are made on the spot.
     */
    public function pdf(Request $request, SetMenu $menu, MenuDocument $document, MenuTranslator $translator): HttpResponse
    {
        [$template, $locales] = $this->printOptions($request, $menu);
        $translator->ensure($menu, $locales);

        return response($document->pdf($menu->refresh(), $template, $locales), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($menu->name['ca'] ?? 'menu').'.pdf"',
        ]);
    }

    /**
     * The same page as HTML for the live preview of the print dialog.
     */
    public function preview(Request $request, SetMenu $menu, MenuDocument $document, MenuTranslator $translator): HttpResponse
    {
        [$template, $locales] = $this->printOptions($request, $menu);
        $locales = array_slice($locales, 0, 1);
        $translator->ensure($menu, $locales);

        return response($document->html($menu->refresh(), $template, $locales));
    }

    public function template(Request $request, SetMenu $menu): JsonResponse
    {
        $data = $request->validate(['template' => ['required', Rule::in(MenuDocument::TEMPLATES)]]);
        $menu->forceFill(['template' => $data['template']])->save();

        return response()->json(['template' => $menu->template]);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function printOptions(Request $request, SetMenu $menu): array
    {
        $template = in_array($request->query('template'), MenuDocument::TEMPLATES, true) ? (string) $request->query('template') : $menu->template;
        $requested = array_filter(explode(',', (string) $request->query('langs', $request->query('lang', 'ca,es'))));
        $locales = array_values(array_intersect(MenuDocument::LOCALES, $requested)) ?: ['ca', 'es'];

        return [$template, $locales];
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
        app(SetMenuWriter::class)->save($menu, $data);
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
            'template' => ['nullable', Rule::in(MenuDocument::TEMPLATES)],
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
