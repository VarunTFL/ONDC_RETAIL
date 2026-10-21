<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcReplacementEvent extends Model
{
    protected $table = 'ondc_replacement_events';

    protected $fillable = [
        'replacement_fulfillment_id',
        'event_type',
        'previous_state',
        'new_state',
        'triggered_by',
        'payload',
        'notes',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public const TYPE_STARTED            = 'STARTED';
    public const TYPE_PACKED             = 'PACKED';
    public const TYPE_PICKED             = 'PICKED';
    public const TYPE_OUT_FOR_DELIVERY   = 'OUT_FOR_DELIVERY';
    public const TYPE_DELIVERED          = 'DELIVERED';
    public const TYPE_CANCELLED          = 'CANCELLED';
    public const TYPE_PAYMENT_COLLECTED  = 'PAYMENT_COLLECTED';

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(OndcReplacementFulfillment::class, 'replacement_fulfillment_id');
    }
}
