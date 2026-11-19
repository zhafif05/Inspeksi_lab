<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Replikasi aturan validasi express-validator dari project Express lama.
 * Bentuk error sengaja dibuat sama dengan express-validator:
 * [{ type: 'field', value, msg, path, location: 'body' }].
 */
class LegacyValidator
{
    private static function err(string $param, string $msg, mixed $value): array
    {
        return [
            'type' => 'field',
            'value' => $value,
            'msg' => $msg,
            'path' => self::legacyPath($param),
            'location' => 'body',
        ];
    }

    /**
     * express-validator memakai notasi kurung siku untuk index array.
     */
    private static function legacyPath(string $param): string
    {
        return preg_replace_callback('/\.(\d+)(?=\.|$)/', fn ($m) => '['.$m[1].']', $param);
    }

    private static function isInt(mixed $v): bool
    {
        // validator.js isInt() menerima tanda + maupun -, seperti express-validator.
        return is_int($v) || (is_string($v) && preg_match('/^[+-]?\d+$/', $v) === 1);
    }

    /** express-validator .optional() hanya melewati field yang benar-benar tidak ada. */
    private static function has(Request $request, string $key): bool
    {
        return $request->exists($key);
    }

    private static function isDate(mixed $v): bool
    {
        if (!is_string($v) || $v === '') {
            return false;
        }
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 && strtotime($v) !== false;
    }

    private static function isIso8601(mixed $v): bool
    {
        if (!is_string($v) || $v === '') {
            return false;
        }
        return strtotime($v) !== false;
    }

