<?php

namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Illuminate\Http\Request;

class AuthorizeRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $user = $request->attributes->get('user');

        if (!$user) {
            return Api::error('Token tidak ditemukan', 401);
        }

        if (!in_array($user['role'] ?? null, $roles, true)) {
            return Api::error('Anda tidak memiliki akses ke resource ini', 403);
        }

        return $next($request);
    }
}
