<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('berita_acara', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_ruang_id')->unique()->constrained('sesi_ruang')->restrictOnDelete();
            $table->foreignId('pengawas_id')->constrained('users')->restrictOnDelete();
            $table->text('isi');
            $table->unsignedInteger('jumlah_hadir')->default(0);
            $table->unsignedInteger('jumlah_absen')->default(0);
            $table->timestamps();
        });

        Schema::create('log_aktivitas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('aksi', 100);
            $table->string('target_type', 100)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['created_at', 'aksi']);
            $table->index(['user_id', 'created_at']);
            $table->index(['target_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_aktivitas');
        Schema::dropIfExists('berita_acara');
    }
};
