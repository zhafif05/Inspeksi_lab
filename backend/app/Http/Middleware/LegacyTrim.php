<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * express-validator `.trim()` lama memutasi req.body hanya pada field
 * yang memang ditrim oleh validator rute terkait.
 */
class LegacyTrim
{
    public function handle(Request $request, Closure $next, string ...$fields)
    {
        $fields = array_values(array_filter($fields, static fn (string $field): bool => $field !== ''));
        if ($fields === []) {
            return $next($request);
        }

        $source = $request->isJson() ? $request->json() : $request->request;
        $original = $source->all();

        $request->merge($this->trimRecursive($original, $fields));

        return $next($request);
    }

    private function trimRecursive(mixed $value, array $fields, ?string $key = null): mixed
    {
        if (is_array($value)) {
            foreach ($value as $childKey => $childValue) {
                $value[$childKey] = $this->trimRecursive($childValue, $fields, (string) $childKey);
            }
            return $value;
        }

        if (is_string($value) && $key !== null && in_array($key, $fields, true)) {
            return trim($value);
        }

        return $value;
    }
}
