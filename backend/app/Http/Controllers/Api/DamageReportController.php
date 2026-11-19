<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use App\Support\LegacyUpload;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class DamageReportController extends Controller
{
    public function saveDamageReport(Request $request)
    {
        if ($error = LegacyUpload::validateImages($request)) {
            return $error;
        }

        // Source menyimpan req.file.filename (nama file saja), bukan path /uploads/...
        $foto = null;
        $uploadedFile = $request->file('foto');
        if ($uploadedFile instanceof UploadedFile) {
            $foto = basename(Legacy::saveUpload($uploadedFile, 'foto'));
        }

        $inspectionResultId = $request->input('inspection_result_id');

        $exist = DB::select(
            'SELECT id, foto FROM inspection_damage_reports WHERE inspection_result_id = ?',
            [$inspectionResultId]
        );

        if (count($exist) > 0) {
            DB::statement(
                'UPDATE inspection_damage_reports
                 SET
                    kondisi_kerusakan = ?,
                    penyebab_kerusakan = ?,
                    status_peralatan = ?,
                    foto = COALESCE(?, foto),
                    updated_at = NOW()
                 WHERE inspection_result_id = ?',
                [
                    $request->input('kondisi_kerusakan'),
                    $request->input('penyebab_kerusakan'),
                    $request->input('status_peralatan'),
                    $foto,
                    $inspectionResultId,
                ]
            );

            return Api::ok(['message' => 'Damage report berhasil diupdate']);
        }

        DB::table('inspection_damage_reports')->insert([
            'inspection_result_id' => $inspectionResultId,
            'inspection_id' => $request->input('inspection_id'),
            'laboratory_id' => $request->input('laboratory_id'),
            'item_id' => $request->input('item_id'),
            'category_id' => $request->input('category_id'),
            'subitem_id' => $request->input('subitem_id'),
            'kondisi_kerusakan' => $request->input('kondisi_kerusakan'),
            'penyebab_kerusakan' => $request->input('penyebab_kerusakan'),
            'status_peralatan' => $request->input('status_peralatan'),
            'foto' => $foto,
        ]);

        return Api::ok(['message' => 'Damage report berhasil disimpan']);
    }

    public function getDamageReport(Request $request, $inspectionResultId)
    {
        $rows = DB::select(
            'SELECT * FROM inspection_damage_reports WHERE inspection_result_id = ? LIMIT 1',
            [$inspectionResultId]
        );

        return Api::ok(['data' => count($rows) > 0 ? $rows[0] : null]);
    }

    public function deleteDamageReport(Request $request, $inspectionResultId)
    {
        DB::table('inspection_damage_reports')
            ->where('inspection_result_id', $inspectionResultId)
            ->delete();

        return Api::ok(['message' => 'Damage report berhasil dihapus']);
    }
}
