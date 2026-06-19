<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_bots', function (Blueprint $table) {
            // Kisa, filtrelenebilir etiket (orn. "uzun vadeli", "test", "yuksek risk").
            $table->string('tag', 40)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('trade_bots', function (Blueprint $table) {
            $table->dropColumn('tag');
        });
    }
};
