<?php

use App\Actions\AuditLogs\RecordAuditLogAction;
use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function auditRecorder(string $ip = '203.0.113.7', string $agent = 'PestAgent/1.0'): RecordAuditLogAction
{
    $request = Request::create('/api/admin/users', 'POST', [], [], [], [
        'REMOTE_ADDR'     => $ip,
        'HTTP_USER_AGENT' => $agent,
    ]);

    return new RecordAuditLogAction($request);
}

test('ghi đủ người thực hiện, đối tượng, giá trị cũ/mới, IP và user agent', function () {
    $actor  = User::factory()->admin()->create();
    $target = User::factory()->create();

    $log = auditRecorder()->execute(
        AuditAction::UpdateUser,
        $actor,
        $target,
        ['name' => 'Cũ'],
        ['name' => 'Mới'],
    );

    $log = $log->fresh();

    expect($log->action)->toBe(AuditAction::UpdateUser)
        ->and($log->user_id)->toBe($actor->id)
        ->and($log->target_type)->toBe(User::class)
        ->and($log->target_id)->toBe($target->id)
        ->and($log->old_values)->toBe(['name' => 'Cũ'])
        ->and($log->new_values)->toBe(['name' => 'Mới'])
        ->and($log->ip_address)->toBe('203.0.113.7')
        ->and($log->user_agent)->toBe('PestAgent/1.0')
        ->and($log->created_at)->not->toBeNull()
        ->and($log->user->is($actor))->toBeTrue();
});

test('không bao giờ lưu mật khẩu hay token vào old/new values, kể cả khi lồng nhau', function () {
    $secret = 'SuperSecret@123';

    $log = auditRecorder()->execute(
        AuditAction::CreateUser,
        User::factory()->create(),
        null,
        null,
        [
            'name'                  => 'Nguyen Van A',
            'password'              => $secret,
            'password_confirmation' => $secret,
            'current_password'      => $secret,
            'new_password'          => $secret,
            'Access_Token'          => $secret,
            'profile'               => ['phone' => '0900000000', 'remember_token' => $secret],
        ],
    );

    $rawJson = DB::table('audit_logs')->where('id', $log->id)->value('new_values');

    expect($rawJson)->not->toContain($secret)
        ->and($log->fresh()->new_values)->toBe([
            'name'    => 'Nguyen Van A',
            'profile' => ['phone' => '0900000000'],
        ]);
});

test('nếu sau khi lọc không còn gì thì lưu null', function () {
    $log = auditRecorder()->execute(
        AuditAction::ResetPassword,
        User::factory()->create(),
        User::factory()->create(),
        null,
        ['password' => 'x', 'password_confirmation' => 'x'],
    );

    expect($log->fresh()->new_values)->toBeNull()
        ->and($log->fresh()->old_values)->toBeNull();
});

test('có thể ghi log không có người thực hiện và không có đối tượng', function () {
    $log = auditRecorder()->execute(AuditAction::Login, null)->fresh();

    expect($log->user_id)->toBeNull()
        ->and($log->target_type)->toBeNull()
        ->and($log->target_id)->toBeNull()
        ->and($log->user)->toBeNull();
});

test('audit log không có cột updated_at vì chỉ ghi thêm', function () {
    expect(Schema::hasColumn('audit_logs', 'updated_at'))->toBeFalse()
        ->and(Schema::hasColumns('audit_logs', [
            'id', 'user_id', 'action', 'target_type', 'target_id',
            'old_values', 'new_values', 'ip_address', 'user_agent', 'created_at',
        ]))->toBeTrue();
});

test('xóa cứng người thực hiện thì log vẫn còn và user_id về null', function () {
    $actor = User::factory()->create();

    $log = auditRecorder()->execute(AuditAction::Login, $actor);

    $actor->forceDelete();

    expect(AuditLog::count())->toBe(1)
        ->and($log->fresh()->user_id)->toBeNull();
});

test('xóa mềm người thực hiện thì log vẫn giữ nguyên user_id', function () {
    $actor = User::factory()->create();

    $log = auditRecorder()->execute(AuditAction::Login, $actor);

    $actor->delete();

    expect($log->fresh()->user_id)->toBe($actor->id);
});
