<?php

namespace Webkul\Ondc\Services;

use Illuminate\Support\Str;

class DomainResolver
{
    /**
     * Resolve ONDC domain using category, product name,
     * description and meta keywords.
     */
    public function resolve(
        ?string $categoryName,
        ?string $productName = null,
        ?string $description = null,
        ?string $metaKeywords = null
    ): ?string {

        $text = Str::lower(implode(' ', array_filter([
            $categoryName,
            $productName,
            $description,
            $metaKeywords,
        ])));

        if (empty($text)) {
            return null;
        }

        $domains = config('ondc.domains', []);

        foreach ($domains as $keyword => $domain) {

            if (Str::contains($text, Str::lower($keyword))) {
                return $domain;
            }
        }

        return null;
    }
}