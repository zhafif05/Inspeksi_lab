<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use App\Support\LegacyUpload;
use App\Support\LegacyValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Port controller inspeksi dari Backend_inspeksi/src/controllers/inspectionDetailController.js.
 * Logika, pesan, status code dan bentuk respons sengaja dipertahankan apa adanya.
 */
class InspectionController extends Controller
{
    private const ALLOWED_STATUSES = ['B', 'K', 'N/A'];

    // ==================== HELPERS ====================

    /** Kebenaran nilai JS untuk `||` (falsy: null, false, 0, '', NaN). */
    private static function jsTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    private static function jsOr(mixed $value, mixed $fallback): mixed
    {
        return self::jsTruthy($value) ? $value : $fallback;
    }

    /** new Date(input) lalu mysql2 timezone +07:00 menulis jam lokal Asia/Jakarta. */
    private static function legacyDateString(mixed $input): string
    {
        $appTimezone = new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        if (!self::jsTruthy($input)) {
            return (new \DateTimeImmutable('now', $appTimezone))->format('Y-m-d H:i:s');
        }

        $value = (string) $input;
        // JS mengartikan 'YYYY-MM-DD' sebagai UTC midnight, bukan lokal.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $value .= ' 00:00:00';
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
        } else {
            $date = new \DateTimeImmutable($value, $appTimezone);
        }

