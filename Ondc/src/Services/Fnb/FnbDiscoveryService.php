<?php

namespace Webkul\Ondc\Services\Fnb;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Webkul\Ondc\Models\OndcFnbCatalog;
use Webkul\Ondc\Models\OndcFnbDiscoverySearch;
use Webkul\Ondc\Models\OndcFnbItem;

/**
 * F&B Discovery Flow (ONDC:RET11) — Flow 1
 *
 * Builds:
 * BAP /search
 * BPP /on_search
 */
class FnbDiscoveryService
{
    public const DOMAIN = 'ONDC:RET11';

    public const DEFAULT_FINDER_FEE_TYPE = 'percent';

    public const DEFAULT_FINDER_FEE_AMOUNT = '5';

    /**
     * ---------------------------------------------------------
     * Validate /search request
     * ---------------------------------------------------------
     */
    public function validateSearch(array $payload): array
    {
        $context = $payload['context'] ?? [];

        if (($context['domain'] ?? '') !== self::DOMAIN) {
            return [
                'valid' => false,
                'error' => "Expected domain "
                    . ($context['domain'] ?? 'missing')
                    . " != "
                    . self::DOMAIN,
            ];
        }

        if (
            empty($context['transaction_id'])
            || empty($context['message_id'])
        ) {
            return [
                'valid' => false,
                'error' => 'transaction_id and message_id required',
            ];
        }

        return [
            'valid' => true,
        ];
    }

    /**
     * ---------------------------------------------------------
     * Build BAP → BPP /search
     * ---------------------------------------------------------
     */
    public function buildSearchPayload(array $params): array
    {
        return [
            'context' => [
                'domain' => self::DOMAIN,

                'action' => 'search',

                'country' => 'IND',

                'city' => $params['city'] ?? 'std:080',

                'core_version' => '1.2.0',

                'bap_id' => config('ondc.bap_id'),

                'bap_uri' => config('ondc.bap_uri'),

                'transaction_id'
                    => $params['transaction_id']
                    ?? (string) Str::uuid(),

                'message_id'
                    => $params['message_id']
                    ?? (string) Str::uuid(),

                'timestamp'
                    => now()->toIso8601ZuluString(),

                'ttl' => 'PT30S',
            ],

            'message' => [
                'intent' => [
                    'fulfillment' => [
                        'type'
                            => $params['fulfillment_type']
                            ?? 'Delivery',
                    ],

                    'payment' => [
                        '@ondc/org/buyer_app_finder_fee_type'
                            => $params['finder_fee_type']
                            ?? self::DEFAULT_FINDER_FEE_TYPE,

                        '@ondc/org/buyer_app_finder_fee_amount'
                            => $params['finder_fee_amount']
                            ?? self::DEFAULT_FINDER_FEE_AMOUNT,
                    ],
                ],
            ],
        ];
    }

    /**
     * ---------------------------------------------------------
     * Process /search and build /on_search
     * ---------------------------------------------------------
     */
    public function processSearchAndBuildOnSearch(
        array $searchPayload
    ): array {
        $context = $searchPayload['context'];

        $intent = $searchPayload['message']['intent'] ?? [];

        /*
         * Find active catalogs.
         */
        $catalogs = OndcFnbCatalog::with([
            'availableItems',
            'customizationGroups',
        ])
            ->where('is_active', true)
            ->get();
            // ->where(
            //     'city',
            //     $context['city'] ?? 'std:080'
            // )
           

        /*
         * Audit search.
         */
        OndcFnbDiscoverySearch::create([
            'transaction_id'
                => $context['transaction_id'],

            'city'
                => $context['city'] ?? null,

            'intent'
                => $intent,

            'finder_fee_type'
                => $intent['payment']
                    ['@ondc/org/buyer_app_finder_fee_type']
                    ?? null,

            'finder_fee_amount'
                => $intent['payment']
                    ['@ondc/org/buyer_app_finder_fee_amount']
                    ?? null,

            'catalog_count'
                => $catalogs->count(),
        ]);

        /*
         * Build providers.
         */
        $providers = $catalogs
            ->map(
                fn (OndcFnbCatalog $catalog) =>
                    $this->mapCatalogToProvider($catalog)
            )
            ->values()
            ->all();

        /*
         * ONDC on_search context.
         */
        $onSearchContext = array_merge(
            $context,
            [
                'action' => 'on_search',

                'message_id'
                    => $context['message_id'],

                'timestamp'
                    => now()->toIso8601ZuluString(),

                'bpp_id'
                    => config('ondc.bpp_id'),

                'bpp_uri'
                    => config('ondc.bpp_uri'),
            ]
        );

        /*
         * Final payload.
         */
       $payload = [
    'context' => $onSearchContext,

    'message' => [
        'catalog' => [
            'bpp/descriptor' => [
                'name' => 'F&B Network',

                'short_desc'
                    => 'Food and beverage products',

                'long_desc'
                    => 'F&B Network provides food and beverage products through ONDC.',

                'symbol' => config(
                    'ondc.bpp_logo',
                    'https://example.com/images/bpp-logo.png'
                ),

                'images' => [
                    config(
                        'ondc.bpp_logo',
                        'https://example.com/images/bpp-logo.png'
                    ),
                ],
            ],

            'bpp/providers' => $providers,
        ],
    ],
];
        /*
         * Debug logging.
         */
        Log::info(
            '[ONDC F&B] /on_search built',
            [
                'tx'
                    => $context['transaction_id'],

                'provider_count'
                    => count($providers),
            ]
        );

        /*
         * TEMPORARY DEBUG
         *
         * Keep this until Workbench passes.
         */
        // Log::info(
        //     '[ONDC F&B] COMPLETE PAYLOAD',
        //     [
        //         'payload' => json_encode(
        //             $payload,
        //             JSON_PRETTY_PRINT
        //             | JSON_UNESCAPED_SLASHES
        //         ),
        //     ]
        // );

        return $payload;
    }

