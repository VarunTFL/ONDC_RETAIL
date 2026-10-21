<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcFnbCustomizationGroup extends Model
{
    protected $table = 'ondc_fnb_customization_groups';

    protected $fillable = [
        'catalog_id', 'group_id', 'parent_item_id', 'name',
        'min_select', 'max_select', 'is_required', 'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(OndcFnbCatalog::class, 'catalog_id');
    }

    public function customizationItems()
    {
        return OndcFnbItem::where('catalog_id', $this->catalog_id)
            ->where('type', OndcFnbItem::TYPE_CUSTOMIZATION)
            ->where('parent_item_id', $this->group_id)
            ->get();
    }
}
