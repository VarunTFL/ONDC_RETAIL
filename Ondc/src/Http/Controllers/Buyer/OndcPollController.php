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

class OndcPollController extends Controller
{
    private const BPP_SUPPRESSION_MINUTES   = 15;
    private const NACK_SUPPRESSION_THRESHOLD = 3;

    private const POLL_ATTEMPTS   = 5;
    private const POLL_SLEEP_SECS = 3;

    protected $searchService;
    protected $ingestionService;

    public function __construct(
        CategorySearchService  $searchService,
        CatalogIngestionService $ingestionService
    ) {
        $this->searchService   = $searchService;
        $this->ingestionService = $ingestionService;
    }

    public function searchAndPoll(Request $request)
    {
        $categorySlug = $request->input('category_slug');
        $keyword      = $request->input('keyword', $categorySlug);
        $domain       = (string) $request->input('domain', '');
        $gps          = $request->input('gps', '12.974002,77.613458');      // temporary;
        $areaCode     = $request->input('area_code', '560001');    

        if (! $categorySlug) {
            return response()->json(['error' => 'category_slug is required'], 422);
        }

        try {
            /*
            |------------------------------------------------------------------
            | STEP 1: Dispatch /search
            |------------------------------------------------------------------
            */
            $ids = $this->searchService->search(
                $categorySlug,
                $keyword,
                $domain,
                $gps,       //temporary
               $areaCode  
            );

            $transactionId = $ids['transaction_id'];
            $messageId     = $ids['message_id'];
            $ackStatus     = $ids['ack_status'];   // 'ACK' | 'NACK' | 'ERROR'

            /*
            |------------------------------------------------------------------
            | STEP 1b: Skip polling entirely if the Switch rejected the search
            |          (saves 15 s of wasted poll attempts per page load)
            |------------------------------------------------------------------
            */
            if ($ackStatus !== 'ACK') {
                Log::warning('ONDC searchAndPoll: /search was not ACK-ed — skipping poll', [
                    'transaction_id' => $transactionId,
                    'ack_status'     => $ackStatus,
                    'category_slug'  => $categorySlug,
                ]);

                return response()->json([
                    'success'        => true,
                    'transaction_id' => $transactionId,
                    'items'          => [],
                    'message'        => 'ONDC search not acknowledged by Switch',
                ]);
            }

            Log::info('ONDC searchAndPoll: /search ACK — starting poll', [
                'transaction_id' => $transactionId,
                'message_id'     => $messageId,
                'category_slug'  => $categorySlug,
            ]);

            /*
            |------------------------------------------------------------------
            | STEP 2: Poll for on_search
            |------------------------------------------------------------------
            */
            $onSearchPayload = null;

            for ($attempt = 1; $attempt <= self::POLL_ATTEMPTS; $attempt++) {
                Log::info("ONDC poll attempt {$attempt}/" . self::POLL_ATTEMPTS, [
                    'transaction_id' => $transactionId,
                    'message_id'     => $messageId,
                ]);

                $response = Http::timeout(30)
                    ->withHeaders([
                        'X-Internal-Key' => 'RB7LyU6/R9X0MD4Ntr7pY0khxyxTJ/uNYWVTWO/RYWY=',
                        'Content-Type'   => 'application/json',
                    ])
                    ->post(
                        'https://retail-buyer.softtrust.org/api/get_ondc_response',
                        [
                            'transaction_id' => $transactionId,
                            'message_id'     => $messageId,
                            'action'         => 'on_search',
                        ]
                    );

                Log::info('ONDC poll response', [
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

                        Log::info('ONDC on_search received', [
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
            | STEP 3: No response after all poll attempts
            |------------------------------------------------------------------
            */
            if (! $onSearchPayload) {
                Log::warning('ONDC poll exhausted without result', [
                    'transaction_id' => $transactionId,
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
            | STEP 4: Ingest catalog responses
            |------------------------------------------------------------------
            */
            foreach ($onSearchPayload as $catalogResponse) {
                $bppId = data_get($catalogResponse, 'context.bpp_id');

                if (empty($bppId)) {
                    Log::warning('ONDC catalog skipped — missing bpp_id', [
                        'transaction_id' => $transactionId,
                        'context'        => $catalogResponse['context'] ?? [],
                    ]);

                    continue;
                }

                if ($this->isSuppressedBpp($bppId)) {
                    Log::info('ONDC catalog skipped — BPP suppressed', [
                        'bpp_id'         => $bppId,
                        'transaction_id' => $transactionId,
                    ]);

                    continue;
                }

                if (
                    ($catalogResponse['message']['ack']['status'] ?? null) === 'NACK'
                    || ! empty($catalogResponse['error'])
                ) {
                    $this->recordBppNack($bppId);

                    Log::info('ONDC catalog skipped — BPP returned NACK', [
                        'bpp_id'         => $bppId,
                        'transaction_id' => $transactionId,
                        'error'          => $catalogResponse['error']['message'] ?? null,
                    ]);
                    continue;
                }

                $catalog   = data_get($catalogResponse, 'message.catalog', []);
                $providers = $catalog['bpp/providers'] ?? $catalog['providers'] ?? [];

                Log::info('ONDC provider count', [
                    'bpp_id'    => $bppId,
                    'providers' => count($providers),
                ]);

                $providersWithItems = array_filter(
                    $providers,
                    fn ($p) => ! empty($p['items'] ?? [])
                );

                if (! empty($providersWithItems)) {
                    // $count = $this->ingestionService->ingest($catalogResponse);
                    // temporary
               $count = $this->ingestionService->ingest($catalogResponse, $categorySlug, $keyword);

                    Log::info('ONDC catalog ingested', [
                        'transaction_id' => $transactionId,
                        'bpp_id'         => $bppId,
                        'item_count'     => $count,
                    ]);
                } else {
                    Log::info('ONDC catalog skipped — providers have no items', [
                        'bpp_id'         => $bppId,
                        'transaction_id' => $transactionId,
                    ]);
                }
            }

            /*
            |------------------------------------------------------------------
            | STEP 5: Return stored items
            |------------------------------------------------------------------
            */
            // $items = DB::table('ondc_items')
            //     ->where('transaction_id', $transactionId)
            //     ->where('category_slug', $categorySlug)
            //     ->orderByDesc('updated_at')
            //     ->get();

            //temporary
            // Replace with:
// STEP 5: Return stored items
// After successful search, store transaction_id in session
session(['ondc_last_transaction_id' => $transactionId]);

$query = DB::table('ondc_items')
    ->where('transaction_id', $transactionId)  // ← already scoped to THIS search's txn
    ->where('category_slug', $categorySlug);

// Only apply keyword filter when keyword differs from the slug
$cleanKeyword = trim(strtolower($keyword));
$slugAsWords  = str_replace('-', ' ', strtolower($categorySlug));

if ($cleanKeyword && $cleanKeyword !== $slugAsWords) {
    $query->where(function ($q) use ($cleanKeyword) {
        $q->whereRaw("LOWER(name) REGEXP ?", ['[[:<:]]' . $cleanKeyword . '[[:>:]]'])
          ->orWhereRaw("LOWER(description) REGEXP ?", ['[[:<:]]' . $cleanKeyword . '[[:>:]]']);
    });
}

$items = $query->orderByDesc('updated_at')->get();


            return response()->json([
                'success'        => true,
                'transaction_id' => $transactionId,
                'items'          => $items,
            ]);

        } catch (\Throwable $e) {
            Log::error('ONDC searchAndPoll failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            session(['ondc_last_transaction_id' => $transactionId]);

            return response()->json([
                'success'        => true,
                'transaction_id' => null,
                'items'          => [],
                'message'        => 'ONDC unavailable',
            ]);
        }
    }

    protected function isSuppressedBpp(?string $bppId): bool
    {
        if (empty($bppId)) {
            return false;
        }

        return Cache::has("ondc_bpp_suppressed:{$bppId}");
    }

    protected function recordBppNack(?string $bppId): void
    {
        if (empty($bppId)) {
            return;
        }

        $countKey = "ondc_bpp_nack_count:{$bppId}";
        $count    = Cache::increment($countKey);

        if ($count === 1) {
            Cache::put(
                $countKey,
                $count,
                now()->addMinutes(self::BPP_SUPPRESSION_MINUTES)
            );
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