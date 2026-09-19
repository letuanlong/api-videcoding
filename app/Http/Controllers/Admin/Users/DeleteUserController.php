<?php

namespace App\Http\Controllers\Admin\Users;

use App\Actions\Users\DeleteUserAction;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class DeleteUserController extends Controller
{
    public function __invoke(Request $request, User $user, DeleteUserAction $action): JsonResponse
    {
        Gate::authorize('delete', $user);

        $action->execute($request->user(), $user);

        return ApiResponse::success(message: 'User deleted successfully.');
    }
}
