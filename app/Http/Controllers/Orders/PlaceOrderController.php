<?php

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\PlaceOrderAction;
use App\DTOs\PlaceOrderData;
use App\Exceptions\OutOfStockException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Orders\PlaceOrderRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;

class PlaceOrderController extends Controller
{
    public function __invoke(PlaceOrderRequest $request, PlaceOrderAction $action): JsonResponse
    {
         $user = $request->user();

        try {
            $dto = PlaceOrderData::fromArray($request->validated(), $user->id);
            $order = $action->execute($dto);

            return response()->json([
                'message' => 'Đặt hàng thành công!',
                'data'    => $order
            ], 201);

        } catch (OutOfStockException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
