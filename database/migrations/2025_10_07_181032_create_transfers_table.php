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
        Schema::create('transfers', function (Blueprint $table) {
            $table->id();
            
            // Source information
            $table->string('source_type'); // 'tweet', 'discord', 'telegram'
            
            // Sender details
            $table->string('from_channel'); // 'twitter', 'discord', 'telegram'
            $table->string('from_user_id'); // External user ID (twitter user_id, discord id, etc.)
            
            // Recipient details
            $table->string('to_channel'); // 'twitter', 'discord', 'telegram', 'evm', 'sol', 'google'
            $table->string('to_user_id'); // External user ID or address
            
            // Transfer details
            $table->unsignedBigInteger('amount');
            $table->string('token')->default('APT');
            
            // Processing status
            $table->tinyInteger('status')->default(0);
            $table->string('tx_hash')->nullable();
            $table->json('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            
            $table->timestamps();
            $table->softDeletes();
            
            $table->index(['from_channel', 'from_user_id']);
            $table->index(['to_channel', 'to_user_id']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transfers');
    }
};
