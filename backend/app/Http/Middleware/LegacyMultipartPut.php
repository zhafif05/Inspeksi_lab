<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * PHP tidak mengisi $_FILES untuk request multipart dengan method PUT.
 * Express/multer lama melakukannya, jadi parser ini meniru perilaku tersebut
 * agar endpoint PUT /inspections/{id}/results tetap menerima foto.
 */
class LegacyMultipartPut
{
    /** @var array<int,string> */
    private static array $temporaryFiles = [];

    public function handle(Request $request, Closure $next)
    {
        if (!in_array(strtoupper($request->getMethod()), ['PUT', 'PATCH'], true)
            || !$request->is('api/*')
            || !str_starts_with(strtolower($request->header('Content-Type', '')), 'multipart/form-data')) {
            return $next($request);
        }

        $contentType = $request->header('Content-Type', '');
        if (!preg_match('/boundary=(?:"([^"]+)"|([^;]+))/i', $contentType, $match)) {
            return $next($request);
        }
        $boundary = $match[1] !== '' ? $match[1] : trim($match[2]);
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $next($request);
        }

        foreach (explode('--' . $boundary, $raw) as $part) {
            $part = ltrim($part, "\r\n");
            if ($part === '' || $part === '--' || $part === "--\r\n") {
                continue;
            }

            $separator = strpos($part, "\r\n\r\n");
            if ($separator === false) {
                continue;
            }

            $headerText = substr($part, 0, $separator);
            $body = substr($part, $separator + 4);
            $body = preg_replace('/\r\n$/', '', $body) ?? $body;

            $name = $this->headerParameter($headerText, 'name');
            if ($name === null || $name === '') {
                continue;
            }

            $filename = $this->headerParameter($headerText, 'filename');
            if ($filename !== null) {
                $mime = $this->headerValue($headerText, 'Content-Type') ?: 'application/octet-stream';
                $path = tempnam(sys_get_temp_dir(), 'legacy-put-');
                if ($path === false) {
                    continue;
                }
                file_put_contents($path, $body);
                self::$temporaryFiles[] = $path;
                $request->files->set($name, new UploadedFile($path, $filename, $mime, UPLOAD_ERR_OK, true));
                continue;
            }

            $request->merge([$name => $body]);
        }

        return $next($request);
    }

    private function headerParameter(string $headerText, string $parameter): ?string
    {
        if (preg_match('/' . preg_quote($parameter, '/') . '="([^"]*)"/i', $headerText, $match)) {
            return $match[1];
        }
        return null;
    }

    private function headerValue(string $headerText, string $header): ?string
    {
        foreach (explode("\r\n", $headerText) as $line) {
            if (stripos($line, $header . ':') === 0) {
                return trim(substr($line, strlen($header) + 1));
            }
        }
        return null;
    }

    public static function cleanup(): void
    {
        foreach (self::$temporaryFiles as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }
}

register_shutdown_function([LegacyMultipartPut::class, 'cleanup']);
