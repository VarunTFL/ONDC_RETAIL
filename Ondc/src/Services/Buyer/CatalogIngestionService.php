<?php

namespace Webkul\Ondc\Services\Buyer;

use App\Jobs\DownloadProductImageJob;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;
use Webkul\Product\Repositories\ProductRepository;

class CatalogIngestionService
{
    public function __construct(
        protected ProductRepository $productRepository,
        protected SearchRankingService $rankingService
    ) {
    }

    /**
     * Ingest ONDC on_search payload.
     */
    public function ingest(
        array $payload,
        string $categorySlug = '',
        string $keyword = ''
    ): int {

        /*
        |--------------------------------------------------------------------------
        | Context
        |--------------------------------------------------------------------------
        */

        $transactionId = data_get($payload, 'context.transaction_id');

        $bppId = data_get($payload, 'context.bpp_id');

        $bppUri = data_get($payload, 'context.bpp_uri');

        $domain = data_get($payload, 'context.domain');

        /*
        |--------------------------------------------------------------------------
        | Resolve Category
        |--------------------------------------------------------------------------
        */

        $resolvedSlug = $categorySlug
            ?: Cache::get(
                "ondc_txn_slug:{$transactionId}",
                $transactionId
            );

        /*
        |--------------------------------------------------------------------------
        | Search Terms
        |--------------------------------------------------------------------------
        */

       

        /*
        |--------------------------------------------------------------------------
        | Catalog
        |--------------------------------------------------------------------------
        */

        $catalog = data_get(
            $payload,
            'message.catalog',
            []
        );

        $providers = $catalog['bpp/providers']
            ?? $catalog['providers']
            ?? [];

        if (empty($providers)) {

            Log::warning('No Providers Found', [
                'transaction_id' => $transactionId
            ]);

            return 0;
        }

        /*
        |--------------------------------------------------------------------------
        | Collect Products
        |--------------------------------------------------------------------------
        */

        $matchedItems = $this->collectMatchedItems(

            $providers,

            $keyword,

            $resolvedSlug,


        );

        /*
        |--------------------------------------------------------------------------
        | Sort By Score
        |--------------------------------------------------------------------------
        */

        usort($matchedItems, function ($a, $b) {

            return $b['score'] <=> $a['score'];

        });

        /*
        |--------------------------------------------------------------------------
        | Process
        |--------------------------------------------------------------------------
        */

        return $this->processMatchedItems(

            $matchedItems,

            $transactionId,

            $bppId,

            $bppUri,

            $domain,

            $resolvedSlug

        );

    }

