# Inspeksi PPNS — Migrasi React Vite + Laravel + MySQL

Folder ini berisi hasil migrasi dari project lama:

- `Frontend_inspeksi/` (Next.js) → `frontend/` (React + Vite)
- `Backend_inspeksi/` (Express.js) → `backend/` (Laravel)

Folder lama `Frontend_inspeksi/` dan `Backend_inspeksi/` **tidak diubah sama sekali**. Database baru juga sengaja dipisahkan menjadi `ppns_lab_inspection_baru` agar data lama tidak tersentuh saat pengujian.

## Struktur

```text
./
├── ANALISIS-MIGRASI.md
├── AUDIT-MIGRASI.md
├── README.md
├── tests/
│   ├── smoke.ps1
│   ├── smoke-output.txt
│   ├── integration.ps1
│   └── integration-output.txt
├── frontend/
│   ├── public/                  # aset statis lama
│   ├── src/
│   │   ├── app/                 # halaman & layout hasil copy lama
│   │   ├── components/          # komponen UI lama
│   │   ├── contexts/            # auth/global context lama
│   │   ├── lib/
│   │   │   ├── api.ts           # axios, token, fotoUrl, redirect 401
│   │   │   └── nextCompat.ts    # pengganti next/navigation
│   │   ├── App.tsx              # semua rute React Router
│   │   └── main.tsx
│   ├── .env                     # VITE_API_URL
│   ├── index.html
│   ├── vite.config.ts
│   └── package.json
└── backend/
    ├── app/
    │   ├── Http/Controllers/Api/  # 10 controller hasil port
    │   ├── Http/Middleware/        # JWT, role, login gate
    │   ├── Models/                 # 10 model Eloquent
    │   └── Support/                # Api, Legacy, LegacyValidator
    ├── database/
    │   ├── migrations/             # 10 migrasi skema
    │   └── sql/
    │       ├── ppns_lab_inspection_Laragon.sql  # dump data lama
    │       ├── schema-live.sql                  # skema hasil dump
    │       └── full_setup.sql                   # skema + data untuk DB baru
    ├── public/uploads/             # 38 file upload lama
    ├── storage/app/
    │   ├── assets/                 # logo PPNS
    │   ├── credentials/            # service account Google Drive
    │   └── templates/              # template Excel/DOCX lama
    ├── routes/api.php
    ├── bootstrap/app.php
    └── .env
```

## Tech stack

### Frontend
- React 19.2.x
- Vite 8.3.x
- TypeScript 5.9.x
- React Router 7.9.x
- axios 1.17.x
- Tailwind CSS 4 + PostCSS
- shadcn/ui, Radix/Base UI, lucide-react, recharts, react-hook-form, zod, sonner

### Backend
- PHP 8.2.4
- Laravel 12.73.x
- MySQL / MariaDB 10.4+
- `firebase/php-jwt` (JWT payload lama dipertahankan)
- `maatwebsite/excel` / PhpSpreadsheet
- `phpoffice/phpword`
- `google/apiclient`

## Prasyarat

- Node.js ≥ 20 (telah diuji dengan Node v24.18.0, npm 11.14.1)
- PHP ≥ 8.2 dengan ekstensi `pdo_mysql`, `mbstring`, `xml`, `zip`, `gd`, `fileinfo`
- Composer 2.x
- MySQL/MariaDB berjalan pada `127.0.0.1:3306`
- Akun MySQL dengan akses create/insert (default `.env`: `root` tanpa password)

## Setup database

Database baru memakai nama `ppns_lab_inspection_baru`.

```powershell
& 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -u root -e "CREATE DATABASE IF NOT EXISTS ppns_lab_inspection_baru CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

Get-Content -LiteralPath 'backend\database\sql\full_setup.sql' -Raw |
  & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -u root -D ppns_lab_inspection_baru
```

`full_setup.sql` sudah berisi skema + seluruh data dari dump lama. **Jangan menjalankan `php artisan migrate` setelah import ini**, karena migrasi akan membuat tabel yang sudah ada. Migrasi disimpan sebagai referensi skema dan untuk instalasi tanpa data.

Alternatif lain: skema dibuat oleh migrasi, lalu data diisi oleh seeder yang membaca `database/sql/ppns_lab_inspection_Laragon.sql` (berisi `INSERT` saja, tanpa `CREATE/DROP`):

```powershell
cd backend
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan db:seed --force
```

Seeder aman dijalankan ulang: jika salah satu tabel sudah berisi data, proses seeding dilewati sehingga tidak menduplikasi atau menimpa data. Karena itu `db:seed` juga boleh dijalankan setelah `full_setup.sql` (akan otomatis skip).

Jika ingin membuat skema kosong saja tanpa data:

```powershell
C:\xampp\php\php.exe artisan migrate --force
```

## Setup backend

Prasyarat: PHP ≥ 8.2, Composer, MySQL/MariaDB. Di mesin ini `php` belum ada di PATH, jadi contoh memakai `C:\xampp\php\php.exe`; di komputer lain bisa memakai `php` biasa.

