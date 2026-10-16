<?php

namespace App\Services\Assistant;

use App\Models\Category;
use App\Models\DiningTable;
use App\Models\ModifierGroup;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Taking orders by voice on the POS ("dos canyes a la taula 4"). The server only resolves
 * and checks: the table, the products, their options. The lines it returns are added on the
 * tablet itself, to that table's unsent lines, through the same offline-first path as a
 * tap, so kitchen and bar routing work as usual. What cannot be resolved comes back as a
 * problem for the model to ask about, without holding back the rest.
 */
class OrderTools
{
    /**
     * @return list<string>
     */
    public static function names(): array
    {
        return ['get_floor', 'add_to_order'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            [
                'type' => 'function',
                'function' => [
                    'name' => 'get_floor',
                    'description' => 'Salas y mesas del local con su estado (libre/ocupada), comensales, importe pendiente y lo que ya tienen pedido.',
                    'parameters' => ['type' => 'object', 'properties' => (object) []],
                ],
            ],
            [
                'type' => 'function',
                'function' => [
                    'name' => 'add_to_order',
                    'description' => 'Añade productos a una mesa (quedan por enviar en el TPV, o se envían a cocina/barra si send=true). Pon solo lo que esté claro; lo dudoso pregúntalo aparte.',
                    'parameters' => [
                        'type' => 'object',
                        'properties' => [
                            'table' => ['type' => 'string', 'description' => 'Nombre/número de la mesa tal como se ve en el plano (p. ej. "4", "T2", "B1").'],
                            'zone' => ['type' => 'string', 'description' => 'Sala o zona, si hay mesas con el mismo nombre en varias.'],
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'product_id' => ['type' => 'integer'],
                                        'quantity' => ['type' => 'integer'],
                                        'option_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Ids de opciones (leche, azúcar, marca…) de los grupos del producto.'],
                                        'note' => ['type' => 'string', 'description' => 'Nota para cocina/barra (sin sal, poco hecho…).'],
                                    ],
                                    'required' => ['product_id', 'quantity'],
                                ],
                            ],
                            'send' => ['type' => 'boolean', 'description' => 'true solo si piden enviarlo/marcharlo ya a cocina o barra.'],
                        ],
                        'required' => ['table', 'items'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    public function run(string $tool, array $args): array
    {
        try {
            return match ($tool) {
                'get_floor' => ['result' => $this->floor()],
                'add_to_order' => $this->add($args),
                default => throw new MenuToolError("Herramienta desconocida: {$tool}"),
            };
        } catch (MenuToolError $e) {
            return ['result' => ['error' => $e->getMessage()]];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function floor(): array
    {
        $orders = Order::query()->with('lines')->whereIn('status', Order::ACTIVE_STATUSES)->whereNotNull('dining_table_id')->get()->keyBy('dining_table_id');

        return [
            'tables' => DiningTable::query()->with('zone')->orderBy('zone_id')->orderBy('sort')->get()->map(function (DiningTable $table) use ($orders) {
                $order = $orders->get($table->id);

                return array_filter([
                    'id' => $table->id,
                    'label' => $table->label,
                    'zone' => $table->zone->name['ca'] ?? '',
                    'occupied' => $order !== null,
                    'guests' => $order?->guests,
                    'ordered' => $order ? $order->lines->whereNull('parent_line_id')->whereNull('voided_at')
                        ->map(fn (OrderLine $l) => $l->quantity.'× '.($l->name['ca'] ?? ''))->values()->all() : null,
                ], fn ($value) => $value !== null);
            })->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{result: array<string, mixed>, action?: array{kind: string, label: string}, preview?: array<string, mixed>}
     */
    private function add(array $args): array
    {
        $table = $this->table((string) ($args['table'] ?? ''), is_string($args['zone'] ?? null) ? $args['zone'] : null);
        $lines = [];
        $problems = [];

        foreach ((array) ($args['items'] ?? []) as $item) {
            if (! is_array($item)) {
                continue;
            }

            $product = Product::query()->with('modifierGroups.modifiers')->find((int) ($item['product_id'] ?? 0));
            $quantity = max(1, min(50, (int) ($item['quantity'] ?? 1)));

            if ($product === null || ! $product->active) {
                $problems[] = 'No existe el producto '.($item['product_id'] ?? '?').'.';

                continue;
            }

            $name = $product->name['ca'] ?? '';

            if ($product->sold_out) {
                $problems[] = "«{$name}» está agotado.";

                continue;
            }

            $groups = $this->groups($product);
            $chosen = array_map('intval', array_filter((array) ($item['option_ids'] ?? []), 'is_numeric'));
            $modifiers = [];

            foreach ($groups as $group) {
                $picked = $group->modifiers->whereIn('id', $chosen)->values();

                if (! $group->multiple && $picked->count() > 1) {
                    $picked = $picked->take(1);
                }

                if ($group->required && $picked->isEmpty()) {
                    $problems[] = "«{$name}» necesita «".($group->name['ca'] ?? '').'»: '.$group->modifiers->map(fn ($m) => ($m->name['ca'] ?? '').' (id '.$m->id.')')->implode(', ').'.';

                    continue 2;
                }

                foreach ($picked as $modifier) {
                    $modifiers[] = ['id' => $modifier->id, 'name' => $modifier->name, 'priceDelta' => $modifier->price_delta];
                }
            }

            $note = is_string($item['note'] ?? null) ? mb_substr(trim($item['note']), 0, 250) : '';
            $lines[] = [
                'productId' => $product->id,
                'name' => $product->name,
                'quantity' => $quantity,
                'modifiers' => $modifiers,
                'note' => $note,
                'unitPrice' => $product->price,
            ];
        }

        if ($lines === []) {
            return ['result' => ['added' => [], 'problems' => $problems ?: ['No hay nada que añadir.']]];
        }

        $send = (bool) ($args['send'] ?? false);
        $summary = implode(', ', array_map(fn (array $l) => $l['quantity'].'× '.($l['name']['ca'] ?? ''), $lines));

        return [
            'result' => [
                'added' => array_map(fn (array $l) => $l['quantity'].'× '.($l['name']['ca'] ?? '').($l['modifiers'] ? ' ('.implode(', ', array_map(fn ($m) => $m['name']['ca'] ?? '', $l['modifiers'])).')' : ''), $lines),
                'table' => $table->label,
                'sent' => $send,
                'problems' => $problems,
            ],
            'action' => ['kind' => 'created', 'label' => ($send ? 'Enviat a la taula ' : 'Afegit a la taula ').$table->label.': '.$summary],
            'preview' => [
                'type' => 'order',
                'id' => (string) Str::uuid(),
                'tableId' => $table->id,
                'tableLabel' => $table->label,
                'zone' => $table->zone->name ?? null,
                'send' => $send,
                'lines' => $lines,
                'problems' => $problems,
            ],
        ];
    }

    private function table(string $label, ?string $zone): DiningTable
    {
        $label = trim($label);

        if ($label === '') {
            throw new MenuToolError('Falta la mesa: pregúntala.');
        }

        $normalize = fn (string $value) => mb_strtolower(preg_replace('/^(taula|mesa|table)\s*/iu', '', trim($value)) ?? '');
        $tables = DiningTable::query()->with('zone')->get()->filter(fn (DiningTable $t) => $normalize($t->label) === $normalize($label));

        if ($zone !== null && trim($zone) !== '') {
            $inZone = $tables->filter(fn (DiningTable $t) => str_contains(mb_strtolower(implode(' ', $t->zone->name ?? [])), mb_strtolower(trim($zone))));
            $tables = $inZone->isNotEmpty() ? $inZone : $tables;
        }

        if ($tables->isEmpty()) {
            throw new MenuToolError("No hay ninguna mesa «{$label}». Mira el plano con get_floor o pregunta cuál es.");
        }

        if ($tables->count() > 1) {
            throw new MenuToolError("Hay varias mesas «{$label}» (".$tables->map(fn (DiningTable $t) => $t->zone->name['ca'] ?? '')->implode(', ').'): pregunta en qué sala.');
        }

        return $tables->first();
    }

    /**
     * Option groups of the product and of its families, in their order.
     *
     * @return Collection<int, ModifierGroup>
     */
    private function groups(Product $product): Collection
    {
        $groups = $product->modifierGroups;
        $category = Category::query()->with('modifierGroups.modifiers')->find($product->category_id);
        $guard = 0;

        while ($category && $guard++ < 10) {
            $groups = $groups->merge($category->modifierGroups);
            $category = $category->parent_id ? Category::query()->with('modifierGroups.modifiers')->find($category->parent_id) : null;
        }

        return $groups->unique('id')->filter(fn (ModifierGroup $g) => $g->deleted_at === null && $g->modifiers->isNotEmpty())->sortBy('sort')->values();
    }
}
