<?php

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * Tạo header Bearer token cho user (mỗi test tự tạo user riêng).
 */
function bearer(User $user): array
{
    return ['Authorization' => 'Bearer ' . $user->createToken('test-token')->plainTextToken];
}

// -----------------------------------------------------------------------------
// 1. TEST ĐẶT HÀNG (POST /api/orders)
// -----------------------------------------------------------------------------
test('đặt hàng thành công sẽ tạo đơn và trừ tồn kho', function () {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['stock' => 10, 'price' => 25000]);

    $response = $this->withHeaders(bearer($user))
        ->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 3]);

    $response->assertStatus(201)
        ->assertJson(['message' => 'Đặt hàng thành công!'])
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.product_id', $product->id)
        ->assertJsonPath('data.quantity', 3);

    $this->assertDatabaseHas('orders', [
        'user_id'     => $user->id,
        'product_id'  => $product->id,
        'quantity'    => 3,
        'total_price' => 75000,
    ]);
    expect($product->fresh()->stock)->toBe(7);
});

test('đặt đúng bằng số lượng tồn kho thì thành công và tồn kho về 0', function () {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['stock' => 5]);

    $this->withHeaders(bearer($user))
        ->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 5])
        ->assertStatus(201);

    expect($product->fresh()->stock)->toBe(0);
});

test('đặt hàng vượt quá tồn kho trả về 400 và không thay đổi dữ liệu', function () {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['name' => 'Hàng hiếm', 'stock' => 2]);

    $response = $this->withHeaders(bearer($user))
        ->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 3]);

    $response->assertStatus(400);
    expect($response->json('message'))->toContain('Hàng hiếm')->toContain('2');

    expect($product->fresh()->stock)->toBe(2);
    $this->assertDatabaseCount('orders', 0);
});

test('đặt hàng lần lượt đến khi hết kho thì lần cuối bị từ chối', function () {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['stock' => 3]);
    $headers = bearer($user);

    $this->withHeaders($headers)->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 2])
        ->assertStatus(201);
    $this->withHeaders($headers)->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 2])
        ->assertStatus(400);

    expect($product->fresh()->stock)->toBe(1);
    $this->assertDatabaseCount('orders', 1);
});

test('đặt hàng khi chưa đăng nhập trả về 401', function () {
    $product = Product::factory()->create(['stock' => 10]);

    $this->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => 1])
        ->assertStatus(401);

    expect($product->fresh()->stock)->toBe(10);
    $this->assertDatabaseCount('orders', 0);
});

test('đặt hàng thiếu dữ liệu trả về 422', function () {
    $user = User::factory()->create();

    $this->withHeaders(bearer($user))
        ->postJson('/api/orders', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product_id', 'quantity']);
});

test('đặt hàng với sản phẩm không tồn tại trả về 422', function () {
    $user = User::factory()->create();

    $this->withHeaders(bearer($user))
        ->postJson('/api/orders', ['product_id' => 99999, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['product_id']);
});

test('đặt hàng với số lượng không hợp lệ trả về 422', function (mixed $quantity) {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['stock' => 10]);

    $this->withHeaders(bearer($user))
        ->postJson('/api/orders', ['product_id' => $product->id, 'quantity' => $quantity])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['quantity']);

    expect($product->fresh()->stock)->toBe(10);
})->with([
    'bằng 0'   => [0],
    'số âm'    => [-1],
    'chữ'      => ['abc'],
    'số thực'  => [1.5],
]);

// -----------------------------------------------------------------------------
// 2. TEST DANH SÁCH ĐƠN HÀNG (GET /api/orders)
// -----------------------------------------------------------------------------
test('danh sách đơn hàng chỉ trả về đơn của user hiện tại', function () {
    $user  = User::factory()->create();
    $other = User::factory()->create();

    Order::factory()->count(2)->create(['user_id' => $user->id]);
    Order::factory()->count(3)->create(['user_id' => $other->id]);

    $response = $this->withHeaders(bearer($user))->getJson('/api/orders');

    $response->assertStatus(200)->assertJsonCount(2, 'data');
    expect($response->json('meta.total'))->toBe(2);
});

test('danh sách đơn hàng có thông tin sản phẩm và đúng cấu trúc', function () {
    $user    = User::factory()->create();
    $product = Product::factory()->create(['name' => 'iPhone 15', 'price' => 25000000]);
    Order::factory()->create([
        'user_id'     => $user->id,
        'product_id'  => $product->id,
        'quantity'    => 2,
        'total_price' => 50000000,
    ]);

    $this->withHeaders(bearer($user))->getJson('/api/orders')
        ->assertStatus(200)
        ->assertJsonStructure([
            'data' => [['id', 'total_price', 'quantity', 'created_at', 'product' => ['id', 'name', 'price']]],
            'links',
            'meta' => ['current_page', 'per_page', 'total'],
        ])
        ->assertJsonPath('data.0.product.name', 'iPhone 15')
        ->assertJsonPath('data.0.quantity', 2)
        ->assertJsonPath('data.0.total_price', 50000000);
});

test('danh sách đơn hàng phân trang 10 đơn mỗi trang', function () {
    $user = User::factory()->create();
    Order::factory()->count(12)->create(['user_id' => $user->id]);
    $headers = bearer($user);

    $page1 = $this->withHeaders($headers)->getJson('/api/orders');
    $page1->assertStatus(200)->assertJsonCount(10, 'data');
    expect($page1->json('meta.total'))->toBe(12)
        ->and($page1->json('meta.last_page'))->toBe(2);

    $this->withHeaders($headers)->getJson('/api/orders?page=2')
        ->assertStatus(200)
        ->assertJsonCount(2, 'data');
});

test('danh sách đơn hàng sắp xếp mới nhất lên đầu', function () {
    $user = User::factory()->create();
    $old  = Order::factory()->create(['user_id' => $user->id, 'created_at' => now()->subDays(2)]);
    $new  = Order::factory()->create(['user_id' => $user->id, 'created_at' => now()]);

    $this->withHeaders(bearer($user))->getJson('/api/orders')
        ->assertStatus(200)
        ->assertJsonPath('data.0.id', $new->id)
        ->assertJsonPath('data.1.id', $old->id);
});

test('đơn tạo cùng thời điểm vẫn có thứ tự ổn định: id lớn hơn xếp trước, không lặp giữa các trang', function () {
    $user = User::factory()->create();
    $same = now()->startOfSecond();
    $ids  = Order::factory()->count(12)->create(['user_id' => $user->id, 'created_at' => $same])->pluck('id');
    $headers = bearer($user);

    $page1 = collect($this->withHeaders($headers)->getJson('/api/orders')->json('data'))->pluck('id');
    $page2 = collect($this->withHeaders($headers)->getJson('/api/orders?page=2')->json('data'))->pluck('id');

    expect($page1->all())->toBe($ids->sortDesc()->take(10)->values()->all())
        ->and($page2->all())->toBe($ids->sortDesc()->slice(10)->values()->all());
});

test('user chưa có đơn hàng nhận danh sách rỗng', function () {
    $user = User::factory()->create();

    $this->withHeaders(bearer($user))->getJson('/api/orders')
        ->assertStatus(200)
        ->assertJsonCount(0, 'data');
});

test('xem danh sách đơn hàng khi chưa đăng nhập trả về 401', function () {
    $this->getJson('/api/orders')->assertStatus(401);
});