```powershell
cd backend
composer install
copy .env.example .env
C:\xampp\php\php.exe artisan key:generate
C:\xampp\php\php.exe artisan serve --host=0.0.0.0 --port=8000
```

`.env.example` sudah berisi `DB_*`, `JWT_SECRET`, dan `GOOGLE_DRIVE_FOLDER_ID`, jadi biasanya tidak perlu diedit untuk lokal.

Environment penting:

```dotenv
APP_URL=http://localhost:8000
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ppns_lab_inspection_baru
DB_USERNAME=root
DB_PASSWORD=
DB_TIMEZONE=+07:00
JWT_SECRET=your_very_secret_jwt_key_change_this_in_production_12345
GOOGLE_DRIVE_FOLDER_ID=1MWhbizYZubnr6M4eABmEwDAEWzOsWtNN
```

Cek kesehatan:

```powershell
curl http://localhost:8000/api/health
```

## Setup frontend

```powershell
cd frontend
npm install
copy .env.example .env
npm run dev
```

Frontend berjalan di `http://localhost:3000`. Isi `.env`:

```dotenv
VITE_API_URL=http://localhost:8000/api
```

Build produksi:

```powershell
npm run build
```

## Akun dan role

Data user lama ikut ter-import. Role yang didukung: `admin`, `kalab`, `plp`, `teknisi`.

Setelah login, redirect mengikuti source lama:

| Role | Dashboard |
|---|---|
| admin | `/admin/dashboard` |
| kalab | `/kalab/dashboard` |
| plp | `/plp/dashboard` |
| teknisi | `/teknisi/dashboard` |

`localStorage.token` dan `localStorage.user` tetap dipakai. Response envelope tetap `{ success, message?, data?, total? }`.

## Endpoint utama

| Grup | Prefix |
|---|---|
| Auth | `/api/auth` |
| Users | `/api/users` |
| Labs | `/api/labs` |
| Items | `/api/items` |
| Schedules | `/api/schedules` |
| Criteria | `/api/criteria` |
| Inspections | `/api/inspections` |
| Damage reports | `/api/damage-reports` |
| Upload | `/api/upload` |
| Export | `/api/export` |

Endpoint upload lama sengaja tetap tanpa middleware auth. Detail pemetaan ada di `ANALISIS-MIGRASI.md`.

## File statis dan template

- Upload langsung disalin ke `backend/public/uploads/` dan dibuka Laravel dari path `/uploads/...`.
- Template Excel/DOCX ada di `backend/storage/app/templates/`.
- Logo ada di `backend/storage/app/assets/`.
- Service account Google Drive ada di `backend/storage/app/credentials/sim-inspeksi-ppns-69f360b30428.json`.

## Performa

Status inspeksi banyak item diambil lewat satu request:

```text
GET /api/inspections/by-items?ids=21,29,81&tahun=2026&semester=GANJIL
```

Endpoint ini dipakai otomatis oleh layout notifikasi dan halaman lab; endpoint lama `/api/inspections/by-item/{id}` tetap ada dan responsnya tidak berubah.

Untuk mempercepat bootstrap Laravel saat memakai `php artisan serve`, aktifkan OPcache di `C:\xampp\php\php.ini`:

```ini
zend_extension=php_opcache.dll
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.validate_timestamps=1
opcache.revalidate_freq=0
```

Restart Apache/CLI setelah mengubah `php.ini`. Tanpa OPcache, pengukuran lokal 13 request status item ≈ 3,5 detik; dengan OPcache + request batch ≈ 0,15 detik.

## Catatan kompatibilitas

Behaviors lama yang sengaja dipertahankan (bukan bug baru): precedence `OR` pada query akses lab, ID sub-item palsu di respons create-with-subitems, pembacaan body pada `GET /api/criteria/approved`, format foto damage report, pesan error 500 dari filter MIME, dan rute frontend phantom. Lihat `ANALISIS-MIGRASI.md` bagian 6.

## Pengujian

Jalankan setelah backend dan frontend aktif. Kredensial contoh di bawah memakai password sementara hanya untuk pengujian; gunakan password asli dari dump/data produksi untuk pemakaian normal.

```powershell
$common = @{
  Base = 'http://127.0.0.1:8000/api'
  AdminEmail = 'admin@ppns.ac.id'; AdminPassword = '<password>'
  KalabEmail = 'kalab3@ppns.ac.id'; KalabPassword = '<password>'
  PlpEmail = 'plp1@gmail.com'; PlpPassword = '<password>'
  TeknisiEmail = 'teknisi1@gmail.com'; TeknisiPassword = '<password>'
}

powershell -ExecutionPolicy Bypass -File tests\smoke.ps1 @common
powershell -ExecutionPolicy Bypass -File tests\integration.ps1 @common
```

- `smoke.ps1`: 28 pemeriksaan health, 401 tanpa token, login keempat role, dan endpoint representatif per role.
- `integration.ps1`: 73 pemeriksaan CRUD, approval kriteria, alur inspeksi 6 bulan, damage report, upload, seluruh export, gate role, validasi, dan pesan error lama. Skrip membuat dan menghapus data uji sendiri via API; jalankan pada database uji yang disposable.

