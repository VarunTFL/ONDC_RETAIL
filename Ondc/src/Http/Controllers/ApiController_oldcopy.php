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
use Webkul\Ondc\Helpers\OndcSigner;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Checkout\Models\Cart;
use Webkul\Ondc\Models\OndcProduct;
use Webkul\Sales\Models\Order;
use Webkul\Sales\Models\OrderItem;
use Webkul\Sales\Models\OrderAddress;
use Webkul\Sales\Models\OrderPayment;
use Firebase\JWT\JWT;

/**
 * ========================================================================================
 * ONDC API CONTROLLER - WORKING FLOW DOCUMENTATION
 * ========================================================================================
 *
 * Hello Developer! Welcome to the ONDC (Open Network for Digital Commerce) API Controller.
 * This file handles all the communication between our e-commerce platform and the ONDC network.
 * Think of it as the "bridge" that lets buyers discover and buy products from our store through ONDC.
 *
 * IMPORTANT: This is NOT a complete implementation yet. We're building it step by step.
 * More features (like confirm, status, cancel) will be added later.
 *
 * ========================================================================================
 * HOW ONDC WORKS (SIMPLE EXPLANATION)
 * ========================================================================================
 *
 * ONDC is like a big marketplace where different sellers (like us) connect to buyers through
 * a common network. Buyers use apps/gateways to search for products, and we respond with our catalog.
 *
 * Key players:
 * - BAP (Buyer App Platform): The app/website where buyers search (like a shopping app)
 * - BPP (Buyer Provider Platform): Our store! We provide products and handle orders
 * - Gateway: The middleman that routes messages between BAP and BPP
 *
 * ========================================================================================
 * THE FLOW: HOW A BUYER FINDS AND BUYS FROM OUR STORE
 * ========================================================================================
 *
 * 1. BUYER SEARCHES (search() method)
 *    - Buyer opens their app and searches for "atta" or "rice"
 *    - Their app (BAP) sends a "search" request to our API
 *    - We receive it, validate it, and immediately send back an "ACK" (acknowledgment)
 *    - In the background, we build our full product catalog and send it back as "on_search"
 *
 * 2. BUYER SELECTS PRODUCTS (select() method)
 *    - Buyer picks items from our catalog and clicks "Add to Cart"
 *    - BAP sends a "select" request with chosen items
 *    - We ACK immediately, then process the selection
 *    - We send back "on_select" with pricing, availability, and delivery details
 *
 * 3. BUYER CONFIRMS ORDER (Future: confirm() method)
 *    - Buyer reviews and confirms the order
 *    - BAP sends "confirm", we send back "on_confirm"
 *    - Order is created in our system
 *
 * 4. ORDER TRACKING (Future: status() method)
 *    - Buyer can check order status
 *    - BAP sends "status", we send back "on_status"
 *
 * ========================================================================================
 * WHAT EACH METHOD DOES (STEP BY STEP)
 * ========================================================================================
 *
 * index() - Dashboard for Testing
 *   - Just generates crypto keys for testing ONDC signatures
 *   - Not part of the main flow
 *
 * search(Request $request) - When Buyer Searches
 *   1. Receives search request from BAP
 *   2. Logs the request for debugging
 *   3. Validates the data (checks for required fields)
 *   4. Stores BAP details in cache (for 30 minutes)
 *   5. Saves the search event to database
 *   6. Sends immediate ACK response
 *   7. In background: calls sendOnSearch() to build and send catalog
 *
 * sendOnSearch() - Builds and Sends Our Product Catalog
 *   1. Checks if we have the private key for signing (security)
 *   2. Builds the full catalog payload (all our products)
 *   3. Signs the payload with our private key
 *   4. Sends to multiple endpoints (BAP directly + our internal BPP)
 *   5. Logs success/failure
 *
 * checkOnSearchStatus() - Admin Tool to Check Search Status
 *   - Lets admins see if search requests were processed
 *   - Shows timeline: received → sent → callback received
 *
 * checkTransactionStatus() - Admin Tool for Any Transaction
 *   - Shows latest status for any transaction ID
 *   - Useful for debugging issues
 *
 * listTransactions() - Admin Tool to List All Transactions
 *   - Shows all ONDC events with filtering by status/action
 *
 * sendToEndpoint() - Helper to Send Signed Payloads
 *   - Sends HTTP requests to BAP or internal endpoints
 *   - Handles success, timeout, and errors
 *   - Updates database with results
 *
 * buildPayload() - Creates the "on_search" Response
 *   1. Sets up context (who we are, what action)
 *   2. Calls buildFullCatalog() for products
 *   3. Returns complete ONDC-compliant payload
 *
 * buildFullCatalog() - Our Store's Complete Catalog
 *   1. Gets all active products from database
 *   2. Organizes them by category
 *   3. Returns in ONDC format with store details
 *
 * buildProviders() - Formats Products for ONDC
 *   1. Groups products by category
 *   2. Creates provider structure (our store)
 *   3. Includes locations, fulfillments, items
 *
 * getAllActiveProducts() - Fetches Products from Database
 *   1. Queries ProductFlat for performance
 *   2. Gets product details, images, stock, categories
 *   3. Falls back to sample data if no products found
 *
 * getSampleProducts() - Dummy Products for Testing
 *   - Returns hardcoded products when database is empty
 *
 * groupProductsByCategory() - Organizes Products
 *   - Groups products by their category name
 *
 * getConsumerCareString() - Support Contact Info
 *   - Returns formatted customer care details
 *
 * fetchOndcResponse() - Admin Tool to Get Responses
 *   - Manually fetches responses from gateway for testing
 *
 * select(Request $request) - When Buyer Selects Items
 *   1. Receives select request with chosen products
 *   2. Validates and extracts BAP details
 *   3. Saves select event to database
 *   4. Sends ACK immediately
 *   5. In background: calls sendOnSelect()
 *
 * sendOnSelect() - Processes Selection and Sends Quote
 *   1. Validates selected items against our catalog
 *   2. Calculates pricing and availability
 *   3. Builds quote with delivery charges
 *   4. Signs and sends "on_select" response
 *
 * buildOnSelectPayload() - Creates Selection Response
 *   1. Validates items and gets real prices
 *   2. Builds quote with item prices + delivery
 *   3. Returns complete ONDC payload
 *
 * buildQuoteBreakup() - Calculates Total Price
 *   - Adds up all item prices + delivery charges
 *
 * validateAndBuildItems() - Checks Selected Products
 *   1. Verifies items exist in our catalog
 *   2. Checks stock availability
 *   3. Returns formatted items with prices
 *
 * callback(Request $request) - Handles All "on_*" Responses
 *   1. Receives callbacks from BAP (on_search, on_select, etc.)
 *   2. Validates the callback
 *   3. Saves the event to database
 *   4. Sends ACK back
 *
 * ========================================================================================
 * DATABASE TABLES USED
 * ========================================================================================
 *
 * ond_api_logs - Every API call (inbound/outbound) gets logged here
 * ond_transactions - Every ONDC event (search, select, etc.) gets recorded here
 *
 * ========================================================================================
 * IMPORTANT NOTES FOR DEVELOPERS
 * ========================================================================================
 *
 * - All responses must be signed with our private key for security
 * - We must respond within 4 seconds for ACKs, but processing can take longer
 * - Use the admin tools (checkOnSearchStatus, etc.) to debug issues
 * - Cache is used to store BAP details temporarily
 * - Logs are crucial for troubleshooting production issues
 * - This implements ONDC RET14 v1.2.5 specification
 *
 * ========================================================================================
 * WHAT'S MISSING (TO BE ADDED LATER)
 * ========================================================================================
 *
 * - Order confirmation (confirm/on_confirm)
 * - Order status tracking (status/on_status)
 * - Order cancellation (cancel/on_cancel)
 * - Order updates (update/on_update)
 * - Payment integration
 * - Inventory management hooks
 * - Error handling improvements
 * - Rate limiting
 * - Webhook security validation
 *
 * ========================================================================================
 * TESTING THIS CODE
 * ========================================================================================
 *
 * 1. Use the index() method to generate test keys
 * 2. Use checkOnSearchStatus() to see if searches work
 * 3. Use listTransactions() to see all events
 * 4. Check logs in ond_api_logs and ond_transactions tables
 *
 * Happy coding! 🚀
 *
 * ========================================================================================
 */

class ApiController extends Controller
{
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

    /**
     * SEARCH API - Entry Point (Called by BAP/Gateway)
     */
    public function search(Request $request)
    {
        $searchData = $request->all();

        Log::info('ONDC_SEARCH_RECEIVED', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $searchData,
        ]);

        // Validate payload
        if (!isset($searchData['context'])) {
            return response()->json([
                'error' => 'Invalid payload: context missing',
            ], 400);
        }

