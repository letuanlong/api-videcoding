<?php

namespace App\Http\Controllers\Admin\Users;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ShowUserController extends Controller
{
    public function __invoke(User $user): JsonResponse
    {
        Gate::authorize('view', $user);

        return ApiResponse::success(new UserResource($user));
    }
}
