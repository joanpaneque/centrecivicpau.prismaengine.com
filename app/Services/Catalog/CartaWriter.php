<?php

namespace App\Services\Catalog;

use App\Jobs\TranslateCarta;
use App\Models\Carta;
use App\Support\Translation;

/**
 * Saves a carta with its sections and dishes (back office form and the assistant), then
 * translates it to the printing languages in the background.
 */
class CartaWriter
{
    /**
     * @param  array<string, mixed>  $data  name, subtitle, active, template,
     *                                      sections[id?, name, items[id?, productId, name, description, price (cents|null)]]
     */
    public function save(Carta $carta, array $data): void
    {
        $carta->fill([
            'name' => Translation::normalize($data['name']),
            'subtitle' => $this->filled($data['subtitle'] ?? null) ? Translation::normalize($data['subtitle']) : null,
            'active' => $data['active'] ?? true,
        ]);

        if (isset($data['template']) && in_array($data['template'], MenuDocument::TEMPLATES, true)) {
            $carta->template = $data['template'];
        }

        if (! $carta->exists) {
            $carta->sort = (int) Carta::query()->max('sort') + 1;
        }

        $carta->save();

        $keepSections = [];

        foreach (array_values($data['sections'] ?? []) as $sort => $row) {
            $section = isset($row['id']) ? $carta->sections()->whereKey($row['id'])->first() : null;
            $section ??= $carta->sections()->make();
            $section->fill(['name' => Translation::normalize($row['name']), 'sort' => $sort])->save();
            $keepSections[] = $section->id;

            $keepItems = [];

            foreach (array_values($row['items'] ?? []) as $itemSort => $itemRow) {
                $item = isset($itemRow['id']) ? $section->items()->whereKey($itemRow['id'])->first() : null;
                $item ??= $section->items()->make();
                $item->fill([
                    'product_id' => $itemRow['productId'] ?? null,
                    'name' => $this->filled($itemRow['name'] ?? null) ? Translation::normalize($itemRow['name']) : null,
                    'description' => $this->filled($itemRow['description'] ?? null) ? Translation::normalize($itemRow['description']) : null,
                    'price' => isset($itemRow['price']) ? max(0, (int) $itemRow['price']) : null,
                    'sort' => $itemSort,
                ])->save();
                $keepItems[] = $item->id;
            }

            $section->items()->whereNotIn('id', $keepItems)->delete();
        }

        $carta->sections()->whereNotIn('id', $keepSections)->delete();
        $carta->touch();

        TranslateCarta::dispatch($carta->id)->afterCommit();
    }

    private function filled(mixed $value): bool
    {
        return is_array($value) && trim(($value['ca'] ?? '').($value['es'] ?? '')) !== '';
    }
}
