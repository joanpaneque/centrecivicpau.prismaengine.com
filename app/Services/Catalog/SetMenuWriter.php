<?php

namespace App\Services\Catalog;

use App\Jobs\TranslateSetMenu;
use App\Models\SetMenu;
use App\Support\Translation;

/**
 * Saves a set menu with its sections and items (back office form and Carta amb IA), then
 * translates it to the printing languages in the background.
 */
class SetMenuWriter
{
    /**
     * @param  array<string, mixed>  $data  name, includes, price (cents), vatRate, color, scheduleType, weekdays,
     *                                      startsOn, endsOn, active, sections[name, choices, course, items[productId|name, destinationId, supplement]]
     */
    public function save(SetMenu $menu, array $data): void
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
        ]);

        if (isset($data['template']) && in_array($data['template'], MenuDocument::TEMPLATES, true)) {
            $menu->template = $data['template'];
        }

        $menu->save();

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

        TranslateSetMenu::dispatch($menu->id)->afterCommit();
    }
}
