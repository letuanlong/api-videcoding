<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function dashSignIn(string $role, array $attributes = []): User
{
    $user = User::factory()->{$role}()->create($attributes);
    Sanctum::actingAs($user);

    return $user;
}

test('SUPERADMIN thấy thống kê của ADMIN và USER, không tính SUPERADMIN', function () {
    dashSignIn('superadmin');
    User::factory()->superadmin()->create();                 // không tính
    User::factory()->admin()->count(2)->create();            // 2 active
    User::factory()->count(3)->create();                     // 3 active
    User::factory()->inactive()->count(2)->create();         // 2 inactive
    User::factory()->admin()->blocked()->create();           // 1 blocked

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats', ['total' => 8, 'active' => 5, 'inactive' => 2, 'blocked' => 1]);
});

test('ADMIN chỉ thấy thống kê của USER', function () {
    dashSignIn('admin');
    User::factory()->admin()->count(3)->create();            // không tính
    User::factory()->superadmin()->create();                 // không tính
    User::factory()->count(2)->create();
    User::factory()->inactive()->create();
    User::factory()->blocked()->count(4)->create();

    $this->getJson('/api/dashboard')
        ->assertOk()
        ->assertJsonPath('data.stats', ['total' => 7, 'active' => 2, 'inactive' => 1, 'blocked' => 4]);
});

test('USER thường chỉ có dashboard cá nhân, không có thống kê', function () {
    $me = dashSignIn('user', ['name' => 'John']);
    User::factory()->count(5)->create();

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect($response->json('data'))->not->toHaveKey('stats')
        ->and($response->json('data.me.id'))->toBe($me->id)
        ->and($response->json('data.me.name'))->toBe('John');
});

test('me có đúng các trường và last_login_at, không có mật khẩu', function () {
    dashSignIn('admin', ['last_login_at' => '2026-09-10 10:00:00']);

    $response = $this->getJson('/api/dashboard')->assertOk();

    expect(array_keys($response->json('data.me')))->toBe(['id', 'name', 'email', 'phone', 'role', 'avatar', 'last_login_at'])
        ->and($response->json('data.me.last_login_at'))->toStartWith('2026-09-10')
        ->and($response->getContent())->not->toContain('password')->not->toContain('$2y$');
});

test('user đã xóa mềm không được tính, chưa có ai thì tất cả bằng 0', function () {
    dashSignIn('admin');

    $this->getJson('/api/dashboard')->assertJsonPath('data.stats', ['total' => 0, 'active' => 0, 'inactive' => 0, 'blocked' => 0]);

    User::factory()->create()->delete();

    $this->getJson('/api/dashboard')->assertJsonPath('data.stats.total', 0);
});

test('thống kê chỉ dùng một truy vấn GROUP BY trên bảng users cho phần đếm', function () {
    dashSignIn('superadmin');
    User::factory()->count(10)->create();

    DB::enableQueryLog();
    $this->getJson('/api/dashboard')->assertOk();

    $counting = collect(DB::getQueryLog())->pluck('query')->filter(fn ($q) => str_contains(strtolower($q), 'count('));

    expect($counting)->toHaveCount(1)
        ->and(strtolower($counting->first()))->toContain('group by');
});

test('dashboard yêu cầu đăng nhập và tài khoản active', function () {
    $this->getJson('/api/dashboard')->assertStatus(401);

    dashSignIn('admin', ['status' => 'inactive']);

    $this->getJson('/api/dashboard')->assertStatus(403);
});
