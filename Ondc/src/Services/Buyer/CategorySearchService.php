<?php

namespace Webkul\Ondc\Services\Buyer;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CategorySearchService
{
    // ── Domain map: category slug → ONDC domain code ────────────────────────
    private const DOMAIN_MAP = [
        'grocery'                    => 'ONDC:RET10',
        'food-beverages'             => 'ONDC:RET11',
        'food-and-beverages'         => 'ONDC:RET11',
        'fashion'                    => 'ONDC:RET12',
        'clothing'                   => 'ONDC:RET12',
        'apparel'                    => 'ONDC:RET12',
        'bpc'                        => 'ONDC:RET13',
        'beauty'                     => 'ONDC:RET13',
        'skincare'                   =>  'ONDC:RET13',
        'personal-care'              => 'ONDC:RET13',
        'electronics'                => 'ONDC:RET14',
        'mobile-phones'              => 'ONDC:RET14',
        'mobile-phones-accessories'  => 'ONDC:RET14',
        'mobiles'                    => 'ONDC:RET14',
        'phones'                     => 'ONDC:RET14',
        'laptops'                    => 'ONDC:RET14',
        'computers'                  => 'ONDC:RET14',
        'appliances'                 => 'ONDC:RET15',
        'home-appliances'            => 'ONDC:RET15',
        'kitchen-appliances'         => 'ONDC:RET15',
        'home-kitchen'               => 'ONDC:RET16',
        'home-decor'                 => 'ONDC:RET16',
        'furniture'                  => 'ONDC:RET16',
        'health-wellness'            => 'ONDC:RET18',
        'fitness'                    => 'ONDC:RET18',
        'pharma'                     => 'ONDC:RET19',
        'medicines'                  => 'ONDC:RET19',
    ];

    /**
     * Keyword → domain heuristic.
     * Used when a free-text search keyword arrives and the slug isn't in DOMAIN_MAP.
     * Order matters: more specific hints first.
     */
    private const KEYWORD_DOMAIN_HINTS = [
        // Electronics / Mobile (RET14)
        'mobile'    => 'ONDC:RET14',
        'phone'     => 'ONDC:RET14',
        'laptop'    => 'ONDC:RET14',
        'tablet'    => 'ONDC:RET14',
        'earphone'  => 'ONDC:RET14',
        'headphone' => 'ONDC:RET14',
        'charger'   => 'ONDC:RET14',
        'speaker'   => 'ONDC:RET14',
        'camera'    => 'ONDC:RET14',
        'computer'  => 'ONDC:RET14',
        'keyboard'  => 'ONDC:RET14',
        'monitor'   => 'ONDC:RET14',
        // Appliances (RET15)
        'fridge'       => 'ONDC:RET15',
        'refrigerator' => 'ONDC:RET15',
        'washing'      => 'ONDC:RET15',
        'microwave'    => 'ONDC:RET15',
        'air condition' => 'ONDC:RET15',
        'mixer'        => 'ONDC:RET15',
        'grinder'      => 'ONDC:RET15',
        // Fashion (RET12)
        'shirt'   => 'ONDC:RET12',
        'trouser' => 'ONDC:RET12',
        'saree'   => 'ONDC:RET12',
        'kurta'   => 'ONDC:RET12',
        'jeans'   => 'ONDC:RET12',
        'dress'   => 'ONDC:RET12',
        'shoes'   => 'ONDC:RET12',
        'sandal'  => 'ONDC:RET12',
        'sneaker' => 'ONDC:RET12',
        'tshirt'  => 'ONDC:RET12',
        'jacket'  => 'ONDC:RET12',
        // BPC (RET13)
        'cream'    => 'ONDC:RET13',
        'shampoo'  => 'ONDC:RET13',
        'soap'     => 'ONDC:RET13',
        'lipstick' => 'ONDC:RET13',
        'perfume'  => 'ONDC:RET13',
        'lotion'   => 'ONDC:RET13',
        // Grocery (RET10)
        'rice'      => 'ONDC:RET10',
        'wheat'     => 'ONDC:RET10',
        'dal'       => 'ONDC:RET10',
        'vegetable' => 'ONDC:RET10',
        'fruit'     => 'ONDC:RET10',
        'milk'      => 'ONDC:RET10',
        'bread'     => 'ONDC:RET10',
        'Tea and Coffee' => 'ONDC:RET10',
        // Food & Beverages (RET11)
        // 'coffee'  => 'ONDC:RET11',
        // 'tea'     => 'ONDC:RET11',
        'juice'   => 'ONDC:RET11',
        'snack'   => 'ONDC:RET11',
        'biscuit' => 'ONDC:RET11',
        // Home & Kitchen (RET16)
        'cookware'  => 'ONDC:RET16',
        'utensil'   => 'ONDC:RET16',
        'furniture' => 'ONDC:RET16',
        'curtain'   => 'ONDC:RET16',
        'bedsheet'  => 'ONDC:RET16',
        'pillow'    => 'ONDC:RET16',
        // Health (RET18)
        'protein'    => 'ONDC:RET18',
        'vitamin'    => 'ONDC:RET18',
        'supplement' => 'ONDC:RET18',
        // Pharma (RET19)
        'medicine' => 'ONDC:RET19',
        'capsule'  => 'ONDC:RET19',
        'syrup'    => 'ONDC:RET19',
    ];

