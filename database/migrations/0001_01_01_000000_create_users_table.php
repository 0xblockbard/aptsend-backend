<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('owner_address')->unique();
            $table->string('primary_vault_address')->unique()->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('owner_address');
            $table->index('primary_vault_address');
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
