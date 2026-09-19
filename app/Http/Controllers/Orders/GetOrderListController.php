<?php

namespace App\Http\Controllers\Orders;

use App\Actions\Orders\GetOrderListAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\OrderResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class GetOrderListController extends Controller
{
    //
    public function __invoke(Request $request, GetOrderListAction $action): AnonymousResourceCollection
    {
         $user = $request->user();

        $orders = $action->execute($user->id);

        // Trả về Resource Collection chuẩn JSON API kèm Phân trang (Pagination)
        return OrderResource::collection($orders);
    }

}
