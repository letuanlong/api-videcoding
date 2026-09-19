<?php

namespace App\Support;

/**
 * Quy tắc per_page dùng chung cho mọi API có phân trang (UMS-012).
 */
class PerPage
{
    public const OPTIONS = [10, 20, 50, 100];
    public const DEFAULT = 20;

    /**
     * Giá trị lạ (chữ, số âm, 0, 1000, mảng...) không gây lỗi mà quay về mặc định.
     */
    public static function resolve(mixed $value): int
    {
        $perPage = filter_var($value, FILTER_VALIDATE_INT);

        return in_array($perPage, self::OPTIONS, true) ? $perPage : self::DEFAULT;
    }
}
