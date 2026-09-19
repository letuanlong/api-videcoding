<?php

namespace App\Actions\AuditLogs;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class RecordAuditLogAction
{
    /**
     * Các khoá chứa từ này (không phân biệt hoa thường) sẽ bị loại khỏi old/new values.
     */
    private const SENSITIVE_KEY_FRAGMENTS = ['password', 'token', 'secret'];

    public function __construct(private readonly Request $request)
    {
    }

    /**
     * Ghi một dòng audit log. Nên gọi bên trong cùng DB::transaction với nghiệp vụ được ghi lại.
     *
     * @param array<string, mixed>|null $oldValues
     * @param array<string, mixed>|null $newValues
     */
    public function execute(
        AuditAction $action,
        ?User $actor,
        ?Model $target = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id'     => $actor?->getKey(),
            'action'      => $action,
            'target_type' => $target ? $target::class : null,
            'target_id'   => $target?->getKey(),
            'old_values'  => $this->sanitize($oldValues),
            'new_values'  => $this->sanitize($newValues),
            'ip_address'  => $this->request->ip(),
            'user_agent'  => $this->request->userAgent(),
        ]);
    }

    /**
     * Loại bỏ đệ quy mọi trường nhạy cảm (mật khẩu, token...). Trả về null nếu không còn gì để lưu.
     *
     * @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $clean = [];

        foreach ($values as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean === [] ? null : $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = strtolower($key);

        foreach (self::SENSITIVE_KEY_FRAGMENTS as $fragment) {
            if (str_contains($key, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
