<?php

use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

// Route tạm chỉ tồn tại trong test, dùng để kiểm tra middleware và handler mà không phụ thuộc endpoint thật
beforeEach(function () {
    Route::middleware(['api', 'auth:sanctum'])->get('/api/_test/any-auth', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'role:admin'])->get('/api/_test/admin-only', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'role:superadmin,admin'])->get('/api/_test/staff-only', fn () => ['ok' => true]);
    Route::middleware(['api', 'auth:sanctum', 'active'])->get('/api/_test/active-only', fn () => ['ok' => true]);
    Route::middleware('api')->get('/api/_test/users/{user}', fn (User $user) => ['id' => $user->id]);
    Route::middleware('api')->get('/api/_test/boom', fn () => throw new RuntimeException('SQLSTATE[HY000] noi dung noi bo'));
    Route::middleware('api')->post('/api/_test/validate', function (Request $request) {
        $request->validate(['name' => 'required', 'email' => 'required|email']);
    });
});

function authHeadersFor(User $user): array
{
    return ['Authorization' => 'Bearer '.$user->createToken('test')->plainTextToken];
}

// -----------------------------------------------------------------------------
// 401
// -----------------------------------------------------------------------------
test('thiếu token trả về 401 theo định dạng chuẩn', function () {
    $this->getJson('/api/_test/any-auth')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
});

test('token không hợp lệ trả về 401', function () {
    $this->withHeader('Authorization', 'Bearer khong-phai-token-that')
        ->getJson('/api/_test/any-auth')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
});

test('token còn trong thời hạn thì dùng được', function () {
    $headers = authHeadersFor(User::factory()->create());

    $this->travel(479)->minutes();

    $this->withHeaders($headers)->getJson('/api/_test/any-auth')->assertOk();
});

test('token quá thời hạn 480 phút bị từ chối 401', function () {
    $headers = authHeadersFor(User::factory()->create());

    $this->travel(481)->minutes();

    $this->withHeaders($headers)->getJson('/api/_test/any-auth')
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'message' => 'Unauthenticated.']);
});

// -----------------------------------------------------------------------------
// 403 - role middleware
// -----------------------------------------------------------------------------
test('sai role trả về 403 theo định dạng chuẩn', function () {
    $this->withHeaders(authHeadersFor(User::factory()->create()))
        ->getJson('/api/_test/admin-only')
        ->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
});

test('Gate/Policy từ chối cũng trả về 403 với cùng message chuẩn', function () {
    Gate::define('khong-duoc-phep', fn (User $user) => false);
    Route::middleware(['api', 'auth:sanctum'])->get('/api/_test/gate', fn () => Gate::authorize('khong-duoc-phep'));

    $this->withHeaders(authHeadersFor(User::factory()->create()))
        ->getJson('/api/_test/gate')
        ->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
});

test('abort(403) với message riêng vẫn trả về message chuẩn, không lộ chi tiết nội bộ', function () {
    Route::middleware('api')->get('/api/_test/abort', fn () => abort(403, 'Ban la superadmin id 1 nen khong duoc xoa'));

    $this->getJson('/api/_test/abort')
        ->assertStatus(403)
        ->assertExactJson([
            'success' => false,
            'message' => 'You do not have permission to perform this action.',
        ]);
});

test('đúng role thì qua middleware', function () {
    $this->withHeaders(authHeadersFor(User::factory()->admin()->create()))
        ->getJson('/api/_test/admin-only')
        ->assertOk();
});

test('middleware role nhận nhiều role và vẫn chặn role còn lại', function (string $state, int $expected) {
    $user = User::factory()->{$state}()->create();

    $this->withHeaders(authHeadersFor($user))->getJson('/api/_test/staff-only')->assertStatus($expected);
})->with([
    'superadmin' => ['superadmin', 200],
    'admin'      => ['admin', 200],
]);

test('USER bị chặn khỏi route dành cho quản trị', function () {
    $this->withHeaders(authHeadersFor(User::factory()->create()))
        ->getJson('/api/_test/staff-only')
        ->assertStatus(403);
});

test('role middleware không có user đăng nhập thì trả về 401, không phải 403', function () {
    $this->getJson('/api/_test/admin-only')->assertStatus(401);
});

