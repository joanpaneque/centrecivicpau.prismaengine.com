<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Carta;
use App\Models\CartaItem;
use App\Models\CartaSection;
use App\Models\Product;
use App\Services\Assistant\MenuTranslator;
use App\Services\Catalog\CartaDocument;
use App\Services\Catalog\CartaWriter;
use App\Services\Catalog\MenuDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable cartas (drinks, tapas, desserts…), apart from the set menus and the POS catalog.
 */
class CartaController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Cartas', [
            'cartas' => Carta::query()->with('sections.items.product')->orderBy('sort')->get()->map(fn (Carta $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'subtitle' => $c->subtitle,
                'notes' => $c->notes,
                'maxPages' => $c->maxPages(),
                'css' => $c->css(),
                'template' => $c->template,
                'active' => $c->active,
                'sections' => $c->sections->map(fn (CartaSection $s) => [
                    'id' => $s->id,
                    'name' => $s->name,
                    'items' => $s->items->map(fn (CartaItem $i) => [
                        'id' => $i->id,
                        'productId' => $i->product_id,
                        'name' => $i->name,
                        'description' => $i->description,
                        'price' => $i->price,
                        'priceText' => $i->price_text,
                        'effectivePrice' => $i->effectivePrice(),
                        'displayName' => $i->displayName(),
                    ])->values()->all(),
                ])->values()->all(),
            ]),
            'products' => Product::query()->where('active', true)->orderBy('sort')->get(['id', 'name', 'price']),
        ]);
    }

    public function store(Request $request, CartaWriter $writer): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(fn () => $writer->save(new Carta, $data));

        return $this->changed();
    }

    public function update(Request $request, Carta $carta, CartaWriter $writer): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(fn () => $writer->save($carta, $data));

        return $this->changed();
    }

    public function duplicate(Carta $carta): RedirectResponse
    {
        DB::transaction(function () use ($carta) {
            $copy = $carta->replicate(['translations']);
            $copy->name = ['ca' => ($carta->name['ca'] ?? '').' (còpia)', 'es' => ($carta->name['es'] ?? '').' (copia)'];
            $copy->active = false;
            $copy->sort = (int) Carta::query()->max('sort') + 1;
            $copy->save();

            foreach ($carta->sections()->with('items')->get() as $section) {
                $newSection = $copy->sections()->create($section->only(['name', 'sort']));

                foreach ($section->items as $item) {
                    $newSection->items()->create($item->only(['product_id', 'name', 'description', 'price', 'price_text', 'sort']));
                }
            }
        });

        return $this->changed(__('tpv.duplicated'));
    }

    public function destroy(Carta $carta): RedirectResponse
    {
        $carta->delete();

        return $this->changed(__('tpv.deleted'));
    }

    /**
     * Printable carta: its sheets in every requested language, with the chosen design.
     * Missing translations are made on the spot.
     */
    public function pdf(Request $request, Carta $carta, CartaDocument $document, MenuTranslator $translator): HttpResponse
    {
        [$template, $locales] = $this->printOptions($request, $carta);
        $translator->ensure($carta, $locales);

        return response($document->pdf($carta->refresh(), $template, $locales), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.Str::slug($carta->name['ca'] ?? 'carta').'.pdf"',
        ]);
    }

    /**
     * The first sheet as HTML for the live preview of the print dialog.
     */
    public function preview(Request $request, Carta $carta, CartaDocument $document, MenuTranslator $translator): HttpResponse
    {
        [$template, $locales] = $this->printOptions($request, $carta);
        $locales = array_slice($locales, 0, 1);
        $translator->ensure($carta, $locales);

        return response($document->html($carta->refresh(), $template, $locales));
    }

    public function template(Request $request, Carta $carta): JsonResponse
    {
        $data = $request->validate(['template' => ['required', Rule::in(MenuDocument::TEMPLATES)]]);
        $carta->forceFill(['template' => $data['template']])->save();

        return response()->json(['template' => $carta->template]);
    }

    /**
     * @return array{0: string, 1: list<string>}
     */
    private function printOptions(Request $request, Carta $carta): array
    {
        $template = in_array($request->query('template'), MenuDocument::TEMPLATES, true) ? (string) $request->query('template') : $carta->template;
        $requested = array_filter(explode(',', (string) $request->query('langs', $request->query('lang', 'ca,es'))));
        $locales = array_values(array_intersect(MenuDocument::LOCALES, $requested)) ?: ['ca', 'es'];

        return [$template, $locales];
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:80'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:80'],
            'subtitle' => ['nullable', 'array'],
            'subtitle.ca' => ['nullable', 'string', 'max:200'],
            'subtitle.es' => ['nullable', 'string', 'max:200'],
            'notes' => ['nullable', 'array'],
            'notes.ca' => ['nullable', 'string', 'max:1000'],
            'notes.es' => ['nullable', 'string', 'max:1000'],
            'layout' => ['nullable', 'array'],
            'layout.maxPages' => ['nullable', 'integer', 'between:1,10'],
            'layout.css' => ['nullable', 'string', 'max:8000'],
            'active' => ['boolean'],
            'template' => ['nullable', Rule::in(MenuDocument::TEMPLATES)],
            'sections' => ['array'],
            'sections.*.id' => ['nullable', 'integer'],
            'sections.*.name' => ['required', 'array'],
            'sections.*.items' => ['array'],
            'sections.*.items.*.id' => ['nullable', 'integer'],
            'sections.*.items.*.productId' => ['nullable', 'integer', 'exists:products,id'],
            'sections.*.items.*.name' => ['nullable', 'array'],
            'sections.*.items.*.description' => ['nullable', 'array'],
            'sections.*.items.*.price' => ['nullable', 'integer', 'min:0'],
            'sections.*.items.*.priceText' => ['nullable', 'string', 'max:20'],
        ]);

        // Every dish needs a name, and a price of its own or from its product.
        $prices = Product::query()->pluck('price', 'id');

        foreach ($data['sections'] ?? [] as $s => $section) {
            foreach ($section['items'] ?? [] as $i => $item) {
                $named = isset($item['name']) && trim(($item['name']['ca'] ?? '').($item['name']['es'] ?? '')) !== '';

                if (! $named && empty($item['productId'])) {
                    throw ValidationException::withMessages(["sections.{$s}.items.{$i}.name" => __('tpv.carta_item_name')]);
                }

                if (! isset($item['price']) && trim((string) ($item['priceText'] ?? '')) === '' && ($prices[$item['productId'] ?? 0] ?? null) === null) {
                    throw ValidationException::withMessages(["sections.{$s}.items.{$i}.price" => __('tpv.carta_item_price')]);
                }
            }
        }

        return $data;
    }

    private function changed(string $message = ''): RedirectResponse
    {
        $this->toast($message === '' ? __('tpv.saved') : $message);

        return back();
    }
}
