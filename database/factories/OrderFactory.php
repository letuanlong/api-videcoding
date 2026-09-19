<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $product  = Product::factory()->create();
        $quantity = fake()->numberBetween(1, 3);

        return [
            'user_id'     => User::factory(),
            'product_id'  => $product->id,
            'quantity'    => $quantity,
            'total_price' => $product->price * $quantity,
        ];
    }
}
