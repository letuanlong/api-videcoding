<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\ChangeUserStatusAction;
use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ChangeUserStatusRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ChangeUserStatusController extends Controller
{
    public function __invoke(ChangeUserStatusRequest $request, User $user, ChangeUserStatusAction $action): JsonResponse
    {
        $user = $action->execute(
            $request->user(),
            $user,
            UserStatus::from($request->validated('status')),
        );

        return ApiResponse::success(new UserResource($user), 'User status updated successfully.');
    }
}
