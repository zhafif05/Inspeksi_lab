<?php

namespace App\Support;

/**
 * Bentuk error Express lama: { success:false, message, error:<objek mysql2> }.
 * Hanya dipakai untuk endpoint API saat APP_DEBUG aktif, sesuai NODE_ENV=development.
 */
class LegacyError
{
    private const MYSQL_CODES = [
        1044 => 'ER_DBACCESS_DENIED_ERROR',
        1045 => 'ER_ACCESS_DENIED_ERROR',
        1048 => 'ER_BAD_NULL_ERROR',
        1049 => 'ER_BAD_DB_ERROR',
        1050 => 'ER_TABLE_EXISTS_ERROR',
        1051 => 'ER_BAD_TABLE_ERROR',
        1054 => 'ER_BAD_FIELD_ERROR',
        1062 => 'ER_DUP_ENTRY',
        1064 => 'ER_PARSE_ERROR',
        1146 => 'ER_NO_SUCH_TABLE',
        1213 => 'ER_LOCK_DEADLOCK',
        1364 => 'ER_NO_DEFAULT_FOR_FIELD',
        1406 => 'ER_DATA_TOO_LONG',
        1451 => 'ER_ROW_IS_REFERENCED_2',
        1452 => 'ER_NO_REFERENCED_ROW_2',
    ];

    public static function payload(\Throwable $e): array
    {
        $payload = [
            'success' => false,
            'message' => $e->getMessage() ?: 'Internal Server Error',
        ];

        if (!config('app.debug')) {
            return $payload;
        }

        if (!$e instanceof \PDOException) {
            $payload['error'] = (object) [];
            return $payload;
        }

        $info = $e->errorInfo ?? [];
        $sqlState = (string) ($info[0] ?? $e->getCode());
        $errno = (int) ($info[1] ?? 0);
        $sqlMessage = (string) ($info[2] ?? $e->getMessage());

        $payload['message'] = $sqlMessage;
        $payload['error'] = [
            'code' => self::MYSQL_CODES[$errno] ?? $sqlState,
            'errno' => $errno,
            'sqlState' => $sqlState,
            'sqlMessage' => $sqlMessage,
        ];

        $sql = self::extractSql($e->getMessage());
        if ($sql !== null) {
            $payload['error']['sql'] = $sql;
        }

        return $payload;
    }

    private static function extractSql(string $message): ?string
    {
        if (preg_match('/SQL:\s*([\s\S]*)\)\s*$/', $message, $match) !== 1) {
            return null;
        }

        $sql = rtrim($match[1]);
        // mysql2 mencetak VALUES dengan 11 spasi pada query create ini.
        return preg_replace('/\r?\n\s+VALUES/', "\n           VALUES", $sql) ?? $sql;
    }
}
