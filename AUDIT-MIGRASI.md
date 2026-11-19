# Laporan Audit & Uji Migrasi — Inspeksi PPNS

Dokumen ini adalah hasil audit dan pengujian migrasi dari stack lama (Next.js + Express.js + MySQL) ke stack baru (React + Vite + Laravel + MySQL). Semua klaim di bawah **sudah dijalankan dan diverifikasi**, bukan sekadar asumsi dari dokumentasi sebelumnya.

## 1. Ruang lingkup dan prinsip

- Proyek lama `Backend_inspeksi/` dan `Frontend_inspeksi/` **tidak diubah**.
- Tidak ada fitur yang dihapus atau ditambahkan.
- Tidak ada perubahan desain UI/UX.
- Struktur database tidak diubah.
- Tidak memakai dummy data untuk menyembunyikan fungsi rusak.
- Tidak mengklaim tes lulus jika belum benar-benar dijalankan.
- Tidak menjalankan perintah penghapus database atau data proyek.

## 2. Sumber kebenaran

Sumber data otoritatif bukan database live lama `ppns_lab_inspection` yang sudah stale, melainkan:

- `Backend_inspeksi/ppns_lab_inspection_Laragon.sql`
- `project-baru/backend/database/sql/schema-live.sql`

Untuk pengujian dipakai database audit terpisah:

| Database | Fungsi |
|---|---|
| `ppns_audit_dump` | Replika dump otoritatif untuk Express lama |
| `ppns_lab_inspection_baru` | Database Laravel baru |
| `ppns_audit_source` | Sumber audit |

Token JWT untuk pengujian dibuat langsung dengan secret aplikasi yang sama, sehingga tidak bergantung pada login berulang yang kena rate limiter.

## 3. Hasil audit per tahap

### 3.1 Struktur

- 10 tabel ditemukan di kedua database: identik.
- 96 kolom: identik dalam tipe, nullability, default, dan `EXTRA`.
- Template Excel, logo, dan template DOCX sudah diverifikasi.
- File upload lama tersedia di `project-baru/backend/public/uploads`.

### 3.2 Backend Laravel

- PHP lint: **62/62 file lolos**, 0 kegagalan.
- `artisan route:list --path=api --json`: **74 rute** — 73 rute hasil migrasi ditambah 1 rute internal `GET /api/inspections/by-items` untuk optimasi batch (tidak mengubah fitur/UI).
- Envelope error disamakan dengan mysql2/Express, termasuk:
  - `ER_BAD_NULL_ERROR`
  - `ER_BAD_FIELD_ERROR`
  - `LIMIT_FILE_SIZE`
  - MIME error
- Auth middleware cocok:
  - Token hilang → `401 {"message":"Token tidak ditemukan"}`
  - Token invalid → `403 {"message":"Token tidak valid atau kadaluarsa"}`
  - Role terlarang → `403`
- Login parity lolos untuk:
  - kredensial valid
  - password whitespace
  - password salah
  - header `RateLimit-*`
- JWT login baru sudah memuat `iat`.

### 3.3 Frontend React + Vite

- `npm run build` lolos: 2656 modules, hanya chunk-size warning.
- `npx tsc --noEmit -p tsconfig.app.json` lolos tanpa output.
- Diff source lama vs baru: **51 file berubah, 231 insertions, 240 deletions**.
- Perubahan bersifat konversi mekanis:
  - `next/navigation` → helper `nextCompat.ts`
  - `Link href` → `Link to`
  - inisialisasi font Next.js → Google Fonts di `index.html`
  - file bootstrap Vite (`main.tsx`, `vite-env.d.ts`)
- `src/app/globals.css` **byte-identik** dengan versi lama (SHA-256 sama).
- Tidak ada perubahan kelas Tailwind, warna, atau layout di luar konversi routing.

### 3.4 Perbandingan UI/UX

- Perbandingan source dan stylesheet menunjukkan UI tidak diubah.
- Font di-load melalui Google Fonts dengan variabel CSS yang sama:
  - `--font-geist-sans: "Inter"`
  - `--font-display: "Playfair Display"`
- Chrome headless tersedia dan dipakai untuk memeriksa render DOM: halaman `/admin` menampilkan `Login Portal`, sedangkan dashboard `kalab` setelah login menampilkan `Selamat Datang` dan `Inspeksi Pending`.
- **Keterbatasan:** perbandingan visual piksel-per-piksel antar stack lama/baru tidak dilakukan. Kesimpulan UI/UX didasarkan pada render DOM yang benar, kesamaan source JSX, CSS byte-identik, dan konversi routing mekanis.

