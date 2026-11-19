<?php

namespace App\Http\Middleware;

use App\Support\Api;
use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;

class VerifyJwt
{
    public function handle(Request $request, Closure $next)
    {
        $auth = $request->header('Authorization');
        $token = $auth ? explode(' ', $auth)[1] ?? null : null;

        if (!$token) {
            return Api::error('Token tidak ditemukan', 401);
        }

        try {
            $decoded = JWT::decode($token, new Key(env('JWT_SECRET'), 'HS256'));
            $request->attributes->set('user', (array) $decoded);
        } catch (\Throwable $e) {
            return Api::error('Token tidak valid atau kadaluarsa', 403);
        }

        return $next($request);
    }
}
