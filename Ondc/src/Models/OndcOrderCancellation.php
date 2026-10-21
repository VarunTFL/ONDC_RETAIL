<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OndcOrderCancellation extends Model
{
    protected $table = 'ondc_order_cancellations';

    protected $fillable = [
        'ondc_order_id',
        'ondc_transaction_id',
        'order_id',
        'cancellation_reason_id',
        'cancelled_by',
        'initiated_by',
        'cancel_type',
        'fulfillment_id',
        'status',
        'precancel_fulfillment_state',
        'precancel_state_updated_at',
        'cancel_request_payload',
        'on_cancel_response_payload',
        'buyer_note',
        'seller_note',
        // RTO fields
        'triggers_rto',
        'rto_status',
        'rto_fulfillment_id',
        'retry_count',
        'rto_initiated_at',
        'rto_delivered_at',
        'rto_disposed_at',
        // Liquidation fields
        'disposition_type',
        'liquidation_value',
        'liquidation_channel',
        'liquidation_notes',
        'liquidated_at',
    ];

    protected $casts = [
        'cancel_request_payload'     => 'array',
        'on_cancel_response_payload' => 'array',
        'precancel_state_updated_at' => 'datetime',
        'triggers_rto'               => 'boolean',
        'rto_initiated_at'           => 'datetime',
        'rto_delivered_at'           => 'datetime',
        'rto_disposed_at'            => 'datetime',
        'liquidation_value'          => 'decimal:2',
        'liquidated_at'              => 'datetime',
    ];

    // ---- Status constants ----
    const STATUS_INITIATED  = 'INITIATED';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_CANCELLED  = 'CANCELLED';
    const STATUS_REJECTED   = 'REJECTED';

    // ---- Relationships ----

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Sales\Models\Order::class, 'order_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(OndcCancellationReason::class, 'cancellation_reason_id', 'code');
    }

    public function fees(): HasMany
    {
        return $this->hasMany(OndcCancellationFee::class, 'cancellation_id');
    }

    public function quoteTrails(): HasMany
    {
        return $this->hasMany(OndcCancelQuoteTrail::class, 'cancellation_id');
    }

    public function rtoEvents(): HasMany
    {
        return $this->hasMany(OndcRtoEvent::class, 'cancellation_id');
    }

    public function cancelledItems(): HasMany
    {
        return $this->hasMany(OndcCancelledItem::class, 'cancellation_id');
    }

    public function liquidationEvents(): HasMany
    {
        return $this->hasMany(OndcLiquidationEvent::class, 'cancellation_id');
    }

    // ---- RTO state constants ----
    public const RTO_INITIATED = 'RTO-Initiated';
    public const RTO_DELIVERED = 'RTO-Delivered';
    public const RTO_DISPOSED  = 'RTO-Disposed';

    // ---- Disposition constants ----
    public const DISP_RESTOCKED          = 'RESTOCKED';
    public const DISP_LIQUIDATED         = 'LIQUIDATED';
    public const DISP_DISPOSED           = 'DISPOSED';
    public const DISP_PARTIAL_LIQUIDATED = 'PARTIAL_LIQUIDATED';
    public const DISP_INTERIM            = 'INTERIM_LIQUIDATING';

    // ---- Helpers ----

    public function isBuyerInitiated(): bool
    {
        return $this->initiated_by === 'BUYER';
    }

    public function totalFees(): float
    {
        return $this->fees->sum('amount');
    }

    public function markCancelled(array $onCancelPayload = []): self
    {
        $this->update([
            'status'                     => self::STATUS_CANCELLED,
            'on_cancel_response_payload' => $onCancelPayload,
        ]);
        return $this;
    }

    public function markRejected(string $sellerNote = ''): self
    {
        $this->update([
            'status'      => self::STATUS_REJECTED,
            'seller_note' => $sellerNote,
        ]);
        return $this;
    }

    public function isRtoInProgress(): bool
    {
        return $this->triggers_rto && in_array($this->rto_status, [
            self::RTO_INITIATED,
            self::RTO_DELIVERED,
        ], true);
    }

    public function isRtoComplete(): bool
    {
        return $this->triggers_rto && in_array($this->rto_status, [
            self::RTO_DELIVERED,
            self::RTO_DISPOSED,
        ], true);
    }
}