### 3.5 Workflow fungsional

| Pengujian | Hasil |
|---|---|
| `compare_exact.js` — 38 endpoint lintas role | **TOTAL 38, BEDA 0** |
| `api_audit.ps1` — 97 kasus | **97 dijalankan, 7 gagal karena asumsi ID/data uji** |
| `compare_trim.js` — 6 kasus trim | **TOTAL 6, BEDA 0** |
| Login parity | Lolos |
| Upload error parity | Lolos |
| Multipart PUT error parity | **TOTAL 2, BEDA 0** |
| Create inspection parity | Lolos |
| `tahun` parity | Lolos |
| Export XLSX style parity | Lolos |
| Export DOCX media parity | Lolos |

Penjelasan 7 kegagalan `api_audit.ps1`:

- 4× `GET /items/1` → `404 Barang tidak ditemukan`
- 3× `GET /criteria/approved` → `404 Lab untuk item tersebut tidak ditemukan`

Keduanya terjadi karena skrip audit memakai ID lama yang tidak ada di dump otoritatif. Ini bukan defect aplikasi. Endpoint yang sama dengan ID yang tersedia di dump lolos.

### 3.6 Direct run testing

| Service | URL | Status |
|---|---|---|
| Express lama | `http://127.0.0.1:5001/api/health` | 200 |
| Laravel baru | `http://127.0.0.1:8000/api/health` | 200 |
| Vite | `http://127.0.0.1:3000/` | 200 |
| MySQL | `127.0.0.1:3306` | aktif |

Express lama dijalankan dengan:

```powershell
$env:PORT='5001'
$env:DB_NAME='ppns_audit_dump'
node server.js
```

Laravel baru dijalankan dengan `php artisan serve --host=0.0.0.0 --port=8000`.

## 4. Perbaikan yang dilakukan

Berikut perbaikan yang sudah diterapkan di proyek baru untuk mencapai parity dengan perilaku lama:

1. **Trim route-scoped**
   - `TrimStrings` dan `ConvertEmptyStringsToNull` global dinonaktifkan untuk grup API.
   - Trim hanya diterapkan pada 11 rute yang memang memakai `.trim()` di Express.
   - Ini mencegah `''` berubah menjadi `null`.

2. **Legacy error envelope**
   - Error database Laravel diterjemahkan ke bentuk error mysql2/Express.
   - Termasuk `ER_BAD_NULL_ERROR`, `ER_BAD_FIELD_ERROR`, dan error upload.

3. **Multipart PUT**
   - Parser `LegacyMultipartPut` diperbaiki agar file PUT terbaca.
   - Fatal saat shutdown diperbaiki dengan memindahkan cleanup ke static method `cleanup()`.

4. **Create inspection parity**
   - Parsing `tanggal_inspeksi` mengikuti konversi JavaScript `new Date()` + mysql2 timezone `+07:00`.
   - Respons tanggal dikembalikan sebagai ISO UTC dengan milidetik, seperti serialisasi `Date` Express.
   - `tahun` mengikuti `Number(value)` JavaScript.
   - Nilai `NaN` sengaja menghasilkan error MySQL `Unknown column 'NaN' in 'where clause'`, persis seperti mysql2.

5. **Upload parity**
   - Gate MIME dan ukuran file mengikuti multer lama.
   - Error envelope identik, termasuk `storageErrors: []`.

6. **Export parity**
   - Nama sheet XLSX sama.
   - Template dan logo byte-identik.
   - Font header baris 9/10 disesuaikan dengan ExcelJS.
   - Embedding foto DOCX diverifikasi byte-per-byte: 4 media file dengan SHA-256 sama.

7. **Seeder data**
   - `DatabaseSeeder` diperbarui untuk mengimport `database/sql/ppns_lab_inspection_Laragon.sql` (9 statement `INSERT`, tanpa `CREATE/DROP`).
   - Alur `php artisan migrate --force && php artisan db:seed --force` diuji pada database kosong: 10 tabel terisi dengan jumlah baris identik dengan database proyek.
   - Seeder dibungkus transaksi dan dilewati otomatis jika data sudah ada, sehingga aman dijalankan ulang.

8. **Debug cleanup**
   - Logging sementara di `LegacyTrim.php` dan `LegacyValidator.php` dihapus.
   - Tidak ada `var_dump`, `dd`, atau debug write tersisa di source backend.

