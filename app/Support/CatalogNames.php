<?php

namespace App\Support;

use App\Models\Category;
use App\Models\Product;
use Illuminate\Support\Str;

/**
 * Duplicate names in the catalog: "Croquetes de pernil", "croquetes  de PERNIL" and
 * "Croquetes de pernil." are the same product. Compared without accents, case, punctuation
 * or extra spaces, in any language (the same rule as nameKey() in resources/js/pos/catalog.ts).
 */
final class CatalogNames
{
    public static function key(string $name): string
    {
        return trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($name))));
    }

    /**
     * @param  array<string, string|null>  $names  by language
     */
    public static function duplicateProduct(array $names, ?int $ignoreId = null): ?Product
    {
        $keys = self::keys($names);

        return $keys === [] ? null : Product::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get()
            ->first(fn (Product $product) => array_intersect(self::keys($product->name), $keys) !== []);
    }

    /**
     * @param  array<string, string|null>  $names  by language
     */
    public static function duplicateFamily(array $names, ?int $ignoreId = null): ?Category
    {
        $keys = self::keys($names);

        return $keys === [] ? null : Category::query()->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->get()
            ->first(fn (Category $category) => array_intersect(self::keys($category->name), $keys) !== []);
    }

    /**
     * @param  array<string, mixed>  $names
     * @return list<string>
     */
    private static function keys(array $names): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($name) => is_string($name) ? self::key($name) : '', $names))));
    }
}
