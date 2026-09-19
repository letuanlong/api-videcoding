<?php

namespace App\Actions\Users;

use App\DTOs\UserListFilters;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class ListUsersAction
{
    public function execute(User $actor, UserListFilters $filters): LengthAwarePaginator
    {
        return User::query()
            ->whereIn('role', $this->visibleRoleValues($actor, $filters))
            ->when($filters->status, fn (Builder $query, $status) => $query->where('status', $status->value))
            ->when($filters->search, fn (Builder $query, string $search) => $this->applySearch($query, $search))
            // Sắp xếp thêm theo id để thứ tự ổn định giữa các trang
            ->orderByDesc('id')
            ->paginate($filters->perPage);
    }

    /**
     * Phạm vi role được trả về, quyết định ở mức query:
     * - không lọc role: các role người xem quản lý được (ADMIN chỉ thấy USER, SUPERADMIN thấy ADMIN + USER);
     * - lọc role: chỉ khi role đó nằm trong phạm vi được xem, ngược lại kết quả rỗng (không lộ dữ liệu, không báo lỗi).
     *
     * @return list<string>
     */
    private function visibleRoleValues(User $actor, UserListFilters $filters): array
    {
        if ($filters->role === null) {
            return $actor->role->manageableValues();
        }

        return $actor->role->canView($filters->role) ? [$filters->role->value] : [];
    }

    /**
     * Tìm ở mức database (name, email, phone). Điều kiện OR bắt buộc nằm trong một nhóm để không phá
     * các điều kiện role/status đứng trước.
     */
    private function applySearch(Builder $query, string $search): Builder
    {
        // Escape ký tự đặc biệt của LIKE bằng '!' (SQLite không có ký tự escape mặc định)
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%';

        return $query->where(function (Builder $group) use ($like) {
            $group->whereRaw("name like ? escape '!'", [$like])
                ->orWhereRaw("email like ? escape '!'", [$like])
                ->orWhereRaw("phone like ? escape '!'", [$like]);
        });
    }
}
