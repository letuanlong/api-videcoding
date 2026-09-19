<?php
namespace App\Http\Controllers\Auth;

use App\Actions\Auth\ChangePasswordAction;
use App\Exceptions\InvalidCurrentPasswordException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ChangePasswordRequest;
use Illuminate\Http\JsonResponse;

class ChangePasswordController extends Controller
{
    public function __invoke(ChangePasswordRequest $request, ChangePasswordAction $action): JsonResponse
    {
        try {
            $action->execute($request->user(), $request->validated());

            return response()->json([
                'message' => 'Đổi mật khẩu thành công!'
            ], 200);

        } catch (InvalidCurrentPasswordException $e) {
            return response()->json([
                'message' => $e->getMessage()
            ], 400);
        }
    }
}