// -----------------------------------------------------------------------------
// 403 - active middleware
// -----------------------------------------------------------------------------
test('user active qua được middleware active', function () {
    $this->withHeaders(authHeadersFor(User::factory()->create()))
        ->getJson('/api/_test/active-only')
        ->assertOk();
});

test('user inactive hoặc blocked dù còn token vẫn bị chặn 403', function (string $state) {
    $user = User::factory()->{$state}()->create();

    $this->withHeaders(authHeadersFor($user))
        ->getJson('/api/_test/active-only')
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'Your account is not active.']);
})->with(['inactive', 'blocked']);

test('user đã bị xóa mềm dù còn token vẫn bị từ chối 401', function () {
    $user    = User::factory()->create();
    $headers = authHeadersFor($user);

    $user->delete();

    $this->withHeaders($headers)->getJson('/api/_test/any-auth')->assertStatus(401);
});

// -----------------------------------------------------------------------------
// 404
// -----------------------------------------------------------------------------
test('user không tồn tại trả về 404 với message User not found.', function () {
    $this->getJson('/api/_test/users/999999')
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'User not found.']);
});

test('user đã xóa mềm cũng trả về 404', function () {
    $user = User::factory()->create();
    $user->delete();

    $this->getJson("/api/_test/users/{$user->id}")
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'User not found.']);
});

test('route không tồn tại trả về 404 với message chung', function () {
    $this->getJson('/api/khong-co-route-nay')
        ->assertStatus(404)
        ->assertExactJson(['success' => false, 'message' => 'Resource not found.']);
});

// -----------------------------------------------------------------------------
// 422
// -----------------------------------------------------------------------------
test('lỗi validate trả về 422 với message chung và errors theo từng trường', function () {
    $this->postJson('/api/_test/validate', ['email' => 'khong-phai-email'])
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors(['name', 'email']);
});

// -----------------------------------------------------------------------------
// Lỗi HTTP khác và 500
// -----------------------------------------------------------------------------
test('sai HTTP method trả về 405 theo định dạng chuẩn', function () {
    $response = $this->postJson('/api/_test/any-auth');

    $response->assertStatus(405)->assertJson(['success' => false]);
    expect($response->json('message'))->toBeString()->not->toBeEmpty();
});

test('lỗi bất ngờ trả về 500 và không lộ nội dung exception khi tắt debug', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/_test/boom');

    $response->assertStatus(500)
        ->assertExactJson(['success' => false, 'message' => 'Server error.']);
    expect($response->getContent())->not->toContain('SQLSTATE');
});

test('mọi lỗi API đều là JSON kể cả khi client không gửi Accept: application/json', function () {
    $response = $this->call('GET', '/api/_test/any-auth');

    $response->assertStatus(401);
    expect($response->headers->get('Content-Type'))->toContain('application/json')
        ->and($response->json('success'))->toBeFalse();
});

// -----------------------------------------------------------------------------
// ApiResponse
// -----------------------------------------------------------------------------
test('ApiResponse::success trả về success, message và data', function () {
    $body = ApiResponse::success(['a' => 1], 'Xong.', 201);

    expect($body->getStatusCode())->toBe(201)
        ->and($body->getData(true))->toBe(['success' => true, 'message' => 'Xong.', 'data' => ['a' => 1]]);
});

test('ApiResponse::success bỏ qua message và data khi không có', function () {
    expect(ApiResponse::success()->getData(true))->toBe(['success' => true]);
});

test('ApiResponse::success tự resolve JsonResource và không lộ password', function () {
    $user = User::factory()->create();

    $data = ApiResponse::success(new UserResource($user))->getData(true)['data'];

    expect($data)->toHaveKeys(['id', 'name', 'email'])
        ->not->toHaveKey('password');
});

test('ApiResponse::paginated trả về data và meta đúng định dạng', function () {
    User::factory()->count(5)->create();

    $body = ApiResponse::paginated(User::orderBy('id')->paginate(2, page: 2), UserResource::class)->getData(true);

    expect($body['success'])->toBeTrue()
        ->and($body['data'])->toHaveCount(2)
        ->and($body['data'][0])->toHaveKeys(['id', 'name', 'email'])
        ->and($body['meta'])->toBe(['current_page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3])
        ->and($body)->not->toHaveKey('message');
});
