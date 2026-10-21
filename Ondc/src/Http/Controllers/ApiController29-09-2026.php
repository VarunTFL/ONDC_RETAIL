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
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Models\OrderPayment;
use Webkul\Ondc\Services\DomainResolver;
use Webkul\Ondc\Services\CategoryMapper;
use Webkul\Ondc\Services\Fnb\FnbDiscoveryService;
use Webkul\Ondc\Models\OndcFnbOrder;
use Webkul\Ondc\Models\OndcFnbItem;
use Webkul\Ondc\Services\Fnb\FnbDeliveryService;

class ApiController extends Controller
{
    protected DomainResolver $domainResolver;
    protected CategoryMapper $categoryMapper;
    protected FnbDiscoveryService $fnbDiscovery;
    protected FnbDeliveryService $fnbDelivery;

    public function __construct(
        DomainResolver $domainResolver,
        CategoryMapper $categoryMapper,
        FnbDiscoveryService $fnbDiscovery,
        FnbDeliveryService $fnbDelivery
    ) {
        $this->domainResolver = $domainResolver;
        $this->categoryMapper = $categoryMapper;
        $this->fnbDiscovery = $fnbDiscovery;
        $this->fnbDelivery = $fnbDelivery;
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

    public function index()
    {
        $keypair = sodium_crypto_sign_keypair();
        $secretKey = sodium_crypto_sign_secretkey($keypair);
        $publicKey = sodium_crypto_sign_publickey($keypair);

        $keys = [
            'private' => base64_encode($secretKey),
            'public'  => base64_encode($publicKey),
        ];

        return view('ondc::admin.api.index', compact('keys'));
    }

    protected function sendAckAndCloseConnection(array $context, string $status = 'ACK'): void
    {
        $ackPayload = json_encode([
            'context' => $context,
            'message' => [
                'ack' => ['status' => $status],
            ],
        ]);

        ignore_user_abort(true);
        set_time_limit(120);

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        header('Content-Type: application/json');
        header('Connection: close');
        header('Content-Length: ' . strlen($ackPayload));

        echo $ackPayload;
        flush();

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
    }

    // ──────────────────────────────────────────────────────────────
    // SEARCH
    // ──────────────────────────────────────────────────────────────

    public function search(Request $request)
    {
        $searchData = $request->all();
        $bapId = $searchData['context']['bap_id'] ?? '';

        if (str_contains($bapId, 'gcr.ondc.org')) {
            return response()->json(['message' => ['ack' => ['status' => 'ACK']]]);
        }

        if (!isset($searchData['context'])) {
            return response()->json(['error' => 'Invalid payload: context missing'], 400);
        }

        $context = $searchData['context'];
        $txnId   = $context['transaction_id'] ?? null;
        $msgId   = $context['message_id'] ?? null;

        if (!$txnId) {
            return response()->json(['error' => 'transaction_id missing in context'], 400);
        }

        $bapUri = rtrim($context['bap_uri'] ?? '', '/');
        $bapId  = $context['bap_id'] ?? null;

        if (!$bapUri) {
            return response()->json(['error' => 'bap_uri missing in context'], 400);
        }

        // 1. Immediately return HTTP ACK to release socket
        $this->sendAckAndCloseConnection($context);

        // 2. Prevent concurrent processing of the exact same message_id
        $lockKey = "ondc:lock:search:{$txnId}:{$msgId}";
        if (!Cache::add($lockKey, true, 30)) {
            exit;
        }

        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri'          => $bapUri,
            'bap_id'           => $bapId,
            'original_request' => $searchData,
        ], now()->addMinutes(30));

        OndcTransaction::create([
            'transaction_id'  => $txnId,
            'action'          => 'search',
            'domain'          => $context['domain'] ?? 'ONDC:RET10',
            'bap_id'          => $bapId,
            'bap_uri'         => $bapUri,
            'request_payload' => $searchData,
            'status'          => 'received',
        ]);

        // 3. Detect incremental search parameters
        $intentTags = data_get($searchData, 'message.intent.tags', []);
        $incMode    = null;
        $isPull     = false;

        foreach ($intentTags as $tag) {
            if (($tag['code'] ?? '') === 'catalog_inc') {
                foreach ($tag['list'] ?? [] as $item) {
                    $code = $item['code'] ?? '';
                    if ($code === 'mode') {
                        $incMode = $item['value'] ?? null;
                    }
                    if ($code === 'start_time' || $code === 'end_time') {
                        $isPull = true;
                    }
                }
            }
        }

        // Handle Mode: Stop (End of push)
        if ($incMode === 'stop') {
            Log::info('[ONDC][SEARCH] catalog_inc stop received. Exiting.', ['txn_id' => $txnId]);
            exit;
        }

        try {
            // Case A: Incremental PULL (Card 3 -> Card 4)
            if ($isPull) {
                Log::info('[ONDC][SEARCH] Incremental pull search detected (Card 3). Sending incremental on_search (Card 4)...', [
                    'txn_id'     => $txnId,
                    'message_id' => $msgId,
                ]);
                $this->sendIncrementalItemPush($txnId, $bapUri, $bapId, $context);
            }
            // Case B: Incremental PUSH (Push flow: Cards 2, 3, 4)
            elseif ($incMode === 'start') {
                Log::info('[ONDC][SEARCH] Incremental push start detected. Dispatching Cards 2, 3, and 4...', [
                    'txn_id' => $txnId,
                ]);
                $this->sendIncrementalItemPush($txnId, $bapUri, $bapId, $context);
                usleep(250000);
                $this->sendIncrementalFulfillmentPush($txnId, $bapUri, $bapId, $context);
                usleep(250000);
                $this->sendIncrementalCustomMenuPush($txnId, $bapUri, $bapId, $context);
            }
            // Case C: Standard Full Catalog Search (Card 1 -> Card 2)
            else {
                Log::info('[ONDC][SEARCH] Full catalog search (Card 1). Sending on_search (Card 2)...', [
                    'txn_id'     => $txnId,
                    'message_id' => $msgId,
                ]);
                $this->sendOnSearch($searchData, $txnId, $bapUri, $bapId);
            }
        } catch (\Throwable $e) {
            Log::error('SEND_ON_SEARCH_EXCEPTION', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }

        exit;
    }

