<?php
namespace App\Actions\Auth;

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\DTOs\LoginResult;
use App\Enums\AuditAction;
use App\Exceptions\AccountNotActiveException;
use App\Exceptions\InvalidCredentialsException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LoginAction
{
    private static ?string $dummyHash = null;

    public function __construct(private readonly RecordAuditLogAction $audit)
    {
    }

    /**
     * @throws InvalidCredentialsException sai email hoặc mật khẩu (401)
     * @throws AccountNotActiveException   đúng mật khẩu nhưng tài khoản inactive/blocked (403)
     */
    public function execute(string $email, #[\SensitiveParameter] string $password): LoginResult
    {
        // User đã xóa mềm bị loại khỏi truy vấn mặc định nên được coi như không tồn tại
        $user = User::where('email', $email)->first();

        // Luôn băm một lần kể cả khi email không tồn tại, để thời gian phản hồi không lộ email nào có trong hệ thống
        $passwordMatches = Hash::check($password, $user?->password ?? self::dummyHash());

        if (! $user || ! $passwordMatches) {
            throw new InvalidCredentialsException();
        }

        // Chỉ kiểm tra trạng thái SAU khi mật khẩu đúng: người không biết mật khẩu không dò được trạng thái tài khoản
        if (! $user->isActive()) {
            throw new AccountNotActiveException($user->status);
        }

        return DB::transaction(function () use ($user) {
            // role/status/last_login_at không mass-assignable nên dùng forceFill
            $user->forceFill(['last_login_at' => now()])->save();

            // Chỉ cho phép một thiết bị đăng nhập tại một thời điểm
            $user->tokens()->delete();

            $token = $user->createToken('auth_token')->plainTextToken;

            $this->audit->execute(AuditAction::Login, $user, $user);

            return new LoginResult($user, $token);
        });
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('mat-khau-gia-de-can-bang-thoi-gian');
    }
}
