<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;

class Api
{
    public static function ok(array $data = [], int $status = 200): JsonResponse
    {
        return response()->json(self::normalizeLegacyTypes(array_merge(['success' => true], $data)), $status);
    }

    /**
     * mysql2 mengembalikan kolom YEAR sebagai number, sementara PDO mengembalikan string.
     * Samakan tipe tersebut agar kontrak JSON tidak berubah setelah migrasi.
     */
    private static function normalizeLegacyTypes(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = self::normalizeLegacyTypes($item);
                if ($key === 'tahun' && is_string($value[$key]) && is_numeric($value[$key])) {
                    $value[$key] = (int) $value[$key];
                }
            }
            return $value;
        }

        if (is_object($value)) {
            foreach (get_object_vars($value) as $key => $item) {
                $value->{$key} = self::normalizeLegacyTypes($item);
                if ($key === 'tahun' && is_string($value->{$key}) && is_numeric($value->{$key})) {
                    $value->{$key} = (int) $value->{$key};
                }
            }
            return $value;
        }

        return $value;
    }

    public static function error(string $message, int $status = 400, array $extra = []): JsonResponse
    {
        return response()->json(array_merge([
            'success' => false,
            'message' => $message,
        ], $extra), $status);
    }

    public static function validation(array $errors): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Validasi input gagal',
            'errors' => $errors,
        ], 400);
    }
}
