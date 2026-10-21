<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OndcReplacementFulfillment extends Model
{
    protected $table = 'ondc_replacement_fulfillments';

    protected $fillable = [
        'return_request_id',
        'replacement_fulfillment_id',
        'flow_type',
        'item_id',
        'quantity',
        'state',
        'packed_at',
        'picked_at',
        'out_for_delivery_at',
        'delivered_at',
        'shipping_address',
        'exchange_specs',
        'agent_name',
        'agent_phone',
        'tracking_url',
        'notes',
    ];

    protected $casts = [
        'shipping_address'    => 'array',
        'exchange_specs'      => 'array',
        'packed_at'           => 'datetime',
        'picked_at'           => 'datetime',
        'out_for_delivery_at' => 'datetime',
        'delivered_at'        => 'datetime',
    ];

    // Forward-fulfillment progression
    public const STATE_PENDING       = 'Pending';
    public const STATE_PACKED        = 'Packed';
    public const STATE_PICKED        = 'Order-picked-up';
    public const STATE_OUT_FOR_DELIV = 'Out-for-delivery';
    public const STATE_DELIVERED     = 'Order-delivered';
    public const STATE_CANCELLED     = 'Cancelled';

    public const PROGRESSION = [
        self::STATE_PENDING,
        self::STATE_PACKED,
        self::STATE_PICKED,
        self::STATE_OUT_FOR_DELIV,
        self::STATE_DELIVERED,
    ];

    public const FLOW_REPLACEMENT = 'REPLACEMENT';
    public const FLOW_EXCHANGE    = 'EXCHANGE';

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(OndcReturnRequest::class, 'return_request_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(OndcReplacementEvent::class, 'replacement_fulfillment_id');
    }

    public function nextState(): ?string
    {
        $idx = array_search($this->state, self::PROGRESSION, true);
        if ($idx === false || $idx >= count(self::PROGRESSION) - 1) return null;
        return self::PROGRESSION[$idx + 1];
    }

    public function isComplete(): bool
    {
        return in_array($this->state, [self::STATE_DELIVERED, self::STATE_CANCELLED], true);
    }
}
