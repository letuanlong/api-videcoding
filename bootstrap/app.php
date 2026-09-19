<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Dự án không có trang login web: request API chưa xác thực trả 401 JSON, không redirect tới route 'login'
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : route('login')
        );

        $middleware->alias([
            'role'   => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // Kiểm tra role/trạng thái phải chạy TRƯỚC route-model-binding. Nếu không, người không có quyền
        // vẫn phân biệt được id có thật (403) với id không tồn tại (404) và dò được danh sách user.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnsureUserHasRole::class);
        $middleware->prependToPriorityList(before: EnsureUserHasRole::class, prepend: EnsureUserIsActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $wantsJson = fn (Request $request) => $request->is('api/*') || $request->expectsJson();

        // API luôn trả JSON (401/422/...) kể cả khi client không gửi "Accept: application/json"
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $wantsJson($request)
        );

        // Chuẩn hóa mọi lỗi API về {success: false, message, errors?}. Thứ tự đăng ký quan trọng:
        // từ cụ thể đến chung, callback trả null thì Laravel thử callback kế tiếp.
        $exceptions->render(function (AuthenticationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return ApiResponse::error('Unauthenticated.', 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($wantsJson) {
            if ($wantsJson($request)) {
                return ApiResponse::error('Validation failed.', $e->status, $e->errors());
            }
        });

        // Route-model-binding thất bại: ModelNotFoundException được đổi thành NotFoundHttpException
        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $previous = $e->getPrevious();
            $isUser = $previous instanceof ModelNotFoundException && $previous->getModel() === User::class;

            return ApiResponse::error($isUser ? 'User not found.' : 'Resource not found.', 404);
        });

        // Các lỗi HTTP còn lại: giữ nguyên status và header (vd. Retry-After của 429).
        // 403 luôn dùng một message chuẩn dù đến từ Policy/Gate (AccessDeniedHttpException) hay abort(403) trong middleware.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            $message = match (true) {
                $status === 403          => 'You do not have permission to perform this action.',
                $e->getMessage() !== ''  => $e->getMessage(),
                default                  => Response::$statusTexts[$status] ?? 'Error',
            };

            return ApiResponse::error($message, $status, headers: $e->getHeaders());
        });

        // Lỗi bất ngờ: không lộ nội dung exception ra client (APP_DEBUG=true vẫn hiện để dev debug)
        $exceptions->render(function (Throwable $e, Request $request) use ($wantsJson) {
            if (! $wantsJson($request) || config('app.debug') || $e instanceof HttpResponseException) {
                return null;
            }

            return ApiResponse::error('Server error.', 500);
        });
    })->create();
