<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ChangePasswordController;
use App\Http\Controllers\Orders\PlaceOrderController;
use App\Http\Controllers\Orders\GetOrderListController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;





// 1. PUBLIC ROUTES (Không cần Login)
Route::post('/auth/login', LoginController::class)->middleware('throttle:5,1');
Route::post('/auth/register', RegisterController::class);

// 2. PROTECTED ROUTES (Bắt buộc phải có Token hợp lệ)
Route::middleware('auth:sanctum')->group(function () {
    
    // Auth routes
    Route::post('/auth/logout', LogoutController::class);
    Route::post('/auth/change-password', ChangePasswordController::class);

    // Order routes
    Route::post('/orders', PlaceOrderController::class);
    Route::get('/orders', GetOrderListController::class);

});




Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');
