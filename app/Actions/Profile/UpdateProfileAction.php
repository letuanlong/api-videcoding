<?php

namespace App\Actions\Profile;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\DTOs\UpdateProfileData;
use App\Enums\AuditAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class UpdateProfileAction
{
    private const AVATAR_DISK = 'public';

    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    public function execute(User $user, UpdateProfileData $data): User
    {
        $oldAvatar = $user->avatar;

        // store() đặt tên file ngẫu nhiên, không dùng tên do client gửi
        $newAvatar = $data->avatar?->store('avatars', self::AVATAR_DISK) ?: null;

        try {
            $changes = DB::transaction(function () use ($user, $data, $newAvatar) {
                $user->fill($data->attributes);

                if ($newAvatar !== null) {
                    $user->avatar = $newAvatar;
                } elseif ($data->removeAvatar) {
                    $user->avatar = null;
                }

                $changes = $user->getDirty();

                if ($changes === []) {
                    return [];
                }

                $original = array_intersect_key($user->getRawOriginal(), $changes);

                $user->save();

                $this->audit->execute(AuditAction::UpdateProfile, $user, $user, $original, $changes);

                return $changes;
            });
        } catch (Throwable $e) {
            // Lỗi DB thì không để lại file mồ côi
            if ($newAvatar !== null) {
                Storage::disk(self::AVATAR_DISK)->delete($newAvatar);
            }

            throw $e;
        }

        // Chỉ xóa ảnh cũ sau khi transaction đã commit thành công
        if (array_key_exists('avatar', $changes) && $oldAvatar) {
            Storage::disk(self::AVATAR_DISK)->delete($oldAvatar);
        }

        return $user;
    }
}
