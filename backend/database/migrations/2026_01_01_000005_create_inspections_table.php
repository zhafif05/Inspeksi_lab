<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspections', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('laboratory_id');
            $table->integer('item_id');
            $table->year('tahun');
            $table->enum('semester', ['GANJIL', 'GENAP']);
            $table->integer('inspector_id');
            $table->dateTime('tanggal_inspeksi');
            $table->text('catatan')->nullable();
            $table->string('foto')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['item_id', 'tahun', 'semester'], 'unique_item_semester');
            $table->index('laboratory_id', 'idx_laboratory_id');
            $table->index('item_id', 'idx_item_id');
            $table->index('inspector_id', 'idx_inspector_id');
            $table->index('tanggal_inspeksi', 'idx_tanggal_inspeksi');
            $table->index(['tahun', 'semester'], 'idx_tahun_semester');
            $table->foreign('laboratory_id', 'inspections_ibfk_1')->references('id')->on('laboratories')->cascadeOnDelete();
            $table->foreign('item_id', 'inspections_ibfk_2')->references('id')->on('items')->cascadeOnDelete();
            $table->foreign('inspector_id', 'inspections_ibfk_3')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspections');
    }
};
