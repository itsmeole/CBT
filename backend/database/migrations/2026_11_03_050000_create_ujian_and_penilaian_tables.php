<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('peserta_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('siswa_id')->constrained('siswa')->cascadeOnDelete();
            $table->foreignId('sesi_ruang_id')->constrained('sesi_ruang')->cascadeOnDelete();
            $table->enum('status', ['belum_login', 'menunggu', 'mengerjakan', 'terkunci', 'selesai'])->default('belum_login')->index();
            $table->boolean('hadir')->default(false)->index();
            $table->dateTime('waktu_mulai')->nullable();
            $table->dateTime('waktu_selesai')->nullable();
            $table->json('urutan_soal')->nullable();
            $table->json('urutan_opsi')->nullable();
            $table->unsignedInteger('jumlah_kunci')->default(0);
            $table->unsignedBigInteger('last_client_seq')->default(0);
            $table->timestamps();

            $table->unique(['siswa_id', 'sesi_ruang_id']);
            $table->index(['sesi_ruang_id', 'status']);
        });

        Schema::create('jawaban_siswa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('peserta_ujian_id')->constrained('peserta_ujian')->cascadeOnDelete();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->cascadeOnDelete();
            $table->json('jawaban')->nullable();
            $table->unsignedBigInteger('client_seq')->default(0);
            $table->decimal('skor', 8, 2)->default(0.00);
            $table->foreignId('dikoreksi_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['peserta_ujian_id', 'bank_soal_id']);
        });

        Schema::create('nilai', function (Blueprint $table) {
            $table->id();
            $table->foreignId('peserta_ujian_id')->unique()->constrained('peserta_ujian')->cascadeOnDelete();
            $table->json('skor_per_tipe')->nullable();
            $table->decimal('total', 8, 2)->default(0.00);
            $table->decimal('maksimal', 8, 2)->default(0.00);
            $table->decimal('nilai_akhir', 8, 2)->default(0.00);
            $table->enum('status', ['menunggu_koreksi', 'final'])->default('menunggu_koreksi')->index();
            $table->timestamps();
        });

        Schema::create('lock_event', function (Blueprint $table) {
            $table->id();
            $table->foreignId('peserta_ujian_id')->constrained('peserta_ujian')->cascadeOnDelete();
            $table->string('pemicu', 100);
            $table->dateTime('waktu_kunci');
            $table->dateTime('waktu_buka')->nullable();
            $table->foreignId('dibuka_oleh')->nullable()->constrained('users')->nullOnDelete();
            $table->text('alasan')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lock_event');
        Schema::dropIfExists('nilai');
        Schema::dropIfExists('jawaban_siswa');
        Schema::dropIfExists('peserta_ujian');
    }
};

