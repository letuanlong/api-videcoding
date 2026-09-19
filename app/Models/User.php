<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\UserRole;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * Cố ý KHÔNG có 'role' và 'status': hai trường này chỉ được gán tường minh
     * (forceFill / thuộc tính) sau khi đã qua UserPolicy, tránh leo thang đặc quyền qua mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'avatar',
    ];

    /**
     * Giá trị mặc định cho model mới tạo trong bộ nhớ (khớp default của cột trong DB).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'role'   => 'user',
        'status' => 'active',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'role'              => UserRole::class,
            'status'            => UserStatus::class,
        ];
    }

    public function isActive(): bool
    {
        return $this->status === UserStatus::Active;
    }

    /**
     * URL công khai của ảnh đại diện. Dùng asset() (theo host của request hiện tại) thay vì Storage::url()
     * vì Storage::url() lấy APP_URL: khi APP_URL không khớp host/port thật thì ảnh bị hỏng, và trình duyệt
     * coi đó là khác origin nên CSP img-src 'self' chặn luôn.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar ? asset('storage/'.ltrim($this->avatar, '/')) : null;
    }

    /**
     * Chỉ lấy những user mà $actor được phép quản lý (áp dụng ở mức query, không lọc trong PHP).
     * Actor không có quyền quản lý role nào thì kết quả rỗng.
     */
    public function scopeVisibleTo(Builder $query, self $actor): Builder
    {
        return $query->whereIn('role', $actor->role->manageableValues());
    }
}
