<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->charset = 'latin1';
            $table->collation = 'latin1_swedish_ci';

            $table->integer('id', true, false)->primary();
            $table->string('name');
            $table->string('nip', 50)->nullable()->default('');
            $table->string('email');
            $table->string('password');
            $table->enum('role', ['admin', 'kalab', 'plp', 'teknisi']);
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique('email', 'email');
            $table->index('email', 'idx_email');
            $table->index('role', 'idx_role');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
