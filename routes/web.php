<?php

use App\Http\Middleware\CmsSecurityHeaders;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// CMS là một SPA: mọi đường dẫn dưới /cms đều trả về cùng một trang, Vue Router tự xử lý phần còn lại.
// Quyền truy cập thật sự do API kiểm tra, trang này không chứa dữ liệu nhạy cảm.
Route::view('/cms/{any?}', 'cms')
    ->where('any', '.*')
    ->middleware(CmsSecurityHeaders::class);
