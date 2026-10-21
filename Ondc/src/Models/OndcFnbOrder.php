<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcFnbOrder extends Model
{
    protected $table = 'ondc_fnb_orders';

    protected $fillable = [
        'ondc_order_id', 'ondc_transaction_id', 'catalog_id',
        'items', 'billing', 'fulfillments', 'quote', 'payment',
        'state', 'fulfillment_state',
        'confirmed_at', 'packed_at', 'picked_at', 'out_for_delivery_at', 'delivered_at',
    ];

    protected $casts = [
        'items'              => 'array',
        'billing'            => 'array',
        'fulfillments'       => 'array',
        'quote'              => 'array',
        'payment'            => 'array',
        'confirmed_at'       => 'datetime',
        'packed_at'          => 'datetime',
        'picked_at'          => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at'       => 'datetime',
    ];

    public const FULFILLMENT_PROGRESSION = [
        'Pending', 'Packed', 'Order-picked-up', 'Out-for-delivery', 'Order-delivered',
    ];

    public function catalog(): BelongsTo
    {
        return $this->belongsTo(OndcFnbCatalog::class, 'catalog_id');
    }

    public function nextFulfillmentState(): ?string
    {
        $idx = array_search($this->fulfillment_state, self::FULFILLMENT_PROGRESSION, true);
        if ($idx === false || $idx >= count(self::FULFILLMENT_PROGRESSION) - 1) return null;
        return self::FULFILLMENT_PROGRESSION[$idx + 1];
    }
}
