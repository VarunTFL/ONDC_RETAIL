<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcCancellationFee extends Model
{
    protected $table = 'ondc_cancellation_fees';

    protected $fillable = [
        'cancellation_id',
        'fulfillment_id',
        'fee_type',
        'currency',
        'amount',
        'percentage',
    ];

    protected $casts = [
        'amount'     => 'decimal:2',
        'percentage' => 'decimal:2',
    ];

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }
}
