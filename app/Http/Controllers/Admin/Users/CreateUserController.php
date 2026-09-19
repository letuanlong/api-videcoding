<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\CreateUserAction;
use App\DTOs\CreateUserData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\CreateUserRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class CreateUserController extends Controller
{
    public function __invoke(CreateUserRequest $request, CreateUserAction $action): JsonResponse
    {
        $user = $action->execute(
            $request->user(),
            CreateUserData::fromArray($request->validated()),
        );

        return ApiResponse::success(new UserResource($user), 'User created successfully.', 201);
    }
}
