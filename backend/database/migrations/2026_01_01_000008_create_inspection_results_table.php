<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_results', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('inspection_id');
            $table->integer('subitem_id');
            $table->integer('bulan_ke')->default(1);
            $table->enum('status', ['B', 'K', 'N/A'])->default('B');
            $table->text('alasan_penolakan')->nullable();
            $table->text('keterangan')->nullable();
            $table->enum('approval_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['inspection_id', 'subitem_id', 'bulan_ke'], 'idx_unique_inspec_subitem_month');
            $table->index('inspection_id', 'idx_inspection_id');
            $table->index('subitem_id', 'idx_subitem_id');
            $table->foreign('inspection_id', 'inspection_results_ibfk_1')->references('id')->on('inspections')->cascadeOnDelete();
            $table->foreign('subitem_id', 'inspection_results_ibfk_2')->references('id')->on('inspection_subitems')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_results');
    }
};
