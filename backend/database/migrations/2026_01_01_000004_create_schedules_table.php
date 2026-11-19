<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedules', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('laboratory_id');
            $table->date('tanggal');
            $table->string('keterangan')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('laboratory_id', 'idx_laboratory_id');
            $table->index('tanggal', 'idx_tanggal');
            $table->foreign('laboratory_id', 'schedules_ibfk_1')->references('id')->on('laboratories')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedules');
    }
};