    /**
     * ---------------------------------------------------------
     * Map Catalog → Provider
     * ---------------------------------------------------------
     */
    protected function mapCatalogToProvider(
    OndcFnbCatalog $catalog
): array {
    $locationId = 'L1';

    $items = OndcFnbItem::where(
        'catalog_id',
        $catalog->id
    )
        ->where('is_available', true)
        ->where('available_count', '>', 0)
        ->get();

    return [
        /*
        |--------------------------------------------------------------------------
        | PROVIDER ID
        |--------------------------------------------------------------------------
        */
        'id' => (string) $catalog->provider_id,

        /*
        |--------------------------------------------------------------------------
        | PROVIDER TIME
        |--------------------------------------------------------------------------
        */
        'time' => [
            'label' => 'enable',
            'timestamp' => now()->toIso8601ZuluString(),
        ],

        /*
        |--------------------------------------------------------------------------
        | PROVIDER DESCRIPTOR
        |--------------------------------------------------------------------------
        */
        'descriptor' => [
            'name' => $catalog->name,

            'short_desc' => $catalog->cuisine
                ? $catalog->cuisine . ' food products'
                : 'Food and beverage products',

            'long_desc' => $catalog->name
                . ' offers food and beverage products.',

            /*
             * Replace with your actual seller/provider image.
             */
            'symbol' => config(
                'ondc.provider_logo',
                'https://example.com/images/provider.png'
            ),

            'images' => [
                config(
                    'ondc.provider_logo',
                    'https://example.com/images/provider.png'
                ),
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | FSSAI
        |--------------------------------------------------------------------------
        */
        '@ondc/org/fssai_license_no'
            => (string) $catalog->fssai_license,

        /*
        |--------------------------------------------------------------------------
        | PROVIDER TTL
        |--------------------------------------------------------------------------
        */
        'ttl' => 'PT30S',

        /*
        |--------------------------------------------------------------------------
        | FULFILLMENTS
        |--------------------------------------------------------------------------
        */
        'fulfillments' => [
            [
                'id' => 'F1',

                'type' => 'Delivery',

                'contact' => [
                    'phone' => (string) (
                        $catalog->phone
                        ?: '9876543210'
                    ),

                    'email' => (string) (
                        $catalog->email
                        ?: 'support@example.com'
                    ),
                ],
            ],
        ],

        /*
        |--------------------------------------------------------------------------
        | LOCATIONS
        |--------------------------------------------------------------------------
        */
        'locations' => [
            $this->mapLocation(
                $catalog,
                $locationId
            ),
        ],

        /*
        |--------------------------------------------------------------------------
        | CATEGORIES
        |--------------------------------------------------------------------------
        */
        'categories' => $this->mapCategories(
            $catalog
        ),

        /*
        |--------------------------------------------------------------------------
        | ITEMS
        |--------------------------------------------------------------------------
        */
        'items' => $items
            ->map(
                fn (OndcFnbItem $item) =>
                    $this->mapItemToOndc(
                        $item,
                        $locationId
                    )
            )
            ->values()
            ->all(),

        /*
        |--------------------------------------------------------------------------
        | SERVICEABILITY
        |--------------------------------------------------------------------------
        */
        'tags' => [
            [
                'code' => 'serviceability',

                'list' => [
                    [
                        'code' => 'location',
                        'value' => $locationId,
                    ],

                    [
                        'code' => 'category',
                        'value' => 'F&B',
                    ],

                    [
                        'code' => 'type',
                        'value' => '10',
                    ],

                    [
                        'code' => 'val',
                        'value' => '5',
                    ],

                    [
                        'code' => 'unit',
                        'value' => 'km',
                    ],
                ],
            ],
        ],
    ];
}

    /**
     * ---------------------------------------------------------
     * PROVIDER CATEGORIES
     * ---------------------------------------------------------
     *
     * Normal item categories:
     *
     * MC1
     * DS1
     *
     * Customization groups:
     *
     * CG1
     * CG2
     * CG3
     */
    protected function mapCategories(
        OndcFnbCatalog $catalog
    ): array {
        $categories = [];

        /*
         * -----------------------------------------------------
         * NORMAL MENU CATEGORIES
         * -----------------------------------------------------
         *
         * Here category_id is expected to contain:
         *
         * MC1
         * DS1
         *
         * NOT "F&B".
         */
        $normalItems = OndcFnbItem::where(
            'catalog_id',
            $catalog->id
        )
            ->where('type', OndcFnbItem::TYPE_ITEM)
            ->where('is_available', true)
            ->whereNotNull('category_id')
            ->get();

        foreach (
            $normalItems
                ->groupBy('category_id')
            as $categoryId => $categoryItems
        ) {
            /*
             * If category_id is F&B, don't create an
             * invalid category called F&B.
             */
            if (
                empty($categoryId)
                || strtoupper($categoryId) === 'F&B'
            ) {
                continue;
            }

            /*
             * Category names.
             *
             * Since your DB currently doesn't have
             * category_name, map known IDs here.
             */
            $categoryName = match ($categoryId) {
                'MC1' => 'Main Course',

                'DS1' => 'Desserts',

                default => ucfirst(
                    str_replace(
                        ['_', '-'],
                        ' ',
                        $categoryId
                    )
                ),
            };

            $categories[] = [
                'id' => $categoryId,

                'parent_category_id'
                    => $categoryId,

                'descriptor' => [
                    'name' => $categoryName,
                ],
            ];
        }

        /*
         * -----------------------------------------------------
         * CUSTOMIZATION GROUPS
         * -----------------------------------------------------
         */
        foreach (
            $catalog->customizationGroups
            as $group
        ) {
            /*
             * group_id contains:
             *
             * CG1
             * CG2
             * CG3
             */
            if (empty($group->group_id)) {
                continue;
            }

            $category = [
                'id' => $group->group_id,

                'parent_category_id'
                    => $group->group_id,

                'descriptor' => [
                    'name' => $group->name,
                ],

                'tags' => [
                    [
                        'code' => 'type',

                        'list' => [
                            [
                                'code' => 'type',
                                'value' => 'custom_group',
                            ],
                        ],
                    ],

                    [
                        'code' => 'config',

                        'list' => [
                            [
                                'code' => 'min',
                                'value' => (string)
                                    $group->min_select,
                            ],
                            [
                                'code' => 'max',
                                'value' => (string)
                                    $group->max_select,
                            ],
                            [
                                'code' => 'input',
                                'value' => 'select',
                            ],
                            [
                                'code' => 'seq',
                                'value' => (string)
                                    $group->sort_order,
                            ],
                        ],
                    ],
                ],
            ];

            $categories[] = $category;
        }

        /*
         * Remove duplicate IDs.
         */
        $unique = [];

        foreach ($categories as $category) {
            $unique[$category['id']] = $category;
        }

        return array_values($unique);
    }

    protected function mapLocation(
    OndcFnbCatalog $catalog,
    string $locationId
): array {
    $address = is_array($catalog->address)
        ? $catalog->address
        : [];

    $gps = $catalog->gps
        ?: '12.974002,77.613458';

    return [
        /*
        |--------------------------------------------------------------------------
        | LOCATION ID
        |--------------------------------------------------------------------------
        */
        'id' => $locationId,

        /*
        |--------------------------------------------------------------------------
        | LOCATION TIME
        |--------------------------------------------------------------------------
        */
        'time' => [
            'label' => 'enable',

            'timestamp'
                => now()->toIso8601ZuluString(),

            'days' => '1,2,3,4,5,6,7',
        ],

        /*
        |--------------------------------------------------------------------------
        | GPS
        |--------------------------------------------------------------------------
        */
        'gps' => $gps,

        /*
        |--------------------------------------------------------------------------
        | ADDRESS
        |--------------------------------------------------------------------------
        */
        'address' => [
            'locality' => $address['locality']
                ?? 'Bangalore',

            'street' => $address['street']
                ?? 'Food Street',

            'city' => $address['city']
                ?? 'Bangalore',

            'area_code' => $address['area_code']
                ?? '560001',

            'state' => $address['state']
                ?? 'Karnataka',
        ],

        /*
        |--------------------------------------------------------------------------
        | SERVICEABILITY CIRCLE
        |--------------------------------------------------------------------------
        */
        'circle' => [
            'gps' => $gps,

            'radius' => [
                'unit' => 'km',
                'value' => '5',
            ],
        ],
    ];
}


    /**
     * ---------------------------------------------------------
     * ITEM → ONDC ITEM
     * ---------------------------------------------------------
     */
   public function mapItemToOndc(
    OndcFnbItem $item,
    string $locationId = 'L1'
): array {
    $isCustomization =
        $item->type === OndcFnbItem::TYPE_CUSTOMIZATION;

    /*
     * Normal item:
     * category_id = MC1
     *
     * Customization:
     * parent_item_id = CG1
     */
    $categoryReference = $isCustomization
        ? $item->parent_item_id
        : $item->category_id;
        

     $isActuallyAvailable = (bool) $item->is_available
    && (int) $item->available_count > 0;

$availableCountForOndc = $isActuallyAvailable ? '99' : '0';   

    return [
        'id' => (string) $item->ondc_item_id,

        'time' => [
            'label' => $item->is_available
                ? 'enable'
                : 'disable',

            'timestamp'
                => now()->toIso8601ZuluString(),
        ],

        ...(
            $item->parent_item_id
                ? [
                    'parent_item_id'
                        => $item->parent_item_id,
                ]
                : []
        ),

        'descriptor' => [
            'name' => $item->name,

            'short_desc'
                => $item->description
                ?: $item->name,

            'long_desc'
                => $item->description
                ?: $item->name,
        ],

        'quantity' => [
            'unitized' => [
                'measure' => [
                    'unit' => 'unit',
                    'value' => '1',
                ],
            ],

            'available' => [
    'count' => $availableCountForOndc,
],

            'maximum' => [
                'count' => (string) (
                    $item->maximum_count ?: 99
                ),
            ],
        ],

        'price' => [
            'currency' => 'INR',

            'value' => number_format(
                (float) $item->price,
                2,
                '.',
                ''
            ),

            'maximum_value' => number_format(
                (float) (
                    $item->maximum_price
                    ?? $item->price
                ),
                2,
                '.',
                ''
            ),
        ],

        'category_id' =>
            $item->category_id ?: 'F&B',

        'category_ids' =>
            $categoryReference
                ? [$categoryReference . ':1']
                : ['F&B:1'],

        'related' => $isCustomization,

        'recommended' => false,

        'fulfillment_id' => 'F1',

        'location_id' => $locationId,

        '@ondc/org/returnable' =>
            (bool) $item->is_returnable,

        '@ondc/org/cancellable' =>
            (bool) $item->is_cancellable,

        '@ondc/org/time_to_ship' =>
            $item->preparation_time ?: 'PT15M',

        '@ondc/org/available_on_cod' => false,

        'tags' => $this->buildItemTags($item),
    ];
}

    /**
     * ---------------------------------------------------------
     * ITEM TAGS
     * ---------------------------------------------------------
     */
    protected function buildItemTags(
        OndcFnbItem $item
    ): array {
        /*
         * Type.
         */
        $tags = [
            [
                'code' => 'type',

                'list' => [
                    [
                        'code' => 'type',

                        'value'
                            => $item->type
                            ?: OndcFnbItem::TYPE_ITEM,
                    ],
                ],
            ],

            /*
             * Veg / non-veg.
             */
            [
                'code' => 'veg_nonveg',

                'list' => [
                    [
                        'code' => match (
                            $item->veg_status
                        ) {
                            'veg' => 'veg',

                            'egg' => 'egg',

                            default => 'non_veg',
                        },

                        'value' => 'yes',
                    ],
                ],
            ],
        ];

        /*
         * Customization parent.
         */
        if (
            $item->type
                === OndcFnbItem::TYPE_CUSTOMIZATION
            && !empty($item->parent_item_id)
        ) {
            $tags[] = [
                'code' => 'parent',

                'list' => [
                    [
                        'code' => 'id',

                        'value'
                            => $item->parent_item_id,
                    ],
                ],
            ];
        }

        return $tags;
    }

    /**
     * ---------------------------------------------------------
     * Consumer care
     * ---------------------------------------------------------
     */
    protected function getConsumerCare(
        OndcFnbItem $item
    ): string {
        $catalog = $item->catalog;

        if (!$catalog) {
            return 'F&B Support,support@example.com,0000000000';
        }

        return sprintf(
            '%s,%s,%s',
            $catalog->name . ' Support',
            $catalog->email ?? 'support@example.com',
            $catalog->phone ?? '0000000000'
        );
    }
}