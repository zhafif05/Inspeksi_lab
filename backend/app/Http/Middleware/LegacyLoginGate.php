<?php

namespace App\Http\Middleware;

use App\Support\Api;
use App\Support\LegacyValidator;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class LegacyLoginGate
{
    private const MAX_ATTEMPTS = 10;
    private const DECAY_SECONDS = 15 * 60;

    public function handle(Request $request, Closure $next)
    {
        $errors = LegacyValidator::login($request);
        if ($errors) {
            return Api::validation($errors);
        }

        $key = 'legacy-login:' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            return $this->withRateLimitHeaders(
                Api::error('Terlalu banyak percobaan login. Coba lagi dalam 15 menit.', 429),
                $key,
                0
            );
        }

        $hits = RateLimiter::hit($key, self::DECAY_SECONDS);

        return $this->withRateLimitHeaders(
            $next($request),
            $key,
            max(0, self::MAX_ATTEMPTS - $hits)
        );
    }

    private function withRateLimitHeaders($response, string $key, int $remaining)
    {
        $resetAt = (int) Cache::get($key . ':timer', time() + self::DECAY_SECONDS);
        $response->headers->set('RateLimit-Limit', (string) self::MAX_ATTEMPTS);
        $response->headers->set('RateLimit-Policy', self::MAX_ATTEMPTS . ';w=' . self::DECAY_SECONDS);
        $response->headers->set('RateLimit-Remaining', (string) $remaining);
        $response->headers->set('RateLimit-Reset', (string) max(0, $resetAt - time()));

        return $response;
    }
}
