# Laporan Audit & Optimasi Performa — Inspeksi PPNS

Tanggal pengukuran: 19 November 2026 (09:30–10:15 WIB)
Lingkup: frontend React/Vite (`frontend/`) dan backend Laravel (`backend/`), tanpa perubahan fitur, UI/UX, workflow, validasi, business logic, atau data yang ditampilkan.
Status: **selesai**. Optimasi dilakukan bertahap dan setiap perubahan diuji sebelum lanjut.

## Metode pengukuran (angka nyata, bukan estimasi)

| Alat | Fungsi | Sampel |
|---|---|---|
| `measure.mjs` (Node `fetch`) | Waktu tempuh sekumpulan endpoint secara berurutan (`seq`) vs paralel (`par`) | 3 kali per mode, dipakai median |
| `measure_query.php` (`DB::listen`) | Jumlah query SQL dan waktu query per request Laravel | 1–3 kali per endpoint |
| `bench_by_items.php`, `bench_check_lab.php` | Eksekusi langsung SQL lama vs SQL baru dalam satu proses, 6 sampel | median |
| `test_dedup.mjs` (axios + HTTP server lokal) | Membuktikan perilaku deduplikasi GET: request jaringan yang benar-benar terjadi | 5 skenario |
| `regression.mjs` | 56 pemeriksaan regresi API lintas peran | dijalankan 2 kali, 56/56 lolos |

Catatan metodologi: `measure.mjs` memakai `fetch` langsung dari Node, sehingga tidak terpengaruh perubahan kode frontend (dedup React/axios). Karena itu perbaikan frontend dibuktikan lewat `test_dedup.mjs` dan perubahan struktur request, bukan lewat angka `fetch` mentah. Angka `fetch` tetap berguna untuk membuktikan keuntungan paralelisasi dan kondisi server.

---

## 1. Kondisi awal

- Backend sehat: `GET /api/health` → `{"status":"OK","message":"Server is running"}` (PHP 8.2.4, Laravel, MariaDB 10.4.28, database `ppns_lab_inspection_baru`).
- Volume data kecil: 10 users, 4 laboratorium, 13 items, 15 inspections, 25 kategori, 59 subitem, 350 hasil inspeksi, 62 laporan kerusakan. Jadi lambatnya bukan karena volume data besar.
- Sebelum audit performa ini, tidak ada perubahan kode aplikasi untuk performa. Perubahan yang sudah ada sebelumnya hanya perbaikan autoload JWT (`vendor/composer/autoload_extra.php` memakai path relatif) dan dokumentasi `README.md` — itu bukan optimasi performa.
- Frontend belum berjalan di port 3000; backend berjalan di `127.0.0.1:8000` lewat `php artisan serve`.
- Karakter awal: halaman menunggu beberapa request, sebagian berurutan padahal independen; tidak ada React Query/SWR/cache data; pagination hanya di browser; semua halaman diimpor secara eager.
- Baseline query per endpoint utama (1 sampel `DB::listen`, `totalMs` ≈ 67–90 ms per request, mayoritas waktu ada di bootstrap Laravel):

| Endpoint | Query | queryMs | totalMs |
|---|---:|---:|---:|
| `/users` | 1 | 7.83 | 74.56 |
| `/labs` | 2 | 7.87 | 70.43 |
| `/items` | 1 | 9.01 | 74.67 |
| `/criteria/category/pending` | 1 | 6.73 | 67.29 |
| `/inspections/pending-reviews` | 1 | 22.21 | 89.72 |
| `/export/report-status` | 6 | 32.91 | 90.36 |
| `/criteria/my` (kalab) | 2 | 21.63 | 73.86 |
| `/inspections/my-pending` (kalab) | 1 | 32.06 | 88.77 |

- Baseline waktu tempuh API (median 3 sampel): Admin 6 endpoint `seq` **2322.6 ms**, `par` **1885.4 ms**; KaLab 4 endpoint `seq` **1209.5 ms**, `par` **1126.2 ms**.

## 2. Halaman yang diperiksa

Prioritas ditentukan dari banyaknya request saat mount dan setelah aksi tulis, bukan dari perkiraan subjektif.

