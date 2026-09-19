<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProfileResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShowProfileController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return ApiResponse::success(new ProfileResource($request->user()));
    }
}
