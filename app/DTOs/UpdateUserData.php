<?php

namespace App\DTOs;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Support\Arr;

readonly class UpdateUserData
{
    /**
     * @param array<string, mixed> $attributes chỉ các trường thường (name/email/phone) thực sự được gửi lên
     */
    public function __construct(
        public array $attributes,
        public ?UserRole $role,
        public ?UserStatus $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data dữ liệu đã qua validate
     */
    public static function fromArray(array $data): self
    {
        return new self(
            Arr::only($data, ['name', 'email', 'phone']),
            isset($data['role']) ? UserRole::from($data['role']) : null,
            isset($data['status']) ? UserStatus::from($data['status']) : null,
        );
    }
}
