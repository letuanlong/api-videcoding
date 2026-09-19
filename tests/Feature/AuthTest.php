<?php
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class);

// Login/Logout: tests/Feature/LoginTest.php. Đổi mật khẩu: tests/Feature/ProfileTest.php.
// File này giữ các test về cách API phản hồi khi client không gửi header "Accept: application/json".

test('route cần đăng nhập trả về 401 JSON dù client không gửi Accept: application/json', function (string $method, string $uri) {
    $response = $this->call($method, $uri);

    $response->assertStatus(401);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
})->with([
    'GET /api/user'                    => ['GET', '/api/user'],
    'GET /api/orders'                  => ['GET', '/api/orders'],
    'POST /api/orders'                 => ['POST', '/api/orders'],
    'POST /api/logout'                 => ['POST', '/api/logout'],
    'GET /api/profile'                 => ['GET', '/api/profile'],
    'PUT /api/profile'                 => ['PUT', '/api/profile'],
    'POST /api/profile/change-password' => ['POST', '/api/profile/change-password'],
    'GET /api/dashboard'               => ['GET', '/api/dashboard'],
    'GET /api/admin/users'             => ['GET', '/api/admin/users'],
    'GET /api/admin/audit-logs'        => ['GET', '/api/admin/audit-logs'],
]);

test('validate lỗi trả về 422 JSON dù client không gửi Accept: application/json', function () {
    $response = $this->call('POST', '/api/login', []);

    $response->assertStatus(422);
    expect($response->headers->get('Content-Type'))->toContain('application/json');
});
