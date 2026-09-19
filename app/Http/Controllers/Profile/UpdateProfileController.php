<?php

namespace App\Http\Controllers\Profile;

use App\Actions\Profile\UpdateProfileAction;
use App\DTOs\UpdateProfileData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\ProfileResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class UpdateProfileController extends Controller
{
    public function __invoke(UpdateProfileRequest $request, UpdateProfileAction $action): JsonResponse
    {
        $user = $action->execute(
            $request->user(),
            UpdateProfileData::fromArray($request->validated()),
        );

        return ApiResponse::success(new ProfileResource($user), 'Profile updated successfully.');
    }
}
