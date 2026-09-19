<?php

namespace App\Http\Requests\Admin\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', User::class);
    }

    /**
     * per_page và page cố ý không bị validate chặt: giá trị lạ được xử lý an toàn (về mặc định) thay vì trả 422.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search'   => ['nullable', 'string', 'max:100'],
            'role'     => ['nullable', Rule::in(['all', ...UserRole::values()])],
            'status'   => ['nullable', Rule::in(['all', ...UserStatus::values()])],
            'per_page' => ['nullable'],
            'page'     => ['nullable'],
        ];
    }
}
