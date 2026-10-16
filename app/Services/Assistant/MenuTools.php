<?php

namespace App\Services\Assistant;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\SetMenu;
use App\Models\SetMenuSection;
use App\Models\SetMenuSectionItem;
use App\Models\User;
use App\Services\Catalog\MenuDocument;
use App\Services\Catalog\SetMenuWriter;
use App\Support\AppSettings;
use App\Support\Translation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What "Carta amb IA" can do: read and change families, products and set menus. Every
 * write is audited under the user who asked, and returns a short label and a preview the
 * chat shows as a card. Errors go back to the model as text so it can correct itself.
 */
class MenuTools
{
    /** The model asked the user something: the turn ends and the options become buttons. */
    public const ASK = 'ask_user';

    private bool $changed = false;

    public function __construct(
        private readonly User $user,
        private readonly SetMenuWriter $menus,
    ) {}

    public function changed(): bool
    {
        return $this->changed;
    }

    /**
     * OpenAI-style tool definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $name = ['name_ca' => ['type' => 'string', 'description' => 'Nombre en catalán'], 'name_es' => ['type' => 'string', 'description' => 'Nombre en castellano']];
        $price = ['type' => ['number', 'null'], 'description' => 'Precio en euros con IVA (2.5 = 2,50 €). null = sin precio (se pone durante el servicio).'];
        $position = ['type' => 'integer', 'description' => 'Posición dentro de la familia/lista, empezando por 1 (1 = la primera).'];

        return [
            self::fn('get_catalog', 'Lee la carta actual: familias (con subfamilias), productos con precio e ids, y grupos de opciones. Úsalo antes de crear o cambiar nada para conocer los ids.', []),
            self::fn('create_family', 'Crea una familia (categoría) de la carta.', $name + [
                'color' => ['type' => 'string', 'description' => 'Color de fondo #rrggbb (que contraste bien con texto blanco o negro).'],
                'parent_id' => ['type' => ['integer', 'null'], 'description' => 'Id de la familia padre si es subfamilia.'],
                'position' => $position,
            ], ['name_ca', 'name_es']),
            self::fn('update_family', 'Cambia una familia: nombre, color, posición o si está activa.', ['id' => ['type' => 'integer']] + $name + [
                'color' => ['type' => 'string'],
                'position' => $position,
                'active' => ['type' => 'boolean'],
            ], ['id']),
            self::fn('create_product', 'Añade un producto a una familia.', ['family_id' => ['type' => 'integer']] + $name + [
                'price' => $price,
                'position' => $position,
                'option_group_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Grupos de opciones (leche, azúcar…) que abre el producto.'],
            ], ['family_id', 'name_ca', 'name_es']),
            self::fn('update_product', 'Cambia un producto: nombre, precio, familia, posición, opciones, agotado o activo.', ['id' => ['type' => 'integer']] + $name + [
                'price' => $price,
                'family_id' => ['type' => 'integer', 'description' => 'Moverlo a otra familia.'],
                'position' => $position,
                'option_group_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'sold_out' => ['type' => 'boolean'],
                'active' => ['type' => 'boolean'],
            ], ['id']),
            self::fn('delete_product', 'Retira un producto de la carta (no borra el historial de ventas). SOLO después de que el usuario lo haya confirmado explícitamente.', [
                'id' => ['type' => 'integer'],
                'confirmed' => ['type' => 'boolean', 'description' => 'true solo si el usuario ya ha confirmado el borrado.'],
            ], ['id', 'confirmed']),
            self::fn('get_set_menus', 'Lee los menús del día (precio, horario, secciones y platos) con sus ids.', []),
            self::fn('save_set_menu', 'Crea un menú del día (sin id) o reescribe uno existente (con id). Hay que mandar el menú COMPLETO: todas las secciones y platos.', [
                'id' => ['type' => ['integer', 'null'], 'description' => 'Id del menú a cambiar; null para crear uno nuevo.'],
                'name_ca' => ['type' => 'string'],
                'name_es' => ['type' => 'string'],
                'price' => ['type' => 'number', 'description' => 'Precio del menú en euros con IVA.'],
                'includes_ca' => ['type' => ['string', 'null'], 'description' => 'Qué incluye (pan, bebida, café…), en catalán.'],
                'includes_es' => ['type' => ['string', 'null']],
                'schedule' => ['type' => 'string', 'enum' => ['always', 'weekdays', 'dates'], 'description' => 'always = cada día; weekdays = ciertos días de la semana; dates = entre dos fechas.'],
                'weekdays' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Días ISO: 1 lunes … 7 domingo (para weekdays).'],
                'starts_on' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD (para dates).'],
                'ends_on' => ['type' => ['string', 'null'], 'description' => 'YYYY-MM-DD (para dates).'],
                'active' => ['type' => 'boolean'],
                'color' => ['type' => ['string', 'null']],
                'template' => self::templateParam(),
                'sections' => [
                    'type' => 'array',
                    'description' => 'Secciones en orden (Primers, Segons, Postres…).',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name_ca' => ['type' => 'string'],
                            'name_es' => ['type' => 'string'],
                            'choices' => ['type' => 'integer', 'description' => 'Cuántos platos elige el cliente de esta sección (normalmente 1).'],
                            'course' => ['type' => ['integer', 'null'], 'description' => '1 primero, 2 segundo, 3 postre; null si no aplica (bebida).'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_id' => ['type' => ['integer', 'null'], 'description' => 'Producto de la carta, si existe.'],
                                        'name_ca' => ['type' => ['string', 'null'], 'description' => 'Nombre del plato si no es un producto de la carta.'],
                                        'name_es' => ['type' => ['string', 'null']],
                                        'supplement' => ['type' => ['number', 'null'], 'description' => 'Suplemento en euros (0 si no hay).'],
                                    ],
                                ],
                            ],
                        ],
                        'required' => ['name_ca', 'name_es', 'items'],
                    ],
                ],
            ], ['name_ca', 'name_es', 'price', 'schedule', 'sections']),
            self::fn('set_menu_template', 'Cambia solo el diseño con el que se imprime un menú (sin tocar sus platos).', [
                'id' => ['type' => 'integer'],
                'template' => self::templateParam(),
            ], ['id', 'template']),
            self::fn('delete_set_menu', 'Borra un menú del día. SOLO después de que el usuario lo haya confirmado explícitamente.', [
                'id' => ['type' => 'integer'],
                'confirmed' => ['type' => 'boolean'],
            ], ['id', 'confirmed']),
            self::fn(self::ASK, 'Pregunta al usuario y le muestra botones grandes para responder con un toque. Úsalo cuando falte información, para confirmar borrados y, al terminar un encargo, para ofrecer siguientes pasos. Termina tu turno.', [
                'question' => ['type' => 'string', 'description' => 'Pregunta corta.'],
                'options' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'De 2 a 5 respuestas cortas (máx. 6 palabras), escritas como las diría el usuario.'],
            ], ['question', 'options']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function templateParam(): array
    {
        return [
            'type' => 'string',
            'enum' => MenuDocument::TEMPLATES,
            'description' => 'Diseño del menú impreso: classic (normal), halloween, christmas (Navidad), easter (Pascua), kids (infantil, colorido).',
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
     * Short "what am I doing" text shown while a tool runs.
     */
    public static function status(string $tool): ?string
    {
        return match ($tool) {
            'get_catalog' => 'catalog',
            'get_set_menus' => 'menus',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    public function run(string $tool, array $args): array
    {
        try {
            return DB::transaction(fn () => match ($tool) {
                'get_catalog' => ['result' => $this->catalog()],
                'get_set_menus' => ['result' => ['menus' => SetMenu::query()->with('sections.items.product')->orderBy('sort')->get()->map(fn (SetMenu $m) => $this->menuData($m))->all()]],
                'create_family' => $this->createFamily($args),
                'update_family' => $this->updateFamily($args),
                'create_product' => $this->createProduct($args),
                'update_product' => $this->updateProduct($args),
                'delete_product' => $this->deleteProduct($args),
                'save_set_menu' => $this->saveMenu($args),
                'set_menu_template' => $this->menuTemplate($args),
                'delete_set_menu' => $this->deleteMenu($args),
                default => throw new MenuToolError("Herramienta desconocida: {$tool}"),
            });
        } catch (MenuToolError $e) {
            return ['result' => ['error' => $e->getMessage()]];
        }
    }

    // ------------------------------------------------------------------ catalog

    /**
     * @return array<string, mixed>
     */
    private function catalog(): array
    {
        $products = Product::query()->with('modifierGroups:id')->orderBy('sort')->orderBy('id')->get()->groupBy('category_id');

        return [
            'families' => Category::query()->orderBy('sort')->orderBy('id')->get()->map(fn (Category $c) => [
                'id' => $c->id,
                'parent_id' => $c->parent_id,
                'name_ca' => $c->name['ca'] ?? '',
                'name_es' => $c->name['es'] ?? '',
                'color' => $c->color,
                'active' => $c->active,
                'products' => ($products->get($c->id) ?? collect())->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name_ca' => $p->name['ca'] ?? '',
                    'name_es' => $p->name['es'] ?? '',
                    'price' => $p->price === null ? null : $p->price / 100,
                    'sold_out' => $p->sold_out,
                    'active' => $p->active,
                    'option_group_ids' => $p->modifierGroups->pluck('id')->all(),
                ])->values()->all(),
            ])->all(),
            'option_groups' => ModifierGroup::query()->with('modifiers')->orderBy('sort')->get()->map(fn (ModifierGroup $g) => [
                'id' => $g->id,
                'name' => $g->name['ca'] ?? $g->name['es'] ?? '',
                'options' => $g->modifiers->map(fn ($m) => $m->name['ca'] ?? $m->name['es'] ?? '')->all(),
            ])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function createFamily(array $args): array
    {
        $name = $this->names($args, true);
        $parentId = $this->optionalInt($args, 'parent_id');

        if ($parentId !== null && ! Category::query()->whereKey($parentId)->exists()) {
            throw new MenuToolError("No existe la familia padre {$parentId}.");
        }

        $family = Category::query()->create([
            'parent_id' => $parentId,
            'name' => $name,
            'color' => $this->color($args['color'] ?? null) ?? '#00056a',
            'active' => true,
            'sort' => (int) Category::query()->where('parent_id', $parentId)->max('sort') + 1,
        ]);
        $this->reposition(Category::query()->where('parent_id', $parentId), $family, $args['position'] ?? null);
        $this->audit('ai.family.create', $family, ['name' => $name]);

        return $this->familyResult($family, 'created', "Família «{$name['ca']}» creada");
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function updateFamily(array $args): array
    {
        $family = Category::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe esa familia.');
        $name = $this->names($args, false);

        if ($name !== null) {
            $family->name = $name;
        }

        if (array_key_exists('color', $args)) {
            $family->color = $this->color($args['color']) ?? $family->color;
        }

        if (isset($args['active'])) {
            $family->active = (bool) $args['active'];
        }

        $family->save();
        $this->reposition(Category::query()->where('parent_id', $family->parent_id), $family, $args['position'] ?? null);
        $this->audit('ai.family.update', $family, ['args' => $args]);

        return $this->familyResult($family->refresh(), 'updated', 'Família «'.($family->name['ca'] ?? '').'» actualitzada');
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function createProduct(array $args): array
    {
        $family = Category::query()->find((int) ($args['family_id'] ?? 0)) ?? throw new MenuToolError('No existe esa familia: mira los ids con get_catalog.');
        $name = $this->names($args, true);

        $product = Product::query()->create([
            'category_id' => $family->id,
            'name' => $name,
            'price' => $this->cents($args['price'] ?? null),
            'vat_rate' => (float) AppSettings::get('default_vat_rate', 10),
            'allergens' => [],
            'active' => true,
            'sold_out' => false,
            'sort' => (int) Product::query()->where('category_id', $family->id)->max('sort') + 1,
        ]);

        if (is_array($args['option_group_ids'] ?? null)) {
            $product->modifierGroups()->sync($this->groupIds($args['option_group_ids']));
        }

        $this->reposition(Product::query()->where('category_id', $family->id), $product, $args['position'] ?? null);
        $this->audit('ai.product.create', $product, ['name' => $name, 'price' => $product->price]);

        return $this->familyResult($family, 'created', "«{$name['ca']}» afegit a {$family->name['ca']}".($product->price === null ? ' (sense preu)' : ' · '.$this->euros($product->price)), ['product_id' => $product->id]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function updateProduct(array $args): array
    {
        $product = Product::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe ese producto: mira los ids con get_catalog.');
        $name = $this->names($args, false);
        $changes = [];

        if ($name !== null) {
            $product->name = $name;
            $changes[] = 'nom';
        }

        if (array_key_exists('price', $args)) {
            $product->price = $this->cents($args['price']);
            $changes[] = $product->price === null ? 'sense preu' : $this->euros($product->price);
        }

        if (isset($args['family_id']) && (int) $args['family_id'] !== $product->category_id) {
            $family = Category::query()->find((int) $args['family_id']) ?? throw new MenuToolError('No existe la familia de destino.');
            $product->category_id = $family->id;
            $product->sort = (int) Product::query()->where('category_id', $family->id)->max('sort') + 1;
            $changes[] = 'a '.($family->name['ca'] ?? '');
        }

        if (isset($args['sold_out'])) {
            $product->sold_out = (bool) $args['sold_out'];
            $changes[] = $product->sold_out ? 'esgotat' : 'disponible';
        }

        if (isset($args['active'])) {
            $product->active = (bool) $args['active'];
            $changes[] = $product->active ? 'actiu' : 'amagat';
        }

        $product->save();

        if (is_array($args['option_group_ids'] ?? null)) {
            $product->modifierGroups()->sync($this->groupIds($args['option_group_ids']));
            $product->touch();
            $changes[] = 'opcions';
        }

        if (isset($args['position'])) {
            $changes[] = 'posició '.(int) $args['position'];
        }

        $this->reposition(Product::query()->where('category_id', $product->category_id), $product, $args['position'] ?? null);
        $this->audit('ai.product.update', $product, ['args' => $args]);

        return $this->familyResult($product->category, 'updated', '«'.($product->name['ca'] ?? '').'» '.($changes ? implode(', ', $changes) : 'actualitzat'), ['product_id' => $product->id]);
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function deleteProduct(array $args): array
    {
        if (($args['confirmed'] ?? false) !== true) {
            throw new MenuToolError('Primero pide confirmación al usuario con ask_user.');
        }

        $product = Product::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe ese producto.');
        $family = $product->category;
        $product->delete();
        $this->audit('ai.product.delete', $product, ['name' => $product->name]);

        return $this->familyResult($family, 'deleted', '«'.($product->name['ca'] ?? '').'» retirat de la carta');
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array{result: array<string, mixed>, action: array{kind: string, label: string}, preview: array<string, mixed>}
     */
    private function familyResult(Category $family, string $kind, string $label, array $extra = []): array
    {
        $this->changed = true;
        $family->load(['products' => fn ($q) => $q->orderBy('sort')->orderBy('id')]);

        return [
            'result' => ['ok' => true, 'family_id' => $family->id] + $extra,
            'action' => ['kind' => $kind, 'label' => $label],
            'preview' => [
                'type' => 'family',
                'id' => $family->id,
                'name' => $family->name,
                'color' => $family->effectiveColor(),
                'photoUrl' => $family->photoUrl(),
                'active' => $family->active,
                'products' => $family->products->map(fn (Product $p) => [
                    'id' => $p->id,
                    'name' => $p->name,
                    'price' => $p->price,
                    'soldOut' => $p->sold_out,
                    'active' => $p->active,
                ])->values()->all(),
            ],
        ];
    }

    // ------------------------------------------------------------------ set menus

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function saveMenu(array $args): array
    {
        $id = $this->optionalInt($args, 'id');
        $menu = $id !== null ? (SetMenu::query()->find($id) ?? throw new MenuToolError('No existe ese menú: mira los ids con get_set_menus.')) : new SetMenu;
        $name = $this->names($args, true);
        $schedule = in_array($args['schedule'] ?? null, ['always', 'weekdays', 'dates'], true) ? $args['schedule'] : 'always';
        $weekdays = array_values(array_unique(array_filter(array_map('intval', (array) ($args['weekdays'] ?? [])), fn (int $d) => $d >= 1 && $d <= 7)));

        if ($schedule === 'weekdays' && $weekdays === []) {
            throw new MenuToolError('Faltan los días de la semana (weekdays).');
        }

        $sections = [];

        foreach ((array) ($args['sections'] ?? []) as $section) {
            if (! is_array($section)) {
                continue;
            }

            $items = [];

            foreach ((array) ($section['items'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }

                $productId = $this->optionalInt($item, 'product_id');

                if ($productId !== null && ! Product::query()->whereKey($productId)->exists()) {
                    throw new MenuToolError("No existe el producto {$productId}.");
                }

                $itemName = $this->names($item, false);

                if ($productId === null && $itemName === null) {
                    throw new MenuToolError('Cada plato necesita product_id o nombre.');
                }

                $items[] = ['productId' => $productId, 'name' => $itemName, 'supplement' => $this->cents($item['supplement'] ?? null) ?? 0];
            }

            $sections[] = [
                'name' => $this->names($section, true),
                'choices' => max(1, min(10, (int) ($section['choices'] ?? 1))),
                'course' => in_array($section['course'] ?? null, [1, 2, 3], true) ? $section['course'] : null,
                'items' => $items,
            ];
        }

        if ($sections === []) {
            throw new MenuToolError('El menú necesita al menos una sección con platos.');
        }

        if ($menu->exists === false) {
            $menu->sort = (int) SetMenu::query()->max('sort') + 1;
        }

        $creating = ! $menu->exists;
        $includesCa = trim((string) ($args['includes_ca'] ?? ''));
        $includesEs = trim((string) ($args['includes_es'] ?? ''));

        $this->menus->save($menu, [
            'name' => $name,
            'includes' => $includesCa !== '' || $includesEs !== '' ? ['ca' => $includesCa ?: $includesEs, 'es' => $includesEs ?: $includesCa] : null,
            'price' => $this->cents($args['price'] ?? 0) ?? 0,
            'vatRate' => $menu->vat_rate ?? (float) AppSettings::get('default_vat_rate', 10),
            'color' => $this->color($args['color'] ?? null) ?? $menu->color,
            'scheduleType' => $schedule,
            'weekdays' => $weekdays,
            'startsOn' => $args['starts_on'] ?? null,
            'endsOn' => $args['ends_on'] ?? null,
            'active' => (bool) ($args['active'] ?? true),
            'template' => $args['template'] ?? null,
            'sections' => $sections,
        ]);

        $this->changed = true;
        $menu = $menu->fresh(['sections.items.product']) ?? $menu;
        $this->audit($creating ? 'ai.menu.create' : 'ai.menu.update', $menu, ['name' => $name]);

        return [
            'result' => ['ok' => true, 'menu_id' => $menu->id],
            'action' => ['kind' => $creating ? 'created' : 'updated', 'label' => ($creating ? 'Menú creat: ' : 'Menú actualitzat: ').$name['ca']],
            'preview' => ['type' => 'set_menu'] + $this->menuPreview($menu),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function menuTemplate(array $args): array
    {
        $menu = SetMenu::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe ese menú.');
        $template = (string) ($args['template'] ?? '');

        if (! in_array($template, MenuDocument::TEMPLATES, true)) {
            throw new MenuToolError('Plantilla no válida.');
        }

        $menu->forceFill(['template' => $template])->save();
        $this->changed = true;
        $this->audit('ai.menu.template', $menu, ['template' => $template]);
        $menu->load('sections.items.product');

        return [
            'result' => ['ok' => true],
            'action' => ['kind' => 'updated', 'label' => 'Disseny del menú: '.$template],
            'preview' => ['type' => 'set_menu'] + $this->menuPreview($menu),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function deleteMenu(array $args): array
    {
        if (($args['confirmed'] ?? false) !== true) {
            throw new MenuToolError('Primero pide confirmación al usuario con ask_user.');
        }

        $menu = SetMenu::query()->find((int) ($args['id'] ?? 0)) ?? throw new MenuToolError('No existe ese menú.');
        $menu->delete();
        $this->changed = true;
        $this->audit('ai.menu.delete', $menu, ['name' => $menu->name]);

        return ['result' => ['ok' => true], 'action' => ['kind' => 'deleted', 'label' => 'Menú esborrat: '.($menu->name['ca'] ?? '')]];
    }

    /**
     * @return array<string, mixed>
     */
    private function menuData(SetMenu $menu): array
    {
        return [
            'id' => $menu->id,
            'name_ca' => $menu->name['ca'] ?? '',
            'name_es' => $menu->name['es'] ?? '',
            'price' => $menu->price / 100,
            'includes_ca' => $menu->includes['ca'] ?? null,
            'schedule' => $menu->schedule_type,
            'template' => $menu->template,
            'weekdays' => $menu->weekdays,
            'starts_on' => $menu->starts_on?->toDateString(),
            'ends_on' => $menu->ends_on?->toDateString(),
            'active' => $menu->active,
            'sections' => $menu->sections->map(fn (SetMenuSection $s) => [
                'name_ca' => $s->name['ca'] ?? '',
                'name_es' => $s->name['es'] ?? '',
                'choices' => $s->choices,
                'course' => $s->course,
                'items' => $s->items->map(fn (SetMenuSectionItem $i) => array_filter([
                    'product_id' => $i->product_id,
                    'name_ca' => $i->name['ca'] ?? $i->product?->name['ca'] ?? null,
                    'name_es' => $i->name['es'] ?? $i->product?->name['es'] ?? null,
                    'supplement' => $i->supplement ? $i->supplement / 100 : null,
                ], fn ($v) => $v !== null))->values()->all(),
            ])->values()->all(),
        ];
    }

    /**
     * Data for the menu card shown in the chat.
     *
     * @return array<string, mixed>
     */
    public function menuPreview(SetMenu $menu): array
    {
        return [
            'id' => $menu->id,
            'name' => $menu->name,
            'includes' => $menu->includes,
            'price' => $menu->price,
            'color' => $menu->color,
            'template' => $menu->template,
            'active' => $menu->active,
            'scheduleType' => $menu->schedule_type,
            'weekdays' => $menu->weekdays ?? [],
            'startsOn' => $menu->starts_on?->toDateString(),
            'endsOn' => $menu->ends_on?->toDateString(),
            'sections' => $menu->sections->map(fn (SetMenuSection $s) => [
                'name' => $s->name,
                'choices' => $s->choices,
                'items' => $s->items->map(fn (SetMenuSectionItem $i) => [
                    'name' => $i->name ?? $i->product->name ?? ['ca' => '', 'es' => ''],
                    'supplement' => $i->supplement,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    // ------------------------------------------------------------------ helpers

    /**
     * @param  array<string, mixed>  $args
     * @return array{ca: string, es: string}|null
     */
    private function names(array $args, bool $required): ?array
    {
        $ca = is_string($args['name_ca'] ?? null) ? mb_substr(trim($args['name_ca']), 0, 80) : '';
        $es = is_string($args['name_es'] ?? null) ? mb_substr(trim($args['name_es']), 0, 80) : '';

        if ($ca === '' && $es === '') {
            if ($required) {
                throw new MenuToolError('Falta el nombre (name_ca y name_es).');
            }

            return null;
        }

        return Translation::normalize(['ca' => $ca !== '' ? $ca : $es, 'es' => $es !== '' ? $es : $ca]);
    }

    private function cents(mixed $euros): ?int
    {
        if ($euros === null || $euros === '') {
            return null;
        }

        if (is_string($euros)) {
            $euros = str_replace(',', '.', $euros);
        }

        if (! is_numeric($euros) || (float) $euros < 0 || (float) $euros > 10000) {
            throw new MenuToolError('Precio no válido.');
        }

        return (int) round((float) $euros * 100);
    }

    private function euros(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    private function color(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function optionalInt(array $args, string $key): ?int
    {
        return is_numeric($args[$key] ?? null) ? (int) $args[$key] : null;
    }

    /**
     * @param  array<mixed>  $ids
     * @return list<int>
     */
    private function groupIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));

        return array_values(array_map('intval', ModifierGroup::query()->whereIn('id', $ids)->pluck('id')->all()));
    }

    /**
     * Put $item at 1-based $position among its siblings and renumber them.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $siblings
     * @param  T  $item
     */
    private function reposition(Builder $siblings, Model $item, mixed $position): void
    {
        if (! is_numeric($position)) {
            return;
        }

        /** @var Collection<int, Model> $list */
        $list = $siblings->orderBy('sort')->orderBy('id')->get()->reject(fn (Model $m) => $m->getKey() === $item->getKey())->values();
        $index = max(0, min($list->count(), (int) $position - 1));
        $list->splice($index, 0, [$item]);

        foreach ($list as $sort => $model) {
            if ((int) $model->getAttribute('sort') !== $sort + 1) {
                $model->forceFill(['sort' => $sort + 1])->save();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function audit(string $action, Model $subject, array $data): void
    {
        AuditLog::record($action, $subject, $data, userId: $this->user->id);
    }
}
