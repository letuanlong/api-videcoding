<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * Dùng: ->middleware('role:superadmin,admin'). Không đúng role thì trả 403.
     * Đây chỉ là chốt chặn thô ở tầng route; quyền chi tiết trên từng bản ghi do UserPolicy quyết định.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            throw new AuthenticationException();
        }

        if (! in_array($user->role?->value, $roles, true)) {
            abort(403);
        }

        return $next($request);
    }
}
