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

class ApiController extends Controller
{
    protected DomainResolver $domainResolver;
    protected CategoryMapper $categoryMapper;
    protected FnbDiscoveryService $fnbDiscovery;

    public function __construct(
        DomainResolver $domainResolver,
        CategoryMapper $categoryMapper,
        FnbDiscoveryService $fnbDiscovery
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
        $txnId = $context['transaction_id'] ?? null;

        if (!$txnId) {
            return response()->json(['error' => 'transaction_id missing in context'], 400);
        }

        $bapUri = rtrim($context['bap_uri'] ?? '', '/');
        $bapId = $context['bap_id'] ?? null;

        if (!$bapUri) {
            return response()->json(['error' => 'bap_uri missing in context'], 400);
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

        $this->sendAckAndCloseConnection($context);

        try {
            $this->sendOnSearch($searchData, $txnId, $bapUri, $bapId);
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

        $this->dispatchCallback('on_search', $payload, $txnId, $bapUri, $bapId);
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

        if ($this->alreadySentCallback($txnId, 'select', $messageId)) {
            return;
        }

        $payload = $this->buildOnSelectPayload($context, $message, $bapUri, $bapId);
        if (empty($payload['context'])) {
            return;
        }

        $this->dispatchCallback('on_select', $payload, $txnId, $bapUri, $bapId);
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

        return [
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
                        'breakup' => array_values($quoteBreakup),
                        'ttl'     => 'P1D',
                    ],
                ],
            ],
        ];
    }

    protected function validateAndBuildItems($items, $fulfillmentId = 'F1')
    {
        $validatedItems = [];

        foreach ($items as $item) {
            $itemId = $item['id'] ?? null;
            $quantity = (int) ($item['quantity']['count'] ?? 1);

            if (!$itemId) {
                continue;
            }

            $productId = (int) str_replace('I', '', $itemId);
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
                    'is_available'   => false,
                ];
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

            $validatedItems[] = [
                'id'             => $itemId,
                'fulfillment_id' => $fulfillmentId,
                'name'           => $productFlat->name ?? 'Product',
                'price'          => $actualPrice,
                'quantity'       => $quantity,
                'available_qty'  => 99,
                'is_available'   => true,
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

            $quantity = (int) ($item['quantity'] ?? 1);
            $price = (float) ($item['price'] ?? 0.0);
            $itemName = $item['name'] ?? 'Item';
            $itemPrice = $price * $quantity;

            $breakup[] = [
                '@ondc/org/item_id'       => (string) $itemId,
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
                        'available' => ['count' => (string) ($item['available_qty'] ?? 99)],
                        'maximum'   => ['count' => (string) ($item['available_qty'] ?? 99)],
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($price, 2, '.', ''),
                    ],
                ],
            ];
        }

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

        // Return ACK and close socket so ngrok is free for confirm
        $this->sendAckAndCloseConnection($context);

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

        exit;
    }

    protected function sendOnInit($initData, $txnId, $bapUri, $bapId)
    {
        $responsePayload = $this->buildOnInitPayload($initData, $txnId, $bapUri, $bapId);
        $msgId = $responsePayload['context']['message_id'] ?? null;

        $lockKey = "ondc:lock:on_init:{$txnId}:{$msgId}";
        if (!Cache::add($lockKey, true, 60)) {
            return;
        }

        if ($this->alreadySentCallback($txnId, 'init', $msgId)) {
            return;
        }

        $this->dispatchCallback('on_init', $responsePayload, $txnId, $bapUri, $bapId);
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
                'tracking'                => true,
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

        $context   = $confirmData['context'];
        $txnId     = $context['transaction_id'] ?? null;
        $bapUri    = rtrim($context['bap_uri'] ?? '', '/');
        $bapId     = $context['bap_id'] ?? null;

        if (!$txnId || !$bapUri) {
            return response()->json(['error' => 'Required context fields missing'], 400);
        }

        // Return ACK and close socket immediately
        $this->sendAckAndCloseConnection($context);

        $lockKey = "ondc:lock:confirm:{$txnId}";
        if (!Cache::add($lockKey, true, 30)) {
            exit;
        }

        try {
            Cache::put("ondc:txn:{$txnId}", [
                'bap_uri'          => $bapUri,
                'bap_id'           => $bapId,
                'original_request' => $confirmData,
                'action'           => 'confirm',
            ], now()->addMinutes(30));

            $this->processConfirmSync($confirmData, $txnId, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('CONFIRM_SYNC_PROCESS_FAILED', ['error' => $e->getMessage()]);
        }

        exit;
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

        Log::info('[TIMING][CONFIRM] 2.1 Starting on_confirm dispatch directly to BAP', [
            'build_ms'        => $buildMs,
            'save_payload_ms' => $savePayloadMs,
            'wall_clock'      => now()->format('H:i:s.v'),
        ]);

        $tBeforeHttp = microtime(true);

        $onConfirmAcked = $this->dispatchCallback('on_confirm', $responsePayload, $txnId, $bapUri, $bapId);

        $httpDispatchMs = round((microtime(true) - $tBeforeHttp) * 1000, 2);

        if ($onConfirmAcked) {
            // 1. Authorize the cancellation gate
            Cache::put("ondc:on_confirm_acked:{$txnId}", true, now()->addMinutes(10));
            Log::info('[ONDC][CONFIRM] on_confirm received ACK from Workbench. Cancel gateway is now open.', [
                'txn_id' => $txnId,
            ]);

            // 2. Automatically dispatch unsolicited on_status (Pending) for Card 7
            try {
                $this->sendOnStatus(
                    $txnId,
                    $orderForReply['id'] ?? $txnId,
                    'Pending',
                    $bapUri,
                    $bapId,
                    $context
                );
            } catch (\Throwable $e) {
                Log::error('[ONDC][STATUS] Failed to dispatch automatic on_status (Pending)', [
                    'txn_id' => $txnId,
                    'error'  => $e->getMessage(),
                ]);
            }

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
        // Prevent duplicate order creation if confirm was replayed
        if (DB::table('orders')->where('increment_id', $txnId)->exists()) {
            Log::info('[BAGISTO][ORDER] Order already exists for transaction', ['txn_id' => $txnId]);
            return;
        }

        $order       = $confirmData['message']['order'] ?? [];
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

            // Items
            foreach ($orderItems as $item) {
                $rawId   = $item['id'] ?? null;
                $dbId    = (int) preg_replace('/^I/', '', $rawId);
                $product = Product::find($dbId);

                if (! $product) {
                    continue;
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

                $unitPrice = $qty > 0 && $itemPriceValue > 0 ? ($itemPriceValue / $qty) : (float) $product->price;
                $total     = $unitPrice * $qty;

                OrderItem::create([
                    'order_id'            => $bagistoOrder->id,
                    'product_id'          => $product->id,
                    'product_name'        => $product->name,
                    'product_type'        => $product->type ?? 'simple',
                    'sku'                 => $product->sku,
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

            // Link Bagisto order ID to confirm snapshot
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
            $withStart['tracking']                = $f['tracking'] ?? true;
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

        $order = DB::table('orders')->where('increment_id', $txnId)->first();
        $currentStatus = 'Pending';

        if ($order) {
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

        $this->sendAckAndCloseConnection($context);

        try {
            $this->sendOnStatus($txnId, $orderId, $currentStatus, $bapUri, $bapId, $context, $msgId);
        } catch (\Throwable $e) {
            Log::error('SEND_ON_STATUS_EXCEPTION', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }

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
        try {
            // Cancel Guard: Drop Pending if order was cancelled
            $isCancelled = Cache::get("ondc:order_state:{$transactionId}") === 'Cancelled'
                || DB::table('ondc_order_cancellations')->where('ondc_transaction_id', $transactionId)->exists();

            if ($isCancelled && in_array($statusCode, ['Pending', 'Created', 'Accepted'], true)) {
                Log::info('ON_STATUS_PENDING_DROPPED_ALREADY_CANCELLED', [
                    'transaction_id' => $transactionId,
                    'order_id'       => $orderId,
                ]);
                return;
            }

            // Unsolicited status must have its own fresh message_id
            $outboundMessageId = $messageId ?: (string) Str::uuid();

            $confirmRow = DB::table('ondc_confirm_payloads')
                ->where('transaction_id', $transactionId)
                ->first();

            if (!$confirmRow) {
                Log::error('ONDC_ON_STATUS_CONFIRM_PAYLOAD_NOT_FOUND', ['transaction_id' => $transactionId]);
                return;
            }

            $messageOrder = json_decode($confirmRow->message_order, true) ?? [];
            $orderState   = ($statusCode === 'Order-delivered') ? 'Completed' : 'In-progress';

            // Monotonic timestamp strictly 1 second ahead of baseContext
            $baseTimestamp     = $baseContext['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
            $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($baseTimestamp) + 1);

            $fulfillments = collect($messageOrder['fulfillments'] ?? [])->map(function ($fulfillment) use ($statusCode, $outboundTimestamp) {
                $fulfillment['state'] = [
                    'descriptor' => ['code' => $statusCode],
                ];
                $fulfillment['updated_at'] = $outboundTimestamp;
                $fulfillment['updated_by'] = config('services.ondc.bpp_id');
                return $fulfillment;
            })->values()->all();

            $context = [
                'domain'         => $baseContext['domain'] ?? 'ONDC:RET10',
                'country'        => $baseContext['country'] ?? 'IND',
                'city'           => $baseContext['city'] ?? 'std:080',
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
                'provider'     => $messageOrder['provider'] ?? [],
                'items'        => $messageOrder['items'] ?? [],
                'billing'      => $messageOrder['billing'] ?? [],
                'fulfillments' => $fulfillments,
                'quote'        => $messageOrder['quote'] ?? [],
                'payment'      => $messageOrder['payment'] ?? [],
                'created_at'   => $messageOrder['created_at'] ?? $outboundTimestamp,
                'updated_at'   => $outboundTimestamp,
            ];

            $payload = [
                'context' => $context,
                'message' => ['order' => $order],
            ];

            Log::info('[ONDC][STATUS] Dispatching on_status (' . $statusCode . ')', [
                'transaction_id' => $transactionId,
                'message_id'     => $outboundMessageId,
            ]);

            $this->dispatchCallback('on_status', $payload, $transactionId, $bapUri, $bapId);
        } catch (\Throwable $e) {
            Log::error('ONDC_ON_STATUS_FAILED', [
                'transaction_id' => $transactionId,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // TRACK
    // ──────────────────────────────────────────────────────────────

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

        // 1. Immediately return HTTP ACK to release the socket
        $this->sendAckAndCloseConnection($ctxt);

        // 2. Lock check strictly for this exact message_id
        $lockKey = "ondc:lock:track:{$txnId}:{$msgId}";
        if (!Cache::add($lockKey, true, 30)) {
            exit;
        }

        // 3. Dispatch on_track synchronously to satisfy Workbench Card 11
        try {
            $this->sendOnTrack($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId);
        } catch (\Throwable $e) {
            Log::error('ON_TRACK_FAILED', [
                'transaction_id' => $txnId,
                'error'          => $e->getMessage(),
                'trace'          => $e->getTraceAsString(),
            ]);
        }

        exit;
    }

   protected function sendOnTrack($trackData, $txnId, $msgId, $bapUri, $bapId, $orderId)
    {
        $ctxt = $trackData['context'];

        // Monotonic timestamp: strictly 1 second ahead of inbound /track
        $inboundTimestamp  = $ctxt['timestamp'] ?? gmdate('Y-m-d\TH:i:s.v\Z');
        $outboundTimestamp = gmdate('Y-m-d\TH:i:s.v\Z', strtotime($inboundTimestamp) + 1);

        $trackingGps = env('ONDC_STORE_GPS', '19.129076,72.825803');
        $trackingUrl = env('ONDC_TRACKING_URL', rtrim(config('services.ondc.bpp_uri'), '/') . '/tracking/' . $orderId);

        $ondcPayload  = OndcConfirmPayload::where('transaction_id', $txnId)->first();
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

        $responsePayload = [
            'context' => [
                'domain'         => $ctxt['domain'] ?? 'ONDC:RET10',
                'country'        => $ctxt['country'] ?? 'IND',
                'city'           => $ctxt['city'] ?? 'std:080',
                'core_version'   => $ctxt['core_version'] ?? '1.2.0',
                'action'         => 'on_track',
                'transaction_id' => $txnId,
                'message_id'     => $msgId, // Strict match for this /track request slot
                'ttl'            => $ctxt['ttl'] ?? 'PT30S',
                'bap_id'         => $bapId ?: ($ctxt['bap_id'] ?? ''),
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
                        'time'       => ['timestamp' => $outboundTimestamp],
                        'updated_at' => $outboundTimestamp,
                    ],
                ],
            ],
        ];

        Log::info('[ONDC][TRACK] Dispatching on_track directly to BAP', [
            'transaction_id' => $txnId,
            'message_id'     => $msgId,
            'timestamp'      => $outboundTimestamp,
        ]);

        $this->dispatchCallback('on_track', $responsePayload, $txnId, $bapUri, $bapId);
    }

    // ──────────────────────────────────────────────────────────────
    // CANONICAL SWITCH DISPATCHER
    //
    // ALL on_* callbacks MUST go through the ONDC Switch
    // (retail-seller.softtrust.org), never directly to bap_uri.
    //
    // Flow: Your Server → Switch (signs + validates) → BAP
    //
    // The Switch needs three things to forward correctly:
    //   1. X-Internal-Key  — authenticates your server to the Switch
    //   2. bap_uri header  — tells the Switch WHERE to forward
    //   3. bpp_uri header  — tells the Switch WHO is sending
    // ──────────────────────────────────────────────────────────────

    public function dispatchCallback(
        string  $action,    // e.g. 'on_confirm', 'on_track'
        array   $payload,
        string  $txnId,
        string  $bapUri,    // Switch reads this to forward to correct BAP
        ?string $bapId = null
    ): bool {
        $switchBase = rtrim(env('ONDC_BPP_URI', config('services.ondc.bpp_uri')), '/');
        $url        = $switchBase . '/' . $action;   // → Switch endpoint, NOT bap_uri
        $tStart     = microtime(true);

        Log::info('[ONDC][SWITCH] Dispatching ' . strtoupper($action), [
            'switch_url' => $url,
            'forward_to' => $bapUri,
            'txn_id'     => $txnId,
            'wall_clock' => now()->format('H:i:s.v'),
        ]);

        try {
            $response = Http::withOptions(['verify' => false])
                ->withHeaders([
                    'Content-Type'   => 'application/json',
                    'Accept'         => 'application/json',
                    // Auth: your server → Switch
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    // Routing: Switch → BAP
                    'bap_uri'        => rtrim($bapUri, '/'),
                    'bpp_uri'        => $switchBase,
                ])
                ->connectTimeout(3)
                ->timeout(8)
                ->post($url, $payload);

            $httpMs      = round((microtime(true) - $tStart) * 1000, 2);
            $wasAccepted = $response->successful();

            Log::info('[ONDC][SWITCH] ' . strtoupper($action) . ' response', [
                'status'     => $response->status(),
                'http_ms'    => $httpMs,
                'txn_id'     => $txnId,
                'wall_clock' => now()->format('H:i:s.v'),
            ]);

            OndcTransaction::create([
                'transaction_id'       => $txnId,
                'action'               => $action,
                'domain'               => $payload['context']['domain'] ?? 'ONDC:RET10',
                'bap_id'               => $payload['context']['bap_id'] ?? $bapId,
                'bap_uri'              => $bapUri,
                'bpp_id'               => config('services.ondc.bpp_id'),
                'status'               => $wasAccepted ? 'ack_received' : 'rejected',
                'request_payload'      => $payload,
                'response_payload'     => $response->json(),
                'response_status_code' => $response->status(),
                'sent_at'              => now(),
                'ack_received_at'      => $wasAccepted ? now() : null,
            ]);

            return $wasAccepted;

        } catch (\Throwable $e) {
            Log::error('[ONDC][SWITCH] ' . strtoupper($action) . ' FAILED', [
                'txn_id' => $txnId,
                'url'    => $url,
                'error'  => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action'         => $action,
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
            ]);

            return false;
        }
    }

    // ──────────────────────────────────────────────────────────────
    // LEGACY WRAPPER — CancellationController calls this externally.
    // Delegates to dispatchCallback so all traffic routes correctly
    // through the Switch with consistent headers.
    // ──────────────────────────────────────────────────────────────

    public function sendToEndpoint(
        $endpoint,
        $payloadJson,
        $txnId,
        $actionType,
        $payload = [],
        $bapUri  = null,
        $bapId   = null
    ): bool {
        // bap_uri in the header tells dispatchCallback where Switch should forward
        $resolvedBapUri = rtrim(
            $endpoint['headers']['bap_uri'] ?? $bapUri ?? '',
            '/'
        );
        $bodyToSend = !empty($payload) ? $payload : json_decode($payloadJson, true);

        return $this->dispatchCallback(
            'on_' . $actionType,
            $bodyToSend,
            $txnId,
            $resolvedBapUri,
            $bapId
        );
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
    public function onSearch($payload)  { return $this->ack(); }
    public function onSelect($payload)  { return $this->ack(); }
    public function onInit($payload)    { return $this->ack(); }
    public function onConfirm($payload) { return $this->ack(); }
    public function onStatus($payload)  { return $this->ack(); }
    public function onCancel($payload)  { return $this->ack(); }
    public function onUpdate($payload)  { return $this->ack(); }

    protected function ack()
    {
        return response()->json(['message' => ['ack' => ['status' => 'ACK']]], 200);
    }
}