<?php

namespace Webkul\Ondc\Services\Buyer;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Webkul\Product\Repositories\ProductRepository;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Webkul\Ondc\Services\Category\CategoryFilterService;

class CatalogIngestionService
{

    public function __construct(
        protected ProductRepository $productRepository,
        protected CategoryFilterService $categoryFilter
    ) {}
    /**
     * Ingest an on_search payload (from either a direct callback or the Switch poll).
     *
     * Only items that are relevant to $categorySlug / $keyword are persisted.
     * Relevance is a loose OR-match: any search term found anywhere in the item's
     * name, short_desc, long_desc, or category_id counts as a hit.
     *
     * @param  array   $payload       Full on_search JSON decoded as array
     * @param  string  $categorySlug  e.g. "grocery", "bath-soaps"  (empty = no filter)
     * @param  string  $keyword       Free-text search term           (empty = no filter)
     * @return int     Number of items upserted
     */
    public function ingest(array $payload, string $categorySlug = '', string $keyword = ''): int
    {
        $transactionId = data_get($payload, 'context.transaction_id');

        $bppId  = data_get($payload, 'context.bpp_id');
        $bppUri = data_get($payload, 'context.bpp_uri');
        $domain = data_get($payload, 'context.domain');

        $start = microtime(true);


        // Resolve the category slug from the cache set by CategorySearchService.
        // Fall back to the transaction id if mapping is missing (e.g. direct callback).
        $resolvedSlug = $categorySlug
            ?: Cache::get("ondc_txn_slug:{$transactionId}", $transactionId);

        // Build the list of search terms used for relevance filtering.
        // Combines slug words ("bath-soaps" → ["bath","soaps"]) and keyword words.
        // $searchTerms = $this->buildSearchTerms($resolvedSlug, $keyword);

        // "bpp/providers" is the spec key — PHP's data_get cannot handle the slash,
        // so we address the catalog array directly.
        $catalog = data_get($payload, 'message.catalog', []);

        Log::info('ONDC catalog structure', [
            'catalog_keys' => array_keys($catalog),
        ]);

        $providers = $catalog['bpp/providers'] ?? $catalog['providers'] ?? [];

        if (empty($providers)) {
            Log::warning('ONDC on_search: no bpp/providers in payload', [
                'transaction_id' => $transactionId,
            ]);

            DB::table('ondc_flow_log')->insert([
                'transaction_id'     => $transactionId,
                'action'             => 'search',
                'status'             => 'sent',
                'bagisto_product_id' => null,
                'payload'            => json_encode($payload),
                'created_at'         => now(),
            ]);
            return 0;
        }

        $count        = 0;
        $skipped      = 0;

        foreach ($providers as $provider) {

            $providerId = $provider['id'] ?? null;

            $providerName = $provider['descriptor']['name']
                ?? $catalog['bpp/descriptor']['name']
                ?? null;

            foreach ($provider['items'] ?? [] as $item) {

                // ── Skip uncategorised items ─────────────────────────────────
                // Items without a category_id are often noise / test entries
                // from seller NPs. Discard them before any further processing.



                if (empty($item['category_id'])) {
                    $skipped++;
                    continue;
                }

                // ── Relevance gate ────────────────────────────────────────────
                // Skip items that do not match any search term.
                // When $searchTerms is empty (no slug/keyword supplied) every
                // item passes — this preserves backwards-compatible behaviour
                // for direct callbacks that call ingest() without extra args.
                if (! $this->categoryFilter->matches($resolvedSlug, $item)) {
                    $skipped++;
                    continue;
                }

                // Images may be URL strings or objects with a "url" key depending on seller NP.
                $rawImage = $item['descriptor']['images'][0] ?? null;
                $imageUrl = is_array($rawImage) ? ($rawImage['url'] ?? null) : $rawImage;




                $name = $item['descriptor']['name'] ?? '';

                $description = $item['descriptor']['long_desc']
                    ?? $item['descriptor']['short_desc']
                    ?? '';

                $price = $item['price']['value'] ?? 0;

                $existingOndc = DB::table('ondc_items')
                    ->where('item_id', $item['id'])
                    ->first();

                if (
                    $existingOndc &&
                    $existingOndc->bagisto_product_id &&
                    $this->productRepository->find($existingOndc->bagisto_product_id)
                ) {

                    $bagistoProductId = $existingOndc->bagisto_product_id;
                } else {

                    $sku = 'ondc_' . $item['id'];

                    $product = $this->productRepository
                        ->findOneByField('sku', $sku);

                    if (! $product) {



                        $product = $this->productRepository->create([
                            'type'                => 'simple',
                            'attribute_family_id' => 1,
                            'sku'                 => $sku,
                        ]);
                    }

                    $bagistoProductId = $product->id;
                }


                // Event::dispatch('catalog.product.update.before', $bagistoProductId);
                $product = $this->productRepository->update([
                    'name'                 => $name,
                    'description'          => $description,
                    'price'                => $price,

                    'url_key'              => Str::slug($name) . '-' . md5($item['id']),

                    'status'               => 1,
                    'visible_individually' => 1,

                    'channels' => [
                        core()->getDefaultChannel()->id,
                    ],

                    'categories' => [],

                    'inventories' => [
                        1 => 1000,
                    ],

                ], $bagistoProductId);

                // Event::dispatch('catalog.product.update.after', $product);

                Log::info('ONDC Product Synced', [
                    'item_id' => $item['id'],
                    'bagisto_product_id' => $bagistoProductId,
                    'provider' => $providerName,
                ]);

                //temporary



                DB::table('ondc_items')->updateOrInsert(
                    [
                        'item_id' => $item['id'],
                    ],
                    [

                        'transaction_id' => $transactionId,
                        'provider_id'    => $providerId,
                        'location_id'        => $item['location_id'] ?? null,
                        'bagisto_product_id' => $bagistoProductId,
                        'provider_name'  => $providerName,
                        'category_slug'  => $resolvedSlug,
                        'ondc_category'  => $item['category_id'] ?? null,
                        'brand'          => $this->extractBrand($item),
                        'name'           => $item['descriptor']['name'] ?? '',
                        'description'    => $item['descriptor']['long_desc']
                            ?? $item['descriptor']['short_desc']
                            ?? null,
                        'price'          => $item['price']['value'] ?? 0,
                        'currency'       => $item['price']['currency'] ?? 'INR',
                        'image'          => $imageUrl,
                        'payload'        => json_encode($item, JSON_UNESCAPED_UNICODE),
                        'bpp_id'         => data_get($payload, 'context.bpp_id'),
                        'bpp_uri'        => data_get($payload, 'context.bpp_uri'),
                        'domain'         => data_get($payload, 'context.domain'),
                        'updated_at'     => now(),
                    ]
                );

                $count++;
            }
        }

        Log::info('Ingestion completed', [
    'seconds' => microtime(true) - $start,
]);
        

        Log::info('ONDC catalog ingested', [
            'transaction_id' => $transactionId,
            'category_slug'  => $resolvedSlug,
            'provider_count' => count($providers),
            'item_count'     => $count,
            'items_skipped'  => $skipped,
        ]);

        return $count;
    }