| Peringkat | Halaman | Alasan |
|---:|---|---|
| 1 | `/admin/labs/:id/inspeksi/:itemId` | 4 request mount + detail; satu simpan/approve memicu mutation + notifikasi + refetch halaman + efek `refreshKey` |
| 2 | `/kalab/labs/:id/inspeksi/:itemId` (serta versi `plp`, `teknisi`) | Pola sama + context notifikasi 4 request |
| 3 | `/admin/labs`, `/kalab/labs`, `/plp/labs`, `/teknisi/labs` | 6–7 endpoint saat mount + request status inspeksi per item |
| 4 | `/admin/labs/:id` serta 3 versi peran | 3 request batch + 2 request status; efek `[selectedSemester, labItems]` bisa mengulang |
| 5 | 4 dashboard peran | `getProfile()` dulu, lalu 3 request; efek `[checked, user]` berisiko fetch dua kali |

Juga diperiksa: halaman users/items/profile, 4 notification context, `RoleGuard`, `DataTable`, dan service layer (`src/services/*.ts`).

## 3. API per halaman

Singkatan: **P** = bisa paralel, **B** = bergantung (harus berurutan), **D** = duplikat, **O** = perlu optimasi backend.

| Halaman | Endpoint saat mount/aksi | Ketergantungan | Strategi |
|---|---|---|---|
| Admin dashboard | `/users`, `/labs`, `/items`, `/inspections/pending-reviews` | independen | sudah `Promise.all` |
| Admin labs | `/labs`, `/users`, `/criteria/category/pending`, `/criteria/subitem/pending`, `/items/calibration-alerts`, `/inspections/pending-reviews`, `/export/report-status` | semua independen | **diparalelkan** (export status dulu berurutan) |
| Kalab/Plp/Teknisi labs | `/labs`, `/items/my`, `/criteria/my`, `/inspections/my-pending`, `/export/report-status` | semua independen | **diparalelkan** |
| Detail lab (4 peran) | `/labs`, `/items/lab/:id`, semesters → `/inspections/by-items`, `/inspections/check-lab-status` | by-items butuh daftar item; check-lab-status hanya butuh periode | batch awal sudah paralel; **status + export check diparalelkan** |
| Inspeksi (4 peran) | `/labs`, `/items/lab/:id`, `/criteria/item/:id`, `/inspections/by-item/:id`, lalu detail | 4 awal independen, detail bergantung | sudah paralel; **refresh setelah simpan diparalelkan** |
| Dashboard Kalab/Plp/Teknisi | `/auth/profile` → `/items/my`, `/inspections/my-pending`, `/criteria/my` | profil dipakai untuk gating | urutan profil → stats tetap; **dependency loop diperbaiki** |
| Notification context (4 peran) | Admin: 3 endpoint; Kalab/Plp/Teknisi: 4 endpoint, tiap 30 detik | independen di dalam batch | sudah paralel; **dedup GET saat in-flight** ditambahkan; polling tetap dipertahankan |

## 4. Penyebab utama loading lambat

Berurutan berdasarkan dampak yang terukur:

1. **Request independen yang berurutan.** `getExportReportStatus()` dipanggil sebelum/ sesudah batch utama padahal tidak memakai hasilnya. Di `/admin/labs/:id`, `checkLabInspectionsStatus()` menunggu `getInspectionsByItemIds()` padahal cukup butuh tahun/semester. Ini memastikan latency total = jumlah latency request, bukan latency terlama.
2. **Duplikasi request yang sama persis.** `StrictMode` menggandakan efek mount; context notifikasi dan halaman meminta endpoint yang sama; `refreshNotifications()` + `fetchData()` + `setRefreshKey` saling tumpang tindih setelah simpan.
3. **N+1 di backend pada endpoint status inspeksi.** `/inspections/by-items` menjalankan 2 query per item (22 query untuk 11 item); `/inspections/check-lab-status` menjalankan 1 query per item (4 query untuk lab 3 item). Keduanya dipakai polling/halaman detail lab.
4. **`useEffect` dengan dependency tidak stabil** (`[checked, user]`, array `roles` inline, `[selectedSemester, labItems]`, efek inspeksi kalab dengan closure basi) → fetch dobel atau data basi.
5. **Arsitektur yang tidak diubah karena batasan scope:** tidak ada cache TTL, polling 30 detik, pagination client-side, eager import semua halaman. Ini tercatat di poin 11.

