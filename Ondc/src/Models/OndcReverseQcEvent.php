<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcReverseQcEvent extends Model
{
    protected $table = 'ondc_reverse_qc_events';

    protected $fillable = [
        'return_request_id',
        'event_type',
        'previous_status',
        'new_status',
        'triggered_by',
        'payload',
        'notes',
    ];

    protected $casts = [
        'payload' => 'array',
    ];

    public const TYPE_REQUESTED = 'RETURN_REQUESTED';
    public const TYPE_APPROVED  = 'APPROVED';
    public const TYPE_REJECTED  = 'REJECTED';
    public const TYPE_PICKED_UP = 'PICKED_UP';
    public const TYPE_QC_STARTED = 'QC_STARTED';
    public const TYPE_QC_PASSED  = 'QC_PASSED';
    public const TYPE_QC_FAILED  = 'QC_FAILED';
    public const TYPE_REFUNDED   = 'REFUNDED';
    public const TYPE_CLOSED     = 'CLOSED';

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(OndcReturnRequest::class, 'return_request_id');
    }
}
