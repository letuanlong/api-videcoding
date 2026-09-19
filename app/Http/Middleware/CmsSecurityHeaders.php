<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CmsSecurityHeaders
{
    /**
     * Header bảo mật cho trang CMS. Token đăng nhập nằm trong sessionStorage nên XSS là rủi ro chính:
     * CSP chặn script ngoài và inline, giảm khả năng chèn mã đọc token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Khi chạy `npm run dev`, Vite phục vụ script từ origin khác nên CSP chặt sẽ làm hỏng trang
        if (! file_exists(public_path('hot'))) {
            $response->headers->set('Content-Security-Policy', implode('; ', [
                "default-src 'self'",
                "script-src 'self'",
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: blob:",
                "font-src 'self' data:",
                "connect-src 'self'",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
            ]));
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
