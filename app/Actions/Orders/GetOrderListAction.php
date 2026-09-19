<?php 
namespace App\Actions\Orders;

use App\Models\Order;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class GetOrderListAction
{
    public function execute(int $userId, int $perPage = 10): LengthAwarePaginator
    {
        return Order::query()
            ->where('user_id', $userId)
            ->with(['product']) // 👈 Eager Loading để KHÔNG bị lỗi N+1 Query!
            ->latest()
            ->latest('id') // Đơn cùng created_at cần thứ tự ổn định, nếu không phân trang sẽ lặp/mất đơn
            ->paginate($perPage);
    }
}
