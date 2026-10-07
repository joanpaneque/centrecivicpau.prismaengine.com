<?php

namespace App\Http\Controllers\Admin;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Modifier;
use App\Models\ModifierGroup;
use App\Models\Product;
use App\Models\ProductionDestination;
use App\Services\Images\ImageProcessor;
use App\Support\Translation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class CatalogController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('admin/Catalog', [
            'categories' => Category::query()->with('modifierGroups:id')->withCount('products')->orderBy('sort')->get()->map(fn (Category $c) => [
                'id' => $c->id,
                'parentId' => $c->parent_id,
                'name' => $c->name,
                'color' => $c->color,
                'destinationId' => $c->production_destination_id,
                'isMenu' => $c->is_menu,
                'active' => $c->active,
                'productsCount' => $c->products_count,
                'modifierGroupIds' => $c->modifierGroups->pluck('id'),
            ]),
            'products' => Product::query()->with('modifierGroups:id')->orderBy('sort')->get()->map(fn (Product $p) => [
                'id' => $p->id,
                'categoryId' => $p->category_id,
                'name' => $p->name,
                'price' => $p->price,
                'vatRate' => $p->vat_rate,
                'photoUrl' => $p->photoUrl(),
                'color' => $p->color,
                'allergens' => $p->allergens ?? [],
                'destinationId' => $p->production_destination_id,
                'active' => $p->active,
                'soldOut' => $p->sold_out,
                'orderCount' => $p->order_count,
                'modifierGroupIds' => $p->modifierGroups->pluck('id'),
            ]),
            'modifierGroups' => ModifierGroup::query()->with('modifiers')->orderBy('sort')->get()->map(fn (ModifierGroup $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'multiple' => $g->multiple,
                'required' => $g->required,
                'modifiers' => $g->modifiers->map(fn (Modifier $m) => ['id' => $m->id, 'name' => $m->name, 'priceDelta' => $m->price_delta])->values()->all(),
            ]),
            'destinations' => ProductionDestination::query()->orderBy('sort')->get(['id', 'name', 'code']),
            'allergens' => Product::ALLERGENS,
        ]);
    }

    public function storeCategory(Request $request): RedirectResponse
    {
        $data = $this->categoryData($request);
        $category = Category::query()->create($data['attributes'] + ['sort' => (int) Category::query()->where('parent_id', $data['attributes']['parent_id'])->max('sort') + 1]);
        $category->modifierGroups()->sync($data['modifierGroupIds']);

        return $this->changed();
    }

    public function updateCategory(Request $request, Category $category): RedirectResponse
    {
        $data = $this->categoryData($request, $category);
        $category->update($data['attributes']);
        $category->modifierGroups()->sync($data['modifierGroupIds']);

        return $this->changed();
    }

    /**
     * Products cannot be orphaned (category_id is required), so a category with products
     * or subcategories must be emptied first.
     */
    public function destroyCategory(Category $category): RedirectResponse
    {
        if ($category->products()->exists() || $category->children()->exists()) {
            $this->toast(__('tpv.category_not_empty'), 'error');

            return back();
        }

        $category->delete();

        return $this->changed(__('tpv.deleted'));
    }

    public function reorderCategories(Request $request): RedirectResponse
    {
        $this->reorder(Category::class, $request);

        return $this->changed(null);
    }

    public function storeProduct(Request $request, ImageProcessor $images): RedirectResponse
    {
        $data = $this->productData($request);
        $product = new Product($data['attributes']);
        $product->sort = (int) Product::query()->where('category_id', $product->category_id)->max('sort') + 1;

        if ($request->hasFile('photo')) {
            $product->photo_path = $images->productPhoto($request->file('photo'), $this->crop($request));
        }

        $product->save();
        $product->modifierGroups()->sync($data['modifierGroupIds']);

        return $this->changed();
    }

    public function updateProduct(Request $request, Product $product, ImageProcessor $images): RedirectResponse
    {
        $data = $this->productData($request);
        $product->fill($data['attributes']);

        if ($request->hasFile('photo')) {
            $old = $product->photo_path;
            $product->photo_path = $images->productPhoto($request->file('photo'), $this->crop($request));

            if ($old) {
                Storage::disk('public')->delete($old);
            }
        } elseif ($request->boolean('removePhoto') && $product->photo_path) {
            Storage::disk('public')->delete($product->photo_path);
            $product->photo_path = null;
        }

        $product->save();
        $product->modifierGroups()->sync($data['modifierGroupIds']);

        return $this->changed();
    }

    public function duplicateProduct(Product $product): RedirectResponse
    {
        DB::transaction(function () use ($product) {
            $copy = $product->replicate(['order_count', 'photo_path']);
            $copy->name = [
                'ca' => ($product->name['ca'] ?? '').' (còpia)',
                'es' => ($product->name['es'] ?? '').' (copia)',
            ];
            $copy->order_count = 0;
            $copy->sort = $product->sort + 1;

            if ($product->photo_path && Storage::disk('public')->exists($product->photo_path)) {
                $path = 'products/'.Str::uuid().'.webp';
                Storage::disk('public')->copy($product->photo_path, $path);
                $copy->photo_path = $path;
            }

            $copy->save();
            $copy->modifierGroups()->sync($product->modifierGroups()->pluck('modifier_groups.id'));
        });

        return $this->changed(__('tpv.duplicated'));
    }

    public function destroyProduct(Product $product): RedirectResponse
    {
        $product->delete();

        return $this->changed(__('tpv.deleted'));
    }

    public function reorderProducts(Request $request): RedirectResponse
    {
        $this->reorder(Product::class, $request);

        return $this->changed(null);
    }

    public function storeModifierGroup(Request $request): RedirectResponse
    {
        DB::transaction(function () use ($request) {
            $group = new ModifierGroup;
            $group->sort = (int) ModifierGroup::query()->max('sort') + 1;
            $this->saveModifierGroup($request, $group);
        });

        return $this->changed();
    }

    public function updateModifierGroup(Request $request, ModifierGroup $group): RedirectResponse
    {
        DB::transaction(fn () => $this->saveModifierGroup($request, $group));

        return $this->changed();
    }

    public function destroyModifierGroup(ModifierGroup $group): RedirectResponse
    {
        $group->categories()->touch();
        $group->products()->touch();
        $group->delete();

        return $this->changed(__('tpv.deleted'));
    }

    private function saveModifierGroup(Request $request, ModifierGroup $group): void
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['nullable', 'string', 'max:60'],
            'name.es' => ['nullable', 'string', 'max:60'],
            'multiple' => ['boolean'],
            'required' => ['boolean'],
            'modifiers' => ['array'],
            'modifiers.*.id' => ['nullable', 'integer'],
            'modifiers.*.name' => ['required', 'array'],
            'modifiers.*.name.ca' => ['nullable', 'string', 'max:60'],
            'modifiers.*.name.es' => ['nullable', 'string', 'max:60'],
            'modifiers.*.priceDelta' => ['nullable', 'integer', 'between:-100000,100000'],
            'categoryIds' => ['array'],
            'categoryIds.*' => ['integer', 'exists:categories,id'],
            'productIds' => ['array'],
            'productIds.*' => ['integer', 'exists:products,id'],
        ]);

        $group->fill([
            'name' => Translation::normalize($data['name']),
            'multiple' => $data['multiple'] ?? true,
            'required' => $data['required'] ?? false,
        ])->save();

        $keep = [];

        foreach (array_values($data['modifiers'] ?? []) as $sort => $row) {
            $modifier = isset($row['id']) ? $group->modifiers()->whereKey($row['id'])->first() : null;
            $modifier ??= $group->modifiers()->make();
            $modifier->fill([
                'name' => Translation::normalize($row['name']),
                'price_delta' => (int) ($row['priceDelta'] ?? 0),
                'sort' => $sort,
            ])->save();
            $keep[] = $modifier->id;
        }

        $group->modifiers()->whereNotIn('id', $keep)->delete();

        $previous = $group->categories()->pluck('categories.id')->merge($group->products()->pluck('products.id'));
        $group->categories()->sync($data['categoryIds'] ?? []);
        $group->products()->sync($data['productIds'] ?? []);
        $group->touch();
        Category::query()->whereIn('id', array_merge($data['categoryIds'] ?? [], $previous->all()))->update(['updated_at' => now()]);
        Product::query()->whereIn('id', array_merge($data['productIds'] ?? [], $previous->all()))->update(['updated_at' => now()]);
    }

    /**
     * @return array{attributes: array<string, mixed>, modifierGroupIds: list<int>}
     */
    private function categoryData(Request $request, ?Category $category = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:60'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:60'],
            'parentId' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn(array_filter([$category?->id]))],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'destinationId' => ['nullable', 'integer', 'exists:production_destinations,id'],
            'isMenu' => ['boolean'],
            'active' => ['boolean'],
            'modifierGroupIds' => ['array'],
            'modifierGroupIds.*' => ['integer', 'exists:modifier_groups,id'],
        ]);

        return [
            'attributes' => [
                'name' => Translation::normalize($data['name']),
                'parent_id' => $data['parentId'] ?? null,
                'color' => $data['color'] ?? null,
                'production_destination_id' => $data['destinationId'] ?? null,
                'is_menu' => $data['isMenu'] ?? false,
                'active' => $data['active'] ?? true,
            ],
            'modifierGroupIds' => array_values(array_map('intval', $data['modifierGroupIds'] ?? [])),
        ];
    }

    /**
     * @return array{attributes: array<string, mixed>, modifierGroupIds: list<int>}
     */
    private function productData(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:80'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:80'],
            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'price' => ['required', 'integer', 'min:0', 'max:10000000'],
            'vatRate' => ['required', 'numeric', Rule::in([0, 4, 5, 10, 21])],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'allergens' => ['array'],
            'allergens.*' => [Rule::in(Product::ALLERGENS)],
            'destinationId' => ['nullable', 'integer', 'exists:production_destinations,id'],
            'active' => ['boolean'],
            'soldOut' => ['boolean'],
            'modifierGroupIds' => ['array'],
            'modifierGroupIds.*' => ['integer', 'exists:modifier_groups,id'],
            'photo' => ['nullable', 'image', 'max:12288'],
            'crop' => ['nullable', 'array'],
            'crop.x' => ['integer', 'min:0'],
            'crop.y' => ['integer', 'min:0'],
            'crop.width' => ['integer', 'min:1'],
            'crop.height' => ['integer', 'min:1'],
        ]);

        return [
            'attributes' => [
                'name' => Translation::normalize($data['name']),
                'category_id' => (int) $data['categoryId'],
                'price' => (int) $data['price'],
                'vat_rate' => (float) $data['vatRate'],
                'color' => $data['color'] ?? null,
                'allergens' => array_values(array_unique($data['allergens'] ?? [])),
                'production_destination_id' => $data['destinationId'] ?? null,
                'active' => $data['active'] ?? true,
                'sold_out' => $data['soldOut'] ?? false,
            ],
            'modifierGroupIds' => array_values(array_map('intval', $data['modifierGroupIds'] ?? [])),
        ];
    }

    /**
     * @return array{x: int, y: int, width: int, height: int}|null
     */
    private function crop(Request $request): ?array
    {
        $crop = $request->input('crop');

        if (! is_array($crop) || ! isset($crop['width'], $crop['height'])) {
            return null;
        }

        return [
            'x' => (int) ($crop['x'] ?? 0),
            'y' => (int) ($crop['y'] ?? 0),
            'width' => (int) $crop['width'],
            'height' => (int) $crop['height'],
        ];
    }

    /**
     * @param  class-string<Category|Product>  $model
     */
    private function reorder(string $model, Request $request): void
    {
        $ids = $request->validate(['ids' => ['required', 'array'], 'ids.*' => ['integer']])['ids'];

        foreach (array_values($ids) as $sort => $id) {
            $model::query()->whereKey($id)->update(['sort' => $sort, 'updated_at' => now()]);
        }
    }

    private function changed(?string $message = ''): RedirectResponse
    {
        TpvChanged::notify(['catalog']);

        if ($message !== null) {
            $this->toast($message === '' ? __('tpv.saved') : $message);
        }

        return back();
    }
}
