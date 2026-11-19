<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    /** @var array<int, string> */
    private const TABLES = [
        'users',
        'laboratories',
        'items',
        'schedules',
        'inspections',
        'inspection_categories',
        'inspection_subitems',
        'inspection_results',
        'inspection_monthly_reviews',
        'inspection_damage_reports',
    ];

    /**
     * Isi tabel dari dump data lama yang sudah ada di folder database/sql.
     *
     * File ppns_lab_inspection_Laragon.sql berisi INSERT saja (tanpa CREATE/DROP),
     * sehingga aman dijalankan setelah `php artisan migrate` pada database kosong.
     */
    public function run(): void
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException(
                    "Tabel {$table} belum ada. Jalankan dulu: php artisan migrate --force"
                );
            }
        }

        // Jangan pernah menimpa data yang sudah ada, termasuk kondisi setengah terisi.
        foreach (self::TABLES as $table) {
            if (DB::table($table)->exists()) {
                $this->command?->info(
                    'Data sudah ada. Seeder dilewati agar tidak menduplikasi/menimpa data.'
                );

                return;
            }
        }

        $path = database_path('sql/ppns_lab_inspection_Laragon.sql');

        if (! is_file($path)) {
            throw new RuntimeException("File dump tidak ditemukan: {$path}");
        }

        $sql = file_get_contents($path);

        if ($sql === false) {
            throw new RuntimeException("Gagal membaca file dump: {$path}");
        }

        try {
            DB::transaction(fn () => DB::unprepared($sql));
        } finally {
            // Dump menonaktifkan FK check di awal. Kembalikan walau terjadi kegagalan.
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
            DB::statement("SET time_zone = IFNULL(@OLD_TIME_ZONE, 'system')");
            DB::statement("SET SQL_MODE = IFNULL(@OLD_SQL_MODE, '')");
        }

        $this->command?->info(
            'Seeder selesai: data dari ppns_lab_inspection_Laragon.sql berhasil diimport.'
        );
    }
}
