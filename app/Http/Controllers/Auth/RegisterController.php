<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class RegisterController extends Controller
{
    /**
     * Laravel sẽ tự động Inject Request và Action Class vào hàm qua Service Container
     */
    public function __invoke(RegisterRequest $request, RegisterUserAction $action): JsonResponse
    {
        // 1. Lấy dữ liệu đã qua Validate từ FormRequest
        $validatedData = $request->validated();

        // 2. Gọi Action thực thi logic tạo User
        $user = $action->execute($validatedData);

        // 3. Trả về Response chuẩn định dạng JSON cùng mã HTTP 201 Created
        return response()->json([
            'message' => 'Đăng ký tài khoản thành công!',
            'data'    => new UserResource($user),
        ], 201);
    }
}
