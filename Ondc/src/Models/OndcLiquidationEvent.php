<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcLiquidationEvent extends Model
{
    protected $table = 'ondc_liquidation_events';

    protected $fillable = [
        'cancellation_id',
        'cancelled_item_id',
        'event_type',
        'disposition',
        'channel',
        'value',
        'triggered_by',
        'payload',
        'notes',
    ];

    protected $casts = [
        'payload' => 'array',
        'value'   => 'decimal:2',
    ];

    public const TYPE_REQUESTED = 'REQUESTED';
    public const TYPE_INTERIM   = 'INTERIM';
    public const TYPE_APPROVED  = 'APPROVED';
    public const TYPE_COMPLETED = 'COMPLETED';
    public const TYPE_REJECTED  = 'REJECTED';

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(OndcCancelledItem::class, 'cancelled_item_id');
    }
}
