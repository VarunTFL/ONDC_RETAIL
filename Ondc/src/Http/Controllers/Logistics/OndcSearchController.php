<?php

namespace Webkul\Ondc\Http\Controllers\Logistics;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class OndcSearchController extends Controller
{
    /**
     * Dispatch ONDC Logistics Search to Workbench
     * POST /ondc/logistics/search
     */
    public function search(Request $request)
    {
        try {
            $transactionId = (string) Str::uuid();
            $messageId     = (string) Str::uuid();
            $timestamp     = now()->utc()->format('Y-m-d\TH:i:s.v\Z');

            $pickupGps       = $request->input('pickup_gps', '12.4535445,77.9283792');
            $pickupPincode   = (string) $request->input('pickup_pincode', '560041');
            $deliveryGps     = $request->input('delivery_gps', '12.342769,77.9129423');
            $deliveryPincode = (string) $request->input('delivery_pincode', '636806');

            $packageWeight   = (float) $request->input('package_weight', 5);
            $length          = (float) $request->input('length', 100);
            $breadth         = (float) $request->input('breadth', 100);
            $height          = (float) $request->input('height', 100);
            $shipmentValue   = (float) $request->input('shipment_value', 50000);
            $packageCategory = $request->input('package_category', 'Grocery');

            // Payload aligned with ONDC v1.2.0 nic2004:60232
            $payload = [
                'context' => [
                    'domain'         => 'nic2004:60232',
                    'country'        => 'IND',
                    'city'           => 'std:080',
                    'action'         => 'search',
                    'core_version'   => '1.2.0',
                    'bap_id'         => '183.177.126.13:4443',
                    'bap_uri' => 'https://183.177.126.13:4443/bagisto/bagisto/public/ondc/logistics/seller',
                    'transaction_id' => $transactionId,
                    'message_id'     => $messageId,
                    'timestamp'      => $timestamp,
                    'ttl'            => 'PT30S',
                ],
                'message' => [
                    'intent' => [
                        'fulfillment' => [
                            'type'  => 'Delivery',
                            'stops' => [
                                [
                                    'id'       => 'L1',
                                    'type'     => 'start',
                                    'location' => [
                                        'gps'       => $pickupGps,
                                        'area_code' => $pickupPincode,
                                    ],
                                    'time'     => [
                                        'range' => [
                                            'start' => now()->addHour()->toIso8601ZuluString(),
                                            'end'   => now()->addHours(2)->toIso8601ZuluString(),
                                        ],
                                    ],
                                ],
                                [
                                    'id'       => 'L2',
                                    'type'     => 'end',
                                    'location' => [
                                        'gps'       => $deliveryGps,
                                        'area_code' => $deliveryPincode,
                                    ],
                                ],
                            ],
                        ],
                        'payment' => [
                            'collected_by' => 'BPP',
                            'type'         => 'POST-FULFILLMENT',
                        ],
                        '@ondc/org/payload_details' => [
                            'category' => $packageCategory,
                            'value'    => $shipmentValue,
                            'weight'   => [
                                'unit'  => 'kilogram',
                                'value' => $packageWeight,
                            ],
                            'dimensions' => [
                                'unit'   => 'centimeter',
                                'length' => ['value' => $length],
                                'breadth' => ['value' => $breadth],
                                'height' => ['value' => $height],
                            ],
                        ],
                    ],
                ],
            ];

            $targetUrl = 'https://workbench.ondc.tech/api-service/nic2004:60232/1.2.0/seller/search';

            Log::info('[ONDC Logistics] Outgoing /search payload to Workbench:', $payload);

            $gwResponse = Http::timeout(30)
                ->withHeaders(['Content-Type' => 'application/json'])
                ->post($targetUrl, $payload);

            $gwData    = $gwResponse->json();
            $ackStatus = data_get($gwData, 'message.ack.status', 'NACK');

            return response()->json([
                'success'        => $ackStatus === 'ACK',
                'transaction_id' => $transactionId,
                'message_id'     => $messageId,
                'ack_status'     => $ackStatus,
                'workbench_resp' => $gwData,
            ]);

        } catch (\Throwable $e) {
            Log::error('[ONDC Logistics] Search failed: ' . $e->getMessage());

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Receive on_search callback from Workbench
     * POST /ondc/logistics/on_search
     */
    public function on_search(Request $request)
    {
         Log::info('[LOGISTICS] ===== ON_SEARCH RECEIVED =====', [
    'url'     => $request->fullUrl(),
    'payload' => $request->all(),
]);

        $payload = $request->all();

        Log::info('[ONDC Logistics Callback] on_search received:', $payload);

        // Store provider/item catalog results from on_search callback
        $transactionId = data_get($payload, 'context.transaction_id');
        $providers     = data_get($payload, 'message.catalog.bpp/providers', []);

        foreach ($providers as $provider) {
            $items = data_get($provider, 'items', []);

            foreach ($items as $item) {
                DB::table('ondc_items')->updateOrInsert(
                    [
                        'transaction_id' => $transactionId,
                        'item_id'        => data_get($item, 'id'),
                    ],
                    [
                        'provider_id' => data_get($provider, 'id'),
                        'details'     => json_encode($item),
                        'created_at'  => now(),
                        'updated_at'  => now(),
                    ]
                );
            }
        }

        // Return ACK to Workbench
        return response()->json([
            'message' => [
                'ack' => [
                    'status' => 'ACK',
                ],
            ],
        ], 200);
    }
}