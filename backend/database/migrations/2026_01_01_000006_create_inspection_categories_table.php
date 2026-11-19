<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_categories', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->string('nama_kategori');
            $table->text('deskripsi')->nullable();
            $table->integer('urutan')->nullable()->default(1);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('APPROVED');
            $table->text('alasan_penolakan')->nullable();
            $table->integer('created_by')->nullable();
            $table->integer('laboratory_id')->nullable();
            $table->integer('item_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('created_by', 'created_by');
            $table->index('urutan', 'idx_urutan');
            $table->index('status', 'idx_status');
            $table->index('laboratory_id', 'idx_laboratory_id');
            $table->index('item_id', 'item_id');
            $table->foreign('created_by', 'inspection_categories_ibfk_1')->references('id')->on('users')->nullOnDelete();
            $table->foreign('laboratory_id', 'inspection_categories_ibfk_2')->references('id')->on('laboratories')->nullOnDelete();
            $table->foreign('item_id', 'inspection_categories_ibfk_3')->references('id')->on('items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_categories');
    }
};
