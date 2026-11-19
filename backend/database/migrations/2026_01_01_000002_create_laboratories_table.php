<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laboratories', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->string('nama_lab');
            $table->string('lokasi');
            $table->integer('kalab_id')->nullable();
            $table->integer('plp_id')->nullable();
            $table->integer('teknisi_id')->nullable();
            $table->string('item_ids', 1000)->nullable();
            $table->string('report_file');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('nama_lab', 'idx_nama_lab');
            $table->index('kalab_id', 'fk_kalab');
            $table->index('plp_id', 'fk_lab_plp1');
            $table->index('teknisi_id', 'fk_lab_plp2');
            $table->foreign('kalab_id', 'fk_kalab')->references('id')->on('users')->nullOnDelete();
            $table->foreign('plp_id', 'fk_lab_plp1')->references('id')->on('users')->nullOnDelete();
            $table->foreign('teknisi_id', 'fk_lab_plp2')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laboratories');
    }
};
