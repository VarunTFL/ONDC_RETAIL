<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcRtoEvent extends Model
{
    protected $table = 'ondc_rto_events';

    protected $fillable = [
        'cancellation_id',
        'rto_fulfillment_id',
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

    public const TYPE_INITIATED = 'RTO-Initiated';
    public const TYPE_DELIVERED = 'RTO-Delivered';
    public const TYPE_DISPOSED  = 'RTO-Disposed';

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }
}
