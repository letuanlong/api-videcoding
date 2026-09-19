<?php

namespace App\Support;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Định dạng response chuẩn của module User Management:
 * thành công {success: true, message?, data, meta?}; lỗi {success: false, message, errors?}
 */
class ApiResponse
{
    public static function success(mixed $data = null, ?string $message = null, int $status = 200): JsonResponse
    {
        $body = ['success' => true];

        if ($message !== null) {
            $body['message'] = $message;
        }

        if ($data instanceof JsonResource) {
            $data = $data->resolve();
        }

        if ($data !== null) {
            $body['data'] = $data;
        }

        return response()->json($body, $status);
    }

    /**
     * @param class-string<JsonResource> $resourceClass
     */
    public static function paginated(LengthAwarePaginator $paginator, string $resourceClass): JsonResponse
    {
        $items = collect($paginator->items())
            ->map(fn ($item) => (new $resourceClass($item))->resolve())
            ->all();

        return response()->json([
            'success' => true,
            'data'    => $items,
            'meta'    => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * @param array<string, mixed>|null $errors
     * @param array<string, string> $headers
     */
    public static function error(string $message, int $status, ?array $errors = null, array $headers = []): JsonResponse
    {
        $body = ['success' => false, 'message' => $message];

        if ($errors !== null) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $status, $headers);
    }
}
