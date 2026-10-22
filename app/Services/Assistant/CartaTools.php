<?php

namespace App\Services\Assistant;

use App\Models\AuditLog;
use App\Models\Carta;
use App\Models\CartaItem;
use App\Models\CartaSection;
use App\Models\Product;
use App\Models\User;
use App\Services\Assistant\Concerns\ReadsToolArguments;
use App\Services\Catalog\CartaDocument;
use App\Services\Catalog\CartaWriter;
use App\Services\Catalog\MenuDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the assistant can do with the printable cartas: create them, change them a dish at
 * a time, pick the design and print them. A dish without a price takes the one of the same
 * product in the POS catalog; if there is none, the tool refuses it so the model asks.
 */
class CartaTools
{
    use ReadsToolArguments;

    /** @var Collection<int, Product>|null */
    private ?Collection $products = null;

    /** @var list<string> dishes whose price came from the catalog in this call */
    private array $fromCatalog = [];

    /** @var list<string> dishes still without a price in this call */
    private array $unpriced = [];

    public function __construct(
        private readonly User $user,
        private readonly CartaWriter $writer,
    ) {}

    /**
     * @return list<string>
     */
    public static function toolNames(): array
    {
        return array_map(fn (array $definition) => $definition['function']['name'], self::definitions());
    }

