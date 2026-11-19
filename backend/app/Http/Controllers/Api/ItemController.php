<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\Legacy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ItemController extends Controller
{
    public function getAllItems(Request $request)
    {
        $items = DB::table('items')->orderByDesc('created_at')->get();

        return Api::ok([
            'data' => $items,
            'total' => $items->count(),
        ]);
    }

    public function getItemsByLab(Request $request, $laboratoryId)
    {
        $lab = DB::table('laboratories')->select('item_ids')->where('id', $laboratoryId)->first();

        if (!$lab) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }

        $itemIds = Legacy::csvIds($lab->item_ids);
        if (count($itemIds) === 0) {
            return Api::ok(['data' => [], 'total' => 0]);
        }

        $items = DB::table('items')->whereIn('id', $itemIds)->orderByDesc('created_at')->get();

        return Api::ok([
            'data' => $items,
            'total' => $items->count(),
        ]);
    }

    public function getMyItems(Request $request)
    {
        $user = Legacy::user($request);
        $laboratoryId = $user['laboratory_id'] ?? null;

        if (!$laboratoryId) {
            return Api::ok(['data' => [], 'total' => 0]);
        }

        $lab = DB::table('laboratories')->select('item_ids')->where('id', $laboratoryId)->first();
        if (!$lab) {
            return Api::ok(['data' => [], 'total' => 0]);
        }

        $itemIds = Legacy::csvIds($lab->item_ids);
        if (count($itemIds) === 0) {
            return Api::ok(['data' => [], 'total' => 0]);
        }

        $items = DB::table('items')->whereIn('id', $itemIds)->orderByDesc('created_at')->get();

        return Api::ok([
            'data' => $items,
            'total' => $items->count(),
        ]);
    }

    public function getCalibrationAlerts(Request $request)
    {
        $rows = DB::select("
            SELECT
                i.id,
                i.nama_barang,
                i.kode_barang,
                i.tanggal_kalibrasi_berikutnya,
                l.id AS laboratory_id,
                l.nama_lab
            FROM laboratories l
            JOIN items i
              ON FIND_IN_SET(i.id, l.item_ids)
            WHERE
                i.tanggal_kalibrasi_berikutnya IS NOT NULL
                AND i.tanggal_kalibrasi_berikutnya <= CURDATE()
            ORDER BY
                i.tanggal_kalibrasi_berikutnya ASC,
                i.id ASC
        ");

        return Api::ok([
            'total' => count($rows),
            'data' => $rows,
        ]);
    }

    public function getItemById(Request $request, $id)
    {
        $item = DB::table('items')->where('id', $id)->first();

        if (!$item) {
            return Api::error('Barang tidak ditemukan', 404);
        }

        return Api::ok(['data' => $item]);
    }

    public function createItem(Request $request)
    {
        $namaBarang = $request->input('nama_barang');
        $kodeBarang = $request->input('kode_barang');
        $pembuatAlat = $request->input('pembuat_alat');
        $tanggalPembelian = $request->input('tanggal_pembelian');
        $laboratoryId = $request->input('laboratory_id');
        $kalibrasiTerakhir = $request->input('tanggal_kalibrasi_terakhir');
        $kalibrasiBerikutnya = $request->input('tanggal_kalibrasi_berikutnya');

        // Route lama meng-import validateItem tetapi tidak menggunakannya.
        // Satu-satunya pengecekan manual di source adalah kode_barang wajib diisi.
        if (empty($kodeBarang) && $kodeBarang !== '0') {
            return Api::error('Kode barang harus diisi', 400);
        }

        $newItemId = DB::table('items')->insertGetId([
            'nama_barang' => $namaBarang,
            'kode_barang' => $kodeBarang,
            'pembuat_alat' => $this->orNull($pembuatAlat),
            'tanggal_pembelian' => $this->orNull($tanggalPembelian),
            'tanggal_kalibrasi_terakhir' => $this->orNull($kalibrasiTerakhir),
            'tanggal_kalibrasi_berikutnya' => $this->orNull($kalibrasiBerikutnya),
        ]);

        // Otomatis tambahkan item ke lab (prioritas body, fallback JWT).
        $user = Legacy::user($request);
        $targetLabId = $laboratoryId ?: ($user['laboratory_id'] ?? null);

        if ($targetLabId) {
            $lab = DB::table('laboratories')->select('item_ids')->where('id', $targetLabId)->first();
            if ($lab) {
                $currentIds = Legacy::csvIds($lab->item_ids);
                if (!in_array((int) $newItemId, $currentIds, true)) {
                    $currentIds[] = (int) $newItemId;
                    DB::table('laboratories')
                        ->where('id', $targetLabId)
                        ->update(['item_ids' => implode(',', $currentIds)]);
                }
            }
        }

        return Api::ok([
            'message' => 'Barang berhasil dibuat',
            'data' => [
                'id' => $newItemId,
                'nama_barang' => $namaBarang,
                'kode_barang' => $kodeBarang,
                'pembuat_alat' => $this->orNull($pembuatAlat),
                'tanggal_pembelian' => $this->orNull($tanggalPembelian),
                'tanggal_kalibrasi_terakhir' => $this->orNull($kalibrasiTerakhir),
                'tanggal_kalibrasi_berikutnya' => $this->orNull($kalibrasiBerikutnya),
            ],
        ], 201);
    }

    public function updateItem(Request $request, $id)
    {
        $exists = DB::table('items')->select('id')->where('id', $id)->first();
        if (!$exists) {
            return Api::error('Barang tidak ditemukan', 404);
        }

        DB::table('items')->where('id', $id)->update([
            'nama_barang' => $request->input('nama_barang'),
            'kode_barang' => $request->input('kode_barang'),
            'pembuat_alat' => $this->orNull($request->input('pembuat_alat')),
            'tanggal_pembelian' => $this->orNull($request->input('tanggal_pembelian')),
            'tanggal_kalibrasi_terakhir' => $this->orNull($request->input('tanggal_kalibrasi_terakhir')),
            'tanggal_kalibrasi_berikutnya' => $this->orNull($request->input('tanggal_kalibrasi_berikutnya')),
        ]);

        return Api::ok(['message' => 'Barang berhasil diperbarui']);
    }

    public function deleteItem(Request $request, $id)
    {
        $conn = DB::connection();
        $conn->beginTransaction();

        try {
            $item = $conn->table('items')->select('id')->where('id', $id)->first();
            if (!$item) {
                // Source melakukan rollback sebelum mengirim 404.
                $conn->rollBack();

                return Api::error('Barang tidak ditemukan', 404);
            }

            // Hapus item_id dari seluruh laboratorium.
            $labs = $conn->table('laboratories')
                ->select('id', 'item_ids')
                ->whereNotNull('item_ids')
                ->get();

            foreach ($labs as $lab) {
                $ids = Legacy::csvIds($lab->item_ids);
                $filtered = array_values(array_filter(
                    $ids,
                    static fn ($value) => $value !== (int) $id
                ));

                if (count($filtered) !== count($ids)) {
                    $conn->table('laboratories')
                        ->where('id', $lab->id)
                        ->update(['item_ids' => implode(',', $filtered)]);
                }
            }

            // Hapus kategori & subitem.
            $categories = $conn->table('inspection_categories')->select('id')->where('item_id', $id)->get();
            foreach ($categories as $category) {
                $conn->table('inspection_subitems')->where('category_id', $category->id)->delete();
            }
            $conn->table('inspection_categories')->where('item_id', $id)->delete();

            // Urutan sengaja dipertahankan seperti source: inspections dihapus
            // lebih dulu, sehingga penghapusan results lewat JOIN tidak melakukan apa-apa.
            $conn->table('inspections')->where('item_id', $id)->delete();

            $conn->statement(
                'DELETE ir FROM inspection_results ir
                 JOIN inspections i ON ir.inspection_id = i.id
                 WHERE i.item_id = ?',
                [$id]
            );

            $conn->table('items')->where('id', $id)->delete();

            $conn->commit();

            return Api::ok(['message' => 'Barang berhasil dihapus']);
        } catch (\Throwable $e) {
            $conn->rollBack();

            throw $e;
        }
    }

    /** Padanan `value || null` pada JavaScript untuk input skalar. */
    private function orNull(mixed $value): mixed
    {
        if ($value === null || $value === '' || $value === false || $value === 0 || $value === 0.0) {
            return null;
        }

        return $value;
    }
}
