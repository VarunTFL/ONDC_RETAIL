<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OndcReturnRequest extends Model
{
    protected $table = 'ondc_return_requests';

    protected $fillable = [
        'ondc_order_id',
        'ondc_transaction_id',
        'order_id',
        'return_request_id',
        'item_id',
        'parent_item_id',
        'item_quantity',
        'reason_id',
        'reason_desc',
        'images',
        'ttl_approval',
        'ttl_reverseqc',
        'status',
        'approved_by',
        'approved_at',
        'rejected_at',
        'rejection_reason',
        'refund_amount',
        'cancellation_id',
        'request_payload',
        // Replacement / Exchange
        'flow_type',
        'replacement_item_id',
        'exchange_item_id',
        'exchange_item_specs',
        'condition_id',
        'condition_desc',
        'exchange_price_difference',
        'settlement_status',
    ];

    protected $casts = [
        'images'                    => 'array',
        'request_payload'           => 'array',
        'exchange_item_specs'       => 'array',
        'approved_at'               => 'datetime',
        'rejected_at'               => 'datetime',
        'refund_amount'             => 'decimal:2',
        'exchange_price_difference' => 'decimal:2',
    ];

    // ---- Flow type constants ----
    public const FLOW_RETURN      = 'RETURN';
    public const FLOW_REPLACEMENT = 'REPLACEMENT';
    public const FLOW_EXCHANGE    = 'EXCHANGE';

    // ---- Settlement status ----
    public const SETTLE_PENDING       = 'PENDING';
    public const SETTLE_COLLECTED     = 'COLLECTED';
    public const SETTLE_REFUNDED      = 'REFUNDED';
    public const SETTLE_NOT_REQUIRED  = 'NOT_REQUIRED';

    // ---- Status constants ----
    public const STATUS_REQUESTED        = 'REQUESTED';
    public const STATUS_APPROVED         = 'APPROVED';
    public const STATUS_REJECTED         = 'REJECTED';
    public const STATUS_PICKUP_INITIATED = 'PICKUP_INITIATED';
    public const STATUS_QC_IN_PROGRESS   = 'QC_IN_PROGRESS';
    public const STATUS_QC_PASSED        = 'QC_PASSED';
    public const STATUS_QC_FAILED        = 'QC_FAILED';
    public const STATUS_REFUND_PROCESSED = 'REFUND_PROCESSED';
    public const STATUS_COMPLETED        = 'COMPLETED';
    public const STATUS_CLOSED           = 'CLOSED';

    // ---- Relationships ----

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }

    public function qcResult(): HasOne
    {
        return $this->hasOne(OndcReverseQcResult::class, 'return_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OndcReverseQcEvent::class, 'return_request_id');
    }

    public function replacementFulfillment()
    {
        return $this->hasOne(OndcReplacementFulfillment::class, 'return_request_id');
    }

    public function isReplacement(): bool
    {
        return $this->flow_type === self::FLOW_REPLACEMENT;
    }

    public function isExchange(): bool
    {
        return $this->flow_type === self::FLOW_EXCHANGE;
    }

    public function isReadyForQc(): bool
    {
        return in_array($this->status, [
            self::STATUS_APPROVED,
            self::STATUS_PICKUP_INITIATED,
            self::STATUS_QC_IN_PROGRESS,
        ], true);
    }

    public function canApprove(): bool
    {
        return $this->status === self::STATUS_REQUESTED;
    }
}
