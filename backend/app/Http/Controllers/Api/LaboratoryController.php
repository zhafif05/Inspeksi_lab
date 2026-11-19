<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Api;
use App\Support\LegacyValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LaboratoryController extends Controller
{
    /** Mirror kebenaran nilai JS (dipakai untuk `x || y` / `if (x)` pada source). */
    private static function jsTruthy(mixed $value): bool
    {
        return !($value === null || $value === false || $value === '' || $value === 0 || $value === 0.0);
    }

    private static function jsOr(mixed $value, mixed $fallback): mixed
    {
        return self::jsTruthy($value) ? $value : $fallback;
    }

    public function getAllLaboratories(Request $request): JsonResponse
    {
        $labs = DB::select(
            "SELECT l.id, l.nama_lab, l.lokasi, l.kalab_id,l.plp_id, l.teknisi_id,
                    u.name as penanggung_jawab, u.name as kalab_name, u.nip as nip_penanggung_jawab,plp.name AS plp_name,teknisi.name AS teknisi_name,
                    l.item_ids, l.report_file, l.created_at, l.updated_at
             FROM laboratories l
             LEFT JOIN users u ON l.kalab_id = u.id
             LEFT JOIN users plp ON l.plp_id = plp.id
             LEFT JOIN users teknisi ON l.teknisi_id = teknisi.id  
             ORDER BY l.created_at DESC"
        );

        // item_ids (CSV) dipecah seperti source lama, tetapi semua ID diambil
        // dalam satu query lalu dibagikan ke masing-masing laboratorium.
        $itemsByLab = [];
        $allItemIds = [];
        foreach ($labs as $lab) {
            $itemsByLab[$lab->id] = self::jsTruthy($lab->item_ids)
                ? array_map(static fn ($x) => (int) $x, explode(',', (string) $lab->item_ids))
                : [];
            $allItemIds = array_merge($allItemIds, $itemsByLab[$lab->id]);
        }

        $allItems = count($allItemIds) > 0
            ? DB::table('items')
                ->select('id', 'nama_barang', 'kode_barang')
                ->whereIn('id', array_values(array_unique($allItemIds)))
                ->orderBy('nama_barang')
                ->get()
                ->all()
            : [];

        foreach ($labs as $lab) {
            $ids = array_flip($itemsByLab[$lab->id]);
            $lab->items = array_values(array_filter(
                $allItems,
                static fn ($item) => isset($ids[$item->id])
            ));
        }

        return Api::ok(['data' => $labs, 'total' => count($labs)], 200);
    }

    public function createLaboratory(Request $request): JsonResponse
    {
        $errors = LegacyValidator::laboratory($request);
        if ($errors) {
            return Api::validation($errors);
        }

        // express-validator `trim()` ikut memutasi req.body pada source.
        $namaLab = trim((string) $request->input('nama_lab'));
        $lokasi = trim((string) $request->input('lokasi'));
        $kalabId = $request->input('kalab_id');
        $plpId = $request->input('plp_id');
        $teknisiId = $request->input('teknisi_id');
        $itemIdsRaw = $request->input('item_ids');

        if (self::jsTruthy($kalabId)) {
            $users = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$kalabId, 'kalab']);

            // Bug lama dipertahankan: validasi PLP/Teknisi bersarang di blok kalab_id.
            if (self::jsTruthy($plpId)) {
                $plpUsers = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$plpId, 'plp']);
                if (count($plpUsers) === 0) {
                    return Api::error('PLP 1 tidak ditemukan', 404);
                }
            }

            if (self::jsTruthy($teknisiId)) {
                $teknisiUsers = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$teknisiId, 'teknisi']);
                if (count($teknisiUsers) === 0) {
                    return Api::error('PLP 2 tidak ditemukan', 404);
                }
            }

            if (count($users) === 0) {
                return Api::error('User kalab tidak ditemukan', 404);
            }
        }

        $itemIds = is_array($itemIdsRaw)
            ? implode(',', $itemIdsRaw)
            : self::jsOr($itemIdsRaw, null);

        $resolvedKalab = self::jsOr($kalabId, null);
        $resolvedPlp = self::jsOr($plpId, null);
        $resolvedTeknisi = self::jsOr($teknisiId, null);

        $id = (int) DB::table('laboratories')->insertGetId([
            'nama_lab' => $namaLab,
            'lokasi' => $lokasi,
            'kalab_id' => $resolvedKalab,
            'plp_id' => $resolvedPlp,
            'teknisi_id' => $resolvedTeknisi,
            'item_ids' => $itemIds,
        ]);

        return Api::ok([
            'message' => 'Laboratorium berhasil dibuat',
            'data' => [
                'id' => $id,
                'nama_lab' => $namaLab,
                'lokasi' => $lokasi,
                'kalab_id' => $resolvedKalab,
                'plp_id' => $resolvedPlp,
                'teknisi_id' => $resolvedTeknisi,
                'item_ids' => $itemIds,
            ],
        ], 201);
    }

    public function updateLaboratory(Request $request, string $id): JsonResponse
    {
        $errors = LegacyValidator::laboratory($request);
        if ($errors) {
            return Api::validation($errors);
        }

        $namaLab = trim((string) $request->input('nama_lab'));
        $lokasi = trim((string) $request->input('lokasi'));
        $kalabId = $request->input('kalab_id');
        $plpId = $request->input('plp_id');
        $teknisiId = $request->input('teknisi_id');
        $itemIdsRaw = $request->input('item_ids');

        $lab = DB::selectOne('SELECT id FROM laboratories WHERE id = ?', [$id]);
        if (!$lab) {
            return Api::error('Laboratorium tidak ditemukan', 404);
        }

        if (self::jsTruthy($kalabId)) {
            $users = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$kalabId, 'kalab']);

            // Bug lama dipertahankan: blok bersarang, pesan beda dengan create.
            if (self::jsTruthy($plpId)) {
                $plpUsers = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$plpId, 'plp']);
                if (count($plpUsers) === 0) {
                    return Api::error('PLP tidak ditemukan', 404);
                }
            }

            if (self::jsTruthy($teknisiId)) {
                $teknisiUsers = DB::select('SELECT id FROM users WHERE id = ? AND role = ?', [$teknisiId, 'teknisi']);
                if (count($teknisiUsers) === 0) {
                    return Api::error('Teknisi tidak ditemukan', 404);
                }
            }

            if (count($users) === 0) {
                return Api::error('User kalab tidak ditemukan', 404);
            }
        }

        // Source membedakan undefined (item_ids tidak ada) dengan value null/''.
        $itemIds = $itemIdsRaw === null
            ? null
            : (is_array($itemIdsRaw) ? implode(',', $itemIdsRaw) : $itemIdsRaw);

        $resolvedKalab = self::jsOr($kalabId, null);
        $resolvedPlp = self::jsOr($plpId, null);
        $resolvedTeknisi = self::jsOr($teknisiId, null);

        if ($itemIds !== null) {
            DB::statement(
                'UPDATE laboratories SET nama_lab = ?, lokasi = ?, kalab_id = ?, plp_id = ?, teknisi_id = ?, item_ids = ? WHERE id = ?',
                [$namaLab, $lokasi, $resolvedKalab, $resolvedPlp, $resolvedTeknisi, $itemIds, $id]
            );
        } else {
            DB::statement(
                'UPDATE laboratories SET nama_lab = ?, lokasi = ?, kalab_id = ?, plp_id = ?, teknisi_id = ? WHERE id = ?',
                [$namaLab, $lokasi, $resolvedKalab, $resolvedPlp, $resolvedTeknisi, $id]
            );
        }

        return Api::ok([
            'message' => 'Laboratorium berhasil diperbarui',
            'data' => [
                'kalab_id' => $resolvedKalab,
                'plp_id' => $resolvedPlp,
                'teknisi_id' => $resolvedTeknisi,
                'item_ids' => self::jsOr($itemIdsRaw, []),
            ],
        ], 200);
    }

    public function deleteLaboratory(Request $request, string $id): JsonResponse
    {
        DB::beginTransaction();

        try {
            $lab = DB::selectOne('SELECT item_ids FROM laboratories WHERE id = ?', [$id]);
            if (!$lab) {
                DB::rollBack();

                return Api::error('Laboratorium tidak ditemukan', 404);
            }

            // Number('') = 0, Number('abc') = NaN -> dibuang, seperti source.
            $itemIds = [];
            if (self::jsTruthy($lab->item_ids)) {
                foreach (explode(',', (string) $lab->item_ids) as $part) {
                    $trimmed = trim($part);
                    if ($trimmed === '') {
                        $itemIds[] = 0;
                    } elseif (is_numeric($trimmed)) {
                        $itemIds[] = $trimmed + 0;
                    }
                }
            }

            $inspections = DB::select('SELECT id FROM inspections WHERE laboratory_id = ?', [$id]);
            $inspectionIds = array_map(static fn ($row) => $row->id, $inspections);

            if (count($inspectionIds) > 0) {
                DB::table('inspection_results')->whereIn('inspection_id', $inspectionIds)->delete();
                DB::table('inspection_monthly_reviews')->whereIn('inspection_id', $inspectionIds)->delete();
                DB::table('inspections')->whereIn('id', $inspectionIds)->delete();
            }

            if (count($itemIds) > 0) {
                $marks = implode(',', array_fill(0, count($itemIds), '?'));
                DB::statement(
                    "DELETE s FROM inspection_subitems s INNER JOIN inspection_categories c ON c.id = s.category_id WHERE c.item_id IN ($marks)",
                    $itemIds
                );
                DB::table('inspection_categories')->whereIn('item_id', $itemIds)->delete();
                DB::table('items')->whereIn('id', $itemIds)->delete();
            }

            DB::table('laboratories')->where('id', $id)->delete();

            DB::commit();

            return Api::ok(['message' => 'Laboratorium berhasil dihapus'], 200);
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
    }
}