    // ── Private helpers ──────────────────────────────────────────────────────

    /**
     * Build a de-duped list of lowercase search terms from the slug and keyword.
     *
     * "bath-soaps"  → ["bath", "soaps"]
     * "lux soap"    → ["lux",  "soap"]
     * Combined      → ["bath", "soaps", "lux", "soap"]
     * Single-char tokens are removed (they cause too many false positives).
     *
     * @re
     */
    //     private function buildSearchTerms(string $categorySlug, string $keyword): array
    //     {
    //         $slugWords    = explode('-', strtolower($categorySlug));
    //         $keywordWords = explode(' ', strtolower(trim($keyword)));

    //         // $terms = array_filter(
    //         //     array_unique(array_merge($slugWords, $keywordWords)),
    //         //     fn (string $t) => strlen($t) > 1
    //         // );
    //          $terms = array_filter(
    //     array_unique(array_merge($slugWords, $keywordWords)),
    //     fn (string $t) => strlen($t) > 2      // was > 1, now > 2
    // );
    //         return array_values($terms);
    //     }
    // private function buildSearchTerms(string $categorySlug, string $keyword): array
    // {
    //     $keyword = trim($keyword);

    //     // If a real keyword was supplied, filter on that alone.
    //     // Slug words ("grocery", "skincare") are domain selectors — they don't
    //     // belong in the product-name match and cause false positives.
    //     if ($keyword !== '' && strtolower($keyword) !== str_replace('-', ' ', strtolower($categorySlug))) {
    //         $terms = explode(' ', strtolower($keyword));
    //     } else {
    //         // No keyword — fall back to slug words so category browsing still filters
    //         $terms = explode('-', strtolower($categorySlug));
    //     }

    //     return array_values(array_filter(
    //         array_unique($terms),
    //         fn (string $t) => strlen($t) > 2
    //     ));
    // }

    /**
     * Extract brand from ONDC item tags.
     */
    private function extractBrand(array $item): ?string
    {
        foreach ($item['tags'] ?? [] as $tag) {

            if (($tag['code'] ?? '') !== 'attribute') {
                continue;
            }

            foreach ($tag['list'] ?? [] as $attribute) {

                if (
                    strtolower($attribute['code'] ?? '') === 'brand'
                ) {
                    return $attribute['value'] ?? null;
                }
            }
        }

        return null;
    }
    // private function buildSearchTerms(string $categorySlug, string $keyword): array
    // {
    //     $keyword = trim($keyword);
    //     $slugAsWords = str_replace('-', ' ', strtolower($categorySlug));

    //     // Pure category browse (keyword === slug, or no keyword) — no free-text
    //     // filter; trust ONDC domain routing to have scoped results already.
    //     if ($keyword === '' || strtolower($keyword) === $slugAsWords) {
    //         return [];
    //     }

    //     return array_values(array_filter(
    //         array_unique(explode(' ', strtolower($keyword))),
    //         fn (string $t) => strlen($t) > 2
    //     ));
    // }

    /**
     * Return true when at least one search term appears anywhere in the item's
     * name, short_desc, long_desc, or category_id fields (case-insensitive).
     *
     * A single OR-match is intentionally broad so that e.g. searching "soap"
     * on the "grocery" slug matches "Lux Bathing Soap" even though "grocery"
     * alone wouldn't — the keyword wins.
     *
     * @param  array    $item         One item entry from bpp/providers[*].items
     * @param  string[] $searchTerms  Terms from buildSearchTerms()
     */
    //    private function isItemRelevant(array $item, array $searchTerms): bool
    // {
    //     $haystack = strtolower(implode(' ', array_filter([
    //         $item['descriptor']['name']       ?? '',
    //         $item['descriptor']['short_desc'] ?? '',
    //         $item['descriptor']['long_desc']  ?? '',
    //         $item['category_id']              ?? '',
    //     ])));

    //     foreach ($searchTerms as $term) {
    //         // Use word-boundary regex so "dal" does not match "sandal",
    //         // "oil" does not match "foil", "rice" does not match "licorice", etc.
    //         if (preg_match('/\b' . preg_quote($term, '/') . '\b/i', $haystack)) {
    //             return true;
    //         }
    //     }

    //     return false;
    // }
}
