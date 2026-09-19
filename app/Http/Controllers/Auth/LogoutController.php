<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Actions\Auth\LogoutAction;

class LogoutController extends Controller
{
    //
    public function __invoke(Request $request, LogoutAction $action): JsonResponse
    {
        $action->execute($request);

        return response()->json([
            'message' => 'Đăng xuất thành công!'
        ], 200);
    }
    
}
