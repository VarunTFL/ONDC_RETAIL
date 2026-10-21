<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcCancellationTerm extends Model
{
    protected $table = 'ondc_cancellation_terms';

    protected $fillable = [
        'order_id',
        'ondc_order_id',
        'fulfillment_state',
        'applicable_reason_codes',
        'cancellation_fee_percentage',
        'cancellation_fee_currency',
        'cancellation_fee_amount',
    ];

    protected $casts = [
        'cancellation_fee_percentage' => 'decimal:2',
        'cancellation_fee_amount'     => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Webkul\Sales\Models\Order::class, 'order_id');
    }

    /**
     * Get the fee applicable for a given fulfillment state and reason code.
     */
    public static function feeForState(string $ondcOrderId, string $fulfillmentState, string $reasonCode): ?self
    {
        return static::where('ondc_order_id', $ondcOrderId)
            ->where('fulfillment_state', $fulfillmentState)
            ->get()
            ->first(function ($term) use ($reasonCode) {
                if (empty($term->applicable_reason_codes)) return true;
                $codes = array_map('trim', explode(',', $term->applicable_reason_codes));
                return in_array($reasonCode, $codes);
            });
    }
}