    public static function login(Request $request): array
    {
        $errors = [];
        $email = $request->input('email');
        $password = $request->input('password');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = self::err('email', 'Email tidak valid', $email);
        }
        if ($password === null || (string) $password === '') {
            $errors[] = self::err('password', 'Password harus diisi', $password);
        }
        return $errors;
    }

    public static function laboratory(Request $request): array
    {
        $errors = [];
        $nama = $request->input('nama_lab');
        $lokasi = $request->input('lokasi');
        $kalab = $request->input('kalab_id');
        if (trim((string) $nama) === '') {
            $errors[] = self::err('nama_lab', 'Nama laboratorium harus diisi', $nama);
        }
        if (trim((string) $lokasi) === '') {
            $errors[] = self::err('lokasi', 'Lokasi harus diisi', $lokasi);
        }
        if (self::has($request, 'kalab_id') && !self::isInt($kalab)) {
            $errors[] = self::err('kalab_id', 'Kalab ID harus angka', $kalab);
        }
        return $errors;
    }

    public static function item(Request $request): array
    {
        $errors = [];
        $nama = $request->input('nama_barang');
        $kode = $request->input('kode_barang');
        $tanggal = $request->input('tanggal_pembelian');
        if (trim((string) $nama) === '') {
            $errors[] = self::err('nama_barang', 'Nama barang harus diisi', $nama);
        }
        if (trim((string) $kode) === '') {
            $errors[] = self::err('kode_barang', 'Kode barang harus diisi', $kode);
        }
        if ($tanggal !== null && $tanggal !== '' && !self::isDate($tanggal)) {
            $errors[] = self::err('tanggal_pembelian', 'Tanggal pembelian format tidak valid (YYYY-MM-DD)', $tanggal);
        }
        return $errors;
    }

    public static function inspection(Request $request): array
    {
        $errors = [];
        $lab = $request->input('laboratory_id');
        $item = $request->input('item_id');
        $bulan = $request->input('bulan_ke');
        $status = $request->input('status');
        $kondisi = $request->input('kondisi');
        $catatan = $request->input('catatan');
        if (!self::isInt($lab)) {
            $errors[] = self::err('laboratory_id', 'Laboratory ID harus angka', $lab);
        }
        if (!self::isInt($item)) {
            $errors[] = self::err('item_id', 'Item ID harus angka', $item);
        }
        if (!self::isInt($bulan) || (int) $bulan < 1 || (int) $bulan > 6) {
            $errors[] = self::err('bulan_ke', 'Bulan ke harus 1-6 (1 semester)', $bulan);
        }
        if (!in_array($status, ['B', 'K', 'N/A'], true)) {
            $errors[] = self::err('status', 'Status harus B, K, atau N/A', $status);
        }
        if (trim((string) $kondisi) === '') {
            $errors[] = self::err('kondisi', 'Kondisi harus diisi', $kondisi);
        }
        if (trim((string) $catatan) === '') {
            $errors[] = self::err('catatan', 'Catatan harus diisi', $catatan);
        }
        return $errors;
    }

    public static function schedule(Request $request): array
    {
        $errors = [];
        $lab = $request->input('laboratory_id');
        $tanggal = $request->input('tanggal');
        $keterangan = $request->input('keterangan');
        if (!self::isInt($lab)) {
            $errors[] = self::err('laboratory_id', 'Laboratory ID harus angka', $lab);
        }
        if (!self::isIso8601($tanggal)) {
            $errors[] = self::err('tanggal', 'Tanggal tidak valid', $tanggal);
        }
        if (trim((string) $keterangan) === '') {
            $errors[] = self::err('keterangan', 'Keterangan harus diisi', $keterangan);
        }
        return $errors;
    }

    public static function category(Request $request): array
    {
        $errors = [];
        $nama = $request->input('nama_kategori');
        $urutan = $request->input('urutan');
        $lab = $request->input('laboratory_id');
        $item = $request->input('item_id');
        if (trim((string) $nama) === '') {
            $errors[] = self::err('nama_kategori', 'Nama kategori harus diisi', $nama);
        }
        if (!self::isInt($urutan) || (int) $urutan < 1) {
            $errors[] = self::err('urutan', 'Urutan harus angka positif', $urutan);
        }
        if (self::has($request, 'laboratory_id') && !self::isInt($lab)) {
            $errors[] = self::err('laboratory_id', 'Laboratory ID harus angka', $lab);
        }
        if (self::has($request, 'item_id') && !self::isInt($item)) {
            $errors[] = self::err('item_id', 'Item ID harus angka', $item);
        }
        return $errors;
    }

    public static function subitem(Request $request): array
    {
        $errors = [];
        $category = $request->input('category_id');
        $nama = $request->input('nama_subitem');
        $urutan = $request->input('urutan');
        if (!self::isInt($category)) {
            $errors[] = self::err('category_id', 'Category ID harus angka', $category);
        }
        if (trim((string) $nama) === '') {
            $errors[] = self::err('nama_subitem', 'Nama sub-item harus diisi', $nama);
        }
        if (!self::isInt($urutan) || (int) $urutan < 1) {
            $errors[] = self::err('urutan', 'Urutan harus angka positif', $urutan);
        }
        return $errors;
    }

    public static function categoryWithSubitems(Request $request): array
    {
        $errors = [];
        $categories = $request->input('categories');
        if (!is_array($categories) || count($categories) < 1) {
            $errors[] = self::err('categories', 'Minimal 1 kategori', $categories);
            $item = $request->input('item_id');
            if (self::has($request, 'item_id') && !self::isInt($item)) {
                $errors[] = self::err('item_id', 'Item ID harus angka', $item);
            }
            $lab = $request->input('laboratory_id');
            if (self::has($request, 'laboratory_id') && !self::isInt($lab)) {
                $errors[] = self::err('laboratory_id', 'Laboratory ID harus angka', $lab);
            }
            return $errors;
        }

        foreach ($categories as $i => $cat) {
            $nama = is_array($cat) ? ($cat['nama_kategori'] ?? null) : null;
            $urutan = is_array($cat) ? ($cat['urutan'] ?? null) : null;
            $subitems = is_array($cat) ? ($cat['subitems'] ?? null) : null;
            if (trim((string) $nama) === '') {
                $errors[] = self::err("categories.$i.nama_kategori", 'Nama kategori harus diisi', $nama);
            }
            if (!self::isInt($urutan) || (int) $urutan < 1) {
                $errors[] = self::err("categories.$i.urutan", 'Urutan kategori harus angka positif', $urutan);
            }
            if (!is_array($subitems) || count($subitems) < 1) {
                $errors[] = self::err("categories.$i.subitems", 'Minimal 1 sub-item per kategori', $subitems);
                continue;
            }
            foreach ($subitems as $j => $sub) {
                $subNama = is_array($sub) ? ($sub['nama_subitem'] ?? null) : null;
                $subUrutan = is_array($sub) ? ($sub['urutan'] ?? null) : null;
                if (trim((string) $subNama) === '') {
                    $errors[] = self::err("categories.$i.subitems.$j.nama_subitem", 'Nama sub-item harus diisi', $subNama);
                }
                if (!self::isInt($subUrutan) || (int) $subUrutan < 1) {
                    $errors[] = self::err("categories.$i.subitems.$j.urutan", 'Urutan sub-item harus angka positif', $subUrutan);
                }
            }
        }

        $item = $request->input('item_id');
        if (self::has($request, 'item_id') && !self::isInt($item)) {
            $errors[] = self::err('item_id', 'Item ID harus angka', $item);
        }
        $lab = $request->input('laboratory_id');
        if (self::has($request, 'laboratory_id') && !self::isInt($lab)) {
            $errors[] = self::err('laboratory_id', 'Laboratory ID harus angka', $lab);
        }
        return $errors;
    }

    public static function updateCategoryWithSubitems(Request $request): array
    {
        $errors = [];
        $nama = $request->input('nama_kategori');
        $urutan = $request->input('urutan');
        $subitems = $request->input('subitems');
        if (trim((string) $nama) === '') {
            $errors[] = self::err('nama_kategori', 'Nama kategori harus diisi', $nama);
        }
        if (!self::isInt($urutan) || (int) $urutan < 1) {
            $errors[] = self::err('urutan', 'Urutan harus angka positif', $urutan);
        }
        if (!is_array($subitems) || count($subitems) < 1) {
            $errors[] = self::err('subitems', 'Minimal 1 sub-item', $subitems);
            return $errors;
        }
        foreach ($subitems as $i => $sub) {
            $subNama = is_array($sub) ? ($sub['nama_subitem'] ?? null) : null;
            $subUrutan = is_array($sub) ? ($sub['urutan'] ?? null) : null;
            $id = is_array($sub) ? ($sub['id'] ?? null) : null;
            if (trim((string) $subNama) === '') {
                $errors[] = self::err("subitems.$i.nama_subitem", 'Nama sub-item harus diisi', $subNama);
            }
            if (!self::isInt($subUrutan) || (int) $subUrutan < 1) {
                $errors[] = self::err("subitems.$i.urutan", 'Urutan sub-item harus angka positif', $subUrutan);
            }
            if (is_array($sub) && array_key_exists('id', $sub) && !self::isInt($id)) {
                $errors[] = self::err("subitems.$i.id", 'ID sub-item harus angka', $id);
            }
        }
        return $errors;
    }

    public static function rejectReview(Request $request): array
    {
        $errors = [];
        $alasan = $request->input('alasan_penolakan');
        if (trim((string) $alasan) === '') {
            $errors[] = self::err('alasan_penolakan', 'Alasan penolakan harus diisi', $alasan);
        }
        return $errors;
    }
}
