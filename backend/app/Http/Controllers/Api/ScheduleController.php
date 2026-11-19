<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\LegacyValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ScheduleController extends Controller
{
    public function getAllSchedules(Request $request): JsonResponse
    {
        $schedules = DB::select(
            "SELECT s.*, l.nama_lab FROM schedules s 
             LEFT JOIN laboratories l ON s.laboratory_id = l.id 
             ORDER BY s.tanggal DESC"
        );

        return Api::ok(['data' => $schedules, 'total' => count($schedules)], 200);
    }

    public function getSchedulesByLab(Request $request, string $laboratoryId): JsonResponse
    {
        $schedules = DB::select(
            "SELECT s.*, l.nama_lab FROM schedules s 
             LEFT JOIN laboratories l ON s.laboratory_id = l.id 
             WHERE s.laboratory_id = ? ORDER BY s.tanggal DESC",
            [$laboratoryId]
        );

        return Api::ok(['data' => $schedules, 'total' => count($schedules)], 200);
    }

    public function createSchedule(Request $request): JsonResponse
    {
        $errors = LegacyValidator::schedule($request);
        if ($errors) {
            return Api::validation($errors);
        }

        $laboratoryId = $request->input('laboratory_id');
        $tanggal = $request->input('tanggal');
        $keterangan = trim((string) $request->input('keterangan')); // trim() dari route lama

        $lab = DB::selectOne('SELECT id FROM laboratories WHERE id = ?', [$laboratoryId]);
        if (!$lab) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }

        $id = (int) DB::table('schedules')->insertGetId([
            'laboratory_id' => $laboratoryId,
            'tanggal' => $tanggal,
            'keterangan' => $keterangan,
        ]);

        return Api::ok([
            'message' => 'Jadwal berhasil dibuat',
            'data' => [
                'id' => $id,
                'laboratory_id' => $laboratoryId,
                'tanggal' => $tanggal,
                'keterangan' => $keterangan,
            ],
        ], 201);
    }

    public function updateSchedule(Request $request, string $id): JsonResponse
    {
        // Route lama memakai validateSchedule juga untuk PUT (termasuk laboratory_id).
        $errors = LegacyValidator::schedule($request);
        if ($errors) {
            return Api::validation($errors);
        }

        $tanggal = $request->input('tanggal');
        $keterangan = trim((string) $request->input('keterangan'));

        $schedule = DB::selectOne('SELECT id FROM schedules WHERE id = ?', [$id]);
        if (!$schedule) {
            return Api::error('Jadwal tidak ditemukan', 404);
        }

        DB::statement(
            'UPDATE schedules SET tanggal = ?, keterangan = ? WHERE id = ?',
            [$tanggal, $keterangan, $id]
        );

        return Api::ok(['message' => 'Jadwal berhasil diperbarui'], 200);
    }

    public function deleteSchedule(Request $request, string $id): JsonResponse
    {
        $schedule = DB::selectOne('SELECT id FROM schedules WHERE id = ?', [$id]);
        if (!$schedule) {
            return Api::error('Jadwal tidak ditemukan', 404);
        }

        DB::statement('DELETE FROM schedules WHERE id = ?', [$id]);

        return Api::ok(['message' => 'Jadwal berhasil dihapus'], 200);
    }
}
