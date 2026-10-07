<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Zone;
use App\Support\Translation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class ZoneController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Zones', [
            'zones' => Zone::query()->withCount('tables')->orderBy('sort')->get()->map(fn (Zone $z) => [
                'id' => $z->id,
                'name' => $z->name,
                'appliesTerraceSurcharge' => $z->applies_terrace_surcharge,
                'isBar' => $z->is_bar,
                'tablesCount' => $z->tables_count,
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $name = Translation::normalize($data['name']);

        $slug = Str::slug($name['ca'] ?: $name['es']) ?: 'zona';
        $base = $slug;
        $i = 2;

        while (Zone::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        Zone::query()->create([
            'name' => $name,
            'slug' => $slug,
            'applies_terrace_surcharge' => $data['appliesTerraceSurcharge'] ?? false,
            'is_bar' => $data['isBar'] ?? false,
            'sort' => (int) Zone::query()->max('sort') + 1,
        ]);

        TpvChanged::notify(['floor']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    public function update(Request $request, Zone $zone): RedirectResponse
    {
        $data = $this->validated($request);

        $zone->update([
            'name' => Translation::normalize($data['name']),
            'applies_terrace_surcharge' => $data['appliesTerraceSurcharge'] ?? false,
            'is_bar' => $data['isBar'] ?? false,
        ]);

        TpvChanged::notify(['floor']);
        $this->toast(__('tpv.saved'));

        return back();
    }

    public function reorder(Request $request): RedirectResponse
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $sort => $id) {
            Zone::query()->whereKey($id)->update(['sort' => $sort, 'updated_at' => now()]);
        }

        TpvChanged::notify(['floor']);

        return back();
    }

    public function destroy(Zone $zone): RedirectResponse
    {
        $busy = Order::query()->whereIn('status', Order::ACTIVE_STATUSES)->whereIn('dining_table_id', $zone->tables()->pluck('id'))->exists();

        if ($busy) {
            $this->toast(__('tpv.table_has_open_order'), 'error');

            return back();
        }

        $zone->tables()->update(['deleted_at' => now(), 'updated_at' => now()]);
        $zone->delete();
        TpvChanged::notify(['floor']);
        $this->toast(__('tpv.deleted'));

        return back();
    }

    /**
     * @return array{name: array<string, string>, appliesTerraceSurcharge?: bool, isBar?: bool}
     */
    private function validated(Request $request): array
    {
        /** @var array{name: array<string, string>, appliesTerraceSurcharge?: bool, isBar?: bool} */
        return $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:60'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:60'],
            'appliesTerraceSurcharge' => ['boolean'],
            'isBar' => ['boolean'],
        ]);
    }
}
