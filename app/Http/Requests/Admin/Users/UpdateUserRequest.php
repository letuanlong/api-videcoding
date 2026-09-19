<?php

namespace App\Http\Requests\Admin\Users;

use App\Enums\UserRole;
use App\Enums\UserStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $actor  = $this->user();
        $target = $this->route('user');

        if (! $actor->can('update', $target)) {
            return false;
        }

        // Chống leo thang đặc quyền: kể cả khi role không đổi, role gửi lên vẫn phải nằm trong phạm vi được gán
        $role = is_string($this->input('role')) ? UserRole::tryFrom($this->input('role')) : null;

        if ($role && ! $actor->can('assignRole', [$target, $role])) {
            return false;
        }

        return ! $this->has('status') || $actor->can('changeStatus', $target);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name'   => ['required', 'string', 'max:255'],
            'email'  => ['sometimes', 'required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'phone'  => ['sometimes', 'nullable', 'string', 'max:20'],
            'role'   => ['sometimes', 'required', Rule::enum(UserRole::class)],
            'status' => ['sometimes', 'required', Rule::enum(UserStatus::class)],
        ];
    }
}
