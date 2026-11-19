<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('items', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->string('nama_barang');
            $table->string('kode_barang', 100);
            $table->string('pembuat_alat')->nullable();
            $table->date('tanggal_pembelian')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
            $table->date('tanggal_kalibrasi_terakhir')->nullable();
            $table->date('tanggal_kalibrasi_berikutnya')->nullable();

            $table->unique('kode_barang', 'kode_barang');
            $table->index('kode_barang', 'idx_kode_barang');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('items');
    }
};
