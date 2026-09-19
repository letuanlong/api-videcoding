<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function lstSignIn(string $role): User
{
    $user = User::factory()->{$role}()->create();
    Sanctum::actingAs($user);

    return $user;
}

/** Lấy danh sách id trong trang hiện tại của response, đã sắp xếp để dễ so sánh. */
function lstIds($response): array
{
    return collect($response->json('data'))->pluck('id')->sort()->values()->all();
}

// -----------------------------------------------------------------------------
// UMS-009: PHÂN QUYỀN DANH SÁCH
// -----------------------------------------------------------------------------
test('SUPERADMIN thấy ADMIN và USER, không thấy SUPERADMIN mặc định', function () {
    $me     = lstSignIn('superadmin');
    $other  = User::factory()->superadmin()->create();
    $admin  = User::factory()->admin()->create();
    $user   = User::factory()->create();

    $response = $this->getJson('/api/admin/users')->assertOk();

    expect(lstIds($response))->toBe([$admin->id, $user->id])
        ->and(lstIds($response))->not->toContain($me->id, $other->id);
});

test('ADMIN chỉ thấy USER', function () {
    lstSignIn('admin');
    User::factory()->admin()->create();
    User::factory()->superadmin()->create();
    $u1 = User::factory()->create();
    $u2 = User::factory()->create();

    expect(lstIds($this->getJson('/api/admin/users')->assertOk()))->toBe([$u1->id, $u2->id]);
});

test('USER bị chặn 403, chưa đăng nhập bị chặn 401', function () {
    lstSignIn('user');

    $this->getJson('/api/admin/users')
        ->assertStatus(403)
        ->assertExactJson(['success' => false, 'message' => 'You do not have permission to perform this action.']);

    $this->app['auth']->forgetGuards();

    $this->getJson('/api/admin/users')->assertStatus(401);
});

test('user đã xóa mềm không xuất hiện trong danh sách', function () {
    lstSignIn('admin');
    $kept    = User::factory()->create();
    $deleted = User::factory()->create();
    $deleted->delete();

    expect(lstIds($this->getJson('/api/admin/users')))->toBe([$kept->id]);
});

test('mỗi phần tử có đúng các trường của đặc tả và không bao giờ có mật khẩu', function () {
    lstSignIn('superadmin');
    User::factory()->create(['name' => 'Nguyen Van A']);

    $response = $this->getJson('/api/admin/users')->assertOk();

    expect(array_keys($response->json('data.0')))->toBe([
        'id', 'name', 'email', 'phone', 'avatar', 'role', 'status',
        'last_login_at', 'created_at', 'updated_at', 'abilities',
    ]);

    // 'reset_password' trong abilities là tên quyền, không phải mật khẩu; cấm khoá "password" và mọi chuỗi hash bcrypt
    expect($response->getContent())
        ->not->toContain('"password"')
        ->not->toContain('remember_token')
        ->not->toContain('$2y$');
});

test('abilities phản ánh đúng quyền của người đang xem', function () {
    lstSignIn('admin');
    User::factory()->create();

    $this->getJson('/api/admin/users')
        ->assertOk()
        ->assertJsonPath('data.0.abilities', [
            'update' => true, 'delete' => true, 'change_status' => true, 'reset_password' => true,
        ]);
});

test('danh sách sắp xếp mới nhất lên đầu', function () {
    lstSignIn('admin');
    $first  = User::factory()->create();
    $second = User::factory()->create();

    expect(collect($this->getJson('/api/admin/users')->json('data'))->pluck('id')->all())
        ->toBe([$second->id, $first->id]);
});

// -----------------------------------------------------------------------------
// UMS-012: PHÂN TRANG
// -----------------------------------------------------------------------------
test('mặc định 20 bản ghi mỗi trang và trả đủ meta', function () {
    lstSignIn('admin');
    User::factory()->count(45)->create();

    $response = $this->getJson('/api/admin/users')->assertOk();

    expect($response->json('data'))->toHaveCount(20)
        ->and($response->json('meta'))->toBe(['current_page' => 1, 'per_page' => 20, 'total' => 45, 'last_page' => 3])
        ->and($response->json('success'))->toBeTrue();
});

test('per_page hợp lệ được áp dụng', function (int $perPage) {
    lstSignIn('admin');
    User::factory()->count(105)->create();

    $response = $this->getJson("/api/admin/users?per_page={$perPage}")->assertOk();

    expect($response->json('data'))->toHaveCount($perPage)
        ->and($response->json('meta.per_page'))->toBe($perPage)
        ->and($response->json('meta.last_page'))->toBe((int) ceil(105 / $perPage));
})->with([10, 20, 50, 100]);

