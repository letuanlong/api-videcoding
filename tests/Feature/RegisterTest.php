<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// -----------------------------------------------------------------------------
// TEST REGISTER
// -----------------------------------------------------------------------------
test('user có thể đăng ký tài khoản mới thành công', function () {
    $response = $this->postJson('/api/auth/register', [
        'name'     => 'Lê Tuấn Long',
        'email'    => 'long@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJson(['message' => 'Đăng ký tài khoản thành công!'])
        ->assertJsonPath('data.name', 'Lê Tuấn Long')
        ->assertJsonPath('data.email', 'long@example.com')
        ->assertJsonStructure(['message', 'data' => ['id', 'name', 'email', 'created_at']]);

    $this->assertDatabaseHas('users', ['email' => 'long@example.com']);
});

test('mật khẩu được hash trong DB và không bị lộ trong response', function () {
    $response = $this->postJson('/api/auth/register', [
        'name'     => 'Test User',
        'email'    => 'hash@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(201)
        ->assertJsonMissingPath('data.password');

    $user = User::where('email', 'hash@example.com')->firstOrFail();

    expect($user->password)->not->toBe('password123')
        ->and(Hash::check('password123', $user->password))->toBeTrue();
});

test('user vừa đăng ký có thể đăng nhập ngay', function () {
    $this->postJson('/api/auth/register', [
        'name'     => 'Test User',
        'email'    => 'flow@example.com',
        'password' => 'password123',
    ])->assertStatus(201);

    $this->postJson('/api/auth/login', [
        'email'    => 'flow@example.com',
        'password' => 'password123',
    ])->assertStatus(200)->assertJsonStructure(['access_token']);
});

test('không thể đăng ký với email đã tồn tại', function () {
    User::factory()->create(['email' => 'dup@example.com']);

    $this->postJson('/api/auth/register', [
        'name'     => 'Người khác',
        'email'    => 'dup@example.com',
        'password' => 'password123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);

    expect(User::where('email', 'dup@example.com')->count())->toBe(1);
});

test('đăng ký thiếu trường bắt buộc trả về 422', function () {
    $this->postJson('/api/auth/register', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'email', 'password']);
});

test('đăng ký với email sai định dạng trả về 422', function () {
    $this->postJson('/api/auth/register', [
        'name'     => 'Test User',
        'email'    => 'khong-phai-email',
        'password' => 'password123',
    ])->assertStatus(422)->assertJsonValidationErrors(['email']);
});

test('đăng ký với mật khẩu ngắn hơn 8 ký tự trả về 422', function () {
    $this->postJson('/api/auth/register', [
        'name'     => 'Test User',
        'email'    => 'short@example.com',
        'password' => '1234567',
    ])->assertStatus(422)->assertJsonValidationErrors(['password']);

    $this->assertDatabaseMissing('users', ['email' => 'short@example.com']);
});

test('không thể tự gán trường ngoài name, email, password khi đăng ký', function () {
    $this->postJson('/api/auth/register', [
        'name'              => 'Test User',
        'email'             => 'extra@example.com',
        'password'          => 'password123',
        'email_verified_at' => now()->toDateTimeString(),
        'id'                => 9999,
    ])->assertStatus(201);

    $user = User::where('email', 'extra@example.com')->firstOrFail();

    expect($user->id)->not->toBe(9999)
        ->and($user->email_verified_at)->toBeNull();
});