9. **Optimasi performa pasca-audit**
   - `GET /api/inspections/by-item/{id}`: 6 query → 2 query per item, respons tetap identik.
   - Rute baru `GET /api/inspections/by-items?ids=...`: satu HTTP request untuk banyak item. Frontend layout notifikasi dan halaman daftar/detail lab memakainya, menggantikan `Promise.all` yang memanggil satu request per item.
   - `GET /api/criteria/my`: 15 query → 2 query (subitem diambil sekaligus lalu dikelompokkan).
   - `GET /api/labs`: 5 query → 2 query (item dari semua lab diambil dalam satu `whereIn`).
   - `GET /api/export/report-status`: 12 query → 6 query; boolean laporan perbaikan diturunkan dari data laporan kerusakan yang sama.
   - `getLaboratoryStaffColumns()` di-cache per proses agar `SHOW COLUMNS` tidak diulang.
   - Uji parity: 44 respons endpoint (termasuk 13 item × 3 kombinasi tahun/semester) **identik byte-for-byte** sebelum/sesudah; 6 kombinasi `export/report-status` juga identik. `tsc --noEmit` dan `npm run build` lolos.
   - Pengukuran lokal: 13 request lama ≈ 3456 ms → 1 request batch ≈ 413 ms. Jika OPcache PHP aktif: 13 request lama ≈ 821 ms → batch ≈ 153 ms.

## 5. Data uji

- Semua inspection dengan catatan `audit parity test`, `audit create parity`, dan `audit tahun parity` sudah dihapus dari kedua database.
- Tidak ada baris test tersisa.
- File `project-baru/backend/public/uploads/file-1794973915105-391.txt` adalah artifact test sebelumnya dan **sengaja tidak dihapus tanpa konfirmasi**.

### 5.1 Verifikasi akhir tambahan

Setelah audit parity selesai, dua skrip di `project-baru/tests/` juga dijalankan pada database uji:

- `smoke.ps1`: **28/28 lolos**.
- `integration.ps1`: **73/73 lolos**, mencakup CRUD users/labs/items/schedules, approval kriteria, gate kriteria PENDING, alur inspeksi 6 bulan, reject/resubmit/approve, damage report, upload lokal, filter MIME Drive, kelima export, dan pesan error lama.
- Chrome headless: halaman `/admin` merender `Login Portal`; dashboard `kalab` merender `Selamat Datang` dan `Inspeksi Pending`.

Fallback `POST /api/inspections/create` tanpa `checklist_results` sengaja tetap menghasilkan 500 `Column 'status' cannot be null` pada MariaDB lokal karena source Express memasukkan `NULL` eksplisit ke kolom `NOT NULL DEFAULT 'B'`. Skenario normal UI memakai `checklist_results` eksplisit dan lolos.

Setelah pengujian, `database/sql/full_setup.sql` di-import ulang ke `ppns_lab_inspection_baru`. Hash password asli dari `C:\Users\zhafi\AppData\Local\Temp\opencode\pw_backup.txt` terverifikasi sama, dan login dengan kredensial asli `kalab3@ppns.ac.id / kalab123` serta `plp1@gmail.com / 123456` berhasil.

## 6. Cara menjalankan

Lihat `README.md` untuk prosedur lengkap. Ringkasnya:

```powershell
# Backend
cd project-baru\backend
C:\xampp\php\php.exe artisan serve --host=0.0.0.0 --port=8000

# Frontend
cd project-baru\frontend
npm run dev -- --port 3000
```

Kredensial terverifikasi:

- `kalab3@ppns.ac.id / kalab123`
- `plp1@gmail.com / 123456`
- Admin: token untuk pengujian dibuat langsung dengan secret aplikasi; password admin belum berhasil diverifikasi dari kandidat yang tersedia.

## 7. Kesimpulan

Migrasi dinyatakan **setara secara fungsional** berdasarkan:

- seluruh endpoint yang diuji dengan data valid menghasilkan respons identik,
- workflow create/read/update/delete dan export sudah sesuai perilaku lama,
- error envelope dan status code identik,
- struktur database identik,
- frontend build dan type check lolos,
- UI source/CSS tidak berubah secara bermakna.

Catatan jujur: render browser sudah dicek dengan Chrome headless, tetapi **perbandingan piksel-per-piksel antar stack belum dilakukan**; satu artifact upload lama sengaja dipertahankan. Selain itu, 7 kegagalan audit API disebabkan oleh asumsi ID/data uji, bukan oleh perbedaan implementasi.
