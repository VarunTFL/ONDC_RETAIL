<?php

namespace Webkul\Ondc\Http\Controllers\Shop;

use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OndcProductController extends Controller
{
    //added new
    public function show(int $id)
    {
        $product = DB::table('ondc_items')->where('id', $id)->first();

        if (! $product) {
            abort(404);
        }

        return view('ondc::shop.product.view', compact('product'));
    }

    public function products($categorySlug)
    {
        try {
            $products = DB::table('ondc_items')
                ->where('category_slug', $categorySlug)
                ->latest()
                ->get();

            return response()->json($products);
        } catch (\Throwable $e) {
            Log::error('ONDC products fetch failed', [
                'categorySlug' => $categorySlug,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'error' => true,
                'message' => 'Unable to load ONDC products',
            ], 200);
        }
    }
}