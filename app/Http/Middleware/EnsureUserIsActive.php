<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    /**
     * Chặn user đã đăng nhập nhưng tài khoản không còn ở trạng thái active
     * (lớp bảo vệ thứ hai, phòng trường hợp token còn sống sau khi bị khóa).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->isActive()) {
            return ApiResponse::error('Your account is not active.', 403);
        }

        return $next($request);
    }
}
