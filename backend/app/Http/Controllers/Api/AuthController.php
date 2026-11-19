<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use Firebase\JWT\JWT;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AuthController extends Controller
{
    /**
     * Validasi login (validateLogin) + rate limit sudah dijalankan
     * middleware legacy.login.gate, sama seperti route lama.
     */
    public function login(Request $request): JsonResponse
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $user = DB::selectOne(
            'SELECT id, name, nip, email, password, role FROM users WHERE email = ?',
            [$email]
        );

        if (!$user) {
            return Api::error('Email atau password salah', 401);
        }

        if (!password_verify((string) $password, (string) $user->password)) {
            return Api::error('Email atau password salah', 401);
        }

        // Sama seperti authController lama: kolom lab ditentukan dari role.
        [$laboratoryId, $labName] = Legacy::resolveUserLab((array) $user);

        $payload = [
            'id' => (int) $user->id,
            'name' => $user->name,
            'nip' => $user->nip,
            'email' => $user->email,
            'role' => $user->role,
            'laboratory_id' => $laboratoryId,
            'lab_name' => $labName,
            'iat' => time(),
            'exp' => time() + 86400,
        ];

        $token = JWT::encode($payload, env('JWT_SECRET'), 'HS256');

        return Api::ok([
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'id' => (int) $user->id,
                    'name' => $user->name,
                    'nip' => $user->nip,
                    'email' => $user->email,
                    'role' => $user->role,
                    'laboratory_id' => $laboratoryId,
                    'lab_name' => $labName,
                ],
                'token' => $token,
            ],
        ], 200);
    }

    public function getProfile(Request $request): JsonResponse
    {
        $userId = Legacy::user($request)['id'] ?? null;

        $user = DB::selectOne(
            'SELECT id, name, nip, email, role, created_at FROM users WHERE id = ?',
            [$userId]
        );

        if (!$user) {
            return Api::error('User tidak ditemukan', 404);
        }

        // Role diambil dari baris DB (bukan dari token), seperti source.
        [$laboratoryId, $labName] = Legacy::resolveUserLab((array) $user);

        $data = (array) $user;
        $data['laboratory_id'] = $laboratoryId;
        $data['lab_name'] = $labName;

        return Api::ok(['data' => $data], 200);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $userId = Legacy::user($request)['id'] ?? null;

        DB::statement(
            'UPDATE users SET name = ?, email = ? WHERE id = ?',
            [$request->input('name'), $request->input('email'), $userId]
        );

        $row = DB::selectOne(
            'SELECT id, name, email, nip, role FROM users WHERE id = ?',
            [$userId]
        );

        // JSON.stringify menghilangkan properti undefined pada Express.
        return $row ? Api::ok(['data' => (array) $row]) : Api::ok([]);
    }
}