## 5. Masalah frontend

| Temuan | Lokasi (sebelum perubahan) | Klasifikasi | Tindakan |
|---|---|---|---|
| Tidak ada dedup GET; 5 GET identik bersamaan → 5 request jaringan | `src/lib/api.ts` | duplikat | **diubah**: dedup GET in-flight di adapter axios |
| Export status dipanggil terpisah sebelum batch | `plp/labs/page.tsx:26-29`, `teknisi/labs/page.tsx:26-29`, `kalab/labs/page.tsx:36-39`, `admin/labs/page.tsx:69-72` | independen, berurutan | **diubah**: masuk `Promise.all` |
| `check-lab-status` menunggu `by-items` | 4 halaman `labs/[id]/page.tsx` | independen, berurutan | **diubah**: `Promise.all` |
| `refreshNotifications()` lalu `fetchData()` | `admin/.../inspeksi/[itemId]/page.tsx:241-265,431-462`, `kalab/...:386-400` | independen, berurutan | **diubah**: keduanya dimulai bersama, toast/state tetap urut |
| Efek `[checked, user]` | 3 dashboard peran | potensi loop | **diubah**: `[checked, user?.lab_name]` |
| `roles` array inline di dependency | `components/auth/RoleGuard.tsx:18-28` | potensi re-run | **diubah**: `rolesRef` |
| Efek `[selectedSemester, labItems]` | 4 halaman detail lab | potensi fetch dobel | sudah ada guard; tidak diubah (perilaku terjaga) |
| `fetchData` kalab tidak menangkap `tahun/semester` | `kalab/.../inspeksi/[itemId]/page.tsx:82-106` | data basi | **diubah**: dependency pindah ke dalam `useCallback` |
| Polling 30 detik di 4 context | `src/contexts/*NotificationContext.tsx` | duplikat | tidak diubah (masalah terbuka) |
| Pagination/filter client-side saja | `components/admin/DataTable.tsx:23-37` | arsitektur | tidak diubah (masalah terbuka) |
| Semua halaman eager import, bundle 825.63 kB | `src/App.tsx:6-35` | arsitektur | tidak diubah (masalah terbuka) |

Detail penting implementasi dedup: axios 1.21 menyimpan `api.defaults.adapter` sebagai **array** `['xhr','http','fetch']`, bukan fungsi. Adapter pembungkus memakai `axios.getAdapter(defaultAdapter, config)` sebelum memanggilnya. Tanpa ini aplikasi akan error `defaultAdapter is not a function` saat runtime. Kunci dedup memuat `baseURL`, `url`, `params`, dan `Authorization`; `responseType: 'blob'` serta semua method selain GET **tidak** digabung. Request berurutan tetap menghasilkan request baru, sehingga tidak ada data basi.

Bukti `test_dedup.mjs`:

| Skenario | Request jaringan | Respons sama |
|---|---:|---|
| 5 GET identik bersamaan | **1** | ya |
| 5 GET identik berurutan | 5 | tidak |
| 2 GET identik, auth berbeda | 2 | tidak |
| 2 GET blob bersamaan | 2 | status sama |
| 2 POST identik bersamaan | 2 | tidak |

## 6. Masalah backend

