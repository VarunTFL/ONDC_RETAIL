<?php

return [
    'buyer_np_url' => env('ONDC_BUYER_NP_URL'),
    'buyer_internal_key' => env('ONDC_BUYER_INTERNAL_KEY'),
    'seller_np_url' => 'https://retail-seller.softtrust.org',
    'seller_internal_key' => 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk=',
    
    'bpp' => [
        'name' => env('ONDC_BPP_NAME', 'Your BPP'),
        'symbol' => env('ONDC_BPP_SYMBOL', 'https://your-domain.com/logo.png'),
    ],
    'store' => [
        'name' => env('ONDC_STORE_NAME', 'Store 1'),
        'symbol' => env('ONDC_STORE_SYMBOL', 'https://your-domain.com/store.png'),
        'gps' => env('ONDC_STORE_GPS', '21.145800,79.088200'),
        'phone' => env('ONDC_STORE_PHONE', '9876543210'),
        'email' => env('ONDC_STORE_EMAIL', 'support@yourstore.com'),
        'care_name' => env('ONDC_CARE_NAME', 'Customer Care'),
        'care_email' => env('ONDC_CARE_EMAIL', 'care@yourstore.com'),
        'care_phone' => env('ONDC_CARE_PHONE', '9763958080'),
        'address' => [
            'street' => env('ONDC_STORE_STREET', 'Your Street'),
            'locality' => env('ONDC_STORE_LOCALITY', 'Locality'),
            'city' => env('ONDC_STORE_CITY', 'Nagpur'),
            'state' => env('ONDC_STORE_STATE', 'Maharashtra'),
            'area_code' => env('ONDC_STORE_AREA_CODE', '440001'),
        ],
    ],
];