<?php

return [
    [
        'key'   => 'ondc',
        'name'  => 'ONDC',
        'route' => 'admin.ondc.products.index',
        'sort'  => 10,
        'icon'  => 'icon-product',
    ],
    [
        'key'    => 'ondc.products',
        'name'   => 'ONDC Products',
        'route'  => 'admin.ondc.products.index',
        'sort'   => 1,
        'parent' => 'ondc',
        'icon'   => '', // 👈 ADD THIS (VERY IMPORssTANT)
    ],
    [
        'key'    => 'ondc.conformance',
        'name'   => 'Product Conformance',
        'route'  => 'admin.ondc.products.conformance',
        'sort'   => 1,
        'parent' => 'ondc',
        'icon'   => '', // 👈 ADD THIS (VERY IMPORTANT)
    ],
    // [
    //     'key'   => 'ondc.api',
    //     'name'  => 'API Logs',
    //     'route' => 'ondc.api.index',
    //     'sort'  => 3,
    //     'icon'  => 'icon catalog',
    // ],
];
