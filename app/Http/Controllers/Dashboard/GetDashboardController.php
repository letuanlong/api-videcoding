<?php

namespace App\Http\Controllers\Dashboard;

use App\Actions\Dashboard\GetDashboardStatsAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GetDashboardController extends Controller
{
    public function __invoke(Request $request, GetDashboardStatsAction $action): JsonResponse
    {
        $user  = $request->user();
        $stats = $action->execute($user);

        $data = [
            'me' => (new ProfileResource($user))->resolve() + ['last_login_at' => $user->last_login_at?->toIso8601String()],
        ];

        // USER thường chỉ có dashboard cá nhân, không có khóa stats
        if ($stats !== null) {
            $data['stats'] = $stats;
        }

        return ApiResponse::success($data);
    }
}
