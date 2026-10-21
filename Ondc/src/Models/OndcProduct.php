<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcProduct extends Model
{
    protected $table = 'ondc_products';
    protected $fillable = [
        'product_id',
    ];
    public function mappings()
    {
        return $this->hasMany(
            \App\Models\OndcProductMapping::class,
            'ondc_product_id'
        );
    }
    
    /**
     * Get the product that owns the ondc product.
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Product\Models\Product::class, 'product_id');
    }
}