test('per_page không hợp lệ quay về 20 chứ không gây lỗi', function (string $query) {
    lstSignIn('admin');
    User::factory()->count(30)->create();

    $response = $this->getJson("/api/admin/users?{$query}")->assertOk();

    expect($response->json('meta.per_page'))->toBe(20)
        ->and($response->json('data'))->toHaveCount(20);
})->with([
    'số không nằm trong danh sách' => ['per_page=7'],
    'quá lớn'                      => ['per_page=1000'],
    'bằng 0'                       => ['per_page=0'],
    'số âm'                        => ['per_page=-5'],
    'chữ'                          => ['per_page=abc'],
    'số thực'                      => ['per_page=10.5'],
    'dạng mảng'                    => ['per_page[]=10'],
    'rỗng'                         => ['per_page='],
]);

test('chuyển trang hoạt động và không lặp bản ghi giữa các trang', function () {
    lstSignIn('admin');
    User::factory()->count(25)->create();

    $page1 = $this->getJson('/api/admin/users?per_page=10&page=1');
    $page2 = $this->getJson('/api/admin/users?per_page=10&page=2');
    $page3 = $this->getJson('/api/admin/users?per_page=10&page=3');

    expect($page2->json('meta.current_page'))->toBe(2)
        ->and($page3->json('data'))->toHaveCount(5)
        ->and(array_merge(lstIds($page1), lstIds($page2), lstIds($page3)))->toHaveCount(25)
        ->and(collect(array_merge(lstIds($page1), lstIds($page2), lstIds($page3)))->unique())->toHaveCount(25);
});

test('page vượt quá trang cuối trả về danh sách rỗng nhưng meta vẫn đúng', function () {
    lstSignIn('admin');
    User::factory()->count(3)->create();

    $response = $this->getJson('/api/admin/users?page=99')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(3)
        ->and($response->json('meta.last_page'))->toBe(1);
});

test('page không hợp lệ quay về trang 1', function (string $page) {
    lstSignIn('admin');
    User::factory()->count(3)->create();

    $this->getJson("/api/admin/users?page={$page}")
        ->assertOk()
        ->assertJsonPath('meta.current_page', 1);
})->with(['abc', '0', '-3']);

// -----------------------------------------------------------------------------
// UMS-010: TÌM KIẾM
// -----------------------------------------------------------------------------
test('tìm theo tên, email và số điện thoại', function (string $search, string $field) {
    lstSignIn('admin');
    $match = User::factory()->create([
        'name'  => 'Nguyen Long Hai',
        'email' => 'hai.nguyen@corp.test',
        'phone' => '0987654321',
    ]);
    User::factory()->create(['name' => 'Tran Binh', 'email' => 'binh@other.test', 'phone' => '0123456789']);

    expect(lstIds($this->getJson('/api/admin/users?search='.urlencode($search))->assertOk()))
        ->toBe([$match->id], "tìm theo {$field}");
})->with([
    'tên'           => ['Long Hai', 'name'],
    'email'         => ['hai.nguyen@corp', 'email'],
    'số điện thoại' => ['98765', 'phone'],
]);

test('tìm kiếm không phân biệt hoa thường', function () {
    lstSignIn('admin');
    $match = User::factory()->create(['name' => 'Nguyen Long']);

    expect(lstIds($this->getJson('/api/admin/users?search=NGUYEN%20LONG')))->toBe([$match->id]);
});

test('tìm không thấy thì trả về danh sách rỗng', function () {
    lstSignIn('admin');
    User::factory()->create(['name' => 'Tran Binh']);

    $response = $this->getJson('/api/admin/users?search=khong-ton-tai')->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
});

test('tìm kiếm kết hợp với phân trang, meta phản ánh đúng số kết quả', function () {
    lstSignIn('admin');
    User::factory()->count(12)->create(['name' => 'Le Long']);
    User::factory()->count(5)->create(['name' => 'Pham Khac']);

    $response = $this->getJson('/api/admin/users?search=long&per_page=10&page=2')->assertOk();

    expect($response->json('data'))->toHaveCount(2)
        ->and($response->json('meta'))->toBe(['current_page' => 2, 'per_page' => 10, 'total' => 12, 'last_page' => 2]);
});

test('điều kiện OR của tìm kiếm không làm hỏng phạm vi role: ADMIN không thấy ADMIN khớp từ khóa', function () {
    lstSignIn('admin');
    $visible = User::factory()->create(['name' => 'Long User']);
    User::factory()->admin()->create(['name' => 'Long Admin']);
    User::factory()->superadmin()->create(['name' => 'Long Super', 'email' => 'long.super@example.com']);

    expect(lstIds($this->getJson('/api/admin/users?search=long')))->toBe([$visible->id]);
});

