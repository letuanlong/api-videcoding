<?php

namespace App\Actions\Dashboard;

use App\Models\User;

class GetDashboardStatsAction
{
    /**
     * Thống kê user theo trạng thái trong phạm vi người xem được quản lý
     * (SUPERADMIN: ADMIN + USER, ADMIN: USER). USER thường không có thống kê nên trả null.
     *
     * @return array{total: int, active: int, inactive: int, blocked: int}|null
     */
    public function execute(User $actor): ?array
    {
        if ($actor->role->manageableRoles() === []) {
            return null;
        }

        // Một query GROUP BY duy nhất, không đếm từng trạng thái riêng lẻ
        $counts = User::visibleTo($actor)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'total'    => (int) $counts->sum(),
            'active'   => (int) ($counts['active'] ?? 0),
            'inactive' => (int) ($counts['inactive'] ?? 0),
            'blocked'  => (int) ($counts['blocked'] ?? 0),
        ];
    }
}
