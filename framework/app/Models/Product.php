<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Product extends Model
{
    protected $table = 'WBO_Products';

    protected $primaryKey = 'product_id';

    protected $fillable = [
        'sku',
        'name',
        'description',
        'category',
        'supplier_id',
        'abc_class',
        'is_seasonal',
        'is_visible',
        'is_featured',
        'unit_cost',
        'unit_price',
        'reorder_point',
    ];

    protected $casts = [
        'is_seasonal' => 'boolean',
        'is_visible' => 'boolean',
        'is_featured' => 'boolean',
        'unit_cost' => 'decimal:2',
        'unit_price' => 'decimal:2',
        'reorder_point' => 'integer',
    ];

    public function images(): HasMany
    {
        return $this->hasMany(
            ProductImage::class,
            'product_id',
            'product_id'
        );
    }

    public function primaryImage(): HasOne
    {
        return $this->hasOne(
            ProductImage::class,
            'product_id',
            'product_id'
        )->where('is_primary', true);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(
            Batch::class,
            'product_id',
            'product_id'
        );
    }
}
