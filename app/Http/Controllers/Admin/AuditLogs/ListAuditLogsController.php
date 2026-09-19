<?php

namespace App\Http\Controllers\Admin\AuditLogs;

use App\Actions\AuditLogs\ListAuditLogsAction;
use App\DTOs\AuditLogFilters;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditLogs\ListAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ListAuditLogsController extends Controller
{
    public function __invoke(ListAuditLogsRequest $request, ListAuditLogsAction $action): JsonResponse
    {
        $logs = $action->execute(AuditLogFilters::fromArray($request->validated()));

        return ApiResponse::paginated($logs, AuditLogResource::class);
    }
}
