<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OndcCancelQuoteTrail extends Model
{
    protected $table = 'ondc_cancel_quote_trails';

    protected $fillable = [
        'cancellation_id',
        'type',
        'ref_id',
        'currency',
        'value',
        'subtype',
    ];

    protected $casts = [
        'value' => 'decimal:2',
    ];

    public function cancellation(): BelongsTo
    {
        return $this->belongsTo(OndcOrderCancellation::class, 'cancellation_id');
    }
}
