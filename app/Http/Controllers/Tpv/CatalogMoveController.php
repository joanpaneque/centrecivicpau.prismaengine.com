<?php

namespace App\Http\Controllers\Tpv;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Product;
use App\Services\Sync\SnapshotBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Move" from the POS: a product to another family, or a family into another one (as a
 * subfamily) or out to the main families. It goes at the end of where it lands.
 */
class CatalogMoveController extends Controller
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::in(['product', 'family'])],
            'id' => ['required', 'integer'],
            'targetId' => ['nullable', 'integer', 'exists:categories,id'],
        ]);

        $target = isset($data['targetId']) ? Category::query()->findOrFail($request->integer('targetId')) : null;

        return $data['kind'] === 'product'
            ? $this->moveProduct(Product::query()->findOrFail($request->integer('id')), $target)
            : $this->moveFamily(Category::query()->findOrFail($request->integer('id')), $target);
    }

    private function moveProduct(Product $product, ?Category $target): JsonResponse
    {
        if ($target === null) {
            throw ValidationException::withMessages(['targetId' => __('tpv.move_product_needs_family')]);
        }

        if ($product->category_id !== $target->id) {
            $from = $product->category_id;
            $product->forceFill([
                'category_id' => $target->id,
                'sort' => (int) Product::query()->where('category_id', $target->id)->max('sort') + 1,
            ])->save();

            AuditLog::record('product.move', $product, ['from' => $from, 'to' => $target->id, 'source' => 'tpv']);
            TpvChanged::notify(['catalog']);
        }

        return response()->json(['product' => $this->snapshots->product($product->fresh() ?? $product)]);
    }

    private function moveFamily(Category $family, ?Category $target): JsonResponse
    {
        if ($target !== null) {
            // Two levels only (families and subfamilies), as in the back office.
            $problem = match (true) {
                $target->id === $family->id => __('tpv.move_family_into_itself'),
                $target->parent_id !== null => __('tpv.move_family_too_deep'),
                Category::query()->where('parent_id', $family->id)->exists() => __('tpv.move_family_has_children'),
                default => null,
            };

            if ($problem !== null) {
                throw ValidationException::withMessages(['targetId' => $problem]);
            }
        }

        if ($family->parent_id !== $target?->id) {
            $from = $family->parent_id;
            $family->forceFill([
                'parent_id' => $target?->id,
                'sort' => (int) Category::query()->max('sort') + 1,
            ])->save();

            AuditLog::record('category.move', $family, ['from' => $from, 'to' => $target?->id, 'source' => 'tpv']);
            TpvChanged::notify(['catalog']);
        }

        return response()->json(['category' => $this->snapshots->category($family->fresh(['modifierGroups']) ?? $family)]);
    }
}
