<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Worksheet\Drawing;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use ZipArchive;

/**
 * Port dari src/controllers/exportController.js (backend lama).
 * Helper yang diperlukan sengaja ditaruh di class ini agar tidak menyentuh file lain.
 */
class ExportController extends Controller
{
    private const TEMPLATE_XLSX = 'TEMPLATE INSPEKSI ALAT LAB_BENGKEL PPNS_Z.xlsx';
    private const TEMPLATE_DAMAGED = 'DRAFT LAPORAN PERALATAN RUSAK LAB DAN BENGKEL.docx';
    private const TEMPLATE_REPAIR = 'DRAFT_LAPORAN_HASIL_PERBAIKAN_LAB_DAN_BENGKEL(1).docx';

    private const XLSX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
    private const DOCX_CONTENT_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    /** [kolom B, kolom K] untuk bulan ke-1 s/d ke-6 (1-based). */
    private const MONTH_COLS = [
        [9, 10], [11, 12], [13, 14], [15, 16], [17, 18], [19, 20],
    ];

    private const TEMPLATE_DATA_ROWS = 31; // baris 11 sampai 41

    // ---------------------------------------------------------------------
    // ENDPOINT
    // ---------------------------------------------------------------------

    public function exportInspection(Request $request, $id)
    {
        $inspections = DB::select(
            'SELECT i.*, u.name as inspector_name, l.nama_lab, l.lokasi,
                    kalab.name as penanggung_jawab, kalab.nip,
                    plp.name AS plp_name, plp.nip AS plp_nip,
                    teknisi.name AS teknisi_name, teknisi.nip AS teknisi_nip,
                    it.nama_barang, it.kode_barang, it.pembuat_alat, it.tanggal_pembelian
            FROM inspections i
            LEFT JOIN users u ON i.inspector_id = u.id
            LEFT JOIN laboratories l ON i.laboratory_id = l.id
            LEFT JOIN users kalab ON l.kalab_id = kalab.id
            LEFT JOIN users plp ON l.plp_id = plp.id
            LEFT JOIN users teknisi ON l.teknisi_id = teknisi.id
            LEFT JOIN items it ON i.item_id = it.id
            WHERE i.id = ?',
            [$id]
        );

        if (count($inspections) === 0) {
            return Api::error('Inspeksi tidak ditemukan', 404);
        }
        $inspection = $inspections[0];

        $results = DB::select(
            'SELECT ir.status, ir.bulan_ke, is2.id as subitem_id, is2.nama_subitem,
                    ic.id as category_id, ic.nama_kategori, ic.urutan as category_urutan,
                    is2.urutan as subitem_urutan
            FROM inspection_results ir
            JOIN inspection_subitems is2 ON ir.subitem_id = is2.id
            JOIN inspection_categories ic ON is2.category_id = ic.id
            WHERE ir.inspection_id = ?
            ORDER BY ic.urutan ASC, is2.urutan ASC, ir.bulan_ke ASC',
            [$id]
        );

        $workbook = IOFactory::load(self::templatePath(self::TEMPLATE_XLSX));
        while ($workbook->getSheetCount() > 1) {
            $workbook->removeSheetByIndex($workbook->getSheetCount() - 1);
        }
        $sheet = $workbook->getSheet(0);

        $staff = [
            'penanggung_jawab' => $inspection->penanggung_jawab ?? null,
            'nip' => $inspection->nip ?? null,
            'plp_name' => $inspection->plp_name ?? null,
            'plp_nip' => $inspection->plp_nip ?? null,
            'teknisi_name' => $inspection->teknisi_name ?? null,
            'teknisi_nip' => $inspection->teknisi_nip ?? null,
        ];

        // exportInspection: header diberi font/border; hanya sel K pada tiap pasangan yang dikosongkan.
        $this->fillInspectionSheet($workbook, $sheet, $inspection, $results, $staff, true, false, true);

        $year = (int) date('Y', strtotime((string) $inspection->tanggal_inspeksi));
        $filename = 'Inspeksi_' . preg_replace('/\s+/u', '_', (string) $inspection->nama_barang) . '_' . $year . '.xlsx';

        return self::xlsxResponse($workbook, $filename);
    }

    public function exportAllCompleted()
    {
        $inspections = DB::select(
            'SELECT i.id, i.tanggal_inspeksi,
                    u.name as inspector_name, l.nama_lab, l.lokasi,
                    it.nama_barang, it.kode_barang
            FROM inspections i
            LEFT JOIN users u ON i.inspector_id = u.id
            LEFT JOIN laboratories l ON i.laboratory_id = l.id
            LEFT JOIN items it ON i.item_id = it.id
            WHERE (
              SELECT COUNT(DISTINCT ir.bulan_ke)
              FROM inspection_results ir
              WHERE ir.inspection_id = i.id
            ) = 6
            ORDER BY l.nama_lab ASC, it.nama_barang ASC, i.tanggal_inspeksi ASC'
        );

        if (count($inspections) === 0) {
            return Api::error('Tidak ada inspeksi yang sudah lengkap 6 bulan', 404);
        }

        $workbook = new Spreadsheet();
        $sheet = $workbook->getActiveSheet();
        $sheet->setTitle('Rekap Inspeksi Lengkap');

        $widths = ['A' => 5, 'B' => 25, 'C' => 30, 'D' => 15, 'E' => 25, 'F' => 20];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        $thin = self::thinBorder();
        $headers = ['No', 'Laboratorium', 'Nama Alat', 'Kode Barang', 'Inspektur', 'Tanggal Inspeksi'];
        foreach ($headers as $i => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($i + 1) . '1', $header);
        }
        $sheet->getStyle('A1:F1')->applyFromArray([
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF4472C4']],
            'font' => ['bold' => true, 'size' => 12, 'name' => 'Calibri', 'color' => ['argb' => 'FFFFFFFF']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => $thin,
        ]);

        $no = 1;
        $rowNumber = 2;
        foreach ($inspections as $insp) {
            $sheet->setCellValue('A' . $rowNumber, $no++);
            $sheet->setCellValue('B' . $rowNumber, $insp->nama_lab);
            $sheet->setCellValue('C' . $rowNumber, (string) $insp->nama_barang . (self::jsTruthy($insp->kode_barang) ? ' (' . $insp->kode_barang . ')' : ''));
            $sheet->setCellValue('D' . $rowNumber, self::jsOr($insp->kode_barang, '-'));
            $sheet->setCellValue('E' . $rowNumber, self::jsOr($insp->inspector_name, '-'));
            $sheet->setCellValue('F' . $rowNumber, self::formatDate($insp->tanggal_inspeksi));
            $rowNumber++;
        }

        if (count($inspections) > 0) {
            $sheet->getStyle('A2:F' . (count($inspections) + 1))->applyFromArray([
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => $thin,
            ]);
        }

        return self::xlsxResponse($workbook, 'Rekap_Inspeksi_Lengkap.xlsx');
    }

    public function checkLabInspectionsStatus(Request $request)
    {
        $labId = $request->input('labId');
        $tahun = $request->input('tahun');
        $semester = $request->input('semester');

        if (!self::jsTruthy($labId) || !self::jsTruthy($tahun) || !self::jsTruthy($semester)) {
            return Api::error('labId, tahun, dan semester harus diisi', 400);
        }

        $labs = DB::select('SELECT item_ids FROM laboratories WHERE id = ?', [$labId]);
        if (count($labs) === 0) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }

        $itemIds = Legacy::csvIds($labs[0]->item_ids ?? null);
        if (count($itemIds) === 0) {
            return Api::error('Laboratorium tidak memiliki peralatan', 400);
        }

        $placeholders = implode(',', array_fill(0, count($itemIds), '?'));
        $params = array_merge(array_values($itemIds), [$labId, $tahun, $semester]);
        $inspectionRows = DB::select(
            "SELECT i.id, i.item_id, i.tahun, i.semester,
                    it.nama_barang, it.kode_barang,
                    COUNT(DISTINCT ir.bulan_ke) as total_months
             FROM inspections i
             LEFT JOIN inspection_results ir ON i.id = ir.inspection_id
             LEFT JOIN items it ON i.item_id = it.id
             WHERE i.item_id IN ($placeholders) AND i.laboratory_id = ? AND i.tahun = ? AND i.semester = ?
             GROUP BY i.id",
            $params
        );

        // Urutan hasil tetap mengikuti item_ids pada laboratorium, seperti loop lama.
        $inspectionsByItem = [];
        foreach ($inspectionRows as $inspection) {
            if (! isset($inspectionsByItem[$inspection->item_id])) {
                $inspectionsByItem[$inspection->item_id] = $inspection;
            }
        }

        $incompleteInspections = [];
        foreach ($itemIds as $itemId) {
            if (! isset($inspectionsByItem[$itemId])) {
                $incompleteInspections[] = [
                    'item_id' => $itemId,
                    'status' => 'NOT_FOUND',
                    'message' => 'Inspeksi belum dibuat',
                ];
                continue;
            }

            $inspection = $inspectionsByItem[$itemId];
            $totalMonths = (int) $inspection->total_months;
            if ($totalMonths < 6) {
                $incompleteInspections[] = [
                    'item_id' => $itemId,
                    'inspection_id' => $inspection->id,
                    'nama_barang' => $inspection->nama_barang,
                    'kode_barang' => $inspection->kode_barang,
                    'status' => 'INCOMPLETE',
                    'completed_months' => $totalMonths,
                    'message' => 'Hanya ' . $totalMonths . '/6 bulan yang selesai',
                ];
            }
        }

