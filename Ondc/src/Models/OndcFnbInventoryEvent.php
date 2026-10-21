<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcFnbInventoryEvent extends Model
{
    protected $table = 'ondc_fnb_inventory_events';

    protected $fillable = [
        'item_id', 'event_type',
        'previous_count', 'new_count',
        'previous_price', 'new_price',
        'triggered_by', 'notes',
    ];

    protected $casts = [
        'previous_price' => 'decimal:2',
        'new_price'      => 'decimal:2',
    ];

    public const TYPE_STOCK_UPDATE  = 'STOCK_UPDATE';
    public const TYPE_OUT_OF_STOCK  = 'OUT_OF_STOCK';
    public const TYPE_BACK_IN_STOCK = 'BACK_IN_STOCK';
    public const TYPE_PRICE_CHANGE  = 'PRICE_CHANGE';

    public function item(): BelongsTo
    {
        return $this->belongsTo(OndcFnbItem::class, 'item_id');
    }
}
