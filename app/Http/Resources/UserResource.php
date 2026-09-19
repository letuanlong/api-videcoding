<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Gate;

/**
 * Chỉ liệt kê tường minh các trường được phép trả ra (whitelist): password và remember_token không bao giờ xuất hiện.
 */
class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $actor = $request->user();

        return [
            'id'            => $this->id,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'avatar'        => $this->avatarUrl(),
            'role'          => $this->role->value,
            'status'        => $this->status->value,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at'    => $this->created_at?->toIso8601String(),
            'updated_at'    => $this->updated_at?->toIso8601String(),

            // Quyền của người đang xem trên bản ghi này, để frontend ẩn/hiện nút mà không phải tự lặp lại
            // ma trận phân quyền. Chỉ phục vụ UX; backend vẫn kiểm tra lại ở mọi endpoint.
            'abilities'     => $this->when($actor !== null, fn () => [
                'update'         => Gate::forUser($actor)->allows('update', $this->resource),
                'delete'         => Gate::forUser($actor)->allows('delete', $this->resource),
                'change_status'  => Gate::forUser($actor)->allows('changeStatus', $this->resource),
                'reset_password' => Gate::forUser($actor)->allows('resetPassword', $this->resource),
            ]),
        ];
    }
}
