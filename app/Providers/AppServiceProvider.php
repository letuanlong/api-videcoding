<?php

namespace App\Providers;

use App\Enums\UserRole;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(User::class, UserPolicy::class);

        // Chỉ SUPERADMIN đang active mới xem được audit log
        Gate::define('viewAuditLogs', fn (User $user) => $user->isActive() && $user->role === UserRole::SuperAdmin);

        // Chính sách mật khẩu dùng chung cho mọi chỗ đặt/đổi mật khẩu mới. 72 là giới hạn của bcrypt.
        Password::defaults(fn () => Password::min(8)->max(72)->mixedCase()->numbers()->symbols());
    }
}
