<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class UserController extends Controller
{
    /** Mirror kebenaran nilai JS (dipakai untuk `x || y` pada source). */
    private static function jsTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    private static function jsOr(mixed $value, mixed $fallback): mixed
    {
        return self::jsTruthy($value) ? $value : $fallback;
    }

    public function getAllUsers(Request $request): JsonResponse
    {
        $users = DB::select(
            'SELECT u.id, u.name, u.nip, u.email, u.role, u.created_at FROM users u ORDER BY u.created_at DESC'
        );

        return Api::ok(['data' => $users, 'total' => count($users)], 200);
    }

    public function createUser(Request $request): JsonResponse
    {
        $name = $request->input('name');
        $nip = $request->input('nip');
        $email = $request->input('email');
        $password = $request->input('password');
        $role = $request->input('role');

        $existingUser = DB::selectOne('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existingUser) {
            return Api::error('Email sudah terdaftar', 400);
        }

        $allowedRoles = ['admin', 'kalab', 'plp', 'teknisi'];
        if (!in_array($role, $allowedRoles, true)) {
            return Api::error('Role tidak valid', 400);
        }

        $hashedPassword = password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => 10]);

        $resolvedNip = self::jsOr($nip, '');
        $resolvedRole = self::jsOr($role, 'kalab');

        $id = (int) DB::table('users')->insertGetId([
            'name' => $name,
            'nip' => $resolvedNip,
            'email' => $email,
            'password' => $hashedPassword,
            'role' => $resolvedRole,
        ]);

        return Api::ok([
            'message' => 'User berhasil dibuat',
            'data' => [
                'id' => $id,
                'name' => $name,
                'nip' => $resolvedNip,
                'email' => $email,
                'role' => $resolvedRole,
            ],
        ], 201);
    }

    public function updateUser(Request $request, string $id): JsonResponse
    {
        $name = $request->input('name');
        $nip = $request->input('nip');
        $email = $request->input('email');
        $role = $request->input('role');
        $password = $request->input('password');

        $user = DB::selectOne('SELECT id FROM users WHERE id = ?', [$id]);
        if (!$user) {
            return Api::error('User tidak ditemukan', 404);
        }

        $resolvedNip = self::jsOr($nip, '');
        $hashedPassword = null;

        if (self::jsTruthy($password) && trim((string) $password) !== '') {
            $hashedPassword = password_hash((string) $password, PASSWORD_BCRYPT, ['cost' => 10]);
        }

        if ($hashedPassword !== null) {
            DB::statement(
                'UPDATE users SET name = ?, nip = ?, email = ?, role = ?, password = ? WHERE id = ?',
                [$name, $resolvedNip, $email, $role, $hashedPassword, $id]
            );
        } else {
            DB::statement(
                'UPDATE users SET name = ?, nip = ?, email = ?, role = ? WHERE id = ?',
                [$name, $resolvedNip, $email, $role, $id]
            );
        }

        return Api::ok(['message' => 'User berhasil diperbarui'], 200);
    }

    public function deleteUser(Request $request, string $id): JsonResponse
    {
        $user = DB::selectOne('SELECT id FROM users WHERE id = ?', [$id]);
        if (!$user) {
            return Api::error('User tidak ditemukan', 404);
        }

        DB::statement('DELETE FROM users WHERE id = ?', [$id]);

        return Api::ok(['message' => 'User berhasil dihapus'], 200);
    }
}
