<?php

namespace Webkul\Ondc\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use App\Models\OndcApiLog;
use App\Models\OndcConfirmPayload;
use App\Models\OndcTransaction;
use Webkul\Admin\Http\Controllers\Controller;
use Webkul\Ondc\Helpers\OndcHelper;
use Webkul\Ondc\Helpers\OndcSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Checkout\Models\Cart;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Models\OrderPayment;
use Firebase\JWT\JWT;
use Webkul\Ondc\Services\DomainResolver;
use Webkul\Ondc\Services\CategoryMapper;
use Webkul\Ondc\Services\Fnb\FnbDiscoveryService;


class ApiController extends Controller
{
    protected DomainResolver $domainResolver;
    protected CategoryMapper $categoryMapper;
     protected FnbDiscoveryService $fnbDiscovery;
     
    public function __construct(
        DomainResolver $domainResolver,
        CategoryMapper $categoryMapper,
        FnbDiscoveryService $fnbDiscovery,
    ) {
        $this->domainResolver = $domainResolver;
        $this->categoryMapper = $categoryMapper;
        $this->fnbDiscovery = $fnbDiscovery;
    }

    protected const ONDC_RET10_CATEGORY_MAP = [
        'Fruits and Vegetables'         => 'CAT001',
        'Masala & Seasoning'            => 'CAT002',
        'Oil & Ghee'                    => 'CAT003',
        'Eggs, Meat & Fish'             => 'CAT004',
        'Cleaning & Household'          => 'CAT005',
        'Bakery, Cakes & Dairy'         => 'CAT006',
        'Pet Care'                      => 'CAT007',
        'Stationery'                    => 'CAT008',
        'Detergents and Dishwash'       => 'CAT009',
        'Dairy and Cheese'              => 'CAT010',
        'Snacks, Dry Fruits, Nuts'      => 'CAT011',
        'Pasta, Soup and Noodles'       => 'CAT012',
        'Cereals and Breakfast'         => 'CAT013',
        'Sauces, Spreads and Dips'      => 'CAT014',
        'Chocolates and Biscuits'       => 'CAT015',
        'Cooking and Baking Needs'      => 'CAT016',
        'Tinned and Processed Food'     => 'CAT017',
        'Atta, Flours and Sooji'        => 'CAT018',
        'Rice and Rice Products'        => 'CAT019',
        'Dals and Pulses'               => 'CAT020',
        'Salt, Sugar and Jaggery'       => 'CAT021',
        'Energy and Soft Drinks'        => 'CAT022',
        'Water'                         => 'CAT023',
        'Tea and Coffee'                => 'CAT024',
        'Fruit Juices and Fruit Drinks' => 'CAT025',
        'Snacks and Namkeen'            => 'CAT026',
        'Ready to Cook and Eat'         => 'CAT027',
        'Pickles and Chutney'           => 'CAT028',
        'Indian Sweets'                 => 'CAT029',
        'Frozen Vegetables'             => 'CAT030',
        'Frozen Snacks'                 => 'CAT031',
        'Gift Voucher'                  => 'CAT032',
    ];




    /**
     * Dashboard - Generate keypair for testing
     */
    public function index()
    {
        $keypair = sodium_crypto_sign_keypair();

        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $keys = [
            'private' => base64_encode($secretKey),
            'public' => base64_encode($publicKey),
        ];

        return view('ondc::admin.api.index', compact('keys'));
    }

    
  public function search(Request $request)
{
    $searchData = $request->all();

    Log::info('ONDC SEARCH RECEIVED Seller', $searchData);

    if (!isset($searchData['context'])) {
        return response()->json([
            'error' => 'Invalid payload: context missing',
        ], 400);
    }

    $context = $searchData['context'];

    $txnId = $context['transaction_id'] ?? null;

    if (!$txnId) {
        return response()->json([
            'error' => 'transaction_id missing in context',
        ], 400);
    }

    $bapUri = rtrim($context['bap_uri'] ?? '', '/');
    $bapId  = $context['bap_id'] ?? null;

    if (!$bapUri) {
        Log::error('BAP_URI_MISSING_IN_SEARCH_REQUEST', [
            'txn_id' => $txnId,
            'payload' => $searchData,
        ]);

        return response()->json([
            'error' => 'bap_uri missing in context',
        ], 400);
    }

    
    Cache::put("ondc:txn:{$txnId}", [
        'bap_uri' => $bapUri,
        'bap_id' => $bapId,
        'original_request' => $searchData,
    ], now()->addMinutes(30));

    OndcTransaction::create([
        'transaction_id' => $txnId,
        'action' => 'search',
        'domain' => $context['domain'] ?? 'ONDC:RET10',
        'bap_id' => $bapId,
        'bap_uri' => $bapUri,
        'request_payload' => $searchData,
        'status' => 'received',
    ]);

    OndcApiLog::create([
        'type' => 'search_inbound',
        'endpoint' => '/ondc/search',
        'request' => json_encode($searchData),
        'status_code' => 200,
        'transaction_id' => $txnId,
    ]);

    
    
    $ack = [
        'context' => $context,
        'message' => [
            'ack' => [
                'status' => 'ACK',
            ],
        ],
    ];

    /*
     * Process on_search asynchronously
     */
    dispatch(function () use (
        $searchData,
        $txnId,
        $bapUri,
        $bapId
    ) {
        $this->sendOnSearch(
            $searchData,
            $txnId,
            $bapUri,
            $bapId
        );
    })->onQueue(env('ONDC_QUEUE', 'default'));
      
    /*
     * Return ACK immediately
     */
    return response()->json($ack);
}


   protected function alreadySentCallback(string $txnId, string $actionType, ?string $messageId): bool
    {
        if (!$messageId) return false;

        return OndcTransaction::where('transaction_id', $txnId)
            ->where('action', 'on_' . $actionType)
            ->where('request_payload->context->message_id', $messageId)
            ->whereIn('status', ['ack_received', 'sent'])
            ->exists();
    }
    /**
     * Send signed on_search to BAP (Pramaan Gateway)
     */
    protected function sendOnSearch($searchData, $txnId, $bapUri, $bapId)
    {
        Log::info('SEND_ON_SEARCH_JOB_STARTED', [
            'txn_id' => $txnId,
            'bap_uri' => $bapUri,
            'bap_id'  => $bapId,
        ]);

        $context = $searchData['context'];
        $messageId = $context['message_id'] ?? null;
       

        if ($this->alreadySentCallback($txnId, 'search', $messageId)) {
            Log::warning('ON_SEARCH_DUPLICATE_SEND_PREVENTED', [
                'txn_id'     => $txnId,
                'message_id' => $messageId,
            ]);
            return;
        }

                $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        if ($requestedDomain === FnbDiscoveryService::DOMAIN) {
            Log::info('ROUTING_SEARCH_TO_FNB_DISCOVERY', [
                'txn_id' => $txnId,
                'domain' => $requestedDomain,
            ]);

            $payload = $this->fnbDiscovery->processSearchAndBuildOnSearch($searchData);

            // processSearchAndBuildOnSearch() merges the inbound context, so
            // bap_id/bap_uri/message_id/transaction_id already carry over —
            // but bpp_id/bpp_uri need to match what the rest of this
            // controller (and the ONDC registry) expects.
            $payload['context']['bpp_id']  = config('services.ondc.bpp_id');
            $payload['context']['bpp_uri'] = config('services.ondc.bpp_uri');
        } else {
            $payload = $this->buildPayload($context, $bapUri, $bapId);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_SEARCH_PAYLOAD', [
            'txn_id' => $txnId,
            'bap_uri' => $bapUri,
            'domain' => $payload['context']['domain'],
            'action' => $payload['context']['action'],
        ]);

        // ── Build the required ONDC signed Authorization header ──────────────
        // Every callback (on_search, on_select, on_init, ...) MUST be signed
        // with the BPP's private key so the BAP/gateway can verify it against
        // the ONDC registry. Without this header, real BAPs (including the
        // pre-prod gateway) will reject or silently drop the callback.
        $signedHeaders = $this->getSignedOndcHeaders($payloadJson, $txnId, 'search');

        $headers = array_merge($signedHeaders, [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ]);

        // $endpoints = [
        //     [
        //         'url' => rtrim($bapUri, '/') . '/on_search',
        //         'type' => 'bap',
        //         'headers' => $headers,
        //     ],
        // ];
        $endpoints = [
    [
        'url' => rtrim(
            env('ONDC_BPP_URI', 'https://retail-seller.softtrust.org'),
            '/'
        ) . '/on_search',

        'type' => 'bpp_internal',

        'headers' => array_merge($headers, [
            'Accept'         => 'application/json',
            'Content-Type'   => 'application/json',
            'X-Internal-Key' => env(
                'ONDC_INTERNAL_KEY',
                'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='
            ),
            'bap_uri'        => $bapUri,
        ]),
    ],
];
       
