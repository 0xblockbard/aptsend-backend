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
        Schema::create('tweet_commands', function (Blueprint $table) {
            $table->id();
            $table->string('tweet_id')->unique();
            $table->string('author_username');
            $table->string('author_user_id');
            $table->text('raw_text');
            
            $table->dateTime("tweet_created_at");

            $table->timestamps();
            $table->softDeletes();

            // Transfer details
            $table->unsignedBigInteger('amount');
            $table->string('token')->default('APT');
            $table->tinyInteger('status')->default(0); // 0 - unprocessed, 1 - processed, 2 - being processed (e.g. need to lookup to_user_id)
            $table->tinyInteger('processed')->default(0); // 0 - transfer not sent, 1 - transfer sent and processed

            // Recipient details
            $table->string('to_channel'); // 'twitter', 'discord', 'telegram', 'evm', 'sol', 'google'
            $table->string('to_user_id')->nullable(); // External user ID or address

            $table->index('status');
            $table->index('tweet_id');
            $table->index('author_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tweet_commands');
    }
};
