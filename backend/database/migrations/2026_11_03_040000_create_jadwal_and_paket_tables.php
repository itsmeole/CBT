<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bobot_tipe', function (Blueprint $table) {
            $table->id();
            $table->enum('tipe', ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'])->unique();
            $table->decimal('bobot', 8, 2)->default(1.00);
            $table->timestamps();
        });

        Schema::create('paket_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('guru_id')->constrained('guru')->cascadeOnDelete();
            $table->string('nama', 100);
            $table->text('deskripsi')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('paket_soal_item', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paket_soal_id')->constrained('paket_soal')->cascadeOnDelete();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->cascadeOnDelete();
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();

            $table->unique(['paket_soal_id', 'bank_soal_id']);
        });

        Schema::create('jadwal_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->foreignId('paket_soal_id')->constrained('paket_soal')->cascadeOnDelete();
            $table->foreignId('tahun_ajaran_id')->constrained('tahun_ajaran')->cascadeOnDelete();
            $table->string('nama', 100);
            $table->date('tanggal');
            $table->unsignedInteger('durasi_menit');
            $table->json('bobot_snapshot'); // Snapshot bobot per tipe soal saat jadwal dibuat (FR-BE-42)
            $table->string('status', 20)->default('aktif')->index(); // aktif, selesai
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('sesi_ruang', function (Blueprint $table) {
            $table->id();
            $table->foreignId('jadwal_ujian_id')->constrained('jadwal_ujian')->cascadeOnDelete();
            $table->foreignId('ruang_id')->constrained('ruang')->cascadeOnDelete();
            $table->unsignedTinyInteger('sesi_ke')->default(1);
            $table->time('jam_mulai');
            $table->time('jam_akhir');
            $table->enum('jenis', ['reguler', 'susulan'])->default('reguler');
            $table->timestamps();
        });

        Schema::create('pengawas_sesi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_ruang_id')->constrained('sesi_ruang')->cascadeOnDelete();
            $table->foreignId('guru_id')->constrained('guru')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['sesi_ruang_id', 'guru_id']);
        });

        Schema::create('token_ujian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sesi_ruang_id')->constrained('sesi_ruang')->cascadeOnDelete();
            $table->string('kode', 6)->index();
            $table->boolean('aktif')->default(true)->index();
            $table->foreignId('dibuat_oleh')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('token_ujian');
        Schema::dropIfExists('pengawas_sesi');
        Schema::dropIfExists('sesi_ruang');
        Schema::dropIfExists('jadwal_ujian');
        Schema::dropIfExists('paket_soal_item');
        Schema::dropIfExists('paket_soal');
        Schema::dropIfExists('bobot_tipe');
    }
};

