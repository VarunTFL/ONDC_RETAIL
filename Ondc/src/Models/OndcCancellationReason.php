<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;

class OndcCancellationReason extends Model
{
    protected $table = 'ondc_cancellation_reasons';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'reason',
        'triggers_rto',
        'who_can_use',
        'cost_attributed_to',
        'applicable_for_part_cancel',
        'is_active',
    ];

    protected $casts = [
        'triggers_rto'               => 'boolean',
        'applicable_for_part_cancel' => 'boolean',
        'is_active'                  => 'boolean',
    ];

    // Buyer-usable reason codes
    public static function buyerReasons()
    {
        return static::where('is_active', true)
            ->where('who_can_use', 'LIKE', '%BNP%')
            ->get();
    }
}
