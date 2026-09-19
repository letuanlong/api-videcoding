<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\UpdateUserAction;
use App\DTOs\UpdateUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\UpdateUserRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateUserController extends Controller
{
    public function __invoke(UpdateUserRequest $request, User $user, UpdateUserAction $action): JsonResponse
    {
        $user = $action->execute(
            $request->user(),
            $user,
            UpdateUserData::fromArray($request->validated()),
        );

        return ApiResponse::success(new UserResource($user), 'User updated successfully.');
    }
}
