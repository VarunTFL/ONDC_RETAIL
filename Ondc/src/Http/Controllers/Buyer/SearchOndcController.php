<?php

namespace Webkul\Ondc\Http\Controllers\Buyer;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Services\Buyer\CategorySearchService;
use Webkul\Ondc\Services\Buyer\CatalogIngestionService;

/**
 * SearchOndcController
 *
 * Powers ONDC product discovery on the website's keyword search page.
 *
 * POST /ondc/buyer/keyword-search
 * Body: { "keyword": "laptop" }
 *
 * Returns:
 * { "success": true, "transaction_id": "...", "items": [...] }
 */
class SearchOndcController extends Controller
{
    private const BPP_SUPPRESSION_MINUTES   = 15;
    private const NACK_SUPPRESSION_THRESHOLD = 3;
    private const POLL_ATTEMPTS             = 5;
    private const POLL_SLEEP_SECS           = 3;

    public function __construct(
        protected CategorySearchService  $searchService,
        protected CatalogIngestionService $ingestionService
    ) {}

    public function search(Request $request)
    {
        $keyword = trim((string) $request->input('keyword', ''));
        $domain  = trim((string) $request->input('domain', ''));

        if ($keyword === '') {
            return response()->json(['error' => 'keyword is required'], 422);
        }

        try {
            /*
            |------------------------------------------------------------------
            | STEP 1: Dispatch /search
            | CategorySearchService sanitises the keyword and builds the correct
            | intent payload (item + fulfillment only — per spec).
            |------------------------------------------------------------------
            */
            $categorySlug = $this->keywordToSlug($keyword);

            $ids = $this->searchService->search(
                categorySlug: $categorySlug,
                itemKeyword:  $keyword,
                domain:       $domain
            );

            $transactionId = $ids['transaction_id'];
            $messageId     = $ids['message_id'];
            $ackStatus     = $ids['ack_status'];   // 'ACK' | 'NACK' | 'ERROR'

            Log::info('ONDC keyword-search dispatched', [
                'keyword'        => $keyword,
                'transaction_id' => $transactionId,
                'ack_status'     => $ackStatus,
            ]);

            /*
            |------------------------------------------------------------------
            | STEP 1b: Skip polling if Switch rejected the /search
            |          Avoids 15 s of wasted polls (confirmed in logs: error 80215)
            |------------------------------------------------------------------
            */
            if ($ackStatus !== 'ACK') {
                Log::warning('ONDC keyword-search: /search NACK — skipping poll', [
                    'transaction_id' => $transactionId,
                    'ack_status'     => $ackStatus,
                    'keyword'        => $keyword,
                ]);

                return response()->json([
                    'success'        => true,
                    'transaction_id' => $transactionId,
                    'items'          => [],
                    'message'        => 'ONDC search not acknowledged by Switch',
                ]);
            }

            /*
            |------------------------------------------------------------------
            | STEP 2: Poll for on_search
            |------------------------------------------------------------------
            */
            $onSearchPayload = null;

            for ($attempt = 1; $attempt <= self::POLL_ATTEMPTS; $attempt++) {
                Log::info("ONDC keyword-search poll {$attempt}/" . self::POLL_ATTEMPTS, [
                    'transaction_id' => $transactionId,
                ]);

                $response = Http::timeout(30)
                    ->withHeaders([
                        'X-Internal-Key' => 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY=',
                        'Content-Type'   => 'application/json',
                    ])
                    ->post('https://retail-buyer.softtrust.org/api/get_ondc_response', [
                        'transaction_id' => $transactionId,
                        'message_id'     => $messageId,
                        'action'         => 'on_search',
                    ]);

                Log::info('ONDC keyword-search poll response', [
                    'attempt' => $attempt,
                    'status'  => $response->status(),
                    'body'    => $response->body(),
                ]);

                if ($response->successful()) {
                    $data = $response->json();

                    if (
                        isset($data['response_code'])
                        && $data['response_code'] == 1
                        && ! empty($data['response'])
                    ) {
                        $onSearchPayload = $data['response'];

                        Log::info('ONDC keyword-search on_search received', [
                            'transaction_id' => $transactionId,
                            'responses'      => count($data['response']),
                        ]);
                        break;
                    }
                }

                if ($attempt < self::POLL_ATTEMPTS) {
                    sleep(self::POLL_SLEEP_SECS);
                }
            }

            /*
            |------------------------------------------------------------------
            | STEP 3: Poll exhausted
            |------------------------------------------------------------------
            */
            if (! $onSearchPayload) {
                Log::warning('ONDC keyword-search poll exhausted', [
                    'transaction_id' => $transactionId,
                    'keyword'        => $keyword,
                ]);

                return response()->json([
                    'success'        => true,
                    'transaction_id' => $transactionId,
                    'items'          => [],
                    'message'        => 'on_search not available yet',
                ]);
            }

            /*
            |------------------------------------------------------------------
            | STEP 4: Ingest catalog
            |------------------------------------------------------------------
            */
            foreach ($onSearchPayload as $catalogResponse) {
                $bppId = data_get($catalogResponse, 'context.bpp_id', 'unknown-bpp');

                if ($this->isSuppressedBpp($bppId)) {
                    Log::info('ONDC keyword-search: skipping suppressed BPP', [
                        'bpp_id' => $bppId,
                    ]);
                    continue;
                }

                if (
                    ($catalogResponse['message']['ack']['status'] ?? null) === 'NACK'
                    || ! empty($catalogResponse['error'])
                ) {
                    $this->recordBppNack($bppId);
                    Log::info('ONDC keyword-search: BPP returned NACK', [
                        'bpp_id' => $bppId,
                        'error'  => $catalogResponse['error']['message'] ?? null,
                    ]);
                    continue;
                }

                $catalog   = data_get($catalogResponse, 'message.catalog', []);
                $providers = $catalog['bpp/providers'] ?? $catalog['providers'] ?? [];

                $providersWithItems = array_filter(
                    $providers,
                    fn ($p) => ! empty($p['items'] ?? [])
                );

                if (! empty($providersWithItems)) {
                    Cache::put(
                        "ondc_txn_slug:{$transactionId}",
                        $categorySlug,
                        now()->addMinutes(10)
                    );

                    $count = $this->ingestionService->ingest($catalogResponse,$categorySlug, $keyword);

                    Log::info('ONDC keyword-search catalog ingested', [
                        'bpp_id'     => $bppId,
                        'item_count' => $count,
                    ]);
                }
            }

            /*
            |------------------------------------------------------------------
            | STEP 5: Return stored items — first try exact transaction match,
            |         then fall back to recent keyword name match
            |------------------------------------------------------------------
            */
            $items = DB::table('ondc_items')
                ->where('transaction_id', $transactionId)
                ->orderByDesc('search_score')
                ->orderByDesc('updated_at')
                ->get();

            if ($items->isEmpty()) {
                $items = DB::table('ondc_items')
                    ->where('name', 'like', '%' . $keyword . '%')
                    ->where('updated_at', '>=', now()->subMinutes(30))
                    ->orderByDesc('search_score')
                    ->orderByDesc('updated_at')
                    ->limit(40)
                    ->get();
            }

            return response()->json([
                'success'        => true,
                'transaction_id' => $transactionId,
                'items'          => $items,
            ]);

        } catch (\Throwable $e) {
            Log::error('ONDC keyword-search failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
                'keyword' => $keyword,
            ]);

            return response()->json([
                'success'        => true,
                'transaction_id' => null,
                'items'          => [],
                'message'        => 'ONDC unavailable',
            ]);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function keywordToSlug(string $keyword): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($keyword)));
    }

    private function isSuppressedBpp(string $bppId): bool
    {
        return Cache::has("ondc_bpp_suppressed:{$bppId}");
    }

    private function recordBppNack(string $bppId): void
    {
        $countKey = "ondc_bpp_nack_count:{$bppId}";
        $count    = Cache::increment($countKey);

        if ($count === 1) {
            Cache::put($countKey, $count, now()->addMinutes(self::BPP_SUPPRESSION_MINUTES));
        }

        if ($count >= self::NACK_SUPPRESSION_THRESHOLD) {
            Cache::put(
                "ondc_bpp_suppressed:{$bppId}",
                true,
                now()->addMinutes(self::BPP_SUPPRESSION_MINUTES)
            );
        }
    }
}