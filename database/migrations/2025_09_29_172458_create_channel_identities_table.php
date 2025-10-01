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
        Schema::create('channel_identities', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('user_id')->nullable();
            $table->string('channel');
            $table->string('channel_user_id');
            
            $table->text('credentials')->nullable(); // encrypted JSON
            $table->timestamp('token_expires_at')->nullable();
            $table->json('metadata')->nullable();

            $table->string('target_vault_address')->nullable();    // The vault for this route

            $table->enum('registration_type', ['self', 'recipient'])->default('self');

            $table->tinyInteger('vault_status')->default(0);   // 0=temp, 1=linked
            $table->timestamp('claimed_at')->nullable();       // When user claimed this identity
            $table->timestamps();
            
            $table->unique(['channel', 'channel_user_id']);
            $table->index('target_vault_address');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('channel_identities');
    }
};
