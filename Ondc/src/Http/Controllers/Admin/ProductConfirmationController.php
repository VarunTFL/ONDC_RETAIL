<?php

namespace Webkul\Ondc\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Ondc\DataGrids\OrderStatusDataGrid;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use App\Models\OndcTransaction;
use App\Models\OndcApiLog;
use Webkul\Ondc\Models\OndcOrderCancellation;
use Webkul\Ondc\Services\PartialCancellationService;
use Webkul\Ondc\Services\SellerCancellationService;
use Webkul\Ondc\Helpers\OndcSigner;
use Webkul\Ondc\Helpers\OndcHelper;
use Illuminate\Support\Facades\Cache;


class ProductConfirmationController extends Controller
{
    public function __construct(
        protected ?SellerCancellationService $sellerCancellationService = null,
        protected ?PartialCancellationService $partialService = null,
    ) {}

    /**
     * Show the Product Confirmation UI page.
     */
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(OrderStatusDataGrid::class)->process();
        }

        $savedProductIds = DB::table('ondc_products')->pluck('product_id')->toArray();

        return view('ondc::admin.product-confirmation.index', compact('savedProductIds'));
    }

    /**
     * Update status for an order (Packed / Assign Delivery / Order Pickup etc.).
     */
    public function markPacked($order_id)
    {
        return $this->updateOrderStatus($order_id, 'Packed', 'packed');
    }

    public function markAgentAssigned($order_id)
    {
        return $this->updateOrderStatus($order_id, 'Agent-assigned', 'agent_assigned');
    }

    public function markPicked($order_id)
    {
        return $this->updateOrderStatus($order_id, 'Order-picked-up', 'picked');
    }

    public function markOutForDelivery($order_id)
    {
        return $this->updateOrderStatus($order_id, 'Out-for-delivery', 'out_for_delivery');
    }

    public function markDelivered($order_id)
    {
        return $this->updateOrderStatus($order_id, 'Order-delivered', 'delivered');
    }

    // temporary
    public function markRtoDelivered($order_id)
    {
        $orderRow = DB::table('orders')->where('id', $order_id)->first();

        if (! $orderRow) {
            return redirect()->back()->with('error', 'Order not found.');
        }

        $cancellation = OndcOrderCancellation::where('order_id', $order_id)
            ->where('triggers_rto', true)
            ->where('rto_status', OndcOrderCancellation::RTO_INITIATED)
            ->latest()
            ->first();

        if (! $cancellation) {
            return redirect()->back()->with(
                'error',
                'No active RTO found.'
            );
        }

        try {

            app(SellerCancellationService::class)
                ->markRtoDelivered($cancellation, []);

            // USE SAME STANDARD FLOW
            return $this->updateOrderStatus(
                $order_id,
                'RTO-Delivered',
                'rto_delivered'
            );
        } catch (\Exception $e) {

            Log::error('[ONDC Admin] RTO Delivered failed', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);

            return redirect()->back()->with(
                'error',
                'RTO Delivered failed: ' . $e->getMessage()
            );
        }
    }

    // ✅ Single reusable method — all status transitions go through here
    // private function updateOrderStatus($order_id, string $dbStatus, string $ondcStatus)
    // {
    //     $orderRow = DB::table('orders')->where('id', $order_id)->first();

    //     if (!$orderRow) {
    //         return redirect()->back()->with('error', 'Order not found.');
    //     }

    //     $ondcPayload = DB::table('ondc_confirm_payloads')->where('transaction_id', $orderRow->increment_id)->first();
    //     if (!$ondcPayload) {
    //         return redirect()->back()->with('error', 'ONDC payload not found for this order.');
    //     }

    //     $txnId        = $ondcPayload->transaction_id;
    //     $bapUri       = $ondcPayload->bap_uri;
    //     $bapId        = $ondcPayload->bap_id;
    //     $messageOrder = json_decode($ondcPayload->message_order, true) ?? [];
    //     $context      = json_decode($ondcPayload->context, true) ?? [];

    //     // temporary
    //     //     if (empty($context)) {
    //     //     $context = [
    //     //         'domain'       => $ondcPayload->domain       ?? 'ONDC:RET10',
    //     //         'country'      => 'IND',
    //     //         'city'         => 'std:080',
    //     //         'core_version' => '1.2.0',
    //     //         'bap_id'       => $ondcPayload->bap_id  ?? '',
    //     //         'bap_uri'      => $ondcPayload->bap_uri ?? '',
    //     //         'bpp_id'       => env('ONDC_BPP_ID'),
    //     //         'bpp_uri'      => env('ONDC_BPP_URI'),
    //     //         'transaction_id' => $ondcPayload->transaction_id ?? '',
    //     //     ];
    //     // }

    //     DB::table('orders')->where('id', $order_id)->update([
    //         'status' => $dbStatus,
    //         'updated_at' => now()
    //     ]);

    //     $statusData = [
    //         // Echo the FULL inbound context so we preserve original
    //         // core_version / domain / city / country (Pramaan strict-validates these).
    //         'context' => array_merge($context, [
    //             'domain'  => $ondcPayload->domain ?: ($context['domain'] ?? 'ONDC:RET10'),
    //             'country' => $context['country'] ?? 'IND',
    //             'city'    => $context['city']    ?? 'std:080',
    //             'bap_id'  => $bapId,
    //             'bap_uri' => $bapUri,
    //         ]),
    //         'message' => [
    //             'order' => [
    //                 'id'           => $messageOrder['id'] ?? (string) $orderRow->increment_id,
    //                 'status'       => $ondcStatus,
    //                 'provider'     => $messageOrder['provider']     ?? [],
    //                 'items'        => $messageOrder['items']        ?? [],
    //                 'billing'      => $messageOrder['billing']      ?? [],
    //                 'fulfillments' => $messageOrder['fulfillments'] ?? [],
    //                 'quote'        => $messageOrder['quote']        ?? [],
    //                 'payment'      => $messageOrder['payment']      ?? [],
    //                 'created_at'   => $messageOrder['created_at']   ?? now()->toIso8601String(),
    //             ],
    //         ],
    //     ];

    //     $this->sendOnStatus($statusData, $txnId, $bapUri, $bapId);

    //     return redirect()->back()->with('success', "Order marked as {$dbStatus} and on_status sent.");
    // }

    /**
     * Unified order status updater for all domains (Retail RET10 & F&B RET11).
     */
    private function updateOrderStatus($order_id, string $dbStatus, string $ondcStatus)
    {
        // 1. Resolve order across both Bagisto orders and ONDC F&B orders
        $orderRow = DB::table('orders')
            ->where('id', $order_id)
            ->orWhere('increment_id', $order_id)
            ->first();

        $fnbOrder = null;

        if ($orderRow) {
            $txnId = $orderRow->increment_id;
            $fnbOrder = DB::table('ondc_fnb_orders')
                ->where('ondc_transaction_id', $txnId)
                ->first();
        } else {
            $fnbOrder = DB::table('ondc_fnb_orders')
                ->where('id', $order_id)
                ->orWhere('ondc_order_id', $order_id)
                ->orWhere('ondc_transaction_id', $order_id)
                ->first();

            if ($fnbOrder) {
                $txnId = $fnbOrder->ondc_transaction_id;
                $orderRow = DB::table('orders')->where('increment_id', $txnId)->first();
            }
        }

        if (! $orderRow && ! $fnbOrder) {
            return redirect()->back()->with('error', 'Order not found in Retail or F&B records.');
        }

        // 2. Fetch the stored ONDC transaction context
        $ondcPayload = DB::table('ondc_confirm_payloads')
            ->where('transaction_id', $txnId)
            ->first();

        if (! $ondcPayload) {
            return redirect()->back()->with('error', 'ONDC payload not found for this transaction.');
        }

        // 3. Normalize the ONDC Status code
        $statusMap = [
            'pending'          => 'Pending',
            'packed'           => 'Packed',
            'agent_assigned'   => 'Agent-assigned',
            'picked'           => 'Order-picked-up',
            'out_for_delivery' => 'Out-for-delivery',
            'delivered'        => 'Order-delivered',
            'rto_delivered'    => 'RTO-Delivered',
        ];

        $standardOndcStatus = $statusMap[strtolower($ondcStatus)]
            ?? $statusMap[strtolower(str_replace('-', '_', $dbStatus))]
            ?? $dbStatus;

        $orderState = match ($standardOndcStatus) {
            'Pending'         => 'Created',
            'Packed'          => 'Accepted',
            'Order-delivered' => 'Completed',
            'RTO-Delivered'   => 'Cancelled',
            default           => 'In-progress',
        };

        // 4. Update Database Tables & Timestamps
        try {
            // Update standard Bagisto orders table if present
            if ($orderRow) {
                DB::table('orders')->where('id', $orderRow->id)->update([
                    'status'     => $dbStatus,
                    'updated_at' => now(),
                ]);
            }

            // Update F&B orders table if present
            if ($fnbOrder) {
                $fnbUpdate = [
                    'fulfillment_state' => $standardOndcStatus,
                    'state'             => $orderState,
                    'updated_at'        => now(),
                ];

                $fnbUpdate += match ($standardOndcStatus) {
                    'Packed'           => ['packed_at' => now()],
                    'Order-picked-up'  => ['picked_at' => now()],
                    'Out-for-delivery' => ['out_for_delivery_at' => now()],
                    'Order-delivered'  => ['delivered_at' => now()],
                    default            => [],
                };

                DB::table('ondc_fnb_orders')->where('id', $fnbOrder->id)->update($fnbUpdate);
            }

            // 5. Synchronize Tracking Cache Gates
            if (in_array($standardOndcStatus, ['Order-picked-up', 'Out-for-delivery'], true)) {
                Cache::put("ondc:picked_up:{$txnId}", true, now()->addHours(2));
                Cache::put("ondc:tracking_enabled:{$txnId}", true, now()->addHours(2));
            }
            if ($standardOndcStatus === 'Order-delivered') {
                Cache::forget("ondc:tracking_enabled:{$txnId}");
            }
        } catch (\Throwable $e) {
            Log::error('[ONDC Admin] Status update failed in database', [
                'txn_id' => $txnId,
                'error'  => $e->getMessage(),
            ]);
        }

        // 6. Dispatch unsolicited on_status through ApiController
        $context = is_array($ondcPayload->context)
            ? $ondcPayload->context
            : (json_decode($ondcPayload->context, true) ?? []);

        $orderIdToSend = $fnbOrder
            ? $fnbOrder->ondc_order_id
            : ($orderRow->increment_id ?? $txnId);

        app(\Webkul\Ondc\Http\Controllers\ApiController::class)->sendOnStatus(
            $txnId,
            $orderIdToSend,
            $standardOndcStatus,
            $ondcPayload->bap_uri,
            $ondcPayload->bap_id,
            $context
        );

        // Release pending Track requests after successful Out-for-delivery on_status
        if ($standardOndcStatus === 'Out-for-delivery') {
            $this->releasePendingTrack($txnId);
        }

        return redirect()->back()->with('success', "Order marked as {$standardOndcStatus} and on_status dispatched.");
    }

    /**
     * Release any pending Track requests for this transaction after Out-for-delivery.
     */
    protected function releasePendingTrack(string $txnId): void
    {
        $lock = Cache::lock("ondc:lock:track_release:{$txnId}", 30);

        if (!$lock->get()) {
            Log::warning('[ONDC][TRACK] Could not acquire release lock', ['transaction_id' => $txnId]);
            return;
        }

        try {
            $listKey = "ondc:track:pending:list:{$txnId}";
            $msgIds = Cache::get($listKey, []);

            if (empty($msgIds)) {
                return;
            }

            foreach ($msgIds as $msgId) {
                $pendingKey = "ondc:track:pending:{$txnId}:{$msgId}";
                $trackData = Cache::get($pendingKey);

                if ($trackData) {
                    $ctxt    = $trackData['context'] ?? [];
                    $bapUri  = rtrim($ctxt['bap_uri'] ?? '', '/');
                    $bapId   = $ctxt['bap_id'] ?? null;
                    $orderId = $trackData['message']['order_id'] ?? null;

                    try {
                        app(\Webkul\Ondc\Http\Controllers\ApiController::class)->sendOnTrack(
                            $trackData,
                            $txnId,
                            $msgId,
                            $bapUri,
                            $bapId,
                            $orderId
                        );
                        Log::info('[ONDC][TRACK] Pending Track released and on_track sent', [
                            'transaction_id' => $txnId,
                            'message_id'     => $msgId,
                        ]);
                        // Only remove on success
                        Cache::forget($pendingKey);
                    } catch (\Throwable $e) {
                        Log::error('[ONDC][TRACK] Failed to release pending Track', [
                            'transaction_id' => $txnId,
                            'message_id'     => $msgId,
                            'error'          => $e->getMessage(),
                        ]);
                        // Keep entry and list membership; allow TTL expiry / future handling
                    }
                }
            }

            // Update list to only contain entries that were actually released successfully
            $remaining = [];
            foreach ($msgIds as $mid) {
                if (Cache::has("ondc:track:pending:{$txnId}:{$mid}")) {
                    $remaining[] = $mid;
                }
            }
            if (empty($remaining)) {
                Cache::forget($listKey);
            } else {
                Cache::put($listKey, $remaining, now()->addMinutes(30));
            }
        } finally {
            $lock->release();
        }
    }


    /**
     * Send on_status to BAP and internal BPP.
     *
     * Spec-compliant: echoes inbound core_version, generates fresh message_id,
     * builds complete context + message.order block.
     */
    protected function sendOnStatus(array $statusData, string $txnId, string $bapUri, ?string $bapId = null)
    {
        Log::info('[ONDC DEBUG] sendOnStatus called', [
            'txn_id'      => $txnId,
            'context_keys' => array_keys($statusData['context'] ?? []),
            'context'      => $statusData['context'] ?? [],
            'status'       => $statusData['message']['order']['status'] ?? 'unknown',
        ]);


        $order         = $statusData['message']['order'] ?? [];
        $currentStatus = $statusData['message']['order']['status'] ?? 'packed';
        $inboundCtx    = $statusData['context'] ?? [];

        $statusMap = [
            'pending'           => 'Pending',
            'packed'            => 'Packed',
            'agent_assigned'    => 'Agent-assigned',
            'picked'            => 'Order-picked-up',
            'out_for_delivery'  => 'Out-for-delivery',
            'delivered'         => 'Order-delivered',
            'rto_delivered'     => 'RTO-Delivered',
        ];
        $ondcStatusCode = $statusMap[$currentStatus] ?? 'Packed';
        $orderState = match ($ondcStatusCode) {
            'Order-delivered'  => 'Completed',
            'RTO-Delivered'    => 'Cancelled',   // RTO means order was cancelled + returned
            default            => 'In-progress',
        };

        $context = [
            'domain'         => $inboundCtx['domain']       ?? 'ONDC:RET10',
            'country'        => $inboundCtx['country']      ?? 'IND',
            'city'           => $inboundCtx['city']         ?? 'std:080',
            'core_version'   => $inboundCtx['core_version'] ?? '1.2.0',  // echo inbound
            'action'         => 'on_status',
            'bap_id'         => $bapId ?: ($inboundCtx['bap_id'] ?? null),
            'bap_uri'        => $bapUri,
            'bpp_id'         => env('ONDC_BPP_ID'),
            'bpp_uri'        => env('ONDC_BPP_URI'),
            'transaction_id' => $txnId,
            'message_id'     => (string) Str::uuid(),                    // fresh every call
            'timestamp'      => OndcHelper::timestamp(),
            'ttl'            => 'PT30S',
        ];

        $orderForReply = [
            'billing'      => $this->normalizeBilling($order['billing'] ?? []),
            'created_at'   => $order['created_at'] ?? OndcHelper::timestamp(),
            'documents' => [
                [
                    'label' => 'Invoice',
                    'url'   => 'https://ondc.sequelstring.com/seller/document/invoice.pdf',
                ],
            ],
            'fulfillments' => $this->buildFulfillmentsForOnStatus($order['fulfillments'] ?? [], $ondcStatusCode, $bapUri),
            'id'           => $order['id'] ?? 'O1',
            'items'        => $this->normalizeItems($order['items'] ?? []),
            'payment'      => $order['payment'] ?? [],
            'provider'     => $order['provider']    ?? [],
            'quote'        => $order['quote']   ?? [],
            'state'        => $orderState,
            'tags'         => array_merge([
                $this->buildBppTermsTags(),
            ], []),
            'updated_at' => OndcHelper::timestamp(),
        ];

        $responsePayload = [
            'context' => $context,
            'message' => [
                'order' => $orderForReply,
            ],
        ];

        $payloadJson = json_encode($responsePayload, JSON_UNESCAPED_SLASHES);
        if ($payloadJson === false) {

            Log::error('ONDC JSON ENCODE FAILED', [
                'error'   => json_last_error_msg(),
                'payload' => $responsePayload,
            ]);

            throw new \Exception(
                'JSON encode failed: ' . json_last_error_msg()
            );
        }

        Log::info('BUILDING_ON_STATUS_PAYLOAD', [
            'txn_id'    => $txnId,
            'bap_uri'   => $bapUri,
            'domain'    => $context['domain'],
            'action'    => 'on_status',
            'status'    => $currentStatus,
            'ondc_code' => $ondcStatusCode,
        ]);

        $endpoints = [
            [
                'url' => rtrim(env('ONDC_BPP_URI'), '/') . '/on_status',
                'type' => 'bpp_internal',
                'headers' => [
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                    'X-Internal-Key' => env('ONDC_INTERNAL_KEY', 'OtJU0H/jIrAXOEQX0PTe2AltvuOJDJ9wDYRT0OtGVkk='),
                    'bap_uri' => $bapUri,
                ],
            ],
        ];

        foreach ($endpoints as $endpoint) {
            $this->sendToEndpoint($endpoint, $payloadJson, $txnId, 'status', $responsePayload, $bapUri, $bapId);
        }
    }


    protected function buildFulfillmentsForOnStatus(array $fulfillments, string $statusCode, ?string $bapUri = null): array
    {
        $trackingBase = rtrim(env('ONDC_TRACKING_URL', 'https://retail-seller.softtrust.org/tracking'), '/');

        return collect($fulfillments)->map(function ($f) use ($statusCode, $trackingBase) {
            $withState = $f;
            $withState['state'] = [
                'descriptor' => [
                    'code' => $statusCode,
                ],
            ];

            // FIX: Tracking is only active during transit, not when delivered
            $withState['tracking'] = in_array($statusCode, ['Order-picked-up', 'Out-for-delivery'], true);

            if ($withState['tracking']) {
                $tags = $withState['tags'] ?? [];
                $hasTracking = collect($tags)->contains(fn($t) => ($t['code'] ?? '') === 'tracking');

                if (!$hasTracking) {
                    // FIX: Added missing mandatory schema fields
                    $tags[] = [
                        'code' => 'tracking',
                        'list' => [
                            ['code' => 'url', 'value' => "{$trackingBase}/{$f['id']}"],
                            ['code' => 'url_enabled', 'value' => 'yes'],
                            ['code' => 'gps_enabled', 'value' => 'yes'],
                        ],
                    ];
                    $withState['tags'] = $tags;
                }

                // Add routing tag (Retail P2P existing value)
                $tags[] = [
                    'code' => 'routing',
                    'list' => [
                        ['code' => 'type', 'value' => 'P2P'],
                    ],
                ];
                $withState['tags'] = $tags;
            } else {
                // Strip tracking tags if order is Delivered or Pending
                $withState['tags'] = collect($withState['tags'] ?? [])
                    ->reject(fn($t) => ($t['code'] ?? '') === 'tracking')
                    ->values()
                    ->all();
            }

            // Preserve existing start.time fields from original fulfillment ($f)
            $existingRangeStart = $f['start']['time']['range']['start'] ?? null;
            $existingRangeEnd   = $f['start']['time']['range']['end'] ?? null;
            $existingTimestamp  = $f['start']['time']['timestamp'] ?? null;

            if (!isset($withState['start'])) {
                $withState['start'] = [];
            }
            if (!isset($withState['start']['time'])) {
                $withState['start']['time'] = [];
            }
            if (!isset($withState['start']['time']['range'])) {
                $withState['start']['time']['range'] = [];
            }

            if (!isset($withState['start']['time']['range']['start']) && $existingRangeStart) {
                $withState['start']['time']['range']['start'] = $existingRangeStart;
            }
            if (!isset($withState['start']['time']['range']['end']) && $existingRangeEnd) {
                $withState['start']['time']['range']['end'] = $existingRangeEnd;
            }
            if (!isset($withState['start']['time']['timestamp']) && $existingTimestamp) {
                $withState['start']['time']['timestamp'] = $existingTimestamp;
            }

            // Only generate new timestamp if no legitimate value exists anywhere
            if (!isset($withState['start']['time']['range']['start'])) {
                $withState['start']['time']['range']['start'] = $existingRangeStart ?? OndcHelper::timestamp();
            }
            if (!isset($withState['start']['time']['range']['end'])) {
                $withState['start']['time']['range']['end'] = $existingRangeEnd ?? $withState['start']['time']['range']['start'] ?? OndcHelper::timestamp();
            }
            if (!isset($withState['start']['time']['timestamp'])) {
                $withState['start']['time']['timestamp'] = $existingTimestamp ?? OndcHelper::timestamp();
            }
            if (!isset($withState['start']['contact'])) {
                $withState['start']['contact'] = $f['start']['contact'] ?? [];
            }
            if (!isset($withState['start']['location'])) {
                $withState['start']['location'] = $f['start']['location'] ?? [];
            }

            return $withState;
        })->all();
    }
    /**
     * Ensure items have correct types: quantity.count as integer, all fields present.
     */
    protected function normalizeItems(array $items): array
    {
        return collect($items)->map(function ($item) {
            // Cast quantity.count to integer (Pramaan validates this type)
            if (isset($item['quantity']['count'])) {
                $item['quantity']['count'] = (int) $item['quantity']['count'];
            }
            return $item;
        })->values()->all();
    }

    /**
     * Ensure billing has every required field — fall back gracefully.
     * Pramaan's on_init/on_confirm/on_status validators require the full block.
     */
    protected function normalizeBilling(array $billing): array
    {
        if (empty($billing)) return $billing;

        $defaultAddress = [
            'name'      => '',
            'building'  => '',
            'locality'  => '',
            'city'      => '',
            'state'     => '',
            'country'   => 'IND',
            'area_code' => '',
        ];

        $billing['address']    = array_merge($defaultAddress, $billing['address'] ?? []);
        $billing['name']       = $billing['name']       ?? ($billing['address']['name'] ?? '');
        $billing['phone']      = $billing['phone']      ?? '';
        $billing['email']      = $billing['email']      ?? '';
        $billing['tax_number'] = $billing['tax_number'] ?? '';
        $billing['created_at'] = $billing['created_at'] ?? now()->toIso8601String();
        $billing['updated_at'] = $billing['updated_at'] ?? now()->toIso8601String();

        return $billing;
    }

    /**
     * Helper method to send payload to a single endpoint
     */
    protected function sendToEndpoint($endpoint, $payloadJson, $txnId, $actionType, $payload = [], $bapUri = null, $bapId = null)
    {
        $url = $endpoint['url'];
        $type = $endpoint['type'];
        $headers = $endpoint['headers'] ?? [];

        Log::info('SENDING_ON_' . strtoupper($actionType), [
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

            Log::info('ON_' . strtoupper($actionType) . '_RESPONSE', [
                'txn_id' => $txnId,
                'type' => $type,
                'url' => $url,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            OndcTransaction::create([
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

            OndcApiLog::create([
                'type' => "{$actionType}_outbound_{$type}",
                'endpoint' => $url,
                'request' => $payloadJson,
                'response' => $response->body(),
                'status_code' => $response->status(),
                'transaction_id' => $txnId,
            ]);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::info('ON_' . strtoupper($actionType) . '_SENT_AWAITING_CALLBACK', [
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
            Log::error('ON_' . strtoupper($actionType) . '_ERROR', [
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

    // ════════════════════════════════════════════════════════════
    //  CANCELLATION (admin / seller-side)
    // ════════════════════════════════════════════════════════════

    /**
     * Show cancel modal data — returns items + reasons for the cancel UI.
     * GET /admin/ondc/orders/{order_id}/cancel-data
     */
    public function cancelData($order_id)
    {
        $orderRow = DB::table('orders')->where('id', $order_id)->first();
        if (! $orderRow) {
            return response()->json(['success' => false, 'error' => 'Order not found'], 404);
        }

        $items = DB::table('order_items')
            ->where('order_id', $order_id)
            ->select('id', 'product_id', 'sku', 'name', 'qty_ordered', 'qty_canceled', 'price', 'total')
            ->get();

        $sellerReasons = \Webkul\Ondc\Models\OndcCancellationReason::where('is_active', true)
            ->where(function ($q) {
                $q->where('who_can_use', 'LIKE', '%SNP%')->orWhere('who_can_use', 'LIKE', '%LSP%');
            })
            ->get(['code', 'reason', 'applicable_for_part_cancel', 'triggers_rto']);

        $partialReasons = $sellerReasons->filter(function ($reason) {
            return (bool) $reason->applicable_for_part_cancel === true;
        })->values();

        return response()->json([
            'success' => true,
            'data'    => [
                'order_id'        => $order_id,
                'increment_id'    => $orderRow->increment_id,
                'order_status'    => $orderRow->status,
                'items'           => $items,
                'reasons_full'    => $sellerReasons,
                'reasons_partial' => $partialReasons,
            ],
        ]);
    }

    /**
     * Full order cancellation by admin (seller).
     * POST /admin/ondc/orders/{order_id}/cancel
     */
    public function cancelOrder(Request $request, $order_id)
    {
        $orderRow = DB::table('orders')->where('id', $order_id)->first();
        if (! $orderRow) {
            return redirect()->back()->with('error', 'Order not found.');
        }

        $ondcPayload = DB::table('ondc_confirm_payloads')->where('transaction_id', $orderRow->increment_id)->first();

        if (!$ondcPayload) {
            return redirect()->back()->with('error', 'ONDC payload not found for this order.');
        }

        $reasonId = $request->input('reason_id', '005');   // default: merchant rejected
        $note     = $request->input('note', '');
        $context  = json_decode($ondcPayload->context, true);

        try {
            $service = app(SellerCancellationService::class);

            $cancellation = $service->initiateSellerCancel([
                'ondc_order_id'              => $orderRow->increment_id,
                'transaction_id'             => $ondcPayload->transaction_id,
                'order_id'                   => $order_id,
                'cancellation_reason_id'     => $reasonId,
                // 'precancel_fulfillment_state' => $orderRow->status === 'Pending' ? 'Pending' : ucfirst(str_replace('_', '-', $orderRow->status ?? 'Pending')),
                'precancel_fulfillment_state' => $orderRow->status ?? 'Pending',
                'cancel_type'                => 'FULL',
                'cancelled_by'               => env('ONDC_BPP_ID'),
                'seller_note'                => $note,
            ]);

            // Update internal order status
            DB::table('orders')->where('id', $order_id)->update([
                'status'     => 'canceled',
                'updated_at' => now(),
            ]);

            // Build + send on_cancel to BAP
            $this->sendUnsolicitedOnCancel($cancellation, $ondcPayload, $orderRow);

            return redirect()->back()->with(
                'success',
                'Order cancelled successfully' .
                    ($cancellation->triggers_rto ? ' — RTO initiated' : '') .
                    '. Buyer notified via on_cancel.'
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', 'Cancel failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('[ONDC Admin] Full cancel failed', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
            ]);
            return redirect()->back()->with('error', 'Cancel failed: ' . $e->getMessage());
        }
    }

    /**
     * Partial cancellation by admin (seller).
     * POST /admin/ondc/orders/{order_id}/cancel/partial
     *
     * Body:
     *   reason_id      string (002 or 012 — applicable for part cancel)
     *   note           string
     *   items[]        array of {item_id, original_quantity, cancelled_quantity, item_price}
     */
    public function partialCancelOrder(Request $request, $order_id)
    {
        $request->validate([
            'reason_id'                   => 'required|string',
            'note'                        => 'nullable|string|max:500',
            'items'                       => 'required|array|min:1',
            'items.*.item_id'             => 'required|string',
            'items.*.original_quantity'   => 'required|integer|min:1',
            'items.*.cancelled_quantity'  => 'required|integer|min:1',
            'items.*.item_price'          => 'required|numeric|min:0',
        ]);

        $orderRow = DB::table('orders')->where('id', $order_id)->first();
        if (! $orderRow) {
            return redirect()->back()->with('error', 'Order not found.');
        }

        $ondcPayload = DB::table('ondc_confirm_payloads')
            ->where('transaction_id', $orderRow->increment_id)
            ->first();

        if (! $ondcPayload) {
            return redirect()->back()->with('error', 'ONDC payload not found for this order.');
        }

        try {
            $service = app(PartialCancellationService::class);

            $cancellation = $service->initiatePartialCancel([
                'ondc_order_id'               => $orderRow->increment_id,
                'transaction_id'              => $ondcPayload->transaction_id,
                'order_id'                    => $order_id,
                'cancellation_reason_id'      => $request->input('reason_id'),
                'precancel_fulfillment_state' => 'Pending',
                'cancel_type'                 => 'PARTIAL',
                'initiated_by'                => 'SELLER',
                'cancelled_by'                => env('ONDC_BPP_ID'),
                'items'                       => $request->input('items'),
                'seller_note'                 => $request->input('note', ''),
            ]);

            // Update qty_canceled in order_items
            foreach ($request->input('items') as $line) {
                DB::table('order_items')
                    ->where('order_id', $order_id)
                    ->where(function ($q) use ($line) {
                        $q->where('sku', $line['item_id'])
                            ->orWhere('product_id', $line['item_id']);
                    })
                    ->update([
                        'qty_canceled' => DB::raw('qty_canceled + ' . (int) $line['cancelled_quantity']),
                        'updated_at'   => now(),
                    ]);
            }

            // Build + send on_update to BAP
            $this->sendUnsolicitedOnUpdate($cancellation, $ondcPayload, $orderRow);

            return redirect()->back()->with(
                'success',
                "Partial cancellation processed. {$cancellation->cancelledItems()->count()} item line(s) cancelled. Buyer notified via on_update."
            );
        } catch (\InvalidArgumentException $e) {
            return redirect()->back()->with('error', 'Partial cancel failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            Log::error('[ONDC Admin] Partial cancel failed', [
                'order_id' => $order_id,
                'error'    => $e->getMessage(),
                'trace'    => $e->getTraceAsString(),
            ]);
            return redirect()->back()->with('error', 'Partial cancel failed: ' . $e->getMessage());
        }
    }

    /**
     * Build + push the on_cancel callback to the BAP.
     */
    protected function sendUnsolicitedOnCancel($cancellation, $ondcPayload, $orderRow)
    {
        $service = app(SellerCancellationService::class);
        $messageOrder = json_decode($ondcPayload->message_order, true) ?? [];
        $context      = json_decode($ondcPayload->context, true) ?? [];

        $payload = $service->buildUnsolicitedOnCancel($cancellation, [
            'domain'         => $ondcPayload->domain ?: ($context['domain'] ?? 'ONDC:RET10'),
            'country'        => $context['country'] ?? 'IND',
            'city'           => $context['city'] ?? 'std:080',
            'core_version'   => $context['core_version'] ?? '1.2.0',
            'bap_id'         => $ondcPayload->bap_id,
            'bap_uri'        => $ondcPayload->bap_uri,
            'items'          => $messageOrder['items']        ?? [],
            'provider'       => $messageOrder['provider']     ?? [],
            'billing'        => $messageOrder['billing']      ?? [],
            'fulfillments'   => $messageOrder['fulfillments'] ?? [],
            'quote'          => $messageOrder['quote']        ?? [],
            'payment'        => ! empty($messageOrder['payment'])
                ? $messageOrder['payment']
                : $this->buildPaymentFromOrder($orderRow, $ondcPayload),
            'fulfillment_id' => $messageOrder['fulfillments'][0]['id'] ?? null,
            'order_total'    => $orderRow->grand_total ?? 0,
            'currency'       => $orderRow->order_currency_code ?? 'INR',
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url         = rtrim($ondcPayload->bap_uri, '/') . '/on_cancel';

        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBody($payloadJson, 'application/json')
                ->timeout(30)
                ->connectTimeout(10)
                ->post($url);

            OndcApiLog::create([
                'type'           => 'on_cancel_outbound',
                'endpoint'       => $url,
                'request'        => $payloadJson,
                'response'       => $response->body(),
                'status_code'    => $response->status(),
                'transaction_id' => $ondcPayload->transaction_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('[ONDC] on_cancel push failed', [
                'transaction_id' => $ondcPayload->transaction_id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build + push the on_update callback (for partial cancel) to the BAP.
     */
    protected function sendUnsolicitedOnUpdate($cancellation, $ondcPayload, $orderRow)
    {
        $service = app(PartialCancellationService::class);
        $messageOrder = json_decode($ondcPayload->message_order, true) ?? [];

        $payload = $service->buildOnUpdatePartialCancelPayload($cancellation, [
            'bap_id'       => $ondcPayload->bap_id,
            'bap_uri'      => $ondcPayload->bap_uri,
            'items'        => $messageOrder['items']        ?? [],
            'provider'     => $messageOrder['provider']     ?? [],
            'billing'      => $messageOrder['billing']      ?? [],
            'fulfillments' => $messageOrder['fulfillments'] ?? [],
            'quote'        => $messageOrder['quote']        ?? [],
            'state'        => 'Created',
        ]);

        $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $url         = rtrim($ondcPayload->bap_uri, '/') . '/on_update';

        try {
            $response = Http::withHeaders([
                'Accept'       => 'application/json',
                'Content-Type' => 'application/json',
            ])
                ->withBody($payloadJson, 'application/json')
                ->timeout(30)
                ->connectTimeout(10)
                ->post($url);

            OndcApiLog::create([
                'type'           => 'on_update_outbound',
                'endpoint'       => $url,
                'request'        => $payloadJson,
                'response'       => $response->body(),
                'status_code'    => $response->status(),
                'transaction_id' => $ondcPayload->transaction_id,
            ]);
        } catch (\Exception $e) {
            Log::warning('[ONDC] on_update push failed', [
                'transaction_id' => $ondcPayload->transaction_id,
                'error'          => $e->getMessage(),
            ]);
        }
    }

    protected function buildPaymentFromOrder($orderRow, $ondcPayload): array
    {
        $payment = DB::table('order_payment')->where('order_id', $orderRow->id)->first();
        $additional = [];

        if ($payment && ! empty($payment->additional)) {
            $additional = json_decode($payment->additional, true) ?: [];
        }

        return [
            'params' => [
                'amount'         => (string) ($additional['amount'] ?? $orderRow->grand_total ?? '0.00'),
                'currency'       => $additional['currency'] ?? $orderRow->order_currency_code ?? 'INR',
                'transaction_id' => $additional['ondc_transaction_id'] ?? $ondcPayload->transaction_id,
            ],
            'status'       => $additional['status'] ?? $payment->status ?? 'PAID',
            'type'         => $payment->method ?? 'ON-ORDER',
            'collected_by' => $additional['collected_by'] ?? 'BAP',
        ];
    }
}
