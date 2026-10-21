<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Facades\Log;

class CategoryMapper
{
    private const PRODUCT_NAME_WEIGHT = 5;
    private const META_KEYWORDS_WEIGHT = 4;
    private const CATEGORY_WEIGHT = 3;
    private const DESCRIPTION_WEIGHT = 2;

    /**
     * Resolve ONDC category for a product.
     */
    public function map(
        string $domain,
        ?string $productName,
        ?string $categoryName,
        ?string $subCategory = null,
        ?string $description = null,
        ?string $metaKeywords = null
    ): ?string {

        $taxonomy = config('ondc.taxonomy');

        if (! isset($taxonomy[$domain])) {
            Log::warning('ONDC Category Mapper: Domain not found.', [
                'domain' => $domain,
            ]);

            return null;
        }

        $productText = strtolower($productName ?? '');

        $keywordText = strtolower($metaKeywords ?? '');

        $categoryText = strtolower(
            implode(' ', array_filter([
                $categoryName,
                $subCategory,
            ]))
        );

        $descriptionText = strtolower(
            strip_tags($description ?? '')
        );

        $bestCategory = null;
        $bestScore = 0;
        $bestMatchedKeywords = [];

        

        foreach ($taxonomy[$domain] as $ondcCategory => $config) {

            if (empty($config['keywords'])) {
                continue;
            }

            $score = 0;
            $matchedKeywords = [];

            foreach ($config['keywords'] as $keyword) {

                $keyword = strtolower(trim($keyword));

                if ($keyword === '') {
                    continue;
                }

                if ($this->containsWord($productText, $keyword)) {
                    $score += self::PRODUCT_NAME_WEIGHT;

                    $matchedKeywords[] = [
                        'field' => 'Product Name',
                        'keyword' => $keyword,
                        'weight' => self::PRODUCT_NAME_WEIGHT,
                    ];
                }

                if ($this->containsWord($keywordText, $keyword)) {
                    $score += self::META_KEYWORDS_WEIGHT;

                    $matchedKeywords[] = [
                        'field' => 'Meta Keywords',
                        'keyword' => $keyword,
                        'weight' => self::META_KEYWORDS_WEIGHT,
                    ];
                }

                if ($this->containsWord($categoryText, $keyword)) {
                    $score += self::CATEGORY_WEIGHT;

                    $matchedKeywords[] = [
                        'field' => 'Category',
                        'keyword' => $keyword,
                        'weight' => self::CATEGORY_WEIGHT,
                    ];
                }

                if ($this->containsWord($descriptionText, $keyword)) {
                    $score += self::DESCRIPTION_WEIGHT;

                    $matchedKeywords[] = [
                        'field' => 'Description',
                        'keyword' => $keyword,
                        'weight' => self::DESCRIPTION_WEIGHT,
                    ];
                }
            }

            // if ($score > 0) {
            //     Log::info('Category Evaluated', [
            //         'ondc_category' => $ondcCategory,
            //         'score' => $score,
            //         'matched_keywords' => $matchedKeywords,
            //     ]);
            // }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestCategory = $ondcCategory;
                $bestMatchedKeywords = $matchedKeywords;
            }
        }

        // Log::info('================ FINAL CATEGORY =================');

        // Log::info('ONDC Category Match', [
        //     'domain' => $domain,
        //     'product' => $productName,
        //     'matched_category' => $bestCategory,
        //     'score' => $bestScore,
        //     'matched_keywords' => $bestMatchedKeywords,
        // ]);

        // Log::info('================ END CATEGORY MAPPING =================');

        return $bestCategory;
    }

    /**
     * Match whole words only.
     */
    private function containsWord(string $text, string $keyword): bool
    {
        return preg_match(
            '/\b' . preg_quote($keyword, '/') . '\b/i',
            $text
        ) === 1;
    }
}