        $txnId = $searchData['context']['transaction_id'] ?? Str::uuid()->toString();
        $searchData['context']['transaction_id'] = $txnId;

        // Extract dynamic BAP details
        $bapUri = rtrim($searchData['context']['bap_uri'] ?? '', '/');
        $bapId = $searchData['context']['bap_id'] ?? null;

        if (!$bapUri) {
            Log::error('BAP_URI_MISSING_IN_SEARCH_REQUEST', [
                'txn_id' => $txnId,
                'payload' => $searchData,
            ]);

            return response()->json([
                'error' => 'bap_uri missing in context',
            ], 400);
        }

        // Store in cache for 30 minutes
        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri' => $bapUri,
            'bap_id' => $bapId,
            'original_request' => $searchData,
        ], now()->addMinutes(30));

        OndcTransaction::create([
            'transaction_id' => $txnId,
            'action' => 'search',
            'domain' => $searchData['context']['domain'] ?? 'ONDC:RET14',
            'bap_id' => $bapId,
            'bap_uri' => $bapUri,
            'request_payload' => $searchData,
            'status' => 'received',
        ]);

        // Log incoming request
        OndcApiLog::create([
            'type' => 'search_inbound',
            'endpoint' => '/ondc/search',
            'request' => json_encode($searchData),
            'status_code' => 200,
            'transaction_id' => $txnId,
        ]);

        // Send ACK immediately (within 4 seconds as per ONDC spec)
        $ack = [
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ];

        // Process asynchronously
        dispatch(function () use ($searchData, $txnId, $bapUri, $bapId) {
            $this->sendOnSearch($searchData, $txnId, $bapUri, $bapId);
        })->onQueue(env('ONDC_QUEUE', 'default'));

        return response()->json($ack);
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

        // Build on_search payload with full catalog
        $payload = $this->buildPayload($context, $bapUri, $bapId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_SEARCH_PAYLOAD', [
            'txn_id' => $txnId,
            'bap_uri' => $bapUri,
            'product_count' => count($payload['message']['catalog']['bpp/providers'][0]['items'] ?? []),
            'domain' => $payload['context']['domain'],
            'action' => $payload['context']['action'],
        ]);
        
        $headers = [
            'Accept'        => 'application/json',
            'Content-Type'  => 'application/json',
        ];

        $endpoints = [
            // [
            //     'url' => rtrim($bapUri, '/') . '/on_search',
            //     'type' => 'bap',
            //     'headers' => $headers,
            // ],
            [
                'url' => rtrim(env('ONDC_BPP_URI', 'https://retail-seller.softtrust.org/'), '/') . '/on_search',
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
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'search', $payload, $bapUri, $bapId);
        }
    }

    /**
     * Helper method to send payload to a single endpoint
     */
    protected function sendToEndpoint($endpoint, $payloadJson, $txnId, $actionType, $payload = [], $bapUri = null, $bapId = null)
    {
        $url = $endpoint['url'];
        $type = $endpoint['type'];
        $headers = $endpoint['headers'];

        Log::info("SENDING_ON_" . strtoupper($actionType), [
            'url' => $url,
            'txn_id' => $txnId,
            'type' => $type,
        ]);

        try {
            $response = Http::withOptions(['verify' => true])
                ->withHeaders($headers)
                ->withBody($payloadJson, 'application/json')
                ->timeout(30)
                ->connectTimeout(10)
                ->post($url);

            Log::info("ON_" . strtoupper($actionType) . "_RESPONSE", [
                'txn_id' => $txnId,
                'type' => $type,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            $transaction = OndcTransaction::create([
                'transaction_id' => $txnId,
                'action' => 'on_' . $actionType,
                'domain' => $payload['context']['domain'] ?? 'ONDC:RET14',
                'bap_id' => $payload['context']['bap_id'] ?? null,
                'bap_uri' => $payload['context']['bap_uri'] ?? null,
                'bpp_id' => env('ONDC_BPP_ID'),
                'status' => 'ack_received',
                'request_payload' => $payload,
                'response_payload' => $response->json(),
                'response_status_code' => $response->status(),
                'sent_at' => now(),
                'ack_received_at' => now(),
            ]);

            // Log API call
            OndcApiLog::create([
                'type' => "{$actionType}_outbound_{$type}",
                'endpoint' => $url,
                'request' => $payloadJson,
                'response' => $response->body(),
                'status_code' => $response->status(),
                'transaction_id' => $txnId,
            ]);

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::info("ON_" . strtoupper($actionType) . "_SENT_AWAITING_CALLBACK", [
                'txn_id' => $txnId,
                'type' => $type,
                'message' => 'Request sent (timeout is normal for async API)',
                'error' => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action' => 'on_' . $actionType,
                'status' => 'sent',
                'sent_at' => now(),
            ]);

            OndcApiLog::create([
                'type' => "{$actionType}_outbound_{$type}_sent",
                'endpoint' => $url,
                'request' => $payloadJson,
                'response' => json_encode(['status' => 'sent_awaiting_callback']),
                'status_code' => 202,
                'transaction_id' => $txnId,
            ]);

        } catch (\Exception $e) {
            Log::error("ON_" . strtoupper($actionType) . "_ERROR", [
                'txn_id' => $txnId,
                'type' => $type,
                'message' => $e->getMessage(),
                'class' => get_class($e),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action' => 'on_' . $actionType,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            OndcApiLog::create([
                'type' => "{$actionType}_outbound_{$type}_error",
                'endpoint' => $url,
                'request' => $payloadJson,
                'response' => json_encode(['error' => $e->getMessage()]),
                'status_code' => 500,
                'transaction_id' => $txnId,
            ]);
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
                'city' => $context['city'] ?? 'std:080',
                'core_version' => $context['core_version'] ?? '1.2.0',
                'action' => 'on_search', // Changed from 'search'
                'bap_id' => $bapId,
                'bap_uri' => $bapUri,
                'bpp_id' => env('ONDC_BPP_ID'),
                'bpp_uri' => env('ONDC_BPP_URI'),
                'transaction_id' => $context['transaction_id'], // SAME as request
                'message_id' => (string) \Illuminate\Support\Str::uuid(), // FRESH per outbound
                'timestamp' => now()->toISOString(),
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
        $timestamp = now()->toISOString();
        $products = $this->getAllActiveProducts();

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
                                'code' => 'static_terms',
                                'value' => '',
                            ],
                            [
                                'code' => 'static_terms_new',
                                'value' => 'https://github.com/ONDC-Official/NP-Static-Terms/sellerNP_MSN/1.0/tc.pdf',
                            ],
                        ],
                    ],
                ],
            ],
            'bpp/providers' => $this->buildProviders($products, $timestamp, $context),
        ];
    }

    /**
     * Get all active products from database
     */
    protected function getAllActiveProducts()
    {
        try {
            // Use ProductFlat for better performance (pre-indexed)
            $productFlats = \Webkul\Product\Models\ProductFlat::query()
                ->join('ondc_products', 'ondc_products.product_id', '=', 'product_flat.product_id')
                ->where('product_flat.status', 1)
                ->where('product_flat.visible_individually', 1)
                ->where('product_flat.locale', app()->getLocale() ?? 'en')
                ->where('product_flat.channel', core()->getCurrentChannelCode() ?? 'default')
                ->limit(50)
                ->select('product_flat.*')
                ->get();

            $result = [];
            
            foreach ($productFlats as $productFlat) {
                // Get product model
                $product = $productFlat->product;
                
                if (!$product) {
                    continue; // Skip if product not found
                }
                
                // Get image safely
                $image = '';
                try {
                    $image = $product->base_image_url ?: 'https://your-domain.com/images/placeholder.png';
                } catch (\Exception $e) {
                    $image = 'https://your-domain.com/images/placeholder.png';
                }
                
                // Get stock safely
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
                
                // Get category safely
                $categoryName = 'General';
                try {
                    if ($productFlat->categories && $productFlat->categories->isNotEmpty()) {
                        $categoryName = $productFlat->categories->first()->name ?? 'General';
                    }
                } catch (\Exception $e) {
                    $categoryName = 'General';
                }
                
                // Build plain array (NO relationships, NO Eloquent objects)
                $result[] = [
                    'id' => 'I' . $product->id,
                    'name' => (string) ($productFlat->name ?? 'Product'),
                    'sku' => (string) ($productFlat->sku ?? ''),
                    'image' => (string) $image,
                    'images' => [(string) $image],
                    'short_description' => (string) Str::limit($productFlat->description ?? $productFlat->name ?? 'Product', 100),
                    'description' => (string) ($productFlat->description ?? $productFlat->name ?? 'Product'),
                    'price' => (float) ($productFlat->min_price ?? 100.00),
                    'unit' => 'kilogram',
                    'unit_value' => 1,
                    'stock' => (int) max(0, $stock), // Ensure non-negative
                    'category_name' => (string) $categoryName,
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
            
            // If no products found, return sample data
            if (empty($result)) {
                Log::info('NO_PRODUCTS_FOUND_USING_SAMPLE_DATA');
                // return $this->getSampleProducts();
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
     */
    protected function buildProviders($products, $timestamp, $context)
    {
        $storeId = 'P1';
        $locationId = 'L1';
        $fulfillmentId = 'F1';

        $groupedByCategory = $this->groupProductsByCategory($products);

        $items = [];
        $variantGroups = [];
        $categoryIds = [];

        foreach ($groupedByCategory as $categoryName => $categoryProducts) {
            $categoryIds[] = $categoryName;

            foreach ($categoryProducts as $product) {
                $itemId = $product['id'];
                $variantGroupId = null;

                // Handle variant groups
                if ($product['has_variants'] ?? false) {
                    $variantGroupId = 'V' . $product['variant_group_id'];

                    // Create variant group category (ONLY if not already created)
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
                                        ['code' => 'name', 'value' => 'item.quantity.unitized.measure'],
                                        ['code' => 'seq', 'value' => '1'],
                                    ],
                                ],
                            ],
                        ];
                    }
                }

                // Build item - MATCHES OFFICIAL FORMAT EXACTLY
                $item = [
                    'id' => $itemId,
                    'descriptor' => [
                        'name' => $product['name'],
                        // descriptor.code must match /^(1|2|3|4|5):[a-zA-Z0-9]+$/
                        // 1=EAN, 2=ISBN, 3=GTIN, 4=HSN, 5=others
                        'code' => $this->buildDescriptorCode($product),
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
                            // count must be a NUMBER per ONDC spec
                            'count' => (int) ($product['stock'] ?? 99),
                        ],
                        'maximum' => [
                            'count' => (int) ($product['stock'] ?? 99),
                        ],
                    ],
                    'category_id' => $product['category_name'] ?? 'General',
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

                // Add parent_item_id if this is a variant
                if ($variantGroupId) {
                    $item['parent_item_id'] = $variantGroupId;
                }

                $items[] = $item;
            }
        }

        // Build provider - MATCHES OFFICIAL FORMAT
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
                            // schedule.times must be array of HH:MM strings per ONDC schema
                            'schedule' => [
                                'holidays'  => [],
                                'frequency' => 'PT4H',
                                'times'     => ['0000', '0400', '0800', '1200', '1600', '2000'],
                            ],
                            'range' => [
                                'start' => '0000',
                                'end' => '2359',
                            ],
                        ],
                        'gps' => config('ondc.store.gps', '21.1458,79.0882'),
                        'address' => [
                            'street' => config('ondc.store.address.street', 'Your Street'),
                            'locality' => config('ondc.store.address.locality', 'Your Locality'),
                            'city' => config('ondc.store.address.city', 'Nagpur'),
                            'state' => config('ondc.store.address.state', 'Maharashtra'),
                            'area_code' => config('ondc.store.address.area_code', '440001'),
                        ],
                        'circle' => [
                            'gps' => config('ondc.store.gps', '21.1458,79.0882'),
                            'radius' => [
                                'unit' => 'km',
                                'value' => '10',
                            ],
                        ],
                    ],
                ],
                'fulfillments' => [
                    [
                        'id' => $fulfillmentId,
                        'type' => 'Delivery',
                        'contact' => [
                            'phone' => config('ondc.store.phone', '+91-9876543210'),
                            'email' => config('ondc.store.email', 'support@yourstore.com'),
                        ],
                    ],
                ],
                'items' => $items,
                'categories' => array_values($variantGroups), // ✅ Now includes variant groups
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
                            ['code' => 'category', 'value' => !empty($categoryIds) ? (string) $categoryIds[0] : 'General'],
                            ['code' => 'type', 'value' => '10'],
                            ['code' => 'val', 'value' => '10'],
                            ['code' => 'unit', 'value' => 'km'],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * Group products by category
     */
    protected function groupProductsByCategory($products)
    {
        $grouped = [];
        foreach ($products as $product) {
            $categoryName = $product['category_name'] ?? 'General';
            $grouped[$categoryName][] = $product;
        }
        return $grouped;
    }

    /**
     * Build consumer care contact string
     */
    protected function getConsumerCareString()
    {
        $careName = config('ondc.store.care_name', 'Customer Care');
        $careEmail = config('ondc.store.care_email', 'care@yourstore.com');
        $carePhone = config('ondc.store.care_phone', '1800-123-4567');
        return "{$careName},{$careEmail},{$carePhone}";
    }

    /**
     * Build a spec-compliant descriptor.code in the form "<type>:<value>".
     *
     * Type codes (ONDC):
     *   1 = EAN (13-digit barcode)
     *   2 = ISBN
     *   3 = GTIN
     *   4 = HSN (numeric tax-classification)
     *   5 = others (alphanumeric)
     *
     * If the product has a `hsn_code`, `ean`, `gtin` or similar attribute we
     * prefer that. Otherwise we fall back to `5:<sku>` (others).
     */
    protected function buildDescriptorCode(array $product): string
    {
        // Prefer EAN
        if (! empty($product['ean']) && preg_match('/^\d{8,13}$/', $product['ean'])) {
            return '1:' . $product['ean'];
        }
        // GTIN
        if (! empty($product['gtin']) && preg_match('/^[0-9A-Za-z]+$/', $product['gtin'])) {
            return '3:' . $product['gtin'];
        }
        // HSN
        if (! empty($product['hsn_code']) && preg_match('/^\d+$/', $product['hsn_code'])) {
            return '4:' . $product['hsn_code'];
        }
        if (! empty($product['hsn']) && preg_match('/^\d+$/', $product['hsn'])) {
            return '4:' . $product['hsn'];
        }
        // ISBN
        if (! empty($product['isbn']) && preg_match('/^[0-9Xx]+$/', $product['isbn'])) {
            return '2:' . $product['isbn'];
        }
        // Fallback to "others" with SKU
        $sku = preg_replace('/[^a-zA-Z0-9]/', '', (string) ($product['sku'] ?? $product['id'] ?? 'NOSKU'));
        if ($sku === '') $sku = 'NOSKU';
        return '5:' . $sku;
    }

    /** Only for testing purposes */
    // protected function getSampleProducts()
    // {
    //     return [
    //         [
    //             'id' => 'I1',
    //             'name' => 'Plain Atta',
    //             'sku' => 'ATTA-001',
    //             'image' => 'https://your-domain.com/images/atta.png',
    //             'images' => ['https://your-domain.com/images/atta.png'],
    //             'short_description' => 'Premium quality plain wheat atta',
    //             'description' => 'Premium quality plain wheat atta - 3kg pack',
    //             'price' => 100.00,
    //             'unit' => 'kilogram',
    //             'unit_value' => 3,
    //             'stock' => 99,
    //             'category_name' => 'Atta, Flours and Sooji',
    //             'has_variants' => false,
    //             'variant_group_id' => null,
    //             'variant_group_name' => null,
    //             'returnable' => true,
    //             'cancellable' => true,
    //             'return_window' => 'P7D',
    //             'seller_pickup_return' => false,
    //             'time_to_ship' => 'PT24H',
    //             'cod_available' => false,
    //             'manufacturer_name' => 'ITC Limited',
    //             'manufacturer_address' => 'ITC Quality Care Cell, P.O Box No.592, Bangalore-560005',
    //             'manufacture_date' => '01/2023',
    //             'origin_country' => 'IND',
    //         ],
    //         [
    //             'id' => 'I2',
    //             'name' => 'Basmati Rice',
    //             'sku' => 'RICE-001',
    //             'image' => 'https://your-domain.com/images/rice.png',
    //             'images' => ['https://your-domain.com/images/rice.png'],
    //             'short_description' => 'Premium aged basmati rice',
    //             'description' => 'Premium aged basmati rice - 5kg pack',
    //             'price' => 450.00,
    //             'unit' => 'kilogram',
    //             'unit_value' => 5,
    //             'stock' => 50,
    //             'category_name' => 'Rice and Rice Products',
    //             'has_variants' => false,
    //             'variant_group_id' => null,
    //             'variant_group_name' => null,
    //             'returnable' => true,
    //             'cancellable' => true,
    //             'return_window' => 'P7D',
    //             'seller_pickup_return' => false,
    //             'time_to_ship' => 'PT24H',
    //             'cod_available' => false,
    //             'manufacturer_name' => 'India Gate',
    //             'manufacturer_address' => 'KRBL Limited, Delhi',
    //             'manufacture_date' => '02/2023',
    //             'origin_country' => 'IND',
    //         ],
    //     ];
    // }

    /**
     * SELECT API - Entry Point (Called by BAP when buyer selects items)
     */
    public function select(Request $request)
    {
        $selectData = $request->all();

        Log::info('ONDC_SELECT_RECEIVED', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload' => $selectData,
        ]);

        // Validate payload
        if (!isset($selectData['context']) || !isset($selectData['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        $txnId = $selectData['context']['transaction_id'] ?? Str::uuid()->toString();
        $selectData['context']['transaction_id'] = $txnId;

        // Extract dynamic BAP details
        $bapUri = rtrim($selectData['context']['bap_uri'] ?? '', '/');
        $bapId = $selectData['context']['bap_id'] ?? null;

        if (!$bapUri) {
            Log::error('BAP_URI_MISSING_IN_SELECT_REQUEST', [
                'txn_id' => $txnId,
                'payload' => $selectData,
            ]);

            return response()->json([
                'error' => 'bap_uri missing in context',
            ], 400);
        }

        // Store in cache for 30 minutes
        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri' => $bapUri,
            'bap_id' => $bapId,
            'original_request' => $selectData,
            'action' => 'select',
        ], now()->addMinutes(30));

        // ✅ SAVE TO DATABASE - Initial select request
        OndcTransaction::create([
            'transaction_id' => $txnId,
            'action' => 'select',
            'domain' => $selectData['context']['domain'] ?? 'ONDC:RET14',
            'bap_id' => $bapId,
            'bap_uri' => $bapUri,
            'request_payload' => $selectData,
            'status' => 'received',
        ]);

        // Log incoming request
        OndcApiLog::create([
            'type' => 'select_inbound',
            'endpoint' => '/ondc/select',
            'request' => json_encode($selectData),
            'status_code' => 200,
            'transaction_id' => $txnId,
        ]);

        // ✅ Send ACK immediately (within 4 seconds as per ONDC spec)
        $ack = [
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ];

        // ✅ Process asynchronously - automatically sends on_select
        dispatch(function () use ($selectData, $txnId, $bapUri, $bapId) {
            $this->sendOnSelect($selectData, $txnId, $bapUri, $bapId);
        })->onQueue(env('ONDC_QUEUE', 'default'));

        return response()->json($ack);
    }

    /**
     * Send signed on_select to BAP
     */
    protected function sendOnSelect($selectData, $txnId, $bapUri, $bapId)
    {
        $context = $selectData['context'];
        $message = $selectData['message'] ?? [];

        if (!env('ONDC_PRIVATE_KEY')) {
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

        // Build on_select payload
        $payload = $this->buildOnSelectPayload($context, $message, $bapUri, $bapId);
        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_SELECT_PAYLOAD', [
            'txn_id' => $txnId,
            'bap_uri' => $bapUri,
            'item_count' => count($payload['message']['order']['items'] ?? []),
            'domain' => $payload['context']['domain'],
            'action' => $payload['context']['action'],
        ]);

        $signer = new OndcSigner();

        try {
            $signatureHeaders = $signer->signRequest(
                $payloadJson,
                env('ONDC_BPP_ID'),
                env('ONDC_PRIVATE_KEY')
            );
        } catch (\Exception $e) {
            Log::error('SIGNATURE_ERROR', [
                'txn_id' => $txnId,
                'error' => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action' => 'on_select',
                'status' => 'failed',
                'error_message' => $e->getMessage(),
            ]);

            OndcApiLog::create([
                'type' => 'on_select_outbound_error',
                'endpoint' => $bapUri . '/on_select',
                'request' => $payloadJson,
                'response' => json_encode(['error' => 'Signature failed: ' . $e->getMessage()]),
                'status_code' => 500,
                'transaction_id' => $txnId,
            ]);
            return;
        }

        $endpoints = [
            // [
            //     'url' => $bapUri . '/on_select',
            //     'type' => 'bap',
            //     'headers' => $signatureHeaders,
            // ],
            [
                'url' => env('ONDC_BPP_URI', 'https://retail-seller.softtrust.org/') . 'on_select',
                'type' => 'bpp_internal',
                'headers' => array_merge($signatureHeaders, [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri' => $bapUri,
                ]),
            ],
        ];

        foreach ($endpoints as $endpoint) {
            // ✅ FIX: Pass payload to helper method
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'select', $payload, $bapUri, $bapId);
        }
    }

    /**
     * Build on_select payload
     */
    protected function buildOnSelectPayload($context, $message, $bapUri, $bapId)
    {
        $timestamp = now()->toISOString();
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

        // Check if any item is out of stock
        $hasOutOfStock = collect($validatedItems)->contains(function ($item) {
            return ($item['@ondc/org/item_status'] ?? '') === 'out_of_stock';
        });

        // Check if any item is invalid (not found)
        $hasInvalidItem = collect($validatedItems)->contains(function ($item) {
            return ($item['@ondc/org/item_status'] ?? '') === 'invalid';
        });

        $payload = [
            'context' => [
                'domain'         => $context['domain']  ?? 'ONDC:RET10',
                'country'        => $context['country'] ?? 'IND',
                'city'           => $context['city']    ?? 'std:080',
                'core_version'   => $context['core_version'] ?? '1.2.0',
                'action'         => 'on_select',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => env('ONDC_BPP_ID'),
                'bpp_uri'        => env('ONDC_BPP_URI'),
                'transaction_id' => $context['transaction_id'],
                'message_id'     => (string) \Illuminate\Support\Str::uuid(),
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
                                    // ✅ Non-Serviceable if out of stock, else Serviceable
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

        // ✅ Add error block if out of stock or item not found
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

            // Extract product ID (remove 'I' prefix if present)
            $productId = str_replace('I', '', $itemId);

            // Fetch product from database
            $product = \Webkul\Product\Models\ProductFlat::query()
                ->join('ondc_products', 'ondc_products.product_id', '=', 'product_flat.product_id')
                ->where('product_flat.product_id', $productId)
                ->where('product_flat.status', 1)
                ->where('product_flat.visible_individually', 1)
                ->where('product_flat.locale', app()->getLocale() ?? 'en')
                ->where('product_flat.channel', core()->getCurrentChannelCode() ?? 'default')
                ->first();

            if (!$product) {
                // Item not found - return error
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

            // Check stock
            $availableStock = 0;
            try {
                if ($product->product && $product->product->inventories) {
                    $availableStock = $product->product->inventories->sum('qty');
                }
            } catch (\Exception $e) {
                $availableStock = 99;
            }

            $isAvailable = $availableStock >= $quantity;

            // Build item response - MINIMAL format as per ONDC spec
            $validatedItems[] = [
                'id' => $itemId,
                'fulfillment_id' => $fulfillmentId,
                'descriptor' => [
                    'name' => $product->name,
                    'code' => $product->sku,
                    'symbol' => $product->base_image_url ?? '',
                    'short_desc' => Str::limit($product->description, 100),
                    'long_desc' => $product->description,
                    'images' => [$product->base_image_url ?? ''],
                ],
                'price' => [
                    'currency' => 'INR',
                    'value' => number_format((float) $product->min_price, 2, '.', ''),
                    'maximum_value' => number_format((float) $product->min_price, 2, '.', ''),
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
                'fulfillment_id' => $fulfillmentId,
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

        // Add item prices
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
                'price_value'             => $itemPrice,   // ← add this for total calculation
                'price' => [
                    'currency' => 'INR',
                    'value'    => number_format($itemPrice, 2, '.', ''),
                ],
                'item' => [
                    'quantity' => [
                        'available' => ['count' => (int) ($item['quantity']['available']['count'] ?? $quantity)],
                        'maximum'   => ['count' => (int) ($item['quantity']['maximum']['count'] ?? $quantity)],
                    ],
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($price, 2, '.', ''),
                    ],
                ],
            ];
        }

        // Add delivery charges
        $deliveryCharge = 0.00; // Free delivery
        $breakup[] = [
            '@ondc/org/item_id' => $items[0]['id'] ?? 'I1',
            'title' => 'Delivery charges',
            '@ondc/org/title_type' => 'delivery',
            'price' => [
                'currency' => 'INR',
                'value' => number_format($deliveryCharge, 2, '.', ''),
            ],
        ];

        // Add convenience fee
        $convenienceFee = 0.00; // No convenience fee
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
     * Automatically sends on_init response and saves to database
     */
    public function init(Request $request)
    {
        $initData = $request->all();

        Log::info('ONDC_INIT_RECEIVED', [
            'ip'          => $request->ip(),
            'user_agent'  => $request->userAgent(),
            'payload'     => $initData,
        ]);

        // 1. Validate context and message
        if (!isset($initData['context']) || !isset($initData['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        $ctxt = $initData['context'];

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

        // 2. Cache the request for async processing
        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri'           => $bapUri,
            'bap_id'            => $bapId,
            'original_request'  => $initData,
            'action'            => $action,
        ], now()->addMinutes(30));

        // 3. Save to `ondc_transactions` (like `select`)
        OndcTransaction::create([
            'transaction_id'   => $txnId,
            'action'           => $action,
            'domain'           => $ctxt['domain'] ?? 'ONDC:RET14',
            'bap_id'           => $bapId,
            'bap_uri'          => $bapUri,
            'bpp_id'           => env('ONDC_BPP_ID'),
            'status'           => 'received',
            'request_payload'  => $initData,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);

        // 4. Log in `OndcApiLog`
        OndcApiLog::create([
            'type'            => $action . '_inbound',
            'endpoint'        => '/ondc/init',
            'request'         => json_encode($initData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'status_code'     => 200,
            'transaction_id'  => $txnId,
        ]);

        // 5. Respond ACK immediately
        $ack = [
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ];

        // 6. Dispatch async `on_init` after the quote is computed
        dispatch(function () use ($initData, $txnId, $bapUri, $bapId) {
            $this->sendOnInit($initData, $txnId, $bapUri, $bapId);
        })->onQueue(env('ONDC_QUEUE', 'default'));

        return response()->json($ack);
    }

    /**
     * Send signed on_init to BAP and internal BPP
     */
    protected function sendOnInit($initData, $txnId, $bapUri, $bapId = null)
    {
        $payload = $initData['message']['order'] ?? [];

        $order = [
            'provider'           => $payload['provider'] ?? [],
            'items'              => $this->buildItemsForOnInit($payload['items'] ?? []),
            'billing'            => $this->buildBillingForOnInit($payload['billing'] ?? []),
            'fulfillments'       => $this->buildFulfillmentsForOnInit($payload['fulfillments'] ?? []),
            'quote'              => $this->buildQuoteForOnInit($payload),
            'payment'            => $this->buildPaymentForOnInit($initData),
            'cancellation_terms' => $this->buildCancellationTerms(),
            'tags'               => $this->buildBppTermsTags(),
        ];

        $context = [
            'domain'          => $initData['context']['domain']       ?? 'ONDC:RET10',
            'country'         => $initData['context']['country']      ?? 'IND',
            'city'            => $initData['context']['city']         ?? 'std:080',
            'core_version'    => $initData['context']['core_version'] ?? '1.2.0',
            'action'          => 'on_init',
            'transaction_id'  => $txnId,
            'message_id'      => (string) Str::uuid(),
            'ttl'             => 'PT30S',
            'bap_id'          => $bapId ?: $initData['context']['bap_id'],
            'bap_uri'         => $bapUri,
            'bpp_id'          => env('ONDC_BPP_ID'),
            'bpp_uri'         => env('ONDC_BPP_URI'),
            'timestamp'       => now()->toIso8601String(),
        ];

        $responsePayload = [
            'context' => $context,
            'message' => [
                'order' => $order,
            ],
        ];

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_INIT_PAYLOAD', [
            'txn_id'      => $txnId,
            'bap_uri'     => $bapUri,
            'item_count'  => count($payload['items'] ?? []),
            'domain'      => $context['domain'],
            'action'      => 'on_init',
        ]);

        // Reuse the same signRequest pattern as in sendOnSelect
        $signer = new OndcSigner();

        try {
            $signatureHeaders = $signer->signRequest(
                $payloadJson,
                env('ONDC_BPP_ID'),
                env('ONDC_PRIVATE_KEY')
            );
        } catch (\Exception $e) {
            Log::error('ON_INIT_SIGNATURE_ERROR', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);

            OndcTransaction::create([
                'transaction_id' => $txnId,
                'action'         => 'on_init',
                'status'         => 'failed',
                'error_message'  => $e->getMessage(),
            ]);

            OndcApiLog::create([
                'type'            => 'on_init_outbound_error',
                'endpoint'        => $bapUri . '/on_init',
                'request'         => $payloadJson,
                'status_code'     => 500,
                'transaction_id'  => $txnId,
            ]);

            return;
        }

        $endpoints = [
            // [
            //     'url'     => $bapUri . '/on_init',
            //     'type'    => 'bap',
            //     'headers' => $signatureHeaders,
            // ],
            [
                'url'     => env('ONDC_BPP_URI') . 'on_init',
                'type'    => 'bpp_internal',
                'headers' => array_merge($signatureHeaders, [
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'       => $bapUri,
                ]),
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'init', $responsePayload, $bapUri, $bapId);
        }
    }

    protected function buildQuoteForOnInit($orderPayload)
    {
        $items = $orderPayload['items'] ?? [];
        $order = $orderPayload;

        $itemCount = count($items);
        $itemPrice = 100.00;  // dummy per‑item price
        $totalValue = $itemCount * $itemPrice;

        $breakup = [];

        foreach ($items as $item) {
            $breakup[] = [
                '@ondc/org/item_id'          => $item['id'] ?? $item['id'],
                '@ondc/org/item_quantity'    => $item['quantity'] ?? ['count' => 1],
                'title'                      => 'Plain Atta', // you can lookup from DB
                '@ondc/org/title_type'       => 'item',
                'price'                      => [
                    'currency' => 'INR',
                    'value'    => number_format($itemPrice, 2),
                ],
                'item'                       => [
                    'price' => [
                        'currency' => 'INR',
                        'value'    => number_format($itemPrice, 2),
                    ],
                ],
            ];
        }

        $breakup[] = [
            '@ondc/org/item_id'       => $items[0]['id'] ?? 'I1',
            'title'                   => 'Delivery charges',
            '@ondc/org/title_type'    => 'delivery',
            'price'                   => [
                'currency' => 'INR',
                'value'    => '00.00',
            ],
        ];

        $breakup[] = [
            '@ondc/org/item_id'       => $items[0]['id'] ?? 'I1',
            'title'                   => 'Convenience Fee',
            '@ondc/org/title_type'    => 'misc',
            'price'                   => [
                'currency' => 'INR',
                'value'    => '00.00',
            ],
        ];

        return [
            'price'  => [
                'value'    => number_format($totalValue, 2),
                'currency' => 'INR',
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
            'created_at'  => $billing['created_at'] ?? now()->toIso8601String(),
            'updated_at'  => $billing['updated_at'] ?? now()->toIso8601String(),
            'address' => [
                // Pramaan strict-validates these — they MUST all be present + populated
                'name'      => $billing['address']['name']      ?? $name,
                'building'  => $billing['address']['building']  ?? 'Building',
                'locality'  => $billing['address']['locality']  ?? 'Locality',
                'city'      => $billing['address']['city']      ?? 'Bengaluru',
                'state'     => $billing['address']['state']     ?? 'Karnataka',
                'country'   => $billing['address']['country']   ?? 'IND',  // ISO-3166-1 alpha-3
                'area_code' => $billing['address']['area_code'] ?? '560001',
            ],
        ];
    }

    protected function buildFulfillmentsForOnInit($fulfillments)
    {
        $storeName = config('ondc.store.name', 'Store 1');

        return collect($fulfillments)->map(function ($f) use ($storeName) {
            $merged = array_merge($f, [
                'tracking' => $f['tracking'] ?? true,
                'type'     => $f['type']     ?? 'Delivery',
                'id'       => $f['id']       ?? 'F1',
                '@ondc/org/provider_name' => $f['@ondc/org/provider_name'] ?? $storeName,
                '@ondc/org/TAT'           => $f['@ondc/org/TAT']           ?? 'PT60M',
            ]);

            // Ensure end.location.address.name is set (Pramaan flagged it)
            if (isset($merged['end']['location']['address']) && empty($merged['end']['location']['address']['name'])) {
                $merged['end']['location']['address']['name'] = $merged['end']['person']['name'] ?? 'Buyer';
            }

            return $merged;
        })->all();
    }

    protected function buildItemsForOnInit($items)
    {
        return collect($items)->map(function ($item) {
            // Pramaan validates quantity.count is a NUMBER (integer)
            if (isset($item['quantity']['count'])) {
                $item['quantity']['count'] = (int) $item['quantity']['count'];
            }
            return $item;
        })->values()->all();
    }

    /**
     * Cancellation terms per ONDC v1.2.0 spec.
     * Fee schedule depends on fulfillment state + reason code.
     */
    protected function buildCancellationTerms(): array
    {
        return [
            [
                'fulfillment_state' => ['descriptor' => ['code' => 'Pending', 'short_desc' => '002']],
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
                'cancellation_fee'  => ['percentage' => '100.00'],
            ],
        ];
    }

    protected function buildPaymentForOnInit($initData)
    {
        return [
            '@ondc/org/buyer_app_finder_fee_type'      => 'percent',
            '@ondc/org/buyer_app_finder_fee_amount'    => '3',
            '@ondc/org/settlement_details'             => [
                [
                    'settlement_counterparty'    => 'seller-app',
                    'settlement_phase'           => 'sale-amount',
                    'settlement_type'            => 'neft',
                    'beneficiary_name'           => env('ONDC_BENEFICIARY_NAME', 'XXXX'),
                    'settlement_bank_account_no' => env('ONDC_BANK_ACCOUNT', 'XXXX'),
                    'settlement_ifsc_code'       => env('ONDC_IFSC', 'XXXX'),
                    'bank_name'                  => env('ONDC_BANK_NAME', 'XXXX'),
                    'branch_name'                => env('ONDC_BRANCH_NAME', 'XXXX'),
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
                env('ONDC_BPP_ID'),
                env('ONDC_PRIVATE_KEY')
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

        // 1. Add `Authorization` from `signRequest`
        $headers = array_merge($signatureHeaders, [
            'Content-Type' => 'application/json',
        ]);

        // 2. Optionally add created/expires in separate headers if ONDC needs them
        $headers['(created)'] = $created;
        $headers['(expires)'] = $expires;

        return $headers;
    }

    /**
     * CONFIRM API - Order is confirmed by BAP
     * Automatically sends on_confirm response and saves to database
     */
    public function confirm(Request $request)
    {
        $confirmData = $request->all();

        Log::info('ONDC_CONFIRM_RECEIVED', [
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
            'payload'    => $confirmData,
        ]);

        // 1. Validate context and message
        if (!isset($confirmData['context']) || !isset($confirmData['message'])) {
            return response()->json([
                'error' => 'Invalid payload: context or message missing',
            ], 400);
        }

        $ctxt = $confirmData['context'];

        if (!$ctxt['domain'] ?? null || !$ctxt['bap_id'] ?? null) {
            return response()->json([
                'error' => 'Missing required context fields',
            ], 400);
        }

        $txnId  = $ctxt['transaction_id'] ?? Str::uuid()->toString();
        $action = $ctxt['action'] ?? 'confirm';
        $bapUri = rtrim($ctxt['bap_uri'] ?? '', '/');
        $bapId  = $ctxt['bap_id'] ?? null;

        if (!$bapUri) {
            Log::error('BAP_URI_MISSING_IN_CONFIRM', [
                'txn_id'  => $txnId,
                'payload' => $confirmData,
            ]);

            return response()->json([
                'error' => 'bap_uri missing in context',
            ], 400);
        }

        // 2. Cache the request for async processing
        Cache::put("ondc:txn:{$txnId}", [
            'bap_uri'          => $bapUri,
            'bap_id'           => $bapId,
            'original_request' => $confirmData,
            'action'           => $action,
        ], now()->addMinutes(30));

        // 3. Save to `ondc_transactions`
        OndcTransaction::create([
            'transaction_id'  => $txnId,
            'action'          => $action,
            'domain'          => $ctxt['domain'] ?? 'ONDC:RET14',
            'bap_id'          => $bapId,
            'bap_uri'         => $bapUri,
            'bpp_id'          => env('ONDC_BPP_ID'),
            'status'          => 'received',
            'request_payload' => $confirmData,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // 4. Log in `OndcApiLog`
        OndcApiLog::create([
            'type'             => $action . '_inbound',
            'endpoint'         => '/ondc/confirm',
            'request'          => json_encode($confirmData, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'status_code'      => 200,
            'transaction_id'   => $txnId,
        ]);

        // 5. Respond ACK immediately
        $ack = [
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ];

        // 6. Dispatch async `on_confirm`
        dispatch(function () use ($confirmData, $txnId, $bapUri, $bapId) {
            $this->sendOnConfirm($confirmData, $txnId, $bapUri, $bapId);
        })->onQueue(env('ONDC_QUEUE', 'default'));

        return response()->json($ack);
    }

    /**
     * Send signed on_confirm to BAP and internal BPP
     */
    protected function sendOnConfirm($confirmData, $txnId, $bapUri, $bapId = null)
    {
        $order       = $confirmData['message']['order'] ?? [];
        $txnId       = $confirmData['context']['transaction_id'] ?? Str::uuid()->toString();
        $orderItems  = $order['items'] ?? [];
        $quote       = $order['quote'] ?? [];
        $payment     = $order['payment'] ?? [];
        $billing     = $order['billing'] ?? [];
        $fulfillment = ($order['fulfillments'][0] ?? null) ?: [];

        DB::beginTransaction();

        // Compute total ordered quantity
        $itemQtySum = 0;
        foreach ($order['items'] as $item) {
            $itemQtySum += (int) ($item['quantity']['count'] ?? 1);
        }

        try {
            // 1. Create Bagisto `orders` record
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

                // taxes, discounts, shipping, etc. (set to 0 for ONDC where not handled by BPP)
                'tax_amount'             => 0.0,
                'base_tax_amount'        => 0.0,
                'shipping_amount'        => 0.0,
                'base_shipping_amount'   => 0.0,
                'discount_amount'        => 0.0,
                'base_discount_amount'   => 0.0,

                'created_at'             => now(),
                'updated_at'             => now(),
            ]);

            // 2. Billing address
            $billingAddr = $billing['address'] ?? [];

            OrderAddress::create([
                'order_id'      => $bagistoOrder->id,
                'address_type'  => 'billing',
                'first_name'    => $billing['name'] ?? '',
                'email'         => $billing['email'] ?? '',
                'phone'         => $billing['phone'] ?? '',
                'address1'      => $billingAddr['building'] ?? $billingAddr['address'] ?? '',
                'city'          => $billingAddr['city'] ?? '',
                'state'         => $billingAddr['state'] ?? '',
                'country'       => $billingAddr['country'] ?? '',
                'postcode'      => $billingAddr['area_code'] ?? '',
            ]);

            // 3. Shipping address (fulfillment → end.location → address)
            $endLocation = $fulfillment['end']['location'] ?? [];
            $endAddr     = $endLocation['address'] ?? [];
            $personName  = ($fulfillment['end']['person']['name'] ?? '') ?: ($billing['name'] ?? '');

            OrderAddress::create([
                'order_id'      => $bagistoOrder->id,
                'address_type'  => 'shipping',
                'first_name'    => $personName,
                'email'         => $fulfillment['end']['contact']['email'] ?? $billing['email'] ?? '',
                'phone'         => $fulfillment['end']['contact']['phone'] ?? $billing['phone'] ?? '',
                'address1'      => $endAddr['address'] ?? $endAddr['building'] ?? '',
                'city'          => $endAddr['city'] ?? '',
                'state'         => $endAddr['state'] ?? '',
                'country'       => $endAddr['country'] ?? '',
                'postcode'      => $endAddr['area_code'] ?? '',
            ]);

            // 4. Order items + ONDC product mapping into DB
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

                // 2. ondc_products.id = $ondcProductId → get product_id
                $ondcRow = DB::table('ondc_products')->where('id', $dbId)->first();

                if (!$ondcRow) {
                    Log::warning('ONDC_PRODUCT_MAPPING_NOT_FOUND', [
                        'txn_id'      => $txnId,
                        'ondc_id'     => $rawId,
                        'db_id'       => $dbId,
                    ]);
                    continue;
                }

                $product = Product::find($ondcRow->product_id);

                if (!$product) {
                    Log::warning('BAGISTO_PRODUCT_NOT_FOUND_BY_ID', [
                        'txn_id'     => $txnId,
                        'ondc_id'    => $rawId,
                        'product_id' => $ondcRow->product_id,
                    ]);
                    continue;
                }

                $qty = (int) ($item['quantity']['count'] ?? 1);

                // 3. Get unit price from quote.breakup → item by `@ondc/org/item_id`
                $itemPriceValue = 0.0;

                if (isset($quote['breakup']) && is_array($quote['breakup'])) {
                    foreach ($quote['breakup'] as $breakup) {
                        if (
                            ($breakup['@ondc/org/item_id'] ?? '') === $rawId
                            && ($breakup['@ondc/org/title_type'] ?? '') === 'item'
                        ) {
                            $itemPriceValue = (float) ($breakup['price']['value'] ?? 0.0);
                            break;
                        }
                    }
                }

                $unitPrice = $itemPriceValue;
                $total     = $unitPrice * $qty;

                // 4.1 Save ONDC product mapping
                // $ondcProduct = DB::table('ondc_products')->firstOrCreate(
                //     ['product_id' => $product->id],
                //     ['product_id' => $product->id] // just ensure it exists
                // );
                $ondcProduct = OndcProduct::firstOrCreate(
                    ['product_id' => $product->id],
                    ['product_id' => $product->id]
                );

                DB::table('ondc_product_mappings')->updateOrInsert(
                    ['ondc_item_id' => $rawId],
                    [
                        'ondc_item_id'    => $rawId,
                        'ondc_product_id' => $ondcProduct->id,
                        'product_id'      => $product->id,
                        'product_sku'     => $product->sku ?? null,
                    ]
                );

                // 4.2 Bagisto order item
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
                    'created_at'             => now(),
                    'updated_at'             => now(),
                ]);
            }

            // 5. Order payment
            OrderPayment::create([
                'order_id' => $bagistoOrder->id,
                'method' => $payment['type'] ?? 'ONDC',
                'method_title' => 'ONDC Payment',  // ✅ Bagisto expects this
                'status' => $payment['status'] ?? 'PAID',
                'amount' => (float) ($payment['params']['amount'] ?? 0.0),
                'base_amount' => (float) ($payment['params']['amount'] ?? 0.0),
                'additional' => [  // ✅ Use array (auto JSON encoded by model)
                    'ondc_transaction_id' => $payment['params']['transaction_id'] ?? null,
                    'bap_id' => $confirmData['context']['bap_id'] ?? null,
                    'collected_by' => $payment['collected_by'] ?? null,
                    'status' => $payment['status'] ?? null,
                    'amount' => $payment['params']['amount'] ?? null,
                    'currency' => $payment['params']['currency'] ?? 'INR',
                    'finder_fee_type' => $payment['@ondc/org/buyer_app_finder_fee_type'] ?? null,
                    'finder_fee_amount' => $payment['@ondc/org/buyer_app_finder_fee_amount'] ?? null,
                    'settlement' => $payment['@ondc/org/settlement_details'][0] ?? null,  // Full bank details
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

        // 6. ONDC `on_confirm` payload (no signer)
        $context = [
            'domain'           => $confirmData['context']['domain'] ?? 'ONDC:RET10',
            'country'          => $confirmData['context']['country'] ?? 'IND',
            'city'             => $confirmData['context']['city'] ?? 'std:022',
            'core_version'     => $confirmData['context']['core_version'] ?? '1.2.0',
            'action'           => 'on_confirm',
            'transaction_id'   => $txnId,
            'message_id'       => (string) Str::uuid(),
            'ttl'              => $confirmData['context']['ttl'] ?? 'PT30S',
            'bap_id'           => $bapId ?: $confirmData['context']['bap_id'],
            'bap_uri'          => $bapUri,
            'bpp_id'           => env('ONDC_BPP_ID'),
            'bpp_uri'          => env('ONDC_BPP_URI'),
            'timestamp'        => now()->toISOString(),
        ];

        $orderForReply = [
            'id'           => $order['id'] ?? 'O1',
            'state'        => 'Accepted',        // ← from your example

            'provider'     => $order['provider'] ?? [],
            'items'        => $this->buildItemsForOnInit($order['items'] ?? []),  // cast quantity.count to int
            'billing'      => $this->buildBillingForOnInit($billing),             // full address block
            'fulfillments' => $this->buildFulfillmentsForOnConfirm($order['fulfillments'] ?? []),
            'quote'              => $quote,                 // 1:1 from confirm
            'payment'            => $payment,               // 1:1 from confirm
            'tags'               => $order['tags'] ?? [],   // 1:1 from confirm
            'cancellation_terms' => $this->buildCancellationTerms(),

            'created_at'   => now()->toIso8601String(),
            'updated_at'   => now()->toIso8601String(),
        ];

        $responsePayload = [
            'context' => $context,
            'message' => [
                'order' => $orderForReply,
            ],
        ];
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

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);

        Log::info('BUILDING_ON_CONFIRM_PAYLOAD', [
            'txn_id'      => $txnId,
            'bap_uri'     => $bapUri,
            'item_count'  => count($orderItems),
            'domain'      => $context['domain'],
            'action'      => 'on_confirm',
        ]);

        // 7. Send plain JSON (no OndcSigner / signatureHeaders)
        $endpoints = [
            // [
            //     'url'     => $bapUri . '/on_confirm',
            //     'type'    => 'bap',
            //     'headers' => [
            //         'Accept'          => 'application/json',
            //         'Content-Type'    => 'application/json',
            //         'User-Agent'      => 'Bagisto-ONDC-BPP',
            //     ],
            // ],
            [
                'url'     => env('ONDC_BPP_URI', '') . 'on_confirm',
                'type'    => 'bpp_internal',
                'headers' => [
                    'Accept'          => 'application/json',
                    'Content-Type'    => 'application/json',
                    'X-Internal-Key'  => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'         => $bapUri,
                ],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'confirm', $responsePayload, $bapUri, $bapId);
        }
    }

    protected function buildFulfillmentsForOnConfirm($fulfillments)
    {
        $storeName = config('ondc.store.name', 'Store 1');
        $storeGps  = env('ONDC_STORE_GPS', '19.129076,72.825803');

        return collect($fulfillments)->map(function ($f) use ($storeName, $storeGps) {
            $withStart = $f;

            // Pramaan validates these as required strings — set defaults if missing
            $withStart['@ondc/org/provider_name'] = $f['@ondc/org/provider_name'] ?? $storeName;
            $withStart['@ondc/org/TAT']           = $f['@ondc/org/TAT']           ?? 'PT60M';
            $withStart['type']                    = $f['type']                   ?? 'Delivery';
            $withStart['tracking']                = $f['tracking']               ?? true;
            $withStart['id']                      = $f['id']                     ?? 'F1';

            // Add `start` block if missing
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
                            'start' => now()->toIso8601String(),
                            'end'   => now()->addMinutes(30)->toIso8601String(),
                        ],
                    ],
                    'contact' => [
                        'phone'  => config('ondc.store.care_phone', '9594663710'),
                        'email'  => config('ondc.store.care_email', 'nobody@nomail.com'),
                    ],
                ];
            }

            // Ensure `end.location.address.name` is populated (Pramaan flagged this)
            if (isset($withStart['end']['location']['address']) && empty($withStart['end']['location']['address']['name'])) {
                $withStart['end']['location']['address']['name'] = $withStart['end']['person']['name'] ?? 'Buyer';
            }

            // Add `state` descriptor if missing
            $withStart['state'] = $f['state'] ?? [
                'descriptor' => ['code' => 'Pending'],
            ];

            return $withStart;
        })->all();
    }
    /**
     * STATUS API — BAP requests current order status.
     *
     * Per ONDC spec: ACK synchronously, then async-push /on_status.
     * The actual on_status callback for state transitions is sent from
     * ProductConfirmationController::sendOnStatus when the seller advances
     * the order. Here we just ACK; if a status snapshot is available
     * (from ondc_confirm_payloads + orders.status) we can also push it.
     */
    public function status(Request $request)
    {
        $payload = $request->all();
        $context = $payload['context'] ?? [];
        $msg     = $payload['message'] ?? [];
        $txnId   = $context['transaction_id'] ?? Str::uuid()->toString();
        $orderId = $msg['order_id'] ?? null;

        Log::info('ONDC_STATUS_RECEIVED', [
            'ip'         => $request->ip(),
            'txn_id'     => $txnId,
            'order_id'   => $orderId,
        ]);

        // Validate the minimal shape
        if (empty($context) || empty($orderId)) {
            return response()->json([
                'message' => ['ack' => ['status' => 'NACK']],
                'error'   => [
                    'type'    => 'DOMAIN-ERROR',
                    'code'    => '30000',
                    'message' => 'message.order_id is required',
                ],
            ], 400);
        }

        try {
            \App\Models\OndcApiLog::create([
                'type'           => 'status_inbound',
                'endpoint'       => '/ondc/status',
                'request'        => json_encode($payload),
                'response'       => null,
                'status_code'    => 200,
                'transaction_id' => $txnId,
            ]);
        } catch (\Throwable $e) {
            // Non-fatal
        }

        // ACK immediately. Async on_status push happens via admin manual actions
        // (Packed / Picked / OFD / Delivered) or could be wired up here for
        // returning the current snapshot. For now keep ACK + log.
        $this->pushOnStatusSnapshot($context, $orderId);

        return response()->json([
            'message' => ['ack' => ['status' => 'ACK']],
        ]);
    }

    /**
     * Build + push an on_status snapshot if we have a saved confirm payload
     * for this transaction. Falls back silently if order isn't found.
     */
    protected function pushOnStatusSnapshot(array $context, string $orderId): void
    {
        try {
            $txnId  = $context['transaction_id'] ?? null;
            $bapUri = $context['bap_uri'] ?? null;
            $bapId  = $context['bap_id']  ?? null;

            if (!$txnId || !$bapUri) return;

            $confirmRow = DB::table('ondc_confirm_payloads')
                ->where('transaction_id', $txnId)
                ->first();
            if (!$confirmRow) return;

            $messageOrder = json_decode($confirmRow->message_order, true) ?? [];
            $orderRow     = DB::table('orders')->where('increment_id', $confirmRow->transaction_id)->first();
            $statusCode   = match ($orderRow->status ?? 'Pending') {
                'Packed'             => 'Packed',
                'Agent-assigned'     => 'Agent-assigned',
                'Order-picked-up'    => 'Order-picked-up',
                'Out-for-delivery'   => 'Out-for-delivery',
                'Order-delivered'    => 'Order-delivered',
                 'RTO-Delivered'     => 'RTO-Delivered',
                'canceled', 'Cancelled' => 'Cancelled',
                default              => 'Pending',
            };
            $orderState = match($statusCode) {
    'Order-delivered'  => 'Completed',
    'RTO-Delivered'    => 'Cancelled',   // RTO means order was cancelled + returned
    default            => 'In-progress',
};

            $payload = [
                'context' => [
                    'domain'         => $context['domain']       ?? 'ONDC:RET10',
                    'country'        => $context['country']      ?? 'IND',
                    'city'           => $context['city']         ?? 'std:080',
                    'core_version'   => $context['core_version'] ?? '1.2.0',
                    'action'         => 'on_status',
                    'bap_id'         => $bapId,
                    'bap_uri'        => $bapUri,
                    'bpp_id'         => env('ONDC_BPP_ID'),
                    'bpp_uri'        => env('ONDC_BPP_URI'),
                    'transaction_id' => $txnId,
                    'message_id'     => (string) Str::uuid(),
                    'timestamp'      => now()->toIso8601String(),
                    'ttl'            => 'PT30S',
                ],
                'message' => [
                    'order' => [
                        'id'           => $messageOrder['id'] ?? $orderId,
                        'state'        => $orderState,
                        'provider'     => $messageOrder['provider']     ?? [],
                        'items'        => $messageOrder['items']        ?? [],
                        'billing'      => $messageOrder['billing']      ?? [],
                        'fulfillments' => collect($messageOrder['fulfillments'] ?? [])->map(function ($f) use ($statusCode) {
                            $f['state'] = ['descriptor' => ['code' => $statusCode]];
                            $f['tracking'] = true;
                            return $f;
                        })->all(),
                        'quote'        => $messageOrder['quote']   ?? [],
                        'payment'      => $messageOrder['payment'] ?? [],
                        'created_at'   => $messageOrder['created_at'] ?? now()->toIso8601String(),
                        'updated_at'   => now()->toIso8601String(),
                    ],
                ],
            ];

            $url = rtrim($bapUri, '/') . '/on_status';
            \Illuminate\Support\Facades\Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBody(json_encode($payload, JSON_UNESCAPED_SLASHES), 'application/json')
                ->timeout(15)
                ->connectTimeout(5)
                ->post($url);

            \App\Models\OndcApiLog::create([
                'type'           => 'on_status_outbound',
                'endpoint'       => $url,
                'request'        => json_encode($payload, JSON_UNESCAPED_SLASHES),
                'response'       => null,
                'status_code'    => 200,
                'transaction_id' => $txnId,
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ONDC] on_status snapshot push failed', ['error' => $e->getMessage()]);
        }
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
        $bapUri = rtrim($ctxt['bap_uri'] ?? '', '/');
        $bapId  = $ctxt['bap_id'] ?? null;
        $orderId = $trackData['message']['order_id'] ?? null;

        if (!$bapUri) {
            return response()->json(['error' => 'bap_uri missing in context'], 400);
        }

        // Log incoming
        OndcTransaction::create([
            'transaction_id'  => $txnId,
            'action'          => 'track',
            'domain'          => $ctxt['domain'] ?? 'ONDC:RET14',
            'bap_id'          => $bapId,
            'bap_uri'         => $bapUri,
            'bpp_id'          => env('ONDC_BPP_ID'),
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

        // ACK immediately
        dispatch(function () use ($trackData, $txnId, $bapUri, $bapId, $orderId) {
            $this->sendOnTrack($trackData, $txnId, $bapUri, $bapId, $orderId);
        })->onQueue(env('ONDC_QUEUE', 'default'));

        return response()->json([
            'message' => ['ack' => ['status' => 'ACK']],
        ]);
    }

    /**
     * Build and send on_track response to BAP
     */
    protected function sendOnTrack($trackData, $txnId, $bapUri, $bapId, $orderId)
    {
        Log::info('SEND_ON_TRACK_JOB_STARTED', [
            'txn_id'   => $txnId,
            'bap_uri'  => $bapUri,
            'order_id' => $orderId,
        ]);

        $ctxt = $trackData['context'];

        // Get fulfillment GPS from ondc_confirm_payloads if available
        $trackingGps = env('ONDC_STORE_GPS', '19.129076,72.825803');
        $trackingUrl = env('ONDC_TRACKING_URL', rtrim(env('ONDC_BPP_URI'), '/') . '/tracking/' . $orderId);

        // Try to get store GPS from saved confirm payload
        $ondcPayload = \App\Models\OndcConfirmPayload::where('transaction_id', $txnId)->first();
        if ($ondcPayload) {
            $messageOrder = is_array($ondcPayload->message_order)
                ? $ondcPayload->message_order
                : json_decode($ondcPayload->message_order, true);

            $fulfillments = $messageOrder['fulfillments'] ?? [];
            if (!empty($fulfillments[0]['start']['location']['gps'])) {
                $trackingGps = $fulfillments[0]['start']['location']['gps'];
            }
        }
        // Extract fulfillment id from saved order (Pramaan validates tracking.id matches a fulfillment)
        $fulfillmentId = $messageOrder['fulfillments'][0]['id'] ?? 'F1';

        $responsePayload = [
            'context' => [
                'domain'         => $ctxt['domain']       ?? 'ONDC:RET10',
                'country'        => $ctxt['country']      ?? 'IND',
                'city'           => $ctxt['city']         ?? 'std:080',
                'core_version'   => $ctxt['core_version'] ?? '1.2.0',
                'action'         => 'on_track',
                'transaction_id' => $txnId,
                'message_id'     => (string) Str::uuid(),  // fresh — Pramaan rejects duplicates
                'ttl'            => $ctxt['ttl'] ?? 'PT30S',
                'bap_id'         => $bapId,
                'bap_uri'        => $bapUri,
                'bpp_id'         => env('ONDC_BPP_ID'),
                'bpp_uri'        => env('ONDC_BPP_URI'),
                'timestamp'      => now()->toIso8601String(),
            ],
            'message' => [
                'tracking' => [
                    'id'       => (string) $fulfillmentId,
                    'url'      => (string) $trackingUrl,
                    'status'   => 'active',
                    'location' => [
                        'gps'        => (string) $trackingGps,
                        'time'       => [
                            'timestamp' => now()->toIso8601String(),
                        ],
                        'updated_at' => now()->toIso8601String(),
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

        $endpoints = [
            // [
            //     'url'     => $bapUri . '/on_track',
            //     'type'    => 'bap',
            //     'headers' => [
            //         'Accept'       => 'application/json',
            //         'Content-Type' => 'application/json',
            //     ],
            // ],
            [
                'url'     => rtrim(env('ONDC_BPP_URI'), '/') . '/on_track',
                'type'    => 'bpp_internal',
                'headers' => [
                    'Accept'          => 'application/json',
                    'Content-Type'    => 'application/json',
                    'X-Internal-Key'  => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri'         => $bapUri,
                ],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'track', $responsePayload, $bapUri, $bapId);
        }
    }

    /**
     * Callback Handler - Routes on_* actions
     */
    public function callback(Request $request)
    {
        $payload = $request->all();
        $txnId = $payload['context']['transaction_id'] ?? null;
        $action = $payload['context']['action'] ?? null; // e.g., 'on_search', 'on_select'

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

        // ✅ FIX: Find the SPECIFIC transaction for this action (on_search, on_select, etc.)
        // Convert 'on_search' → 'search' to find the original outbound transaction
        $originalAction = str_replace('on_', '', $action);
        
        $transaction = OndcTransaction::where('transaction_id', $txnId)
            ->where('action', $originalAction)
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$transaction) {
            // Fallback: find any transaction with this txn_id
            $transaction = OndcTransaction::where('transaction_id', $txnId)
                ->orderBy('created_at', 'desc')
                ->first();
        }

        if ($transaction) {
            // ✅ UPDATE existing transaction (don't change action)
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

        // Log callback
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

    // Stub callback handlers (can be removed - all handled by callback())
    public function onSearch($payload) { return $this->ack(); }
    public function onSelect($payload) { return $this->ack(); }
    public function onInit($payload) { return $this->ack(); }
    public function onConfirm($payload) { return $this->ack(); }
    public function onStatus($payload) { return $this->ack(); }
    public function onCancel($payload) { return $this->ack(); }
    public function onUpdate($payload) { return $this->ack(); }

    protected function ack()
    {
        return response()->json([
            'message' => [
                'ack' => ['status' => 'ACK'],
            ],
        ], 200);
    }
}