<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OndcCancelledItem extends Model
{
    protected $table = 'ondc_cancelled_items';

    protected $fillable = [
        'cancellation_id',
        'item_id',
        'fulfillment_id',
        'original_quantity',
        'cancelled_quantity',
        'remaining_quantity',
        'item_price',
        'refund_amount',
        'disposition',
        'liquidation_value',
        'liquidation_channel',
        'notes',
        'disposed_at',
    ];

    protected $casts = [
        'item_price'         => 'decimal:2',
        'refund_amount'      => 'decimal:2',
        'liquidation_value'  => 'decimal:2',
        'disposed_at'        => 'datetime',
    ];

    public const DISPOSITION_PENDING    = 'PENDING';
    public const DISPOSITION_INTERIM    = 'INTERIM';
    public const DISPOSITION_RESTOCKED  = 'RESTOCKED';
    public const DISPOSITION_LIQUIDATED = 'LIQUIDATED';
    public const DISPOSITION_DISPOSED   = 'DISPOSED';

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OndcLiquidationEvent::class, 'cancelled_item_id');
    }

    public function isLiquidated(): bool
    {
        return $this->disposition === self::DISPOSITION_LIQUIDATED;
    }
}
