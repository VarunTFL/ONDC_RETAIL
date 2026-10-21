<?php

namespace Webkul\Ondc\Http\Controllers\Buyer;

use Illuminate\Routing\Controller;

class LatestOnSearchController extends Controller
{
    public function index()
    {
        $path = storage_path('app/ondc/latest_on_search.json');
        if (! file_exists($path)) {
            return response()->json([
                'message' => 'No on_search received yet'
            ], 404);
        }
        $json = file_get_contents($path);

        return response($json, 200)->header('Content-Type', 'application/json');
    }
}