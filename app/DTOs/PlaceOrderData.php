<?php
namespace App\DTOs;

readonly class PlaceOrderData
{
    public function __construct(
        public int $userId,
        public int $productId,
        public int $quantity,
    ) {}

    public static function fromArray(array $data, int $userId): self
    {
        return new self(
            userId: $userId,
            productId: (int) $data['product_id'],
            quantity: (int) $data['quantity'],
        );
    }
}