test('ký tự % và _ trong từ khóa được coi là chữ thường, không phải ký tự đại diện', function () {
    lstSignIn('admin');
    $percent    = User::factory()->create(['name' => 'Giam 100% ngay']);
    $underscore = User::factory()->create(['name' => 'ten_dat_biet']);
    User::factory()->create(['name' => 'Giam 1000 ngay']);
    User::factory()->create(['name' => 'tenXdatXbiet']);

    expect(lstIds($this->getJson('/api/admin/users?search='.urlencode('100%'))))->toBe([$percent->id])
        ->and(lstIds($this->getJson('/api/admin/users?search='.urlencode('ten_dat'))))->toBe([$underscore->id]);
});

test('tìm kiếm chạy ở mức database (LIKE + LIMIT) chứ không tải hết user vào PHP', function () {
    lstSignIn('admin');
    User::factory()->count(30)->create();
    $target = User::factory()->create(['name' => 'Duy Nhat Mot']);

    DB::enableQueryLog();
    $response = $this->getJson('/api/admin/users?search=Duy%20Nhat')->assertOk();
    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' | ');

    expect(lstIds($response))->toBe([$target->id])
        ->and(strtolower($queries))->toContain('like')->toContain('limit');
});

test('từ khóa quá dài bị từ chối 422', function () {
    lstSignIn('admin');

    $this->getJson('/api/admin/users?search='.str_repeat('a', 101))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['search']);
});

// -----------------------------------------------------------------------------
// UMS-011: LỌC
// -----------------------------------------------------------------------------
test('lọc theo role', function () {
    lstSignIn('superadmin');
    $admin = User::factory()->admin()->create();
    $user  = User::factory()->create();

    expect(lstIds($this->getJson('/api/admin/users?role=admin')))->toBe([$admin->id])
        ->and(lstIds($this->getJson('/api/admin/users?role=user')))->toBe([$user->id])
        ->and(lstIds($this->getJson('/api/admin/users?role=all')))->toBe([$admin->id, $user->id]);
});

test('lọc theo status', function () {
    lstSignIn('admin');
    $active   = User::factory()->create();
    $inactive = User::factory()->inactive()->create();
    $blocked  = User::factory()->blocked()->create();

    expect(lstIds($this->getJson('/api/admin/users?status=active')))->toBe([$active->id])
        ->and(lstIds($this->getJson('/api/admin/users?status=inactive')))->toBe([$inactive->id])
        ->and(lstIds($this->getJson('/api/admin/users?status=blocked')))->toBe([$blocked->id])
        ->and(lstIds($this->getJson('/api/admin/users?status=all')))->toBe([$active->id, $inactive->id, $blocked->id]);
});

test('role, status và search kết hợp được với nhau và với phân trang', function () {
    lstSignIn('superadmin');
    $hit = User::factory()->admin()->inactive()->create(['name' => 'Long Match']);
    User::factory()->admin()->create(['name' => 'Long Sai Status']);
    User::factory()->inactive()->create(['name' => 'Long Sai Role']);
    User::factory()->admin()->inactive()->create(['name' => 'Khac Ten']);

    $response = $this->getJson('/api/admin/users?role=admin&status=inactive&search=long&per_page=10')->assertOk();

    expect(lstIds($response))->toBe([$hit->id])
        ->and($response->json('meta.total'))->toBe(1);
});

test('ADMIN lọc role không được phép thì nhận danh sách rỗng, không lộ dữ liệu', function (string $role) {
    lstSignIn('admin');
    User::factory()->admin()->create();
    User::factory()->superadmin()->create();

    $response = $this->getJson("/api/admin/users?role={$role}")->assertOk();

    expect($response->json('data'))->toBe([])
        ->and($response->json('meta.total'))->toBe(0);
})->with(['admin', 'superadmin']);

test('SUPERADMIN được lọc role=superadmin để xem các tài khoản SUPERADMIN', function () {
    lstSignIn('superadmin');
    $other = User::factory()->superadmin()->create();
    User::factory()->admin()->create();

    $response = $this->getJson('/api/admin/users?role=superadmin')->assertOk();

    expect(lstIds($response))->toContain($other->id)
        ->and(collect($response->json('data'))->pluck('role')->unique()->all())->toBe(['superadmin']);
});

test('SUPERADMIN thấy SUPERADMIN khác nhưng không được thao tác trên đó', function () {
    lstSignIn('superadmin');
    User::factory()->superadmin()->create();

    $this->getJson('/api/admin/users?role=superadmin')
        ->assertOk()
        ->assertJsonPath('data.0.abilities', [
            'update' => false, 'delete' => false, 'change_status' => false, 'reset_password' => false,
        ]);
});

test('giá trị role hoặc status không hợp lệ bị từ chối 422', function (string $query, string $field) {
    lstSignIn('admin');

    $this->getJson("/api/admin/users?{$query}")
        ->assertStatus(422)
        ->assertJson(['success' => false, 'message' => 'Validation failed.'])
        ->assertJsonValidationErrors([$field]);
})->with([
    'role lạ'   => ['role=boss', 'role'],
    'status lạ' => ['status=deleted', 'status'],
]);
