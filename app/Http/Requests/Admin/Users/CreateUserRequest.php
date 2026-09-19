<?php

namespace App\Http\Requests\Admin\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class CreateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $role  = is_string($this->input('role')) ? UserRole::tryFrom($this->input('role')) : null;

        // Role thiếu/không hợp lệ thì để validation trả 422; người không quản lý được ai vẫn nhận 403 trước
        return $role
            ? $actor->can('create', [User::class, $role])
            : $actor->can('viewAny', User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            // unique tính cả tài khoản đã xóa mềm để trả 422 gọn thay vì lỗi 500 từ unique index
            'email'    => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone'    => ['nullable', 'string', 'max:20'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
            'role'     => ['required', Rule::enum(UserRole::class)],
            'status'   => ['required', Rule::enum(UserStatus::class)],
        ];
    }
}
