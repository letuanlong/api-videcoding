<?php

namespace App\Actions\AuditLogs;

use App\DTOs\AuditLogFilters;
use App\Models\AuditLog;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListAuditLogsAction
{
    public function execute(AuditLogFilters $filters): LengthAwarePaginator
    {
        return AuditLog::query()
            // Người thực hiện có thể đã bị xóa mềm nhưng log vẫn phải hiển thị được tên
            ->with(['user' => fn ($query) => $query->withTrashed()->select('id', 'name', 'email')])
            ->when($filters->userId, fn (Builder $query, int $id) => $query->where('user_id', $id))
            ->when($filters->action, fn (Builder $query, $action) => $query->where('action', $action->value))
            ->when($filters->dateFrom, fn (Builder $query, $date) => $query->where('created_at', '>=', $date))
            ->when($filters->dateTo, fn (Builder $query, $date) => $query->where('created_at', '<=', $date))
            ->orderByDesc('id')
            ->paginate($filters->perPage);
    }
}
