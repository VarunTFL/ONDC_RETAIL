<?php

namespace Webkul\Ondc\Http\Controllers\Buyer;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Services\Buyer\CatalogIngestionService;
use Illuminate\Support\Facades\File;

class OnSearchController extends Controller
{
    public function __construct(
        protected CatalogIngestionService $catalogIngestionService
    ) {}

    public function onSearch(Request $request)
    {
        try {
            $payload = $request->all();
            $providers = data_get($payload, 'message.catalog.bpp/providers', []);

            Log::info('ONDC buyer/on_search HIT', [
                'transaction_id' => data_get($payload, 'context.transaction_id'),
                'provider_count' => count($providers),
            ]);

            // Store / ingest catalog
            $this->catalogIngestionService->ingest($payload);

            // Save latest payload for tester UI
            cache()->put('latest_on_search', $payload, now()->addMinutes(30));

            $dir = storage_path('app/ondc');
            if (! File::exists($dir)) {
                File::makeDirectory($dir, 0755, true);
            }

            file_put_contents($dir . '/latest_on_search.json', json_encode($payload, JSON_PRETTY_PRINT));

            return response()->json([
                'message' => [
                    'ack' => [
                        'status' => 'ACK'
                    ]
                ]
            ]);
        } 
        catch (\Throwable $e) {
            Log::error('ON_SEARCH_ERROR', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => [
                    'ack' => [
                        'status' => 'NACK'
                    ]
                ],
                'error' => [
                    'message' => $e->getMessage()
                ]
            ], 500);
        }
    }
}