<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coin_id')->nullable()->constrained()->nullOnDelete();
            $table->string('level', 16)->default('info'); // info | warning | error
            $table->string('event', 64);
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['level', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};
