<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\AuthUserResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    public function __invoke(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $result = $action->execute(
            $request->validated('email'),
            $request->validated('password')
        );

        return ApiResponse::success([
            'user'  => (new AuthUserResource($result->user))->resolve(),
            'token' => $result->token,
        ], 'Login successful.');
    }
}
