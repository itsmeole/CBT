<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('laporan_pdf', function (Blueprint $table) {
            $table->id();
            $table->foreignId('diminta_oleh')->constrained('users')->restrictOnDelete();
            $table->string('jenis', 40);
            $table->foreignId('jadwal_ujian_id')->nullable()->constrained('jadwal_ujian')->nullOnDelete();
            $table->foreignId('sesi_ruang_id')->nullable()->constrained('sesi_ruang')->nullOnDelete();
            $table->foreignId('kelas_id')->nullable()->constrained('kelas')->nullOnDelete();
            $table->string('status', 20)->default('pending')->index();
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->index(['diminta_oleh', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laporan_pdf');
    }
};