        return $date->setTimezone($appTimezone)->format('Y-m-d H:i:s');
    }

    /** Date object lama diserialisasi express sebagai ISO UTC dengan milidetik. */
    private static function legacyDateIso(mixed $input): ?string
    {
        $appTimezone = new \DateTimeZone(config('app.timezone', 'Asia/Jakarta'));
        $utc = new \DateTimeZone('UTC');

        try {
            if (!self::jsTruthy($input)) {
                $date = new \DateTimeImmutable('now', $utc);
            } else {
                $value = (string) $input;
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
                    $date = new \DateTimeImmutable($value . ' 00:00:00', $utc);
                } else {
                    $date = new \DateTimeImmutable($value, $appTimezone);
                }
            }
        } catch (\Throwable $e) {
            return null;
        }

        return $date->setTimezone($utc)->format('Y-m-d\TH:i:s.v\Z');
    }

    /** Number(value) dari JS; null mewakili NaN. */
    private static function jsNumber(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        if (is_bool($value)) {
            return $value ? 1.0 : 0.0;
        }
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return 0.0;
        }
        if (preg_match('/^[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?$/', $value) === 1) {
            return (float) $value;
        }
        if (preg_match('/^0[xX][0-9a-fA-F]+$/', $value) === 1) {
            return (float) hexdec(substr($value, 2));
        }

        return null;
    }

    /** status?.trim()?.toUpperCase(); null bila kosong/falsy. */
    private static function normalizeStatus(mixed $status): ?string
    {
        if ($status === null || $status === false) {
            return null;
        }
        $normalized = strtoupper(trim((string) $status));
        return $normalized === '' ? null : $normalized;
    }

    private static function flattenFiles(array $files): array
    {
        $flat = [];
        foreach ($files as $value) {
            if ($value instanceof UploadedFile) {
                $flat[] = $value;
            } elseif (is_array($value)) {
                $flat = array_merge($flat, self::flattenFiles($value));
            }
        }
        return $flat;
    }

    /** getUploadedFile(req, fieldname) dari source. */
    private function getUploadedFile(Request $request, string $fieldname): ?UploadedFile
    {
        $files = $request->allFiles();
        if (!array_key_exists($fieldname, $files)) {
            return null;
        }
        $value = $files[$fieldname];
        if (is_array($value)) {
            $first = reset($value);
            return $first instanceof UploadedFile ? $first : null;
        }
        return $value instanceof UploadedFile ? $value : null;
    }

    /** deleteUploadedFiles(req) dari source: hapus semua file yang sudah di-save. */
    private function deleteUploadedFiles(Request $request): void
    {
        foreach (self::flattenFiles($request->allFiles()) as $file) {
            $path = $file->getRealPath() ?: $file->getPathname();
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * parse JSON dari form-data. Mendeteksi JSON invalid seperti JSON.parse,
     * lalu tetap memakai Legacy::parseJsonField sesuai konvensi project.
     */
    private static function parseField(Request $request, string $field, bool &$invalid): mixed
    {
        $raw = $request->input($field);
        $invalid = false;
        if (is_string($raw)) {
            json_decode($raw, true);
            $invalid = json_last_error() !== JSON_ERROR_NONE;
        }
        return Legacy::parseJsonField($request, $field);
    }

    /** Normalisasi damageReports: object dipertahankan, array/other -> {}. */
    private static function normalizeDamageReports(mixed $reports): array
    {
        if (!$reports || !is_array($reports) || array_is_list($reports)) {
            return [];
        }
        return $reports;
    }

    /** Placeholder untuk `id IN (?)` (mysql2 meng-expand array, DB::select tidak). */
    private static function inPlaceholders(int $count): string
    {
        return implode(',', array_fill(0, $count, '?'));
    }

    // ==================== CREATE ====================

    public function createInspectionWithChecklist(Request $request): JsonResponse
    {
        if ($error = LegacyUpload::validateImages($request)) {
            return $error;
        }

        $itemId = $request->input('item_id');
        $catatan = $request->input('catatan');
        $user = Legacy::user($request);
        $userId = $user['id'] ?? null;
        $role = $user['role'] ?? null;

        // Photo diambil lebih awal, seperti multer yang menyimpan file sebelum controller.
        $inspectionPhoto = $this->getUploadedFile($request, 'foto');
        $foto = $inspectionPhoto ? Legacy::saveUpload($inspectionPhoto, 'foto') : null;

        if ($role === 'admin') {
            if (!self::jsTruthy($request->input('laboratory_id'))) {
                $this->deleteUploadedFiles($request);
                return Api::error('Admin harus mengisi laboratory_id', 400);
            }
            $laboratoryId = $request->input('laboratory_id');
        } else {
            $laboratoryId = self::jsOr($request->input('laboratory_id'), $user['laboratory_id'] ?? null);
            if (!self::jsTruthy($laboratoryId)) {
                $this->deleteUploadedFiles($request);
                return Api::error('Anda tidak terdaftar di laboratorium manapun', 400);
            }

            // QUIRK dipertahankan: OR tanpa kurung.
            // WHERE id = ? AND kalab_id = ? OR plp_id = ? OR teknisi_id = ?
            $labCheck = DB::select(
                'SELECT id FROM laboratories WHERE id = ? AND kalab_id = ? OR plp_id = ? OR teknisi_id = ?',
                [$laboratoryId, $userId, $userId, $userId]
            );
            if (count($labCheck) === 0) {
                $this->deleteUploadedFiles($request);
                return Api::error('Anda tidak memiliki akses ke laboratorium ini', 403);
            }
        }

        // checklist_results || results
        $checklistField = self::jsTruthy($request->input('checklist_results')) ? 'checklist_results' : 'results';
        $invalidChecklist = false;
        $checklistResults = self::parseField($request, $checklistField, $invalidChecklist);
        if ($invalidChecklist) {
            $this->deleteUploadedFiles($request);
            return Api::error('Format checklist_results JSON tidak valid', 400);
        }

        $invalidDamage = false;
        $damageReports = self::parseField($request, 'damageReports', $invalidDamage);
        if ($invalidDamage) {
            $this->deleteUploadedFiles($request);
            return Api::error('Format damageReports JSON tidak valid', 400);
        }
        $damageReports = self::normalizeDamageReports($damageReports);

        $nonApproved = DB::select(
            "SELECT COUNT(*) as count FROM inspection_categories
             WHERE item_id = ? AND status IN ('PENDING', 'REJECTED')",
            [$itemId]
        );
        if ((int) ($nonApproved[0]->count ?? 0) > 0) {
            $this->deleteUploadedFiles($request);
            return Api::error(
                'Tidak dapat membuat inspeksi karena masih ada kategori yang PENDING atau REJECTED. Harap tunggu persetujuan admin.',
                400
            );
        }

        if (!self::jsTruthy($itemId)) {
            $this->deleteUploadedFiles($request);
            return Api::error('Field item_id harus diisi', 400);
        }

        // Tahun/semester: default mengikuti Legacy::yearSemester, override eksplisit
        // diperlakukan persis seperti source.
        $tanggalInput = $request->input('tanggal_inspeksi');
        $tanggalInspeksi = self::legacyDateString($tanggalInput);
        $tanggalRespons = self::legacyDateIso($tanggalInput);
        $timestamp = strtotime($tanggalInspeksi) ?: time();
        [$legacyYear, $legacySemester] = Legacy::yearSemester($tanggalInspeksi);
        $bulan = (int) date('m', $timestamp);
        $tahunInput = $request->input('tahun');
        $semesterInput = $request->input('semester');
        $semester = self::jsTruthy($semesterInput) ? (string) $semesterInput : $legacySemester;
        $rawYear = (int) $legacyYear + ($bulan === 1 ? 1 : 0);
        $yearNumber = self::jsTruthy($tahunInput) ? self::jsNumber($tahunInput) : null;
        $inspectionYear = $yearNumber !== null ? (int) $yearNumber : ($tahunInput ? NAN : $rawYear);
        if (!self::jsTruthy($tahunInput) && !self::jsTruthy($semesterInput) && $bulan === 1) {
            $semester = 'GANJIL';
            $inspectionYear = (int) $legacyYear;
        }

        // mysql2 menuliskan NaN apa adanya ke SQL tanpa tanda kutip, sehingga MySQL
        // menganggapnya sebagai nama kolom. PDO tidak melakukan hal yang sama.
        if (is_nan((float) $inspectionYear)) {
            $message = "Unknown column 'NaN' in 'where clause'";
            $sql = "SELECT id FROM inspections WHERE item_id = '" . addslashes((string) $itemId)
                . "' AND tahun = NaN AND semester = '" . addslashes((string) $semester) . "'";
            return Api::error($message, 500, [
                'error' => [
                    'code' => 'ER_BAD_FIELD_ERROR',
                    'errno' => 1054,
                    'sqlState' => '42S22',
                    'sqlMessage' => $message,
                    'sql' => $sql,
                ],
            ]);
        }

        $existing = DB::select(
            'SELECT id FROM inspections WHERE item_id = ? AND tahun = ? AND semester = ?',
            [$itemId, $inspectionYear, $semester]
        );
        if (count($existing) > 0) {
            $this->deleteUploadedFiles($request);
            return Api::error('Inspeksi untuk item ini sudah ada. Gunakan endpoint update untuk mengubah hasil.', 400);
        }

        $items = DB::select('SELECT id FROM items WHERE id = ?', [$itemId]);
        if (count($items) === 0) {
            $this->deleteUploadedFiles($request);
            return Api::error('Barang tidak ditemukan', 404);
        }

        $labs = DB::select(
            'SELECT id FROM laboratories WHERE id = ? AND FIND_IN_SET(?, item_ids)',
            [$laboratoryId, $itemId]
        );
        if (count($labs) === 0) {
            $this->deleteUploadedFiles($request);
            return Api::error('Barang bukan milik laboratorium Anda', 403);
        }

        try {
            $created = DB::transaction(function () use (
                $request,
                $itemId,
                $laboratoryId,
                $userId,
                $catatan,
                $foto,
                $tanggalInspeksi,
                $inspectionYear,
                $semester,
                $checklistResults,
                $damageReports
            ) {
                $inspectionId = (int) DB::table('inspections')->insertGetId([
                    'laboratory_id' => $laboratoryId,
                    'item_id' => $itemId,
                    'tahun' => $inspectionYear,
                    'semester' => $semester,
                    'inspector_id' => $userId,
                    'tanggal_inspeksi' => $tanggalInspeksi,
                    'catatan' => $catatan,
                    'foto' => $foto,
                ]);

                $hasChecklist = is_array($checklistResults)
                    && array_is_list($checklistResults)
                    && count($checklistResults) > 0;

                if ($hasChecklist) {
                    // Set bulan_ke menjaga urutan kemunculan pertama (seperti JS Set).
                    $monthsSet = [];
                    foreach ($checklistResults as $result) {
                        $result = is_array($result) ? $result : [];
                        $monthsSet[self::jsOr($result['bulan_ke'] ?? null, 1)] = true;
                    }
                    foreach (array_keys($monthsSet) as $bln) {
                        DB::statement(
                            "INSERT INTO inspection_monthly_reviews (inspection_id, bulan_ke, review_status)
                             VALUES (?, ?, 'PENDING')
                             ON DUPLICATE KEY UPDATE review_status = 'PENDING'",
                            [$inspectionId, $bln]
                        );
                    }
                }

                // Checklist kosong: auto-populate results bulan 1 dari subitem APPROVED.
                if (!$hasChecklist) {
                    $approvedSubitems = DB::select(
                        "SELECT si.id, si.nama_subitem FROM inspection_subitems si
                         JOIN inspection_categories c ON si.category_id = c.id
                         WHERE (c.laboratory_id = ? OR c.laboratory_id IS NULL OR c.created_by = ?)
                           AND si.status = 'APPROVED' AND c.status = 'APPROVED'
                         ORDER BY c.urutan ASC, si.urutan ASC",
                        [$laboratoryId, $userId]
                    );

                    $totalRows = 0;
                    foreach ($approvedSubitems as $sub) {
                        // Sama persis dengan source: NULL eksplisit. Pada MySQL/MariaDB
                        // strict mode ini menolak insert seperti aplikasi lama.
                        DB::statement(
                            'INSERT INTO inspection_results (inspection_id, subitem_id, bulan_ke, status, keterangan)
                             VALUES (?, ?, 1, NULL, NULL)',
                            [$inspectionId, $sub->id]
                        );
                        $totalRows++;
                    }
                    DB::statement(
                        "INSERT INTO inspection_monthly_reviews (inspection_id, bulan_ke, review_status)
                         VALUES (?, 1, 'PENDING')",
                        [$inspectionId]
                    );

                    return [
                        'mode' => 'auto',
                        'inspection_id' => $inspectionId,
                        'total_rows' => $totalRows,
                        'total_subitems' => count($approvedSubitems),
                    ];
                }

                foreach ($checklistResults as $result) {
                    $result = is_array($result) ? $result : [];
                    $subitemId = $result['subitem_id'] ?? null;
                    $normalizedStatus = self::normalizeStatus($result['status'] ?? null);
                    $bln = self::jsOr($result['bulan_ke'] ?? null, 1);

                    if (!self::jsTruthy($subitemId) || !$normalizedStatus) {
                        continue;
                    }
                    if (!in_array($normalizedStatus, self::ALLOWED_STATUSES, true)) {
                        continue;
                    }

                    $resultId = (int) DB::table('inspection_results')->insertGetId([
                        'inspection_id' => $inspectionId,
                        'subitem_id' => $subitemId,
                        'bulan_ke' => $bln,
                        'status' => $normalizedStatus,
                        'keterangan' => self::jsOr($result['keterangan'] ?? null, null),
                    ]);

                    $damageReport = self::jsOr(
                        $damageReports[$subitemId] ?? null,
                        $damageReports[(string) $subitemId] ?? null
                    );
                    if ($normalizedStatus === 'K' && self::jsTruthy($damageReport)) {
                        $damagePhoto = $this->getUploadedFile($request, 'damage_report_foto_' . $subitemId);
                        $subitem = DB::selectOne(
                            'SELECT category_id FROM inspection_subitems WHERE id = ? LIMIT 1',
                            [$subitemId]
                        );
                        if (!$subitem) {
                            throw new RuntimeException("Subitem {$subitemId} tidak ditemukan");
                        }
                        $damageReport = is_array($damageReport) ? $damageReport : [];
                        DB::table('inspection_damage_reports')->insert([
                            'inspection_result_id' => $resultId,
                            'inspection_id' => $inspectionId,
                            'laboratory_id' => $laboratoryId,
                            'item_id' => $itemId,
                            'category_id' => $subitem->category_id,
                            'subitem_id' => $subitemId,
                            'kondisi_kerusakan' => self::jsOr($damageReport['kondisi_kerusakan'] ?? null, null),
                            'penyebab_kerusakan' => self::jsOr($damageReport['penyebab_kerusakan'] ?? null, null),
                            'status_peralatan' => self::jsOr($damageReport['status_peralatan'] ?? null, null),
                            'foto' => $damagePhoto
                                ? Legacy::saveUpload($damagePhoto, 'damage_report_foto_' . $subitemId)
                                : self::jsOr($damageReport['foto'] ?? null, null),
                        ]);
                    }
                }

                // Overall dihitung dari seluruh checklist_results mentah (termasuk yang di-skip).
                $allNA = true;
                $anyK = false;
                foreach ($checklistResults as $result) {
                    $status = self::normalizeStatus(is_array($result) ? ($result['status'] ?? null) : null);
                    if (!($status === 'N/A' || !$status)) {
                        $allNA = false;
                    }
                    if ($status === 'K') {
                        $anyK = true;
                    }
                }
                $overallStatus = $allNA ? 'N/A' : ($anyK ? 'K' : 'B');

                return [
                    'mode' => 'checklist',
                    'inspection_id' => $inspectionId,
                    'overall_status' => $overallStatus,
                    'results_count' => count($checklistResults),
                ];
            });
        } catch (Throwable $e) {
            $this->deleteUploadedFiles($request);
            throw $e;
        }

        if ($created['mode'] === 'auto') {
            return Api::ok([
                'message' => "Inspeksi berhasil dibuat, {$created['total_rows']} hasil siap diisi untuk 6 bulan",
                'data' => [
                    'id' => $created['inspection_id'],
                    'laboratory_id' => $laboratoryId,
                    'item_id' => $itemId,
                    'inspector_id' => $userId,
                    'catatan' => $catatan,
                    'foto' => $foto,
                    'tanggal_inspeksi' => $tanggalRespons,
                    'total_subitems' => $created['total_subitems'],
                    'total_results' => $created['total_rows'],
                ],
            ], 201);
        }

        return Api::ok([
            'message' => 'Inspeksi berhasil dibuat',
            'data' => [
                'id' => $created['inspection_id'],
                'laboratory_id' => $laboratoryId,
                'item_id' => $itemId,
                'inspector_id' => $userId,
                'overall_status' => $created['overall_status'],
                'catatan' => $catatan,
                'foto' => $foto,
                'tanggal_inspeksi' => $tanggalRespons,
                'results_count' => $created['results_count'],
            ],
        ], 201);
    }

    // ==================== DETAIL / LIST / UPDATE ====================

    public function getInspectionDetail(Request $request, string $id): JsonResponse
    {
        $inspection = DB::selectOne(
            "SELECT i.*, u.name as inspector_name, l.nama_lab, it.nama_barang, it.kode_barang
             FROM inspections i
             LEFT JOIN users u ON i.inspector_id = u.id
             LEFT JOIN laboratories l ON i.laboratory_id = l.id
             LEFT JOIN items it ON i.item_id = it.id
             WHERE i.id = ?",
            [$id]
        );
        if (!$inspection) {
            return Api::error('Inspeksi tidak ditemukan', 404);
        }

        $results = DB::select(
            "SELECT
                ir.id,
                ir.status,
                ir.keterangan,
                ir.bulan_ke,
                ir.approval_status,
                ir.alasan_penolakan,
                is2.id as subitem_id,
                is2.nama_subitem,
                is2.deskripsi as subitem_deskripsi,
                ic.id as category_id,
                ic.nama_kategori,
                ic.urutan as category_urutan,
                is2.urutan as subitem_urutan
             FROM inspection_results ir
             JOIN inspection_subitems is2 ON ir.subitem_id = is2.id
             JOIN inspection_categories ic ON is2.category_id = ic.id
             WHERE ir.inspection_id = ?
             ORDER BY ir.bulan_ke ASC, ic.urutan ASC, is2.urutan ASC",
            [$id]
        );

        $reviews = DB::select(
            "SELECT imr.bulan_ke, imr.review_status, imr.reviewed_by, imr.alasan_penolakan, imr.reviewed_at,
                    u.name as reviewer_name
             FROM inspection_monthly_reviews imr
             LEFT JOIN users u ON imr.reviewed_by = u.id
             WHERE imr.inspection_id = ?",
            [$id]
        );

        $reviewMap = [];
        foreach ($reviews as $review) {
            $reviewMap[$review->bulan_ke] = [
                'review_status' => $review->review_status,
                'reviewed_by' => $review->reviewed_by,
                'reviewer_name' => $review->reviewer_name,
                'alasan_penolakan' => $review->alasan_penolakan,
                'reviewed_at' => $review->reviewed_at,
            ];
        }

        // Grouping: bulan -> kategori -> item (urutan kunci mengikuti source).
        $bulanGrouped = [];
        foreach ($results as $row) {
            $bln = $row->bulan_ke;
            if (!isset($bulanGrouped[$bln])) {
                $bulanGrouped[$bln] = ['bulan_ke' => $bln, 'categories' => []];
            }
            if (!isset($bulanGrouped[$bln]['categories'][$row->category_id])) {
                $bulanGrouped[$bln]['categories'][$row->category_id] = [
                    'category_id' => $row->category_id,
                    'nama_kategori' => $row->nama_kategori,
                    'urutan' => $row->category_urutan,
                    'items' => [],
                ];
            }
            $bulanGrouped[$bln]['categories'][$row->category_id]['items'][] = [
                'id' => $row->id,
                'subitem_id' => $row->subitem_id,
                'nama_subitem' => $row->nama_subitem,
                'deskripsi' => $row->subitem_deskripsi,
                'status' => $row->status,
                'keterangan' => $row->keterangan,
                'approval_status' => $row->approval_status,
                'alasan_penolakan' => $row->alasan_penolakan,
                'urutan' => $row->subitem_urutan,
            ];
        }

        $months = array_keys($bulanGrouped);
        sort($months, SORT_STRING);
        $monthlyResults = [];
        foreach ($months as $bln) {
            $catList = array_values($bulanGrouped[$bln]['categories']);
            usort($catList, static fn (array $a, array $b): int => $a['urutan'] <=> $b['urutan']);

            $allItems = [];
            foreach ($catList as $category) {
                $allItems = array_merge($allItems, $category['items']);
            }
            $totalItems = count($allItems);
            $baikCount = 0;
            $kurangCount = 0;
            $naCount = 0;
            $approvedCount = 0;
            $rejectedCount = 0;
            $pendingApprovalCount = 0;
            foreach ($allItems as $item) {
                if ($item['status'] === 'B') {
                    $baikCount++;
                }
                if ($item['status'] === 'K') {
                    $kurangCount++;
                }
                if ($item['status'] === 'N/A') {
                    $naCount++;
                }
                if ($item['approval_status'] === 'APPROVED') {
                    $approvedCount++;
                }
                if ($item['approval_status'] === 'REJECTED') {
                    $rejectedCount++;
                }
                if ($item['approval_status'] === 'PENDING') {
                    $pendingApprovalCount++;
                }
            }

            $monthlyResults[] = [
                'bulan_ke' => (int) $bln,
                'categories' => $catList,
                'statistics' => [
                    'total_items' => $totalItems,
                    'baik' => $baikCount,
                    'kurang' => $kurangCount,
                    'na' => $naCount,
                    'overall_status' => $kurangCount > 0 ? 'K' : ($baikCount > 0 ? 'B' : '-'),
                    'approval' => [
                        'approved' => $approvedCount,
                        'rejected' => $rejectedCount,
                        'pending' => $pendingApprovalCount,
                    ],
                ],
                'review' => $reviewMap[$bln] ?? [
                    'review_status' => 'PENDING',
                    'reviewed_by' => null,
                    'reviewer_name' => null,
                    'alasan_penolakan' => null,
                    'reviewed_at' => null,
                ],
            ];
        }

        return Api::ok([
            'data' => [
                'inspection' => (array) $inspection,
                'monthly_results' => $monthlyResults,
            ],
        ], 200);
    }

    public function getInspectionsByMonth(Request $request, string $bulan_ke): JsonResponse
    {
        $inspections = DB::select(
            "SELECT i.id, i.laboratory_id, i.item_id, i.tanggal_inspeksi,
                    u.name as inspector_name, l.nama_lab, it.nama_barang,
                    COUNT(ir.id) as total_checks,
                    SUM(CASE WHEN ir.status = 'B' THEN 1 ELSE 0 END) as baik_count,
                    SUM(CASE WHEN ir.status = 'K' THEN 1 ELSE 0 END) as kurang_count
             FROM inspections i
             LEFT JOIN users u ON i.inspector_id = u.id
             LEFT JOIN laboratories l ON i.laboratory_id = l.id
             LEFT JOIN items it ON i.item_id = it.id
             LEFT JOIN inspection_results ir ON i.id = ir.inspection_id AND ir.bulan_ke = ?
             GROUP BY i.id
             ORDER BY i.tanggal_inspeksi DESC",
            [$bulan_ke]
        );

        return Api::ok(['data' => $inspections, 'total' => count($inspections)], 200);
    }

    public function updateInspectionResult(Request $request, string $resultId): JsonResponse
    {
        $status = self::normalizeStatus($request->input('status'));
        if (!$status || !in_array($status, self::ALLOWED_STATUSES, true)) {
            return Api::error('Status harus B, K, atau N/A', 400);
        }

        $result = DB::selectOne(
            'SELECT id, approval_status, inspection_id, bulan_ke, alasan_penolakan FROM inspection_results WHERE id = ?',
            [$resultId]
        );
        if (!$result) {
            return Api::error('Hasil inspeksi tidak ditemukan', 404);
        }

        $existingReview = DB::selectOne(
            'SELECT review_status FROM inspection_monthly_reviews WHERE inspection_id = ? AND bulan_ke = ?',
            [$result->inspection_id, $result->bulan_ke]
        );
        if ($existingReview && $existingReview->review_status === 'APPROVED') {
            return Api::error(
                "Hasil inspeksi bulan ke-{$result->bulan_ke} sudah disetujui. Tidak dapat mengubah hasil yang sudah disetujui.",
                400
            );
        }

        $wasRejected = $result->approval_status === 'REJECTED';
        DB::statement(
            'UPDATE inspection_results SET status = ?, keterangan = ?, approval_status = ?, alasan_penolakan = ? WHERE id = ?',
            [
                $status,
                self::jsOr($request->input('keterangan'), null),
                $wasRejected ? 'PENDING' : $result->approval_status,
                $wasRejected ? null : $result->alasan_penolakan,
                $resultId,
            ]
        );
        DB::statement(
            "UPDATE inspection_monthly_reviews
             SET review_status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL, alasan_penolakan = NULL
             WHERE inspection_id = ? AND bulan_ke = ? AND review_status = 'REJECTED'",
            [$result->inspection_id, $result->bulan_ke]
        );

        return Api::ok([
            'message' => 'Hasil inspeksi berhasil diperbarui',
            'resubmitted' => $wasRejected,
        ], 200);
    }

    public function updateInspectionResults(Request $request, string $id): JsonResponse
    {
        if ($error = LegacyUpload::validateImages($request)) {
            return $error;
        }

        $invalidResults = false;
        $results = self::parseField($request, 'results', $invalidResults);
        if ($invalidResults) {
            $this->deleteUploadedFiles($request);
            return Api::error('Format results JSON tidak valid', 400);
        }

        $invalidDamage = false;
        $damageReports = self::parseField($request, 'damageReports', $invalidDamage);
        if ($invalidDamage) {
            $this->deleteUploadedFiles($request);
            return Api::error('Format damageReports JSON tidak valid', 400);
        }
        $damageReports = self::normalizeDamageReports($damageReports);

        // Number(bulan_ke): non-numeric -> NaN -> falsy -> error.
        $bulanKe = $request->input('bulan_ke');
        $month = is_numeric($bulanKe) ? $bulanKe + 0 : 0;
        if (!$month || $month < 1 || $month > 6) {
            $this->deleteUploadedFiles($request);
            return Api::error('Field bulan_ke (1-6) harus diisi', 400);
        }

        if (!is_array($results) || !array_is_list($results) || count($results) === 0) {
            $this->deleteUploadedFiles($request);
            return Api::error('Daftar hasil inspeksi harus diisi', 400);
        }

        $inspection = DB::selectOne('SELECT id, laboratory_id, item_id FROM inspections WHERE id = ?', [$id]);
        if (!$inspection) {
            $this->deleteUploadedFiles($request);
            return Api::error('Inspeksi tidak ditemukan', 404);
        }

        $existingReview = DB::selectOne(
            'SELECT review_status FROM inspection_monthly_reviews WHERE inspection_id = ? AND bulan_ke = ?',
            [$id, $month]
        );
        if ($existingReview && $existingReview->review_status === 'APPROVED') {
            $this->deleteUploadedFiles($request);
            return Api::error(
                "Hasil inspeksi bulan ke-{$month} sudah disetujui. Tidak dapat mengubah hasil yang sudah disetujui.",
                400
            );
        }

        if ($month > 1) {
            $prevReview = DB::selectOne(
                'SELECT review_status FROM inspection_monthly_reviews WHERE inspection_id = ? AND bulan_ke = ?',
                [$id, $month - 1]
            );
            if (!$prevReview || $prevReview->review_status !== 'APPROVED') {
                $this->deleteUploadedFiles($request);
                return Api::error(
                    "Bulan ke-" . ($month - 1) . " belum disetujui. Selesaikan dan tunggu persetujuan admin terlebih dahulu.",
                    400
                );
            }
        }

        try {
            $summary = DB::transaction(function () use (
                $request,
                $id,
                $inspection,
                $month,
                $results,
                $damageReports
            ) {
                foreach ($results as $row) {
                    $row = is_array($row) ? $row : [];
                    $status = self::normalizeStatus($row['status'] ?? null);
                    if (!$status || !in_array($status, self::ALLOWED_STATUSES, true)) {
                        continue;
                    }

                    if (self::jsTruthy($row['id'] ?? null)) {
                        DB::statement(
                            'UPDATE inspection_results SET status = ?, keterangan = ?, approval_status = ? WHERE id = ? AND inspection_id = ? AND bulan_ke = ?',
                            [
                                $status,
                                self::jsOr($row['keterangan'] ?? null, null),
                                'PENDING',
                                $row['id'],
                                $id,
                                $month,
                            ]
                        );
                    } elseif (self::jsTruthy($row['subitem_id'] ?? null)) {
                        DB::statement(
                            "INSERT INTO inspection_results (inspection_id, subitem_id, bulan_ke, status, keterangan, approval_status)
                             VALUES (?, ?, ?, ?, ?, 'PENDING')
                             ON DUPLICATE KEY UPDATE status = VALUES(status), keterangan = VALUES(keterangan), approval_status = 'PENDING'",
                            [$id, $row['subitem_id'], $month, $status, self::jsOr($row['keterangan'] ?? null, null)]
                        );
                    } else {
                        continue;
                    }

                    $by = self::jsTruthy($row['id'] ?? null) ? 'ir.id = ?' : 'ir.subitem_id = ?';
                    $inspectionResult = DB::selectOne(
                        "SELECT ir.id, ir.inspection_id, ir.subitem_id, si.category_id
                         FROM inspection_results ir
                         JOIN inspection_subitems si ON ir.subitem_id = si.id
                         WHERE ir.inspection_id = ? AND ir.bulan_ke = ? AND {$by}
                         LIMIT 1",
                        [$id, $month, self::jsOr($row['id'] ?? null, $row['subitem_id'] ?? null)]
                    );
                    if (!$inspectionResult) {
                        continue;
                    }

                    $subitemId = $inspectionResult->subitem_id;
                    $damageReport = self::jsOr(
                        $damageReports[$subitemId] ?? null,
                        $damageReports[(string) $subitemId] ?? null
                    );
                    if ($status === 'K' && self::jsTruthy($damageReport)) {
                        $damageReport = is_array($damageReport) ? $damageReport : [];
                        $damagePhoto = $this->getUploadedFile($request, 'damage_report_foto_' . $subitemId);
                        $damageFoto = $damagePhoto
                            ? Legacy::saveUpload($damagePhoto, 'damage_report_foto_' . $subitemId)
                            : self::jsOr($damageReport['foto'] ?? null, null);

                        $existingDamage = DB::selectOne(
                            'SELECT id FROM inspection_damage_reports WHERE inspection_result_id = ? LIMIT 1',
                            [$inspectionResult->id]
                        );
                        if ($existingDamage) {
                            DB::statement(
                                "UPDATE inspection_damage_reports
                                 SET
                                   inspection_id = ?,
                                   laboratory_id = ?,
                                   item_id = ?,
                                   category_id = ?,
                                   subitem_id = ?,
                                   kondisi_kerusakan = ?,
                                   penyebab_kerusakan = ?,
                                   status_peralatan = ?,
                                   foto = COALESCE(?, foto),
                                   updated_at = NOW()
                                 WHERE inspection_result_id = ?",
                                [
                                    $id,
                                    $inspection->laboratory_id,
                                    $inspection->item_id,
                                    $inspectionResult->category_id,
                                    $subitemId,
                                    self::jsOr($damageReport['kondisi_kerusakan'] ?? null, null),
                                    self::jsOr($damageReport['penyebab_kerusakan'] ?? null, null),
                                    self::jsOr($damageReport['status_peralatan'] ?? null, null),
                                    $damageFoto,
                                    $inspectionResult->id,
                                ]
                            );
                        } else {
                            DB::statement(
                                "INSERT INTO inspection_damage_reports
                                 (inspection_result_id, inspection_id, laboratory_id, item_id, category_id,
                                  subitem_id, kondisi_kerusakan, penyebab_kerusakan, status_peralatan, foto)
                                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                                [
                                    $inspectionResult->id,
                                    $id,
                                    $inspection->laboratory_id,
                                    $inspection->item_id,
                                    $inspectionResult->category_id,
                                    $subitemId,
                                    self::jsOr($damageReport['kondisi_kerusakan'] ?? null, null),
                                    self::jsOr($damageReport['penyebab_kerusakan'] ?? null, null),
                                    self::jsOr($damageReport['status_peralatan'] ?? null, null),
                                    $damageFoto,
                                ]
                            );
                        }
                    }
                }

                DB::statement(
                    "INSERT INTO inspection_monthly_reviews (inspection_id, bulan_ke, review_status, reviewed_by, reviewed_at)
                     VALUES (?, ?, 'PENDING', NULL, NULL)
                     ON DUPLICATE KEY UPDATE review_status = 'PENDING', reviewed_by = NULL, reviewed_at = NULL, alasan_penolakan = NULL",
                    [$id, $month]
                );

                $updatedResults = DB::select(
                    'SELECT status FROM inspection_results WHERE inspection_id = ? AND bulan_ke = ?',
                    [$id, $month]
                );
                $total = count($updatedResults);
                $anyK = false;
                $allNA = $total > 0;
                foreach ($updatedResults as $updated) {
                    if ($updated->status === 'K') {
                        $anyK = true;
                    }
                    if ($updated->status !== 'N/A') {
                        $allNA = false;
                    }
                }
                $overallStatus = $allNA ? 'N/A' : ($anyK ? 'K' : 'B');

                return $overallStatus;
            });
        } catch (Throwable $e) {
            $this->deleteUploadedFiles($request);
            throw $e;
        }

        return Api::ok([
            'message' => "Hasil inspeksi bulan ke-{$month} berhasil diperbarui dan menunggu review admin",
            'data' => [
                'bulan_ke' => $month,
                'total_updated' => count($results),
                'overall_status' => $summary,
                'review_status' => 'PENDING',
            ],
        ], 200);
    }

    // ==================== DELETE / REVIEW WORKFLOW ====================

    public function deleteInspection(Request $request, string $id): JsonResponse
    {
        $inspection = DB::selectOne('SELECT foto FROM inspections WHERE id = ?', [$id]);
        if (!$inspection) {
            return Api::error('Inspeksi tidak ditemukan', 404);
        }

        if ($inspection->foto) {
            $fotoPath = Legacy::uploadPath($inspection->foto);
            if (is_file($fotoPath)) {
                @unlink($fotoPath);
            }
        }

        DB::statement('DELETE FROM inspections WHERE id = ?', [$id]);

        return Api::ok(['message' => 'Inspeksi berhasil dihapus'], 200);
    }

    public function approveMonthlyResults(Request $request, string $id, string $bulan_ke): JsonResponse
    {
        $review = DB::selectOne(
            'SELECT id, review_status FROM inspection_monthly_reviews WHERE inspection_id = ? AND bulan_ke = ?',
            [$id, $bulan_ke]
        );
        if (!$review) {
            return Api::error(
                "Review untuk bulan ke-{$bulan_ke} tidak ditemukan. Isi hasil inspeksi terlebih dahulu.",
                404
            );
        }
        if ($review->review_status !== 'PENDING') {
            return Api::error("Review bulan ke-{$bulan_ke} sudah memiliki status: {$review->review_status}", 400);
        }

        DB::statement(
            "UPDATE inspection_results
             SET approval_status = 'APPROVED', alasan_penolakan = NULL
             WHERE inspection_id = ? AND bulan_ke = ? AND approval_status IN ('PENDING', 'REJECTED')",
            [$id, $bulan_ke]
        );
        $userId = Legacy::user($request)['id'] ?? null;
        DB::statement(
            "UPDATE inspection_monthly_reviews
             SET review_status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = NULL
             WHERE id = ?",
            [$userId, $review->id]
        );

        return Api::ok(['message' => "Hasil inspeksi bulan ke-{$bulan_ke} berhasil disetujui"], 200);
    }

    public function rejectMonthlyResults(Request $request, string $id, string $bulan_ke): JsonResponse
    {
        // validateRejectReview + handleValidationErrors dari route lama.
        $errors = LegacyValidator::rejectReview($request);
        if (count($errors) > 0) {
            return Api::validation($errors);
        }

        $alasanPenolakan = $request->input('alasan_penolakan');

        $review = DB::selectOne(
            'SELECT id, review_status FROM inspection_monthly_reviews WHERE inspection_id = ? AND bulan_ke = ?',
            [$id, $bulan_ke]
        );
        if (!$review) {
            return Api::error("Review untuk bulan ke-{$bulan_ke} tidak ditemukan.", 404);
        }
        if ($review->review_status !== 'PENDING') {
            return Api::error("Review bulan ke-{$bulan_ke} sudah memiliki status: {$review->review_status}", 400);
        }

        $alasan = self::jsOr($alasanPenolakan, null);
        DB::statement(
            "UPDATE inspection_results
             SET approval_status = 'REJECTED', alasan_penolakan = ?
             WHERE inspection_id = ? AND bulan_ke = ? AND approval_status IN ('PENDING', 'APPROVED')",
            [$alasan, $id, $bulan_ke]
        );
        $userId = Legacy::user($request)['id'] ?? null;
        DB::statement(
            "UPDATE inspection_monthly_reviews
             SET review_status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = ?
             WHERE id = ?",
            [$userId, $alasan, $review->id]
        );

        return Api::ok([
            'message' => "Hasil inspeksi bulan ke-{$bulan_ke} berhasil ditolak",
            'alasan' => $alasanPenolakan,
        ], 200);
    }

    public function getPendingReviews(Request $request): JsonResponse
    {
        // Sama persis dengan createInspection: Januari -> tahun-1 & GANJIL.
        [$currentYear, $currentSemester] = Legacy::yearSemester();

        $pending = DB::select(
            "SELECT
                imr.*,
                i.item_id, i.laboratory_id, i.inspector_id, i.tahun, i.semester,
                it.nama_barang, it.kode_barang,
                l.nama_lab,
                u.name as inspector_name,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke) as total_checks,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke AND ir.status = 'B') as baik_count,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke AND ir.status = 'K') as kurang_count,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke AND ir.status = 'N/A') as na_count
             FROM inspection_monthly_reviews imr
             JOIN inspections i ON imr.inspection_id = i.id
             JOIN items it ON i.item_id = it.id
             JOIN laboratories l ON i.laboratory_id = l.id
             LEFT JOIN users u ON i.inspector_id = u.id
             WHERE imr.review_status = ? AND i.tahun = ? AND i.semester = ?
             ORDER BY imr.created_at DESC",
            ['PENDING', $currentYear, $currentSemester]
        );

        return Api::ok(['data' => $pending, 'total' => count($pending)], 200);
    }

    // ==================== PER-ITEM APPROVAL ====================

    public function approveResultItem(Request $request, string $resultId): JsonResponse
    {
        $result = DB::selectOne(
            'SELECT ir.id, ir.inspection_id, ir.bulan_ke FROM inspection_results ir WHERE ir.id = ?',
            [$resultId]
        );
        if (!$result) {
            return Api::error('Hasil inspeksi tidak ditemukan', 404);
        }

        DB::statement(
            'UPDATE inspection_results SET approval_status = ?, alasan_penolakan = NULL WHERE id = ?',
            ['APPROVED', $resultId]
        );

        $monthStatus = DB::selectOne(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN approval_status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count
             FROM inspection_results
             WHERE inspection_id = ? AND bulan_ke = ?",
            [$result->inspection_id, $result->bulan_ke]
        );
        if ((int) ($monthStatus->total ?? 0) > 0
            && (int) ($monthStatus->total ?? 0) === (int) ($monthStatus->approved_count ?? 0)) {
            $userId = Legacy::user($request)['id'] ?? null;
            DB::statement(
                "UPDATE inspection_monthly_reviews
                 SET review_status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = NULL
                 WHERE inspection_id = ? AND bulan_ke = ? AND review_status != 'APPROVED'",
                [$userId, $result->inspection_id, $result->bulan_ke]
            );
        }

        return Api::ok(['message' => 'Hasil inspeksi berhasil disetujui'], 200);
    }

    public function rejectResultItem(Request $request, string $resultId): JsonResponse
    {
        $result = DB::selectOne(
            'SELECT ir.id, ir.inspection_id, ir.bulan_ke FROM inspection_results ir WHERE ir.id = ?',
            [$resultId]
        );
        if (!$result) {
            return Api::error('Hasil inspeksi tidak ditemukan', 404);
        }

        $alasanPenolakan = $request->input('alasan_penolakan');
        DB::statement(
            'UPDATE inspection_results SET approval_status = ?, alasan_penolakan = ? WHERE id = ?',
            ['REJECTED', self::jsOr($alasanPenolakan, null), $resultId]
        );

        $userId = Legacy::user($request)['id'] ?? null;
        DB::statement(
            "UPDATE inspection_monthly_reviews
             SET review_status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = ?
             WHERE inspection_id = ? AND bulan_ke = ? AND review_status = 'PENDING'",
            [$userId, self::jsOr($alasanPenolakan, null), $result->inspection_id, $result->bulan_ke]
        );

        return Api::ok([
            'message' => 'Hasil inspeksi berhasil ditolak',
            'alasan_penolakan' => $alasanPenolakan,
        ], 200);
    }

    // ==================== BULK APPROVAL ====================

    public function bulkApproveResults(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || !array_is_list($ids) || count($ids) === 0) {
            return Api::error('Daftar ID hasil inspeksi harus diisi', 400);
        }

        $placeholders = self::inPlaceholders(count($ids));
        $existing = DB::select(
            "SELECT DISTINCT inspection_id, bulan_ke FROM inspection_results
             WHERE id IN ({$placeholders}) AND approval_status IN ('PENDING', 'REJECTED')",
            $ids
        );
        if (count($existing) === 0) {
            return Api::error('Tidak ada hasil inspeksi yang menunggu persetujuan', 404);
        }

        DB::statement(
            "UPDATE inspection_results SET approval_status = ?, alasan_penolakan = NULL WHERE id IN ({$placeholders})",
            array_merge(['APPROVED'], $ids)
        );

        $userId = Legacy::user($request)['id'] ?? null;
        foreach ($existing as $row) {
            $monthStatus = DB::selectOne(
                "SELECT COUNT(*) as total,
                        SUM(CASE WHEN approval_status = 'APPROVED' THEN 1 ELSE 0 END) as approved_count
                 FROM inspection_results
                 WHERE inspection_id = ? AND bulan_ke = ?",
                [$row->inspection_id, $row->bulan_ke]
            );
            if ((int) ($monthStatus->total ?? 0) > 0
                && (int) ($monthStatus->total ?? 0) === (int) ($monthStatus->approved_count ?? 0)) {
                DB::statement(
                    "UPDATE inspection_monthly_reviews
                     SET review_status = 'APPROVED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = NULL
                     WHERE inspection_id = ? AND bulan_ke = ? AND review_status != 'APPROVED'",
                    [$userId, $row->inspection_id, $row->bulan_ke]
                );
            }
        }

        return Api::ok(['message' => count($ids) . ' hasil inspeksi berhasil disetujui'], 200);
    }

    public function bulkRejectResults(Request $request): JsonResponse
    {
        $ids = $request->input('ids');
        if (!is_array($ids) || !array_is_list($ids) || count($ids) === 0) {
            return Api::error('Daftar ID hasil inspeksi harus diisi', 400);
        }
        $alasanPenolakan = $request->input('alasan_penolakan');
        if (!self::jsTruthy($alasanPenolakan)) {
            return Api::error('Alasan penolakan harus diisi', 400);
        }

        $placeholders = self::inPlaceholders(count($ids));
        $existing = DB::select(
            "SELECT DISTINCT inspection_id, bulan_ke FROM inspection_results
             WHERE id IN ({$placeholders}) AND approval_status IN ('PENDING', 'APPROVED')",
            $ids
        );
        if (count($existing) === 0) {
            return Api::error('Tidak ada hasil inspeksi yang bisa ditolak', 404);
        }

        DB::statement(
            "UPDATE inspection_results SET approval_status = ?, alasan_penolakan = ? WHERE id IN ({$placeholders})",
            array_merge(['REJECTED', $alasanPenolakan], $ids)
        );

        $userId = Legacy::user($request)['id'] ?? null;
        foreach ($existing as $row) {
            DB::statement(
                "UPDATE inspection_monthly_reviews
                 SET review_status = 'REJECTED', reviewed_by = ?, reviewed_at = NOW(), alasan_penolakan = ?
                 WHERE inspection_id = ? AND bulan_ke = ? AND review_status = 'PENDING'",
                [$userId, $alasanPenolakan, $row->inspection_id, $row->bulan_ke]
            );
        }

        return Api::ok([
            'message' => count($ids) . ' hasil inspeksi berhasil ditolak',
            'alasan' => $alasanPenolakan,
        ], 200);
    }

    // ==================== KALAB DASHBOARD / STATUS / LOOKUP ====================

    public function getMyPendingInspections(Request $request): JsonResponse
    {
        $userId = Legacy::user($request)['id'] ?? null;
        $inspections = DB::select(
            "SELECT
                i.id, i.laboratory_id, i.item_id, i.tanggal_inspeksi,
                l.nama_lab, it.nama_barang, it.kode_barang,
                imr.bulan_ke, imr.review_status, imr.alasan_penolakan, imr.created_at as review_date,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke) as total_items,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke AND ir.approval_status = 'REJECTED') as rejected_count,
                (SELECT COUNT(*) FROM inspection_results ir WHERE ir.inspection_id = i.id AND ir.bulan_ke = imr.bulan_ke AND ir.approval_status = 'APPROVED') as approved_count
             FROM inspections i
             JOIN inspection_monthly_reviews imr ON i.id = imr.inspection_id
             JOIN laboratories l ON i.laboratory_id = l.id
             JOIN items it ON i.item_id = it.id
             WHERE i.inspector_id = ? AND imr.review_status IN ('PENDING', 'REJECTED')
             ORDER BY imr.review_status ASC, imr.created_at DESC",
            [$userId]
        );

        return Api::ok(['data' => $inspections, 'total' => count($inspections)], 200);
    }

    public function getResultsByStatus(Request $request): JsonResponse
    {
        $approvalStatus = $request->query('approval_status');
        $inspectionId = $request->query('inspection_id');
        $laboratoryId = $request->query('laboratory_id');
        // tahun & semester dibaca source tetapi tidak dipakai dalam query - dipertahankan.

        $whereClauses = [];
        $params = [];

        if ($approvalStatus) {
            $upper = strtoupper((string) $approvalStatus);
            if (!in_array($upper, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
                return Api::error('approval_status harus PENDING, APPROVED, atau REJECTED', 400);
            }
            $whereClauses[] = 'ir.approval_status = ?';
            $params[] = $upper;
        }
        if ($inspectionId) {
            $whereClauses[] = 'ir.inspection_id = ?';
            $params[] = $inspectionId;
        }
        if ($laboratoryId) {
            $whereClauses[] = 'i.laboratory_id = ?';
            $params[] = $laboratoryId;
        }

        $whereSQL = count($whereClauses) > 0 ? 'WHERE ' . implode(' AND ', $whereClauses) : '';
        $results = DB::select(
            "SELECT
                ir.id as result_id,
                ir.status as hasil_status,
                ir.keterangan,
                ir.bulan_ke,
                ir.approval_status,
                ir.alasan_penolakan,
                is2.id as subitem_id,
                is2.nama_subitem,
                ic.id as category_id,
                ic.nama_kategori,
                i.id as inspection_id,
                i.tanggal_inspeksi,
                i.tahun, i.semester,
                it.nama_barang,
                it.kode_barang,
                l.nama_lab,
                l.id as laboratory_id,
                u.name as inspector_name
             FROM inspection_results ir
             JOIN inspections i ON ir.inspection_id = i.id
             JOIN inspection_subitems is2 ON ir.subitem_id = is2.id
             JOIN inspection_categories ic ON is2.category_id = ic.id
             JOIN items it ON i.item_id = it.id
             JOIN laboratories l ON i.laboratory_id = l.id
             LEFT JOIN users u ON i.inspector_id = u.id
             {$whereSQL}
             ORDER BY ir.bulan_ke ASC, l.nama_lab ASC, it.nama_barang ASC, ic.urutan ASC, is2.urutan ASC",
            $params
        );

        $grouped = [];
        foreach ($results as $row) {
            $key = $row->inspection_id . '-' . $row->bulan_ke;
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'inspection_id' => $row->inspection_id,
                    'bulan_ke' => $row->bulan_ke,
                    'tahun' => $row->tahun,
                    'semester' => $row->semester,
                    'tanggal_inspeksi' => $row->tanggal_inspeksi,
                    'nama_barang' => $row->nama_barang,
                    'kode_barang' => $row->kode_barang,
                    'nama_lab' => $row->nama_lab,
                    'laboratory_id' => $row->laboratory_id,
                    'inspector_name' => $row->inspector_name,
                    'items' => [],
                ];
            }
            $grouped[$key]['items'][] = [
                'result_id' => $row->result_id,
                'subitem_id' => $row->subitem_id,
                'nama_subitem' => $row->nama_subitem,
                'category_id' => $row->category_id,
                'nama_kategori' => $row->nama_kategori,
                'hasil_status' => $row->hasil_status,
                'keterangan' => $row->keterangan,
                'approval_status' => $row->approval_status,
                'alasan_penolakan' => $row->alasan_penolakan,
            ];
        }

        return Api::ok(['data' => array_values($grouped), 'total' => count($results)], 200);
    }

    /**
     * Hitung status inspeksi satu item. Dipakai bersama endpoint single dan batch
     * supaya hasilnya identik persis, hanya jumlah HTTP request yang berbeda.
     *
     * @return array<string, mixed>
     */
    private static function itemInspectionStatus(string $itemId, mixed $tahun, mixed $semester): array
    {
        $whereClause = 'WHERE i.item_id = ?';
        $params = [$itemId];
        if ($tahun) {
            $whereClause .= ' AND i.tahun = ?';
            $params[] = is_numeric($tahun) ? $tahun + 0 : 0;
        }
        if ($semester) {
            $whereClause .= ' AND i.semester = ?';
            $params[] = $semester;
        }

        // Semua penghitungan yang sebelumnya dilakukan per inspection_id kini
        // menjadi subquery, sehingga satu item cukup 2 query (dulu hingga 6).
        $rows = DB::select(
            "SELECT i.id, i.tahun, i.semester, imr.review_status, imr.bulan_ke, imr.alasan_penolakan,
                    EXISTS(SELECT 1 FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'APPROVED') as has_approved_month,
                    (SELECT COUNT(DISTINCT bulan_ke) FROM inspection_results WHERE inspection_id = i.id) as filled_months,
                    (SELECT COUNT(*) FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'PENDING') as review_pending,
                    (SELECT COUNT(*) FROM inspection_results WHERE inspection_id = i.id AND approval_status = 'PENDING') as result_pending,
                    (SELECT COUNT(*) FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'REJECTED') as review_rejected
             FROM inspections i
             LEFT JOIN inspection_monthly_reviews imr ON imr.inspection_id = i.id
             {$whereClause}
             ORDER BY imr.bulan_ke DESC
             LIMIT 1",
            $params
        );

        $categoryCounts = DB::selectOne(
            "SELECT COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as category_pending,
                    COUNT(CASE WHEN status = 'REJECTED' THEN 1 END) as category_rejected
             FROM inspection_categories
             WHERE item_id = ?",
            [$itemId]
        );

        $pendingCount = (int) ($categoryCounts->category_pending ?? 0);
        $rejectedInspectionCount = 0;

        if (count($rows) > 0) {
            $pendingCount += (int) ($rows[0]->review_pending ?? 0)
                + (int) ($rows[0]->result_pending ?? 0);
            $rejectedInspectionCount = (int) ($rows[0]->review_rejected ?? 0);
        }

        $needFillNextMonth = false;
        if (count($rows) > 0) {
            $latestApproved = (int) ($rows[0]->filled_months ?? 0);
            if ($rows[0]->review_status === 'APPROVED' && $latestApproved < 6) {
                $needFillNextMonth = true;
            }
        }

        return [
            'exists' => count($rows) > 0,
            'inspection_id' => count($rows) > 0 ? $rows[0]->id : null,
            'tahun' => count($rows) > 0 ? $rows[0]->tahun : null,
            'semester' => count($rows) > 0 ? $rows[0]->semester : null,
            'review_status' => count($rows) > 0 ? $rows[0]->review_status : null,
            'alasan_penolakan' => count($rows) > 0 ? $rows[0]->alasan_penolakan : null,
            'has_approved_month' => count($rows) > 0 ? (bool) ($rows[0]->has_approved_month ?? 0) : false,
            'filled_months' => count($rows) > 0 ? (int) ($rows[0]->filled_months ?? 0) : 0,
            'pendingCount' => $pendingCount,
            'hasPending' => $pendingCount > 0,
            'hasRejectedCategory' => (int) ($categoryCounts->category_rejected ?? 0) > 0,
            'hasRejectedInspection' => $rejectedInspectionCount > 0,
            'needFillNextMonth' => $needFillNextMonth,
        ];
    }

    /**
     * Versi batch dari itemInspectionStatus(): dua query untuk seluruh item,
     * bukan dua query per item. Nilai per item sengaja dihitung dengan rumus
     * yang sama persis supaya response tidak berubah.
     *
     * @param array<int, string> $ids
     * @return array<string, mixed>
     */
    private static function itemsInspectionStatuses(array $ids, mixed $tahun, mixed $semester): array
    {
        $data = [];
        if ($ids === []) {
            return $data;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $params = array_values($ids);
        $whereClause = "WHERE i.item_id IN ($placeholders)";
        if ($tahun) {
            $whereClause .= ' AND i.tahun = ?';
            $params[] = is_numeric($tahun) ? $tahun + 0 : 0;
        }
        if ($semester) {
            $whereClause .= ' AND i.semester = ?';
            $params[] = $semester;
        }

        // ROW_NUMBER menggantikan "ORDER BY imr.bulan_ke DESC LIMIT 1" per item.
        $rows = DB::select(
            "SELECT * FROM (
                SELECT i.item_id, i.id, i.tahun, i.semester, imr.review_status, imr.bulan_ke, imr.alasan_penolakan,
                       EXISTS(SELECT 1 FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'APPROVED') as has_approved_month,
                       (SELECT COUNT(DISTINCT bulan_ke) FROM inspection_results WHERE inspection_id = i.id) as filled_months,
                       (SELECT COUNT(*) FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'PENDING') as review_pending,
                       (SELECT COUNT(*) FROM inspection_results WHERE inspection_id = i.id AND approval_status = 'PENDING') as result_pending,
                       (SELECT COUNT(*) FROM inspection_monthly_reviews WHERE inspection_id = i.id AND review_status = 'REJECTED') as review_rejected,
                       ROW_NUMBER() OVER (PARTITION BY i.item_id ORDER BY imr.bulan_ke DESC) as rn
                FROM inspections i
                LEFT JOIN inspection_monthly_reviews imr ON imr.inspection_id = i.id
                {$whereClause}
            ) ranked WHERE rn = 1",
            $params
        );

        $byItem = [];
        foreach ($rows as $row) {
            $byItem[(string) $row->item_id] = $row;
        }

        $categoryCounts = DB::select(
            "SELECT item_id,
                    COUNT(CASE WHEN status = 'PENDING' THEN 1 END) as category_pending,
                    COUNT(CASE WHEN status = 'REJECTED' THEN 1 END) as category_rejected
             FROM inspection_categories
             WHERE item_id IN ($placeholders)
             GROUP BY item_id",
            $ids
        );

        $countsByItem = [];
        foreach ($categoryCounts as $count) {
            $countsByItem[(string) $count->item_id] = $count;
        }

        foreach ($ids as $id) {
            $key = (string) $id;
            $row = $byItem[$key] ?? null;
            $counts = $countsByItem[$key] ?? null;

            $pendingCount = (int) ($counts->category_pending ?? 0);
            $rejectedInspectionCount = 0;

            if ($row) {
                $pendingCount += (int) ($row->review_pending ?? 0)
                    + (int) ($row->result_pending ?? 0);
                $rejectedInspectionCount = (int) ($row->review_rejected ?? 0);
            }

            $needFillNextMonth = false;
            if ($row) {
                $latestApproved = (int) ($row->filled_months ?? 0);
                if ($row->review_status === 'APPROVED' && $latestApproved < 6) {
                    $needFillNextMonth = true;
                }
            }

            $data[$key] = [
                'exists' => $row !== null,
                'inspection_id' => $row ? $row->id : null,
                'tahun' => $row ? $row->tahun : null,
                'semester' => $row ? $row->semester : null,
                'review_status' => $row ? $row->review_status : null,
                'alasan_penolakan' => $row ? $row->alasan_penolakan : null,
                'has_approved_month' => $row ? (bool) ($row->has_approved_month ?? 0) : false,
                'filled_months' => $row ? (int) ($row->filled_months ?? 0) : 0,
                'pendingCount' => $pendingCount,
                'hasPending' => $pendingCount > 0,
                'hasRejectedCategory' => (int) ($counts->category_rejected ?? 0) > 0,
                'hasRejectedInspection' => $rejectedInspectionCount > 0,
                'needFillNextMonth' => $needFillNextMonth,
            ];
        }

        return $data;
    }

    public function checkInspectionByItemId(Request $request, string $item_id): JsonResponse
    {
        return Api::ok(
            self::itemInspectionStatus($item_id, $request->query('tahun'), $request->query('semester')),
            200
        );
    }

    /**
     * Versi batch untuk frontend: satu HTTP request untuk seluruh item yang
     * sedang dirender, bukan satu request per item. Logika tiap item sama persis
     * dengan checkInspectionByItemId().
     */
    public function checkInspectionsByItemIds(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(
            array_map(static fn ($value) => trim((string) $value), explode(',', (string) $request->query('ids', ''))),
            static fn ($value) => $value !== ''
        ));

        $data = self::itemsInspectionStatuses(
            $ids,
            $request->query('tahun'),
            $request->query('semester')
        );

        return Api::ok(['data' => $data], 200);
    }

    public function getLabSemesters(Request $request, string $laboratoryId): JsonResponse
    {
        $rows = DB::select(
            "SELECT DISTINCT i.tahun, i.semester
             FROM inspections i
             WHERE i.laboratory_id = ?
             ORDER BY i.tahun DESC, i.semester DESC",
            [$laboratoryId]
        );

        return Api::ok(['data' => $rows], 200);
    }
}
