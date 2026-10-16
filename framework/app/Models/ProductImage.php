<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductImage extends Model
{
    protected $table = 'WBO_ProductImages';

    protected $primaryKey = 'image_id';

    public $timestamps = false;

    protected $fillable = [
        'product_id',
        'image_path',
        'image_data',
        'mime_type',
        'file_size',
        'alt_text',
        'is_primary',
        'sort_order',
        'uploaded_by',
    ];

    protected $hidden = [
        'image_data',
    ];

    protected $casts = [
        'is_primary' => 'boolean',
        'file_size' => 'integer',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(
            Product::class,
            'product_id',
            'product_id'
        );
    }
}