    private const SWITCH_URL    = 'https://retail-buyer.softtrust.org/search';
    private const INTERNAL_KEY  = 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY=';

    /**
     * Send an ONDC /search request to the Switch.
     *
     * Returns an array with:
     *   transaction_id  string   UUID of the request
     *   message_id      string   UUID of the message
     *   ack_status      string   'ACK' | 'NACK' | 'ERROR'
     *
     * Callers MUST check ack_status before polling. If 'NACK' or 'ERROR',
     * polling will always return empty (the Switch never registered the txn).
     *
     * @param  string  $categorySlug   e.g. "mobile-phones-accessories"
     * @param  string  $itemKeyword    forwarded in intent.item.descriptor.name
     * @param  string  $domain         explicit ONDC domain; '' = auto-detect
     */
    public function search(
        string $categorySlug,
        string $itemKeyword = '',
        string $domain      = '',
         string $gps       = '12.974002,77.613458',   // ←temporary
    string $areaCode  = '560001'   
    ): array {
        $domain        = $this->resolveDomain($categorySlug, $itemKeyword, $domain);
        $transactionId = (string) Str::uuid();
        $messageId     = (string) Str::uuid();

        // ── Build intent ─────────────────────────────────────────────────────
        // Per the ONDC Switch API spec (Section 3.2), the /search intent contains:
        //   - item.descriptor.name   (the search keyword)
        //   - fulfillment            (delivery location)
        // DO NOT include intent.category — the Switch does not accept arbitrary
        // human-readable strings there and returns error 80215 when it is present.
        // DO NOT include intent.payment — not part of the /search spec example.
        $intent = [];

        // Only add item block when a keyword is provided.
        // Strip special characters (&, /, +) that can cause Switch validation errors.
        $cleanKeyword = trim(preg_replace('/[&\/\\\+]+/', ' ', $itemKeyword));
        $cleanKeyword = preg_replace('/\s+/', ' ', $cleanKeyword);

        if ($cleanKeyword !== '') {
            $intent['item'] = [
                'descriptor' => [
                    'name' => $cleanKeyword,
                ],
            ];
        }

       $intent['fulfillment'] = [
            'type' => 'Delivery',
            'end'  => [
                'location' => [
                    'gps'     => $gps,
                    'address' => [
                        'area_code' => $areaCode,
                    ],
                ],
            ],
        ];

        $intent['payment'] = [
            "@ondc/org/buyer_app_finder_fee_type" => 'percent',
            "@ondc/org/buyer_app_finder_fee_amount"  => "3",
        ];
        // "payment": {
        //     "@ondc/org/buyer_app_finder_fee_type": "percent",
        //     "@ondc/org/buyer_app_finder_fee_amount": "3"
        // }

        $payload = [
            'context' => [
                'domain'         => $domain,
                'action'         => 'search',
                'country'        => 'IND',
                'city'           => env('ONDC_CITY', 'std:080'),
                'core_version'   => '1.2.0',
                'bap_id'         => 'retail-buyer.softtrust.org',
                'bap_uri'        => 'https://retail-buyer.softtrust.org/',
                'transaction_id' => $transactionId,
                'message_id'     => $messageId,
                'timestamp'      => now()->toIso8601String(),
                'ttl'            => 'PT30S',
            ],
            //temporary
            // Replace with:
            'message' => [
                'intent' => $intent,
            ],
        ];

        Log::info('ONDC /search dispatched', [
            'url'            => self::SWITCH_URL,
            'transaction_id' => $transactionId,
            'message_id'     => $messageId,
            'category_slug'  => $categorySlug,
            'keyword'        => $cleanKeyword,
            'domain'         => $domain,
            'payload' => $payload
        ]);

        DB::table('ondc_flow_log')->insert([
            'transaction_id'     => $transactionId,
            'action'             => 'search',
            'status'             => 'sent',
            'bagisto_product_id' => null,
            'payload'            => json_encode($payload),
            'created_at'         => now(),
        ]);

         $ackStatus = 'ERROR';

        try {
            $response = Http::timeout(15)
                ->retry(1, 200)
                ->withHeaders([
                    'Content-Type'   => 'application/json',
                    'X-Internal-Key' => self::INTERNAL_KEY,
                ])
                ->post(self::SWITCH_URL, $payload);

            $responseBody = $response->json();
            $ackStatus    = data_get($responseBody, 'message.ack.status', 'ERROR');

            Log::info('SearchResponse: ',[
                'CategorySearchServiceGetResponseDetails' => $response
            ]);

            if ($response->successful() && $ackStatus === 'ACK') {
                Log::info('ONDC /search ACK received', [
                    'transaction_id' => $transactionId,
                    'status'         => $response->status(),
                ]);

                // Cache slug only on ACK — no point caching a failed txn.
                Cache::put(
                    "ondc_txn_slug:{$transactionId}",
                    $categorySlug,
                    now()->addMinutes(10)
                );
            } else {
                Log::warning('ONDC /search NACK or error — polling will be skipped', [
                    'transaction_id' => $transactionId,
                    'http_status'    => $response->status(),
                    'ack_status'     => $ackStatus,
                    'error_code'     => data_get($responseBody, 'error.code'),
                    'error_message'  => data_get($responseBody, 'error.message'),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ONDC /search HTTP exception — polling will be skipped', [
                'message'        => $e->getMessage(),
                'category_slug'  => $categorySlug,
                'transaction_id' => $transactionId,
            ]);
            $ackStatus = 'ERROR';
        }

        return [
            'transaction_id' => $transactionId,
            'message_id'     => $messageId,
            'ack_status'     => $ackStatus,   // 'ACK' | 'NACK' | 'ERROR'
        ];
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Resolve the ONDC domain with this priority:
     *   1. Explicit $domain param (non-empty, not the generic placeholder)
     *   2. Slug exact match in DOMAIN_MAP
     *   3. Keyword substring match in KEYWORD_DOMAIN_HINTS
     *   4. Fallback: ONDC:RET14 (Electronics — broadest test catalog)
     */
    // private function resolveDomain(string $slug, string $keyword, string $explicitDomain): string
    // {
    //     if ($explicitDomain !== '' && $explicitDomain !== 'ONDC:RET14') {
    //         return $explicitDomain;
    //     }

    //     if (isset(self::DOMAIN_MAP[$slug])) {
    //         return self::DOMAIN_MAP[$slug];
    //     }

    //     $lower = strtolower($keyword);
    //     foreach (self::KEYWORD_DOMAIN_HINTS as $hint => $domain) {
    //         if (str_contains($lower, $hint)) {
    //             return $domain;
    //         }
    //     }

    //     return $explicitDomain ?: 'ONDC:RET14';
    // }

  // Replace the current resolveDomain with this:
private function resolveDomain(string $slug, string $keyword = '', string $explicitDomain = ''): string
{
    // 1. Explicit domain passed in (non-empty, non-placeholder)
    if ($explicitDomain !== '' && $explicitDomain !== 'ONDC:RET14') {
        return $explicitDomain;
    }

    // 2. Exact slug match
    if (isset(self::DOMAIN_MAP[strtolower($slug)])) {
        return self::DOMAIN_MAP[strtolower($slug)];
    }

    // 3. Keyword hint match
    $keywordLower = strtolower($keyword);
    foreach (self::KEYWORD_DOMAIN_HINTS as $hint => $domain) {
        if (str_contains($keywordLower, $hint)) {
            return $domain;
        }
    }

    // 4. Fallback
    return 'ONDC:RET10';
}
}