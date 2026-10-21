<?php

namespace Webkul\Ondc\Services\Category;

class CategoryFilterService
{
    /**
     * Keywords used to identify products belonging to each category.
     */
    private const CATEGORY_MAP = [

        'grocery' => [
            'grocery',
            'food',
            'beverage',
            'rice',
            'atta',
            'flour',
            'dal',
            'lentil',
            'oil',
            'milk',
            'water',
            'juice',
            'tea',
            'coffee',
            'fruit',
            'vegetable',
            'snack',
            'chips',
            'biscuit',
            'chocolate',
            'spice',
            'masala',
            'salt',
            'sugar',
            'organic',
            'packaged food',
        ],

        'skincare' => [
            'beauty',
            'personal care',
            'skin',
            'face',
            'cream',
            'moisturizer',
            'moisturiser',
            'lotion',
            'serum',
            'cleanser',
            'face wash',
            'body wash',
            'body care',
            'cosmetic',
            'hair care',
            'shampoo',
            'conditioner',
            'soap',
            'gel',
            'sunscreen',
            'sun screen',
            'baby lotion',
            'massage oil',
            'grooming',
            'fragrance',
            'perfume',
            'deodorant',
        ],

        'clothing' => [
            'fashion',
            'apparel',
            'shirt',
            'tshirt',
            't-shirt',
            'jeans',
            'pant',
            'trouser',
            'dress',
            'kurta',
            'saree',
            'hoodie',
            'jacket',
            'blazer',
            'top',
            'lower',
            'track pant',
            'shoe',
            'footwear',
            'slipper',
            'sandal',
        ],

        'electronics' => [
            'electronics',
            'mobile',
            'phone',
            'laptop',
            'computer',
            'charger',
            'cable',
            'speaker',
            'earphone',
            'headphone',
            'tv',
            'television',
            'refrigerator',
            'washing machine',
            'fan',
            'appliance',
        ],

        'home-kitchen' => [
            'home',
            'kitchen',
            'cookware',
            'utensil',
            'plate',
            'glass',
            'cup',
            'bowl',
            'chair',
            'table',
            'furniture',
            'decor',
            'mattress',
            'storage',
        ],

        'health' => [
            'health',
            'wellness',
            'medicine',
            'medical',
            'tablet',
            'capsule',
            'syrup',
            'protein',
            'supplement',
            'ayurvedic',
            'fitness',
            'glucometer',
            'bp',
            'thermometer',
        ],

        'toys' => [
            'toy',
            'game',
            'board game',
            'puzzle',
            'kids',
            'lego',
            'doll',
            'educational',
        ],

        'books' => [
            'book',
            'novel',
            'stationery',
            'pen',
            'pencil',
            'notebook',
            'diary',
            'office',
            'school',
        ],

        'gift-cards' => [
            'gift card',
            'voucher',
            'coupon',
        ],

        'b2b' => [
            'bulk',
            'wholesale',
            'hardware',
            'construction',
            'cement',
            'industrial',
            'steel',
        ],
    ];

    /**
     * Check whether ONDC item belongs to requested category.
     */
    public function matches(string $categorySlug, array $item): bool
    {
        if (! isset(self::CATEGORY_MAP[$categorySlug])) {
            return true;
        }

        $keywords = self::CATEGORY_MAP[$categorySlug];

        $name = strtolower(
            $item['descriptor']['name'] ?? ''
        );

        $description = strtolower(
            ($item['descriptor']['short_desc'] ?? '') . ' ' .
            ($item['descriptor']['long_desc'] ?? '')
        );

        $ondcCategory = strtolower(
            $item['category_id'] ?? ''
        );

        $brand = strtolower(
            $this->extractBrand($item)
        );

        $searchText = implode(' ', [
            $name,
            $description,
            $ondcCategory,
            $brand,
        ]);

        $score = 0;

        foreach ($keywords as $keyword) {

            $keyword = strtolower($keyword);

            if (str_contains($name, $keyword)) {
                $score += 10;
            }

            if (str_contains($description, $keyword)) {
                $score += 6;
            }

            if (str_contains($ondcCategory, $keyword)) {
                $score += 5;
            }

            if (str_contains($brand, $keyword)) {
                $score += 2;
            }
        }

        /**
         * Reject obvious mismatches.
         */
        if ($categorySlug === 'skincare') {

            $negativeWords = [
                'milk',
                'water',
                'rice',
                'atta',
                'dal',
                'biscuit',
                'snack',
                'juice',
            ];

            foreach ($negativeWords as $word) {

                if (
                    str_contains($searchText, $word)
                    && $score < 12
                ) {
                    return false;
                }
            }
        }

        if ($categorySlug === 'grocery') {

            $negativeWords = [
                'cream',
                'moisturizer',
                'moisturiser',
                'lotion',
                'serum',
                'perfume',
                'soap',
                'shampoo',
                'face wash',
            ];

            foreach ($negativeWords as $word) {

                if (
                    str_contains($searchText, $word)
                    && $score < 12
                ) {
                    return false;
                }
            }
        }

        return $score >= 10;
    }

    /**
     * Extract Brand from ONDC tags.
     */
    private function extractBrand(array $item): string
    {
        foreach ($item['tags'] ?? [] as $tag) {

            if (($tag['code'] ?? '') !== 'attribute') {
                continue;
            }

            foreach ($tag['list'] ?? [] as $attribute) {

                if (
                    strtolower($attribute['code'] ?? '') === 'brand'
                ) {
                    return $attribute['value'] ?? '';
                }
            }
        }

        return '';
    }
}