<?php

namespace Webkul\Ondc\Http\Controllers\Buyer;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Webkul\Ondc\Services\Buyer\OndcSelectService;


class OndcSelectController extends Controller
{
    public function __construct(protected OndcSelectService $selectService) {}



    /**
     * POST /ondc/buyer/select
     * Body: { "ondc_item_id": 5, "quantity": 1, "gps": "19.13,72.83", "area_code": "400053" }
     */
    public function select(Request $request): JsonResponse
    {
        Log::info('[ONDC Select] Add to cart triggered', [
            'ondc_item_id' => $request->input('ondc_item_id'),
            'quantity'     => $request->integer('quantity', 1),
        ]);

        // Skip ONDC select for non-ONDC products
        if (! $request->input('ondc_item_id') || (int) $request->input('ondc_item_id') === 0) {
            return response()->json(['success' => true, 'skipped' => true]);
        }

        $request->validate([
            'ondc_item_id' => 'required|integer|min:1',
            'quantity'     => 'sometimes|integer|min:1',
            'gps'          => 'sometimes|string',
            'area_code'    => 'sometimes|string',
        ]);

        try {
            $result = $this->selectService->select(
                ondcItemId:       (int) $request->input('ondc_item_id'),
                quantity:         $request->integer('quantity', 1),
                deliveryLocation: [
                    'gps'       => $request->input('gps',       '19.131140,72.834091'),
                    'area_code' => $request->input('area_code', '400053'),
                ]
            );

            return response()->json($result);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 404);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'error' => $e->getMessage()], 422);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'error' => 'Select failed: ' . $e->getMessage()], 500);
        }
    }
}