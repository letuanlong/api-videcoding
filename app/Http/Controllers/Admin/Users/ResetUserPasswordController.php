<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\ResetUserPasswordAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ResetUserPasswordRequest;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ResetUserPasswordController extends Controller
{
    public function __invoke(ResetUserPasswordRequest $request, User $user, ResetUserPasswordAction $action): JsonResponse
    {
        $action->execute($request->user(), $user, $request->validated('password'));

        return ApiResponse::success(message: 'Password reset successfully.');
    }
}