    /**
     * OpenAI-style tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $item = [
            'product_id' => ['type' => ['integer', 'null'], 'description' => 'Producto del TPV (get_catalog) si el plato está ahí: sin price usa su precio.'],
            'name_ca' => ['type' => ['string', 'null'], 'description' => 'Nombre del plato en catalán (si no es un producto, o para mostrarlo distinto).'],
            'name_es' => ['type' => ['string', 'null']],
            'description_ca' => ['type' => ['string', 'null'], 'description' => 'Descripción corta opcional (ingredientes…), en catalán.'],
            'description_es' => ['type' => ['string', 'null']],
            'price' => ['type' => ['number', 'null'], 'description' => 'Precio en euros con IVA. Omítelo si el usuario no lo ha dicho: se coge el del producto del TPV.'],
            'price_text' => ['type' => ['string', 'null'], 'description' => 'Texto en lugar del precio, tal cual (máx. 20 caracteres): «S.M.» (según mercado), «Consultar»… Solo si lo piden; con él no hace falta price. null lo quita.'],
        ];
        $document = [
            'notes_ca' => ['type' => ['string', 'null'], 'description' => 'Notas al pie de la carta, en letra pequeña al final (leyenda, «S.M. = segons mercat», alérgenos…). Puede tener varias líneas (\n). Solo si lo piden. null las quita.'],
            'notes_es' => ['type' => ['string', 'null']],
            'max_pages' => ['type' => ['integer', 'null'], 'description' => 'Máximo de hojas A4 por idioma («que quepa en una hoja» = 1). La letra se reduce lo necesario. null = automático.'],
            'css' => ['type' => ['string', 'null'], 'description' => 'CSS extra para el documento impreso (sustituye al anterior; null lo quita). Lo pinta DomPDF: CSS 2.1, sin flex, grid ni columnas, sin imágenes externas. Clases: .sheet (cada hoja; .carta en las cartas, .more en las hojas 2+), h1 (título), .includes (subtítulo), h2 (sección), table.dishes, .dishes td, .dish (nombre), .description, .cost (precio), .notes (notas al pie), .vat, .frame (marco), .decor (dibujos). Los tamaños de letra van en style inline: para cambiarlos usa !important. Revisa siempre el resultado con check_carta_layout.'],
        ];
        $template = ['type' => 'string', 'enum' => MenuDocument::TEMPLATES, 'description' => 'Diseño impreso: classic (normal), halloween, christmas (Navidad), easter (Pascua), kids (infantil).'];

        return [
            self::fn('get_cartas', 'Lee las cartas imprimibles (bebidas, tapas, postres…) con sus secciones, platos, precios e ids.', []),
            self::fn('save_carta', 'Crea una carta (sin id) o la reescribe ENTERA (con id). Para cambios sueltos usa edit_carta.', [
                'id' => ['type' => ['integer', 'null']],
                'name_ca' => ['type' => 'string'],
                'name_es' => ['type' => 'string'],
                'subtitle_ca' => ['type' => ['string', 'null'], 'description' => 'Línea bajo el título (horario, «Tapes per compartir»…).'],
                'subtitle_es' => ['type' => ['string', 'null']],
                'template' => $template,
                'active' => ['type' => 'boolean'],
            ] + $document + [
                'sections' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name_ca' => ['type' => 'string'],
                            'name_es' => ['type' => 'string'],
                            'items' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => $item]],
                        ],
                        'required' => ['name_ca', 'name_es', 'items'],
                    ],
                ],
            ], ['name_ca', 'name_es', 'sections']),
            self::fn('edit_carta', 'Cambia una carta existente sin reescribirla: título, subtítulo, diseño, activa, y una lista de cambios (añadir, cambiar, mover o quitar platos y secciones).', [
                'id' => ['type' => 'integer'],
                'name_ca' => ['type' => 'string'],
                'name_es' => ['type' => 'string'],
                'subtitle_ca' => ['type' => ['string', 'null']],
                'subtitle_es' => ['type' => ['string', 'null']],
                'template' => $template,
                'active' => ['type' => 'boolean'],
            ] + $document + [
                'changes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'action' => ['type' => 'string', 'enum' => ['add_item', 'update_item', 'remove_item', 'add_section', 'update_section', 'remove_section']],
                            'item_id' => ['type' => 'integer', 'description' => 'Para update_item y remove_item.'],
                            'section_id' => ['type' => 'integer', 'description' => 'Sección donde va el plato (add_item, o mover con update_item), o la sección a cambiar/quitar.'],
                            'section' => ['type' => 'string', 'description' => 'En vez de section_id, el nombre de la sección (si es nueva, añádela antes con add_section en la misma lista).'],
                            'position' => ['type' => 'integer', 'description' => 'Posición desde 1 dentro de la sección (o entre secciones).'],
                        ] + $item,
                        'required' => ['action'],
                    ],
                ],
            ], ['id']),
            self::fn('check_carta_layout', 'Genera la carta de verdad (como el PDF) y dice, por idioma, cuántas hojas ocupa, si cabe en max_pages, el tamaño de letra usado (1 = el más grande, 0 = el mínimo) y te devuelve el HTML generado para que lo revises. Úsalo SIEMPRE después de cambiar max_pages, css, notas o muchos platos, y antes de decir que algo cabe en una hoja; si no queda bien, corrige (edit_carta) y vuelve a comprobar.', [
                'id' => ['type' => 'integer'],
                'languages' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MenuDocument::LOCALES], 'description' => 'Por defecto ca y es (los otros idiomas suelen ocupar algo más).'],
                'include_html' => ['type' => 'boolean', 'description' => 'Devolver el HTML generado (del primer idioma). Por defecto true.'],
            ], ['id']),
            self::fn('print_carta', 'Prepara la impresión de una carta: sale en el chat la hoja A4 de verdad, los idiomas marcados y el botón para generar el PDF. Úsalo cuando pidan imprimirla, verla impresa o en otros idiomas.', [
                'id' => ['type' => 'integer'],
                'languages' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => MenuDocument::LOCALES], 'description' => 'Idiomas: es, ca, fr, en, de, ru, it, nl. Por defecto ca y es.'],
                'template' => $template,
            ], ['id']),
            self::fn('delete_carta', 'Borra una carta entera. SOLO después de que el usuario lo haya confirmado explícitamente.', [
                'id' => ['type' => 'integer'],
                'confirmed' => ['type' => 'boolean'],
            ], ['id', 'confirmed']),
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function fn(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => ['type' => 'object', 'properties' => (object) $properties, 'required' => $required],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    public function run(string $tool, array $args): array
    {
        $this->fromCatalog = [];
        $this->unpriced = [];

        try {
            return DB::transaction(fn () => match ($tool) {
                'get_cartas' => ['result' => ['cartas' => Carta::query()->with('sections.items.product')->orderBy('sort')->get()->map(fn (Carta $c) => $this->cartaData($c))->all()]],
                'save_carta' => $this->save($args),
                'edit_carta' => $this->edit($args),
                'print_carta' => $this->print($args),
                'check_carta_layout' => $this->check($args),
                'delete_carta' => $this->delete($args),
                default => throw new MenuToolError("Herramienta desconocida: {$tool}"),
            });
        } catch (MenuToolError $e) {
            return ['result' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}, preview: array<string, mixed>}
     */
    private function save(array $args): array
    {
        $id = $this->optionalInt($args, 'id');
        $carta = $id !== null ? (Carta::query()->find($id) ?? throw new MenuToolError('No existe esa carta: mira los ids con get_cartas.')) : new Carta;
        $creating = ! $carta->exists;
        $sections = [];

        foreach ((array) ($args['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $items = [];

            foreach ((array) ($section['items'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $this->item($item, null);
                }
            }

            $sections[] = ['name' => $this->names($section, true), 'items' => $items];
        }

        if ($sections === []) {
            throw new MenuToolError('La carta necesita al menos una sección con platos.');
        }

        $this->checkPrices();

        $data = [
            'name' => $this->names($args, true),
            'subtitle' => $this->names($args, false, 'subtitle', 200),
            'active' => (bool) ($args['active'] ?? true),
            'template' => $args['template'] ?? ($creating ? 'classic' : $carta->template),
            'sections' => $sections,
        ];

        if (array_key_exists('notes_ca', $args) || array_key_exists('notes_es', $args)) {
            $data['notes'] = $this->names($args, false, 'notes', 1000);
        }

        if (array_key_exists('max_pages', $args) || array_key_exists('css', $args)) {
            $data['layout'] = [
                'maxPages' => array_key_exists('max_pages', $args) ? $this->optionalInt($args, 'max_pages') : $carta->maxPages(),
                'css' => array_key_exists('css', $args) ? $args['css'] : $carta->css(),
            ];
        }

        return $this->saved($carta, $data, $creating ? 'created' : 'updated', ($creating ? 'Carta creada: ' : 'Carta actualitzada: ').$data['name']['ca']);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}, preview: array<string, mixed>}
     */
    private function edit(array $args): array
    {
        $carta = Carta::query()->with('sections.items.product')->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe esa carta: mira los ids con get_cartas.');
        $data = $this->toData($carta);
        $done = [];

        if (($name = $this->names($args, false)) !== null) {
            $data['name'] = $name;
            $done[] = 'títol';
        }

        if (array_key_exists('subtitle_ca', $args) || array_key_exists('subtitle_es', $args)) {
            $data['subtitle'] = $this->names($args, false, 'subtitle', 200);
            $done[] = 'subtítol';
        }

        if (isset($args['template']) && in_array($args['template'], MenuDocument::TEMPLATES, true)) {
            $data['template'] = $args['template'];
            $done[] = 'disseny '.$args['template'];
        }

        if (isset($args['active'])) {
            $data['active'] = (bool) $args['active'];
            $done[] = $data['active'] ? 'activa' : 'desactivada';
        }

        if (array_key_exists('notes_ca', $args) || array_key_exists('notes_es', $args)) {
            $data['notes'] = $this->names($args, false, 'notes', 1000);
            $done[] = $data['notes'] ? 'notes' : 'sense notes';
        }

        if (array_key_exists('max_pages', $args)) {
            $data['layout']['maxPages'] = $this->optionalInt($args, 'max_pages');
            $done[] = $data['layout']['maxPages'] ? 'màxim '.$data['layout']['maxPages'].' full(s)' : 'fulls automàtics';
        }

        if (array_key_exists('css', $args)) {
            $data['layout']['css'] = $args['css'];
            $done[] = Carta::cleanCss($args['css']) ? 'estil retocat' : 'sense estil propi';
        }

        foreach ((array) ($args['changes'] ?? []) as $change) {
            if (is_array($change)) {
                $done[] = $this->apply($data, $change);
            }
        }

        if ($done === []) {
            throw new MenuToolError('No has indicado ningún cambio.');
        }

        $this->checkPrices();

        return $this->saved($carta, $data, 'updated', ($data['name']['ca'] ?? '').': '.implode(', ', $done));
    }

    /**
     * Apply one change to the carta data; returns what it did, in a few words.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $change
     */
    private function apply(array &$data, array $change): string
    {
        $action = (string) ($change['action'] ?? '');

        switch ($action) {
            case 'add_section':
                $name = $this->names($change, true);
                $this->insert($data['sections'], ['id' => null, 'name' => $name, 'items' => []], $change['position'] ?? null);

                return 'secció '.$name['ca'];

            case 'update_section':
            case 'remove_section':
                $s = $this->sectionIndex($data, $change);
                $section = $data['sections'][$s];

                if ($action === 'remove_section') {
                    array_splice($data['sections'], $s, 1);

                    return '− secció '.$section['name']['ca'];
                }

                $section['name'] = $this->names($change, false) ?? $section['name'];
                array_splice($data['sections'], $s, 1);
                $this->insert($data['sections'], $section, $change['position'] ?? $s + 1);

                return 'secció '.$section['name']['ca'];

            case 'add_item':
                $s = $this->sectionIndex($data, $change);
                $item = $this->item($change, null);
                $this->insert($data['sections'][$s]['items'], $item, $change['position'] ?? null);

                return '+ '.$this->label($item);

            case 'update_item':
            case 'remove_item':
                [$s, $i] = $this->itemIndex($data, (int) ($change['item_id'] ?? 0));
                $current = $data['sections'][$s]['items'][$i];
                array_splice($data['sections'][$s]['items'], $i, 1);

                if ($action === 'remove_item') {
                    return '− '.$this->label($current);
                }

                $item = $this->item($change, $current);
                $moving = isset($change['section_id']) || isset($change['section']);
                $target = $moving ? $this->sectionIndex($data, $change) : $s;
                $this->insert($data['sections'][$target]['items'], $item, $change['position'] ?? ($moving ? null : $i + 1));

                return $this->label($item);

            default:
                throw new MenuToolError("Cambio no válido: «{$action}».");
        }
    }

    /**
     * A dish ready for the writer. Without a price of its own it needs a product of the
     * catalog with one: the given product_id, or a product with the same name.
     *
     * @param  array<string, mixed>  $args
     * @param  array<string, mixed>|null  $current  the dish being changed
     * @return array{id: int|null, productId: int|null, name: array{ca: string, es: string}|null, description: array{ca: string, es: string}|null, price: int|null, priceText: string|null}
     */
    private function item(array $args, ?array $current): array
    {
        $productId = array_key_exists('product_id', $args) ? $this->optionalInt($args, 'product_id') : ($current['productId'] ?? null);
        $name = $this->names($args, false) ?? $current['name'] ?? null;
        $description = array_key_exists('description_ca', $args) || array_key_exists('description_es', $args)
            ? $this->names($args, false, 'description', 200)
            : ($current['description'] ?? null);
        $price = array_key_exists('price', $args) ? $this->cents($args['price']) : ($current['price'] ?? null);
        $priceText = array_key_exists('price_text', $args)
            ? (is_string($args['price_text']) && trim($args['price_text']) !== '' ? mb_substr(trim($args['price_text']), 0, 20) : null)
            : ($current['priceText'] ?? null);
        $product = null;

        if ($productId !== null) {
            $product = $this->products()->get($productId) ?? throw new MenuToolError("No existe el producto {$productId}: mira los ids con get_catalog.");
        } elseif ($name === null) {
            throw new MenuToolError('Cada plato necesita nombre o product_id.');
        } elseif ($price === null && $priceText === null) {
            $product = $this->findProduct($name);
        }

        if ($name !== null && $product !== null && $this->same($name, $product->name)) {
            $name = null;
        }

        $label = ($name ?? $product->name ?? [])['ca'] ?? '';

        if ($priceText !== null) {
            // Priced with a text: nothing to check.
        } elseif ($price === null && $product?->price === null) {
            $this->unpriced[] = $label;
        } elseif ($price === null) {
            $this->fromCatalog[] = $label.' ('.$this->euros((int) $product->price).')';
        }

        return ['id' => $current['id'] ?? null, 'productId' => $product?->id, 'name' => $name, 'description' => $description, 'price' => $price, 'priceText' => $priceText];
    }

    private function checkPrices(): void
    {
        if ($this->unpriced !== []) {
            throw new MenuToolError('No se ha guardado nada. No sé el precio de: '.implode(', ', array_map(fn ($n) => "«{$n}»", $this->unpriced))
                .'. No están en la carta del TPV (o allí no tienen precio). Pregunta al usuario el precio de cada uno (no lo inventes) y vuelve a llamar con price (o price_text si es un texto como «S.M.»).');
        }
    }

    /**
     * Same product by name (Catalan or Spanish, ignoring case and spaces).
     *
     * @param  array{ca: string, es: string}  $name
     */
    private function findProduct(array $name): ?Product
    {
        $active = $this->products()->filter(fn (Product $p) => $p->active && $p->deleted_at === null);

        return $active->first(fn (Product $p) => $this->same($name, $p->name));
    }

    /**
     * @param  array<string, string>  $a
     * @param  array<string, string>|null  $b
     */
    private function same(array $a, ?array $b): bool
    {
        $norm = fn (?string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)) ?? '');
        $left = array_filter([$norm($a['ca'] ?? null), $norm($a['es'] ?? null)]);
        $right = array_filter([$norm($b['ca'] ?? null), $norm($b['es'] ?? null)]);

        return array_intersect($left, $right) !== [];
    }

