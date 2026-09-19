<?php

namespace App\DTOs;

use App\Enums\UserRole;
use App\Enums\UserStatus;

readonly class CreateUserData
{
    public function __construct(
        public string $name,
        public string $email,
        public ?string $phone,
        #[\SensitiveParameter] public string $password,
        public UserRole $role,
        public UserStatus $status,
    ) {
    }

    /**
     * @param array<string, mixed> $data dữ liệu đã qua validate
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['name'],
            $data['email'],
            $data['phone'] ?? null,
            $data['password'],
            UserRole::from($data['role']),
            UserStatus::from($data['status']),
        );
    }
}
