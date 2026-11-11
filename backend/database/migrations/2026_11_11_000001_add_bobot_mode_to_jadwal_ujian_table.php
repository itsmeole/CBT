<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * per_butir  : bobot tipe = poin maksimal setiap butir (perilaku lama, dipertahankan untuk jadwal yang sudah ada).
     * total_tipe : bobot tipe = jatah total tipe itu, dibagi rata ke semua butir bertipe sama (jadwal baru).
     */
    public function up(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->string('bobot_mode', 20)->default('per_butir')->after('bobot_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('jadwal_ujian', function (Blueprint $table) {
            $table->dropColumn('bobot_mode');
        });
    }
};