    /**
 * Collect all matching items from every provider.
 */
/**
 * Collect all matching items from every provider.
 */
private function collectMatchedItems(
    array $providers,
    string $keyword,
    string $resolvedSlug,
    
): array {

    $matchedItems = [];

    foreach ($providers as $provider) {

        foreach ($provider['items'] ?? [] as $item) {

            /*
            |--------------------------------------------------------------------------
            | Skip Invalid Products
            |--------------------------------------------------------------------------
            */

            if (empty($item['descriptor']['name'])) {
                continue;
            }

            /*
            |--------------------------------------------------------------------------
            | Calculate Score
            |--------------------------------------------------------------------------
            */

          $result = $this->rankingService->score(
    $item,
    $keyword,
    $resolvedSlug
);

if (! $result['included']) {
    continue;
}

$matchedItems[] = [

    'score' => $result['score'],

    'intent' => $result['intent'],

    'provider' => $provider,

    'item' => $item,

];
        }
    }

    Log::info('Search Ranking', [

        'keyword' => $keyword,

        'matched_items' => count($matchedItems),

    ]);

    return $matchedItems;
}
        

/**
 * Process all matched products after sorting.
 */
private function processMatchedItems(
    array $matchedItems,
    string $transactionId,
    ?string $bppId,
    ?string $bppUri,
    ?string $domain,
    string $resolvedSlug
): int {

    $count = 0;

    foreach ($matchedItems as $match) {

        $this->processItem(

            $match,

            $transactionId,

            $bppId,

            $bppUri,

            $domain,

            $resolvedSlug

        );

        $count++;
    }

    Log::info('Products Processed', [

        'count' => $count,

    ]);

    return $count;
}

/**
 * Process one ranked product.
 */
private function processItem(
    array $match,
    string $transactionId,
    ?string $bppId,
    ?string $bppUri,
    ?string $domain,
    string $resolvedSlug
): void {

    $provider = $match['provider'];
    $item = $match['item'];

    $providerId = $provider['id'] ?? null;

    $providerName =
        $provider['descriptor']['name']
        ?? null;

    /*
    |--------------------------------------------------------------------------
    | Basic Details
    |--------------------------------------------------------------------------
    */

    $name = $item['descriptor']['name'] ?? '';

    $description =
        $item['descriptor']['long_desc']
        ?? $item['descriptor']['short_desc']
        ?? '';

    $price = (float)($item['price']['value'] ?? 0);

    $rawImage = $item['descriptor']['images'][0] ?? null;

    $imageUrl = is_array($rawImage)
        ? ($rawImage['url'] ?? null)
        : $rawImage;

    /*
    |--------------------------------------------------------------------------
    | Existing ONDC Item
    |--------------------------------------------------------------------------
    */

    $existingOndc = DB::table('ondc_items')
        ->where('item_id', $item['id'])
        ->first();

    /*
    |--------------------------------------------------------------------------
    | Find/Create Bagisto Product
    |--------------------------------------------------------------------------
    */

    if ($existingOndc && $existingOndc->bagisto_product_id) {

        $bagistoProductId = $existingOndc->bagisto_product_id;

    } else {

        $sku = 'ondc_'.$item['id'];

        $product = $this->productRepository
            ->findOneByField('sku', $sku);

        if (! $product) {

            Event::dispatch('catalog.product.create.before');

            $product = $this->productRepository->create([

                'type' => 'simple',

                'attribute_family_id' => 1,

                'sku' => $sku,

            ]);

            Event::dispatch(
                'catalog.product.create.after',
                $product
            );

        }

        $bagistoProductId = $product->id;
    }

    /*
    |--------------------------------------------------------------------------
    | Continue...
    |--------------------------------------------------------------------------
    */
        /*
    |--------------------------------------------------------------------------
    | Check Whether Product Needs Update
    |--------------------------------------------------------------------------
    */

    $needsUpdate = true;

    if ($existingOndc) {

        $needsUpdate =
            $existingOndc->name !== $name ||
            $existingOndc->description !== $description ||
            (float) $existingOndc->price !== $price;
    }

    /*
    |--------------------------------------------------------------------------
    | Update Bagisto Product
    |--------------------------------------------------------------------------
    */

    if ($needsUpdate) {

        Event::dispatch(
            'catalog.product.update.before',
            $bagistoProductId
        );

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

        Event::dispatch(
            'catalog.product.update.after',
            $product
        );

        Log::info('Bagisto product updated', [
            'product_id' => $bagistoProductId,
        ]);

    } else {

        Log::info('Bagisto product skipped', [
            'product_id' => $bagistoProductId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Save ONDC Item
    |--------------------------------------------------------------------------
    */

    DB::table('ondc_items')->updateOrInsert(

        [
            'item_id' => $item['id'],
        ],

        [

            'transaction_id'     => $transactionId,
            'provider_id'        => $providerId,
            'bagisto_product_id' => $bagistoProductId,
            'provider_name'      => $providerName,

            'category_slug'      => $resolvedSlug,

            'name'               => $name,

            'description'        => $description,

            'price'              => $price,
            'search_score' => $match['score'],

            'currency'           => $item['price']['currency'] ?? 'INR',

            'image'              => $imageUrl,

            'payload'            => json_encode(
                $item,
                JSON_UNESCAPED_UNICODE
            ),

            'bpp_id'             => $bppId,

            'bpp_uri'            => $bppUri,

            'domain'             => $domain,

            'location_id'        => $item['location_id'] ?? null,

            'updated_at'         => now(),

        ]

    );

    /*
    |--------------------------------------------------------------------------
    | Queue Image Download
    |--------------------------------------------------------------------------
    */

    if (!empty($imageUrl)) {

        dispatch(

            new DownloadProductImageJob(

                $bagistoProductId,

                $imageUrl,

                $bppId,

                $transactionId

            )

        );

    }

    Log::info('ONDC Product Synced', [

        'item_id' => $item['id'],

        'bagisto_product_id' => $bagistoProductId,

        'provider' => $providerName,

    ]);

}

/**
 * Build search terms from category slug and keyword.
 */

}