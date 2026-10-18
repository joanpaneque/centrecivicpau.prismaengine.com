<?php

namespace App\Http\Controllers\Tpv;

use App\Events\TpvChanged;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Product;
use App\Services\Assistant\ProductNameSuggester;
use App\Services\Sync\SnapshotBuilder;
use App\Support\AppSettings;
use App\Support\CatalogNames;
use App\Support\Translation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Invented products from the POS: the AI cleans up the typed name and proposes a family,
 * and the waiter can add the product to the catalog on the spot. Both need a connection
 * (the tablet falls back to a one-off invented line when offline).
 */
class ProductController extends Controller
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    public function suggest(Request $request, ProductNameSuggester $suggester): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80']]);

        return response()->json(['suggestion' => $suggester->suggest(trim($data['name']))]);
    }

    public function store(Request $request): JsonResponse
    {
        abort_if($request->user()?->isKitchen(), 403);

        $data = $request->validate([
            'name' => ['required', 'array'],
            'name.ca' => ['required_without:name.es', 'nullable', 'string', 'max:80'],
            'name.es' => ['required_without:name.ca', 'nullable', 'string', 'max:80'],
            'price' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'destinationId' => ['nullable', 'integer', 'exists:production_destinations,id'],
        ]);

        $name = Translation::normalize($data['name']);

        // Never two products with the same name: the TPV offers the existing one instead.
        if ($existing = CatalogNames::duplicateProduct($name)) {
            return response()->json([
                'message' => __('tpv.product_duplicate', ['name' => Translation::pick($existing->name)]),
                'duplicate' => $this->snapshots->product($existing),
            ], 422);
        }

        $product = new Product([
            'name' => $name,
            'category_id' => (int) $data['categoryId'],
            'price' => isset($data['price']) ? (int) $data['price'] : null,
            'vat_rate' => (float) AppSettings::get('default_vat_rate', 10),
            'production_destination_id' => $data['destinationId'] ?? null,
            'active' => true,
            'sold_out' => false,
        ]);
        $product->sort = (int) Product::query()->where('category_id', $product->category_id)->max('sort') + 1;
        $product->save();

        AuditLog::record('product.create', $product, ['name' => $product->name, 'price' => $product->price, 'source' => 'tpv']);
        TpvChanged::notify(['catalog']);

        return response()->json(['product' => $this->snapshots->product($product->fresh() ?? $product)]);
    }
}
