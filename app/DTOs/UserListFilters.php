<?php

namespace App\DTOs;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Support\PerPage;

readonly class UserListFilters
{
    public function __construct(
        public ?string $search,
        public ?UserRole $role,
        public ?UserStatus $status,
        public int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $data dữ liệu đã qua validate
     */
    public static function fromArray(array $data): self
    {
        $search = isset($data['search']) ? trim((string) $data['search']) : null;

        // 'all' (hoặc bỏ trống) nghĩa là không lọc
        $role   = ($data['role'] ?? 'all') === 'all' ? null : UserRole::tryFrom((string) $data['role']);
        $status = ($data['status'] ?? 'all') === 'all' ? null : UserStatus::tryFrom((string) $data['status']);

        return new self(
            $search === '' ? null : $search,
            $role,
            $status,
            PerPage::resolve($data['per_page'] ?? null),
        );
    }
}