        foreach ($endpoints as $endpoint) {
            Log::info('ON_SEARCH_TARGET', [
                'url' => $endpoint['url'],
                'type' => $endpoint['type'],
            ]);
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'search', $payload, $bapUri, $bapId);
        }
    }

    /**
     * Build the signed Authorization header required by the ONDC protocol
     * for any outbound callback (on_search, on_select, on_init, ...).
     *
     * Falls back to unsigned headers (with an error logged) if signing
     * fails, so a bad ONDC_PRIVATE_KEY doesn't crash the whole request —
     * but note an unsigned callback WILL be rejected by a real BAP/gateway.
     */
    public  function getSignedOndcHeaders(string $payloadJson, string $txnId, string $actionType): array
    {
        $signer = new OndcSigner();

        try {
            $bppId = config('services.ondc.bpp_id');
$privateKey = config('services.ondc.private_key');

if (!$bppId || !$privateKey) {
    Log::error(strtoupper($actionType) . '_MISSING_ONDC_CREDENTIALS', [
        'txn_id' => $txnId,
        'bpp_id_present' => (bool) $bppId,
        'private_key_present' => (bool) $privateKey,
    ]);
    return [];
}

$signatureHeaders = $signer->signRequest($payloadJson, $bppId, $privateKey);

        } catch (\Exception $e) {
            Log::error(strtoupper($actionType) . '_SIGNATURE_ERROR', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);

            // Unsigned fallback — will be rejected by a real BAP, but at
            // least the request still goes out and gets logged for debugging.
            return [];
        }

        return $signatureHeaders;
    }

    /**
     * Helper method to send payload to a single endpoint
     */
    public  function sendToEndpoint(
    $endpoint,
    $payloadJson,
    $txnId,
    $actionType,
    $payload = [],
    $bapUri = null,
    $bapId = null
): bool {
    $url     = $endpoint['url'];
    $type    = $endpoint['type'];
    $headers = $endpoint['headers'];

    Log::info("SENDING_ON_" . strtoupper($actionType), [
        'url'    => $url,
        'txn_id' => $txnId,
        'type'   => $type,
    ]);

    try {

        /*
        |--------------------------------------------------------------------------
        | Send HTTP request
        |--------------------------------------------------------------------------
        */

        $response = Http::withOptions([
                'verify' => true,
            ])
            ->withHeaders($headers)
            ->withBody($payloadJson, 'application/json')
            ->timeout(30)
            ->connectTimeout(10)
            ->post($url);

        /*
        |--------------------------------------------------------------------------
        | Signature Debug
        |--------------------------------------------------------------------------
        */

        Log::info('ONDC_OUTBOUND_SIGNATURE_DEBUG', [
            'url'           => $url,
            'body_sha256'   => hash('sha256', $payloadJson),
            'body_length'   => strlen($payloadJson),
            'header_keys'   => array_keys($headers),
            'authorization' => $headers['Authorization'] ?? null,
            'digest'        => $headers['Digest'] ?? null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Log response
        |--------------------------------------------------------------------------
        */

        Log::info("ON_" . strtoupper($actionType) . "_RESPONSE", [
            'txn_id' => $txnId,
            'type'   => $type,
            'url'    => $url,
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Check whether callback was accepted
        |--------------------------------------------------------------------------
        |
        | Any 2xx response is considered successful.
        |
        */

        $wasAccepted = $response->successful();

        if (! $wasAccepted) {

            Log::warning(
                "ON_" . strtoupper($actionType) . "_REJECTED_BY_BAP",
                [
                    'txn_id' => $txnId,
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]
            );
        }

        /*
        |--------------------------------------------------------------------------
        | Save ONDC transaction
        |--------------------------------------------------------------------------
        */

        OndcTransaction::create([
            'transaction_id' => $txnId,

            'action' => 'on_' . $actionType,

            'domain' => $payload['context']['domain']
                ?? 'ONDC:RET14',

            'bap_id' => $payload['context']['bap_id']
                ?? $bapId,

            'bap_uri' => $payload['context']['bap_uri']
                ?? $bapUri,

            'bpp_id' => config(
                'services.ondc.bpp_id'
            ),

            'status' => $wasAccepted
                ? 'ack_received'
                : 'rejected',

            'request_payload' => $payload,

            'response_payload' => $response->json(),

            'response_status_code' => $response->status(),

            'sent_at' => now(),

            'ack_received_at' => $wasAccepted
                ? now()
                : null,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save API log
        |--------------------------------------------------------------------------
        */

        OndcApiLog::create([
            'type' => "{$actionType}_outbound_{$type}",

            'endpoint' => $url,

            'request' => $payloadJson,

            'response' => $response->body(),

            'status_code' => $response->status(),

            'transaction_id' => $txnId,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Return actual result
        |--------------------------------------------------------------------------
        |
        | true  = endpoint returned 2xx
        | false = endpoint returned non-2xx
        |
        */

        return $wasAccepted;

    } catch (\Illuminate\Http\Client\ConnectionException $e) {

        /*
        |--------------------------------------------------------------------------
        | Connection / Timeout
        |--------------------------------------------------------------------------
        |
        | We cannot confirm that the callback was accepted.
        | Therefore return false.
        |
        */

        Log::warning(
            "ON_" . strtoupper($actionType) . "_CONNECTION_ERROR",
            [
                'txn_id'  => $txnId,
                'type'    => $type,
                'url'     => $url,
                'message' => $e->getMessage(),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Save transaction
        |--------------------------------------------------------------------------
        */

        OndcTransaction::create([
            'transaction_id' => $txnId,

            'action' => 'on_' . $actionType,

            'status' => 'sent',

            'sent_at' => now(),

            'error_message' => $e->getMessage(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save API log
        |--------------------------------------------------------------------------
        */

        OndcApiLog::create([
            'type' => "{$actionType}_outbound_{$type}_sent",

            'endpoint' => $url,

            'request' => $payloadJson,

            'response' => json_encode([
                'status' => 'sent_awaiting_callback',
                'error'  => $e->getMessage(),
            ]),

            'status_code' => 202,

            'transaction_id' => $txnId,
        ]);

        return false;

    } catch (\Exception $e) {

        /*
        |--------------------------------------------------------------------------
        | General error
        |--------------------------------------------------------------------------
        */

        Log::error(
            "ON_" . strtoupper($actionType) . "_ERROR",
            [
                'txn_id'  => $txnId,
                'type'    => $type,
                'url'     => $url,
                'message' => $e->getMessage(),
                'class'   => get_class($e),
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Save failed transaction
        |--------------------------------------------------------------------------
        */

        OndcTransaction::create([
            'transaction_id' => $txnId,

            'action' => 'on_' . $actionType,

            'status' => 'failed',

            'error_message' => $e->getMessage(),
        ]);

        /*
        |--------------------------------------------------------------------------
        | Save API log
        |--------------------------------------------------------------------------
        */

        OndcApiLog::create([
            'type' => "{$actionType}_outbound_{$type}_error",

            'endpoint' => $url,

            'request' => $payloadJson,

            'response' => json_encode([
                'error' => $e->getMessage(),
            ]),

            'status_code' => 500,

            'transaction_id' => $txnId,
        ]);

        return false;
    }
}
    /**
     * Build ONDC-compliant on_search payload with full catalog
     */
    protected function buildPayload($context, $bapUri, $bapId)
    {
        return [
            'context' => [
                'domain' => $context['domain'] ?? 'ONDC:RET10',
                'country' => $context['country'] ?? 'IND',
                'city' => preg_match('/^(std:\d{3,5}|\*)$/', $context['city'] ?? '')
    ? $context['city']
    : 'std:011',
                'core_version' => $context['core_version'] ?? '1.2.0',
                'action' => 'on_search',
                'bap_id' => $bapId,
                'bap_uri' => $bapUri,
                'bpp_id' => config('services.ondc.bpp_id'),
                'bpp_uri' => config('services.ondc.bpp_uri'),
                'transaction_id' => $context['transaction_id'],
                'message_id' => $context['message_id'],
                'timestamp' => OndcHelper::timestamp(),
                'ttl' => 'PT30S',
            ],
            'message' => [
                'catalog' => $this->buildFullCatalog($context),
            ],
        ];
    }

    /**
     * Build full catalog response for ONDC RET14 v1.2.5
     */
    protected function buildFullCatalog($context)
    {
        $timestamp = OndcHelper::timestamp();
        $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        $products = $this->getAllActiveProducts($requestedDomain);

        return [
            'bpp/fulfillments' => [
                [
                    'id' => 'F1',
                    'type' => 'Delivery',
                ],
            ],
            'bpp/descriptor' => [
                'name' => config('ondc.bpp.name', 'Your BPP Name'),
                'symbol' => config('ondc.bpp.symbol', 'https://your-domain.com/logo.png'),
                'short_desc' => 'Seller Marketplace',
                'long_desc' => 'Seller Marketplace',
                'images' => [config('ondc.bpp.symbol', 'https://your-domain.com/logo.png')],
                'tags' => [
                    [
                        'code' => 'bpp_terms',
                        'list' => [
                            [
                                'code' => 'np_type',
                                'value' => 'MSN',
                            ],
                            [
                                'code' => 'accept_bap_terms',
                                'value' => 'Y',
                            ],
                            [
                                'code' => 'collect_payment',
                                'value' => 'N',
                            ],
                        ],
                    ],
                ],
            ],
            'bpp/providers' => $this->buildProviders($products, $timestamp, $context),
        ];
    }

    /**
     * Get all active products from database, filtered to the requested
     * ONDC domain (via DomainResolver against the product's category).
     */
    protected function getAllActiveProducts(?string $requestedDomain = null)
    {
        try {
            $productFlats = \Webkul\Product\Models\ProductFlat::query()
                ->where('product_flat.status', 1)
                ->where('product_flat.visible_individually', 1)
                ->where('product_flat.locale', app()->getLocale() ?? 'en')
                ->where('product_flat.channel', core()->getCurrentChannelCode() ?? 'default')
                ->where('product_flat.sku', 'NOT LIKE', '%ONDC%')
                ->select('product_flat.*')
                ->get();

            $result = [];

            foreach ($productFlats as $productFlat) {
                $product = $productFlat->product;

                if (!$product) {
                    continue;
                }

                $image = '';
                try {
                    $image = $product->base_image_url ?: 'https://your-domain.com/images/placeholder.png';
                } catch (\Exception $e) {
                    $image = 'https://your-domain.com/images/placeholder.png';
                }

                $stock = 99;
                try {
                    if ($product->inventories && $product->inventories->isNotEmpty()) {
                        $stock = $product->inventories->sum('qty');
                    } elseif ($productFlat->inventories && $productFlat->inventories->isNotEmpty()) {
                        $stock = $productFlat->inventories->sum('qty');
                    }
                } catch (\Exception $e) {
                    $stock = 99;
                }

                $categoryName = DB::table('product_categories')
                    ->join(
                        'category_translations',
                        'product_categories.category_id',
                        '=',
                        'category_translations.category_id'
                    )
                    ->where('product_categories.product_id', $product->id)
                    ->where('category_translations.locale', app()->getLocale())
                    ->value('name') ?? 'General';

               

                // FIX: DomainResolver::resolve() takes a single argument
                // (the category name) — it does not accept product name,
                // description, or meta_keywords. Passing those extra
                // arguments previously was silently ignored by PHP (no
                // error, but no effect either), which meant domain
                // resolution here was actually just matching category name
                // against config('ondc.domains') keywords.
                $resolvedDomain = $this->domainResolver->resolve($categoryName);

                

                if ($requestedDomain) {
                    if (!$resolvedDomain) {
                        continue;
                    }

                    if ($resolvedDomain !== $requestedDomain) {
                        continue;
                    }
                }

                // Calculate actual selling price (respects Bagisto special_price window)
                $today = now()->toDateString();

                $actualPrice = (float) $productFlat->price;

                if (
                    !empty($productFlat->special_price)
                    && (
                        empty($productFlat->special_price_from)
                        || $productFlat->special_price_from <= $today
                    )
                    && (
                        empty($productFlat->special_price_to)
                        || $productFlat->special_price_to >= $today
                    )
                ) {
                    $actualPrice = (float) $productFlat->special_price;
                }

                $result[] = [
                    'id' => 'I' . $product->id,
                    'name' => (string) ($productFlat->name ?? 'Product'),
                    'sku' => (string) ($productFlat->sku ?? ''),
                    'image' => (string) $image,
                    'images' => [(string) $image],
                    'short_description' => (string) Str::limit($productFlat->description ?? $productFlat->name ?? 'Product', 100),
                    'description' => (string) ($productFlat->description ?? $productFlat->name ?? 'Product'),
                    'price' => $actualPrice,
                    'unit' => 'kilogram',
                    'unit_value' => 1,
                    'stock' => (int) max(0, $stock),
                    'category_name' => (string) $categoryName,
                    'meta_keywords' => (string) ($productFlat->meta_keywords ?? ''),
                    'has_variants' => false,
                    'variant_group_id' => null,
                    'variant_group_name' => null,
                    'returnable' => true,
                    'cancellable' => true,
                    'return_window' => 'P7D',
                    'seller_pickup_return' => false,
                    'time_to_ship' => 'PT24H',
                    'cod_available' => false,
                    'manufacturer_name' => 'Your Company',
                    'manufacturer_address' => 'Your Address, Nagpur, Maharashtra 440001',
                    'manufacture_date' => date('m/Y'),
                    'origin_country' => 'IND',
                ];
            }

            if (empty($result)) {
                Log::info('NO_PRODUCTS_FOUND_USING_SAMPLE_DATA', [
                    'requested_domain' => $requestedDomain,
                ]);
            }

            return $result;
        } catch (\Exception $e) {
            Log::error('PRODUCT_FETCH_ERROR', [
                'error' => $e->getMessage(),
                'class' => get_class($e),
                'line' => $e->getLine(),
            ]);
        }
    }

    /**
     * Build providers array with products - MATCHES OFFICIAL ONDC FORMAT
     *
     * Category/domain resolution now delegates to CategoryMapper (keyword
     * scoring across product name/meta/category/description) and
     * DomainResolver (category-name keyword match against config('ondc.domains')),
     * replacing the old static ONDC_RET10_CATEGORIES/mapToOndcCategory approach.
     */
    protected function buildProviders($products, $timestamp, $context)
    {
        $storeId = 'P1';
        $locationId = 'L1';
        $fulfillmentId = 'F1';

        $groupedByCategory = $this->groupProductsByCategory($products);

        $items = [];
        $variantGroups = [];
        $categoryEntries = [];
        $categoryIds = [];

        foreach ($groupedByCategory as $categoryName => $categoryProducts) {
            foreach ($categoryProducts as $product) {
                $itemId = $product['id'];
                $variantGroupId = null;

                // FIX: single-argument call matching DomainResolver::resolve(?string $categoryName).
                $domain = $this->domainResolver->resolve($product['category_name'] ?? '');

                if (!$domain) {
                    Log::warning('ONDC_DOMAIN_NOT_RESOLVED', [
                        'product' => $product['name'] ?? '',
                        'category' => $product['category_name'] ?? '',
                    ]);

                    $domain = config('ondc.default_domain', 'ONDC:RET10');
                }

                $ondcCategory = $this->categoryMapper->map(
                    $domain,
                    $product['name'] ?? '',
                    $product['category_name'] ?? '',
                    null,
                    $product['description'] ?? '',
                    $product['meta_keywords'] ?? ''
                );

                if (!$ondcCategory) {
                    $ondcCategory = config('ondc.default_category', 'Ready to Cook and Eat');

                    Log::warning('ONDC_CATEGORY_UNMAPPED_USING_FALLBACK', [
                        'product_id'       => $product['id'] ?? null,
                        'name'             => $product['name'] ?? null,
                        'source_category'  => $product['category_name'] ?? null,
                        'domain'           => $domain,
                        'fallback_category' => $ondcCategory,
                    ]);
                }

                if (!in_array($ondcCategory, $categoryIds, true)) {
                    $categoryIds[] = $ondcCategory;
                }

                if (!isset($categoryEntries[$ondcCategory])) {
                    $categoryEntries[$ondcCategory] = [
                        'id' => self::ONDC_RET10_CATEGORY_MAP[$ondcCategory],
                        'descriptor' => [
                            'name' => $ondcCategory,
                        ],
                        'tags' => [
                            [
                                'code' => 'attr',
                                'list' => [
                                    ['code' => 'name', 'value' => $this->sanitizeAttrName($ondcCategory)],
                                    ['code' => 'seq', 'value' => (string) (count($categoryEntries) + 1)],
                                ],
                            ],
                        ],
                    ];
                }

                if ($product['has_variants'] ?? false) {
                    $variantGroupId = 'V' . $product['variant_group_id'];

                    if (!isset($variantGroups[$variantGroupId])) {
                        $variantGroups[$variantGroupId] = [
                            'id' => $variantGroupId,
                            'descriptor' => [
                                'name' => $product['variant_group_name'] ?? $product['name'],
                            ],
                            'tags' => [
                                [
                                    'code' => 'type',
                                    'list' => [
                                        ['code' => 'type', 'value' => 'variant_group'],
                                    ],
                                ],
                                [
                                    'code' => 'attr',
                                    'list' => [
                                        ['code' => 'name', 'value' => $this->sanitizeAttrName($product['variant_group_name'] ?? 'quantity')],
                                        ['code' => 'seq', 'value' => '1'],
                                    ],
                                ],
                            ],
                        ];
                    }
                }

                $inStock = ((int) ($product['stock'] ?? 0)) > 0;
                $availableCount = $inStock ? '99' : '0';

                $item = [
                    'id' => $itemId,
                    'descriptor' => [
                        'name' => $product['name'],
                        'code' => !empty($product['sku']) ? ('1:' . $product['sku']) : '',
                        'symbol' => $product['image'] ?? '',
                        'short_desc' => $product['short_description'] ?? $product['name'],
                        'long_desc' => $product['description'] ?? $product['name'],
                        'images' => $product['images'] ?? [$product['image'] ?? ''],
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value' => number_format((float) $product['price'], 2, '.', ''),
                        'maximum_value' => number_format((float) $product['price'], 2, '.', ''),
                    ],
                    'quantity' => [
                        'unitized' => [
                            'measure' => [
                                'unit' => $product['unit'] ?? 'kilogram',
                                'value' => (string) ($product['unit_value'] ?? 1),
                            ],
                        ],
                        'available' => [
                            'count' => $availableCount,
                        ],
                        'maximum' => [
                            'count' => $availableCount,
                        ],
                    ],
                    'category_id' => $ondcCategory,
                    'fulfillment_id' => $fulfillmentId,
                    'location_id' => $locationId,
                    '@ondc/org/returnable' => $product['returnable'] ?? true,
                    '@ondc/org/cancellable' => $product['cancellable'] ?? true,
                    '@ondc/org/return_window' => $product['return_window'] ?? 'P7D',
                    '@ondc/org/seller_pickup_return' => $product['seller_pickup_return'] ?? false,
                    '@ondc/org/time_to_ship' => $product['time_to_ship'] ?? 'PT24H',
                    '@ondc/org/available_on_cod' => $product['cod_available'] ?? false,
                    '@ondc/org/contact_details_consumer_care' => $this->getConsumerCareString(),
                    '@ondc/org/statutory_reqs_packaged_commodities' => [
                        'manufacturer_or_packer_name' => $product['manufacturer_name'] ?? 'Your Company',
                        'manufacturer_or_packer_address' => $product['manufacturer_address'] ?? 'Your Address',
                        'common_or_generic_name_of_commodity' => $product['name'],
                        'month_year_of_manufacture_packing_import' => $product['manufacture_date'] ?? date('m/Y'),
                    ],
                    'time' => [
                        'label' => 'enable',
                        'timestamp' => $timestamp,
                    ],
                    'tags' => [
                        [
                            'code' => 'origin',
                            'list' => [
                                ['code' => 'country', 'value' => $product['origin_country'] ?? 'IND'],
                            ],
                        ],
                    ],
                ];

                if ($variantGroupId) {
                    $item['parent_item_id'] = $variantGroupId;
                }

                $items[] = $item;
            }
        }

        return [
            [
                'id' => $storeId,
                'descriptor' => [
                    'name' => config('ondc.store.name', 'Store 1'),
                    'symbol' => config('ondc.store.symbol', 'https://your-domain.com/store-logo.png'),
                    'short_desc' => 'Store 1',
                    'long_desc' => 'Store 1',
                    'images' => [config('ondc.store.symbol', 'https://your-domain.com/store-logo.png')],
                ],
                'time' => [
                    'label' => 'enable',
                    'timestamp' => $timestamp,
                ],
                'ttl' => 'P1D',
                'locations' => [
                    [
                        'id' => $locationId,
                        'time' => [
                            'label' => 'enable',
                            'timestamp' => $timestamp,
                            'days' => '1,2,3,4,5,6,7',
                            'schedule' => [
                                'holidays'  => ['2026-05-01'],
                                'frequency' => 'PT4H',
                                'times'     => ['0000', '0400', '0800', '1200', '1600', '2000'],
                            ],
                            'range' => [
                                'start' => '0000',
                                'end' => '2359',
                            ],
                        ],
                        'gps' => config('ondc.store.gps', '21.145800,79.088200'),
                        'address' => [
                            'street' => config('ondc.store.address.street', 'Your Street'),
                            'locality' => config('ondc.store.address.locality', 'Your Locality'),
                            'city' => config('ondc.store.address.city', 'Nagpur'),
                            'state' => config('ondc.store.address.state', 'Maharashtra'),
                            'area_code' => config('ondc.store.address.area_code', '440001'),
                        ],
                        'circle' => [
                            'gps' => config('ondc.store.gps', '21.145800,79.088200'),
                            'radius' => [
                                'unit' => 'km',
                                'value' =>'10',
                            ],
                        ],
                    ],
                ],
                'fulfillments' => [
                    [
                        'id' => $fulfillmentId,
                        'type' => 'Delivery',
                        'contact' => [
                            'phone' => config('ondc.store.phone', '9876543210'),
                            'email' => config('ondc.store.email', 'support@yourstore.com'),
                        ],
                    ],
                ],
                'items' => $items,
                'categories' => array_merge(array_values($categoryEntries), array_values($variantGroups)),
                'tags' => [
                    [
                        'code' => 'timing',
                        'list' => [
                            ['code' => 'type', 'value' => 'All'],
                            ['code' => 'location', 'value' => $locationId],
                            ['code' => 'day_from', 'value' => '1'],
                            ['code' => 'day_to', 'value' => '7'],
                            ['code' => 'time_from', 'value' => '0000'],
                            ['code' => 'time_to', 'value' => '2359'],
                        ],
                    ],
                    [
                        'code' => 'serviceability',
                        'list' => [
                            ['code' => 'location', 'value' => $locationId],
                            ['code' => 'category', 'value' => !empty($categoryIds) ? (string) $categoryIds[0] : 'Ready to Cook and Eat'],
                            ['code' => 'type', 'value' => '10'],
                            ['code' => 'val', 'value' => '10'],
                            ['code' => 'unit', 'value' => 'km'],
                        ],
                    ],
                ],
            ],
        ];
    }

    protected function groupProductsByCategory($products)
    {
        $grouped = [];
        foreach ($products as $product) {
            $categoryName = $product['category_name'] ?? 'General';
            $grouped[$categoryName][] = $product;
        }
        return $grouped;
    }

    protected function getConsumerCareString()
    {
        $careName = config('ondc.store.care_name', 'Customer Care');
        $careEmail = config('ondc.store.care_email', 'care@yourstore.com');
        $carePhone = config('ondc.store.care_phone', '1800-123-4567');
        return "{$careName},{$careEmail},{$carePhone}";
    }

    protected function sanitizeAttrName(?string $value): string
    {
        if (empty($value)) {
            return 'attr';
        }

        $sanitized = preg_replace('/[\s\-]+/', '_', $value);
        $sanitized = preg_replace('/[^a-zA-Z0-9_.]/', '', $sanitized);
        $sanitized = preg_replace('/_+/', '_', $sanitized);
        $sanitized = trim($sanitized, '_.');

        return $sanitized ?: 'attr';
    }

    /**
     * SELECT API - Entry Point (Called by BAP when buyer selects items)
     */
   public function select(Request $request)
{
    $selectData = $request->all();

    Log::info('ONDC_SELECT_RECEIVED', [
        'ip' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'payload' => json_encode(
            $selectData,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        ),
    ]);

    if (!isset($selectData['context']) || !isset($selectData['message'])) {
        return response()->json([
            'error' => 'Invalid payload: context or message missing',
        ], 400);
    }

    // Keep the original ONDC context exactly as received
    $context = $selectData['context'];

    $txnId = $context['transaction_id'] ?? null;

    if (!$txnId) {
        Log::error('TRANSACTION_ID_MISSING_IN_SELECT_REQUEST', [
            'payload' => $selectData,
        ]);

        return response()->json([
            'error' => 'transaction_id missing in context',
        ], 400);
    }

    $bapUri = rtrim($context['bap_uri'] ?? '', '/');
    $bapId  = $context['bap_id'] ?? null;

    if (!$bapUri) {
        Log::error('BAP_URI_MISSING_IN_SELECT_REQUEST', [
            'txn_id' => $txnId,
            'payload' => $selectData,
        ]);

        return response()->json([
            'error' => 'bap_uri missing in context',
        ], 400);
    }

    /*
     * Store transaction information
     */
    Cache::put("ondc:txn:{$txnId}", [
        'bap_uri' => $bapUri,
        'bap_id' => $bapId,
        'original_request' => $selectData,
        'action' => 'select',
    ], now()->addMinutes(30));

    /*
     * Store transaction
     */
    OndcTransaction::create([
        'transaction_id' => $txnId,
        'action' => 'select',
        'domain' => $context['domain'] ?? 'ONDC:RET10',
        'bap_id' => $bapId,
        'bap_uri' => $bapUri,
        'request_payload' => $selectData,
        'status' => 'received',
    ]);

    /*
     * API log
     */
    OndcApiLog::create([
        'type' => 'select_inbound',
        'endpoint' => '/ondc/select',
        'request' => json_encode($selectData),
        'status_code' => 200,
        'transaction_id' => $txnId,
    ]);

    /*
     * ACK WITH ORIGINAL CONTEXT
     */
    $ack = [
        'context' => $context,
        'message' => [
            'ack' => [
                'status' => 'ACK',
            ],
        ],
    ];

    /*
     * Process on_select asynchronously
     */
    dispatch(function () use (
        $selectData,
        $txnId,
        $bapUri,
        $bapId
    ) {
        $this->sendOnSelect(
            $selectData,
            $txnId,
            $bapUri,
            $bapId
        );
    })->onQueue(env('ONDC_QUEUE', 'ondc'));

    /*
     * Return ACK immediately
     */
    return response()->json($ack);
}

    /**
     * Send signed on_select to BAP
     */
    protected function sendOnSelect($selectData, $txnId, $bapUri, $bapId)
    {
        // sleep(5);
        $context = $selectData['context'];
        $message = $selectData['message'] ?? [];
        $messageId = $context['message_id'] ?? null;
        // $messageId = (string) \Illuminate\Support\Str::uuid();

        if ($this->alreadySentCallback($txnId, 'select', $messageId)) {
            Log::warning('ON_SELECT_DUPLICATE_SEND_PREVENTED', [
                'txn_id'     => $txnId,
                'message_id' => $messageId,
            ]);
            return;
        }

        if (!config('services.ondc.private_key')) {
            Log::error('ONDC_PRIVATE_KEY_MISSING');

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action' => 'on_select',
                'domain' => $selectData['context']['domain'] ?? 'ONDC:RET14',
                'bap_id' => $bapId,
                'bap_uri' => $bapUri,
                'request_payload' => $selectData,
                'status' => 'received',
            ]);

            OndcApiLog::create([
                'type' => 'on_select_outbound_error',
                'endpoint' => $bapUri . '/on_select',
                'request' => json_encode(['error' => 'ONDC_PRIVATE_KEY not configured']),
                'status_code' => 500,
                'transaction_id' => $txnId,
            ]);
            return;
        }

        $payload = $this->buildOnSelectPayload($context, $message, $bapUri, $bapId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_SELECT_PAYLOAD', [
            'txn_id' => $txnId,
            'bap_uri' => $bapUri,
            'item_count' => count($payload['message']['order']['items'] ?? []),
            'domain' => $payload['context']['domain'],
            'action' => $payload['context']['action'],
        ]);

       $signedHeaders = $this->getSignedOndcHeaders(
    $payloadJson,
    $txnId,
    'select'
);

$headers = array_merge($signedHeaders, [
    'Accept'        => 'application/json',
    'Content-Type'  => 'application/json',
]);
        $endpoints = [
            // [
            //     'url' => $bapUri . '/on_select',
            //     'type' => 'bap',
            //     'headers' => $headers,
            // ],
            [
                'url' => rtrim(config('services.ondc.bpp_uri', 'https://retail-seller.softtrust.org'), '/') . '/on_select',
                'type' => 'bpp_internal',
                'headers' => array_merge($headers, [
                    'Accept'          => 'application/json',
                    'Content-Type'    => 'application/json',
                    'X-Internal-Key'  => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'         => $bapUri,
                ]),
            ],
        ];

        foreach ($endpoints as $endpoint) {
            Log::info('ON_SEARCH_TARGET', [
                'url' => $endpoint['url'],
                'type' => $endpoint['type'],
            ]);
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'select', $payload, $bapUri, $bapId);
        }
    }

    /**
     * Build on_select payload
     */
    protected function buildOnSelectPayload($context, $message, $bapUri, $bapId)
    {
        $inboundTimestamp = $context['timestamp'] ?? now()->toIso8601String();
        $timestamp = \Carbon\Carbon::parse($inboundTimestamp)->addSeconds(1)->format('Y-m-d\TH:i:s.v\Z');

        $items = $message['order']['items'] ?? [];
        $providerId = $message['order']['provider']['id'] ?? 'P1';
        $locationId = $message['order']['provider']['locations'][0]['id'] ?? 'L1';

        $validatedItems = $this->validateAndBuildItems($items);
        $quoteBreakup = $this->buildQuoteBreakup($validatedItems);
        $totalPrice = array_sum(array_column($quoteBreakup, 'price_value'));
        $quoteBreakup = array_map(function ($item) {
            unset($item['price_value']);
            return $item;
        }, $quoteBreakup);

        $hasOutOfStock = collect($validatedItems)->contains(function ($item) {
            return ($item['@ondc/org/item_status'] ?? '') === 'out_of_stock';
        });

        $hasInvalidItem = collect($validatedItems)->contains(function ($item) {
            return ($item['@ondc/org/item_status'] ?? '') === 'invalid';
        });

        $payload = [
            'context' => [
                'domain'         => $context['domain'] ?? 'ONDC:RET10',
                'country'        => $context['country'] ?? 'IND',
                'city'           => $context['city'] ?? 'std:011',
                'core_version'   => $context['core_version'] ?? '1.2.0',
                'action'         => 'on_select',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'transaction_id' => $context['transaction_id'],
                'message_id'     => $context['message_id'],
                'timestamp'      => $timestamp,
                'ttl'            => 'PT30S',
            ],
            'message' => [
                'order' => [
                    'provider' => [
                        'id'        => $providerId,
                        'locations' => [['id' => $locationId]],
                    ],
                    'fulfillments' => [
                        [
                            'id'                         => 'F1',
                            'type'                       => 'Delivery',
                            '@ondc/org/provider_name'    => config('ondc.store.name', 'Store 1'),
                            'tracking'                   => true,
                            '@ondc/org/category'         => 'Standard Delivery',
                            '@ondc/org/TAT'              => 'PT24H',
                            'state' => [
                                'descriptor' => [
                                    'code' => $hasOutOfStock ? 'Non-Serviceable' : 'Serviceable',
                                ],
                            ],
                        ],
                    ],
                    'quote' => [
                        'price' => [
                            'value'    => number_format($totalPrice, 2, '.', ''),
                            'currency' => 'INR',
                        ],
                        'breakup' => $quoteBreakup,
                        'ttl'     => 'P1D',
                    ],
                    'items' => $validatedItems,
                ],
            ],
        ];

        if ($hasOutOfStock) {
            $payload['error'] = [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '40002',
                'message' => 'One or more items are out of stock',
            ];
        } elseif ($hasInvalidItem) {
            $payload['error'] = [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '40002',
                'message' => 'One or more items are not available',
            ];
        }

        return $payload;
    }

    /**
     * Validate items and build response with actual price & availability
     */
    protected function validateAndBuildItems($items)
    {
        $validatedItems = [];

        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            $quantity = (int) ($item['quantity']['count'] ?? 1);
            $fulfillmentId = $item['fulfillment_id'] ?? 'F1';

            if (!$itemId) {
                continue;
            }

            $productId = str_replace('I', '', $itemId);

            $product = \Webkul\Product\Models\ProductFlat::query()
                ->where('product_flat.product_id', $productId)
                ->where('product_flat.status', 1)
                ->where('product_flat.visible_individually', 1)
                ->where('product_flat.locale', app()->getLocale() ?? 'en')
                ->where('product_flat.channel', core()->getCurrentChannelCode() ?? 'default')
                ->first();

            if (!$product) {
                $validatedItems[] = [
                    'id' => $itemId,
                    'fulfillment_id' => $fulfillmentId,
                    'descriptor' => [
                        'name' => 'Item not found',
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value' => '0.00',
                    ],
                    'quantity' => [
                        'count' => (int) $quantity,
                    ],
                    '@ondc/org/item_status' => 'invalid',
                    '@ondc/org/error_code' => 'ITEM_NOT_FOUND',
                ];
                continue;
            }

            $today = now()->toDateString();

            $actualPrice = (float) $product->price;

            if (
                !empty($product->special_price)
                && (
                    empty($product->special_price_from)
                    || $product->special_price_from <= $today
                )
                && (
                    empty($product->special_price_to)
                    || $product->special_price_to >= $today
                )
            ) {
                $actualPrice = (float) $product->special_price;
            }

            $availableStock = 0;
            try {
                if ($product->product && $product->product->inventories) {
                    $availableStock = $product->product->inventories->sum('qty');
                }
            } catch (\Exception $e) {
                $availableStock = 99;
            }

            $isAvailable = $availableStock >= $quantity;

            $validatedItems[] = [
                'id' => $itemId,
                'fulfillment_id' => $fulfillmentId,
                'descriptor' => [
                    'name' => $product->name,
                    'code' => !empty($product->sku) ? ('1:' . $product->sku) : '',
                    'symbol' => $product->base_image_url ?? '',
                    'short_desc' => Str::limit($product->description, 100),
                    'long_desc' => $product->description,
                    'images' => [$product->base_image_url ?? ''],
                ],
                'price' => [
                    'currency' => 'INR',
                    'value' => number_format($actualPrice, 2, '.', ''),
                    'maximum_value' => number_format($actualPrice, 2, '.', ''),
                ],
                'quantity' => [
                    'count' => (int) $quantity,
                    'available' => [
                        'count' => (int) ($isAvailable ? $quantity : 0),
                    ],
                    'maximum' => [
                        'count' => (int) ($isAvailable ? $quantity : 0),
                    ],
                ],
                'location_id' => 'L1',
                '@ondc/org/item_status' => $isAvailable ? 'valid' : 'out_of_stock',
                '@ondc/org/item_quantity' => [
                    'count' => (int) ($isAvailable ? $quantity : 0),
                ],
            ];

            if (!$isAvailable) {
                $validatedItems[count($validatedItems) - 1]['@ondc/org/error_code'] = 'OUT_OF_STOCK';
            }
        }

        return $validatedItems;
    }

    /**
     * Build quote breakup array
     */
    protected function buildQuoteBreakup($items)
    {
        $breakup = [];
        $totalItemPrice = 0;

        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            $quantity = (int) ($item['quantity']['count'] ?? 1);
            $price = (float) ($item['price']['value'] ?? 0);
            $itemName = $item['descriptor']['name'] ?? 'Item';
            $itemPrice = $price * $quantity;
            $totalItemPrice += $itemPrice;

            $breakup[] = [
                '@ondc/org/item_id'       => $itemId,
                '@ondc/org/item_quantity' => ['count' => $quantity],
                'title'                   => $itemName,
                '@ondc/org/title_type'    => 'item',
                'price_value'             => $itemPrice,
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($itemPrice, 2, '.', ''),
                ],
                'item' => [
                    'quantity' => [
                        'available' => ['count' => (string) ($item['quantity']['available']['count'] ?? $quantity)],
                        'maximum'   => ['count' => (string) ($item['quantity']['maximum']['count'] ?? $quantity)],
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($price, 2, '.', ''),
                    ],
                ],
            ];
        }

        $deliveryCharge = 0.00;
        $breakup[] = [
            '@ondc/org/item_id' => $items[0]['id'] ?? 'I1',
            'title' => 'Delivery charges',
            '@ondc/org/title_type' => 'delivery',
            'price' => [
                'currency' => 'INR',
                'value' => number_format($deliveryCharge, 2, '.', ''),
            ],
        ];

        $convenienceFee = 0.00;
        $breakup[] = [
            '@ondc/org/item_id' => $items[0]['id'] ?? 'I1',
            'title' => 'Convenience Fee',
            '@ondc/org/title_type' => 'misc',
            'price' => [
                'currency' => 'INR',
                'value' => number_format($convenienceFee, 2, '.', ''),
            ],
        ];

        return $breakup;
    }

    /**
     * INIT API - Entry Point (Called by BAP after select)
     */
    public function init(Request $request)
    {
        $initData = $request->all();

        Log::info('ONDC_INIT_RECEIVED', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'transaction_id' => data_get($initData, 'context.transaction_id'),
            'message_id' => data_get($initData, 'context.message_id'),
            'action' => data_get($initData, 'context.action'),
            'domain' => data_get($initData, 'context.domain'),
            'bap_id' => data_get($initData, 'context.bap_id'),
        ]);

        if (!isset($initData['context']) || !isset($initData['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        $ctxt = $initData['context'];

        Log::info('INIT_MESSAGE_ID_DEBUG', [
            'transaction_id' => $ctxt['transaction_id'] ?? null,
            'message_id'     => $ctxt['message_id'] ?? null,
            'action'         => $ctxt['action'] ?? null,
        ]);


        if (!$ctxt['domain'] ?? null || !$ctxt['bap_id'] ?? null) {
            return response()->json([
                'error' => 'Missing required context fields',
            ], 400);
        }

        $txnId   = $ctxt['transaction_id'] ?? Str::uuid()->toString();
        $action  = $ctxt['action']       ?? 'init';
        $bapUri  = rtrim($ctxt['bap_uri'] ?? '', '/');
        $bapId   = $ctxt['bap_id']       ?? null;

        if (!$bapUri) {
            Log::error('BAP_URI_MISSING_IN_INIT', [
                'txn_id'  => $txnId,
                'payload' => $initData,
            ]);

            return response()->json([
                'error' => 'bap_uri missing in context',
            ], 400);
        }

        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri'           => $bapUri,
            'bap_id'            => $bapId,
            'original_request'  => $initData,
            'action'            => $action,
        ], now()->addMinutes(30));

        OndcTransaction::create([
            'transaction_id'   => $txnId,
            'action'           => $action,
            'domain'           => $ctxt['domain'] ?? 'ONDC:RET10',
            'bap_id'           => $bapId,
            'bap_uri'          => $bapUri,
            'bpp_id'           => config('services.ondc.bpp_id'),
            'status'           => 'received',
            'request_payload'  => $initData,
            'created_at' => now(),
            'updated_at'       => now(),
        ]);

        OndcApiLog::create([
            'type'            => $action . '_inbound',
            'endpoint'        => '/ondc/init',
            'request'         => json_encode($initData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'status_code'     => 200,
            'transaction_id'  => $txnId,
        ]);

        
            
        

    $ack = [
    'context' => $ctxt,
    'message' => [
        'ack' => [
            'status' => 'ACK',
        ],
    ],
];

dispatch(function () use (
    $initData,
    $txnId,
    $bapUri,
    $bapId
) {
    $this->sendOnInit(
        $initData,
        $txnId,
        $bapUri,
        $bapId
    );
})->onQueue(env('ONDC_QUEUE', 'ondc'));

return response()->json($ack);
    }

    /**
 * Send on_init response asynchronously
 */
protected function sendOnInit($initData, $txnId, $bapUri, $bapId)
{
    Log::info('SEND_ON_INIT_JOB_STARTED', [
        'txn_id'  => $txnId,
        'bap_uri' => $bapUri,
        'bap_id'  => $bapId,
    ]);

    try {
        $responsePayload = $this->buildOnInitPayload(
            $initData,
            $txnId,
            $bapUri,
            $bapId
        );

        if (empty($responsePayload['context']['message_id'] ?? null)) {
            Log::error('ON_INIT_MESSAGE_ID_MISSING', [
                'txn_id' => $txnId,
            ]);

            return;
        }

        $msgId = $responsePayload['context']['message_id'];

        /*
         * Prevent duplicate on_init callback
         */
        if ($this->alreadySentCallback($txnId, 'init', $msgId)) {
            Log::warning('ON_INIT_DUPLICATE_SEND_PREVENTED', [
                'txn_id'     => $txnId,
                'message_id' => $msgId,
            ]);

            return;
        }

        $payloadJson = json_encode(
            $responsePayload,
            JSON_UNESCAPED_SLASHES
        );

        Log::info('BUILDING_ON_INIT_PAYLOAD', [
            'txn_id'     => $txnId,
            'bap_uri'    => $bapUri,
            'message_id' => $msgId,
            'action'     => $responsePayload['context']['action'] ?? null,
        ]);

        /*
         * Generate ONDC signature
         */
        $signedHeaders = $this->getSignedOndcHeaders(
            $payloadJson,
            $txnId,
            'init'
        );

        $headers = array_merge($signedHeaders, [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ]);

        /*
         * Send to internal BPP endpoint.
         *
         * The bap_uri header tells the internal BPP
         * where the actual on_init callback must go.
         */
        $endpoints = [
            [
                'url' => rtrim(
                    config(
                        'services.ondc.bpp_uri',
                        'https://retail-seller.softtrust.org'
                    ),
                    '/'
                ) . '/on_init',

                'type' => 'bpp_internal',

                'headers' => array_merge($headers, [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Internal-Key' => env(
                        'ONDC_INTERNAL_KEY',
                        'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='
                    ),
                    'bap_uri' => $bapUri,
                ]),
            ],
        ];

        foreach ($endpoints as $endpoint) {

            Log::info('ON_INIT_TARGET', [
                'url'     => $endpoint['url'],
                'type'    => $endpoint['type'],
                'txn_id'  => $txnId,
                'bap_uri' => $bapUri,
            ]);

            Log::info('ON_INIT_FINAL_PAYLOAD_DEBUG', [
                'transaction_id' => $txnId,
                'message_id' => $responsePayload['context']['message_id'] ?? null,
                'action' => $responsePayload['context']['action'] ?? null,
                'payload' => json_encode(
                    $responsePayload,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ),
            ]);

            $this->sendToEndpoint(
                $endpoint,
                $payloadJson,
                $txnId,
                'init',
                $responsePayload,
                $bapUri,
                $bapId
            );
        }

        /*
         * Log the generated on_init payload
         */
        OndcApiLog::create([
            'type'           => 'init_outbound_async',
            'endpoint'       => $bapUri . '/on_init',
            'request'        => $payloadJson,
            'status_code'    => 200,
            'transaction_id' => $txnId,
        ]);

        Log::info('SEND_ON_INIT_JOB_COMPLETED', [
            'txn_id'     => $txnId,
            'message_id' => $msgId,
        ]);

    } catch (\Exception $e) {

        Log::error('ON_INIT_SEND_ERROR', [
            'txn_id' => $txnId,
            'error'  => $e->getMessage(),
            'class'  => get_class($e),
            'line'   => $e->getLine(),
            'file'   => $e->getFile(),
        ]);

        OndcTransaction::create([
            'transaction_id' => $txnId,
            'action'         => 'on_init',
            'bap_id'         => $bapId,
            'bap_uri'        => $bapUri,
            'status'         => 'failed',
            'error_message'  => $e->getMessage(),
        ]);

        OndcApiLog::create([
            'type'           => 'init_outbound_async_error',
            'endpoint'       => $bapUri . '/on_init',
            'request'        => json_encode($initData),
            'response'       => json_encode([
                'error' => $e->getMessage(),
            ]),
            'status_code'    => 500,
            'transaction_id' => $txnId,
        ]);
    }
}

    /**
     * Build on_init payload synchronously
     */
    protected function buildOnInitPayload($initData, $txnId, $bapUri, $bapId = null)
    {
                $inboundContext = $initData['context'] ?? [];    
                $payload = $initData['message']['order'] ?? [];

        $inboundTimestamp = $inboundContext['timestamp'] ?? now()->toIso8601String();
        $outboundTimestamp = \Carbon\Carbon::parse($inboundTimestamp)->addSeconds(1)->format('Y-m-d\TH:i:s.v\Z');

        $order = [
            'provider'       => $payload['provider'] ?? [],
            'items'          => $this->buildItemsForOnInit($payload['items'] ?? []),
            'billing'        => $this->buildBillingForOnInit($payload['billing'] ?? []),
            'fulfillments'   => $this->buildFulfillmentsForOnInit($payload['fulfillments'] ?? []),
            'quote'          => $this->buildQuoteForOnInit($payload),
            'payment'        => $this->buildPaymentForOnInit($initData),
            'tags'           => $this->buildBppTermsTags(),
            'cancellation_terms' => [
                [
                    'fulfillment_state' => [
                        'descriptor' => ['code' => 'Pending', 'short_desc' => '003']
                    ],
                    'cancellation_fee' => [
                        'percentage' => '0.00',
                        'amount' => ['currency' => 'INR', 'value' => '0.00']
                    ]
                ],
                [
                    'fulfillment_state' => [
                        'descriptor' => ['code' => 'Packed', 'short_desc' => '001,003']
                    ],
                    'cancellation_fee' => [
                        'percentage' => '10.00',
                        'amount' => ['currency' => 'INR', 'value' => '10.00']
                    ]
                ],
                [
                    'fulfillment_state' => [
                        'descriptor' => ['code' => 'Order-picked-up', 'short_desc' => '001,003']
                    ],
                    'cancellation_fee' => [
                        'percentage' => '10.00',
                        'amount' => ['currency' => 'INR', 'value' => '10.00']
                    ]
                ],
            ],
        ];

        Log::info('ON_INIT_INCOMING_MESSAGE_ID_DEBUG', [
            'transaction_id' => $txnId,
            'message_id'     => $initData['context']['message_id'] ?? null,
        ]);

       $context = [
            'domain'         => $inboundContext['domain'] ?? 'ONDC:RET10',
            'country'        => $inboundContext['country'] ?? 'IND',
            'city'           => $inboundContext['city'] ?? 'std:080',
            'core_version'   => $inboundContext['core_version'] ?? '1.2.0',
            'action'         => 'on_init',
            'transaction_id' => $inboundContext['transaction_id'] ?? $txnId,
            'message_id'     => $inboundContext['message_id'],
            'ttl'            => 'PT30S',
            'bap_id'         => $inboundContext['bap_id'] ?? $bapId,
            'bap_uri'        => $inboundContext['bap_uri'] ?? $bapUri,
            'bpp_id'         => config('services.ondc.bpp_id'),
            'bpp_uri'        => config('services.ondc.bpp_uri'),
            'timestamp'      => $outboundTimestamp, // 2. Assign dynamic timestamp
        ];

        Log::info('ON_INIT_BUILT_MESSAGE_ID_DEBUG', [
            'transaction_id' => $txnId,
            'message_id'     => $context['message_id'] ?? null,
        ]);

        return [
            'context' => $context,
            'message' => [
                'order' => $order,
            ],
        ];
    }

    protected function buildQuoteForOnInit($orderPayload)

    {

        $items = $orderPayload['items'] ?? [];

        $breakup = [];

        $totalValue = 0.00;
    
        foreach ($items as $item) {

            $itemId = $item['id'] ?? null;

            if (!$itemId) {

                continue;

            }
    
            $productId = (int) str_replace('I', '', $itemId);
    
            $product = \Webkul\Product\Models\ProductFlat::query()

                ->where('product_id', $productId)

                ->where('status', 1)

                ->where('visible_individually', 1)

                ->where('locale', app()->getLocale())

                ->where('channel', core()->getCurrentChannelCode())

                ->first();
    
            if (!$product) {

                continue;

            }
    
            $quantity  = (int) ($item['quantity']['count'] ?? 1);

            $unitPrice = (float) $this->getOnInitProductPrice($product);

            $itemTotal = $unitPrice * $quantity;

            $totalValue += $itemTotal;
    
            $breakup[] = [

                '@ondc/org/item_id'       => (string) $itemId,

                '@ondc/org/item_quantity' => [

                    'count' => $quantity,

                ],

                'title'                   => (string) $product->name,

                '@ondc/org/title_type'    => 'item',

                'price'                   => [

                    'currency' => 'INR',

                    'value'    => number_format($itemTotal, 2, '.', ''),

                ],

                'item'                    => [

                    'price' => [

                        'currency' => 'INR',

                        'value'    => number_format($unitPrice, 2, '.', ''),

                    ],

                ],

            ];

        }
    
        // Fulfillments ID for delivery & convenience charges

        $fulfillmentId = $orderPayload['fulfillments'][0]['id'] ?? 'F1';
    
        // Delivery charges breakup item

        $breakup[] = [

            '@ondc/org/item_id'    => (string) $fulfillmentId,

            '@ondc/org/title_type' => 'delivery',

            'title'                => 'Delivery charges',

            'price'                => [

                'currency' => 'INR',

                'value'    => '0.00',

            ],

        ];
    
        // Convenience fee breakup item

        $breakup[] = [

            '@ondc/org/item_id'    => (string) $fulfillmentId,

            '@ondc/org/title_type' => 'misc',

            'title'                => 'Convenience Fee',

            'price'                => [

                'currency' => 'INR',

                'value'    => '0.00',

            ],

        ];
    
        return [

            'price' => [

                'currency' => 'INR',

                'value'    => number_format($totalValue, 2, '.', ''),

            ],

            'breakup' => $breakup,

            'ttl'     => 'P1D',

        ];

    }
    
    

    protected function buildBillingForOnInit($billing)
    {
        $name = $billing['name'] ?? ($billing['address']['name'] ?? 'ONDC buyer');

        return [
            'name'        => $name,
            'email'       => $billing['email'] ?? 'nobody@nomail.com',
            'phone'       => $billing['phone'] ?? '9898989898',
            'tax_number'  => $billing['tax_number'] ?? '00ABCDE1234F1Z5',
            'created_at'  => $billing['created_at'] ?? OndcHelper::timestamp(),
            'updated_at'  => $billing['updated_at'] ?? OndcHelper::timestamp(),
            'address' => [
                'name'      => $billing['address']['name']      ?? $name,
                'building'  => $billing['address']['building']  ?? 'Building',
                'locality'  => $billing['address']['locality']  ?? 'Locality',
                'city'      => $billing['address']['city']      ?? 'Bengaluru',
                'state'     => $billing['address']['state']     ?? 'Karnataka',
                'country' => ($billing['address']['country'] === 'India') ? 'IND' : ($billing['address']['country'] ?? 'IND'),
                'area_code' => $billing['address']['area_code'] ?? '560001',
            ],
        ];
    }

    protected function buildFulfillmentsForOnInit($fulfillments, $billing = [])
    {
        $storeName = config('ondc.store.name', 'Store 1');

        return collect($fulfillments)->map(function ($f) use ($storeName, $billing) {
            $endLocation = $f['end']['location'] ?? [];
            $rawAddress  = $endLocation['address'] ?? ($billing['address'] ?? []);
            $personName  = $f['end']['person']['name'] ?? ($billing['name'] ?? 'Buyer');

            $cleanAddress = [
                'name'      => (string) ($rawAddress['name'] ?? $personName),
                'building'  => (string) ($rawAddress['building'] ?? $rawAddress['door'] ?? 'Building'),
                'locality'  => (string) ($rawAddress['locality'] ?? $rawAddress['street'] ?? 'Locality'),
                'city'      => (string) ($rawAddress['city'] ?? 'Mumbai'),
                'state'     => (string) ($rawAddress['state'] ?? 'Maharashtra'),
                'country'   => (string) (($rawAddress['country'] ?? '') === 'India' ? 'IND' : ($rawAddress['country'] ?? 'IND')),
                'area_code' => (string) ($rawAddress['area_code'] ?? $rawAddress['postcode'] ?? '400053'),
            ];

            return [
                'id'                      => (string) ($f['id'] ?? 'F1'),
                'type'                    => (string) ($f['type'] ?? 'Delivery'),
                'tracking'                => true,
                '@ondc/org/provider_name' => (string) ($f['@ondc/org/provider_name'] ?? $storeName),
                '@ondc/org/TAT'           => (string) ($f['@ondc/org/TAT'] ?? 'PT60M'),
                'end'                     => [
                    'contact' => [
                        'email' => (string) ($f['end']['contact']['email'] ?? ($billing['email'] ?? 'nobody@nomail.com')),
                        'phone' => (string) ($f['end']['contact']['phone'] ?? ($billing['phone'] ?? '9898989898')),
                    ],
                    'location' => [
                        // 1. CRITICAL FIX: Appended '00' to coordinates so they meet the 6 decimal minimum format length
                        'gps'     => (string) ($endLocation['gps'] ?? config('ondc.store.gps', '21.145800,79.088200')),
                        'address' => $cleanAddress,
                    ],
                ],
            ];
        })->all();
    }

    protected function buildItemsForOnInit($items)
    {
        return collect($items)->map(function ($item) {

            $itemId = $item['id'] ?? null;

            if (!$itemId) {
                return $item;
            }

            // init item IDs are like I1112, I1157, etc.
            $productId = (int) str_replace('I', '', $itemId);

            $product = \Webkul\Product\Models\ProductFlat::query()
                ->where('product_id', $productId)
                ->where('status', 1)
                ->where('visible_individually', 1)
                ->where('locale', app()->getLocale())
                ->where('channel', core()->getCurrentChannelCode())
                ->first();

            if (!$product) {
                Log::warning('ON_INIT_PRODUCT_NOT_FOUND', [
                    'item_id'    => $itemId,
                    'product_id' => $productId,
                ]);

                return $item;
            }

            $quantity = (int) ($item['quantity']['count'] ?? 1);

            return [
                'id' => $itemId,

                'fulfillment_id' => $item['fulfillment_id'] ?? 'F1',

                'quantity' => [
                    'count' => $quantity,
                ],

                // Actual product information
                'descriptor' => [
                    'name'       => $product->name,
                    'short_desc' => strip_tags($product->short_description ?? ''),
                    'long_desc'  => strip_tags($product->description ?? ''),
                    'code'       => $product->sku,
                ],

                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format(
                        $this->getOnInitProductPrice($product),
                        2,
                        '.',
                        ''
                    ),
                ],

                'location_id' => $item['location_id']
                    ?? $item['location']
                    ?? 'L1',
            ];
        })->values()->all();
    }

    protected function getOnInitProductPrice($product)
    {
        $today = now();

        if (
            $product->special_price !== null
            && $product->special_price !== ''
            && $product->special_price > 0
            && (
                !$product->special_price_from
                || $today->greaterThanOrEqualTo($product->special_price_from)
            )
            && (
                !$product->special_price_to
                || $today->lessThanOrEqualTo($product->special_price_to)
            )
        ) {
            return (float) $product->special_price;
        }

        return (float) $product->price;
    }

    protected function buildCancellationTerms(): array
    {
        return [
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Pending', 'short_desc' => '003']],
                'cancellation_fee'  => ['percentage' => '0.00', 'amount' => ['currency' => 'INR', 'value' => '0.00']],
            ],
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Packed', 'short_desc' => '001,003']],
                'cancellation_fee'  => ['percentage' => '10.00', 'amount' => ['currency' => 'INR', 'value' => '0.00']],
            ],
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Order-picked-up', 'short_desc' => '001,003']],
                'cancellation_fee'  => ['percentage' => '10.00', 'amount' => ['currency' => 'INR', 'value' => '0.00']],
            ],
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Out-for-delivery', 'short_desc' => '009']],
                'cancellation_fee'  => ['percentage' => '0.00', 'amount' => ['currency' => 'INR', 'value' => '0.00']],
            ],
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Out-for-delivery', 'short_desc' => '010,011,012,013,014,015']],
                'cancellation_fee'  => ['percentage' => '100.00', 'amount' => ['currency' => 'INR', 'value' => '0.00']],
            ],
        ];
    }

    protected function buildPaymentForOnInit($initData)
    {
        return [
            'type'         => 'ON-ORDER',
            'collected_by' => 'BPP',
            'uri'          => env('ONDC_PAYMENT_URI', 'https://retail-seller.softtrust.org/payment'),
            'status'       => 'NOT-PAID',
            '@ondc/org/buyer_app_finder_fee_type'   => 'percent',
            '@ondc/org/buyer_app_finder_fee_amount' => '3',
            '@ondc/org/settlement_basis'            => 'delivery',
            '@ondc/org/settlement_window'           => 'P1D',
            '@ondc/org/withholding_amount'          => '10.00',
            '@ondc/org/settlement_details' => [
                [
                    'settlement_counterparty' => 'seller-app',
                    'settlement_phase'        => 'sale-amount',
                    'settlement_type'         => 'neft',
                    'beneficiary_name'        => env('ONDC_BENEFICIARY_NAME', 'Store'),
                    'settlement_bank_account_no' => env('ONDC_BANK_ACCOUNT'),
                    'settlement_ifsc_code'    => env('ONDC_IFSC_CODE'),
                    'bank_name'               => env('ONDC_BANK_NAME'),
                    'branch_name'             => env('ONDC_BRANCH_NAME'),
                ],
            ],
        ];
    }

    protected function buildBppTermsTags()
    {
        return [
            [
                'code' => 'bpp_terms',
                'list' => [
                    [
                        'code'  => 'np_type',
                        'value' => 'MSN',
                    ],
                    [
                        'code'  => 'tax_number',
                        'value' => env('ONDC_SELLER_GST', 'gst_number_of_sellerNP'),
                    ],
                    [
                        'code'  => 'provider_tax_number',
                        'value' => env('ONDC_SELLER_PAN', 'PAN_number_of_provider'),
                    ],
                ],
            ],
        ];
    }

    protected function getHttpHeadersForOnInit($txnId, $payloadJson)
    {
        if (! is_string($payloadJson)) {
            $payloadJson = json_encode($payloadJson, JSON_UNESCAPED_SLASHES);
        }

        $digest = base64_encode(hash('sha256', $payloadJson, true));

        $now     = time();
        $created = $now;
        $expires = $now + 60;

        $signer = new OndcSigner();

        try {
            $signatureHeaders = $signer->signRequest(
                $payloadJson,
                config('services.ondc.bpp_id'),
                config('services.ondc.private_key')
            );
        } catch (\Exception $e) {
            Log::error('INIT_SIGNATURE_ERROR', [
                'txn_id'  => $txnId,
                'error'   => $e->getMessage(),
            ]);

            return [
                'Authorization' => 'Signature error',
                'Content-Type'  => 'text/plain',
            ];
        }

        $headers = array_merge($signatureHeaders, [
            'Content-Type' => 'application/json',
        ]);

        $headers['(created)'] = $created;
        $headers['(expires)'] = $expires;

        return $headers;
    }

    protected function sanitizeOrderTags(array $tags): array
    {
        // $allowedCodes = [
        //     'np_type',
        //     'static_terms',
        //     'tax_number',
        //     'provider_tax_number',
        //     'accept_bap_terms',
        //     'max_liability_cap',
        //     'max_liability',
        //     'mandatory_arbitration',
        //     'court_jurisdiction',
        //     'delay_interest',
        // ];

        $allowedCodes = [
    'static_terms',
    'tax_number',
    'accept_bpp_terms',
];

        return collect($tags)->map(function ($tag) use ($allowedCodes) {
            if (!isset($tag['list']) || !is_array($tag['list'])) {
                return $tag;
            }

            $tag['list'] = collect($tag['list'])
                ->map(function ($item) use ($allowedCodes) {
                    // if (($item['code'] ?? '') === 'accept_bpp_terms') {
                    //     $item['code'] = 'accept_bap_terms';
                    // }
                    return in_array($item['code'] ?? '', $allowedCodes) ? $item : null;
                })
                ->filter()
                ->values()
                ->all();

            return $tag;
        })->all();
    }

    /**
    * CONFIRM API - Order is confirmed by BAP
    */
    public function confirm(Request $request)
    {
        $confirmData = $request->all();
    
        Log::info('ONDC_CONFIRM_RECEIVED', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload'    => $confirmData,
        ]);
    
        /*
        * Basic validation
        */
        if (
            !isset($confirmData['context']) ||
            !isset($confirmData['message'])
        ) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }
    
        $context = $confirmData['context'];
    
        /*
        * Required context
        */
        $txnId = $context['transaction_id'] ?? null;
        $bapId = $context['bap_id'] ?? null;
        $bapUri = rtrim($context['bap_uri'] ?? '', '/');
        $messageId = $context['message_id'] ?? null;
    
        if (!$txnId) {
            return response()->json([
                'error' => 'transaction_id missing in context',
            ], 400);
        }
    
        if (!$messageId) {
            return response()->json([
                'error' => 'message_id missing in context',
            ], 400);
        }
    
        if (!$bapId) {
            return response()->json([
                'error' => 'bap_id missing in context',
            ], 400);
        }
    
        if (!$bapUri) {
            Log::error('BAP_URI_MISSING_IN_CONFIRM', [
                'txn_id'  => $txnId,
                'payload' => $confirmData,
            ]);
    
            return response()->json([
                'error' => 'bap_uri missing in context',
            ], 400);
        }
    
        /*
        * Store transaction/session information
        */
        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri'          => $bapUri,
            'bap_id'           => $bapId,
            'original_request' => $confirmData,
            'action'           => 'confirm',
        ], now()->addMinutes(30));
    
        /*
        * Save inbound confirm
        */
        OndcTransaction::create([
            'transaction_id'  => $txnId,
            'action'          => 'confirm',
            'domain'          => $context['domain'] ?? 'ONDC:RET10',
            'bap_id'           => $bapId,
            'bap_uri'          => $bapUri,
            'bpp_id'           => config('services.ondc.bpp_id'),
            'status'           => 'received',
            'request_payload'  => $confirmData,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    
        /*
        * API log
        */
        OndcApiLog::create([
            'type'            => 'confirm_inbound',
            'endpoint'        => '/ondc/confirm',
            'request'         => json_encode(
                $confirmData,
                JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
            ),
            'status_code'     => 200,
            'transaction_id'  => $txnId,
        ]);
    
        /*
        * IMPORTANT:
        *
        * ACK must contain the SAME context received
        * from the BAP.
        *
        * Do NOT generate a new message_id here.
        */
        $ack = [
            'context' => $context,
    
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ];
    
        /*
        * Process on_confirm asynchronously.
        */
        dispatch(function () use (
            $confirmData,
            $txnId,
            $bapUri,
            $bapId
        ) {
            $this->sendOnConfirm(
                $confirmData,
                $txnId,
                $bapUri,
                $bapId
            );
        })->onQueue('ondc-confirm');
    
        /*
        * Return ACK immediately.
        */
        return response()->json($ack);
    }

    /**
    * Send on_confirm response asynchronously.
    */
    protected function sendOnConfirm(
        $confirmData,
        $txnId,
        $bapUri,
        $bapId
    ) {
        Log::info('SEND_ON_CONFIRM_JOB_STARTED', [
            'txn_id'  => $txnId,
            'bap_uri' => $bapUri,
            'bap_id'  => $bapId,
        ]);
    
        try {
    
            /*
            * Build the actual on_confirm payload.
            *
            * This is where:
            * - Bagisto order is created
            * - order items are created
            * - payment is stored
            * - on_confirm response is prepared
            */
            $responsePayload = $this->buildOnConfirmPayload(
                $confirmData,
                $txnId,
                $bapUri,
                $bapId
            );
    
            /*
            * Validate message_id.
            *
            * IMPORTANT:
            * on_confirm must use the SAME message_id
            * from the incoming confirm request.
            */
            $msgId = $responsePayload['context']['message_id'] ?? null;
    
            if (!$msgId) {
                Log::error('ON_CONFIRM_MESSAGE_ID_MISSING', [
                    'txn_id' => $txnId,
                ]);
    
                return;
            }
    
            /*
            * Prevent duplicate on_confirm callback.
            */
            if ($this->alreadySentCallback(
                $txnId,
                'confirm',
                $msgId
            )) {
                Log::warning('ON_CONFIRM_DUPLICATE_SEND_PREVENTED', [
                    'txn_id'     => $txnId,
                    'message_id' => $msgId,
                ]);
    
                return;
            }
    
            /*
            * Convert payload to JSON.
            */
            $payloadJson = json_encode(
                $responsePayload,
                JSON_UNESCAPED_SLASHES
            );
    
            Log::info('BUILDING_ON_CONFIRM_PAYLOAD', [
                'txn_id'     => $txnId,
                'bap_uri'    => $bapUri,
                'message_id' => $msgId,
                'action'     => $responsePayload['context']['action'] ?? null,
            ]);
    
            /*
            * Generate ONDC signature.
            */
            $signedHeaders = $this->getSignedOndcHeaders(
                $payloadJson,
                $txnId,
                'confirm'
            );
    
            $headers = array_merge($signedHeaders, [
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ]);
    
            /*
            * Send to internal BPP endpoint.
            *
            * Internal endpoint will use bap_uri
            * to forward the callback to the actual BAP.
            */
            $endpoint = [
                'url' => rtrim(
                    config(
                        'services.ondc.bpp_uri',
                        'https://retail-seller.softtrust.org'
                    ),
                    '/'
                ) . '/on_confirm',
    
                'type' => 'bpp_internal',
    
                'headers' => array_merge($headers, [
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
    
                    'X-Internal-Key' => env(
                        'ONDC_INTERNAL_KEY',
                        'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='
                    ),
    
                    /*
                    * Tell internal BPP where this callback
                    * ultimately needs to go.
                    */
                    'bap_uri' => $bapUri,
                ]),
            ];
    
            Log::info('ON_CONFIRM_TARGET', [
                'url'       => $endpoint['url'],
                'type'      => $endpoint['type'],
                'txn_id'    => $txnId,
                'bap_id'    => $bapId,
                'bap_uri'   => $bapUri,
                'message_id'=> $msgId,
            ]);
    
            /*
            * Send callback.
            */
            $onConfirmSent = $this->sendToEndpoint(
    $endpoint,
    $payloadJson,
    $txnId,
    'confirm',
    $responsePayload,
    $bapUri,
    $bapId
);

if ($onConfirmSent) {
    $this->sendOnStatus(
        $txnId,
        $responsePayload['message']['order']['id'] ?? $txnId,
        'Pending',
        $bapUri,
        $bapId,
        $responsePayload['context']
    );
}
    
            Log::info('SEND_ON_CONFIRM_JOB_FINISHED', [
                'txn_id'     => $txnId,
                'message_id' => $msgId,
            ]);
    
        } catch (\Throwable $e) {
    
            Log::error('SEND_ON_CONFIRM_FAILED', [
                'txn_id'  => $txnId,
                'bap_uri' => $bapUri,
                'bap_id'  => $bapId,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
    
            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action'         => 'on_confirm',
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
            ]);
    
            OndcApiLog::create([
                'type'            => 'confirm_outbound_error',
                'endpoint'        => '/on_confirm',
                'request'         => json_encode(
                    $confirmData,
                    JSON_UNESCAPED_SLASHES
                ),
                'response'        => json_encode([
                    'error' => $e->getMessage(),
                ]),
                'status_code'     => 500,
                'transaction_id'  => $txnId,
            ]);
        }
    }


    /**
     * Build on_confirm payload synchronously.
     * Creates the Bagisto order and returns the ONDC on_confirm response payload.
     */
    protected function buildOnConfirmPayload($confirmData, $txnId, $bapUri, $bapId = null)
    {
        $order       = $confirmData['message']['order'] ?? [];
        $orderItems  = $order['items'] ?? [];
        $quote       = $order['quote'] ?? [];
        $payment     = $order['payment'] ?? [];
        $billing     = $order['billing'] ?? [];
        $fulfillment = ($order['fulfillments'][0] ?? null) ?: [];

        $bagistoOrder = null;

        DB::beginTransaction();

        $itemQtySum = 0;
        foreach ($order['items'] as $item) {
            $itemQtySum += (int) ($item['quantity']['count'] ?? 1);
        }

        try {
            $bagistoOrder = Order::create([
                'increment_id'           => $txnId,
                'channel_id'             => 1,
                'status'                 => 'Pending',
                'customer_id'            => null,
                'customer_type'          => 'Webkul\Customer\Models\Customer',
                'channel_type'           => 'ONDC',
                'channel_name'           => 'ONDC',
                'is_guest'               => true,

                'total_item_count'       => count($order['items'] ?? []),
                'total_qty_ordered'      => $itemQtySum,

                'base_currency_code'     => $order['quote']['price']['currency'] ?? 'INR',
                'channel_currency_code'  => $order['quote']['price']['currency'] ?? 'INR',
                'order_currency_code'    => $order['quote']['price']['currency'] ?? 'INR',
                'grand_total'            => (float) ($order['quote']['price']['value'] ?? 0.0),
                'base_grand_total'       => (float) ($order['quote']['price']['value'] ?? 0.0),
                'sub_total'              => (float) ($order['quote']['price']['value'] ?? 0.0),
                'base_sub_total'         => (float) ($order['quote']['price']['value'] ?? 0.0),

                'tax_amount'             => 0.0,
                'base_tax_amount'        => 0.0,
                'shipping_amount'        => 0.0,
                'base_shipping_amount'   => 0.0,
                'discount_amount'        => 0.0,
                'base_discount_amount'   => 0.0,

                'created_at' => $order['created_at'] ?? OndcHelper::timestamp(),
                'updated_at' => OndcHelper::timestamp(),
            ]);

            $billingAddr = $billing['address'] ?? [];

            OrderAddress::create([
                'order_id'     => $bagistoOrder->id,
                'address_type' => 'billing',
                'first_name'   => $billing['name'] ?? '',
                'email'        => $billing['email'] ?? '',
                'phone'        => $billing['phone'] ?? '',
                'address1'     => $billingAddr['building'] ?? $billingAddr['address'] ?? '',
                'city'         => $billingAddr['city'] ?? '',
                'state'        => $billingAddr['state'] ?? '',
                'country'      => $billingAddr['country'] ?? '',
                'postcode'     => $billingAddr['area_code'] ?? '',
            ]);

            $endLocation = $fulfillment['end']['location'] ?? [];
            $endAddr     = $endLocation['address'] ?? [];
            $personName  = ($fulfillment['end']['person']['name'] ?? '') ?: ($billing['name'] ?? '');

            OrderAddress::create([
                'order_id'     => $bagistoOrder->id,
                'address_type' => 'shipping',
                'first_name'   => $personName,
                'email'        => $fulfillment['end']['contact']['email'] ?? $billing['email'] ?? '',
                'phone'        => $fulfillment['end']['contact']['phone'] ?? $billing['phone'] ?? '',
                'address1'     => $endAddr['address'] ?? $endAddr['building'] ?? '',
                'city'         => $endAddr['city'] ?? '',
                'state'        => $endAddr['state'] ?? '',
                'country'      => $endAddr['country'] ?? '',
                'postcode'     => $endAddr['area_code'] ?? '',
            ]);

            foreach ($orderItems as $item) {
                $rawId = $item['id'] ?? null;

                if (!$rawId) {
                    Log::warning('ONDC_ITEM_ID_MISSING', [
                        'txn_id' => $txnId,
                        'item'   => $item,
                    ]);
                    continue;
                }

                $dbId = (int) preg_replace('/^I/', '', $rawId);
                if (!$dbId) {
                    Log::warning('ONDC_ITEM_ID_INVALID_AFTER_CLEAN', [
                        'txn_id' => $txnId,
                        'raw_id' => $rawId,
                    ]);
                    continue;
                }

                $product = Product::find($dbId);
                if (!$product) {
                    Log::warning('BAGISTO_PRODUCT_NOT_FOUND_BY_ID', [
                        'txn_id'     => $txnId,
                        'ondc_id'    => $rawId,
                        'product_id' => $dbId,
                    ]);
                    continue;
                }

                $qty = (int) ($item['quantity']['count'] ?? 1);

                $itemPriceValue = 0.0;

                if (isset($quote['breakup']) && is_array($quote['breakup'])) {
                    foreach ($quote['breakup'] as $breakup) {
                        if (($breakup['@ondc/org/item_id'] ?? '') === $rawId && ($breakup['@ondc/org/title_type'] ?? '') === 'item') {
                            $itemPriceValue = (float) ($breakup['price']['value'] ?? 0.0);
                            break;
                        }
                    }
                }

                $unitPrice = $itemPriceValue;
                $total     = $unitPrice * $qty;

                OrderItem::create([
                    'order_id'               => $bagistoOrder->id,
                    'product_id'             => $product->id,
                    'product_name'           => $product->name,
                    'product_type'           => $product->type,
                    'sku'                    => $product->sku,
                    'ondc_item_id'           => $rawId,
                    'price'                  => $unitPrice,
                    'base_price'             => $unitPrice,
                    'qty_ordered'            => $qty,
                    'qty_shipped'            => 0,
                    'qty_invoiced'           => 0,
                    'qty_canceled'           => 0,
                    'qty_refunded'           => 0,
                    'total'                  => $total,
                    'base_total'             => $total,
                    'tax_amount'             => 0,
                    'base_tax_amount'        => 0,
                    'total_invoiced'         => 0,
                    'base_total_invoiced'    => 0,
                    'amount_refunded'        => 0,
                    'base_amount_refunded'   => 0,
                    'discount_percent'       => 0,
                    'discount_amount'        => 0,
                    'base_discount_amount'   => 0,
                    'price_incl_tax'         => $unitPrice,
                    'base_price_incl_tax'    => $unitPrice,
                    'total_incl_tax'         => $total,
                    'base_total_incl_tax'    => $total,
                    'created_at' => now(),
                    'updated_at'             => now(),
                ]);
            }

            OrderPayment::create([
                'order_id' => $bagistoOrder->id,
                'method' => $payment['type'] ?? 'ONDC',
                'method_title' => 'ONDC Payment',
                'status' => $payment['status'] ?? 'PAID',
                'amount' => (float) ($payment['params']['amount'] ?? 0.0),
                'base_amount' => (float) ($payment['params']['amount'] ?? 0.0),
                'additional' => [
                    'ondc_transaction_id' => $payment['params']['transaction_id'] ?? null,
                    'bap_id' => $confirmData['context']['bap_id'] ?? null,
                    'collected_by' => $payment['collected_by'] ?? null,
                    'status' => $payment['status'] ?? null,
                    'amount' => $payment['params']['amount'] ?? null,
                    'currency' => $payment['params']['currency'] ?? 'INR',
                    'finder_fee_type' => $payment['@ondc/org/buyer_app_finder_fee_type'] ?? null,
                    'finder_fee_amount' => $payment['@ondc/org/buyer_app_finder_fee_amount'] ?? null,
                    'settlement' => $payment['@ondc/org/settlement_details'][0] ?? null,
                ],
            ]);

            DB::commit();

            Log::info('BAGISTO_ORDER_CREATED', [
                'order_id'     => $bagistoOrder->id,
                'increment_id' => $bagistoOrder->increment_id,
                'txn_id'       => $txnId,
                'grand_total'  => $bagistoOrder->grand_total,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('BAGISTO_ORDER_CREATE_FAILED', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action'         => 'on_confirm',
                'status'         => 'order_failed',
                'error_message'  => $e->getMessage(),
            ]);

            OndcApiLog::create([
                'type'            => 'on_confirm_order_error',
                'endpoint'        => '/ondc/confirm',
                'request'         => json_encode($confirmData, JSON_UNESCAPED_SLASHES),
                'status_code'     => 500,
                'transaction_id'  => $txnId,
            ]);
        }

        $context = [
            'domain'           => $confirmData['context']['domain'] ?? 'ONDC:RET10',
            'country'          => $confirmData['context']['country'] ?? 'IND',
            'city'             => $confirmData['context']['city'] ?? 'std:022',
            'core_version'     => $confirmData['context']['core_version'] ?? '1.2.0',
            'action'           => 'on_confirm',
            'transaction_id'   => $txnId,
            'message_id'       => $confirmData['context']['message_id'],
            'ttl'              => $confirmData['context']['ttl'] ?? 'PT30S',
            'bap_id'           => $bapId ?: $confirmData['context']['bap_id'],
            'bap_uri'          => $bapUri,
            'bpp_id'           => config('services.ondc.bpp_id'),
            'bpp_uri'          => config('services.ondc.bpp_uri'),
            'timestamp'        => OndcHelper::timestamp(),
        ];

        $orderForReply = [
            'id'           => $order['id'] ?? 'O1',
            'state'        => 'Accepted',

            'provider'     => $order['provider'] ?? [],
            'items'        => $this->buildItemsForOnInit($order['items'] ?? []),
            'billing'      => $this->buildBillingForOnInit($billing),
            'fulfillments' => $this->buildFulfillmentsForOnConfirm($order['fulfillments'] ?? []),
            'quote'        => $quote,
            'payment'      => $payment,
            'tags' => $this->sanitizeOrderTags($order['tags'] ?? []),
            'cancellation_terms' => $this->buildCancellationTerms(),

            'created_at' => $order['created_at'] ?? OndcHelper::timestamp(),
            'updated_at'   => OndcHelper::timestamp(),
        ];

        $responsePayload = [
            'context' => $context,
            'message' => [
                'order' => $orderForReply,
            ],
        ];

        if ($bagistoOrder) {
            try {
                OndcConfirmPayload::create([
                    'id'               => (string) Str::uuid(),
                    'transaction_id'   => $txnId,
                    'increment_id'     => $txnId,
                    'context'          => $context,
                    'message_order'    => $orderForReply,
                    'bap_id'           => $context['bap_id'],
                    'bap_uri'          => $bapUri,
                    'domain'           => $context['domain'],
                    'bagisto_order_id' => $bagistoOrder->id,
                ]);

                Log::info('ONDC_ON_CONFIRM_PAYLOAD_SAVED', [
                    'txn_id'   => $txnId,
                    'order_id' => $bagistoOrder->id,
                ]);
            } catch (\Exception $e) {
                Log::error('ONDC_ON_CONFIRM_PAYLOAD_SAVE_FAILED', [
                    'txn_id' => $txnId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('BUILDING_ON_CONFIRM_PAYLOAD', [
            'txn_id'      => $txnId,
            'bap_uri'     => $bapUri,
            'item_count'  => count($orderItems),
            'domain'      => $context['domain'],
            'action'      => 'on_confirm',
        ]);

        return $responsePayload;
    }

    protected function buildFulfillmentsForOnConfirm($fulfillments)
    {
        $storeName = config('ondc.store.name', 'Store 1');
        $storeGps  = env('ONDC_STORE_GPS', '19.129076,72.825803');

        return collect($fulfillments)->map(function ($f) use ($storeName, $storeGps) {
            $withStart = $f;

            $withStart['@ondc/org/provider_name'] = $f['@ondc/org/provider_name'] ?? $storeName;
            $withStart['@ondc/org/TAT']           = $f['@ondc/org/TAT']           ?? 'PT60M';
            $withStart['type']                    = $f['type']                   ?? 'Delivery';
            $withStart['tracking']                = $f['tracking']               ?? true;
            $withStart['id']                      = $f['id']                     ?? 'F1';

            if (!isset($f['start'])) {
                $withStart['start'] = [
                    'location' => [
                        'id'         => 'L1',
                        'descriptor' => [
                            'name' => $storeName,
                        ],
                        'gps'     => $storeGps,
                        'address' => [
                            'name'      => 'Store',
                            'building'  => config('ondc.store.address.building', 'Building'),
                            'city'      => config('ondc.store.address.city', 'Bengaluru'),
                            'state'     => config('ondc.store.address.state', 'Karnataka'),
                            'country'   => 'IND',
                            'area_code' => config('ondc.store.address.area_code', '560001'),
                            'locality'  => config('ondc.store.address.locality', 'Locality'),
                        ],
                    ],
                    'time' => [
                        'range' => [
                            'start' => OndcHelper::timestamp(),
                            'end'   => OndcHelper::timestamp(now()->addMinutes(30)),
                        ],
                    ],
                    'contact' => [
                        'phone'  => config('ondc.store.care_phone', '9594663710'),
                        'email'  => config('ondc.store.care_email', 'nobody@nomail.com'),
                    ],
                ];
            }

            if (isset($withStart['end']['location']['address']) && empty($withStart['end']['location']['address']['name'])) {
                $withStart['end']['location']['address']['name'] = $withStart['end']['person']['name'] ?? 'Buyer';
            }

            $withStart['state'] = $f['state'] ?? [
                'descriptor' => ['code' => 'Pending'],
            ];

            return $withStart;
        })->all();
    }

    /**
     * STATUS API — BAP request
     * s current order status.
     */
   /**
 * STATUS API - BAP requests the latest order status.
 *
 * Flow:
 * /status
 *      ↓
 * ACK immediately
 *      ↓
 * send on_status asynchronously
 *
 * For a solicited on_status:
 * - same transaction_id
 * - SAME message_id as /status request
 */
public function status(Request $request)
{
    $payload = $request->all();

    $context = $payload['context'] ?? [];
    $message = $payload['message'] ?? [];

    $txnId   = $context['transaction_id'] ?? null;
    $msgId   = $context['message_id'] ?? null;
    $orderId = $message['order_id'] ?? null;
    $bapUri  = rtrim($context['bap_uri'] ?? '', '/');
    $bapId   = $context['bap_id'] ?? null;

    Log::info('ONDC_STATUS_RECEIVED', [
        'transaction_id' => $txnId,
        'message_id'     => $msgId,
        'order_id'       => $orderId,
        'bap_id'         => $bapId,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Validate request
    |--------------------------------------------------------------------------
    */

    if (
        empty($context) ||
        empty($txnId) ||
        empty($msgId) ||
        empty($orderId) ||
        empty($bapUri) ||
        empty($bapId)
    ) {
        Log::error('ONDC_STATUS_INVALID_REQUEST', [
            'payload' => $payload,
        ]);

        return response()->json([
            'context' => $context,

            'message' => [
                'ack' => [
                    'status' => 'NACK',
                ],
            ],

            'error' => [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '30000',
                'message' => 'Required status request context or order_id is missing',
            ],
        ], 400);
    }

    /*
    |--------------------------------------------------------------------------
    | Find order
    |--------------------------------------------------------------------------
    */

    $order = DB::table('orders')
        ->where('increment_id', $txnId)
        ->first();

    if (!$order) {
        Log::error('ONDC_STATUS_ORDER_NOT_FOUND', [
            'transaction_id' => $txnId,
            'order_id'       => $orderId,
        ]);

        return response()->json([
            'context' => $context,

            'message' => [
                'ack' => [
                    'status' => 'NACK',
                ],
            ],

            'error' => [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '30018',
                'message' => 'Order not found',
            ],
        ], 404);
    }

    /*
    |--------------------------------------------------------------------------
    | Save inbound /status transaction
    |--------------------------------------------------------------------------
    */

    OndcTransaction::create([
        'transaction_id'  => $txnId,
        'action'          => 'status',
        'domain'          => $context['domain'] ?? 'ONDC:RET10',
        'bap_id'          => $bapId,
        'bap_uri'         => $bapUri,
        'bpp_id'          => config('services.ondc.bpp_id'),
        'status'          => 'received',
        'request_payload' => $payload,
        'created_at'      => now(),
        'updated_at'      => now(),
    ]);

    /*
    |--------------------------------------------------------------------------
    | Determine CURRENT ONDC fulfillment status
    |--------------------------------------------------------------------------
    */

    $statusMap = [
        'Pending'          => 'Pending',
        'Packed'           => 'Packed',
        'Agent-assigned'   => 'Agent-assigned',
        'Order-picked-up'  => 'Order-picked-up',
        'Out-for-delivery' => 'Out-for-delivery',
        'Order-delivered'  => 'Order-delivered',
        'RTO-Delivered'    => 'RTO-Delivered',

        // lowercase fallback
        'pending'          => 'Pending',
        'packed'           => 'Packed',
        'agent_assigned'   => 'Agent-assigned',
        'picked'           => 'Order-picked-up',
        'out_for_delivery' => 'Out-for-delivery',
        'delivered'        => 'Order-delivered',
        'rto_delivered'    => 'RTO-Delivered',
    ];

    $currentStatus = $statusMap[$order->status ?? 'Pending']
        ?? 'Pending';

    Log::info('ONDC_STATUS_CURRENT_ORDER_STATE', [
        'transaction_id' => $txnId,
        'order_id'       => $orderId,
        'db_status'      => $order->status,
        'ondc_status'    => $currentStatus,
    ]);

    /*
    |--------------------------------------------------------------------------
    | ACK /status immediately
    |--------------------------------------------------------------------------
    */

    $ack = [
        'context' => $context,

        'message' => [
            'ack' => [
                'status' => 'ACK',
            ],
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Send on_status AFTER /status ACK
    |--------------------------------------------------------------------------
    |
    | IMPORTANT:
    |
    | This is a SOLICITED on_status.
    |
    | Therefore use the SAME message_id as /status.
    |
    | transaction_id also remains SAME.
    |
    */

    dispatch(function () use (
        $txnId,
        $orderId,
        $currentStatus,
        $bapUri,
        $bapId,
        $context,
        $msgId
    ) {
        try {

            Log::info('ONDC_STATUS_SENDING_ON_STATUS', [
                'transaction_id' => $txnId,
                'order_id'       => $orderId,
                'status'         => $currentStatus,
                'message_id'     => $msgId,
            ]);

            $this->sendOnStatus(
                $txnId,
                $orderId,
                $currentStatus,
                $bapUri,
                $bapId,
                $context,
                $msgId
            );

        } catch (\Throwable $e) {

            Log::error('ONDC_STATUS_ON_STATUS_FAILED', [
                'transaction_id' => $txnId,
                'order_id'       => $orderId,
                'message_id'     => $msgId,
                'error'          => $e->getMessage(),
            ]);
        }

    })->onQueue(env('ONDC_QUEUE', 'ondc'));

    /*
    |--------------------------------------------------------------------------
    | Return ACK
    |--------------------------------------------------------------------------
    */

    return response()->json($ack);
}


    protected function sendOnStatus(
    string $transactionId,
    string $orderId,
    string $statusCode,
    string $bapUri,
    string $bapId,
    array $baseContext,
    ?string $messageId = null
): void {
    try {

        /*
        |--------------------------------------------------------------------------
        | Generate message ID
        |--------------------------------------------------------------------------
        |
        | For unsolicited on_status, generate a NEW message_id.
        |
        | If this on_status is being sent specifically as a response to
        | a /status request, pass the request message_id instead.
        |
        */

        $messageId = $messageId ?: (string) Str::uuid();

        /*
        |--------------------------------------------------------------------------
        | Find confirmed order payload
        |--------------------------------------------------------------------------
        */

        $confirmRow = DB::table('ondc_confirm_payloads')
            ->where('transaction_id', $transactionId)
            ->first();

        if (!$confirmRow) {

            Log::error('ONDC_ON_STATUS_CONFIRM_PAYLOAD_NOT_FOUND', [
                'transaction_id' => $transactionId,
                'order_id'        => $orderId,
                'status'          => $statusCode,
            ]);

            return;
        }

        $messageOrder = json_decode(
            $confirmRow->message_order,
            true
        ) ?? [];

        /*
        |--------------------------------------------------------------------------
        | Find Bagisto order
        |--------------------------------------------------------------------------
        */

        $orderRow = DB::table('orders')
            ->where('increment_id', $transactionId)
            ->orWhere('id', $orderId)
            ->first();

        if (!$orderRow) {

            Log::error('ONDC_ON_STATUS_ORDER_NOT_FOUND', [
                'transaction_id' => $transactionId,
                'order_id'        => $orderId,
                'status'          => $statusCode,
            ]);

            return;
        }

        Log::info('ON_CANCEL_ORDER_FOUND', [
        'transaction_id' => $txnId,
        'ondc_order_id'   => $orderId,
        'bagisto_id'      => $orderRow->id,
        'increment_id'    => $orderRow->increment_id,
    ]);

        /*
        |--------------------------------------------------------------------------
        | Determine ONDC order state
        |--------------------------------------------------------------------------
        */

        $orderState = $statusCode === 'Order-delivered'
            ? 'Completed'
            : 'In-progress';

        /*
        |--------------------------------------------------------------------------
        | Build fulfillment
        |--------------------------------------------------------------------------
        */

       $fulfillments = collect(
    $messageOrder['fulfillments'] ?? []
)->map(function ($fulfillment) use ($statusCode) {

    $fulfillment['state'] = [
        'descriptor' => [
            'code' => $statusCode,
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Tracking becomes active after rider is assigned
    |--------------------------------------------------------------------------
    */

    $trackingEnabled = in_array($statusCode, [
        'Agent-assigned',
        'Order-picked-up',
        'Out-for-delivery',
    ], true);

    /*
    |--------------------------------------------------------------------------
    | Delivered = tracking inactive
    |--------------------------------------------------------------------------
    */

    if ($statusCode === 'Order-delivered') {
        $trackingEnabled = false;
    }

    $fulfillment['tracking'] = $trackingEnabled;

    $fulfillment['updated_at'] = OndcHelper::timestamp();

    $fulfillment['updated_by'] =
        config('services.ondc.bpp_id');

    /*
    |--------------------------------------------------------------------------
    | Tracking configuration
    |--------------------------------------------------------------------------
    */

    if ($trackingEnabled) {

        $fulfillmentId = $fulfillment['id'] ?? 'F1';

        $trackingUrl = rtrim(
            env(
                'ONDC_TRACKING_URL',
                'https://retail-seller.softtrust.org/tracking'
            ),
            '/'
        ) . '/' . $fulfillmentId;

        $tags = $fulfillment['tags'] ?? [];

        /*
         * Remove old tracking configuration.
         */
        $tags = collect($tags)
            ->reject(function ($tag) {
                return ($tag['code'] ?? '') === 'tracking';
            })
            ->values()
            ->all();

        /*
         * Add ONDC tracking configuration.
         */
        $tags[] = [
            'code' => 'tracking',
            'list' => [
                [
                    'code'  => 'gps_enabled',
                    'value' => 'yes',
                ],
                [
                    'code'  => 'url_enabled',
                    'value' => 'yes',
                ],
                [
                    'code'  => 'url',
                    'value' => $trackingUrl,
                ],
            ],
        ];

        $fulfillment['tags'] = $tags;
    }

    return $fulfillment;

})->values()->all();

        /*
        |--------------------------------------------------------------------------
        | Build ONDC context
        |--------------------------------------------------------------------------
        */

        $context = [
            'domain' => $baseContext['domain']
                ?? 'ONDC:RET10',

            'country' => $baseContext['country']
                ?? 'IND',

            'city' => $baseContext['city']
                ?? 'std:011',

            'core_version' => $baseContext['core_version']
                ?? '1.2.0',

            'action' => 'on_status',

            'bap_id' => $bapId,

            'bap_uri' => $bapUri,

            'bpp_id' => config(
                'services.ondc.bpp_id'
            ),

            'bpp_uri' => config(
                'services.ondc.bpp_uri'
            ),

            'transaction_id' => $transactionId,

            'message_id' => $messageId,

            'timestamp' => OndcHelper::timestamp(),

            'ttl' => 'PT30S',
        ];

        /*
        |--------------------------------------------------------------------------
        | Build order
        |--------------------------------------------------------------------------
        */

        $order = [
            'id' => $messageOrder['id'] ?? $orderId,

            'state' => $orderState,

            'provider' => $messageOrder['provider'] ?? [],

            'items' => $messageOrder['items'] ?? [],

            'billing' => $messageOrder['billing'] ?? [],

            'fulfillments' => $fulfillments,

            'quote' => $messageOrder['quote'] ?? [],

            'payment' => $messageOrder['payment'] ?? [],

            'created_at' => $messageOrder['created_at']
                ?? OndcHelper::timestamp(),

            'updated_at' => OndcHelper::timestamp(),
        ];

        /*
        |--------------------------------------------------------------------------
        | Final payload
        |--------------------------------------------------------------------------
        */

        $payload = [
            'context' => $context,

            'message' => [
                'order' => $order,
            ],
        ];

        $payloadJson = json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        );

        /*
        |--------------------------------------------------------------------------
        | Log payload
        |--------------------------------------------------------------------------
        */

        Log::info('ONDC_ON_STATUS_PAYLOAD_BUILT', [
            'transaction_id' => $transactionId,
            'order_id'        => $orderId,
            'status'          => $statusCode,
            'message_id'      => $messageId,
            'payload'         => $payload,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate callback
        |--------------------------------------------------------------------------
        */

        if (
            $this->alreadySentCallback(
                $transactionId,
                'status',
                $messageId
            )
        ) {

            Log::warning(
                'ONDC_ON_STATUS_DUPLICATE_PREVENTED',
                [
                    'transaction_id' => $transactionId,
                    'message_id'     => $messageId,
                    'status'         => $statusCode,
                ]
            );

            return;
        }

        /*
        |--------------------------------------------------------------------------
        | Generate ONDC signature
        |--------------------------------------------------------------------------
        */

        $signedHeaders = $this->getSignedOndcHeaders(
            $payloadJson,
            $transactionId,
            'status'
        );

        $headers = array_merge(
            $signedHeaders,
            [
                'Accept' => 'application/json',

                'Content-Type' => 'application/json',

                'X-Internal-Key' => env(
                    'ONDC_INTERNAL_KEY'
                ),

                'bap_uri' => $bapUri,
            ]
        );

        /*
        |--------------------------------------------------------------------------
        | Internal switch endpoint
        |--------------------------------------------------------------------------
        */

        $url = rtrim(
            config(
                'services.ondc.bpp_uri',
                'https://retail-seller.softtrust.org'
            ),
            '/'
        ) . '/on_status';

        $endpoint = [
            'url' => $url,

            'type' => 'bpp_internal',

            'headers' => $headers,
        ];

        Log::info('ONDC_ON_STATUS_TARGET', [
            'url'             => $url,
            'transaction_id'  => $transactionId,
            'order_id'        => $orderId,
            'status'          => $statusCode,
            'message_id'      => $messageId,
            'bap_uri'         => $bapUri,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Send through internal switch
        |--------------------------------------------------------------------------
        */

        $sent = $this->sendToEndpoint(
    $endpoint,
    $payloadJson,
    $transactionId,
    'status',
    $payload,
    $bapUri,
    $bapId
);

if ($sent) {
    Log::info('ONDC_ON_STATUS_SENT', [
        'transaction_id' => $transactionId,
        'order_id'       => $orderId,
        'status'         => $statusCode,
        'message_id'     => $messageId,
    ]);
} else {
    Log::error('ONDC_ON_STATUS_NOT_SENT', [
        'transaction_id' => $transactionId,
        'order_id'       => $orderId,
        'status'         => $statusCode,
        'message_id'     => $messageId,
    ]);
}

        Log::info('ONDC_ON_STATUS_SENT', [
            'transaction_id' => $transactionId,
            'order_id'        => $orderId,
            'status'          => $statusCode,
            'message_id'      => $messageId,
        ]);

    } catch (\Throwable $e) {

        Log::error('ONDC_ON_STATUS_FAILED', [
            'transaction_id' => $transactionId,
            'order_id'        => $orderId,
            'status'          => $statusCode,
            'message_id'      => $messageId ?? null,
            'error'           => $e->getMessage(),
            'file'            => $e->getFile(),
            'line'            => $e->getLine(),
        ]);
    }
}


protected function resolveOndcStatus(?string $bagistoStatus): ?string
{
    if (!$bagistoStatus) {
        return null;
    }

    return match (strtolower(trim($bagistoStatus))) {

        'pending' =>
            'Pending',

        'packed' =>
            'Packed',

        'agent-assigned',
        'agent_assigned',
        'agent assigned' =>
            'Agent-assigned',

        'order-picked-up',
        'order_picked_up',
        'picked',
        'picked-up',
        'picked_up' =>
            'Order-picked-up',

        'out-for-delivery',
        'out_for_delivery',
        'out for delivery' =>
            'Out-for-delivery',

        'order-delivered',
        'order_delivered',
        'delivered' =>
            'Order-delivered',

        'cancelled',
        'canceled' =>
            'Cancelled',

        default =>
            null,
    };
}


protected function triggerOndcStatusUpdate(
    string $transactionId,
    string $orderId,
    string $bagistoStatus
): void {
    try {

        $statusCode = $this->resolveOndcStatus(
            $bagistoStatus
        );

        if (!$statusCode) {
            return;
        }

        $transaction = OndcTransaction::where(
            'transaction_id',
            $transactionId
        )
        ->latest()
        ->first();

        if (!$transaction) {

            Log::error('ONDC_TRANSACTION_NOT_FOUND_FOR_STATUS', [
                'transaction_id' => $transactionId,
                'order_id'        => $orderId,
                'status'          => $statusCode,
            ]);

            return;
        }

        $bapUri = rtrim(
            $transaction->bap_uri ?? '',
            '/'
        );

        $bapId = $transaction->bap_id;

        if (!$bapUri || !$bapId) {

            Log::error('ONDC_STATUS_BAP_DETAILS_MISSING', [
                'transaction_id' => $transactionId,
                'bap_uri'         => $bapUri,
                'bap_id'          => $bapId,
            ]);

            return;
        }

        $baseContext = [
            'domain'       => $transaction->domain
                ?? 'ONDC:RET10',

            'country'      => 'IND',

            'city'         => 'std:011',

            'core_version' => '1.2.0',

            'bap_id'       => $bapId,

            'bap_uri'      => $bapUri,

            'transaction_id' => $transactionId,
        ];

        $alreadySent = OndcTransaction::where('transaction_id', $transactionId)
            ->where('action', 'on_status')
            ->where('request_payload->message->order->fulfillments.0.state.descriptor.code', $statusCode)
            ->whereIn('status', ['ack_received', 'sent'])
            ->exists();

        if ($alreadySent) {
            Log::warning('ONDC_STATUS_DUPLICATE_PREVENTED', [
                'transaction_id' => $transactionId,
                'order_id'       => $orderId,
                'status'         => $statusCode,
            ]);

            return;
        }

        dispatch(function () use (
            $transactionId,
            $orderId,
            $statusCode,
            $bapUri,
            $bapId,
            $baseContext
        ) {

            $this->sendOnStatus(
                $transactionId,
                $orderId,
                $statusCode,
                $bapUri,
                $bapId,
                $baseContext
            );

        })->onQueue(
            env('ONDC_QUEUE', 'ondc')
        );

        Log::info('ONDC_ON_STATUS_QUEUED', [
            'transaction_id' => $transactionId,
            'order_id'        => $orderId,
            'status'          => $statusCode,
        ]);

    } catch (\Throwable $e) {

        Log::error('ONDC_ON_STATUS_TRIGGER_FAILED', [
            'transaction_id' => $transactionId,
            'order_id'        => $orderId,
            'bagisto_status'  => $bagistoStatus,
            'error'           => $e->getMessage(),
        ]);
    }
}


//For testing purpose workbench
public function testOnStatus($order_id, $status)
{
    $order = DB::table('orders')
        ->where('id', $order_id)
        ->first();

    if (!$order) {
        return response()->json([
            'success' => false,
            'message' => 'Order not found',
        ], 404);
    }

    $allowedStatuses = [
        'Pending',
        'Packed',
        'Agent-assigned',
        'Order-picked-up',
        'Out-for-delivery',
        'Order-delivered',
    ];

    if (!in_array($status, $allowedStatuses, true)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid ONDC status',
            'allowed' => $allowedStatuses,
        ], 400);
    }

    $ondcPayload = DB::table('ondc_confirm_payloads')
        ->where('transaction_id', $order->increment_id)
        ->first();

    if (!$ondcPayload) {
        return response()->json([
            'success' => false,
            'message' => 'ONDC confirm payload not found',
            'transaction_id' => $order->increment_id,
        ], 404);
    }

    $messageOrder = json_decode(
        $ondcPayload->message_order,
        true
    ) ?? [];

    $context = json_decode(
        $ondcPayload->context,
        true
    ) ?? [];

    $bapUri = rtrim(
        $ondcPayload->bap_uri ?? '',
        '/'
    );

    $bapId = $ondcPayload->bap_id ?? null;

    /*
     * Directly call the existing on_status sender
     * for testing only.
     */
 $this->sendOnStatus(
    $ondcPayload->transaction_id,
    $messageOrder['id'] ?? $order_id,
    $status,
    $bapUri,
    $bapId,
    $context,
    null
);

    return response()->json([
        'success' => true,
        'message' => 'on_status sent',
        'transaction_id' => $ondcPayload->transaction_id,
        'order_id' => $messageOrder['id'] ?? null,
        'status' => $status,
    ]);
}


    /**
     * TRACK API - BAP requests order tracking
     */
    public function track(Request $request)
    {
        $trackData = $request->all();

        Log::info('ONDC_TRACK_RECEIVED', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload'    => $trackData,
        ]);

        if (!isset($trackData['context']) || !isset($trackData['message'])) {
            return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
        }

        $ctxt   = $trackData['context'];
        $txnId  = $ctxt['transaction_id'] ?? Str::uuid()->toString();
        $msgId  = $ctxt['message_id'] ?? Str::uuid()->toString();
        $bapUri = rtrim($ctxt['bap_uri'] ?? '', '/');
        $bapId  = $ctxt['bap_id'] ?? null;
        $orderId = $trackData['message']['order_id'] ?? null;

        if (!$bapUri) {
            return response()->json(['error' => 'bap_uri missing in context'], 400);
        }


        $duplicateTrack = OndcTransaction::where('transaction_id', $txnId)
            ->where('action', 'track')
            ->where('request_payload->context->message_id', $msgId)
            ->exists();

        if ($duplicateTrack) {
            Log::warning('ONDC_TRACK_DUPLICATE_REQUEST', [
                'transaction_id' => $txnId,
                'message_id'     => $msgId,
                'order_id'       => $orderId,
            ]);

            return response()->json([
                'context' => $ctxt,
                'message' => [
                    'ack' => [
                        'status' => 'ACK',
                    ],
                ],
            ]);
        }


        OndcTransaction::create([
            'transaction_id'  => $txnId,
            'action'          => 'track',
            'domain'          => $ctxt['domain'] ?? 'ONDC:RET10',
            'bap_id'          => $bapId,
            'bap_uri'         => $bapUri,
            'bpp_id'          => config('services.ondc.bpp_id'),
            'status'          => 'received',
            'request_payload' => $trackData,
        ]);

        OndcApiLog::create([
            'type'           => 'track_inbound',
            'endpoint'       => '/ondc/track',
            'request'        => json_encode($trackData),
            'status_code'    => 200,
            'transaction_id' => $txnId,
        ]);


        // Process on_track asynchronously after ACK is returned.
        dispatch(function () use (
            $trackData,
            $txnId,
            $msgId,
            $bapUri,
            $bapId,
            $orderId
        ) {
            try {
                $this->sendOnTrack(
                    $trackData,
                    $txnId,
                    $msgId,
                    $bapUri,
                    $bapId,
                    $orderId
                );
            } catch (\Throwable $e) {
                Log::error('ON_TRACK_ASYNC_FAILED', [
                    'transaction_id' => $txnId,
                    'message_id'     => $msgId,
                    'order_id'       => $orderId,
                    'error'          => $e->getMessage(),
                ]);
            }
        })->onQueue(env('ONDC_QUEUE', 'ondc'));

        return response()->json([
            'context' => $ctxt,
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ]);
    }

    /**
     * Build and send on_track response to BAP
     */
    protected function sendOnTrack($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId)
    {
        Log::info('SEND_ON_TRACK_JOB_STARTED', [
            'txn_id'   => $txnId,
            'bap_uri'  => $bapUri,
            'order_id' => $orderId,
        ]);

        if ($this->alreadySentCallback($txnId, 'track', $msgId)) {
            Log::warning('ON_TRACK_DUPLICATE_SEND_PREVENTED', [
                'txn_id'     => $txnId,
                'message_id' => $msgId,
            ]);
            return;
        }

        $ctxt = $trackData['context'];

    

        $trackingGps = env('ONDC_STORE_GPS', '19.129076,72.825803');
        $trackingUrl = env('ONDC_TRACKING_URL', rtrim(config('services.ondc.bpp_uri'), '/') . '/tracking/' . $orderId);

        $ondcPayload = \App\Models\OndcConfirmPayload::where('transaction_id', $txnId)->first();
        $messageOrder = [];
        if ($ondcPayload) {
            $messageOrder = is_array($ondcPayload->message_order)
                ? $ondcPayload->message_order
                : json_decode($ondcPayload->message_order, true);

            $fulfillments = $messageOrder['fulfillments'] ?? [];
            if (!empty($fulfillments[0]['start']['location']['gps'])) {
                $trackingGps = $fulfillments[0]['start']['location']['gps'];
            }
        }
        $fulfillmentId = $messageOrder['fulfillments'][0]['id'] ?? 'F1';
        $outboundTimestamp = OndcHelper::timestamp();
        
        $responsePayload = [
            'context' => [
                'domain'         => $ctxt['domain']       ?? 'ONDC:RET10',
                'country'        => $ctxt['country']      ?? 'IND',
                'city'           => $ctxt['city']         ?? 'std:080',
                'core_version'   => $ctxt['core_version'] ?? '1.2.0',
                'action'         => 'on_track',
                'transaction_id' => $txnId,
                'message_id'     => $msgId,
                'ttl'            => $ctxt['ttl'] ?? 'PT30S',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'timestamp'      => $outboundTimestamp,
            ],
            'message' => [
                'tracking' => [
                    'id'       => (string) $fulfillmentId,
                    'url'      => (string) $trackingUrl,
                    'status'   => 'active',
                    'location' => [
                        'gps'        => (string) $trackingGps,
                        'time'       => [
                            'timestamp' => $outboundTimestamp,
                        ],
                         'updated_at' => $outboundTimestamp,
                    ],
                ],
            ],
        ];

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_TRACK_PAYLOAD', [
            'txn_id'      => $txnId,
            'bap_uri'     => $bapUri,
            'order_id'    => $orderId,
            'tracking_gps' => $trackingGps,
        ]);


        $signedHeaders = $this->getSignedOndcHeaders(
            $payloadJson,
            $txnId,
            'track'
        );

        $headers = array_merge($signedHeaders, [
            'Accept'       => 'application/json',
            'Content-Type' => 'application/json',
        ]);


        $endpoints = [
            [
                'url' => rtrim(
                    config(
                        'services.ondc.bpp_uri',
                        'https://retail-seller.softtrust.org'
                    ),
                    '/'
                ) . '/on_track',

                'type' => 'bpp_internal',

                'headers' => array_merge($headers, [
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY'),
                    'bap_uri'        => $bapUri,
                ]),
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'track', $responsePayload, $bapUri, $bapId);
        }
    }

    /**
     * CANCEL API - BAP requests cancellation of an order
     */
   /**
 * CANCEL API - BAP requests cancellation of an order
 */



  

    /**
     * Callback Handler - Routes on_* actions
     */
    public function callback(Request $request)
    {
        $payload = $request->all();
        $txnId = $payload['context']['transaction_id'] ?? null;
        $action = $payload['context']['action'] ?? null;

        Log::info('ONDC_CALLBACK_RECEIVED', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'txn_id' => $txnId,
            'action' => $action,
            'payload' => $payload,
        ]);

        if (!$txnId) {
            return response()->json([
                'error' => 'transaction_id missing in context',
            ], 400);
        }

        $originalAction = str_replace('on_', '', $action);

        $transaction = OndcTransaction::where('transaction_id', $txnId)
            ->where('action', $originalAction)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$transaction) {
            $transaction = OndcTransaction::where('transaction_id', $txnId)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($transaction) {
            $transaction->update([
                'callback_received_at' => now(),
                'response_payload' => array_merge($transaction->response_payload ?? [], $payload),
                'status' => 'completed',
            ]);

            Log::info('CALLBACK_TRANSACTION_UPDATED', [
                'txn_id' => $txnId,
                'action' => $transaction->action,
                'status' => 'completed',
            ]);
        } else {
            Log::warning('CALLBACK_TRANSACTION_NOT_FOUND', [
                'txn_id' => $txnId,
                'action' => $action,
            ]);
        }

        OndcApiLog::create([
            'type' => $action . '_callback',
            'endpoint' => '/ondc/callback',
            'request' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'response' => json_encode(['status' => 'ACK']),
            'status_code' => 200,
            'transaction_id' => $txnId,
        ]);

        return response()->json([
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ]);
    }

/**
 * Process buyer cancellation asynchronously
 * and send on_cancel through the internal BPP endpoint.
 */



    public function onSearch($payload)
    {
        return $this->ack();
    }
    public function onSelect($payload)
    {
        return $this->ack();
    }
    public function onInit($payload)
    {
        return $this->ack();
    }
    public function onConfirm($payload)
    {
        return $this->ack();
    }
    public function onStatus($payload)
    {
        return $this->ack();
    }
    public function onCancel($payload)
    {
        return $this->ack();
    }
    public function onUpdate($payload)
    {
        return $this->ack();
    }

    protected function ack()
    {
        return response()->json([
            'message' => [
                'ack' => ['status' => 'ACK'],
            ],
        ], 200);
    }
}