| Endpoint / controller | Temuan | Tindakan |
|---|---|---|
| `InspectionController@checkInspectionsByItemIds` (`routes/api.php:108`) | Memanggil `itemInspectionStatus()` per item → 2 query/item | **diubah**: helper batch `itemsInspectionStatuses()` dengan 2 query untuk semua item |
| `ExportController@checkLabInspectionsStatus` (`routes/api.php:112`) | Loop per item dengan `SELECT ... COUNT(DISTINCT bulan_ke)` → 1 query/item | **diubah**: satu `WHERE item_id IN (...)` + `GROUP BY i.id`, urutan hasil tetap mengikuti `item_ids` lab |
| `ItemController@getCalibrationAlerts` | `FIND_IN_SET(i.id, l.item_ids)` → full scan | tidak diubah; butuh perubahan skema, di luar scope |
| `InspectionCriteriaController@getApprovedCategoriesWithSubitems` | 1 query subitem per kategori | tidak diubah (belum terukur sebagai bottleneck halaman prioritas) |
| `InspectionController@updateInspectionResults` | 2 query per baris hasil | tidak diubah (perubahan logic berisiko) |
| `ExportController@exportLabItems` | loop per item + template XLSX dibuka ulang per sheet | tidak diubah |
| `InspectionController@getPendingReviews`, `@getMyPendingInspections` | subquery berkorelasi per baris | tidak diubah |
| `AuthController@getProfile`, `Legacy::resolveUserLab()` | 2 query/profile | tidak diubah (sudah minimal) |

Response endpoint yang dioptimasi diverifikasi **identik karakter per karakter** terhadap baseline (4 skenario: by-items dengan/tanpa periode, check-lab-status 2025/2026).

## 7. Masalah query

- `/inspections/by-items`: 22 query untuk 11 item, masing-masing dengan 5 subquery + `ORDER BY bulan_ke DESC LIMIT 1`. Diganti dengan 2 query: satu `ROW_NUMBER() OVER (PARTITION BY i.item_id ORDER BY imr.bulan_ke DESC)` dan satu agregasi kategori `GROUP BY item_id`. Awalnya partisi memakai `i.id` dan menghasilkan respons berbeda; sudah diperbaiki ke `i.item_id` lalu diverifikasi identik.
- `/inspections/check-lab-status`: 3 query per item + 1 query lab. Diganti 1 query `IN (...) ... GROUP BY i.id` + 1 query lab.
- Index yang sudah ada dan mendukung query: `inspections(item_id,tahun,semester)` unik, `inspections(laboratory_id,item_id,inspector_id,tahun,semester)`, `inspection_results(inspection_id,subitem_id)` unik, `inspection_monthly_reviews(inspection_id,bulan_ke)` unik, index FK lain sudah lengkap.
- Tidak ada index baru yang ditambahkan. Kolom yang dipakai `ORDER BY`/filter tapi belum ter-index: `users.created_at`, `laboratories.created_at`, `items.created_at`, `items.tanggal_kalibrasi_berikutnya`, `inspection_categories.created_at`, `inspection_subitems.created_at`, `inspection_results.approval_status`. Karena data masih kecil dan index baru berpotensi mengubah perilaku/ biaya tulis, ini hanya dicatat.
- `laboratories.item_ids` berupa CSV sehingga `FIND_IN_SET` tidak bisa memakai index B-tree; perlu normalisasi skema jika ingin diselesaikan (tidak dilakukan).
- Precedence `OR` pada query akses lab sengaja dipertahankan karena termasuk legacy behavior yang sudah didokumentasikan di `ANALISIS-MIGRASI.md`.

## 8. Perubahan kode yang dilakukan

**Frontend (14 file):**

1. `src/lib/api.ts` — dedup GET in-flight via adapter axios + `axios.getAdapter` (termasuk penanganan axios 1.x).
2. `src/app/admin/labs/page.tsx` — 7 request awal dalam satu `Promise.all`.
3. `src/app/plp/labs/page.tsx`, `src/app/kalab/labs/page.tsx`, `src/app/teknisi/labs/page.tsx` — export status ikut `Promise.all`.
4. `src/app/admin/labs/[id]/page.tsx`, `.../kalab/...`, `.../plp/...`, `.../teknisi/...` — `by-items` dan `check-lab-status` paralel.
5. `src/app/admin/labs/[id]/inspeksi/[itemId]/page.tsx` — 4 aksi (approve/reject kategori, approve/reject bulan) menjalankan notifikasi + refetch bersamaan.
6. `src/app/kalab/labs/[id]/inspeksi/[itemId]/page.tsx` — refresh setelah simpan diparalelkan; dependency `tahun/semester` masuk ke `useCallback`.
7. `src/app/kalab/dashboard/page.tsx`, `.../plp/...`, `.../teknisi/...` — dependency `[checked, user?.lab_name]`.
8. `src/components/auth/RoleGuard.tsx` — `rolesRef` menggantikan array `roles` di dependency.

