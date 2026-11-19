<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class Legacy
{
    public static function user(Request $request): array
    {
        return $request->attributes->get('user') ?? [];
    }

    /** Kolom laboratorium berdasarkan role, sama seperti authController lama. */
    public static function labColumn(?string $role): ?string
    {
        return match ($role) {
            'kalab' => 'kalab_id',
            'plp' => 'plp_id',
            'teknisi' => 'teknisi_id',
            default => null,
        };
    }

    public static function resolveUserLab(array $user): array
    {
        $laboratoryId = null;
        $labName = null;
        $column = self::labColumn($user['role'] ?? null);
        if ($column) {
            $lab = DB::table('laboratories')->select('id', 'nama_lab')->where($column, $user['id'] ?? null)->first();
            if ($lab) {
                $laboratoryId = $lab->id;
                $labName = $lab->nama_lab;
            }
        }
        return [$laboratoryId, $labName];
    }

    /** Aturan semester lama: Februari-Juli = GENAP, selain itu GANJIL (Januari mundur tahun). */
    public static function yearSemester(?string $tanggal = null): array
    {
        $tanggal = $tanggal ?: date('Y-m-d H:i:s');
        $bulan = (int) date('m', strtotime($tanggal));
        $tahun = (int) date('Y', strtotime($tanggal));
        if ($bulan === 1) {
            $tahun -= 1;
        }
        $semester = ($bulan >= 2 && $bulan <= 7) ? 'GENAP' : 'GANJIL';
        return [(string) $tahun, $semester];
    }

    public static function uploadPath(string $path): string
    {
        return public_path('uploads/' . ltrim($path, '/'));
    }

    public static function saveUpload($file, string $prefix): ?string
    {
        if (!$file) {
            return null;
        }
        $extension = $file->getClientOriginalExtension();
        $name = $prefix . '-' . round(microtime(true) * 1000) . '-' . random_int(100, 999) . '.' . $extension;
        $file->move(public_path('uploads'), $name);
        return '/uploads/' . $name;
    }

    /** Array.isArray() dari JS: object dengan key numerik bukan array. */
    public static function isJsArray(Request $request, string $key): bool
    {
        if ($request->isJson()) {
            $decoded = json_decode($request->getContent());
            return is_object($decoded)
                && property_exists($decoded, $key)
                && is_array($decoded->{$key});
        }

        return is_array($request->request->get($key));
    }

    /** Kebenaran nilai JS untuk `||` (falsy: null, false, 0, '', NaN). */
    public static function jsTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    public static function jsOr(mixed $value, mixed $fallback): mixed
    {
        return self::jsTruthy($value) ? $value : $fallback;
    }

    /** Membaca req.body saja (tidak menggabungkan query string seperti $request->input()). */
    public static function body(Request $request, ?string $key = null): mixed
    {
        $body = $request->isJson() ? $request->json()->all() : $request->request->all();
        if ($key === null) {
            return $body;
        }
        return $body[$key] ?? null;
    }

    public static function parseJsonField(Request $request, string $field): mixed
    {
        $value = $request->input($field);
        if ($value === null || $value === '') {
            return null;
        }
        if (is_array($value)) {
            return $value;
        }
        return json_decode((string) $value, true);
    }

    public static function csvIds(?string $csv): array
    {
        if (!$csv) {
            return [];
        }
        return array_values(array_filter(array_map('intval', explode(',', $csv))));
    }
}
