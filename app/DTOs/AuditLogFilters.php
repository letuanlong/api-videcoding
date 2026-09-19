<?php

namespace App\DTOs;

use App\Enums\AuditAction;
use App\Support\PerPage;
use Carbon\CarbonImmutable;

readonly class AuditLogFilters
{
    public function __construct(
        public ?int $userId,
        public ?AuditAction $action,
        public ?CarbonImmutable $dateFrom,
        public ?CarbonImmutable $dateTo,
        public int $perPage,
    ) {
    }

    /**
     * @param array<string, mixed> $data dữ liệu đã qua validate
     */
    public static function fromArray(array $data): self
    {
        return new self(
            isset($data['user']) ? (int) $data['user'] : null,
            isset($data['action']) ? AuditAction::from($data['action']) : null,
            // Lọc theo cả ngày: từ 00:00:00 của date_from đến 23:59:59 của date_to
            isset($data['date_from']) ? CarbonImmutable::parse($data['date_from'])->startOfDay() : null,
            isset($data['date_to']) ? CarbonImmutable::parse($data['date_to'])->endOfDay() : null,
            PerPage::resolve($data['per_page'] ?? null),
        );
    }
}
