<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AuditLogs\ListAuditLogsController;
use App\Http\Controllers\Admin\Users\ChangeUserStatusController;
use App\Http\Controllers\Admin\Users\CreateUserController;
use App\Http\Controllers\Admin\Users\DeleteUserController;
use App\Http\Controllers\Admin\Users\ListUsersController;
use App\Http\Controllers\Admin\Users\ResetUserPasswordController;
use App\Http\Controllers\Admin\Users\ShowUserController;
use App\Http\Controllers\Admin\Users\UpdateUserController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Dashboard\GetDashboardController;
use App\Http\Controllers\Orders\GetOrderListController;
use App\Http\Controllers\Orders\PlaceOrderController;
use App\Http\Controllers\Profile\ShowProfileController;
use App\Http\Controllers\Profile\UpdateProfileController;

// 1. PUBLIC ROUTES (Không cần Login)
Route::post('/login', LoginController::class)->middleware('throttle:5,1');

// 2. PROTECTED ROUTES (Bắt buộc phải có Token hợp lệ)
Route::middleware('auth:sanctum')->group(function () {

    // Logout không đi qua 'active': user vừa bị khóa vẫn phải tự đăng xuất được
    Route::post('/logout', LogoutController::class);

    // Các route còn lại chỉ dành cho tài khoản đang active
    Route::middleware('active')->group(function () {

        Route::get('/dashboard', GetDashboardController::class);

        // Profile của chính mình
        Route::get('/profile', ShowProfileController::class);
        Route::put('/profile', UpdateProfileController::class);
        // Giới hạn số lần thử để token bị đánh cắp không dùng được để dò mật khẩu hiện tại
        Route::post('/profile/change-password', ChangePasswordController::class)->middleware('throttle:5,1');

        // Order routes
        Route::post('/orders', PlaceOrderController::class);
        Route::get('/orders', GetOrderListController::class);

        Route::get('/user', function (Request $request) {
            return $request->user();
        });

        // User Management: chốt chặn thô theo role ở đây, quyền chi tiết trên từng bản ghi do UserPolicy quyết định
        Route::prefix('admin')->group(function () {
            Route::middleware('role:superadmin,admin')->group(function () {
                Route::get('/users', ListUsersController::class);
                Route::post('/users', CreateUserController::class);
                Route::get('/users/{user}', ShowUserController::class);
                Route::put('/users/{user}', UpdateUserController::class);
                Route::delete('/users/{user}', DeleteUserController::class);
                Route::patch('/users/{user}/status', ChangeUserStatusController::class);
                Route::post('/users/{user}/reset-password', ResetUserPasswordController::class);
            });

            // Audit log: chỉ SUPERADMIN
            Route::get('/audit-logs', ListAuditLogsController::class)->middleware('role:superadmin');
        });
    });
});
