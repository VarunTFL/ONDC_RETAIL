<?php

namespace Webkul\Ondc\Services\Buyer;

class SearchRankingService
{
    /**
     * Calculate search result.
     */
    public function score(
        array $item,
        string $keyword = '',
        string $categorySlug = ''
    ): array {

        $keyword = strtolower(trim($keyword));

        $intent = $this->detectIntent(
            $keyword,
            $categorySlug
        );

        if (! $this->shouldInclude(
            $item,
            $keyword,
            $intent
        )) {

            return [

                'included' => false,

                'intent' => $intent,

                'score' => 0,

            ];

        }

        return [

            'included' => true,

            'intent' => $intent,

            'score' => $this->calculateScore(

                $item,

                $keyword,

                $intent

            ),

        ];

    }

    /**
 * Detect what the user is actually searching for.
 */
private function detectIntent(
    string $keyword,
    string $categorySlug
): string {

    $defaultIntent = strtolower(trim($categorySlug));

    $categories = config('search-engine.categories', []);

    // If no keyword, fall back to selected category
    if ($keyword === '') {
        return $defaultIntent ?: 'general';
    }

    $bestIntent = null;
    $bestScore = 0;

    foreach ($categories as $category => $config) {

        $score = 0;

        /*
        |--------------------------------------------------------------------------
        | Aliases
        |--------------------------------------------------------------------------
        */

        foreach ($config['aliases'] ?? [] as $alias) {

            if (str_contains($keyword, strtolower($alias))) {
                $score += 100;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Priority Keywords
        |--------------------------------------------------------------------------
        */

        foreach ($config['priority'] ?? [] as $word) {

            if (str_contains($keyword, strtolower($word))) {
                $score += 200;
            }

        }

        /*
        |--------------------------------------------------------------------------
        | Regular Keywords
        |--------------------------------------------------------------------------
        */

        foreach ($config['keywords'] ?? [] as $word) {

            if (str_contains($keyword, strtolower($word))) {
                $score += 50;
            }

        }

        if ($score > $bestScore) {

            $bestScore = $score;

            $bestIntent = $category;

        }

    }

    if ($bestIntent !== null) {
        return $bestIntent;
    }

    return $defaultIntent ?: 'general';
}

/**
 * Decide whether a product should be considered for ranking.
 */
private function shouldInclude(
    array $item,
    string $keyword,
    string $intent
): bool {

    // Category browsing (no keyword)
    if ($keyword === '') {
        return true;
    }

    $config = config("search-engine.categories.$intent");

    if (!$config) {
        return true;
    }

    /*
|--------------------------------------------------------------------------
| Category Validation
|--------------------------------------------------------------------------
*/

if (
    !$this->categoryMatchesIntent(
        $item['category_id'] ?? '',
        $intent
    )
) {

    return false;

}

    $name = strtolower($item['descriptor']['name'] ?? '');

    $short = strtolower($item['descriptor']['short_desc'] ?? '');

    $long = strtolower($item['descriptor']['long_desc'] ?? '');

    $category = strtolower($item['category_id'] ?? '');

    $text = implode(' ', [
        $name,
        $short,
        $long,
        $category,
    ]);

    /*
    |--------------------------------------------------------------------------
    | Exact Phrase Match
    |--------------------------------------------------------------------------
    */

    if (str_contains($text, strtolower($keyword))) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Category Match
    |--------------------------------------------------------------------------
    */

    if (str_contains($category, strtolower($keyword))) {
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Match Search Words
    |--------------------------------------------------------------------------
    */

    $terms = preg_split('/\s+/', strtolower($keyword));

    foreach ($terms as $term) {

        if (strlen($term) < 2) {
            continue;
        }

        if (
            str_contains($name, $term) ||
            str_contains($short, $term) ||
            str_contains($long, $term)
        ) {
            return true;
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Priority Keywords
    |--------------------------------------------------------------------------
    */

    foreach ($config['priority'] ?? [] as $word) {

        if (str_contains($text, strtolower($word))) {
            return true;
        }

    }

    /*
    |--------------------------------------------------------------------------
    | Regular Keywords
    |--------------------------------------------------------------------------
    */

    foreach ($config['keywords'] ?? [] as $word) {

        if (str_contains($text, strtolower($word))) {
            return true;
        }

    }

    return false;
}

/**
 * Check whether an ONDC category belongs to the detected intent.
 */
private function categoryMatchesIntent(
    string $ondcCategory,
    string $intent
): bool {

    if ($ondcCategory === '') {
        return true;
    }

    $ondcCategory = strtolower(trim($ondcCategory));

    $config = config("search-engine.categories.$intent");

    if (!$config) {
        return true;
    }

    foreach ($config['ondc_categories'] ?? [] as $category) {

        if (str_contains($ondcCategory, strtolower($category))) {
            return true;
        }

    }

    return false;
}

/**
 * Calculate relevance score.
 */
private function calculateScore(
    array $item,
    string $keyword,
    string $intent
): int {

    $config = config("search-engine.categories.$intent");

    if (!$config) {
        return 0;
    }

    $name = strtolower($item['descriptor']['name'] ?? '');

    $short = strtolower($item['descriptor']['short_desc'] ?? '');

    $long = strtolower($item['descriptor']['long_desc'] ?? '');

    $category = strtolower($item['category_id'] ?? '');

    $keyword = strtolower(trim($keyword));

    $score = 0;

    /*
    |--------------------------------------------------------------------------
    | Category Browsing
    |--------------------------------------------------------------------------
    */

    if ($keyword === '') {

        return $config['boost'];

    }

    /*
    |--------------------------------------------------------------------------
    | Exact Phrase Match
    |--------------------------------------------------------------------------
    */

    if ($name === $keyword) {

        $score += 3000;

    } elseif (str_starts_with($name, $keyword)) {

        $score += 2200;

    } elseif (str_contains($name, $keyword)) {

        $score += 1800;

    }

    /*
    |--------------------------------------------------------------------------
    | Individual Word Matching
    |--------------------------------------------------------------------------
    */

    $terms = array_filter(
        preg_split('/\s+/', $keyword)
    );

    foreach ($terms as $term) {

        if (strlen($term) < 2) {
            continue;
        }

        // Whole word in product name
        if (preg_match('/\b'.preg_quote($term,'/').'\b/i', $name)) {

            $score += 500;

        }

        // Anywhere in product name
        elseif (str_contains($name, $term)) {

            $score += 250;

        }

        // Category
        if (str_contains($category, $term)) {

            $score += 300;

        }

        // Short description

        if (preg_match('/\b'.preg_quote($term,'/').'\b/i', $short)) {

            $score += 150;

        }

        // Long description

        if (preg_match('/\b'.preg_quote($term,'/').'\b/i', $long)) {

            $score += 80;

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Priority Words
    |--------------------------------------------------------------------------
    */

    foreach ($config['priority'] as $priority) {

        if (str_contains($name, strtolower($priority))) {

            $score += 400;

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Regular Keywords
    |--------------------------------------------------------------------------
    */

    foreach ($config['keywords'] as $word) {

        if (str_contains($name, strtolower($word))) {

            $score += 120;

        }

    }

    /*
    |--------------------------------------------------------------------------
    | Category Boost
    |--------------------------------------------------------------------------
    */

    $score += $config['boost'];

    return $score;
}
}