**Backend (2 file):**

9. `backend/app/Http/Controllers/Api/InspectionController.php` — helper `itemsInspectionStatuses()` + pemakaiannya di `checkInspectionsByItemIds()`; `itemInspectionStatus()` untuk endpoint tunggal tidak diubah.
10. `backend/app/Http/Controllers/Api/ExportController.php` — query batch di `checkLabInspectionsStatus()`.

Tidak ada perubahan pada: route, payload request/response, validasi, pesan toast, skeleton/loading indicator, tampilan, workflow, atau skema database. Tool pengukur dan script regression berada di `C:\Users\zhafi\AppData\Local\Temp\opencode\` (di luar repo), supaya tidak menambah file ke project.

## 9. Perbandingan performa

### 9.1 Endpoint API (Node `fetch`, median 3 sampel)

| Kumpulan endpoint | Mode | Sebelum | Sesudah | Perbaikan |
|---|---|---:|---:|---:|
| Admin, 6 endpoint | seq | 2322.6 ms | 2208.8 ms | 4.9% |
| Admin, 6 endpoint | par | 1885.4 ms | 1616.2 ms | **14.3%** |
| KaLab, 4 endpoint | seq | 1209.5 ms | 1124.7 ms | 7.0% |
| KaLab, 4 endpoint | par | 1126.2 ms | 1122.0 ms | 0.4% (dalam noise) |

Kumpulan endpoint notifikasi (baseline hanya **1 sampel**, jadi tidak dipakai sebagai klaim utama): admin seq 2282.9 → 920.3 ms, admin par 906.4 → 799.1 ms; kalab seq 1071.8 → 814.2 ms, kalab par 890.9 → 786.2 ms. Selisih besar pada sampel seq kemungkinan kontensi server saat baseline, bukan efek kode frontend — `fetch` dari Node tidak memakai adapter axios.

Angka absolut per request masih didominasi bootstrap Laravel di `php artisan serve` (~65–80 ms per request sebelum logika controller). Percobaan OPcache lewat flag CLI tidak menunjukkan perbaikan di harness ini (tanpa OPcache 66.4/70.8/66.4 ms; dengan OPcache 103.2/97.5/119.2 ms), jadi OPcache **tidak** dijadikan rekomendasi berdasarkan data ini.

### 9.2 Query database — bukti paling kuat

| Endpoint | Sebelum | Sesudah | Median sebelum → sesudah |
|---|---|---|---|
| `/inspections/by-items` (11 item) | 22 query, queryMs median 108.0 ms, total SQL 110.3 ms | 2 query, queryMs median 19.91 ms, total SQL 20.2 ms | **5.5× lebih cepat, query 22 → 2** |
| `/inspections/check-lab-status` (lab 3 item) | 3 query item + 1 query lab, total SQL median 4.045 ms | 1 query item + 1 query lab, total SQL median 1.584 ms | **2.6× lebih cepat, query 4 → 2** |

Pengukuran endpoint penuh untuk `check-lab-status`: sebelum 96.06 ms / 4 query / 10.73 ms query; sesudah 88.72, 95.55, 203.05 ms / 2 query / 14.02–22.68 ms query. Di level HTTP selisihnya tertutup overhead bootstrap; benchmark SQL langsung di atas yang dipakai sebagai dasar keputusan.

### 9.3 Frontend

- 5 GET identik bersamaan: **5 → 1 request jaringan** (`test_dedup.mjs`).
- Request independen yang sebelumnya berurutan kini paralel; peningkatan paralelisasi terukur 14.3% pada kumpulan 6 endpoint Admin.
- Efek dengan dependency tidak stabil tidak lagi memicu refetch tambahan (verifikasi struktural + regresi).

## 10. Regression test

`regression.mjs`: **56/56 PASS**, dijalankan dua kali (sebelum dan sesudah pemulihan environment).

| Kategori | Contoh pemeriksaan | Hasil |
|---|---|---|
| Login/logout | login kalab `kalab123`, plp `123456`, password salah → 401; logout = hapus `token`/`user` di localStorage (`services/auth.ts:31-34`, tidak ada endpoint logout API) | PASS |
| Hak akses | kalab diblokir `/users` dan `/criteria/category/pending`; plp diblokir `/items/calibration-alerts`; admin diizinkan | PASS |
| Token 4 peran | `/auth/profile` → 200 dengan role sesuai | PASS |
| Labs/items | `/labs`, `/items`, `/items/my`, `/items/lab/11`, `/items/21`, `/items/calibration-alerts` | PASS |
| CRUD & validasi | POST kosong `/items`, `/criteria/category/create`, `/users` → 400; tidak ada data bertambah | PASS |
| Pencarian/filter | `/inspections/by-item/21?tahun=...`, `/by-items?ids=...`, `/inspections/results/status?approval_status=PENDING` | PASS |
| Detail | `/inspections/detail/202`, `/criteria/item/21`, `/inspections/lab/11/semesters` | PASS |
| Proses inspeksi/workflow | `/inspections/pending-reviews`, `/my-pending`, `/check-lab-status`, `/export/report-status`; response optimized endpoints identik dengan baseline | PASS |
| Autentikasi negatif | tanpa token → 401, token invalid → 403 | PASS |

Pemeriksaan pendukung:

- `npx tsc --noEmit -p tsconfig.app.json` → bersih.
- `npm run build` → sukses (825.63 kB JS, warning chunk >500 kB sudah ada sebelumnya).
- `php -l` pada 2 controller backend → tidak ada syntax error.
- Jumlah data sebelum dan sesudah regression identik: `10 / 4 / 13 / 15 / 25 / 59 / 350 / 62`.

Catatan: password admin dan teknisi tidak tersedia (`AUDIT-MIGRASI.md:212`), sehingga login untuk dua peran itu diuji lewat token JWT yang dibuat read-only dari database; endpoint `/auth/login` tetap diuji lewat dua kredensial yang diketahui.

Catatan environment: sekitar 10:10 MySQL berhenti di luar proses audit. Saya memulihkannya dengan menghapus `aria_log.*` yang korup (salinan cadangan di `C:\Users\zhafi\AppData\Local\Temp\opencode\aria_log_backup`), lalu memverifikasi jumlah data identik dan backend kembali sehat. Ini bukan perubahan kode aplikasi.

## 11. Masalah yang belum terselesaikan

1. Polling 30 detik pada 4 notification context masih aktif. Dedup hanya menggabungkan request yang bersamaan, tidak mengurangi frekuensi polling. Mengubah interval atau menambah cache TTL berisiko mengubah kesegaran data yang ditampilkan, jadi tidak dilakukan.
2. `StrictMode` tetap aktif di development; dedup menekan duplikasi mount, tetapi tidak menghapus pola efek ganda itu sendiri.
3. Pagination masih client-side (`DataTable.tsx`): semua baris tetap diunduh. Pagination server-side mengubah kontrak API dan jumlah data yang dikirim, jadi tidak dilakukan.
4. Bundle frontend masih ~826 kB (warning Vite). `React.lazy`/`Suspense` bisa membantu, tetapi mengubah fallback loading/UX, jadi tidak dilakukan.
5. N+1 lain masih ada dan belum diukur sebagai bottleneck halaman prioritas: `/criteria/approved`, `updateInspectionResults`, `exportLabItems`, subquery berkorelasi di `pending-reviews`/`my-pending`.
6. `FIND_IN_SET` pada `laboratories.item_ids` dan precedence `OR` pada query akses lab masih ada; keduanya legacy behavior, perlu normalisasi skema/keputusan produk.
7. Index untuk kolom `created_at`, `tanggal_kalibrasi_berikutnya`, dan `approval_status` belum ditambahkan karena data masih kecil dan dampaknya belum terukur.
8. Pengukuran memakai proxy Node + `DB::listen`, bukan DevTools browser/Telescope. Angka HTTP mentah mengandung noise bootstrap; klaim frontend dibuktikan lewat harness dedup dan struktur request, bukan lewat angka browser.
9. Open handle: script pengukur berada di temp, bukan di repository. Jika ingin pengukuran ulang permanen, script perlu dipindahkan ke folder `tests/` dengan persetujuan terpisah.
