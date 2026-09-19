<?php

namespace App\Exceptions;

use App\Enums\UserStatus;
use App\Support\ApiResponse;
use Exception;
use Illuminate\Http\JsonResponse;

class AccountNotActiveException extends Exception
{
    public function __construct(public readonly UserStatus $status)
    {
        parent::__construct(match ($status) {
            UserStatus::Blocked => 'Your account has been blocked.',
            default             => 'Your account is inactive.',
        });
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), 403);
    }
}
