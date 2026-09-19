<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
   public function run(): void
    {
        // 1. Tạo 2 User
        $user1 = User::firstOrCreate(['email' => 'long@gmail.com'], [
            'name' => 'Lê Tuấn Long',
            'password' => bcrypt('12345678')
        ]);

        $user2 = User::firstOrCreate(['email' => 'user2@gmail.com'], [
            'name' => 'Nguyễn Văn B',
            'password' => bcrypt('12345678')
        ]);

        // 2. Tạo 3 Sản phẩm
        $p1 = Product::create(['name' => 'iPhone 15 Pro', 'stock' => 20, 'price' => 25000000]);
        $p2 = Product::create(['name' => 'MacBook Air M2', 'stock' => 15, 'price' => 28000000]);
        $p3 = Product::create(['name' => 'Tai nghe AirPods Pro', 'stock' => 50, 'price' => 5000000]);

        // 3. Tạo 12 đơn hàng cho User 1
        for ($i = 1; $i <= 12; $i++) {
            Order::create([
                'user_id' => $user1->id,
                'product_id' => $p1->id,
                'quantity' => 1,
                'total_price' => $p1->price,
            ]);
        }

        // 4. Tạo 3 đơn hàng cho User 2
        for ($i = 1; $i <= 3; $i++) {
            Order::create([
                'user_id' => $user2->id,
                'product_id' => $p2->id,
                'quantity' => 1,
                'total_price' => $p2->price,
            ]);
        }
    }
}
