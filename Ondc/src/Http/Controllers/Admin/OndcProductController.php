<?php

namespace Webkul\Ondc\Http\Controllers\Admin;

use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Webkul\Product\Models\Product;
use Webkul\Ondc\DataGrids\ONDCProductDataGrid;

class OndcProductController extends Controller
{
    public function index()
    {
        if (request()->ajax()) {
            return datagrid(ONDCProductDataGrid::class)->process();
        }

        $savedProductIds = DB::table('ondc_products')->pluck('product_id')->toArray();

        return view('ondc::admin.products.index', compact('savedProductIds'));
    }

    public function store(Request $request)
    {
        $selected = $request->input('products', []);
        
        if (empty($selected)) {
            return redirect()->back()->with('warning', 'No products selected');
        }

        $savedCount = 0;
        $skippedCount = 0;

        foreach ($selected as $productId) {
            // Check if already exists
            $exists = DB::table('ondc_products')
                ->where('product_id', $productId)
                ->exists();

            if ($exists) {
                $skippedCount++;
                continue;
            }

            // Insert new record
            DB::table('ondc_products')->insert([
                'product_id' => $productId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            
            $savedCount++;
        }

        $message = "{$savedCount} product(s) saved successfully.";
        if ($skippedCount > 0) {
            $message .= " ({$skippedCount} already existed)";
        }

        return redirect()->back()->with('success', $message);
    }
}