    protected function sendOnSearch($searchData, $txnId, $bapUri, $bapId)
    {
        $context = $searchData['context'];
        $messageId = $context['message_id'] ?? null;

        if ($this->alreadySentCallback($txnId, 'search', $messageId)) {
            return;
        }

        $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        if ($requestedDomain === FnbDiscoveryService::DOMAIN) {
            $payload = $this->fnbDiscovery->processSearchAndBuildOnSearch($searchData);
            $payload['context']['bpp_id'] = config('services.ondc.bpp_id');
            $payload['context']['bpp_uri'] = config('services.ondc.bpp_uri');
        } else {
            $payload = $this->buildPayload($context, $bapUri, $bapId);
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        $endpoint = [
            'url'     => rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/') . '/on_search',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
            ],
        ];

        $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'search', $payload, $bapUri, $bapId);
    }

    // INCREMENTAL SEARCH
    public function sendIncrementalOnSearch(
        string $txnId,
        string $bapUri,
        ?string $bapId,
        array $baseContext,
        ?string $specificItemId = null
    ): void {
        try {
            $inboundTimestamp  = $baseContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
            $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);
            $outboundMessageId = (string) Str::uuid();

            // Fetch active catalog products
            $requestedDomain = $baseContext['domain'] ?? 'ONDC:RET10';
            $products = $this->getAllActiveProducts($requestedDomain);

            if (empty($products)) {
                Log::error('[ONDC][INC_SEARCH] No active products found to build incremental catalog');
                return;
            }

            // Target either the requested item or the first product in catalog
            $targetProduct = $products[0];
            if ($specificItemId) {
                foreach ($products as $p) {
                    if ($p['id'] === $specificItemId) {
                        $targetProduct = $p;
                        break;
                    }
                }
            }

            $domain = $this->domainResolver->resolve($targetProduct['category_name'] ?? '') ?: 'ONDC:RET10';
            $ondcCategory = $this->categoryMapper->map(
                $domain,
                $targetProduct['name'] ?? '',
                $targetProduct['category_name'] ?? '',
                null,
                $targetProduct['description'] ?? '',
                $targetProduct['meta_keywords'] ?? ''
            ) ?: 'Atta, Flours and Sooji';

            // Build single incremental item
            $incrementalItem = [
                'id'         => (string) $targetProduct['id'],
                'descriptor' => [
                    'name'       => (string) $targetProduct['name'],
                    'code'       => !empty($targetProduct['sku']) ? ('1:' . $targetProduct['sku']) : '1:SKU001',
                    'symbol'     => (string) ($targetProduct['image'] ?? ''),
                    'short_desc' => (string) ($targetProduct['short_description'] ?? $targetProduct['name']),
                    'long_desc'  => (string) ($targetProduct['description'] ?? $targetProduct['name']),
                    'images'     => $targetProduct['images'] ?? [($targetProduct['image'] ?? '')],
                ],
                'price' => [
                    'currency'      => 'INR',
                    'value'         => number_format((float) $targetProduct['price'], 2, '.', ''),
                    'maximum_value' => number_format((float) $targetProduct['price'], 2, '.', ''),
                ],
                'quantity' => [
                    'unitized' => [
                        'measure' => [
                            'unit'  => (string) ($targetProduct['unit'] ?? 'kilogram'),
                            'value' => (string) ($targetProduct['unit_value'] ?? '1'),
                        ],
                    ],
                    'available' => ['count' => (string) ($targetProduct['stock'] ?? '99')],
                    'maximum'   => ['count' => (string) ($targetProduct['stock'] ?? '99')],
                ],
                'category_id'                             => $ondcCategory,
                'fulfillment_id'                          => 'F1',
                'location_id'                             => 'L1',
                '@ondc/org/returnable'                    => $targetProduct['returnable'] ?? true,
                '@ondc/org/cancellable'                   => $targetProduct['cancellable'] ?? true,
                '@ondc/org/return_window'                 => 'P7D',
                '@ondc/org/seller_pickup_return'          => false,
                '@ondc/org/time_to_ship'                  => 'PT24H',
                '@ondc/org/available_on_cod'              => false,
                '@ondc/org/contact_details_consumer_care' => $this->getConsumerCareString(),
                '@ondc/org/statutory_reqs_packaged_commodities' => [
                    'manufacturer_or_packer_name'             => config('ondc.store.name', 'Store 1'),
                    'manufacturer_or_packer_address'          => config('ondc.store.address.locality', 'Store Address'),
                    'common_or_generic_name_of_commodity'     => (string) $targetProduct['name'],
                    'month_year_of_manufacture_packing_import' => date('m/Y'),
                ],
                'time' => [
                    'label'     => 'enable',
                    'timestamp' => $outboundTimestamp,
                ],
                'tags' => [
                    [
                        'code' => 'origin',
                        'list' => [
                            ['code' => 'country', 'value' => 'IND'],
                        ],
                    ],
                ],
            ];

            $context = [
                'domain'         => $baseContext['domain'] ?? 'ONDC:RET10',
                'country'        => $baseContext['country'] ?? 'IND',
                'city'           => $baseContext['city'] ?? '*',
                'core_version'   => $baseContext['core_version'] ?? '1.2.0',
                'action'         => 'on_search',
                'bap_id'         => $bapId ?: ($baseContext['bap_id'] ?? ''),
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'transaction_id' => $txnId,
                'message_id'     => $outboundMessageId,
                'timestamp'      => $outboundTimestamp,
                'ttl'            => 'PT30S',
            ];

            $payload = [
                'context' => $context,
                'message' => [
                    'catalog' => [
                        'bpp/providers' => [
                            [
                                'id'    => 'P1',
                                'items' => [$incrementalItem],
                            ],
                        ],
                    ],
                ],
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $bppUri      = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

            $endpoint = [
                'url'     => $bppUri . '/on_search',
                'type'    => 'bpp_internal',
                'headers' => [
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'        => $bapUri,
                    'bpp_uri'        => $bppUri,
                ],
            ];

            Log::info('[ONDC][INC_SEARCH] Dispatching incremental on_search push', [
                'txn_id'     => $txnId,
                'message_id' => $outboundMessageId,
                'item_id'    => $targetProduct['id'],
            ]);

            $this->sendToEndpoint(
                $endpoint,
                $payloadJson,
                $txnId,
                'search',
                $payload,
                $bapUri,
                $bapId
            );
        } catch (\Throwable $e) {
            Log::error('[ONDC][INC_SEARCH] Failed to dispatch incremental on_search', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // Card 2: Incremental Item Push
    public function sendIncrementalItemPush(string $txnId, string $bapUri, ?string $bapId, array $baseContext): void
    {
        $this->dispatchIncrementalPush($txnId, $bapUri, $bapId, $baseContext, 1, 'item');
    }

    // Card 3: Filtered Items + Fulfillment Options
    public function sendIncrementalFulfillmentPush(string $txnId, string $bapUri, ?string $bapId, array $baseContext): void
    {
        $this->dispatchIncrementalPush($txnId, $bapUri, $bapId, $baseContext, 2, 'fulfillment');
    }

    // Card 4: Custom Menu / Category Push
    public function sendIncrementalCustomMenuPush(string $txnId, string $bapUri, ?string $bapId, array $baseContext): void
    {
        $this->dispatchIncrementalPush($txnId, $bapUri, $bapId, $baseContext, 3, 'custom_menu');
    }

    protected function dispatchIncrementalPush(
        string $txnId,
        string $bapUri,
        ?string $bapId,
        array $baseContext,
        int $stepOffset,
        string $pushType
    ): void {
        try {
            $inboundTimestamp  = $baseContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
            $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + $stepOffset);
            $outboundMessageId = (string) Str::uuid();

            $products = $this->getAllActiveProducts($baseContext['domain'] ?? 'ONDC:RET10');
            if (empty($products)) {
                return;
            }

            $targetProduct = $products[0];
            $domain = $this->domainResolver->resolve($targetProduct['category_name'] ?? '') ?: 'ONDC:RET10';
            $ondcCategory = $this->categoryMapper->map(
                $domain,
                $targetProduct['name'] ?? '',
                $targetProduct['category_name'] ?? '',
                null,
                $targetProduct['description'] ?? '',
                $targetProduct['meta_keywords'] ?? ''
            ) ?: 'Atta, Flours and Sooji';

            $item = [
                'id'         => (string) $targetProduct['id'],
                'descriptor' => [
                    'name'       => (string) $targetProduct['name'],
                    'code'       => !empty($targetProduct['sku']) ? ('1:' . $targetProduct['sku']) : '1:SKU001',
                    'symbol'     => (string) ($targetProduct['image'] ?? ''),
                    'short_desc' => (string) ($targetProduct['short_description'] ?? $targetProduct['name']),
                    'long_desc'  => (string) ($targetProduct['description'] ?? $targetProduct['name']),
                    'images'     => $targetProduct['images'] ?? [($targetProduct['image'] ?? '')],
                ],
                'price' => [
                    'currency'      => 'INR',
                    'value'         => number_format((float) $targetProduct['price'], 2, '.', ''),
                    'maximum_value' => number_format((float) $targetProduct['price'], 2, '.', ''),
                ],
                'quantity' => [
                    'unitized' => [
                        'measure' => [
                            'unit'  => (string) ($targetProduct['unit'] ?? 'kilogram'),
                            'value' => (string) ($targetProduct['unit_value'] ?? '1'),
                        ],
                    ],
                    'available' => ['count' => (string) ($targetProduct['stock'] ?? '99')],
                    'maximum'   => ['count' => (string) ($targetProduct['stock'] ?? '99')],
                ],
                'category_id'                             => $ondcCategory,
                'fulfillment_id'                          => 'F1',
                'location_id'                             => 'L1',
                '@ondc/org/returnable'                    => $targetProduct['returnable'] ?? true,
                '@ondc/org/cancellable'                   => $targetProduct['cancellable'] ?? true,
                '@ondc/org/return_window'                 => 'P7D',
                '@ondc/org/seller_pickup_return'          => false,
                '@ondc/org/time_to_ship'                  => 'PT24H',
                '@ondc/org/available_on_cod'              => false,
                '@ondc/org/contact_details_consumer_care' => $this->getConsumerCareString(),
                '@ondc/org/statutory_reqs_packaged_commodities' => [
                    'manufacturer_or_packer_name'             => config('ondc.store.name', 'Store 1'),
                    'manufacturer_or_packer_address'          => config('ondc.store.address.locality', 'Store Address'),
                    'common_or_generic_name_of_commodity'     => (string) $targetProduct['name'],
                    'month_year_of_manufacture_packing_import' => date('m/Y'),
                ],
                'time' => [
                    'label'     => 'enable',
                    'timestamp' => $outboundTimestamp,
                ],
                'tags' => [
                    [
                        'code' => 'origin',
                        'list' => [
                            ['code' => 'country', 'value' => 'IND'],
                        ],
                    ],
                ],
            ];

            $providerData = [
                'id'    => 'P1',
                'items' => [$item],
            ];

            // Card 3 requirement: Fulfillment options included
            if ($pushType === 'fulfillment') {
                $providerData['fulfillments'] = [
                    [
                        'id'   => 'F1',
                        'type' => 'Delivery',
                    ],
                ];
            }

            // Card 4 requirement: Custom Menu / Category definitions included
            if ($pushType === 'custom_menu') {
                $providerData['categories'] = [
                    [
                        'id'         => self::ONDC_RET10_CATEGORY_MAP[$ondcCategory] ?? 'CAT027',
                        'descriptor' => ['name' => $ondcCategory],
                        'tags'       => [
                            [
                                'code' => 'type',
                                'list' => [
                                    ['code' => 'type', 'value' => 'custom_menu'],
                                ],
                            ],
                        ],
                    ],
                ];
            }

            $context = [
                'domain'         => $baseContext['domain'] ?? 'ONDC:RET10',
                'country'        => $baseContext['country'] ?? 'IND',
                'city'           => $baseContext['city'] ?? '*',
                'core_version'   => $baseContext['core_version'] ?? '1.2.0',
                'action'         => 'on_search',
                'bap_id'         => $bapId ?: ($baseContext['bap_id'] ?? ''),
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'transaction_id' => $txnId,
                'message_id'     => $outboundMessageId,
                'timestamp'      => $outboundTimestamp,
                'ttl'            => 'PT30S',
            ];

            $payload = [
                'context' => $context,
                'message' => [
                    'catalog' => [
                        'bpp/providers' => [$providerData],
                    ],
                ],
            ];

            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $bppUriActual = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

            $endpoint = [
                'url'     => $bppUriActual . '/on_search',
                'type'    => 'bpp_internal',
                'headers' => [
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'        => $bapUri,
                    'bpp_uri'        => $bppUriActual,
                ],
            ];

            Log::info("[ONDC][INC_SEARCH] Pushing {$pushType} (Step offset: +{$stepOffset}s)", [
                'txn_id'     => $txnId,
                'message_id' => $outboundMessageId,
            ]);

            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'search', $payload, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error("[ONDC][INC_SEARCH] Failed pushing {$pushType}", [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // SELECT
    // ──────────────────────────────────────────────────────────────

    public function select(Request $request)
    {
        $selectData = $request->all();

        if (!isset($selectData['context']) || !isset($selectData['message'])) {
            return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
        }

        $context = $selectData['context'];
        $txnId   = $context['transaction_id'] ?? null;
        $bapUri  = rtrim($context['bap_uri'] ?? '', '/');
        $bapId   = $context['bap_id'] ?? null;

        if (!$txnId || !$bapUri) {
            return response()->json(['error' => 'Required parameters missing'], 400);
        }

        // Return ACK and close socket so ngrok is free for the next call
        $this->sendAckAndCloseConnection($context);

        try {
            Cache::put("ondc:txn:{$txnId}", [
                'bap_uri'          => $bapUri,
                'bap_id'           => $bapId,
                'original_request' => $selectData,
                'action'           => 'select',
            ], now()->addMinutes(30));

            $this->sendOnSelect($selectData, $txnId, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('SEND_ON_SELECT_EXCEPTION', ['error' => $e->getMessage()]);
        }
        exit;
    }

    protected function sendOnSelect($selectData, $txnId, $bapUri, $bapId)
    {
        $context = $selectData['context'];
        $message = $selectData['message'] ?? [];
        $messageId = $context['message_id'] ?? null;

        $lockKey = "ondc:lock:on_select:{$txnId}:{$messageId}";
        if (!Cache::add($lockKey, true, 60)) {
            return;
        }

        $requestedDomain = $context['domain'] ?? config(
            'ondc.default_domain',
            'ONDC:RET10'
        );

        if ($requestedDomain === FnbDiscoveryService::DOMAIN) {
            // F&B select flow
            Log::info('[FNB][SELECT] Routing to F&B processSelect', [
                'domain' => $requestedDomain,
                'transaction_id' => $txnId,
                'message_id' => $messageId,
            ]);

            $payload = $this->fnbDelivery->processSelect($selectData);

            Log::info('[FNB][SELECT] processSelect returned', [
                'action' => data_get($payload, 'context.action'),
                'transaction_id' => data_get($payload, 'context.transaction_id'),
                'message_id' => data_get($payload, 'context.message_id'),
                'has_order' => isset($payload['message']['order']),
            ]);
        } else {
            // Existing RET10 / generic retail flow
            $payload = $this->buildOnSelectPayload(
                $context,
                $message,
                $bapUri,
                $bapId
            );
        }
        if (empty($payload['context'])) {
            return;
        }

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bppUri = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $endpoint = [
            'url'     => $bppUri . '/on_select',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'select', $payload, $bapUri, $bapId);
    }

    protected function buildOnSelectPayload($context, $message, $bapUri, $bapId)
    {
        $inboundTimestamp = $context['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $timestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);

        $order         = $message['order'] ?? [];
        $items         = $order['items'] ?? [];
        $providerId    = data_get($order, 'provider.id', 'P1');
        $locationId    = data_get($order, 'provider.locations.0.id', 'L1');
        $fulfillmentId = data_get($order, 'fulfillments.0.id', 'F1');

        $validatedItems = $this->validateAndBuildItems($items, $fulfillmentId);
        $quoteBreakup   = $this->buildQuoteBreakup($validatedItems, $fulfillmentId);
        $totalPrice     = array_sum(array_column($quoteBreakup, 'price_value'));

        $quoteBreakup = array_map(function ($item) {
            unset($item['price_value']);
            return $item;
        }, $quoteBreakup);

        $hasOutOfStock = collect($validatedItems)->contains(fn($item) => ($item['is_available'] ?? true) === false);

        $cleanOrderItems = [];
        foreach ($validatedItems as $valItem) {
            $cleanOrderItems[] = [
                'id'             => (string) $valItem['id'],
                'fulfillment_id' => (string) ($valItem['fulfillment_id'] ?? $fulfillmentId),
            ];
        }

        $payload = [
            'context' => [
                'domain'         => $context['domain'] ?? 'ONDC:RET10',
                'country'        => $context['country'] ?? 'IND',
                'city'           => $context['city'] ?? 'std:080',
                'core_version'   => $context['core_version'] ?? '1.2.0',
                'action'         => 'on_select',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => $context['bpp_id'] ?? config('services.ondc.bpp_id'),
                'bpp_uri'        => $context['bpp_uri'] ?? config('services.ondc.bpp_uri'),
                'transaction_id' => $context['transaction_id'],
                'message_id'     => $context['message_id'],
                'timestamp'      => $timestamp,
                'ttl'            => 'PT30S',
            ],
            'message' => [
                'order' => [
                    'provider' => [
                        'id'        => $providerId,
                        'locations' => [
                            ['id' => $locationId],
                        ],
                    ],
                    'items'        => array_values($cleanOrderItems),
                    'fulfillments' => [
                        [
                            'id'                      => $fulfillmentId,
                            'type'                    => 'Delivery',
                            '@ondc/org/provider_name' => config('ondc.store.name', 'Store 1'),
                            'tracking'                => false,
                            '@ondc/org/category'      => 'Standard Delivery',
                            '@ondc/org/TAT'           => 'PT24H',
                            'state' => [
                                'descriptor' => [
                                    'code' => 'Serviceable',
                                ],
                            ],
                        ],
                    ],
                    'quote' => [
                        'price' => [
                            'value'    => number_format($totalPrice, 2, '.', ''),
                            'currency' => 'INR',
                        ],
                        'breakup' => array_values($quoteBreakup),
                        'ttl'     => 'P1D',
                    ],
                ],
            ],
        ];

        // Attach ONDC Out of Stock DOMAIN-ERROR if any item is out of stock
        if ($hasOutOfStock) {
            $payload['error'] = [
                'type'    => 'DOMAIN-ERROR',
                'code'    => '40002',
                'message' => 'One or more selected items are out of stock',
            ];
        }

        return $payload;
    }

    protected function validateAndBuildItems($items, $fulfillmentId = 'F1')
    {
        $validatedItems = [];

        foreach ($items as $item) {
            $itemId   = $item['id'] ?? null;
            $quantity = (int) ($item['quantity']['count'] ?? 1);

            if (!$itemId) {
                continue;
            }

            $productId   = (int) str_replace('I', '', $itemId);
            $productFlat = DB::table('product_flat')
                ->where('product_id', $productId)
                ->where('status', 1)
                ->first();

            if (!$productFlat) {
                $validatedItems[] = [
                    'id'             => $itemId,
                    'fulfillment_id' => $fulfillmentId,
                    'name'           => 'Item',
                    'price'          => 0.00,
                    'quantity'       => $quantity,
                    'available_qty'  => 0,
                    'is_available'   => false,
                ];
                continue;
            }

            // Query actual inventory from Bagisto
            $totalInventory = (int) DB::table('product_inventories')
                ->where('product_id', $productId)
                ->sum('qty');

            $isAvailable = $totalInventory >= $quantity && $totalInventory > 0;

            $today       = now()->toDateString();
            $actualPrice = (float) $productFlat->price;

            if (
                !empty($productFlat->special_price)
                && (empty($productFlat->special_price_from) || $productFlat->special_price_from <= $today)
                && (empty($productFlat->special_price_to) || $productFlat->special_price_to >= $today)
            ) {
                $actualPrice = (float) $productFlat->special_price;
            }

            $validatedItems[] = [
                'id'             => $itemId,
                'fulfillment_id' => $fulfillmentId,
                'name'           => $productFlat->name ?? 'Product',
                'price'          => $actualPrice,
                'quantity'       => $quantity,
                'available_qty'  => max(0, $totalInventory),
                'is_available'   => $isAvailable,
            ];
        }

        return $validatedItems;
    }

    protected function buildQuoteBreakup($items, $fulfillmentId = 'F1')
    {
        $breakup = [];

        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            if (!$itemId) {
                continue;
            }

            $quantity     = (int) ($item['quantity'] ?? 1);
            $price        = (float) ($item['price'] ?? 0.0);
            $itemName     = $item['name'] ?? 'Item';
            $availableQty = (int) ($item['available_qty'] ?? 0);
            $isAvailable  = (bool) ($item['is_available'] ?? true);

            // If unavailable, count & price contribute 0 to the bill
            $billableCount = $isAvailable ? $quantity : 0;
            $itemTotal     = $isAvailable ? ($price * $quantity) : 0.00;

            $breakup[] = [
                '@ondc/org/item_id'       => (string) $itemId,
                '@ondc/org/item_quantity' => ['count' => $billableCount],
                'title'                   => $itemName,
                '@ondc/org/title_type'    => 'item',
                'price_value'             => $itemTotal,
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($itemTotal, 2, '.', ''),
                ],
                'item' => [
                    'quantity' => [
                        'available' => ['count' => (string) $availableQty],
                        'maximum'   => ['count' => (string) $availableQty],
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($price, 2, '.', ''),
                    ],
                ],
            ];
        }

        // Delivery
        $breakup[] = [
            '@ondc/org/item_id'    => (string) $fulfillmentId,
            'title'                => 'Delivery charges',
            '@ondc/org/title_type' => 'delivery',
            'price_value'          => 0.00,
            'price' => [
                'currency' => 'INR',
                'value'    => '0.00',
            ],
        ];

        // Convenience Fee
        $breakup[] = [
            '@ondc/org/item_id'    => (string) $fulfillmentId,
            'title'                => 'Convenience Fee',
            '@ondc/org/title_type' => 'misc',
            'price_value'          => 0.00,
            'price' => [
                'currency' => 'INR',
                'value'    => '0.00',
            ],
        ];

        return $breakup;
    }

    // ──────────────────────────────────────────────────────────────
    // INIT
    // ──────────────────────────────────────────────────────────────

    public function init(Request $request)
    {
        $initData = $request->all();

        if (!isset($initData['context']) || !isset($initData['message'])) {
            return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
        }

        $context = $initData['context'];
        $txnId   = $context['transaction_id'] ?? null;
        $bapUri  = rtrim($context['bap_uri'] ?? '', '/');
        $bapId   = $context['bap_id'] ?? null;

        if (!$txnId || !$bapUri) {
            return response()->json(['error' => 'Required parameters missing'], 400);
        }

        // 1. Return ACK and completely close the socket immediately
        $this->sendAckAndCloseConnection($context);

        // 2. Pause briefly to ensure Workbench ingests the ACK before receiving the callback
        sleep(1);

        // 3. Process the request inline (backgrounded by fastcgi_finish_request)
        try {
            Cache::put("ondc:txn:{$txnId}", [
                'bap_uri'          => $bapUri,
                'bap_id'           => $bapId,
                'original_request' => $initData,
                'action'           => 'init',
            ], now()->addMinutes(30));

            $this->sendOnInit($initData, $txnId, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('SEND_ON_INIT_EXCEPTION', ['error' => $e->getMessage()]);
        }

        // DO NOT put exit; here. Let PHP complete gracefully.
    }

    protected function sendOnInit($initData, $txnId, $bapUri, $bapId)
    {
        $context = $initData['context'] ?? [];
        $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        if ($requestedDomain === FnbDiscoveryService::DOMAIN) {
            Log::info('[FNB][INIT] Routing to F&B processInit', [
                'domain'         => $requestedDomain,
                'transaction_id' => $txnId,
            ]);

            $responsePayload = $this->fnbDelivery->processInit($initData);
            $responsePayload['context']['bpp_id']  = config('services.ondc.bpp_id');
            $responsePayload['context']['bpp_uri'] = config('services.ondc.bpp_uri');
        } else {
            $responsePayload = $this->buildOnInitPayload($initData, $txnId, $bapUri, $bapId);
        }

        $msgId = $responsePayload['context']['message_id'] ?? null;

        $lockKey = "ondc:lock:on_init:{$txnId}:{$msgId}";
        if (!Cache::add($lockKey, true, 60)) {
            return;
        }

        if ($this->alreadySentCallback($txnId, 'init', $msgId)) {
            return;
        }

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        $endpoint = [
            'url'     => rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/') . '/on_init',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
            ],
        ];

        $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'init', $responsePayload, $bapUri, $bapId);
    }

    protected function buildOnInitPayload($initData, $txnId, $bapUri, $bapId = null)
    {
        $inboundContext = $initData['context'] ?? [];
        $payload        = $initData['message']['order'] ?? [];

        $inboundTimestamp = $inboundContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);

        $order = [
            'provider'           => $payload['provider'] ?? [],
            'items'              => array_values($this->buildItemsForOnInit($payload['items'] ?? [])),
            'billing'            => $this->buildBillingForOnInit($payload['billing'] ?? []),
            'fulfillments'       => array_values($this->buildFulfillmentsForOnInit($payload['fulfillments'] ?? [])),
            'quote'              => $this->buildQuoteForOnInit($payload),
            'payment'            => $this->buildPaymentForOnInit($initData),
            'tags'               => $this->buildBppTermsTags(),
            'cancellation_terms' => $this->buildCancellationTerms(),
        ];

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
            'timestamp'      => $outboundTimestamp,
        ];

        return [
            'context' => $context,
            'message' => ['order' => $order],
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
            $productFlat = DB::table('product_flat')->where('product_id', $productId)->where('status', 1)->first();
            if (!$productFlat) {
                continue;
            }

            $quantity = (int) ($item['quantity']['count'] ?? 1);
            $unitPrice = (float) $this->getOnInitProductPrice($productFlat);
            $itemTotal = $unitPrice * $quantity;
            $totalValue += $itemTotal;

            $breakup[] = [
                '@ondc/org/item_id'       => (string) $itemId,
                '@ondc/org/item_quantity' => ['count' => $quantity],
                'title'                   => (string) ($productFlat->name ?? 'Product'),
                '@ondc/org/title_type'    => 'item',
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($itemTotal, 2, '.', ''),
                ],
                'item' => [
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($unitPrice, 2, '.', ''),
                    ],
                ],
            ];
        }

        $fulfillmentId = $orderPayload['fulfillments'][0]['id'] ?? 'F1';

        $breakup[] = [
            '@ondc/org/item_id'    => (string) $fulfillmentId,
            '@ondc/org/title_type' => 'delivery',
            'title'                => 'Delivery charges',
            'price' => [
                'currency' => 'INR',
                'value'    => '0.00',
            ],
        ];

        $breakup[] = [
            '@ondc/org/item_id'    => (string) $fulfillmentId,
            '@ondc/org/title_type' => 'misc',
            'title'                => 'Convenience Fee',
            'price' => [
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
            'name'       => $name,
            'email'      => $billing['email'] ?? 'nobody@nomail.com',
            'phone'      => $billing['phone'] ?? '9898989898',
            'tax_number' => $billing['tax_number'] ?? '00ABCDE1234F1Z5',
            'created_at' => $billing['created_at'] ?? OndcHelper::timestamp(),
            'updated_at' => $billing['updated_at'] ?? OndcHelper::timestamp(),
            'address' => [
                'name'      => $billing['address']['name'] ?? $name,
                'building'  => $billing['address']['building'] ?? 'Building',
                'locality'  => $billing['address']['locality'] ?? 'Locality',
                'city'      => $billing['address']['city'] ?? 'Bengaluru',
                'state'     => $billing['address']['state'] ?? 'Karnataka',
                'country'   => ($billing['address']['country'] ?? '') === 'India' ? 'IND' : ($billing['address']['country'] ?? 'IND'),
                'area_code' => $billing['address']['area_code'] ?? '560001',
            ],
        ];
    }

    protected function buildFulfillmentsForOnInit($fulfillments, $billing = [])
    {
        $storeName = config('ondc.store.name', 'Store 1');

        return collect($fulfillments)->map(function ($f) use ($storeName, $billing) {
            $endLocation = $f['end']['location'] ?? [];
            $rawAddress = $endLocation['address'] ?? ($billing['address'] ?? []);
            $personName = $f['end']['person']['name'] ?? ($billing['name'] ?? 'Buyer');

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
                'tracking'                => false,
                '@ondc/org/provider_name' => (string) ($f['@ondc/org/provider_name'] ?? $storeName),
                '@ondc/org/TAT'           => (string) ($f['@ondc/org/TAT'] ?? 'PT60M'),
                'end' => [
                    'contact' => [
                        'email' => (string) ($f['end']['contact']['email'] ?? ($billing['email'] ?? 'nobody@nomail.com')),
                        'phone' => (string) ($f['end']['contact']['phone'] ?? ($billing['phone'] ?? '9898989898')),
                    ],
                    'location' => [
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

            $productId = (int) str_replace('I', '', $itemId);
            $productFlat = DB::table('product_flat')->where('product_id', $productId)->where('status', 1)->first();

            if (!$productFlat) {
                return $item;
            }

            $quantity = (int) ($item['quantity']['count'] ?? 1);

            return [
                'id'             => $itemId,
                'fulfillment_id' => $item['fulfillment_id'] ?? 'F1',
                'quantity' => [
                    'count' => $quantity,
                ],
                'descriptor' => [
                    'name'       => $productFlat->name,
                    'short_desc' => strip_tags($productFlat->short_description ?? ''),
                    'long_desc'  => strip_tags($productFlat->description ?? ''),
                    'code'       => $productFlat->sku,
                ],
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($this->getOnInitProductPrice($productFlat), 2, '.', ''),
                ],
                'location_id' => $item['location_id'] ?? $item['location'] ?? 'L1',
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
            && (!$product->special_price_from || $today->greaterThanOrEqualTo($product->special_price_from))
            && (!$product->special_price_to || $today->lessThanOrEqualTo($product->special_price_to))
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
            'type'                                  => 'ON-ORDER',
            'collected_by'                          => 'BPP',
            'uri'                                   => env('ONDC_PAYMENT_URI', 'https://retail-seller.softtrust.org/payment'),
            'status'                                => 'NOT-PAID',
            '@ondc/org/buyer_app_finder_fee_type'   => 'percent',
            '@ondc/org/buyer_app_finder_fee_amount' => '3',
            '@ondc/org/settlement_basis'            => 'delivery',
            '@ondc/org/settlement_window'           => 'P1D',
            '@ondc/org/withholding_amount'          => '10.00',
            '@ondc/org/settlement_details' => [
                [
                    'settlement_counterparty'    => 'seller-app',
                    'settlement_phase'           => 'sale-amount',
                    'settlement_type'            => 'neft',
                    'beneficiary_name'           => env('ONDC_BENEFICIARY_NAME', 'Store'),
                    'settlement_bank_account_no' => env('ONDC_BANK_ACCOUNT', '0000000000'),
                    'settlement_ifsc_code'       => env('ONDC_IFSC_CODE', 'SBIN0000000'),
                    'bank_name'                  => env('ONDC_BANK_NAME', 'State Bank of India'),
                    'branch_name'                => env('ONDC_BRANCH_NAME', 'Main Branch'),
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
                    ['code' => 'np_type', 'value' => 'MSN'],
                    ['code' => 'tax_number', 'value' => env('ONDC_SELLER_GST', 'gst_number_of_sellerNP')],
                    ['code' => 'provider_tax_number', 'value' => env('ONDC_SELLER_PAN', 'PAN_number_of_provider')],
                ],
            ],
        ];
    }

    protected function sanitizeOrderTags(array $tags): array
    {
        $allowedCodes = ['static_terms', 'tax_number', 'accept_bpp_terms'];

        return collect($tags)->map(function ($tag) use ($allowedCodes) {
            if (!isset($tag['list']) || !is_array($tag['list'])) {
                return $tag;
            }

            $tag['list'] = collect($tag['list'])
                ->map(fn($item) => in_array($item['code'] ?? '', $allowedCodes) ? $item : null)
                ->filter()
                ->values()
                ->all();

            return $tag;
        })->all();
    }

    // ──────────────────────────────────────────────────────────────
    // CONFIRM (WITH DETAILED TIMING LOGS)
    // ──────────────────────────────────────────────────────────────

    public function confirm(Request $request)
    {
        $confirmData = $request->all();

        if (!isset($confirmData['context']) || !isset($confirmData['message'])) {
            return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
        }

        $context = $confirmData['context'];
        $txnId  = $context['transaction_id'] ?? null;
        $bapUri = rtrim($context['bap_uri'] ?? '', '/');
        $bapId  = $context['bap_id'] ?? null;

        if (!$txnId || !$bapUri) {
            return response()->json(['error' => 'Required context fields missing'], 400);
        }

        Log::info('[FNB][CONFIRM] Incoming confirm received', [
            'transaction_id' => $txnId,
            'message_id'     => $context['message_id'] ?? null,
        ]);

        // 1. Return ACK and completely close the socket immediately
        $this->sendAckAndCloseConnection($context);

        // 2. Pause briefly to ensure Workbench ingests the ACK
        sleep(1);

        // 3. Process inline
        try {
            Cache::put("ondc:txn:{$txnId}", [
                'bap_uri'          => $bapUri,
                'bap_id'           => $bapId,
                'original_request' => $confirmData,
                'action'           => 'confirm',
            ], now()->addMinutes(30));

            Log::info('[FNB][CONFIRM] Routing to sendOnConfirm', [
                'transaction_id' => $txnId,
                'message_id'     => $context['message_id'] ?? null,
            ]);

            $this->sendOnConfirm($confirmData, $txnId, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('[FNB][CONFIRM] sendOnConfirm failed', [
                'transaction_id' => $txnId,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
        }

        // DO NOT put exit; here.
    }
    
    protected function sendOnConfirm(
        $confirmData,
        $txnId,
        $bapUri,
        $bapId
    ) {
        $context   = $confirmData['context'] ?? [];
        $messageId = $context['message_id'] ?? null;

        $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        Log::info('[ONDC][CONFIRM] Processing confirm', [
            'domain'         => $requestedDomain,
            'transaction_id' => $txnId,
            'message_id'     => $messageId,
        ]);

        $lockKey = "ondc:lock:on_confirm:{$txnId}:{$messageId}";

        if (!Cache::add($lockKey, true, 60)) {
            Log::warning('[ONDC][CONFIRM] Duplicate confirm ignored', [
                'transaction_id' => $txnId,
                'message_id'     => $messageId,
            ]);

            return;
        }

        if ($requestedDomain === FnbDiscoveryService::DOMAIN) {
            Log::info('[FNB][CONFIRM] Routing to F&B processConfirm', [
                'domain'         => $requestedDomain,
                'transaction_id' => $txnId,
                'message_id'     => $messageId,
            ]);

            $responsePayload = $this->fnbDelivery->processConfirm($confirmData);

            Log::info('[FNB][CONFIRM] processConfirm returned', [
                'action'         => data_get($responsePayload, 'context.action'),
                'transaction_id' => data_get($responsePayload, 'context.transaction_id'),
                'message_id'     => data_get($responsePayload, 'context.message_id'),
                'order_id'       => data_get($responsePayload, 'message.order.id'),
            ]);
        } else {
            $this->processConfirmSync(
                $confirmData,
                $txnId,
                $bapUri,
                $bapId
            );

            return;
        }

        $responsePayload['context']['bpp_id']  = config('services.ondc.bpp_id');
        $responsePayload['context']['bpp_uri'] = config('services.ondc.bpp_uri');

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);
        $bppUri      = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $endpoint = [
            'url'     => $bppUri . '/on_confirm',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        Log::info('[FNB][CONFIRM] Dispatching on_confirm', [
            'transaction_id' => $txnId,
            'message_id'     => data_get($responsePayload, 'context.message_id'),
            'timestamp'      => data_get($responsePayload, 'context.timestamp'),
            'url'            => $endpoint['url'],
        ]);

        $onConfirmAcked = $this->sendToEndpoint(
            $endpoint,
            $payloadJson,
            $txnId,
            'confirm',
            $responsePayload,
            $bapUri,
            $bapId
        );

        Log::info('[FNB][CONFIRM] on_confirm dispatch completed', [
            'transaction_id' => $txnId,
            'message_id'     => data_get($responsePayload, 'context.message_id'),
            'acked'          => $onConfirmAcked,
        ]);

        if ($onConfirmAcked) {
            Cache::put("ondc:on_confirm_acked:{$txnId}", true, now()->addMinutes(10));

            Log::info('[FNB][CONFIRM] on_confirm ACK received. Waiting before on_status.', [
                'transaction_id' => $txnId,
            ]);

            // usleep(800000);

            $orderForReply = $responsePayload['message']['order'] ?? [];

            // DISABLED — premature automatic on_status after on_confirm ACK
            // (prevents Workbench selecting wrong previousRequestTimestamp)
            // try {
            //     $this->sendOnStatus(
            //         $txnId,
            //         $orderForReply['id'] ?? $txnId,
            //         'Pending',
            //         $bapUri,
            //         $bapId,
            //         $responsePayload['context']
            //     );
            //     Log::info('[FNB][CONFIRM] Initial on_status(Pending) dispatched', [
            //         'transaction_id' => $txnId,
            //         'order_id'       => $orderForReply['id'] ?? $txnId,
            //     ]);
            // } catch (\Throwable $e) {
            //     Log::error('[FNB][STATUS] Failed to dispatch automatic on_status(Pending)', [
            //         'transaction_id' => $txnId,
            //         'error'          => $e->getMessage(),
            //     ]);
            // }

            // Create Bagisto Order record for F&B so it populates the DataGrid
            try {
                $this->createBagistoOrderSynchronously($confirmData, $txnId, $context, $orderForReply);
            } catch (\Throwable $e) {
                Log::error('[FNB][BAGISTO][ORDER] Order creation failed post-dispatch', [
                    'txn_id' => $txnId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }
    }

    protected function processConfirmSync(array $confirmData, string $txnId, string $bapUri, ?string $bapId): void
    {
        $tSyncStart = microtime(true);
        $order = $confirmData['message']['order'] ?? [];
        if (isset($order[0]) && is_array($order[0])) {
            $order = $order[0];
        }

        $inboundContext    = $confirmData['context'] ?? [];
        $inboundTimestamp  = $inboundContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);
        $inboundMessageId  = $inboundContext['message_id'];

        $context = [
            'domain'         => $inboundContext['domain'] ?? 'ONDC:RET10',
            'country'        => $inboundContext['country'] ?? 'IND',
            'city'           => $inboundContext['city'] ?? 'std:080',
            'core_version'   => $inboundContext['core_version'] ?? '1.2.0',
            'action'         => 'on_confirm',
            'transaction_id' => $txnId,
            'message_id'     => $inboundMessageId,
            'ttl'            => 'PT30S',
            'bap_id'         => $bapId ?: ($inboundContext['bap_id'] ?? ''),
            'bap_uri'        => $bapUri,
            'bpp_id'         => config('services.ondc.bpp_id'),
            'bpp_uri'        => config('services.ondc.bpp_uri'),
            'timestamp'      => $outboundTimestamp,
        ];

        $providerId = data_get($order, 'provider.id', 'P1');
        $locationId = data_get($order, 'provider.locations.0.id', 'L1');
        $provider = [
            'id'        => (string) $providerId,
            'locations' => [
                ['id' => (string) $locationId],
            ],
        ];

        $billing = $order['billing'] ?? [];
        if (isset($billing[0]) && is_array($billing[0])) {
            $billing = $billing[0];
        }

        $items = collect($order['items'] ?? [])->map(function ($item) {
            if (isset($item[0]) && is_array($item[0])) {
                $item = $item[0];
            }
            return [
                'id'             => (string) ($item['id'] ?? ''),
                'fulfillment_id' => (string) ($item['fulfillment_id'] ?? 'F1'),
                'quantity'       => [
                    'count' => (int) data_get($item, 'quantity.count', 1),
                ],
            ];
        })->values()->all();

        $fulfillments = $this->buildFulfillmentsForOnConfirm($order['fulfillments'] ?? []);

        // Strict priority on confirm order created_at to avoid millisecond drift
        $confirmCreatedAt = $order['created_at']
            ?? ($confirmData['message']['order']['created_at'] ?? null)
            ?? ($inboundContext['timestamp'] ?? $outboundTimestamp);

        $inboundTags = collect($order['tags'] ?? [])->filter(fn($t) => ($t['code'] ?? '') === 'bap_terms')->values()->all();
        $bppTermsTag = [
            'code' => 'bpp_terms',
            'list' => [
                ['code' => 'tax_number', 'value' => env('ONDC_SELLER_GST', '37ABACA6712B1Z6')],
                ['code' => 'provider_tax_number', 'value' => env('ONDC_SELLER_PAN', 'ABCDE1234F')],
                ['code' => 'np_type', 'value' => 'MSN'],
            ],
        ];
        $finalTags = array_merge($inboundTags, [$bppTermsTag]);

        $orderForReply = [
            'id'           => $order['id'] ?? $txnId,
            'state'        => 'Created',
            'provider'     => $provider,
            'items'        => $items,
            'billing'      => $billing,
            'fulfillments' => $fulfillments,
            'quote'        => $order['quote'] ?? [],
            'payment'      => $order['payment'] ?? [],
            'tags'         => $finalTags,
            'created_at'   => $confirmCreatedAt,
            'updated_at'   => $outboundTimestamp,
        ];

        $responsePayload = [
            'context' => $context,
            'message' => ['order' => $orderForReply],
        ];

        $buildMs = round((microtime(true) - $tSyncStart) * 1000, 2);

        $tBeforeSave = microtime(true);
        try {
            OndcConfirmPayload::updateOrCreate(
                ['transaction_id' => $txnId],
                [
                    'id'               => (string) Str::uuid(),
                    'increment_id'     => $txnId,
                    'context'          => $context,
                    'message_order'    => $orderForReply,
                    'bap_id'           => $context['bap_id'],
                    'bap_uri'          => $bapUri,
                    'domain'           => $context['domain'],
                    'bagisto_order_id' => null,
                ]
            );
        } catch (\Throwable $e) {
            Log::error('PRE_SAVE_CONFIRM_PAYLOAD_FAILED', ['error' => $e->getMessage()]);
        }
        $savePayloadMs = round((microtime(true) - $tBeforeSave) * 1000, 2);

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);
        $bppUri      = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $endpoint = [
            'url'     => $bppUri . '/on_confirm',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        Log::info('[TIMING][CONFIRM] 2.1 Starting outbound HTTP dispatch to ' . $endpoint['url'], [
            'build_ms'        => $buildMs,
            'save_payload_ms' => $savePayloadMs,
            'wall_clock'      => now()->format('H:i:s.v'),
        ]);

        $tBeforeHttp = microtime(true);

        // SINGLE outbound HTTP dispatch
        $onConfirmAcked = $this->sendToEndpoint(
            $endpoint,
            $payloadJson,
            $txnId,
            'confirm',
            $responsePayload,
            $bapUri,
            $bapId
        );

        $httpDispatchMs = round((microtime(true) - $tBeforeHttp) * 1000, 2);

        if ($onConfirmAcked) {
            Cache::put("ondc:on_confirm_acked:{$txnId}", true, now()->addMinutes(10));
            Log::info('[ONDC][CONFIRM] on_confirm received ACK from Workbench. Cancel gateway is now open.', [
                'txn_id' => $txnId,
            ]);

            // ── DELAY GUARD: Give Workbench 800ms to digest the payload before firing unsolicited status ──
            // usleep(800000);

            // DISABLED — second automatic sendOnStatus('Pending') after on_confirm ACK
            // (prevents Workbench selecting wrong previousRequestTimestamp)
            // try {
            //     $this->sendOnStatus(
            //         $txnId,
            //         $orderForReply['id'] ?? $txnId,
            //         'Pending',
            //         $bapUri,
            //         $bapId,
            //         $context
            //     );
            // } catch (\Throwable $e) {
            //     Log::error('[ONDC][STATUS] Failed to dispatch automatic on_status (Pending)', [
            //         'txn_id' => $txnId,
            //         'error'  => $e->getMessage(),
            //     ]);
            // }

            // 3. Flush buffered /cancel request if one arrived while on_confirm was in transit
            $bufferedCancel = Cache::pull("ondc:pending_cancel:{$txnId}");
            if ($bufferedCancel) {
                Log::info('[ONDC][CONFIRM] Flushing buffered cancel with exact message_id', [
                    'txn_id'     => $txnId,
                    'message_id' => data_get($bufferedCancel, 'context.message_id'),
                ]);

                app(\Webkul\Ondc\Http\Controllers\CancellationController::class)->processCancelSync($bufferedCancel);
            }
            try {
                $this->createBagistoOrderSynchronously($confirmData, $txnId, $context, $orderForReply);
            } catch (\Throwable $e) {
                Log::error('[BAGISTO][ORDER] Order creation failed post-dispatch', [
                    'txn_id' => $txnId,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('[TIMING][CONFIRM] 2.2 on_confirm finished all steps', [
            'http_dispatch_ms' => $httpDispatchMs,
            'total_sync_ms'    => round((microtime(true) - $tSyncStart) * 1000, 2),
            'wall_clock'       => now()->format('H:i:s.v'),
        ]);
    }

    protected function createBagistoOrderSynchronously(array $confirmData, string $txnId, array $context, array $orderForReply): void
    {
        if (DB::table('orders')->where('increment_id', $txnId)->exists()) {
            Log::info('[BAGISTO][ORDER] Order already exists for transaction', ['txn_id' => $txnId]);
            return;
        }

        $order = $confirmData['message']['order'] ?? [];
        if (isset($order[0]) && is_array($order[0])) {
            $order = $order[0];
        }

        $orderItems  = $order['items'] ?? [];
        $quote       = $order['quote'] ?? [];
        $payment     = $order['payment'] ?? [];
        $billing     = $order['billing'] ?? [];
        $fulfillment = ($order['fulfillments'][0] ?? null) ?: [];

        $itemQtySum = 0;
        foreach ($orderItems as $item) {
            $itemQtySum += (int) data_get($item, 'quantity.count', 1);
        }

        $orderTotal = (float) data_get($quote, 'price.value', 0.0);
        $currency   = data_get($quote, 'price.currency', 'INR');

        DB::beginTransaction();

        try {
            $bagistoOrder = Order::create([
                'increment_id'          => $txnId,
                'channel_id'            => 1,
                'status'                => 'Pending',
                'customer_id'           => null,
                'customer_type'         => 'Webkul\Customer\Models\Customer',
                'channel_type'          => 'ONDC',
                'channel_name'          => 'ONDC',
                'is_guest'              => true,
                'total_item_count'      => count($orderItems),
                'total_qty_ordered'     => $itemQtySum,
                'base_currency_code'    => $currency,
                'channel_currency_code' => $currency,
                'order_currency_code'   => $currency,
                'grand_total'           => $orderTotal,
                'base_grand_total'      => $orderTotal,
                'sub_total'             => $orderTotal,
                'base_sub_total'        => $orderTotal,
                'tax_amount'            => 0.0,
                'base_tax_amount'       => 0.0,
                'shipping_amount'       => 0.0,
                'base_shipping_amount'  => 0.0,
                'discount_amount'       => 0.0,
                'base_discount_amount'  => 0.0,
                'created_at'            => now(),
                'updated_at'            => now(),
            ]);

            // Billing Address
            $billingAddr = $billing['address'] ?? [];
            OrderAddress::create([
                'order_id'     => $bagistoOrder->id,
                'address_type' => 'billing',
                'first_name'   => $billing['name'] ?? ($billingAddr['name'] ?? 'Buyer'),
                'email'        => $billing['email'] ?? 'nobody@nomail.com',
                'phone'        => $billing['phone'] ?? '9898989898',
                'address1'     => $billingAddr['building'] ?? ($billingAddr['address'] ?? 'Locality'),
                'city'         => $billingAddr['city'] ?? 'Bengaluru',
                'state'        => $billingAddr['state'] ?? 'Karnataka',
                'country'      => ($billingAddr['country'] ?? '') === 'India' ? 'IND' : ($billingAddr['country'] ?? 'IND'),
                'postcode'     => $billingAddr['area_code'] ?? '560001',
            ]);

            // Shipping Address
            $endLocation = $fulfillment['end']['location'] ?? [];
            $endAddr     = $endLocation['address'] ?? [];
            $personName  = ($fulfillment['end']['person']['name'] ?? '') ?: ($billing['name'] ?? 'Buyer');

            OrderAddress::create([
                'order_id'     => $bagistoOrder->id,
                'address_type' => 'shipping',
                'first_name'   => $personName,
                'email'        => $fulfillment['end']['contact']['email'] ?? ($billing['email'] ?? 'nobody@nomail.com'),
                'phone'        => $fulfillment['end']['contact']['phone'] ?? ($billing['phone'] ?? '9898989898'),
                'address1'     => $endAddr['address'] ?? ($endAddr['building'] ?? 'Locality'),
                'city'         => $endAddr['city'] ?? 'Bengaluru',
                'state'        => $endAddr['state'] ?? 'Karnataka',
                'country'      => ($endAddr['country'] ?? '') === 'India' ? 'IND' : ($endAddr['country'] ?? 'IND'),
                'postcode'     => $endAddr['area_code'] ?? '560001',
            ]);

            // Items (Retail & F&B Support)
            foreach ($orderItems as $item) {
                $rawId   = $item['id'] ?? null;
                $dbId    = (int) preg_replace('/^I/', '', $rawId);
                $product = Product::find($dbId);

                $fnbItem = null;
                if (! $product) {
                    $fnbItem = OndcFnbItem::where('ondc_item_id', $rawId)->first();
                }

                $qty            = (int) data_get($item, 'quantity.count', 1);
                $itemPriceValue = 0.0;

                if (isset($quote['breakup']) && is_array($quote['breakup'])) {
                    foreach ($quote['breakup'] as $breakup) {
                        if (($breakup['@ondc/org/item_id'] ?? '') === $rawId && ($breakup['@ondc/org/title_type'] ?? '') === 'item') {
                            $itemPriceValue = (float) ($breakup['price']['value'] ?? 0.0);
                            break;
                        }
                    }
                }

                $unitPrice = $qty > 0 && $itemPriceValue > 0
                    ? ($itemPriceValue / $qty)
                    : ($product ? (float) $product->price : ($fnbItem ? (float) $fnbItem->price : (float) data_get($item, 'price.value', 0.0)));
                $total     = $unitPrice * $qty;

                $itemName = $product
                    ? $product->name
                    : ($fnbItem?->name ?? data_get($item, 'descriptor.name') ?? data_get($item, 'title') ?? 'F&B Item');
                $itemSku  = $product
                    ? $product->sku
                    : ($fnbItem?->ondc_item_id ?? $rawId ?? 'FNB-ITEM');

                OrderItem::create([
                    'order_id'            => $bagistoOrder->id,
                    'product_id'          => $product?->id,
                    'name'                => $itemName,
                    'product_name'        => $itemName,
                    'product_type'        => $product ? ($product->type ?? 'simple') : 'simple',
                    'sku'                 => $itemSku,
                    'ondc_item_id'        => $rawId,
                    'price'               => $unitPrice,
                    'base_price'          => $unitPrice,
                    'qty_ordered'         => $qty,
                    'total'               => $total,
                    'base_total'          => $total,
                    'tax_amount'          => 0,
                    'base_tax_amount'     => 0,
                    'price_incl_tax'      => $unitPrice,
                    'base_price_incl_tax' => $unitPrice,
                    'total_incl_tax'      => $total,
                    'base_total_incl_tax' => $total,
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]);
            }

            // Payment
            OrderPayment::create([
                'order_id'     => $bagistoOrder->id,
                'method'       => $payment['type'] ?? 'ONDC',
                'method_title' => 'ONDC Payment',
                'status'       => $payment['status'] ?? 'PAID',
                'amount'       => (float) ($payment['params']['amount'] ?? $orderTotal),
                'base_amount'  => (float) ($payment['params']['amount'] ?? $orderTotal),
                'additional'   => [
                    'ondc_transaction_id' => $txnId,
                    'bap_id'              => $context['bap_id'] ?? null,
                    'collected_by'        => $payment['collected_by'] ?? null,
                    'status'              => $payment['status'] ?? null,
                ],
            ]);

            DB::commit();

            OndcConfirmPayload::where('transaction_id', $txnId)->update([
                'bagisto_order_id' => $bagistoOrder->id,
            ]);

            Log::info('[BAGISTO][ORDER] Order created successfully', [
                'bagisto_order_id' => $bagistoOrder->id,
                'increment_id'     => $txnId,
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('[BAGISTO][ORDER] Database transaction failed', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    protected function buildFulfillmentsForOnConfirm($fulfillments)
    {
        $storeName = config('ondc.store.name', 'Store 1');
        $storeGps  = env('ONDC_STORE_GPS', '19.129076,72.825803');
        $now       = gmdate('Y-m-d\TH:i:s.v\Z');
        $endTime   = gmdate('Y-m-d\TH:i:s.v\Z', strtotime('+30 minutes'));

        return collect($fulfillments)->map(function ($f) use ($storeName, $storeGps, $now, $endTime) {
            $withStart = $f;
            $withStart['@ondc/org/provider_name'] = $f['@ondc/org/provider_name'] ?? $storeName;
            $withStart['@ondc/org/TAT']           = $f['@ondc/org/TAT'] ?? 'PT60M';
            $withStart['type']                    = $f['type'] ?? 'Delivery';
            $withStart['tracking']                = true; // MUST be false until Order-picked-up
            $withStart['id']                      = $f['id'] ?? 'F1';

            if (!isset($f['start'])) {
                $withStart['start'] = [
                    'location' => [
                        'id'         => 'L1',
                        'descriptor' => ['name' => $storeName],
                        'gps'        => $storeGps,
                        'address'    => [
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
                            'start' => $now,
                            'end'   => $endTime,
                        ],
                    ],
                    'contact' => [
                        'phone' => config('ondc.store.care_phone', '9594663710'),
                        'email' => config('ondc.store.care_email', 'nobody@nomail.com'),
                    ],
                ];
            }

            if (isset($withStart['end']['location']['address']) && empty($withStart['end']['location']['address']['name'])) {
                $withStart['end']['location']['address']['name'] = $withStart['end']['person']['name'] ?? 'Buyer';
            }

            $withStart['state'] = $f['state'] ?? ['descriptor' => ['code' => 'Pending']];

            return $withStart;
        })->all();
    }

    // ──────────────────────────────────────────────────────────────
    // STATUS (WITH CANCEL GUARD)
    // ──────────────────────────────────────────────────────────────

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
        $domain  = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');

        if (empty($context) || empty($txnId) || empty($msgId) || empty($orderId) || empty($bapUri) || empty($bapId)) {
            return response()->json([
                'context' => $context,
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => [
                    'type'    => 'DOMAIN-ERROR',
                    'code'    => '30000',
                    'message' => 'Required context or order_id missing',
                ],
            ], 400);
        }

        // 1. Immediately send synchronous ACK to close the socket
        $this->sendAckAndCloseConnection($context);

        // 2. Resolve status based on Domain (Diverting F&B to OndcFnbOrder)
        if ($domain === FnbDiscoveryService::DOMAIN) {
            $fnbOrder = OndcFnbOrder::where('ondc_transaction_id', $txnId)
                ->orWhere('ondc_order_id', $orderId)
                ->first();

            $currentStatus = $fnbOrder ? $fnbOrder->fulfillment_state : 'Pending';

            Log::info('[FNB][STATUS] Responding to /status poll from OndcFnbOrder', [
                'txn_id'         => $txnId,
                'current_status' => $currentStatus,
            ]);
        } else {
            $order = DB::table('orders')->where('increment_id', $txnId)->first();
            $statusMap = [
                'Pending'          => 'Pending',
                'Packed'           => 'Packed',
                'Agent-assigned'   => 'Agent-assigned',
                'Order-picked-up'  => 'Order-picked-up',
                'Out-for-delivery' => 'Out-for-delivery',
                'Order-delivered'  => 'Order-delivered',
                'RTO-Delivered'    => 'RTO-Delivered',
            ];
            $currentStatus = $statusMap[$order->status ?? 'Pending'] ?? 'Pending';
        }

        // 3. Dispatch the response asynchronously
        app()->terminating(function () use ($txnId, $orderId, $currentStatus, $bapUri, $bapId, $context, $msgId) {
            sleep(1);
            try {
                $this->sendOnStatus(
                    $txnId,
                    $orderId ?: $txnId,
                    $currentStatus,
                    $bapUri,
                    $bapId,
                    $context,
                    $msgId // Echo Workbench message_id
                );
            } catch (\Throwable $e) {
                Log::error('SEND_ON_STATUS_EXCEPTION', [
                    'txn_id' => $txnId,
                    'error'  => $e->getMessage(),
                ]);
            }
        });

        exit;
    }

    public function sendOnStatus(
        string $transactionId,
        string $orderId,
        string $statusCode,
        string $bapUri,
        string $bapId,
        array $baseContext,
        ?string $messageId = null
    ): void {
        $concurrencyLockKey = "ondc:lock:on_status_dispatch:{$transactionId}";
        if (!Cache::add($concurrencyLockKey, true, 10)) {
            Log::info('[ONDC][STATUS] Concurrent on_status locked, skipping', [
                'transaction_id' => $transactionId,
                'status_code'    => $statusCode,
            ]);
            return;
        }

        try {
            $dedupKey = "ondc:status_sent:{$transactionId}:{$statusCode}";
            if (!Cache::add($dedupKey, true, now()->addMinutes(30))) {
                Log::info('[ONDC][STATUS] Duplicate on_status suppressed', [
                    'transaction_id' => $transactionId,
                    'status'         => $statusCode,
                ]);
                return;
            }

            $outboundMessageId = $messageId ?: (string) Str::uuid();

            $confirmRow = DB::table('ondc_confirm_payloads')->where('transaction_id', $transactionId)->first();
            if (!$confirmRow) {
                Log::error('ONDC_ON_STATUS_CONFIRM_PAYLOAD_NOT_FOUND', ['transaction_id' => $transactionId]);
                return;
            }

            $messageOrder = json_decode($confirmRow->message_order, true) ?? [];

            $orderState = match ($statusCode) {
                'Pending'          => 'Created',
                'Packed'           => 'In-progress',
                'Order-delivered'  => 'Completed',
                default            => 'In-progress',
            };

            // 1. Sync F&B Order Table
            if (($baseContext['domain'] ?? '') === FnbDiscoveryService::DOMAIN) {
                $fnbUpdate = ['fulfillment_state' => $statusCode, 'state' => $orderState];
                if ($statusCode === 'Packed') $fnbUpdate['packed_at'] = now();
                if ($statusCode === 'Order-picked-up') $fnbUpdate['picked_at'] = now();
                if ($statusCode === 'Out-for-delivery') $fnbUpdate['out_for_delivery_at'] = now();
                if ($statusCode === 'Order-delivered') $fnbUpdate['delivered_at'] = now();

                OndcFnbOrder::where('ondc_transaction_id', $transactionId)->update($fnbUpdate);
            }

            // 2. Sync Bagisto Orders Table
            DB::table('orders')->where('increment_id', $transactionId)->update([
                'status'     => $statusCode,
                'updated_at' => now(),
            ]);

            $outboundTimestamp = $this->nextMonotonicTimestamp($transactionId, $baseContext['timestamp'] ?? null);

            $isTrackingActive = in_array($statusCode, ['Packed', 'Agent-assigned', 'Order-picked-up', 'Out-for-delivery'], true);

            if ($isTrackingActive) {
                Cache::put("ondc:tracking_enabled:{$transactionId}", true, now()->addHours(2));
            }
            if ($statusCode === 'Order-delivered') {
                Cache::forget("ondc:tracking_enabled:{$transactionId}");
            }

            $bppUri       = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');
            $trackingBase = rtrim(env('ONDC_TRACKING_URL') ?: ($bppUri . '/tracking'), '/');

            $fulfillments = collect($messageOrder['fulfillments'] ?? [])
                ->map(function ($fulfillment) use ($statusCode, $outboundTimestamp, $isTrackingActive, $trackingBase) {
                    $fulfillment['state']      = ['descriptor' => ['code' => $statusCode]];
                    $fulfillment['tracking']   = $isTrackingActive;
                    $fulfillment['updated_at'] = $outboundTimestamp;
                    $fulfillment['updated_by'] = config('services.ondc.bpp_id');

                    if (in_array($statusCode, ['Order-picked-up', 'Out-for-delivery'], true)) {
                        $fulfillment['start']['time']['timestamp'] = $outboundTimestamp;
                    }
                    if ($statusCode === 'Order-delivered') {
                        $fulfillment['end']['time']['timestamp'] = $outboundTimestamp;
                    }

                    $tags = collect($fulfillment['tags'] ?? [])
                        ->reject(fn($t) => ($t['code'] ?? '') === 'tracking')
                        ->values()
                        ->all();

                    if ($isTrackingActive) {
                        $tags[] = [
                            'code' => 'tracking',
                            'list' => [
                                ['code' => 'gps_enabled', 'value' => 'yes'],
                                ['code' => 'url_enabled', 'value' => 'yes'],
                                ['code' => 'url',         'value' => $trackingBase . '/' . ($fulfillment['id'] ?? 'F1')],
                            ],
                        ];
                    }
                    $fulfillment['tags'] = $tags;

                    return $fulfillment;
                })->values()->all();

            $context = [
                'domain'         => $baseContext['domain']       ?? 'ONDC:RET10',
                'country'        => $baseContext['country']      ?? 'IND',
                'city'           => $baseContext['city']         ?? 'std:080',
                'core_version'   => $baseContext['core_version'] ?? '1.2.0',
                'action'         => 'on_status',
                'bap_id'         => $bapId ?: ($baseContext['bap_id'] ?? ''),
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'transaction_id' => $transactionId,
                'message_id'     => $outboundMessageId,
                'timestamp'      => $outboundTimestamp,
                'ttl'            => 'PT30S',
            ];

            $order = [
                'id'           => $messageOrder['id'] ?? $orderId,
                'state'        => $orderState,
                'provider'     => $messageOrder['provider']   ?? [],
                'items'        => $messageOrder['items']      ?? [],
                'billing'      => $messageOrder['billing']    ?? [],
                'fulfillments' => $fulfillments,
                'quote'        => $messageOrder['quote']      ?? [],
                'payment'      => $messageOrder['payment']    ?? [],
                'created_at'   => $messageOrder['created_at'] ?? $outboundTimestamp,
                'updated_at'   => $outboundTimestamp,
            ];

            $payload     = ['context' => $context, 'message' => ['order' => $order]];
            $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

            $endpoint = [
                'url'     => $bppUri . '/on_status',
                'type'    => 'bpp_internal',
                'headers' => [
                    'Accept'         => 'application/json',
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'        => $bapUri,
                    'bpp_uri'        => $bppUri,
                ],
            ];

            Log::info('[ONDC][STATUS] Dispatching on_status (' . $statusCode . ')', [
                'transaction_id' => $transactionId,
                'domain'         => $context['domain'],
                'message_id'     => $outboundMessageId,
            ]);

            $this->sendToEndpoint($endpoint, $payloadJson, $transactionId, 'status', $payload, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('ONDC_ON_STATUS_FAILED', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
        } finally {
            Cache::forget($concurrencyLockKey);
        }
    }

    protected function sendMerchantInitiatedPartialCancel(
        string $txnId,
        array $orderForReply,
        string $bapUri,
        ?string $bapId,
        array $context
    ): void {
        $items = $orderForReply['items'] ?? [];
        if (empty($items)) {
            Log::warning('[ONDC][MERCHANT-CANCEL] No items to partial-cancel', ['txn_id' => $txnId]);
            return;
        }

        // Pramaan mock expects reason 002 (item unavailable) — the only
        // reason code with applicable_for_part_cancel = 1.
        $target = $items[0];
        $itemId = $target['id'] ?? null;
        if (! $itemId) {
            return;
        }

        $quote = $orderForReply['quote'] ?? [];
        $itemPrice = 0.0;
        foreach ($quote['breakup'] ?? [] as $line) {
            if (($line['@ondc/org/item_id'] ?? '') === $itemId && ($line['@ondc/org/title_type'] ?? '') === 'item') {
                $itemPrice = (float) ($line['item']['price']['value'] ?? $line['price']['value'] ?? 0);
                break;
            }
        }

        $originalQty = (int) data_get($target, 'quantity.count', 1);

        $internalOrder = DB::table('orders')->where('increment_id', $txnId)->first();

        $partialService = app(\Webkul\Ondc\Services\PartialCancellationService::class);

        try {
            $cancellation = $partialService->initiatePartialCancel([
                'ondc_order_id'               => $orderForReply['id'] ?? $txnId,
                'transaction_id'              => $txnId,
                'order_id'                    => $internalOrder->id ?? null,
                'cancellation_reason_id'      => '002',
                'precancel_fulfillment_state' => 'Pending',
                'initiated_by'                => 'SELLER',
                'cancelled_by'                => config('services.ondc.bpp_id'),
                'items'                       => [[
                    'item_id'            => $itemId,
                    'fulfillment_id'     => $target['fulfillment_id'] ?? 'F1',
                    'original_quantity'  => $originalQty,
                    'cancelled_quantity' => $originalQty, // full qty of this line item unavailable
                    'item_price'         => $itemPrice,
                ]],
                'seller_note' => 'Item unavailable at confirmation time',
            ]);
        } catch (\Throwable $e) {
            Log::error('[ONDC][MERCHANT-CANCEL] initiatePartialCancel failed', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
            return;
        }

        // Reflect in Bagisto order_items
        DB::table('order_items')
            ->where('order_id', $internalOrder->id ?? 0)
            ->where(function ($q) use ($itemId) {
                $q->where('sku', $itemId)->orWhere('ondc_item_id', $itemId);
            })
            ->update([
                'qty_canceled' => DB::raw('qty_canceled + ' . $originalQty),
                'updated_at'   => now(),
            ]);

        $payload = $partialService->buildOnUpdatePartialCancelPayload($cancellation, [
            'bap_id'       => $bapId,
            'bap_uri'      => $bapUri,
            'domain'       => $context['domain'] ?? 'ONDC:RET10',
            'city'         => $context['city'] ?? 'std:080',
            'items'        => $orderForReply['items'] ?? [],
            'provider'     => $orderForReply['provider'] ?? [],
            'billing'      => $orderForReply['billing'] ?? [],
            'fulfillments' => $orderForReply['fulfillments'] ?? [],
            'quote'        => $orderForReply['quote'] ?? [],
            'state'        => 'Created',
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $bppUri = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');

        $endpoint = [
            'url'     => $bppUri . '/on_update',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'update', $payload, $bapUri, $bapId);
    }

    private function nextMonotonicTimestamp(string $transactionId, ?string $baseTimestamp = null): string
    {
        $now  = time();
        $baseTs = $baseTimestamp ? strtotime($baseTimestamp) : $now;

        // Enforce: never go backward from server time (prevents stale-base replay pushing outbound past).
        $baseTs = max($baseTs, $now);

        $key  = "ondc:last_timestamp:{$transactionId}";
        $last = Cache::get($key);

        $baseMs = (int) (round($baseTs * 1000));
        $currentMs = $baseMs;
        if ($last !== null) {
            $lastMs = (int) (round((float) $last * 1000));
            // Strictly greater than previously emitted timestamp for this transaction.
            if ($currentMs <= $lastMs) {
                $currentMs = $lastMs + 1;
            }
        }

        $currentSec = (int) floor($currentMs / 1000);
        $currentMsRem = $currentMs % 1000;
        $currentFloat = $currentMs / 1000.0;

        Cache::put($key, $currentFloat, now()->addHours(2));

        return gmdate('Y-m-d\TH:i:s', $currentSec) . '.' . str_pad($currentMsRem, 3, '0', STR_PAD_LEFT) . 'Z';
    }

    public function track(Request $request)
    {
        $trackData = $request->all();

        if (!isset($trackData['context']) || !isset($trackData['message'])) {
            return response()->json(['error' => 'Invalid payload: context or message missing'], 400);
        }

        $ctxt    = $trackData['context'];
        $txnId   = $ctxt['transaction_id'] ?? Str::uuid()->toString();
        $msgId   = $ctxt['message_id'] ?? Str::uuid()->toString();
        $bapUri  = rtrim($ctxt['bap_uri'] ?? '', '/');
        $bapId   = $ctxt['bap_id'] ?? null;
        $orderId = $trackData['message']['order_id'] ?? null;

        if (!$bapUri) {
            return response()->json(['error' => 'bap_uri missing in context'], 400);
        }

        // 1. Send synchronous ACK back to caller
        $this->sendAckAndCloseConnection($ctxt);

        // 2. Dispatch /on_track async callback using the request's exact message_id
        app()->terminating(function () use ($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId) {
            try {
                $this->sendOnTrack($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId);
            } catch (\Throwable $e) {
                Log::error('ON_TRACK_FAILED', ['txn_id' => $txnId, 'message_id' => $msgId, 'error' => $e->getMessage()]);
            }
        });
    }

    protected function sendOnTrack($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId)
    {
        $ctxt = $trackData['context'];
        // $outboundTimestamp = $this->nextMonotonicTimestamp($txnId, $ctxt['timestamp'] ?? null);
        // $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime(now()) + 1);
        $outboundTimestamp = now('UTC')->addSecond()->format('Y-m-d\TH:i:s.v\Z');

        // Build standard context response
        $baseContext = [
            'domain'         => $ctxt['domain'] ?? 'ONDC:RET10',
            'country'        => $ctxt['country'] ?? 'IND',
            'city'           => $ctxt['city'] ?? 'std:080',
            'core_version'   => $ctxt['core_version'] ?? '1.2.0',
            'action'         => 'on_track',
            'transaction_id' => $txnId,
            'message_id'     => $msgId,
            'ttl'            => $ctxt['ttl'] ?? 'PT30S',
            'bap_id'         => $bapId ?: ($ctxt['bap_id'] ?? ''),
            'bap_uri'        => $bapUri,
            'bpp_id'         => config('services.ondc.bpp_id') ?: env('ONDC_BPP_ID', 'retail-seller.softtrust.org'),
            'bpp_uri'        => rtrim(config('services.ondc.bpp_uri') ?: env('ONDC_BPP_URI', 'https://retail-seller.softtrust.org'), '/'),
            'timestamp'      => $outboundTimestamp,
        ];

        // Fetch message_order array if available to read fulfillment details
        $ondcPayload  = OndcConfirmPayload::where('transaction_id', $txnId)->first();
        $messageOrder = $ondcPayload
            ? (is_array($ondcPayload->message_order) ? $ondcPayload->message_order : (json_decode($ondcPayload->message_order, true) ?? []))
            : [];

        $fulfillmentId = $trackData['message']['fulfillment_id'] ?? $messageOrder['fulfillments'][0]['id'] ?? 'F1';

        $bppUri      = rtrim(config('services.ondc.bpp_uri') ?: env('ONDC_BPP_URI', 'https://retail-seller.softtrust.org'), '/');
        $storeGps    = env('ONDC_STORE_GPS', '19.129076,72.825803');
        $trackingUrl = env('ONDC_TRACKING_URL')
            ? rtrim(env('ONDC_TRACKING_URL'), '/') . '/' . ($orderId ?: $txnId)
            : $bppUri . '/tracking/' . ($orderId ?: $txnId);

        $trackingGps = $messageOrder['fulfillments'][0]['start']['location']['gps'] ?? $storeGps;

        // Payload for active tracking response
        $responsePayload = [
            'context' => $baseContext,
            'message' => [
                'tracking' => [
                    'id'       => (string) $fulfillmentId,
                    'status'   => 'active',
                    'url'      => (string) $trackingUrl,
                    'location' => [
                        'gps'        => (string) $trackingGps,
                        'time'       => ['timestamp' => $outboundTimestamp],
                        'updated_at' => $outboundTimestamp,
                    ],
                    'tags' => [
                        [
                            'code' => 'config',
                            'list' => [
                                ['code' => 'attr', 'value' => 'tracking.location.gps'],
                                ['code' => 'type', 'value' => 'live_poll'],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        Log::info('[ONDC][TRACK] Sending on_track', ['txn_id' => $txnId, 'message_id' => $msgId]);

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        $endpoint = [
            'url'     => $bppUri . '/on_track',
            'type'    => 'bpp_internal',
            'headers' => [
                'Accept'         => 'application/json',
                'Content-Type'   => 'application/json',
                'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                'bap_uri'        => $bapUri,
                'bpp_uri'        => $bppUri,
            ],
        ];

        $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'track', $responsePayload, $bapUri, $bapId);
    }
    
    // ──────────────────────────────────────────────────────────────
    // GENERAL DISPATCH (WITH HTTP ROUND-TRIP TIMING)
    // ──────────────────────────────────────────────────────────────
    public function sendToEndpoint(
        $endpoint,
        $payloadJson,
        $txnId,
        $actionType,
        $payload = [],
        $bapUri = null,
        $bapId = null
    ): bool {
        $url     = $endpoint['url'];
        $headers = $endpoint['headers'];
        $bodyToSend = !empty($payload) ? $payload : json_decode($payloadJson, true);

        Log::info('[TIMING][DISPATCH] HTTP POST Started -> ' . $url, [
            'action'     => $actionType,
            'txn_id'     => $txnId,
            'wall_clock' => now()->format('H:i:s.v'),
        ]);

        try {
            $httpStart = microtime(true);

            $response = Http::withOptions(['verify' => false])
                ->withHeaders($headers)
                ->connectTimeout(3)
                ->timeout(5)
                ->post($url, $bodyToSend);

            $httpMs = round((microtime(true) - $httpStart) * 1000, 2);

            Log::info('[TIMING][DISPATCH] HTTP POST Responded', [
                'action'     => $actionType,
                'txn_id'     => $txnId,
                'status'     => $response->status(),
                'http_ms'    => $httpMs,
                'wall_clock' => now()->format('H:i:s.v'),
                'body'       => $response->body(),
            ]);

            $wasAccepted = $response->successful();

            OndcTransaction::create([
                'transaction_id'       => $txnId,
                'action'               => 'on_' . $actionType,
                'domain'               => $bodyToSend['context']['domain'] ?? 'ONDC:RET10',
                'bap_id'               => $bodyToSend['context']['bap_id'] ?? $bapId,
                'bap_uri'              => $bodyToSend['context']['bap_uri'] ?? $bapUri,
                'bpp_id'               => config('services.ondc.bpp_id'),
                'status'               => $wasAccepted ? 'ack_received' : 'rejected',
                'request_payload'      => $bodyToSend,
                'response_payload'     => $response->json(),
                'response_status_code' => $response->status(),
                'sent_at'              => now(),
                'ack_received_at'      => $wasAccepted ? now() : null,
            ]);

            return $wasAccepted;
        } catch (\Throwable $e) {
            Log::error('[TIMING][DISPATCH] HTTP POST FAILED', [
                'action'  => $actionType,
                'txn_id'  => $txnId,
                'url'     => $url,
                'error'   => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action'         => 'on_' . $actionType,
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function alreadySentCallback(string $txnId, string $actionType, ?string $messageId = null): bool
    {
        return OndcTransaction::where('transaction_id', $txnId)
            ->where('action', 'on_' . $actionType)
            ->whereIn('status', ['ack_received', 'sent'])
            ->where('created_at', '>=', now()->subMinutes(2))
            ->exists();
    }

    public function callback(Request $request)
    {
        $payload = $request->all();
        $txnId = $payload['context']['transaction_id'] ?? null;
        $action = $payload['context']['action'] ?? null;

        if (!$txnId) {
            return response()->json(['error' => 'transaction_id missing in context'], 400);
        }

        $originalAction = str_replace('on_', '', $action);
        $transaction = OndcTransaction::where('transaction_id', $txnId)
            ->where('action', $originalAction)
            ->latest('created_at')
            ->first();

        if ($transaction) {
            $transaction->update([
                'callback_received_at' => now(),
                'response_payload'     => array_merge($transaction->response_payload ?? [], $payload),
                'status'               => 'completed',
            ]);
        }

        return response()->json(['message' => ['ack' => ['status' => 'ACK']]]);
    }

    protected function buildPayload($context, $bapUri, $bapId)
    {
        return [
            'context' => [
                'domain'         => $context['domain'] ?? 'ONDC:RET10',
                'country'        => $context['country'] ?? 'IND',
                'city'           => preg_match('/^(std:\d{3,5}|\*)$/', $context['city'] ?? '') ? $context['city'] : 'std:011',
                'core_version'   => $context['core_version'] ?? '1.2.0',
                'action'         => 'on_search',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => config('services.ondc.bpp_id'),
                'bpp_uri'        => config('services.ondc.bpp_uri'),
                'transaction_id' => $context['transaction_id'],
                'message_id'     => $context['message_id'],
                'timestamp'      => OndcHelper::timestamp($context['timestamp'] ?? null),
                'ttl'            => 'PT30S',
            ],
            'message' => [
                'catalog' => $this->buildFullCatalog($context),
            ],
        ];
    }

    protected function buildFullCatalog($context)
    {
        $timestamp = OndcHelper::timestamp();
        $requestedDomain = $context['domain'] ?? config('ondc.default_domain', 'ONDC:RET10');
        $products = $this->getAllActiveProducts($requestedDomain);

        return [
            'bpp/fulfillments' => [
                ['id' => 'F1', 'type' => 'Delivery'],
            ],
            'bpp/descriptor' => [
                'name'       => config('ondc.bpp.name', 'Your BPP Name'),
                'symbol'     => config('ondc.bpp.symbol', 'https://your-domain.com/logo.png'),
                'short_desc' => 'Seller Marketplace',
                'long_desc'  => 'Seller Marketplace',
                'images'     => [config('ondc.bpp.symbol', 'https://your-domain.com/logo.png')],
                'tags'       => [
                    [
                        'code' => 'bpp_terms',
                        'list' => [
                            ['code' => 'np_type', 'value' => 'MSN'],
                            ['code' => 'accept_bap_terms', 'value' => 'Y'],
                            ['code' => 'collect_payment', 'value' => 'N'],
                        ],
                    ],
                ],
            ],
            'bpp/providers' => $this->buildProviders($products, $timestamp, $context),
        ];
    }

    protected function getAllActiveProducts(?string $requestedDomain = null)
    {
        $cacheKey = 'ondc:catalog_products:' . ($requestedDomain ?? 'default');

        return Cache::remember($cacheKey, 600, function () use ($requestedDomain) {
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

                    $image = $product->base_image_url ?: 'https://your-domain.com/images/placeholder.png';
                    $categoryName = DB::table('product_categories')
                        ->join('category_translations', 'product_categories.category_id', '=', 'category_translations.category_id')
                        ->where('product_categories.product_id', $product->id)
                        ->where('category_translations.locale', app()->getLocale())
                        ->value('name') ?? 'General';

                    $resolvedDomain = $this->domainResolver->resolve($categoryName);
                    if ($requestedDomain && (!$resolvedDomain || $resolvedDomain !== $requestedDomain)) {
                        continue;
                    }

                    $today = now()->toDateString();
                    $actualPrice = (float) $productFlat->price;

                    if (
                        !empty($productFlat->special_price)
                        && (empty($productFlat->special_price_from) || $productFlat->special_price_from <= $today)
                        && (empty($productFlat->special_price_to) || $productFlat->special_price_to >= $today)
                    ) {
                        $actualPrice = (float) $productFlat->special_price;
                    }

                    $result[] = [
                        'id'                => 'I' . $product->id,
                        'name'              => (string) ($productFlat->name ?? 'Product'),
                        'sku'               => (string) ($productFlat->sku ?? ''),
                        'image'             => (string) $image,
                        'images'            => [(string) $image],
                        'short_description' => (string) Str::limit($productFlat->description ?? $productFlat->name ?? 'Product', 100),
                        'description'       => (string) ($productFlat->description ?? $productFlat->name ?? 'Product'),
                        'price'             => $actualPrice,
                        'unit'              => 'kilogram',
                        'unit_value'        => 1,
                        'stock'             => 99,
                        'category_name'     => (string) $categoryName,
                        'meta_keywords'     => (string) ($productFlat->meta_keywords ?? ''),
                        'has_variants'      => false,
                        'returnable'        => true,
                        'cancellable'       => true,
                        'return_window'     => 'P7D',
                        'time_to_ship'      => 'PT24H',
                        'origin_country'    => 'IND',
                    ];
                }

                return $result;
            } catch (\Exception $e) {
                return [];
            }
        });
    }

    protected function buildProviders($products, $timestamp, $context)
    {
        $storeId = 'P1';
        $locationId = 'L1';
        $fulfillmentId = 'F1';

        $groupedByCategory = $this->groupProductsByCategory($products);

        $items = [];
        $categoryEntries = [];
        $categoryIds = [];

        foreach ($groupedByCategory as $categoryName => $categoryProducts) {
            foreach ($categoryProducts as $product) {
                $itemId = $product['id'];
                $domain = $this->domainResolver->resolve($product['category_name'] ?? '') ?: config('ondc.default_domain', 'ONDC:RET10');
                $ondcCategory = $this->categoryMapper->map($domain, $product['name'] ?? '', $product['category_name'] ?? '', null, $product['description'] ?? '', $product['meta_keywords'] ?? '') ?: 'Ready to Cook and Eat';

                if (!in_array($ondcCategory, $categoryIds, true)) {
                    $categoryIds[] = $ondcCategory;
                }

                if (!isset($categoryEntries[$ondcCategory])) {
                    $categoryEntries[$ondcCategory] = [
                        'id'         => self::ONDC_RET10_CATEGORY_MAP[$ondcCategory] ?? 'CAT027',
                        'descriptor' => ['name' => $ondcCategory],
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

                $items[] = [
                    'id' => $itemId,
                    'descriptor' => [
                        'name'       => $product['name'],
                        'code'       => !empty($product['sku']) ? ('1:' . $product['sku']) : '',
                        'symbol'     => $product['image'] ?? '',
                        'short_desc' => $product['short_description'] ?? $product['name'],
                        'long_desc'  => $product['description'] ?? $product['name'],
                        'images'     => $product['images'] ?? [$product['image'] ?? ''],
                    ],
                    'price' => [
                        'currency'      => 'INR',
                        'value'         => number_format((float) $product['price'], 2, '.', ''),
                        'maximum_value' => number_format((float) $product['price'], 2, '.', ''),
                    ],
                    'quantity' => [
                        'unitized' => [
                            'measure' => [
                                'unit'  => $product['unit'] ?? 'kilogram',
                                'value' => (string) ($product['unit_value'] ?? 1),
                            ],
                        ],
                        'available' => ['count' => '99'],
                        'maximum'   => ['count' => '99'],
                    ],
                    'category_id'                             => $ondcCategory,
                    'fulfillment_id'                          => $fulfillmentId,
                    'location_id'                             => $locationId,
                    '@ondc/org/returnable'                    => $product['returnable'] ?? true,
                    '@ondc/org/cancellable'                   => $product['cancellable'] ?? true,
                    '@ondc/org/return_window'                 => 'P7D',
                    '@ondc/org/seller_pickup_return'          => false,
                    '@ondc/org/time_to_ship'                  => 'PT24H',
                    '@ondc/org/available_on_cod'              => false,
                    '@ondc/org/contact_details_consumer_care' => $this->getConsumerCareString(),
                    '@ondc/org/statutory_reqs_packaged_commodities' => [
                        'manufacturer_or_packer_name'              => 'Your Company',
                        'manufacturer_or_packer_address'           => 'Your Address',
                        'common_or_generic_name_of_commodity'      => $product['name'],
                        'month_year_of_manufacture_packing_import' => date('m/Y'),
                    ],
                    'time' => [
                        'label'     => 'enable',
                        'timestamp' => $timestamp,
                    ],
                    'tags' => [
                        [
                            'code' => 'origin',
                            'list' => [
                                ['code' => 'country', 'value' => 'IND'],
                            ],
                        ],
                    ],
                ];
            }
        }

        return [
            [
                'id' => $storeId,
                'descriptor' => [
                    'name'       => config('ondc.store.name', 'Store 1'),
                    'symbol'     => config('ondc.store.symbol', 'https://your-domain.com/store-logo.png'),
                    'short_desc' => 'Store 1',
                    'long_desc'  => 'Store 1',
                    'images'     => [config('ondc.store.symbol', 'https://your-domain.com/store-logo.png')],
                ],
                'time' => [
                    'label'     => 'enable',
                    'timestamp' => $timestamp,
                ],
                'ttl' => 'P1D',
                'locations' => [
                    [
                        'id'   => $locationId,
                        'time' => [
                            'label'     => 'enable',
                            'timestamp' => $timestamp,
                            'days'      => '1,2,3,4,5,6,7',
                            'schedule'  => [
                                'holidays'  => ['2026-05-01'],
                                'frequency' => 'PT4H',
                                'times'     => ['0000', '0400', '0800', '1200', '1600', '2000'],
                            ],
                            'range' => [
                                'start' => '0000',
                                'end'   => '2359',
                            ],
                        ],
                        'gps' => config('ondc.store.gps', '21.145800,79.088200'),
                        'address' => [
                            'street'    => config('ondc.store.address.street', 'Your Street'),
                            'locality'  => config('ondc.store.address.locality', 'Your Locality'),
                            'city'      => config('ondc.store.address.city', 'Nagpur'),
                            'state'     => config('ondc.store.address.state', 'Maharashtra'),
                            'area_code' => config('ondc.store.address.area_code', '440001'),
                        ],
                        'circle' => [
                            'gps' => config('ondc.store.gps', '21.145800,79.088200'),
                            'radius' => [
                                'unit'  => 'km',
                                'value' => '10',
                            ],
                        ],
                    ],
                ],
                'fulfillments' => [
                    [
                        'id'      => $fulfillmentId,
                        'type'    => 'Delivery',
                        'contact' => [
                            'phone' => config('ondc.store.phone', '9876543210'),
                            'email' => config('ondc.store.email', 'support@yourstore.com'),
                        ],
                    ],
                ],
                'items'      => $items,
                'categories' => array_values($categoryEntries),
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

    // Default protocol fallbacks
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
        return response()->json(['message' => ['ack' => ['status' => 'ACK']]], 200);
    }
}
