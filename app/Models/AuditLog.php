<?php

namespace App\Models;

use App\Enums\AuditAction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    // Audit log chỉ ghi thêm, không bao giờ cập nhật
    const UPDATED_AT = null;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'target_type',
        'target_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'action'     => AuditAction::class,
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /**
     * Người thực hiện hành động.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
