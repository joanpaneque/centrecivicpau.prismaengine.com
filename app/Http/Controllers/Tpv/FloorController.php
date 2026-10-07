<?php

namespace App\Http\Controllers\Tpv;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DiningTable;
use App\Models\FloorElement;
use App\Models\Order;
use App\Models\Zone;
use App\Services\Sync\SnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Floor plan editor. Layout is configuration, so it is edited online only (the tablet
 * disables the editor while offline).
 */
class FloorController extends Controller
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'zoneId' => ['required', 'exists:zones,id'],
            'label' => ['nullable', 'string', 'max:20'],
            'seats' => ['nullable', 'integer', 'min:1', 'max:30'],
            'x' => ['nullable', 'integer'],
            'y' => ['nullable', 'integer'],
            'width' => ['nullable', 'integer', 'min:40', 'max:400'],
            'height' => ['nullable', 'integer', 'min:40', 'max:400'],
            'rotation' => ['nullable', 'integer', 'min:-360', 'max:360'],
            'shape' => ['nullable', Rule::in(['square', 'round', 'rect', 'stool'])],
            'isAuxiliary' => ['boolean'],
        ]);

        $zone = Zone::query()->whereKey((int) $data['zoneId'])->firstOrFail();
        $label = $data['label'] ?? $this->nextLabel($zone, (bool) ($data['isAuxiliary'] ?? false));
        $shape = $data['shape'] ?? ($zone->is_bar ? 'stool' : 'square');

        $table = DiningTable::query()->create([
            'zone_id' => $zone->id,
            'label' => $label,
            'seats' => $data['seats'] ?? ($zone->is_bar ? 1 : 4),
            'x' => $data['x'] ?? 20,
            'y' => $data['y'] ?? 20,
            'width' => $data['width'] ?? ($shape === 'rect' ? 160 : ($shape === 'stool' ? 70 : 90)),
            'height' => $data['height'] ?? ($shape === 'stool' ? 70 : 90),
            'rotation' => $this->angle($data['rotation'] ?? 0),
            'shape' => $shape,
            'is_auxiliary' => (bool) ($data['isAuxiliary'] ?? false),
            'sort' => (int) DiningTable::query()->where('zone_id', $zone->id)->max('sort') + 1,
        ]);

        AuditLog::record('table.create', $table, ['label' => $table->label]);
        TpvChanged::notify(['floor']);

        return response()->json(['table' => $this->snapshots->table($table)]);
    }

    public function update(Request $request, DiningTable $table): JsonResponse
    {
        $data = $request->validate([
            'label' => ['sometimes', 'string', 'max:20'],
            'seats' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'x' => ['sometimes', 'integer'],
            'y' => ['sometimes', 'integer'],
            'width' => ['sometimes', 'integer', 'min:40', 'max:400'],
            'height' => ['sometimes', 'integer', 'min:40', 'max:400'],
            'rotation' => ['sometimes', 'integer', 'min:-360', 'max:360'],
            'shape' => ['sometimes', Rule::in(['square', 'round', 'rect', 'stool'])],
            'isAuxiliary' => ['sometimes', 'boolean'],
            'zoneId' => ['sometimes', 'exists:zones,id'],
        ]);

        $table->fill(array_filter([
            'label' => $data['label'] ?? null,
            'seats' => $data['seats'] ?? null,
            'x' => $data['x'] ?? null,
            'y' => $data['y'] ?? null,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'shape' => $data['shape'] ?? null,
            'zone_id' => $data['zoneId'] ?? null,
        ], fn ($v) => $v !== null));

        if (array_key_exists('isAuxiliary', $data)) {
            $table->is_auxiliary = (bool) $data['isAuxiliary'];
        }

        if (array_key_exists('rotation', $data)) {
            $table->rotation = $this->angle($data['rotation']);
        }

        $table->save();
        TpvChanged::notify(['floor']);

        return response()->json(['table' => $this->snapshots->table($table)]);
    }

    /**
     * Save the geometry of many tables and elements at once after editing the plan.
     */
    public function layout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tables' => ['array'],
            'tables.*.id' => ['required', 'integer', 'exists:dining_tables,id'],
            'tables.*.x' => ['required', 'integer', 'min:-2000', 'max:5000'],
            'tables.*.y' => ['required', 'integer', 'min:-2000', 'max:5000'],
            'tables.*.width' => ['sometimes', 'integer', 'min:40', 'max:400'],
            'tables.*.height' => ['sometimes', 'integer', 'min:40', 'max:400'],
            'tables.*.rotation' => ['sometimes', 'integer', 'min:-360', 'max:360'],
            'elements' => ['array'],
            'elements.*.id' => ['required', 'integer', 'exists:floor_elements,id'],
            'elements.*.x' => ['required', 'integer', 'min:-2000', 'max:5000'],
            'elements.*.y' => ['required', 'integer', 'min:-2000', 'max:5000'],
            'elements.*.width' => ['sometimes', 'integer', 'min:8', 'max:2000'],
            'elements.*.height' => ['sometimes', 'integer', 'min:8', 'max:2000'],
            'elements.*.rotation' => ['sometimes', 'integer', 'min:-360', 'max:360'],
        ]);

        foreach ($data['tables'] ?? [] as $row) {
            DiningTable::query()->whereKey($row['id'])->update($this->geometry($row));
        }

        foreach ($data['elements'] ?? [] as $row) {
            FloorElement::query()->whereKey($row['id'])->update($this->geometry($row));
        }

        TpvChanged::notify(['floor']);

        return response()->json(['ok' => true]);
    }

    public function storeElement(Request $request): JsonResponse
    {
        $data = $this->elementData($request, creating: true);
        $type = (string) $data['type'];
        [$width, $height] = FloorElement::SIZES[$type];

        $element = FloorElement::query()->create([
            'zone_id' => (int) $data['zoneId'],
            'type' => $type,
            'label' => $data['label'] ?? null,
            'x' => $data['x'] ?? 20,
            'y' => $data['y'] ?? 20,
            'width' => $data['width'] ?? $width,
            'height' => $data['height'] ?? $height,
            'rotation' => $this->angle($data['rotation'] ?? 0),
            'color' => $data['color'] ?? null,
            'sort' => (int) FloorElement::query()->where('zone_id', (int) $data['zoneId'])->max('sort') + 1,
        ]);

        TpvChanged::notify(['floor']);

        return response()->json(['element' => $this->snapshots->floorElement($element)]);
    }

    public function updateElement(Request $request, FloorElement $element): JsonResponse
    {
        $data = $this->elementData($request, creating: false);

        foreach (['label', 'color', 'x', 'y', 'width', 'height', 'type', 'sort'] as $key) {
            if (array_key_exists($key, $data)) {
                $element->{$key} = $data[$key];
            }
        }

        if (array_key_exists('rotation', $data)) {
            $element->rotation = $this->angle($data['rotation']);
        }

        $element->save();
        TpvChanged::notify(['floor']);

        return response()->json(['element' => $this->snapshots->floorElement($element)]);
    }

    public function destroyElement(FloorElement $element): JsonResponse
    {
        $element->delete();
        TpvChanged::notify(['floor']);

        return response()->json(['ok' => true]);
    }

    /**
     * @return array<string, mixed>
     */
    private function elementData(Request $request, bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'zoneId' => [$creating ? 'required' : 'prohibited', 'integer', 'exists:zones,id'],
            'type' => [$required, Rule::in(FloorElement::TYPES)],
            'label' => ['sometimes', 'nullable', 'string', 'max:40'],
            'x' => ['sometimes', 'integer', 'min:-2000', 'max:5000'],
            'y' => ['sometimes', 'integer', 'min:-2000', 'max:5000'],
            'width' => ['sometimes', 'integer', 'min:8', 'max:2000'],
            'height' => ['sometimes', 'integer', 'min:8', 'max:2000'],
            'rotation' => ['sometimes', 'integer', 'min:-360', 'max:360'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'sort' => ['sometimes', 'integer', 'min:0'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function geometry(array $row): array
    {
        $values = ['x' => (int) $row['x'], 'y' => (int) $row['y'], 'updated_at' => now()];

        foreach (['width', 'height'] as $key) {
            if (isset($row[$key])) {
                $values[$key] = (int) $row[$key];
            }
        }

        if (isset($row['rotation'])) {
            $values['rotation'] = $this->angle($row['rotation']);
        }

        return $values;
    }

    private function angle(mixed $degrees): int
    {
        $value = ((int) $degrees) % 360;

        return $value < 0 ? $value + 360 : $value;
    }

    public function destroy(DiningTable $table): JsonResponse
    {
        $this->ensureFree([$table->id]);
        $table->delete();
        AuditLog::record('table.delete', $table, ['label' => $table->label]);
        TpvChanged::notify(['floor']);

        return response()->json(['ok' => true]);
    }

    /**
     * Remove every auxiliary (council) table of a zone that has no open order.
     */
    public function removeAuxiliary(Zone $zone): JsonResponse
    {
        $ids = DiningTable::query()->where('zone_id', $zone->id)->where('is_auxiliary', true)->pluck('id');
        $busy = Order::query()->whereIn('dining_table_id', $ids)->whereIn('status', Order::ACTIVE_STATUSES)->pluck('dining_table_id');
        $removable = $ids->diff($busy);

        DiningTable::query()->whereIn('id', $removable)->get()->each->delete();
        AuditLog::record('table.remove_auxiliary', $zone, ['count' => $removable->count()]);
        TpvChanged::notify(['floor']);

        return response()->json(['removed' => $removable->count(), 'kept' => $busy->count()]);
    }

    /**
     * @param  list<int>  $ids
     */
    private function ensureFree(array $ids): void
    {
        if (Order::query()->whereIn('dining_table_id', $ids)->whereIn('status', Order::ACTIVE_STATUSES)->exists()) {
            abort(422, __('tpv.table_has_open_order'));
        }
    }

    private function nextLabel(Zone $zone, bool $auxiliary): string
    {
        $labels = DiningTable::query()->where('zone_id', $zone->id)->pluck('label');

        if ($auxiliary) {
            $n = 1;
            while ($labels->contains('A'.$n)) {
                $n++;
            }

            return 'A'.$n;
        }

        $max = $labels->map(fn ($l) => (int) preg_replace('/\D/', '', (string) $l))->max() ?? 0;
        $prefix = $zone->is_bar ? 'B' : '';

        return $prefix.((int) $max + 1);
    }
}
