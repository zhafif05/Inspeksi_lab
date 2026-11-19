<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_damage_reports', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('inspection_result_id');
            $table->integer('inspection_id');
            $table->integer('laboratory_id');
            $table->integer('item_id');
            $table->integer('category_id');
            $table->integer('subitem_id');
            $table->text('kondisi_kerusakan')->nullable();
            $table->text('penyebab_kerusakan')->nullable();
            $table->string('status_peralatan', 50)->nullable();
            $table->string('foto')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->index('inspection_result_id', 'inspection_result_id');
            $table->index('inspection_id', 'inspection_id');
            $table->index('laboratory_id', 'laboratory_id');
            $table->index('item_id', 'item_id');
            $table->index('category_id', 'category_id');
            $table->index('subitem_id', 'subitem_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_damage_reports');
    }
};
