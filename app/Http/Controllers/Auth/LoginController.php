<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\LoginAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;

class LoginController extends Controller
{
    //
    public function __invoke(LoginRequest $request, LoginAction $action): JsonResponse
    {
        $token = $action->execute(
            $request->validated('email'),
            $request->validated('password')
        );

        return response()->json([
            'message'      => 'Đăng nhập thành công!',
            'access_token' => $token,
            'token_type'   => 'Bearer',
        ], 200);
    }

}
