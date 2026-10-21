<?php

namespace Webkul\Ondc\Models;

use Illuminate\Database\Eloquent\Model;

class OndcFnbDiscoverySearch extends Model
{
    protected $table = 'ondc_fnb_discovery_searches';

    protected $fillable = [
        'transaction_id', 'city', 'intent',
        'finder_fee_type', 'finder_fee_amount',
        'catalog_count', 'response_payload',
    ];

    protected $casts = [
        'intent'            => 'array',
        'response_payload'  => 'array',
        'finder_fee_amount' => 'decimal:2',
    ];
}
