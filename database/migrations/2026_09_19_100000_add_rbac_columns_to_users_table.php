<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * role/status dùng string thay vì ENUM của DB: dễ thêm giá trị mới và chạy được cả SQLite lẫn MySQL.
     * User đã có sẵn sẽ tự nhận role=user, status=active nhờ giá trị mặc định.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable()->after('email');
            $table->string('role', 20)->default('user')->after('password');
            $table->string('status', 20)->default('active')->after('role');
            $table->string('avatar')->nullable()->after('status');
            $table->timestamp('last_login_at')->nullable()->after('avatar');
            $table->softDeletes();

            $table->index('role');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Gỡ index trước khi drop cột để chạy được trên SQLite
            $table->dropIndex(['role']);
            $table->dropIndex(['status']);

            $table->dropSoftDeletes();
            $table->dropColumn(['phone', 'role', 'status', 'avatar', 'last_login_at']);
        });
    }
};
