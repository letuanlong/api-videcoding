<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'           => $this->id,
            'total_price'  => (float) $this->total_price,
            'quantity'     => $this->quantity,
            'created_at'   => $this->created_at->format('Y-m-d H:i:s'),
            // Transform thông tin Product đi kèm
            'product'      => [
                'id'    => $this->product->id,
                'name'  => $this->product->name,
                'price' => (float) $this->product->price,
            ],
        ];
    }
}
