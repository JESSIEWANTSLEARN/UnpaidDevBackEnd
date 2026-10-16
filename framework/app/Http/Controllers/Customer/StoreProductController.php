<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\Storage;

class StoreProductController extends Controller
{
    public function index()
    {
        $products = Product::query()
            ->leftJoin(
                'WBO_Categories as product_categories',
                'product_categories.category_id', '=',
                'WBO_Products.category_id'
            )
            ->select('WBO_Products.*', 'product_categories.name as category_name')
            ->with('primaryImage')
            ->withSum('batches as available_stock', 'current_quantity')
            ->where('WBO_Products.is_visible', true)
            ->where('WBO_Products.is_featured', true)
            ->orderBy('WBO_Products.product_id')
            ->get()
            ->map(function ($product) {

                return [
                    'product_id' => $product->product_id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category' => $product->category_name ?: $product->category,
                    'price' => (float) $product->unit_price,

                    'available_stock' =>
                        (int) ($product->available_stock ?? 0),

                    'image_url' => $product->primaryImage
                        ? Storage::url(
                            $product->primaryImage->image_path
                        )
                        : null,
                ];
            });

        return response()->json([
            'success' => true,
            'products' => $products,
        ]);
    }
}