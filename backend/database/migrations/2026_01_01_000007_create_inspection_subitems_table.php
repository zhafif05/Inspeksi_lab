<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_subitems', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('category_id');
            $table->string('nama_subitem');
            $table->text('deskripsi')->nullable();
            $table->integer('urutan')->nullable()->default(1);
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('APPROVED');
            $table->text('alasan_penolakan')->nullable();
            $table->integer('created_by')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('created_by', 'created_by');
            $table->index('category_id', 'idx_category_id');
            $table->index('urutan', 'idx_urutan');
            $table->index('status', 'idx_status');
            $table->foreign('category_id', 'inspection_subitems_ibfk_1')->references('id')->on('inspection_categories')->cascadeOnDelete();
            $table->foreign('created_by', 'inspection_subitems_ibfk_2')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_subitems');
    }
};