## Pindah folder atau clone dari GitHub

**Pindah folder saja:** tidak perlu setup ulang. `backend/vendor/`, `backend/.env`, dan `frontend/node_modules/` ikut berpindah. Autoloader sudah memakai path relatif lewat `$vendorDir = dirname(__DIR__)`, jadi `Firebase\JWT\JWT` tetap ditemukan di lokasi baru. Cukup jalankan ulang servernya:

```powershell
cd backend
C:\xampp\php\php.exe artisan serve --host=0.0.0.0 --port=8000
```

```powershell
cd frontend
npm run dev
```

**Clone dari GitHub / komputer baru:** iya, setup ulang diperlukan. Yang **tidak ikut ter-commit** adalah `backend/vendor/`, `backend/.env`, `frontend/node_modules/`, `frontend/.env`, dan `frontend/public/build/`. `composer.lock` ikut, jadi versi dependency tetap sama.

Urutannya:

```powershell
# 1. Backend
cd backend
composer install
copy .env.example .env
C:\xampp\php\php.exe artisan key:generate

# 2. Database: pilih salah satu
#    a) import dump lengkap
Get-Content -LiteralPath 'database\sql\full_setup.sql' -Raw |
  & 'C:\xampp\mysql\bin\mysql.exe' -h 127.0.0.1 -u root -D ppns_lab_inspection_baru
#    b) atau migrasi + seeder
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan db:seed --force

# 3. Jalankan backend
C:\xampp\php\php.exe artisan serve --host=0.0.0.0 --port=8000
```

```powershell
# 4. Frontend (terminal terpisah)
cd frontend
npm install
copy .env.example .env
npm run dev
```

Cek health: `curl http://localhost:8000/api/health`.

Kalau `composer install` / `composer dump-autoload` hang seperti di mesin ini, patch autoload di bawah perlu diterapkan lagi setelah `vendor/` terbentuk. Karena `vendor/` tidak masuk Git, simpan salinan `backend/vendor/composer/autoload_extra.php` dan `autoload_real.php` sebelum menghapus/restore `vendor/`.

## Catatan autoload Windows

Pada lingkungan Windows ini, `composer dump-autoload` tidak selesai (hang). Autoloader yang dipakai sudah diperbaiki secara lokal:

- `backend/vendor/composer/autoload_extra.php` berisi pemetaan PSR-4/PSR-0 dan `files` yang hilang dari `autoload_static.php`, dibangun dari `vendor/composer/installed.json`.
- `backend/vendor/composer/autoload_real.php` dimodifikasi untuk mendaftarkan pemetaan tersebut dan menghindari duplikasi `files` lewat `realpath()`.

Jika `vendor/` diInstal ulang, jalankan `composer dump-autoload` pada mesin yang mendukung, atau regenerasi `autoload_extra.php` dan terapkan lagi patch `autoload_real.php`. `autoload_extra.php` sekarang memakai `$vendorDir = dirname(__DIR__)`, sehingga sudah relatif terhadap lokasi `vendor/` dan tidak perlu diubah lagi saat folder proyek dipindah.

## Status verifikasi

Semua angka di bawah adalah hasil eksekusi nyata, bukan estimasi.

| Pemeriksaan | Hasil |
|---|---|
| `npx tsc --noEmit -p tsconfig.app.json` | Lolos, 0 error |
| `npm run build` (Vite 8.3.8) | Lolos, 2656 modules, hanya chunk-size warning |
| `php -l` seluruh PHP baru | 62/62 file lolos |
| `artisan route:list --path=api --json` | 73 rute, sama dengan inventaris Express |
| Perbandingan skema | 10 tabel, 96 kolom identik (tipe, nullability, default, `EXTRA`) |
| `tests/smoke.ps1` | 28/28 lolos |
| `tests/integration.ps1` | 73/73 lolos |
| `compare_exact.js` (audit Express vs Laravel) | 38 endpoint, 0 beda |
| `api_audit.ps1` | 97 kasus dijalankan; 7 gagal hanya karena ID uji lama yang tidak ada di dump, bukan defect |
| Export XLSX/DOCX | Nama sheet, template, font, dan media byte-identik |
| Database akhir | `full_setup.sql` di-import ulang; hash password asli terverifikasi; 10 users, 4 labs, 17 items, 19 inspections, 389 results, 62 damage reports |

Detail audit lengkap ada di `AUDIT-MIGRASI.md`. Render browser juga dicek memakai Chrome headless: halaman login/admin dan dashboard `kalab` ter-render dengan benar (DOM memuat `Login Portal`, `Selamat Datang`, dan `Inspeksi Pending`). Perbandingan piksel-per-piksel antar dua stack tidak dilakukan, tetapi source JSX identik dan `globals.css` byte-identik. Fallback `POST /api/inspections/create` tanpa `checklist_results` tetap menghasilkan error 500 `Column 'status' cannot be null` pada MariaDB lokal, sama seperti source Express yang memasukkan `NULL` eksplisit ke kolom `NOT NULL`; jalur normal UI mengirim `checklist_results` dan lolos.
#