        return Api::ok([
            'canExport' => count($incompleteInspections) === 0,
            'incompleteInspections' => $incompleteInspections,
            'totalItems' => count($itemIds),
            'completedItems' => count($itemIds) - count($incompleteInspections),
        ]);
    }

    public function exportLabItems(Request $request)
    {
        $labId = $request->input('labId');
        $tahun = $request->input('tahun');
        $semester = $request->input('semester');

        if (!self::jsTruthy($labId) || !self::jsTruthy($tahun) || !self::jsTruthy($semester)) {
            return Api::error('labId, tahun, dan semester harus diisi', 400);
        }

        $labs = DB::select(
            'SELECT l.*,
                    kalab.name AS kalab_name, kalab.nip AS kalab_nip,
                    plp.name AS plp_name, plp.nip AS plp_nip,
                    teknisi.name AS teknisi_name, teknisi.nip AS teknisi_nip
            FROM laboratories l
            LEFT JOIN users kalab ON l.kalab_id = kalab.id
            LEFT JOIN users plp ON l.plp_id = plp.id
            LEFT JOIN users teknisi ON l.teknisi_id = teknisi.id
            WHERE l.id = ?',
            [$labId]
        );

        if (count($labs) === 0) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }
        $lab = $labs[0];

        $itemIds = Legacy::csvIds($lab->item_ids ?? null);
        if (count($itemIds) === 0) {
            return Api::error('Laboratorium belum memiliki peralatan', 400);
        }

        $inspectionList = [];
        foreach ($itemIds as $itemId) {
            $inspections = DB::select(
                'SELECT i.*, it.nama_barang, it.kode_barang, it.pembuat_alat, it.tanggal_pembelian,
                        l.nama_lab, l.lokasi, u.name AS inspector_name,
                        kalab.name AS penanggung_jawab, kalab.nip
                FROM inspections i
                JOIN items it ON i.item_id = it.id
                JOIN laboratories l ON i.laboratory_id = l.id
                LEFT JOIN users u ON i.inspector_id = u.id
                LEFT JOIN users kalab ON l.kalab_id = kalab.id
                WHERE i.item_id = ? AND i.laboratory_id = ? AND i.tahun = ? AND i.semester = ?',
                [$itemId, $labId, $tahun, $semester]
            );

            if (count($inspections) === 0) {
                return Api::error('Peralatan ID ' . $itemId . ' belum memiliki inspeksi', 400);
            }

            $inspection = $inspections[0];
            $pending = DB::selectOne(
                'SELECT COUNT(*) AS total FROM inspection_results
                 WHERE inspection_id = ? AND (status IS NULL OR status = \'\')',
                [$inspection->id]
            );

            if ((int) $pending->total > 0) {
                return Api::error('Peralatan "' . $inspection->nama_barang . '" masih memiliki checklist yang belum selesai.', 400);
            }

            $inspectionList[] = $inspection;
        }

        try {
            $workbook = IOFactory::load(self::templatePath(self::TEMPLATE_XLSX));
            while ($workbook->getSheetCount() > 1) {
                $workbook->removeSheetByIndex($workbook->getSheetCount() - 1);
            }

            $templateSheet = $workbook->getSheet(0);

            $logoPath = self::logoPath();

            foreach ($inspectionList as $i => $inspection) {
                $desiredTitle = self::uniqueSheetTitle(
                    $workbook,
                    self::safeSheetTitle(self::jsOr($inspection->kode_barang, $inspection->nama_barang))
                );
                if ($i === 0) {
                    $sheet = $templateSheet;
                    $sheet->setTitle($desiredTitle);
                } else {
                    // Sama seperti source: template dibuka ulang untuk setiap sheet clone.
                    $templateWorkbook = IOFactory::load(self::templatePath(self::TEMPLATE_XLSX));
                    $externalSheet = $templateWorkbook->getSheet(0);
                    $externalSheet->setTitle($desiredTitle);
                    $sheet = $workbook->addExternalSheet($externalSheet, $workbook->getSheetCount());
                }

                if ($i > 0 && $logoPath !== null) {
                    self::addLogo($sheet, $logoPath);
                }

                $results = DB::select(
                    'SELECT ir.status, ir.bulan_ke, is2.id as subitem_id, is2.nama_subitem,
                            ic.id as category_id, ic.nama_kategori, ic.urutan as category_urutan,
                            is2.urutan as subitem_urutan
                    FROM inspection_results ir
                    JOIN inspection_subitems is2 ON ir.subitem_id = is2.id
                    JOIN inspection_categories ic ON is2.category_id = ic.id
                    WHERE ir.inspection_id = ?
                    ORDER BY ic.urutan, is2.urutan, ir.bulan_ke',
                    [$inspection->id]
                );

                $staff = [
                    'penanggung_jawab' => $inspection->penanggung_jawab ?? null,
                    'nip' => $inspection->nip ?? null,
                    'plp_name' => $lab->plp_name ?? null,
                    'plp_nip' => $lab->plp_nip ?? null,
                    'teknisi_name' => $lab->teknisi_name ?? null,
                    'teknisi_nip' => $lab->teknisi_nip ?? null,
                ];

                // exportLabItems: seluruh header bulan dikosongkan dulu, tanpa font/border baru.
                $this->fillInspectionSheet($workbook, $sheet, $inspection, $results, $staff, false, true, false);
            }

            return self::xlsxResponse($workbook, 'Export_' . $lab->nama_lab . '.xlsx');
        } catch (\Throwable $e) {
            return Api::error($e->getMessage(), 500);
        }
    }

    public function exportDamagedEquipmentReport(Request $request)
    {
        $tahun = $request->input('tahun');
        $semester = self::normalizeSemester($request->input('semester'));

        if (!self::jsTruthy($tahun) || !preg_match('/^\d{4}$/', (string) $tahun) || !in_array($semester, ['GANJIL', 'GENAP'], true)) {
            return Api::error('Query tahun dan semester wajib diisi. Semester harus GANJIL atau GENAP.', 400);
        }

        $template = self::templatePath(self::TEMPLATE_DAMAGED);
        if (!is_file($template)) {
            return Api::error('Template laporan peralatan rusak tidak ditemukan.', 500);
        }

        $labsData = self::buildDamagedLabsData((int) $tahun, $semester);
        if (count($labsData) === 0) {
            return Api::error('Tidak ada laboratorium yang memiliki alat rusak.', 400);
        }

        $content = self::cloneTemplateWithDamagedEquipmentPages($labsData, (int) $tahun, $semester);
        $filename = 'Laporan_Peralatan_Rusak_' . $semester . '_' . $tahun . '.docx';

        return response($content, 200, [
            'Content-Type' => self::DOCX_CONTENT_TYPE,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    public function exportRepairEquipmentReport(Request $request)
    {
        $tahun = $request->input('tahun');
        $semester = self::normalizeSemester($request->input('semester'));

        if (!self::jsTruthy($tahun) || !preg_match('/^\d{4}$/', (string) $tahun) || !in_array($semester, ['GANJIL', 'GENAP'], true)) {
            return Api::error('Query tahun dan semester wajib diisi. Semester harus GANJIL atau GENAP.', 400);
        }

        $template = self::templatePath(self::TEMPLATE_REPAIR);
        if (!is_file($template)) {
            return Api::error('Template laporan perbaikan peralatan tidak ditemukan.', 500);
        }

        $labsData = self::buildRepairLabsData((int) $tahun, $semester);
        if (count($labsData) === 0) {
            return Api::error('Tidak ada data perbaikan peralatan.', 400);
        }

        $content = self::cloneTemplateWithRepairPages($labsData, (int) $tahun, $semester);
        $filename = 'Laporan_Peralatan_Perbaikan_' . $semester . '_' . $tahun . '.docx';

        return response($content, 200, [
            'Content-Type' => self::DOCX_CONTENT_TYPE,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Content-Length' => strlen($content),
        ]);
    }

    public function getExportReportStatus(Request $request)
    {
        $tahun = $request->input('tahun');
        $semester = self::normalizeSemester($request->input('semester'));

        // buildRepairLabsData memakai sumber data yang sama dengan
        // buildDamagedLabsData, hanya menyaring item yang punya damage report.
        // Hitung sekali lalu turunkan kedua boolean agar tidak meng-query ulang
        // seluruh laporan (dulu ~12 query, sekarang ~6).
        $damaged = self::buildDamagedLabsData((int) $tahun, $semester);

        $hasRepairReport = false;
        foreach ($damaged as $labData) {
            foreach ($labData['damagedItems'] ?? [] as $item) {
                if ($item['damageReport'] !== null) {
                    $hasRepairReport = true;
                    break 2;
                }
            }
        }

        return Api::ok([
            'hasDamagedReport' => count($damaged) > 0,
            'hasRepairReport' => $hasRepairReport,
        ]);
    }

    // ---------------------------------------------------------------------
    // XLSX HELPERS
    // ---------------------------------------------------------------------

    private static function templatePath(string $name): string
    {
        return storage_path('app/templates/' . $name);
    }

    private static function logoPath(): ?string
    {
        $candidates = [
            storage_path('app/assets/Logo_PPNS.png'),
            storage_path('app/assets/logo-ppns.png'),
            //Asset yang tersalin saat ini berupa file bernama "assets" (isi PNG yang sama).
            storage_path('app/assets'),
            base_path('assets/Logo_PPNS.png'),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function resolveUploadPath(?string $filePath): ?string
    {
        if (!self::jsTruthy($filePath)) {
            return null;
        }

        $normalized = ltrim(str_replace('\\', '/', (string) $filePath), '/');
        if (preg_match('~^[A-Za-z]:/~', $normalized)) {
            return is_file($normalized) ? $normalized : null;
        }

        // Source lama hanya mencoba <backend>/<path>. Di project baru folder
        // uploads berada di public/, jadi public_path($normalized) adalah padanannya.
        // Jangan menambah pencarian berdasarkan basename: source tidak melakukannya.
        $candidates = [
            base_path($normalized),
            public_path($normalized),
            base_path('public/' . $normalized),
            storage_path('app/' . $normalized),
        ];

        foreach ($candidates as $path) {
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    private static function jsTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    private static function jsOr(mixed $value, mixed $fallback): mixed
    {
        return self::jsTruthy($value) ? $value : $fallback;
    }

    /** Kompatibilitas dengan batasan nama sheet PhpSpreadsheet. */
    private static function safeSheetTitle(mixed $title): string
    {
        $title = str_replace(Worksheet::getInvalidCharacters(), '', (string) self::jsOr($title, 'Sheet'));
        if (mb_strlen($title) > 31) {
            $title = mb_substr($title, 0, 31);
        }

        return $title === '' ? 'Sheet' : $title;
    }

    /** Hindari exception PhpSpreadsheet bila dua alat punya kode/nama sama. */
    private static function uniqueSheetTitle(Spreadsheet $workbook, string $title): string
    {
        if (!$workbook->sheetNameExists($title)) {
            return $title;
        }

        $base = mb_substr($title, 0, 27);
        $suffix = 2;
        while ($workbook->sheetNameExists($base . ' (' . $suffix . ')')) {
            $suffix++;
        }

        return $base . ' (' . $suffix . ')';
    }

    private static function formatDate(mixed $date): string
    {
        if (!self::jsTruthy($date)) {
            return '-';
        }

        $timestamp = strtotime((string) $date);
        if ($timestamp === false) {
            return '-';
        }

        $months = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];

        return date('j', $timestamp) . ' ' . $months[(int) date('n', $timestamp)] . ' ' . date('Y', $timestamp);
    }

    /** @return array<string, array<string, string>> */
    private static function thinBorder(): array
    {
        $side = ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']];

        return ['left' => $side, 'right' => $side, 'top' => $side, 'bottom' => $side];
    }

    private static function xlsxResponse(Spreadsheet $workbook, string $filename)
    {
        $writer = IOFactory::createWriter($workbook, 'Xlsx');
        ob_start();
        try {
            $writer->save('php://output');
            $content = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        return response($content, 200, [
            'Content-Type' => self::XLSX_CONTENT_TYPE,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private static function unmergeIfExists(Worksheet $sheet, string $range): void
    {
        if (isset($sheet->getMergeCells()[$range])) {
            $sheet->unmergeCells($range);
        }
    }

    private static function buildCategoryTree(array $results): array
    {
        $categories = [];
        foreach ($results as $row) {
            $categoryId = $row->category_id;
            if (!isset($categories[$categoryId])) {
                $categories[$categoryId] = [
                    'id' => $categoryId,
                    'nama_kategori' => $row->nama_kategori,
                    'urutan' => $row->category_urutan,
                    'subitems' => [],
                ];
            }

            $subitemId = $row->subitem_id;
            if (!isset($categories[$categoryId]['subitems'][$subitemId])) {
                $categories[$categoryId]['subitems'][$subitemId] = [
                    'nama_subitem' => $row->nama_subitem,
                    'urutan' => $row->subitem_urutan,
                    'months' => [],
                ];
            }
            $categories[$categoryId]['subitems'][$subitemId]['months'][$row->bulan_ke] = $row->status;
        }

        usort($categories, static fn ($a, $b) => $a['urutan'] <=> $b['urutan']);
        foreach ($categories as &$category) {
            uasort($category['subitems'], static fn ($a, $b) => $a['urutan'] <=> $b['urutan']);
        }
        unset($category);

        return $categories;
    }

    /**
     * Isi sheet template untuk satu inspeksi. Dipakai exportInspection dan exportLabItems.
     *
     * @param array<int, object> $results
     * @param array<string, mixed> $staff
     */
    private function fillInspectionSheet(
        Spreadsheet $workbook,
        Worksheet $sheet,
        object $inspection,
        array $results,
        array $staff,
        bool $styleHeader,
        bool $clearAllHeader,
        bool $postProcessFonts
    ): void {
        $categories = self::buildCategoryTree($results);

        $year = (int) date('Y', strtotime((string) $inspection->tanggal_inspeksi));

        $sheet->getCell('A7')->setValue('');
        $sheet->getCell('A8')->setValue('');

        $sheet->getCell('D1')->setValue('LABORATORIUM/BENGKEL ' . strtoupper((string) self::jsOr($inspection->nama_lab, '')));
        $sheet->getCell('A5')->setValue('Tahun ' . $year);
        $sheet->getCell('A7')->setValue(
            'Nama alat : ' . $inspection->nama_barang .
            (self::jsTruthy($inspection->kode_barang) ? ' (' . $inspection->kode_barang . ')' : '')
        );
        $sheet->getCell('A8')->setValue('Pembuat alat : ' . self::jsOr($inspection->pembuat_alat, '-'));

        if (self::jsTruthy($inspection->tanggal_pembelian)) {
            $sheet->getCell('G8')->setValue('Tanggal Pembelian : ' . self::formatDate($inspection->tanggal_pembelian));
        }

        if (self::jsTruthy($inspection->lokasi) && preg_match('/Gedung\s+(\S+)\s+Lantai\s+(\d+)(?:\s+Ruang\s+(.+))?/i', (string) $inspection->lokasi, $match)) {
            $sheet->getCell('S7')->setValue('Gedung: ' . $match[1]);
            $sheet->getCell('S8')->setValue('Lantai   : ' . $match[2]);
            if (isset($match[3])) {
                $sheet->getCell('S6')->setValue('Ruang: ' . trim($match[3]));
            }
        }

        // Unmerge header lama (rentang yang tidak ada diabaikan seperti try/catch di JS).
        for ($c = 9; $c <= 24; $c++) {
            self::unmergeIfExists($sheet, Coordinate::stringFromColumnIndex($c) . '9:' . Coordinate::stringFromColumnIndex(min($c + 3, 24)) . '9');
        }
        for ($c = 9; $c <= 24; $c++) {
            self::unmergeIfExists($sheet, Coordinate::stringFromColumnIndex($c) . '10:' . Coordinate::stringFromColumnIndex(min($c + 1, 24)) . '10');
        }

        if ($clearAllHeader) {
            for ($c = 9; $c <= 24; $c++) {
                $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '9')->setValue(null);
                $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '10')->setValue(null);
            }
        }

        $headerFont = ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'color' => ['argb' => 'FF000000']];
        foreach (self::MONTH_COLS as $index => [$bCol, $kCol]) {
            $bLetter = Coordinate::stringFromColumnIndex($bCol);
            $kLetter = Coordinate::stringFromColumnIndex($kCol);

            $sheet->getCell($bLetter . '9')->setValue('Bulan ke-' . ($index + 1));
            if (!$clearAllHeader) {
                $sheet->getCell($kLetter . '9')->setValue(null);
            }
            $sheet->mergeCells($bLetter . '9:' . $kLetter . '9');
            $sheet->getCell($bLetter . '10')->setValue('B');
            $sheet->getCell($kLetter . '10')->setValue('K');

            if ($styleHeader) {
                $sheet->getCell($bLetter . '9')->getStyle()->applyFromArray(['font' => $headerFont, 'borders' => self::thinBorder()]);
                $sheet->getCell($kLetter . '9')->getStyle()->applyFromArray(['borders' => self::thinBorder()]);
                $sheet->getCell($bLetter . '10')->getStyle()->applyFromArray(['font' => $headerFont, 'borders' => self::thinBorder()]);
                $sheet->getCell($kLetter . '10')->getStyle()->applyFromArray(['font' => $headerFont, 'borders' => self::thinBorder()]);
            } else {
                // ExcelJS tidak menyalin font master ke sel gabungan pada alur export-lab.
                // PhpSpreadsheet menyalinnya, jadi kembalikan font baris 9 ke default.
                $plainHeaderFont = ['name' => 'Calibri', 'size' => 11, 'bold' => false];
                $sheet->getCell($bLetter . '9')->getStyle()->applyFromArray(['font' => $plainHeaderFont]);
                $sheet->getCell($kLetter . '9')->getStyle()->applyFromArray(['font' => $plainHeaderFont]);
                $sheet->getCell($bLetter . '10')->getStyle()->applyFromArray(['font' => $headerFont]);
                $sheet->getCell($kLetter . '10')->getStyle()->applyFromArray(['font' => $headerFont]);
            }
        }

        for ($c = 21; $c <= 24; $c++) {
            $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '9')->setValue(null);
            $sheet->getCell(Coordinate::stringFromColumnIndex($c) . '10')->setValue(null);
        }

        // Baris dinamis 11-41.
        $totalDataRowsNeeded = 0;
        foreach ($categories as $category) {
            $totalDataRowsNeeded += 1 + count($category['subitems']);
        }

        if ($totalDataRowsNeeded < self::TEMPLATE_DATA_ROWS) {
            $sheet->removeRow(11 + $totalDataRowsNeeded, self::TEMPLATE_DATA_ROWS - $totalDataRowsNeeded);
        } elseif ($totalDataRowsNeeded > self::TEMPLATE_DATA_ROWS) {
            $sheet->insertNewRowBefore(41, $totalDataRowsNeeded - self::TEMPLATE_DATA_ROWS);
        }

        $lastFooterRow = 12 + $totalDataRowsNeeded + 14;
        for ($r = 11; $r <= min($lastFooterRow, $sheet->getHighestRow()); $r++) {
            self::unmergeIfExists($sheet, 'A' . $r . ':H' . $r);
            self::unmergeIfExists($sheet, 'A' . $r . ':X' . $r);
        }

        $currentRowNum = 11;
        $thin = self::thinBorder();

        foreach ($categories as $category) {
            $sheet->getRowDimension($currentRowNum)->setRowHeight(20);
            self::unmergeIfExists($sheet, 'A' . $currentRowNum . ':H' . $currentRowNum);
            self::unmergeIfExists($sheet, 'A' . $currentRowNum . ':X' . $currentRowNum);

            $sheet->getCell('A' . $currentRowNum)->setValue($category['nama_kategori']);
            $sheet->getCell('A' . $currentRowNum)->getStyle()->applyFromArray([
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFD9D9D9']],
                'font' => ['bold' => true, 'size' => 11, 'name' => 'Calibri', 'color' => ['argb' => 'FF000000']],
                'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
                'borders' => $thin,
            ]);
            $sheet->mergeCells('A' . $currentRowNum . ':X' . $currentRowNum);
            $currentRowNum++;

            foreach ($category['subitems'] as $subitem) {
                $sheet->getRowDimension($currentRowNum)->setRowHeight(28.5);
                $sheet->getStyle('A' . $currentRowNum . ':X' . $currentRowNum)->applyFromArray([
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']],
                    'font' => ['bold' => false, 'size' => 11, 'name' => 'Calibri', 'color' => ['argb' => 'FF000000']],
                    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
                    'borders' => $thin,
                ]);

                self::unmergeIfExists($sheet, 'A' . $currentRowNum . ':H' . $currentRowNum);
                $sheet->mergeCells('A' . $currentRowNum . ':H' . $currentRowNum);
                $sheet->getCell('A' . $currentRowNum)->setValue($subitem['nama_subitem']);
                $sheet->getCell('A' . $currentRowNum)->getStyle()->applyFromArray([
                    'alignment' => [
                        'horizontal' => Alignment::HORIZONTAL_LEFT,
                        'vertical' => Alignment::VERTICAL_TOP,
                        'wrapText' => true,
                    ],
                ]);

                foreach (self::MONTH_COLS as $index => [$bCol, $kCol]) {
                    $status = self::jsOr($subitem['months'][$index + 1] ?? null, '');
                    if ($status === 'B') {
                        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($bCol) . $currentRowNum);
                        $cell->setValue("\u{2713}");
                        $cell->getStyle()->applyFromArray([
                            'font' => ['size' => 14, 'name' => 'Calibri', 'bold' => true, 'color' => ['argb' => 'FF008000']],
                        ]);
                    } elseif ($status === 'K') {
                        $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($kCol) . $currentRowNum);
                        $cell->setValue("\u{2717}");
                        $cell->getStyle()->applyFromArray([
                            'font' => ['size' => 14, 'name' => 'Calibri', 'bold' => true, 'color' => ['argb' => 'FFFF0000']],
                        ]);
                    }
                }
                $currentRowNum++;
            }
        }

        // Footer dinamis.
        $tglInspeksiRow = 12 + $totalDataRowsNeeded;
        $inspectorNameRow = $tglInspeksiRow + 1;
        $kalabNameRow = $tglInspeksiRow + 13;
        $kalabNipRow = $tglInspeksiRow + 14;

        self::unmergeIfExists($sheet, 'A' . $tglInspeksiRow . ':H' . $tglInspeksiRow);
        self::unmergeIfExists($sheet, 'A' . $inspectorNameRow . ':H' . $inspectorNameRow);
        $sheet->mergeCells('A' . $tglInspeksiRow . ':H' . $tglInspeksiRow);
        $sheet->mergeCells('A' . $inspectorNameRow . ':H' . $inspectorNameRow);

        $footerTextStyle = [
            'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => false, 'color' => ['argb' => 'FF000000']],
            'fill' => ['fillType' => Fill::FILL_NONE],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
        ];
        $sheet->getCell('A' . $tglInspeksiRow)->setValue('Tgl Inspeksi      : ' . self::formatDate($inspection->tanggal_inspeksi));
        $sheet->getCell('A' . $tglInspeksiRow)->getStyle()->applyFromArray($footerTextStyle);
        $sheet->getCell('A' . $inspectorNameRow)->setValue('Diinspeksi oleh: ' . self::jsOr($inspection->inspector_name, '-'));
        $sheet->getCell('A' . $inspectorNameRow)->getStyle()->applyFromArray($footerTextStyle);

        // Border kanan X6:X10 mengganti seluruh border seperti assignment JS.
        for ($r = 6; $r <= 10; $r++) {
            $sheet->getStyle('X' . $r)->applyFromArray([
                'borders' => [
                    'left' => ['borderStyle' => Border::BORDER_NONE],
                    'right' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']],
                    'top' => ['borderStyle' => Border::BORDER_NONE],
                    'bottom' => ['borderStyle' => Border::BORDER_NONE],
                ],
            ]);
        }
        $sheet->getStyle('U9:X10')->applyFromArray(['borders' => $thin]);

        $underlineFont = ['name' => 'Calibri', 'size' => 11, 'bold' => true, 'underline' => Font::UNDERLINE_SINGLE, 'color' => ['argb' => 'FF000000']];
        if (self::jsTruthy($staff['penanggung_jawab'] ?? null)) {
            $sheet->getCell('A' . $kalabNameRow)->setValue($staff['penanggung_jawab']);
            $sheet->getCell('A' . $kalabNameRow)->getStyle()->applyFromArray(['font' => $underlineFont]);
        }
        if (self::jsTruthy($staff['nip'] ?? null)) {
            $sheet->getCell('A' . $kalabNipRow)->setValue('NIP. ' . $staff['nip']);
            $sheet->getCell('A' . $kalabNipRow)->getStyle()->applyFromArray([
                'font' => ['name' => 'Calibri', 'size' => 11, 'bold' => false, 'color' => ['argb' => 'FF000000']],
            ]);
        }
        if (self::jsTruthy($staff['plp_name'] ?? null)) {
            $sheet->getCell('G' . $kalabNameRow)->setValue($staff['plp_name']);
            $sheet->getCell('G' . $kalabNameRow)->getStyle()->applyFromArray(['font' => $underlineFont]);
            $sheet->getCell('G' . $kalabNipRow)->setValue('NIP. ' . self::jsOr($staff['plp_nip'], '-'));
        }
        if (self::jsTruthy($staff['teknisi_name'] ?? null)) {
            $sheet->getCell('P' . $kalabNameRow)->setValue($staff['teknisi_name']);
            $sheet->getCell('P' . $kalabNameRow)->getStyle()->applyFromArray(['font' => $underlineFont]);
            $sheet->getCell('P' . $kalabNipRow)->setValue('NIP. ' . self::jsOr($staff['teknisi_nip'], '-'));
        }

        if (self::jsTruthy($inspection->foto)) {
            $photoPath = self::resolveUploadPath($inspection->foto);
            if ($photoPath !== null) {
                self::addImage($sheet, $photoPath, 'Y7', 240, 180);
            }
        }

        if ($postProcessFonts) {
            for ($col = 1; $col <= 12; $col++) {
                $cell = $sheet->getCell(Coordinate::stringFromColumnIndex($col) . '6');
                $cell->getStyle()->applyFromArray([
                    'font' => ['color' => ['argb' => 'FF000000']],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFFFFFF']],
                ]);
            }
            $plainFont = ['name' => 'Calibri', 'size' => 11, 'bold' => false, 'color' => ['argb' => 'FF000000']];
            foreach (['C7', 'C8', 'L8'] as $address) {
                $sheet->getCell($address)->getStyle()->applyFromArray(['font' => $plainFont]);
            }
            // Source tidak menentukan bold untuk A7/A8/K8, jadi biarkan bold bawaan template.
            $inheritedBoldFont = ['name' => 'Calibri', 'size' => 11, 'color' => ['argb' => 'FF000000']];
            foreach (['A7', 'A8', 'K8'] as $address) {
                $sheet->getCell($address)->getStyle()->applyFromArray(['font' => $inheritedBoldFont]);
            }
        }
    }

    private static function addImage(Worksheet $sheet, string $path, string $coordinates, int $width, int $height): void
    {
        $drawing = new Drawing();
        $drawing->setName('Foto Inspeksi');
        $drawing->setDescription('Foto Inspeksi');
        $drawing->setPath($path);
        $drawing->setCoordinates($coordinates);
        $drawing->setWidth($width);
        $drawing->setHeight($height);
        $drawing->setWorksheet($sheet);
    }

    private static function addLogo(Worksheet $sheet, string $path): void
    {
        $drawing = new Drawing();
        $drawing->setName('Logo PPNS');
        $drawing->setDescription('Logo PPNS');
        $drawing->setPath($path);
        $drawing->setCoordinates('B1');
        $drawing->setWidth(60);
        $drawing->setHeight(70);
        $drawing->setOffsetY(4); // tl.row = 0.2 pada source
        $drawing->setWorksheet($sheet);
    }

    // ---------------------------------------------------------------------
    // DATA HELPERS (LAPORAN DOCX)
    // ---------------------------------------------------------------------

    private static function normalizeSemester(mixed $value): string
    {
        return strtoupper(trim((string) self::jsOr($value, '')));
    }

    private static function isDamagedByHalfOrMore(mixed $totalResults, mixed $damagedResults): bool
    {
        $total = (int) $totalResults;

        return $total > 0 && ((int) $damagedResults) / $total >= 0.5;
    }

    private static function joinDistinct(array $values): string
    {
        $list = [];
        foreach ($values as $value) {
            if (self::jsTruthy($value)) {
                $list[] = (string) $value;
            }
        }
        $list = array_values(array_unique($list));

        return $list ? implode('; ', $list) : '-';
    }

    private static function getLaboratoryStaffColumns(): array
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }

        $columns = DB::select('SHOW COLUMNS FROM laboratories');
        $names = [];
        foreach ($columns as $column) {
            $names[$column->Field] = true;
        }

        return $cache = [
            'kalab' => isset($names['kalab_id']) ? 'kalab_id' : null,
            'plp' => isset($names['plp_id']) ? 'plp_id' : (isset($names['plp1_id']) ? 'plp1_id' : null),
            'teknisi' => isset($names['teknisi_id']) ? 'teknisi_id' : (isset($names['plp2_id']) ? 'plp2_id' : null),
        ];
    }

    private static function getAllLaboratories(): array
    {
        $staff = self::getLaboratoryStaffColumns();
        $kalabJoin = $staff['kalab'] ? 'LEFT JOIN users kalab ON l.' . $staff['kalab'] . ' = kalab.id' : '';
        $plpJoin = $staff['plp'] ? 'LEFT JOIN users plp ON l.' . $staff['plp'] . ' = plp.id' : '';
        $teknisiJoin = $staff['teknisi'] ? 'LEFT JOIN users teknisi ON l.' . $staff['teknisi'] . ' = teknisi.id' : '';
        $kalabSelect = $staff['kalab'] ? 'kalab.name AS kalab_name, kalab.nip AS kalab_nip' : 'NULL AS kalab_name, NULL AS kalab_nip';
        $plpSelect = $staff['plp'] ? 'plp.name AS plp_name, plp.nip AS plp_nip' : 'NULL AS plp_name, NULL AS plp_nip';
        $teknisiSelect = $staff['teknisi'] ? 'teknisi.name AS teknisi_name, teknisi.nip AS teknisi_nip' : 'NULL AS teknisi_name, NULL AS teknisi_nip';

        return DB::select(
            'SELECT l.*, ' . $kalabSelect . ', ' . $plpSelect . ', ' . $teknisiSelect . '
            FROM laboratories l
            ' . $kalabJoin . '
            ' . $plpJoin . '
            ' . $teknisiJoin . '
            ORDER BY l.nama_lab ASC'
        );
    }

    private static function getSemesterInspections($labId, array $itemIds, $tahun, $semester): array
    {
        if (count($itemIds) === 0) {
            return [];
        }

        $itemPlaceholders = implode(',', array_fill(0, count($itemIds), '?'));

        return DB::select(
            'SELECT i.id, i.laboratory_id, i.item_id, i.tahun, i.semester, i.tanggal_inspeksi,
                    it.nama_barang, it.kode_barang
            FROM inspections i
            JOIN items it ON i.item_id = it.id
            WHERE i.laboratory_id = ?
              AND i.item_id IN (' . $itemPlaceholders . ')
              AND i.tahun = ?
              AND i.semester = ?
            ORDER BY it.nama_barang ASC',
            array_merge([$labId], $itemIds, [$tahun, $semester])
        );
    }

    private static function isLabSemesterComplete(object $lab, $tahun, $semester): array
    {
        $itemIds = Legacy::csvIds($lab->item_ids ?? null);
        if (count($itemIds) === 0) {
            return ['complete' => false, 'inspections' => []];
        }

        $inspections = self::getSemesterInspections($lab->id, $itemIds, $tahun, $semester);
        $inspectedItemIds = [];
        foreach ($inspections as $inspection) {
            $inspectedItemIds[(int) $inspection->item_id] = true;
        }
        $allItemsInspected = true;
        foreach ($itemIds as $itemId) {
            if (!isset($inspectedItemIds[(int) $itemId])) {
                $allItemsInspected = false;
                break;
            }
        }

        if (!$allItemsInspected || count($inspections) === 0) {
            return ['complete' => false, 'inspections' => []];
        }

        $inspectionIds = array_map(static fn ($inspection) => $inspection->id, $inspections);
        $idPlaceholders = implode(',', array_fill(0, count($inspectionIds), '?'));
        $reviewRows = DB::select(
            'SELECT inspection_id,
                    COUNT(DISTINCT bulan_ke) AS total_months,
                    SUM(CASE WHEN review_status = \'APPROVED\' THEN 1 ELSE 0 END) AS approved_months
            FROM inspection_monthly_reviews
            WHERE inspection_id IN (' . $idPlaceholders . ')
              AND bulan_ke BETWEEN 1 AND 6
            GROUP BY inspection_id',
            $inspectionIds
        );

        $reviewMap = [];
        foreach ($reviewRows as $row) {
            $reviewMap[(int) $row->inspection_id] = $row;
        }

        $allReviewsApproved = true;
        foreach ($inspectionIds as $inspectionId) {
            $review = $reviewMap[(int) $inspectionId] ?? null;
            if (!$review || (int) $review->total_months !== 6 || (int) $review->approved_months !== 6) {
                $allReviewsApproved = false;
                break;
            }
        }

        return [
            'complete' => $allReviewsApproved,
            'inspections' => $allReviewsApproved ? $inspections : [],
        ];
    }

    private static function getDamagedInspectionStats(array $inspectionIds): array
    {
        if (count($inspectionIds) === 0) {
            return [];
        }

        $idPlaceholders = implode(',', array_fill(0, count($inspectionIds), '?'));
        $rows = DB::select(
            'SELECT inspection_id,
                    COUNT(*) AS total_results,
                    SUM(CASE WHEN status = \'K\' THEN 1 ELSE 0 END) AS damaged_results,
                    MIN(CASE WHEN status = \'K\' THEN created_at ELSE NULL END) AS first_damage_date
            FROM inspection_results
            WHERE inspection_id IN (' . $idPlaceholders . ')
            GROUP BY inspection_id',
            $inspectionIds
        );

        $map = [];
        foreach ($rows as $row) {
            $map[(int) $row->inspection_id] = $row;
        }

        return $map;
    }

    private static function getDamageReportForInspection($inspectionId): ?array
    {
        $reports = DB::select(
            'SELECT dr.*, ir.created_at AS result_updated_at
            FROM inspection_damage_reports dr
            JOIN inspection_results ir ON dr.inspection_result_id = ir.id
            WHERE dr.inspection_id = ?
            ORDER BY dr.created_at DESC, dr.id DESC',
            [$inspectionId]
        );

        if (count($reports) === 0) {
            return null;
        }

        $latest = $reports[0];
        $firstWithPhoto = $latest;
        foreach ($reports as $report) {
            if (self::jsTruthy($report->foto)) {
                $firstWithPhoto = $report;
                break;
            }
        }

        return [
            'tanggal_ditemukan' => self::jsOr(self::jsOr($latest->created_at, $latest->result_updated_at), null),
            'kondisi_kerusakan' => self::joinDistinct(array_map(static fn ($report) => $report->kondisi_kerusakan, $reports)),
            'penyebab_kerusakan' => self::joinDistinct(array_map(static fn ($report) => $report->penyebab_kerusakan, $reports)),
            'status_peralatan' => self::jsOr($latest->status_peralatan, '-'),
            'foto' => self::jsOr($firstWithPhoto->foto, null),
        ];
    }

    private static function buildDamagedLabsData($tahun, $semester): array
    {
        $labs = self::getAllLaboratories();
        $exportLabs = [];

        foreach ($labs as $lab) {
            $state = self::isLabSemesterComplete($lab, $tahun, $semester);
            if (!$state['complete']) {
                continue;
            }

            $inspections = $state['inspections'];
            $statsMap = self::getDamagedInspectionStats(array_map(static fn ($inspection) => $inspection->id, $inspections));
            $damagedItems = [];

            foreach ($inspections as $inspection) {
                $stats = $statsMap[(int) $inspection->id] ?? null;
                if (!$stats || !self::isDamagedByHalfOrMore($stats->total_results, $stats->damaged_results)) {
                    continue;
                }

                $damagedItems[] = [
                    'inspection' => $inspection,
                    'stats' => $stats,
                    'damageReport' => self::getDamageReportForInspection($inspection->id),
                ];
            }

            if (count($damagedItems) > 0) {
                $exportLabs[] = ['lab' => $lab, 'damagedItems' => $damagedItems];
            }
        }

        return $exportLabs;
    }

    private static function buildRepairLabsData($tahun, $semester): array
    {
        $labs = self::getAllLaboratories();
        $exportLabs = [];

        foreach ($labs as $lab) {
            $state = self::isLabSemesterComplete($lab, $tahun, $semester);
            if (!$state['complete']) {
                continue;
            }

            $inspections = $state['inspections'];
            $statsMap = self::getDamagedInspectionStats(array_map(static fn ($inspection) => $inspection->id, $inspections));
            $repairItems = [];

            foreach ($inspections as $inspection) {
                $stats = $statsMap[(int) $inspection->id] ?? null;
                if (!$stats || !self::isDamagedByHalfOrMore($stats->total_results, $stats->damaged_results)) {
                    continue;
                }

                $damageReport = self::getDamageReportForInspection($inspection->id);
                if ($damageReport !== null) {
                    $repairItems[] = [
                        'inspection' => $inspection,
                        'stats' => $stats,
                        'damageReport' => $damageReport,
                    ];
                }
            }

            if (count($repairItems) > 0) {
                $exportLabs[] = ['lab' => $lab, 'repairItems' => $repairItems];
            }
        }

        return $exportLabs;
    }

    // ---------------------------------------------------------------------
    // DOCX: XML DASAR
    // ---------------------------------------------------------------------

    private static function escapeXml(mixed $value): string
    {
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) ($value ?? ''));

        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            (string) $text
        );
    }

    private static function replaceText(string $xml, string $search, mixed $replacement): string
    {
        return str_replace($search, self::escapeXml($replacement), $xml);
    }

    private static function replaceFirst(string $xml, string $search, mixed $replacement): string
    {
        $position = strpos($xml, $search);
        if ($position === false) {
            return $xml;
        }

        return substr_replace($xml, self::escapeXml($replacement), $position, strlen($search));
    }

    private static function clearFirst(string $xml, string $search): string
    {
        return self::replaceFirst($xml, $search, '');
    }

    private static function clearFirstTextRun(string $xml, string $text): string
    {
        $escaped = preg_quote($text, '~');

        return preg_replace('~<w:t(?![A-Za-z])([^>]*)>' . $escaped . '</w:t>~', '<w:t$1></w:t>', $xml, 1) ?? $xml;
    }

    /** @return array<int, array{matchStart:int, innerStart:int, innerEnd:int, text:string}> */
    private static function getRunTextSpans(string $xml): array
    {
        // Hindari mencocokkan <w:tc>, <w:tr>, <w:tbl>, dll. sebagai <w:t>.
        if (!preg_match_all('~<w:t(?![A-Za-z])([^>]*)>([\s\S]*?)</w:t>~', $xml, $matches, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $spans = [];
        foreach ($matches[0] as $index => $full) {
            $spans[] = [
                'matchStart' => $full[1],
                'innerStart' => $full[1] + strlen('<w:t' . $matches[1][$index][0] . '>'),
                'innerEnd' => $full[1] + strlen($full[0]) - strlen('</w:t>'),
                'text' => $matches[2][$index][0],
            ];
        }

        return $spans;
    }

    /** @param array<int, array{matchStart:int, innerStart:int, innerEnd:int, text:string}> $spans */
    private static function findFlattenedMatch(array $spans, string $searchText): ?array
    {
        $flat = '';
        foreach ($spans as $span) {
            $flat .= $span['text'];
        }

        $start = strpos($flat, $searchText);
        if ($start === false) {
            return null;
        }

        $end = $start + strlen($searchText);
        $position = 0;
        $startSpanIndex = -1;
        $endSpanIndex = -1;

        foreach ($spans as $index => $span) {
            $length = strlen($span['text']);
            if ($startSpanIndex === -1 && $start < $position + $length) {
                $startSpanIndex = $index;
            }
            if ($endSpanIndex === -1 && $end <= $position + $length) {
                $endSpanIndex = $index;
                break;
            }
            $position += $length;
        }

        if ($startSpanIndex === -1 || $endSpanIndex === -1) {
            return null;
        }

        return [
            'startSpanIdx' => $startSpanIndex,
            'endSpanIdx' => $endSpanIndex,
            'matchStart' => $spans[$startSpanIndex]['matchStart'],
        ];
    }

    private static function replaceTextAcrossRuns(string $xml, string $searchText, mixed $replacementText): string
    {
        $spans = self::getRunTextSpans($xml);
        $match = self::findFlattenedMatch($spans, $searchText);
        if ($match === null) {
            return $xml;
        }

        $result = $xml;
        for ($i = $match['endSpanIdx']; $i >= $match['startSpanIdx']; $i--) {
            $span = $spans[$i];
            $newInner = $i === $match['startSpanIdx'] ? self::escapeXml($replacementText) : '';
            $result = substr_replace($result, $newInner, $span['innerStart'], $span['innerEnd'] - $span['innerStart']);
        }

        return $result;
    }

    private static function replaceAllTextAcrossRuns(string $xml, string $searchText, mixed $replacementText): string
    {
        $result = $xml;
        while (true) {
            $next = self::replaceTextAcrossRuns($result, $searchText, $replacementText);
            if ($next === $result) {
                break;
            }
            $result = $next;
        }

        return $result;
    }

    private static function findParagraphStartForFlattenedText(string $xml, string $searchText): int
    {
        $spans = self::getRunTextSpans($xml);
        $match = self::findFlattenedMatch($spans, $searchText);
        if ($match === null) {
            return -1;
        }

        $targetPosition = $match['matchStart'];
        if (!preg_match_all('~<w:p(?=[\s/>])~', $xml, $matches, PREG_OFFSET_CAPTURE)) {
            return -1;
        }

        $paragraphStart = -1;
        foreach ($matches[0] as $found) {
            if ($found[1] >= $targetPosition) {
                break;
            }
            $paragraphStart = $found[1];
        }

        return $paragraphStart;
    }

    private static function docParagraph(mixed $text, array $options = []): string
    {
        $bold = !empty($options['bold']) ? '<w:b/>' : '';
        $align = !empty($options['align']) ? '<w:jc w:val="' . $options['align'] . '"/>' : '';
        $spacing = !empty($options['spacingAfter']) ? '<w:spacing w:after="' . $options['spacingAfter'] . '"/>' : '';
        $size = !empty($options['size'])
            ? '<w:sz w:val="' . $options['size'] . '"/><w:szCs w:val="' . $options['size'] . '"/>'
            : '';

        return "\n      <w:p>\n        <w:pPr>" . $align . $spacing . "</w:pPr>\n        <w:r>\n          <w:rPr>" . $bold . $size . "</w:rPr>\n"
            . '          <w:t xml:space="preserve">' . self::escapeXml($text) . "</w:t>\n        </w:r>\n      </w:p>";
    }

    private static function docCell(mixed $content, array $options = []): string
    {
        $width = !empty($options['width']) ? '<w:tcW w:w="' . $options['width'] . '" w:type="dxa"/>' : '';
        $shade = !empty($options['shade']) ? '<w:shd w:fill="' . $options['shade'] . '"/>' : '';
        $body = is_array($content)
            ? implode('', $content)
            : self::docParagraph(self::jsOr($content, '-'), ['size' => 18]);

        return '<w:tc><w:tcPr>' . $width . $shade . '</w:tcPr>' . $body . '</w:tc>';
    }

    private static function plainCellParagraph(mixed $text): string
    {
        return "\n      <w:p>\n        <w:pPr>\n"
            . '          <w:spacing w:after="0" w:line="240" w:lineRule="auto"/>' . "\n"
            . '          <w:rPr><w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
            . '<w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>' . "\n"
            . "        </w:pPr>\n        <w:r>\n          <w:rPr>"
            . '<w:rFonts w:ascii="Times New Roman" w:hAnsi="Times New Roman" w:cs="Times New Roman"/>'
            . '<w:sz w:val="20"/><w:szCs w:val="20"/></w:rPr>' . "\n"
            . '          <w:t xml:space="preserve">' . self::escapeXml(self::jsOr($text, '-')) . "</w:t>\n        </w:r>\n      </w:p>";
    }

    private static function setCellContent(string $cellXml, mixed $value): string
    {
        $tcPr = '<w:tcPr/>';
        if (preg_match('~<w:tcPr[\s\S]*?</w:tcPr>~', $cellXml, $found)) {
            $tcPr = $found[0];
        }
        $body = is_array($value) ? implode('', $value) : self::plainCellParagraph($value);

        return '<w:tc>' . $tcPr . $body . '</w:tc>';
    }

    private static function replaceRowCellBlock(string $rowXml, string $cellsXml): string
    {
        $firstCellStart = strpos($rowXml, '<w:tc');
        $lastCellEnd = strrpos($rowXml, '</w:tc>');
        if ($firstCellStart === false || $lastCellEnd === false) {
            return $rowXml;
        }

        return substr($rowXml, 0, $firstCellStart) . $cellsXml . substr($rowXml, $lastCellEnd + strlen('</w:tc>'));
    }

    private static function replaceRowCells(string $rowXml, array $values): string
    {
        if (!preg_match_all('~<w:tc[\s\S]*?</w:tc>~', $rowXml, $matches)) {
            return $rowXml;
        }

        $nextCells = '';
        foreach ($matches[0] as $index => $cell) {
            $nextCells .= self::setCellContent($cell, $values[$index] ?? '');
        }

        return self::replaceRowCellBlock($rowXml, $nextCells);
    }

    private static function replaceRowCellAt(string $rowXml, int $cellIndex, mixed $value): string
    {
        if (!preg_match_all('~<w:tc[\s\S]*?</w:tc>~', $rowXml, $matches)) {
            return $rowXml;
        }

        $nextCells = '';
        foreach ($matches[0] as $index => $cell) {
            $nextCells .= $index === $cellIndex ? self::setCellContent($cell, $value) : $cell;
        }

        return self::replaceRowCellBlock($rowXml, $nextCells);
    }

    private static function buildDamageTableRows(string $tableXml, array $rows): string
    {
        if (!preg_match_all('~<w:tr[\s\S]*?</w:tr>~', $tableXml, $matches)) {
            return $tableXml;
        }
        $rowMatches = $matches[0];
        if (count($rowMatches) < 2) {
            return $tableXml;
        }

        $headerRow = $rowMatches[0];
        $dataTemplateRow = $rowMatches[1];
        preg_match_all('~<w:tc[\s\S]*?</w:tc>~', $dataTemplateRow, $cellMatches);
        $cellTemplates = $cellMatches[0];

        $dataRows = '';
        foreach ($rows as $row) {
            $cells = '';
            foreach ($row as $index => $value) {
                $templateCell = $cellTemplates[$index] ?? '<w:tc><w:tcPr/></w:tc>';
                $cells .= self::setCellContent($templateCell, $value);
            }
            $dataRows .= self::replaceRowCellBlock($dataTemplateRow, $cells);
        }

        return str_replace(implode('', $rowMatches), $headerRow . $dataRows, $tableXml);
    }

    private static function buildRepairTableRows(string $tableXml, array $repairItems): string
    {
        if (!preg_match_all('~<w:tr[\s\S]*?</w:tr>~', $tableXml, $matches)) {
            return $tableXml;
        }
        $rowMatches = $matches[0];
        if (count($rowMatches) < 3) {
            return $tableXml;
        }

        $headerRow = $rowMatches[0];
        $subHeaderRow = $rowMatches[1];
        $dataTemplateRow = $rowMatches[2];
        $dataRows = '';

        foreach ($repairItems as $index => $item) {
            $damageReport = $item['damageReport'] ?? [];
            $rowXml = $dataTemplateRow;
            $rowXml = self::replaceRowCellAt($rowXml, 0, (string) ($index + 1));
            $rowXml = self::replaceRowCellAt($rowXml, 1, self::jsOr($item['inspection']->nama_barang, '-'));
            $rowXml = self::replaceRowCellAt($rowXml, 2, self::jsOr($item['inspection']->kode_barang, '-'));
            $rowXml = self::replaceRowCellAt($rowXml, 3, self::jsOr($damageReport['kondisi_kerusakan'] ?? null, '-'));
            $rowXml = self::replaceRowCellAt($rowXml, 4, self::jsOr($damageReport['penyebab_kerusakan'] ?? null, '-'));
            $rowXml = self::replaceRowCellAt($rowXml, 5, self::jsOr($damageReport['status_peralatan'] ?? null, '-'));
            $rowXml = self::replaceRowCellAt($rowXml, 6, '-');
            $rowXml = self::replaceRowCellAt($rowXml, 7, '-');
            $dataRows .= $rowXml;
        }

        return str_replace(implode('', $rowMatches), $headerRow . $subHeaderRow . $dataRows, $tableXml);
    }

    private static function alignCellWidthsToGrid(string $tableXml): string
    {
        if (!preg_match_all('~<w:gridCol w:w="(\d+)"\s*/>~', $tableXml, $matches)) {
            return $tableXml;
        }
        $gridWidths = array_map('intval', $matches[1]);

        $minimumFirstColumn = 850;
        if ($gridWidths[0] < $minimumFirstColumn) {
            $deficit = $minimumFirstColumn - $gridWidths[0];
            $widestIndex = 0;
            for ($i = 1; $i < count($gridWidths); $i++) {
                if ($gridWidths[$i] > $gridWidths[$widestIndex]) {
                    $widestIndex = $i;
                }
            }
            if ($widestIndex !== 0 && $gridWidths[$widestIndex] - $deficit > 0) {
                $gridWidths[$widestIndex] -= $deficit;
                $gridWidths[0] = $minimumFirstColumn;
            }
        }

        $gridIndex = 0;
        $tableXml = preg_replace_callback(
            '~<w:gridCol w:w="\d+"\s*/>~',
            static function (array $match) use (&$gridIndex, $gridWidths) {
                return '<w:gridCol w:w="' . $gridWidths[$gridIndex++] . '"/>';
            },
            $tableXml
        );

        if (!preg_match_all('~<w:tr[\s\S]*?</w:tr>~', $tableXml, $rowMatches)) {
            return $tableXml;
        }
        $rows = $rowMatches[0];
        $newRows = [];

        foreach ($rows as $row) {
            if (!preg_match_all('~<w:tc[\s\S]*?</w:tc>~', $row, $cellMatches)) {
                $newRows[] = $row;
                continue;
            }

            $columnIndex = 0;
            $newCells = '';
            foreach ($cellMatches[0] as $cell) {
                $span = 1;
                if (preg_match('~<w:gridSpan w:val="(\d+)"\s*/>~', $cell, $spanMatch)) {
                    $span = (int) $spanMatch[1];
                }
                $slice = array_slice($gridWidths, $columnIndex, $span);
                $width = array_sum($slice) ?: 1000;
                $columnIndex += $span;

                if (preg_match('~<w:tcW[^/]*/>~', $cell)) {
                    $newCells .= preg_replace('~<w:tcW[^/]*/>~', '<w:tcW w:w="' . $width . '" w:type="dxa"/>', $cell, 1);
                } else {
                    $newCells .= str_replace('<w:tcPr>', '<w:tcPr><w:tcW w:w="' . $width . '" w:type="dxa"/>', $cell);
                }
            }
            $newRows[] = self::replaceRowCellBlock($row, $newCells);
        }

        return str_replace(implode('', $rows), implode('', $newRows), $tableXml);
    }

    private static function ensureFixedTableLayout(string $xml): string
    {
        return preg_replace_callback(
            '~<w:tblPr>([\s\S]*?)</w:tblPr>~',
            static function (array $match) {
                if (str_contains($match[1], '<w:tblLayout')) {
                    return $match[0];
                }

                return '<w:tblPr><w:tblLayout w:type="fixed"/>' . $match[1] . '</w:tblPr>';
            },
            $xml
        ) ?? $xml;
    }

    private static function ensureImageContentTypes(string $contentTypesXml): string
    {
        $xml = $contentTypesXml;
        $defaults = [
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
        ];
        foreach ($defaults as $extension => $contentType) {
            if (!str_contains($xml, 'Extension="' . $extension . '"')) {
                $xml = str_replace('</Types>', '<Default Extension="' . $extension . '" ContentType="' . $contentType . '"/></Types>', $xml);
            }
        }

        return $xml;
    }

    private static function ensureDocumentNamespaces(string $documentXml): string
    {
        $namespaces = [
            'a' => 'http://schemas.openxmlformats.org/drawingml/2006/main',
            'pic' => 'http://schemas.openxmlformats.org/drawingml/2006/picture',
            'wp' => 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing',
            'r' => 'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        ];
        preg_match('~<w:document\b[^>]*>~', $documentXml, $rootMatch);
        $rootTag = $rootMatch[0] ?? '';

        foreach ($namespaces as $prefix => $uri) {
            if (str_contains($rootTag, 'xmlns:' . $prefix . '=')) {
                continue;
            }
            $documentXml = str_replace('<w:document ', '<w:document xmlns:' . $prefix . '="' . $uri . '" ', $documentXml);
        }

        return $documentXml;
    }

    private static function pageBreak(): string
    {
        return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
    }

    private static function removeInitialPageBreaks(string $body): string
    {
        $cleaned = preg_replace(
            '~^(?:\s*<w:p>\s*<w:r>\s*<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>\s*)+~',
            '',
            $body
        ) ?? $body;

        return preg_replace(
            '~<w:p>\s*<w:r>\s*<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>~',
            '',
            (string) $cleaned
        ) ?? (string) $cleaned;
    }

    private static function removeExtraPageBreaks(string $body): string
    {
        return preg_replace_callback(
            '~(<w:p>\s*<w:r>\s*<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>\s*){2,}~',
            static function (array $match) {
                if (preg_match('~<w:p>\s*<w:r>\s*<w:br\s+w:type="page"\s*/>\s*</w:r>\s*</w:p>~', $match[0], $single)) {
                    return $single[0];
                }

                return '';
            },
            $body
        ) ?? $body;
    }

    private static function forceReportSectionToNewPage(string $body): string
    {
        $marker = '<w:t>LAPORAN PER SEMESTER P</w:t>';
        $markerIndex = strpos($body, $marker);
        if ($markerIndex === false) {
            return $body;
        }

        if (!preg_match_all('~<w:p(?=[\s/>])~', $body, $matches, PREG_OFFSET_CAPTURE)) {
            return $body;
        }
        $paragraphStart = -1;
        foreach ($matches[0] as $found) {
            if ($found[1] >= $markerIndex) {
                break;
            }
            $paragraphStart = $found[1];
        }
        if ($paragraphStart === -1) {
            return $body;
        }

        $beforeParagraph = substr($body, 0, $paragraphStart);
        if (!preg_match_all('~<w:br\s+w:type="page"\s*/>~', $beforeParagraph, $breakMatches, PREG_OFFSET_CAPTURE)) {
            return $body;
        }
        $lastPageBreak = $breakMatches[0][count($breakMatches[0]) - 1][1];

        $pageBreakStart = strrpos($beforeParagraph, '<w:p', $lastPageBreak - strlen($beforeParagraph));
        $pageBreakEnd = strpos($beforeParagraph, '</w:p>', $lastPageBreak);
        $pageBreakEnd = $pageBreakEnd === false ? false : $pageBreakEnd + strlen('</w:p>');
        if ($pageBreakStart === false || $pageBreakEnd === false) {
            return $body;
        }

        return substr($body, 0, $pageBreakStart) . substr($body, $pageBreakEnd);
    }

    private static function forceReportSectionToNewPageRepair(string $body): string
    {
        $paragraphStart = self::findParagraphStartForFlattenedText($body, 'LAPORAN PER SEMESTER P');
        if ($paragraphStart === -1) {
            return $body;
        }

        $beforeParagraph = substr($body, 0, $paragraphStart);
        if (!preg_match_all('~<w:br\s+w:type="page"\s*/>~', $beforeParagraph, $breakMatches, PREG_OFFSET_CAPTURE)) {
            return $body;
        }
        $lastPageBreak = $breakMatches[0][count($breakMatches[0]) - 1][1];

        $pageBreakStart = strrpos($beforeParagraph, '<w:p', $lastPageBreak - strlen($beforeParagraph));
        $pageBreakEnd = strpos($beforeParagraph, '</w:p>', $lastPageBreak);
        $pageBreakEnd = $pageBreakEnd === false ? false : $pageBreakEnd + strlen('</w:p>');
        if ($pageBreakStart === false || $pageBreakEnd === false) {
            return $body;
        }

        return substr($body, 0, $pageBreakStart) . substr($body, $pageBreakEnd);
    }

    private static function calculateImageDimensionsEMU(string $absolutePath, int $maxWidthEMU = 1828800, int $maxHeightEMU = 1371600): array
    {
        $dimensions = @getimagesize($absolutePath);
        if ($dimensions === false || !$dimensions[1]) {
            return ['width' => $maxWidthEMU, 'height' => $maxHeightEMU];
        }

        $aspectRatio = $dimensions[0] / $dimensions[1];
        $width = $maxWidthEMU;
        $height = (int) round($width / $aspectRatio);

        if ($height > $maxHeightEMU) {
            $height = $maxHeightEMU;
            $width = (int) round($height * $aspectRatio);
        }

        return ['width' => $width, 'height' => $height];
    }

    private static function imageParagraph(string $relId, int $docPrId, int $widthEMU, int $heightEMU): string
    {
        $scaledWidth = (int) round($widthEMU * 0.3);
        $scaledHeight = (int) round($heightEMU * 0.3);

        return "\n      <w:p>\n        <w:pPr><w:jc w:val=\"center\"/></w:pPr>\n        <w:r>\n          <w:drawing>\n"
            . '            <wp:inline xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '
            . 'distT="0" distB="0" distL="0" distR="0">' . "\n"
            . '              <wp:extent cx="' . $scaledWidth . '" cy="' . $scaledHeight . '"/>' . "\n"
            . '              <wp:effectExtent l="0" t="0" r="0" b="0"/>' . "\n"
            . '              <wp:docPr id="' . $docPrId . '" name="Foto Kerusakan ' . $docPrId . '"/>' . "\n"
            . '              <wp:cNvGraphicFramePr>' . "\n"
            . '                <a:graphicFrameLocks xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" noChangeAspect="1"/>' . "\n"
            . '              </wp:cNvGraphicFramePr>' . "\n"
            . '              <a:graphic xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main">' . "\n"
            . '                <a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n"
            . '                  <pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">' . "\n"
            . '                    <pic:nvPicPr>' . "\n"
            . '                      <pic:cNvPr id="0" name="Foto Kerusakan"/>' . "\n"
            . '                      <pic:cNvPicPr/>' . "\n"
            . '                    </pic:nvPicPr>' . "\n"
            . '                    <pic:blipFill>' . "\n"
            . '                      <a:blip r:embed="' . $relId . '"/>' . "\n"
            . '                      <a:stretch><a:fillRect/></a:stretch>' . "\n"
            . '                    </pic:blipFill>' . "\n"
            . '                    <pic:spPr>' . "\n"
            . '                      <a:xfrm>' . "\n"
            . '                        <a:off x="0" y="0"/>' . "\n"
            . '                        <a:ext cx="' . $scaledWidth . '" cy="' . $scaledHeight . '"/>' . "\n"
            . '                      </a:xfrm>' . "\n"
            . '                      <a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . "\n"
            . '                    </pic:spPr>' . "\n"
            . '                  </pic:pic>' . "\n"
            . '                </a:graphicData>' . "\n"
            . '              </a:graphic>' . "\n"
            . '            </wp:inline>' . "\n"
            . '          </w:drawing>' . "\n"
            . '        </w:r>' . "\n"
            . '      </w:p>';
    }

    private static function addImageToDocx(ZipArchive $zip, array &$imageState, ?string $filePath): string
    {
        $absolutePath = self::resolveUploadPath($filePath);
        if ($absolutePath === null) {
            return self::plainCellParagraph('-');
        }

        $extension = strtolower(ltrim((string) pathinfo($absolutePath, PATHINFO_EXTENSION), '.'));
        if (!in_array($extension, ['jpg', 'jpeg', 'png'], true)) {
            return self::plainCellParagraph('-');
        }

        [$width, $height] = array_values(self::calculateImageDimensionsEMU($absolutePath));

        $imageIndex = $imageState['nextImageIndex']++;
        $relId = 'rIdDamage' . $imageIndex;
        $mediaExtension = $extension === 'jpg' ? 'jpeg' : $extension;
        $mediaName = 'damage-' . $imageIndex . '.' . $mediaExtension;
        $imageContent = @file_get_contents($absolutePath);
        if ($imageContent === false) {
            return self::plainCellParagraph('-');
        }
        $zip->addFromString('word/media/' . $mediaName, $imageContent);
        $imageState['relsXml'] = str_replace(
            '</Relationships>',
            '<Relationship Id="' . $relId . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/' . $mediaName . '"/></Relationships>',
            $imageState['relsXml']
        );

        return self::imageParagraph($relId, 2000 + $imageIndex, $width, $height);
    }

    // ---------------------------------------------------------------------
    // DOCX: FOOTER, BODY, CLONE TEMPLATE
    // ---------------------------------------------------------------------

    private static function replaceFooterSignatureBlock(string $body, object $lab): string
    {
        if (!preg_match_all('~<w:tbl>[\s\S]*?</w:tbl>~', $body, $matches) || count($matches[0]) < 2) {
            return $body;
        }

        $footerTable = $matches[0][1];
        if (!preg_match_all('~<w:tr[\s\S]*?</w:tr>~', $footerTable, $rowMatches) || count($rowMatches[0]) === 0) {
            return $body;
        }

        $tanggal = self::formatDate(date('Y-m-d'));
        $namaLab = self::escapeXml(self::jsOr($lab->nama_lab, '-'));
        $kalabName = self::escapeXml(self::jsOr($lab->kalab_name, '-'));
        $kalabNip = self::escapeXml(self::jsOr($lab->kalab_nip, '-'));

        $newRows = [
            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr><w:p><w:r><w:t/></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>' . $tanggal . '</w:t></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Mengetahui,</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Menyetujui,</w:t></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Ketua Jurusan                </w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Kepala Lab./Bengkel ' . $namaLab . '</w:t></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr><w:p><w:r><w:t/></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr><w:p><w:r><w:t/></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr><w:p><w:r><w:t/></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr><w:p><w:r><w:t/></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>(                                      )</w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/><w:u w:val="single"/></w:rPr><w:t>' . $kalabName . '</w:t></w:r></w:p></w:tc>'
            . '</w:tr>',

            '<w:tr>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>NIP                                      </w:t></w:r></w:p></w:tc>'
            . '<w:tc><w:tcPr><w:tcW w:w="50%" w:type="pct"/><w:jc w:val="right"/></w:tcPr>'
            . '<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>NIP. ' . $kalabNip . '</w:t></w:r></w:p></w:tc>'
            . '</w:tr>',
        ];

        $newFooterTable = str_replace(implode('', $rowMatches[0]), implode('', $newRows), $footerTable);

        return str_replace($footerTable, $newFooterTable, $body);
    }

    private static function fillDamagedEquipmentTemplateBody(
        string $bodyTemplate,
        object $lab,
        array $damagedItems,
        $tahun,
        string $semester,
        ZipArchive $zip,
        array &$imageState,
        bool $skipHeader = false
    ): string {
        $rows = [];
        foreach ($damagedItems as $itemIndex => $item) {
            $damageReport = $item['damageReport'] ?? [];
            $photoXml = self::addImageToDocx($zip, $imageState, $damageReport['foto'] ?? null);
            $stats = $item['stats'] ?? [];

            $rows[] = [
                (string) ($itemIndex + 1),
                self::jsOr($item['inspection']->nama_barang, '-'),
                self::jsOr($item['inspection']->kode_barang, '-'),
                self::formatDate(self::jsOr(
                    self::jsOr($damageReport['tanggal_ditemukan'] ?? null, (is_object($stats) ? ($stats->first_damage_date ?? null) : ($stats['first_damage_date'] ?? null))),
                    $item['inspection']->tanggal_inspeksi
                )),
                self::jsOr($damageReport['kondisi_kerusakan'] ?? null, '-'),
                self::jsOr($damageReport['penyebab_kerusakan'] ?? null, '-'),
                self::jsOr($damageReport['status_peralatan'] ?? null, '-'),
                // perbaikan_upt_pp ? '-' : '-' -> selalu '-' pada source
                '-',
                // perbaikan_pihak_ketiga ? '-' : '-' -> selalu '-' pada source
                '-',
                [$photoXml],
            ];
        }

        $body = $bodyTemplate;
        $body = self::removeInitialPageBreaks($body);
        $body = self::forceReportSectionToNewPage($body);

        if (!$skipHeader) {
            $body = self::replaceTextAcrossRuns(
                $body,
                'PERIODE PELAPORAN: SEMESTER GENAP 2024/2025',
                'PERIODE PELAPORAN: SEMESTER ' . $semester . ' ' . $tahun
            );
            $body = self::replaceTextAcrossRuns(
                $body,
                'Surabaya, XX-XX-20XX',
                'Surabaya, ' . self::formatDate(date('Y-m-d'))
            );
        }
        $body = self::replaceText($body, 'Unit Kerja : Nama Lab./Bengkel', 'Unit Kerja : ' . self::jsOr($lab->nama_lab, '-'));
        $body = self::replaceText($body, 'Semester : Ganjil/Genap Tahun 20XX/20XX', 'Semester : ' . $semester . ' Tahun ' . $tahun);
        $body = self::replaceTextAcrossRuns($body, 'Nama Lab./Bengkel', $lab->nama_lab);
        $body = self::replaceTextAcrossRuns($body, 'Ganjil/Genap Tahun 20XX/20XX', $semester . ' Tahun ' . $tahun);
        $body = self::replaceFooterSignatureBlock($body, $lab);

        if (preg_match('~<w:tbl>[\s\S]*?</w:tbl>~', $body, $tableMatch)) {
            $filledTable = self::buildDamageTableRows($tableMatch[0], $rows);
            $filledTable = self::alignCellWidthsToGrid($filledTable);
            $body = str_replace($tableMatch[0], $filledTable, $body);
        }

        return $body;
    }

    private static function fillRepairTemplateBody(
        string $bodyTemplate,
        object $lab,
        array $repairItems,
        $tahun,
        string $semester,
        ZipArchive $zip,
        array &$imageState,
        bool $skipHeader = false
    ): string {
        $body = $bodyTemplate;
        $body = self::removeInitialPageBreaks($body);
        $body = self::forceReportSectionToNewPageRepair($body);
        $body = self::removeExtraPageBreaks($body);

        $body = self::replaceTextAcrossRuns(
            $body,
            'PERIODE PELAPORAN: SEMESTER GENAP 2024/2025',
            'PERIODE PELAPORAN: SEMESTER ' . $semester . ' ' . $tahun
        );
        $body = self::replaceTextAcrossRuns($body, 'Nama Lab./Bengkel', $lab->nama_lab);
        $body = self::replaceTextAcrossRuns($body, 'Ganjil/Genap Tahun 20XX/20XX', $semester . ' Tahun ' . $tahun);
        $body = self::replaceFooterSignatureBlock($body, $lab);

        if (preg_match('~<w:tbl>[\s\S]*?</w:tbl>~', $body, $tableMatch)) {
            $body = str_replace($tableMatch[0], self::buildRepairTableRows($tableMatch[0], $repairItems), $body);
        }

        return $body;
    }

    /** @return array{ZipArchive, string} */
    private static function openTemplateZip(string $templatePath): array
    {
        $temporary = tempnam(sys_get_temp_dir(), 'expdocx');
        if ($temporary === false || !copy($templatePath, $temporary)) {
            throw new \RuntimeException('Gagal menyalin template laporan.');
        }

        $zip = new ZipArchive();
        if ($zip->open($temporary) !== true) {
            @unlink($temporary);
            throw new \RuntimeException('Gagal membuka template laporan.');
        }

        return [$zip, $temporary];
    }

    private static function cloneTemplateWithDamagedEquipmentPages(array $labsData, $tahun, string $semester): string
    {
        [$zip, $temporary] = self::openTemplateZip(self::templatePath(self::TEMPLATE_DAMAGED));

        try {
            $documentXml = (string) $zip->getFromName('word/document.xml');
            $contentTypesXml = (string) $zip->getFromName('[Content_Types].xml');
            $imageState = [
                'relsXml' => (string) $zip->getFromName('word/_rels/document.xml.rels'),
                'nextImageIndex' => 1,
            ];

            preg_match('~<w:body[\s\S]*?>([\s\S]*?)</w:body>~', $documentXml, $bodyMatch);
            $bodyContent = $bodyMatch[1] ?? '';

            $sectPr = '<w:sectPr/>';
            $sectPrIndex = null;
            if (preg_match_all('~<w:sectPr[\s\S]*?</w:sectPr>~', $bodyContent, $sectMatches, PREG_OFFSET_CAPTURE)) {
                $last = $sectMatches[0][count($sectMatches[0]) - 1];
                $sectPr = $last[0];
                $sectPrIndex = $last[1];
            }
            if ($sectPrIndex !== null) {
                $bodyTemplate = substr($bodyContent, 0, $sectPrIndex) . substr($bodyContent, $sectPrIndex + strlen($sectPr));
            } else {
                $bodyTemplate = $bodyContent;
            }

            $headerTemplate = '';
            $bodyWithoutHeader = $bodyTemplate;
            $markerIndex = strpos($bodyTemplate, '<w:t>LAPORAN PER SEMESTER P</w:t>');
            if ($markerIndex !== false && preg_match_all('~<w:p(?=[\s/>])~', $bodyTemplate, $paragraphMatches, PREG_OFFSET_CAPTURE)) {
                $paragraphStart = -1;
                foreach ($paragraphMatches[0] as $found) {
                    if ($found[1] >= $markerIndex) {
                        break;
                    }
                    $paragraphStart = $found[1];
                }
                if ($paragraphStart !== -1) {
                    $headerTemplate = substr($bodyTemplate, 0, $paragraphStart);
                    $bodyWithoutHeader = substr($bodyTemplate, $paragraphStart);
                }
            }

            $logoRegex = '~<w:p[\s\S]*?<w:drawing[\s\S]*?<wp:inline[\s\S]*?<a:blip[\s\S]*?r:embed="[^"]*"[\s\S]*?</a:blip>[\s\S]*?</wp:inline>[\s\S]*?</w:drawing>[\s\S]*?</w:p>~';
            $bodyWithoutHeader = preg_replace($logoRegex, '', $bodyWithoutHeader) ?? $bodyWithoutHeader;
            $bodyWithoutHeader = preg_replace('~<w:p>\s*</w:p>~', '', $bodyWithoutHeader) ?? $bodyWithoutHeader;

            $pages = '';
            $lastIndex = count($labsData) - 1;
            foreach ($labsData as $labIndex => $entry) {
                $fullTemplate = $headerTemplate . $bodyWithoutHeader;
                $pageContent = self::fillDamagedEquipmentTemplateBody(
                    $labIndex === 0 ? $fullTemplate : $bodyWithoutHeader,
                    $entry['lab'],
                    $entry['damagedItems'],
                    $tahun,
                    $semester,
                    $zip,
                    $imageState,
                    $labIndex !== 0
                );
                $pages .= $pageContent . ($labIndex < $lastIndex ? self::pageBreak() : '');
            }

            $nextDocumentXml = preg_replace_callback(
                '~<w:body[\s\S]*?</w:body>~',
                static fn () => '<w:body>' . $pages . $sectPr . '</w:body>',
                $documentXml,
                1
            ) ?? $documentXml;
            $nextDocumentXml = self::ensureFixedTableLayout(self::ensureDocumentNamespaces($nextDocumentXml));

            $zip->addFromString('word/document.xml', $nextDocumentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $imageState['relsXml']);
            $zip->addFromString('[Content_Types].xml', self::ensureImageContentTypes($contentTypesXml));
        } finally {
            $zip->close();
        }

        $content = (string) @file_get_contents($temporary);
        @unlink($temporary);

        return $content;
    }

    private static function cloneTemplateWithRepairPages(array $labsData, $tahun, string $semester): string
    {
        [$zip, $temporary] = self::openTemplateZip(self::templatePath(self::TEMPLATE_REPAIR));

        try {
            $documentXml = (string) $zip->getFromName('word/document.xml');
            $contentTypesXml = (string) $zip->getFromName('[Content_Types].xml');
            $imageState = [
                'relsXml' => (string) $zip->getFromName('word/_rels/document.xml.rels'),
                'nextImageIndex' => 1,
            ];

            preg_match('~<w:body[\s\S]*?>([\s\S]*?)</w:body>~', $documentXml, $bodyMatch);
            $bodyContent = $bodyMatch[1] ?? '';

            $sectPr = '<w:sectPr/>';
            $sectPrIndex = null;
            if (preg_match_all('~<w:sectPr[\s\S]*?</w:sectPr>~', $bodyContent, $sectMatches, PREG_OFFSET_CAPTURE)) {
                $last = $sectMatches[0][count($sectMatches[0]) - 1];
                $sectPr = $last[0];
                $sectPrIndex = $last[1];
            }
            if ($sectPrIndex !== null) {
                $bodyTemplate = substr($bodyContent, 0, $sectPrIndex) . substr($bodyContent, $sectPrIndex + strlen($sectPr));
            } else {
                $bodyTemplate = $bodyContent;
            }

            $headerTemplate = '';
            $bodyWithoutHeader = $bodyTemplate;
            $paragraphStart = self::findParagraphStartForFlattenedText($bodyTemplate, 'LAPORAN PER SEMESTER HASIL PERBAIKAN');
            if ($paragraphStart !== -1) {
                $headerTemplate = substr($bodyTemplate, 0, $paragraphStart);
                $bodyWithoutHeader = substr($bodyTemplate, $paragraphStart);
            }

            $logoRegex = '~<w:p[\s\S]*?<w:drawing[\s\S]*?<wp:inline[\s\S]*?<a:blip[\s\S]*?r:embed="[^"]*"[\s\S]*?</a:blip>[\s\S]*?</wp:inline>[\s\S]*?</w:drawing>[\s\S]*?</w:p>~';
            $bodyWithoutHeader = preg_replace($logoRegex, '', $bodyWithoutHeader) ?? $bodyWithoutHeader;
            $bodyWithoutHeader = preg_replace('~<w:p>\s*</w:p>~', '', $bodyWithoutHeader) ?? $bodyWithoutHeader;

            $pages = '';
            $lastIndex = count($labsData) - 1;
            foreach ($labsData as $labIndex => $entry) {
                $fullTemplate = $headerTemplate . $bodyWithoutHeader;
                $pageContent = self::fillRepairTemplateBody(
                    $labIndex === 0 ? $fullTemplate : $bodyWithoutHeader,
                    $entry['lab'],
                    $entry['repairItems'],
                    $tahun,
                    $semester,
                    $zip,
                    $imageState,
                    $labIndex !== 0
                );
                $pages .= $pageContent . ($labIndex < $lastIndex ? self::pageBreak() : '');
            }

            $nextDocumentXml = preg_replace_callback(
                '~<w:body[\s\S]*?</w:body>~',
                static fn () => '<w:body>' . $pages . $sectPr . '</w:body>',
                $documentXml,
                1
            ) ?? $documentXml;
            $nextDocumentXml = self::ensureDocumentNamespaces($nextDocumentXml);

            $zip->addFromString('word/document.xml', $nextDocumentXml);
            $zip->addFromString('word/_rels/document.xml.rels', $imageState['relsXml']);
            $zip->addFromString('[Content_Types].xml', self::ensureImageContentTypes($contentTypesXml));
        } finally {
            $zip->close();
        }

        $content = (string) @file_get_contents($temporary);
        @unlink($temporary);

        return $content;
    }
}
