<?php 
namespace App\Actions\Orders;

use App\DTOs\PlaceOrderData;
use App\Exceptions\OutOfStockException;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class PlaceOrderAction
{
    public function execute(PlaceOrderData $data): Order
    {
        return DB::transaction(function () use ($data) {
            // 1. Lock dòng product trong DB để tránh 2 request trừ stock cùng lúc (Pessimistic Lock)
            $product = Product::where('id', $data->productId)
                ->lockForUpdate()
                ->firstOrFail();

            // 2. Kiểm tra tồn kho
            if ($product->stock < $data->quantity) {
                throw new OutOfStockException("Sản phẩm {$product->name} chỉ còn {$product->stock} sản phẩm trong kho.");
            }

            // 3. Trừ số lượng tồn kho
            $product->decrement('stock', $data->quantity);

            // 4. Tạo bản ghi đơn hàng
            return Order::create([
                'user_id'     => $data->userId,
                'product_id'  => $product->id,
                'quantity'    => $data->quantity,
                'total_price' => $product->price * $data->quantity,
            ]);
        });
    }
}