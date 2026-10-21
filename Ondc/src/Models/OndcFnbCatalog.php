<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OndcFnbCatalog extends Model
{
    protected $table = 'ondc_fnb_catalogs';

    protected $fillable = [
        'provider_id', 'name', 'cuisine', 'is_veg_only', 'fssai_license',
        'city', 'gps', 'address', 'phone', 'email',
        'time_serviced', 'preparation_time', 'is_active', 'tags',
    ];

    protected $casts = [
        'is_veg_only'    => 'boolean',
        'is_active'      => 'boolean',
        'address'        => 'array',
        'time_serviced'  => 'array',
        'tags'           => 'array',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(OndcFnbItem::class, 'catalog_id');
    }

    public function customizationGroups(): HasMany
    {
        return $this->hasMany(OndcFnbCustomizationGroup::class, 'catalog_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(OndcFnbOrder::class, 'catalog_id');
    }

    public function availableItems()
    {
        return $this->items()->where('is_available', true)->where('available_count', '>', 0);
    }
}