    /**
     * @return Collection<int, Product>
     */
    private function products(): Collection
    {
        return $this->products ??= Product::withTrashed()->get()->keyBy('id');
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $change
     */
    private function sectionIndex(array $data, array $change): int
    {
        $id = $this->optionalInt($change, 'section_id');

        foreach ($data['sections'] as $s => $section) {
            if ($id !== null && $section['id'] === $id) {
                return $s;
            }
        }

        if ($id !== null) {
            throw new MenuToolError("No existe la sección {$id} en esta carta.");
        }

        $wanted = is_string($change['section'] ?? null) ? trim($change['section']) : '';

        if ($wanted === '') {
            throw new MenuToolError('Indica la sección (section_id o section).');
        }

        foreach ($data['sections'] as $s => $section) {
            if ($this->same(['ca' => $wanted, 'es' => $wanted], $section['name'])) {
                return $s;
            }
        }

        throw new MenuToolError("No existe la sección «{$wanted}»: créala antes con add_section (puede ir en la misma llamada).");
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: int, 1: int}
     */
    private function itemIndex(array $data, int $id): array
    {
        foreach ($data['sections'] as $s => $section) {
            foreach ($section['items'] as $i => $item) {
                if ($item['id'] === $id) {
                    return [$s, $i];
                }
            }
        }

        throw new MenuToolError("No existe el plato {$id} en esta carta: mira los ids con get_cartas.");
    }

    /**
     * @param  array<mixed>  $list
     */
    private function insert(array &$list, mixed $value, mixed $position): void
    {
        $index = is_numeric($position) ? max(0, min(count($list), (int) $position - 1)) : count($list);
        array_splice($list, $index, 0, [$value]);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function label(array $item): string
    {
        $product = $item['productId'] !== null ? $this->products()->get($item['productId']) : null;
        $name = ($item['name'] ?? $product->name ?? [])['ca'] ?? '';
        $price = $item['price'] ?? $product?->price;

        if (($item['priceText'] ?? null) !== null) {
            return $name.' '.$item['priceText'];
        }

        return $name.($price !== null ? ' '.$this->euros((int) $price) : '');
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}, preview: array<string, mixed>}
     */
    private function saved(Carta $carta, array $data, string $kind, string $label): array
    {
        $this->writer->save($carta, $data);
        $carta = $carta->fresh(['sections.items.product']) ?? $carta;
        AuditLog::record('ai.carta.'.($kind === 'created' ? 'create' : 'update'), $carta, ['name' => $carta->name], userId: $this->user->id);

        return [
            'result' => array_filter(['ok' => true, 'carta_id' => $carta->id, 'prices_from_catalog' => $this->fromCatalog]),
            'action' => ['kind' => $kind, 'label' => $label],
            'preview' => $this->preview($carta),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}, preview: array<string, mixed>}
     */
    private function print(array $args): array
    {
        $carta = Carta::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe esa carta: mira los ids con get_cartas.');
        $languages = array_values(array_intersect(MenuDocument::LOCALES, array_map('strval', (array) ($args['languages'] ?? [])))) ?: ['ca', 'es'];

        if (isset($args['template']) && in_array($args['template'], MenuDocument::TEMPLATES, true)) {
            $carta->forceFill(['template' => $args['template']])->save();
        }

        // Translate now, so the preview and the PDF are ready when the card shows up.
        $translated = app(MenuTranslator::class)->ensure($carta, $languages);
        $carta->load('sections.items.product');

        return [
            'result' => ['ok' => true, 'languages' => $languages, 'translated' => $translated],
            'action' => ['kind' => 'updated', 'label' => 'Carta llesta per imprimir: '.strtoupper(implode(', ', $languages))],
            'preview' => ['print' => ['languages' => $languages]] + $this->preview($carta),
        ];
    }

    /**
     * The carta printed for real (DomPDF), so the model can see whether it fits and review
     * the HTML it produced with its CSS. Images are left out of the HTML (they are only data).
     *
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>}
     */
    private function check(array $args): array
    {
        $carta = Carta::query()->with('sections.items.product')->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe esa carta: mira los ids con get_cartas.');
        $languages = array_values(array_intersect(MenuDocument::LOCALES, array_map('strval', (array) ($args['languages'] ?? [])))) ?: ['ca', 'es'];
        app(MenuTranslator::class)->ensure($carta, $languages);
        $carta->refresh()->load('sections.items.product');
        $document = app(CartaDocument::class);
        $result = ['languages' => $document->report($carta, $carta->template, $languages)];

        if (($args['include_html'] ?? true) !== false) {
            $html = $document->html($carta, $carta->template, [$languages[0]]);
            $html = (string) preg_replace('/data:[a-z\/+.-]+;base64,[A-Za-z0-9+\/=]+/', 'data:…', $html);
            $result['html'] = mb_strlen($html) > 20000 ? mb_substr($html, 0, 20000).'…' : $html;
        }

        return ['result' => $result];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}}
     */
    private function delete(array $args): array
    {
        if (($args['confirmed'] ?? false) !== true) {
            throw new MenuToolError('Primero pide confirmación al usuario con ask_user.');
        }

        $carta = Carta::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe esa carta.');
        $carta->delete();
        AuditLog::record('ai.carta.delete', $carta, ['name' => $carta->name], userId: $this->user->id);

        return ['result' => ['ok' => true], 'action' => ['kind' => 'deleted', 'label' => 'Carta esborrada: '.($carta->name['ca'] ?? '')]];
    }

