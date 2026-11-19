<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_monthly_reviews', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->integer('inspection_id');
            $table->integer('bulan_ke');
            $table->enum('review_status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->integer('reviewed_by')->nullable();
            $table->text('alasan_penolakan')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['inspection_id', 'bulan_ke'], 'unique_inspection_month');
            $table->index('reviewed_by', 'reviewed_by');
            $table->index('review_status', 'idx_review_status');
            $table->foreign('inspection_id', 'inspection_monthly_reviews_ibfk_1')->references('id')->on('inspections')->cascadeOnDelete();
            $table->foreign('reviewed_by', 'inspection_monthly_reviews_ibfk_2')->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_monthly_reviews');
    }
};
