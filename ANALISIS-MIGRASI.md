# Analisis Migrasi — Inspeksi PPNS

Dokumen ini adalah hasil Tahap 1 (analisis) sebelum implementasi. Seluruh analisis bersifat **read-only** terhadap folder lama.

## 1. Sistem lama

| Layer | Teknologi | Lokasi |
|---|---|---|
| Frontend | Next.js App Router, TypeScript, Tailwind, axios | `Frontend_inspeksi/` |
| Backend | Express.js, MySQL (`mysql2/promise`), JWT, multer, Excel, DOCX, Google Drive | `Backend_inspeksi/` |
| Database | MySQL `ppns_lab_inspection` (10 tabel) | SQL dump + migrasi lama |
| Upload | `Backend_inspeksi/public/uploads` | 38 file |
| Template | Excel inspeksi + 2 DOCX laporan | `Backend_inspeksi/...` |

## 2. Inventaris frontend lama

- 24 file `src/app/**/page.tsx`.
- Rute utama: `/`, `/admin`, `/admin/dashboard`, `/admin/users`, `/admin/labs`, `/admin/labs/[id]`, `/admin/labs/[id]/inspeksi/[itemId]`, `/admin/items`, `/admin/profile`, serta rute `dashboard`, `labs`, `labs/[id]`, `labs/[id]/inspeksi/[itemId]`, `profile` untuk `kalab`, `plp`, dan `teknisi`.
- Rute phantom/duplikat yang tetap dipertahankan: `/admin/inspections`, `/admin/criteria`, `/admin/inspections/detail/:id` (dirender oleh komponen lama, bukan halaman App Router terpisah).
- 4 role: `admin`, `kalab`, `plp`, `teknisi`. Login admin di `/admin`; role lain masuk melalui redirect setelah login.
- `localStorage.token` + `localStorage.user`; axios interceptor menambahkan `Authorization: Bearer <token>` dan melakukan hard redirect ke `${origin}/dev/siminspeksi/admin` pada 401.
- Redirect setelah login: `admin → /admin/dashboard`, `kalab → /kalab/dashboard`, `plp → /plp/dashboard`, `teknisi → /teknisi/dashboard`, dengan delay 1500 ms.

## 3. Inventaris backend lama

10 grup rute / 10 controller:

| Grup | Prefix | Controller lama |
|---|---|---|
| Auth | `/api/auth` | `authController.js` |
| Users | `/api/users` | `userController.js` |
| Laboratories | `/api/labs` | `laboratoryController.js` |
| Items | `/api/items` | `itemController.js` |
| Schedules | `/api/schedules` | `scheduleController.js` |
| Criteria | `/api/criteria` | `inspectionCriteriaController.js` |
| Inspection detail | `/api/inspections` | `inspectionDetailController.js` |
| Damage reports | `/api/damage-reports` | `damageReportController.js` |
| Upload | `/api/upload` | `uploadController.js` |
| Export | `/api/export` + ekspor inspeksi | `exportController.js` |

Envelope respons lama: `{ success: boolean, message?, data?, total? }`, dengan pesan error berbahasa Indonesia. Semua pesan dan status code dipertahankan.

## 4. Struktur data

10 tabel: `users`, `laboratories`, `items`, `schedules`, `inspections`, `inspection_categories`, `inspection_subitems`, `inspection_results`, `inspection_monthly_reviews`, `inspection_damage_reports`.

Skema direkonstruksi dari:
- query di seluruh controller,
- script migrasi lama (`migrate-*.js`),
- `mysqldump --no-data` dari database live yang masih tersedia.

Data diambil dari dump lama `ppns_lab_inspection_Laragon.sql` yang berisi `INSERT` saja, lalu digabung dengan skema live menjadi `backend/database/sql/full_setup.sql`.

## 5. Aturan bisnis yang dipertahankan

- Semester: Februari–Juli = `GENAP`, selain itu `GANJIL`; Januari memakai tahun sebelumnya pada beberapa query dashboard/approval.
- Status hasil inspeksi: `B`, `K`, `N/A`; status approval: `PENDING`, `APPROVED`, `REJECTED`.
- Siklus 6 bulan: `bulan_ke` 1–6; approval bulanan otomatis ketika semua item pada bulan tersebut `APPROVED`.
- Kriteria dan sub-item dibuat dengan status `PENDING` dan harus disetujui admin.
- Approve/reject kategori, sub-item, hasil inspeksi, dan review bulanan hanya untuk `admin`.
- Upload: `POST /api/upload/lab` dan `/api/upload/lab/drive` sengaja **tanpa auth**, sesuai source lama.
- Ekspor: Excel inspeksi tunggal, ekspor lab multi-sheet, ekspor semua inspeksi 6 bulan, DOCX laporan peralatan rusak, DOCX laporan perbaikan, dan status laporan.

## 6. Quirk lama yang sengaja tidak diperbaiki

- Bug precedence `OR` pada pengecekan akses laboratorium: `WHERE id = ? AND kalab_id = ? OR plp_id = ? OR teknisi_id = ?`.
- ID sub-item palsu pada respons create category-with-subitems (`categoryId + index + 1`).
- Endpoint `GET /api/criteria/approved` membaca body request.
- `foto` pada damage report disimpan sebagai nama file saja, sementara upload lain memakai prefix `/uploads/`.
- Filter MIME yang gagal melempar exception sehingga menjadi 500 dengan pesan lama.
- Beberapa validasi dan urutan pengecekan error mengikuti source Express, bukan pola Laravel.

## 7. Pemetaan migrasi

| Aspek lama | Implementasi baru |
|---|---|
| Next App Router | React Router 7 + `BrowserRouter`, `App.tsx`, `nextCompat.ts` |
| `next/navigation` | hook kompatibel `useRouter`, `usePathname`, `useParams`, `useSearchParams`, `Outlet` |
| Express routes | `routes/api.php` Laravel |
| Express middleware | `VerifyJwt`, `AuthorizeRole`, `LegacyLoginGate` |
| express-validator | `LegacyValidator` |
| `mysql2` pool | Query Builder / `DB::select` Laravel |
| multer | `$request->file()` + `Legacy::saveUpload()` |
| ExcelJS / docx template | PhpSpreadsheet / PhpWord |
| Google Drive API | `google/apiclient` dengan key file lama |

## 8. Status tahap

- Tahap 1 Analisis: selesai.
- Tahap 2 Struktur project baru: selesai.
- Tahap 3 Backend: selesai (10 controller, 10 model, 10 migrasi, middleware, routes).
- Tahap 4 Frontend: selesai (88 file sumber diregenerasi dengan transformasi Next→Vite, build produksi dan `tsc` sukses).
- Tahap 5 Integrasi: environment, CORS, database terpisah, dependency final dalam proses verifikasi.
- Tahap 6 Testing: pengujian endpoint dan alur per role dilakukan sebelum deklarasi selesai.
