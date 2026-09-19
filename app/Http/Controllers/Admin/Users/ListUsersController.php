<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\ListUsersAction;
use App\DTOs\UserListFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Users\ListUsersRequest;
use App\Http\Resources\UserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ListUsersController extends Controller
{
    public function __invoke(ListUsersRequest $request, ListUsersAction $action): JsonResponse
    {
        $users = $action->execute(
            $request->user(),
            UserListFilters::fromArray($request->validated()),
        );

        return ApiResponse::paginated($users, UserResource::class);
    }
}
