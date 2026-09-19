<?php
namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use App\Actions\Auth\ChangePasswordAction;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Exception;

class ChangePasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        // Tạm thời lấy User đầu tiên trong DB để test (vì chưa làm phần Auth Login)
        // Khi có Sanctum Auth, bạn chỉ cần dùng: $user = $request->user();
        $user = $request->user();
        // 1. Validate dữ liệu đầu vào
        $validated = $request->validate([
            'current_password' => ['required', 'string'],
            'new_password' => ['required', 'string', 'min:8', 'confirmed'], // Yêu cầu new_password_confirmation
        ]);

      try {
        $action->execute($user, $request->validated());

        return response()->json([
            'message' => 'Đổi mật khẩu thành công!'
        ], 200);

        } catch (Exception $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
