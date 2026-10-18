<?php

namespace App\Http\Controllers\Tpv;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Services\Sync\SnapshotBuilder;
use App\Support\CatalogNames;
use App\Support\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * New families (and subfamilies) created from the TPV during service. Admins only.
 */
class CategoryController extends Controller
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:60'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:60'],
            'parentId' => ['nullable', 'integer', 'exists:categories,id'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'destinationId' => ['nullable', 'integer', 'exists:production_destinations,id'],
        ]);

        $name = Translation::normalize($data['name']);

        if ($existing = CatalogNames::duplicateFamily($name)) {
            return response()->json([
                'message' => __('tpv.family_duplicate', ['name' => Translation::pick($existing->name)]),
                'duplicate' => $this->snapshots->category($existing->load('modifierGroups')),
            ], 422);
        }

        $parent = isset($data['parentId']) ? Category::query()->find($request->integer('parentId')) : null;

        // Two levels, as in the back office.
        if ($parent?->parent_id !== null) {
            throw ValidationException::withMessages(['parentId' => __('tpv.move_family_too_deep')]);
        }

        $category = new Category([
            'name' => $name,
            'parent_id' => $parent?->id,
            'color' => $data['color'] ?? null,
            'production_destination_id' => $data['destinationId'] ?? null,
            'active' => true,
        ]);
        $category->sort = (int) Category::query()->max('sort') + 1;
        $category->save();

        AuditLog::record('category.create', $category, ['name' => $category->name, 'source' => 'tpv']);
        TpvChanged::notify(['catalog']);

        return response()->json(['category' => $this->snapshots->category($category->load('modifierGroups'))]);
    }
}
