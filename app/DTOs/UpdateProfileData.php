<?php

namespace App\DTOs;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

readonly class UpdateProfileData
{
    /**
     * @param array<string, mixed> $attributes chỉ name/phone thực sự được gửi lên
     */
    public function __construct(
        public array $attributes,
        public ?UploadedFile $avatar,
        public bool $removeAvatar,
    ) {
    }

    /**
     * Whitelist cứng: chỉ name, phone, avatar. role, status, email... gửi lên đều bị bỏ qua.
     *
     * @param array<string, mixed> $data dữ liệu đã qua validate
     */
    public static function fromArray(array $data): self
    {
        $avatar = $data['avatar'] ?? null;

        return new self(
            Arr::only($data, ['name', 'phone']),
            $avatar instanceof UploadedFile ? $avatar : null,
            // avatar được gửi rõ ràng là null (JSON) nghĩa là xóa ảnh đại diện
            array_key_exists('avatar', $data) && $avatar === null,
        );
    }
}
