<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class StoreProductController extends Controller
{
    public function index()
    {
        $products = Product::with([
            'primaryImage' => function ($query) {
                $query->select(
                    'image_id',
                    'product_id',
                    'image_path'
                );
            },
        ])
            ->withSum('batches as available_stock', 'current_quantity')
            ->where('is_visible', true)
            ->where('is_featured', true)
            ->orderBy('product_id')
            ->get()
            ->map(function ($product) {
                return [
                    'product_id' => $product->product_id,
                    'sku' => $product->sku,
                    'name' => $product->name,
                    'description' => $product->description,
                    'category' => $product->category,
                    'price' => (float) $product->unit_price,
                    'available_stock' =>
                        (int) ($product->available_stock ?? 0),
                    'image_url' =>
                        $this->productImageUrl($product->primaryImage),
                ];
            });

        return response()->json([
            'success' => true,
            'products' => $products,
        ]);
    }

    public function image(int $imageId)
    {
        $image = DB::table('WBO_ProductImages')
            ->select(
                'image_data',
                'mime_type',
                'file_size'
            )
            ->where('image_id', $imageId)
            ->first();

        if (
            !$image
            || $image->image_data === null
            || !$image->mime_type
        ) {
            abort(404);
        }

        return response($image->image_data, 200, [
            'Content-Type' => $image->mime_type,
            'Content-Length' => (string) (
                $image->file_size
                ?? strlen($image->image_data)
            ),
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    private function productImageUrl($image): ?string
    {
        if (!$image) {
            return null;
        }

        if ($image->image_path) {
            return Storage::url($image->image_path);
        }

        return '/api/store/product-images/' . $image->image_id;
    }
}
