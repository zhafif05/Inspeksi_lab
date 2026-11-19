<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use App\Support\LegacyValidator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InspectionCriteriaController extends Controller
{
    private function jsNull(mixed $value): mixed
    {
        return $value ?: null;
    }

    private function labForItemOrError(Request $request, mixed $itemId, mixed $bodyLabId, string $notFoundMessage)
    {
        if (Legacy::user($request)['role'] === 'admin') {
            if (!Legacy::jsTruthy($bodyLabId)) {
                return Api::error('Admin harus mengisi laboratory_id', 400);
            }
            return (int) $bodyLabId;
        }

        $labs = DB::select(
            'SELECT id FROM laboratories WHERE FIND_IN_SET(?, item_ids) LIMIT 1',
            [$itemId]
        );
        if (count($labs) === 0) {
            return Api::error($notFoundMessage, 404);
        }
        return (int) $labs[0]->id;
    }

    public function createInspectionCategory(Request $request)
    {
        if ($errors = LegacyValidator::category($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $body = Legacy::body($request);
        $namaKategori = $request->input('nama_kategori');
        $deskripsi = $request->input('deskripsi');
        $urutan = $request->input('urutan');
        $itemId = $request->input('item_id');

        $lab = $this->labForItemOrError($request, $itemId, $request->input('laboratory_id'), 'Lab tidak ditemukan untuk item tersebut');
        if ($lab instanceof \Illuminate\Http\JsonResponse) {
            return $lab;
        }
        $laboratoryId = $lab;

        $existing = DB::select(
            "SELECT id FROM inspection_categories
             WHERE nama_kategori = ? AND (laboratory_id = ? OR (laboratory_id IS NULL AND created_by = ?))",
            [$namaKategori, $laboratoryId, $user['id']]
        );
        if (count($existing) > 0) {
            return Api::error("Kategori \"$namaKategori\" sudah ada", 409);
        }

        $id = DB::table('inspection_categories')->insertGetId([
            'nama_kategori' => $namaKategori,
            'deskripsi' => $this->jsNull($deskripsi),
            'urutan' => $urutan,
            'status' => 'PENDING',
            'created_by' => $user['id'],
            'laboratory_id' => $laboratoryId,
            'item_id' => $this->jsNull($itemId),
        ]);

        $data = [
            'id' => $id,
            'nama_kategori' => $namaKategori,
        ];
        if (array_key_exists('deskripsi', $body)) {
            $data['deskripsi'] = $deskripsi;
        }
        $data['urutan'] = $urutan;
        $data['status'] = 'PENDING';
        $data['item_id'] = $this->jsNull($itemId);
        $data['created_by'] = $user['id'];

        return Api::ok([
            'message' => 'Kategori inspeksi berhasil dibuat (Menunggu Persetujuan Admin)',
            'data' => $data,
        ], 201);
    }

    public function createInspectionSubitem(Request $request)
    {
        if ($errors = LegacyValidator::subitem($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $body = Legacy::body($request);
        $categoryId = $request->input('category_id');
        $namaSubitem = $request->input('nama_subitem');
        $deskripsi = $request->input('deskripsi');
        $urutan = $request->input('urutan');

        $categories = DB::select('SELECT id FROM inspection_categories WHERE id = ?', [$categoryId]);
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }

        $id = DB::table('inspection_subitems')->insertGetId([
            'category_id' => $categoryId,
            'nama_subitem' => $namaSubitem,
            'deskripsi' => $this->jsNull($deskripsi),
            'urutan' => $urutan,
            'status' => 'PENDING',
            'created_by' => $user['id'],
        ]);

        $data = [
            'id' => $id,
            'category_id' => $categoryId,
            'nama_subitem' => $namaSubitem,
        ];
        if (array_key_exists('deskripsi', $body)) {
            $data['deskripsi'] = $deskripsi;
        }
        $data['urutan'] = $urutan;
        $data['status'] = 'PENDING';

        return Api::ok([
            'message' => 'Sub-item inspeksi berhasil dibuat (Menunggu Persetujuan Admin)',
            'data' => $data,
        ], 201);
    }

    public function createInspectionCategoryWithSubitems(Request $request)
    {
        if ($errors = LegacyValidator::categoryWithSubitems($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $categories = $request->input('categories');
        $itemId = $request->input('item_id');
        $userId = $user['id'];

        $lab = $this->labForItemOrError($request, $itemId, $request->input('laboratory_id'), 'Lab tidak ditemukan untuk item tersebut');
        if ($lab instanceof \Illuminate\Http\JsonResponse) {
            return $lab;
        }
        $laboratoryId = $lab;

        $createdCategories = DB::transaction(function () use ($categories, $itemId, $userId, $laboratoryId) {
            $created = [];
            foreach ($categories as $cat) {
                $categoryId = DB::table('inspection_categories')->insertGetId([
                    'nama_kategori' => $cat['nama_kategori'],
                    'deskripsi' => $cat['deskripsi'] ?? null,
                    'urutan' => $cat['urutan'],
                    'status' => 'PENDING',
                    'created_by' => $userId,
                    'laboratory_id' => $laboratoryId,
                    'item_id' => $itemId ?: null,
                ]);

                $rows = [];
                foreach ($cat['subitems'] as $sub) {
                    $rows[] = [
                        'category_id' => $categoryId,
                        'nama_subitem' => $sub['nama_subitem'],
                        'deskripsi' => $sub['deskripsi'] ?? null,
                        'urutan' => $sub['urutan'],
                        'status' => 'PENDING',
                        'created_by' => $userId,
                    ];
                }
                DB::table('inspection_subitems')->insert($rows);

                $created[] = [
                    'id' => $categoryId,
                    'nama_kategori' => $cat['nama_kategori'],
                    'deskripsi' => $cat['deskripsi'] ?? null,
                    'urutan' => $cat['urutan'],
                    'status' => 'PENDING',
                    'item_id' => $itemId ?: null,
                    'subitems' => array_map(function ($sub, $i) use ($categoryId) {
                        return [
                            // ID palsu sengaja dipertahankan sesuai source lama.
                            'id' => $categoryId + $i + 1,
                            'category_id' => $categoryId,
                            'nama_subitem' => $sub['nama_subitem'],
                            'deskripsi' => $sub['deskripsi'] ?? null,
                            'urutan' => $sub['urutan'],
                            'status' => 'PENDING',
                        ];
                    }, $cat['subitems'], array_keys($cat['subitems'])),
                ];
            }
            return $created;
        });

        return Api::ok([
            'message' => count($categories) . ' kategori dan sub-item berhasil dibuat (Menunggu Persetujuan Admin)',
            'data' => $createdCategories,
            'total' => count($createdCategories),
        ], 201);
    }

    public function updateCategory(Request $request, $id)
    {
        if ($errors = LegacyValidator::category($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $categories = DB::select(
            'SELECT id, status, created_by, laboratory_id FROM inspection_categories WHERE id = ?',
            [$id]
        );
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }

        $cat = $categories[0];
        if ($user['role'] !== 'admin' && $cat->created_by !== $user['id'] && ($user['laboratory_id'] ?? null) !== $cat->laboratory_id) {
            return Api::error('Anda tidak memiliki akses', 403);
        }
        if ($cat->status !== 'REJECTED') {
            return Api::error('Hanya kategori REJECTED yang bisa diperbarui', 400);
        }

        DB::table('inspection_categories')->where('id', $id)->update([
            'nama_kategori' => $request->input('nama_kategori'),
            'deskripsi' => $this->jsNull($request->input('deskripsi')),
            'urutan' => $request->input('urutan'),
            'status' => 'PENDING',
            'alasan_penolakan' => null,
        ]);

        return Api::ok(['message' => 'Kategori berhasil diperbarui (Menunggu Persetujuan Admin)']);
    }

    public function updateSubitem(Request $request, $id)
    {
        if ($errors = LegacyValidator::subitem($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $subitems = DB::select(
            "SELECT si.id, si.status, si.created_by, ic.id as category_id
             FROM inspection_subitems si
             JOIN inspection_categories ic ON si.category_id = ic.id
             WHERE si.id = ?",
            [$id]
        );
        if (count($subitems) === 0) {
            return Api::error('Sub-item tidak ditemukan', 404);
        }

        $sub = $subitems[0];
        if ($user['role'] !== 'admin' && $sub->created_by !== $user['id']) {
            return Api::error('Anda tidak memiliki akses', 403);
        }
        if ($sub->status !== 'REJECTED') {
            return Api::error('Hanya sub-item REJECTED yang bisa diperbarui', 400);
        }

        DB::table('inspection_subitems')->where('id', $id)->update([
            'nama_subitem' => $request->input('nama_subitem'),
            'deskripsi' => $this->jsNull($request->input('deskripsi')),
            'urutan' => $request->input('urutan'),
            'status' => 'PENDING',
            'alasan_penolakan' => null,
        ]);

        return Api::ok(['message' => 'Sub-item berhasil diperbarui (Menunggu Persetujuan Admin)']);
    }

    public function updateCategoryWithSubitems(Request $request, $id)
    {
        if ($errors = LegacyValidator::updateCategoryWithSubitems($request)) {
            return Api::validation($errors);
        }

        $user = Legacy::user($request);
        $subitems = $request->input('subitems');
        $categories = DB::select(
            'SELECT id, status, created_by, laboratory_id FROM inspection_categories WHERE id = ?',
            [$id]
        );
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }

        $cat = $categories[0];
        if ($user['role'] !== 'admin' && $cat->created_by !== $user['id'] && ($user['laboratory_id'] ?? null) !== $cat->laboratory_id) {
            return Api::error('Anda tidak memiliki akses', 403);
        }
        if ($cat->status !== 'REJECTED') {
            return Api::error('Hanya kategori REJECTED yang bisa diperbarui', 400);
        }

        DB::transaction(function () use ($request, $id, $subitems, $user) {
            DB::table('inspection_categories')->where('id', $id)->update([
                'nama_kategori' => $request->input('nama_kategori'),
                'deskripsi' => $this->jsNull($request->input('deskripsi')),
                'urutan' => $request->input('urutan'),
                'status' => 'PENDING',
                'alasan_penolakan' => null,
            ]);

            $existingIds = [];
            foreach ($subitems as $sub) {
                if (Legacy::jsTruthy($sub['id'] ?? null)) {
                    DB::table('inspection_subitems')
                        ->where('id', $sub['id'])
                        ->where('category_id', $id)
                        ->update([
                            'nama_subitem' => $sub['nama_subitem'],
                            'deskripsi' => $sub['deskripsi'] ?? null,
                            'urutan' => $sub['urutan'],
                            'status' => 'PENDING',
                            'alasan_penolakan' => null,
                        ]);
                    $existingIds[] = $sub['id'];
                } else {
                    $existingIds[] = DB::table('inspection_subitems')->insertGetId([
                        'category_id' => $id,
                        'nama_subitem' => $sub['nama_subitem'],
                        'deskripsi' => $sub['deskripsi'] ?? null,
                        'urutan' => $sub['urutan'],
                        'status' => 'PENDING',
                        'created_by' => $user['id'],
                    ]);
                }
            }

            DB::table('inspection_subitems')
                ->where('category_id', $id)
                ->whereNotIn('id', !empty($existingIds) ? $existingIds : [0])
                ->delete();
        });

        return Api::ok(['message' => 'Kategori dan sub-item berhasil diperbarui (Menunggu Persetujuan Admin)']);
    }

    public function getMyCriteriaWithSubitems(Request $request)
    {
        $userId = Legacy::user($request)['id'];
        $categories = DB::select(
            "SELECT id, nama_kategori, deskripsi, urutan, status, alasan_penolakan, item_id
             FROM inspection_categories
             WHERE created_by = ?
             ORDER BY urutan ASC",
            [$userId]
        );

        // Ambil semua sub-item dalam satu query, lalu kelompokkan per kategori.
        // Hasilnya identik dengan query per kategori, tanpa N+1.
        $subitemsByCategory = [];
        $categoryIds = array_map(static fn ($cat) => $cat->id, $categories);
        if (count($categoryIds) > 0) {
            $placeholders = implode(',', array_fill(0, count($categoryIds), '?'));
            $allSubitems = DB::select(
                "SELECT id, nama_subitem, deskripsi, urutan, status, alasan_penolakan, category_id
                 FROM inspection_subitems
                 WHERE category_id IN ({$placeholders})
                 ORDER BY urutan ASC",
                $categoryIds
            );
            foreach ($allSubitems as $subitem) {
                $categoryId = $subitem->category_id;
                unset($subitem->category_id);
                $subitemsByCategory[$categoryId][] = $subitem;
            }
        }

        $data = array_map(static function ($cat) use ($subitemsByCategory) {
            return array_merge((array) $cat, [
                'subitems' => $subitemsByCategory[$cat->id] ?? [],
            ]);
        }, $categories);

        return Api::ok(['data' => $data, 'total' => count($data)]);
    }

    public function getPendingCategories()
    {
        $categories = DB::select(
            "SELECT c.*, u.name as created_by_name, l.nama_lab
             FROM inspection_categories c
             LEFT JOIN users u ON c.created_by = u.id
             LEFT JOIN laboratories l ON c.laboratory_id = l.id
             WHERE c.status = 'PENDING'
             ORDER BY c.created_at DESC"
        );

        return Api::ok(['data' => $categories, 'total' => count($categories)]);
    }

    public function getPendingSubitems()
    {
        $subitems = DB::select(
            "SELECT si.*, ic.nama_kategori, ic.laboratory_id, u.name as created_by_name
             FROM inspection_subitems si
             JOIN inspection_categories ic ON si.category_id = ic.id
             LEFT JOIN users u ON si.created_by = u.id
             WHERE si.status = 'PENDING'
             ORDER BY si.created_at DESC"
        );

        return Api::ok(['data' => $subitems, 'total' => count($subitems)]);
    }

    public function approveCategory(Request $request, $id)
    {
        $categories = DB::select('SELECT id, status FROM inspection_categories WHERE id = ?', [$id]);
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }
        if (!in_array($categories[0]->status, ['PENDING', 'REJECTED'], true)) {
            return Api::error("Kategori sudah memiliki status: {$categories[0]->status}", 400);
        }

        DB::table('inspection_categories')->where('id', $id)->update([
            'status' => 'APPROVED',
            'alasan_penolakan' => null,
        ]);

        return Api::ok(['message' => 'Kategori inspeksi berhasil disetujui']);
    }

    public function rejectCategory(Request $request, $id)
    {
        $categories = DB::select('SELECT id, status FROM inspection_categories WHERE id = ?', [$id]);
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }
        if (!in_array($categories[0]->status, ['PENDING', 'APPROVED'], true)) {
            return Api::error("Kategori sudah memiliki status: {$categories[0]->status}", 400);
        }

        $alasan = $request->input('alasan_penolakan');
        DB::table('inspection_categories')->where('id', $id)->update([
            'status' => 'REJECTED',
            'alasan_penolakan' => $alasan ?: null,
        ]);

        return Api::ok(['message' => 'Kategori inspeksi berhasil ditolak', 'alasan' => $alasan]);
    }

    public function approveSubitem(Request $request, $id)
    {
        $subitems = DB::select('SELECT id, status FROM inspection_subitems WHERE id = ?', [$id]);
        if (count($subitems) === 0) {
            return Api::error('Sub-item tidak ditemukan', 404);
        }
        if ($subitems[0]->status !== 'PENDING') {
            return Api::error("Sub-item sudah memiliki status: {$subitems[0]->status}", 400);
        }

        DB::table('inspection_subitems')->where('id', $id)->update(['status' => 'APPROVED']);

        return Api::ok(['message' => 'Sub-item inspeksi berhasil disetujui']);
    }

    public function rejectSubitem(Request $request, $id)
    {
        $subitems = DB::select('SELECT id, status FROM inspection_subitems WHERE id = ?', [$id]);
        if (count($subitems) === 0) {
            return Api::error('Sub-item tidak ditemukan', 404);
        }
        if ($subitems[0]->status !== 'PENDING') {
            return Api::error("Sub-item sudah memiliki status: {$subitems[0]->status}", 400);
        }

        $alasan = $request->input('alasan_penolakan');
        DB::table('inspection_subitems')->where('id', $id)->update([
            'status' => 'REJECTED',
            'alasan_penolakan' => $alasan ?: null,
        ]);

        return Api::ok(['message' => 'Sub-item inspeksi berhasil ditolak', 'alasan' => $alasan]);
    }

    public function getApprovedCategoriesWithSubitems(Request $request)
    {
        // Source lama membaca req.body pada method GET; query string tidak ikut dibaca.
        $itemId = Legacy::body($request, 'item_id');
        $user = Legacy::user($request);
        $laboratoryId = null;

        if ($user['role'] === 'admin') {
            $laboratoryId = Legacy::body($request, 'laboratory_id');
        } else {
            $labs = DB::select('SELECT id FROM laboratories WHERE FIND_IN_SET(?, item_ids) LIMIT 1', [$itemId]);
            if (count($labs) === 0) {
                return Api::error('Lab untuk item tersebut tidak ditemukan', 404);
            }
            $laboratoryId = $labs[0]->id;
        }

        if ($laboratoryId) {
            $categories = DB::select(
                "SELECT c.id, c.nama_kategori, c.deskripsi, c.urutan, c.item_id
                 FROM inspection_categories c
                 WHERE c.status = 'APPROVED' AND (c.laboratory_id = ? OR c.laboratory_id IS NULL)
                 ORDER BY c.urutan ASC",
                [$laboratoryId]
            );
        } else {
            $categories = DB::select(
                "SELECT c.id, c.nama_kategori, c.deskripsi, c.urutan, c.item_id
                 FROM inspection_categories c
                 WHERE c.status = 'APPROVED'
                 ORDER BY c.urutan ASC"
            );
        }

        $data = array_map(function ($cat) {
            $subitems = DB::select(
                "SELECT id, nama_subitem, deskripsi, urutan
                 FROM inspection_subitems
                 WHERE category_id = ? AND status = 'APPROVED'
                 ORDER BY urutan ASC",
                [$cat->id]
            );
            return array_merge((array) $cat, ['subitems' => $subitems]);
        }, $categories);

        return Api::ok(['data' => $data, 'total' => count($data)]);
    }

    public function getCategoriesByItemId(Request $request, $itemId)
    {
        $categories = DB::select(
            "SELECT id, nama_kategori, deskripsi, urutan, item_id, status, alasan_penolakan
             FROM inspection_categories
             WHERE item_id = ?
             ORDER BY urutan ASC",
            [$itemId]
        );

        $data = array_map(function ($cat) {
            $subitems = DB::select(
                "SELECT id, nama_subitem, deskripsi, urutan, status, alasan_penolakan
                 FROM inspection_subitems
                 WHERE category_id = ?
                 ORDER BY urutan ASC",
                [$cat->id]
            );
            return array_merge((array) $cat, ['subitems' => $subitems]);
        }, $categories);

        return Api::ok(['data' => $data, 'total' => count($data)]);
    }

    public function bulkApproveCategories(Request $request)
    {
        $ids = $request->input('ids');
        if (!Legacy::isJsArray($request, 'ids') || count($ids) === 0) {
            return Api::error('Daftar ID kategori harus diisi', 400);
        }

        $affected = DB::transaction(function () use ($ids) {
            $count = DB::table('inspection_categories')
                ->whereIn('id', $ids)
                ->where('status', 'PENDING')
                ->update(['status' => 'APPROVED']);

            DB::table('inspection_subitems')
                ->whereIn('category_id', $ids)
                ->where('status', 'PENDING')
                ->update(['status' => 'APPROVED']);

            return $count;
        });

        return Api::ok(['message' => "$affected kategori dan sub-item berhasil disetujui"]);
    }

    public function bulkRejectCategories(Request $request)
    {
        $ids = $request->input('ids');
        if (!Legacy::isJsArray($request, 'ids') || count($ids) === 0) {
            return Api::error('Daftar ID kategori harus diisi', 400);
        }
        $alasan = $request->input('alasan_penolakan');

        $affected = DB::transaction(function () use ($ids, $alasan) {
            $count = DB::table('inspection_categories')
                ->whereIn('id', $ids)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'REJECTED',
                    'alasan_penolakan' => $alasan ?: null,
                ]);

            DB::table('inspection_subitems')
                ->whereIn('category_id', $ids)
                ->where('status', 'PENDING')
                ->update([
                    'status' => 'REJECTED',
                    'alasan_penolakan' => $alasan ?: null,
                ]);

            return $count;
        });

        return Api::ok([
            'message' => "$affected kategori dan sub-item berhasil ditolak",
            'alasan' => $alasan,
        ]);
    }

    public function deleteCategory(Request $request, $id)
    {
        $categories = DB::select('SELECT id FROM inspection_categories WHERE id = ?', [$id]);
        if (count($categories) === 0) {
            return Api::error('Kategori tidak ditemukan', 404);
        }

        DB::table('inspection_categories')->where('id', $id)->delete();

        return Api::ok(['message' => 'Kategori beserta sub-item berhasil dihapus']);
    }

    public function deleteSubitem(Request $request, $id)
    {
        $subitems = DB::select('SELECT id FROM inspection_subitems WHERE id = ?', [$id]);
        if (count($subitems) === 0) {
            return Api::error('Sub-item tidak ditemukan', 404);
        }

        DB::table('inspection_subitems')->where('id', $id)->delete();

        return Api::ok(['message' => 'Sub-item berhasil dihapus']);
    }
}