    /**
     * The carta as the writer takes it, to change it a piece at a time.
     *
     * @return array<string, mixed>
     */
    private function toData(Carta $carta): array
    {
        return [
            'name' => $carta->name,
            'subtitle' => $carta->subtitle,
            'active' => $carta->active,
            'template' => $carta->template,
            'notes' => $carta->notes,
            'layout' => ['maxPages' => $carta->maxPages(), 'css' => $carta->css()],
            'sections' => $carta->sections->map(fn (CartaSection $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'items' => $s->items->map(fn (CartaItem $i) => [
                    'id' => $i->id,
                    'productId' => $i->product_id,
                    'name' => $i->name,
                    'description' => $i->description,
                    'price' => $i->price,
                    'priceText' => $i->price_text,
                ])->all(),
            ])->all(),
        ];
    }

    /**
     * What the model reads back (get_cartas).
     *
     * @return array<string, mixed>
     */
    private function cartaData(Carta $carta): array
    {
        return [
            'id' => $carta->id,
            'name_ca' => $carta->name['ca'] ?? '',
            'name_es' => $carta->name['es'] ?? '',
            'subtitle_ca' => $carta->subtitle['ca'] ?? null,
            'notes_ca' => $carta->notes['ca'] ?? null,
            'notes_es' => $carta->notes['es'] ?? null,
            'template' => $carta->template,
            'max_pages' => $carta->maxPages(),
            'css' => $carta->css(),
            'active' => $carta->active,
            'sections' => $carta->sections->map(fn (CartaSection $s) => [
                'id' => $s->id,
                'name_ca' => $s->name['ca'] ?? '',
                'name_es' => $s->name['es'] ?? '',
                'items' => $s->items->map(fn (CartaItem $i) => array_filter([
                    'id' => $i->id,
                    'product_id' => $i->product_id,
                    'name_ca' => $i->displayName()['ca'] ?? '',
                    'name_es' => $i->displayName()['es'] ?? '',
                    'description_ca' => $i->description['ca'] ?? null,
                    'price' => $i->price_text === null && $i->effectivePrice() !== null ? $i->effectivePrice() / 100 : null,
                    'price_text' => $i->price_text,
                    'price_from_catalog' => $i->price === null && $i->price_text === null && $i->product_id !== null ? true : null,
                ], fn ($v) => $v !== null))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Data for the carta card shown in the chat.
     *
     * @return array<string, mixed>
     */
    public function preview(Carta $carta): array
    {
        return [
            'type' => 'carta',
            'id' => $carta->id,
            'name' => $carta->name,
            'subtitle' => $carta->subtitle,
            'notes' => $carta->notes,
            'template' => $carta->template,
            'active' => $carta->active,
            'sections' => $carta->sections->map(fn (CartaSection $s) => [
                'name' => $s->name,
                'items' => $s->items->map(fn (CartaItem $i) => [
                    'name' => $i->displayName(),
                    'description' => $i->description,
                    'price' => $i->effectivePrice(),
                    'priceText' => $i->price_text,
                    'fromCatalog' => $i->price === null && $i->price_text === null && $i->product_id !== null,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }
}
