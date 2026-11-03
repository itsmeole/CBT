<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime', 50);
            $table->unsignedBigInteger('ukuran');
            $table->timestamps();
        });

        Schema::create('bank_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('guru_id')->constrained('guru')->cascadeOnDelete();
            $table->foreignId('mapel_id')->constrained('mapel')->cascadeOnDelete();
            $table->enum('tipe', ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'])->index();
            $table->longText('konten');
            $table->json('kunci')->nullable();
            $table->text('rubrik')->nullable();
            $table->unsignedInteger('versi')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('opsi_soal', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bank_soal_id')->constrained('bank_soal')->cascadeOnDelete();
            $table->longText('konten');
            $table->string('sisi', 10)->nullable(); // 'kiri' atau 'kanan' untuk menjodohkan
            $table->unsignedInteger('urutan')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('opsi_soal');
        Schema::dropIfExists('bank_soal');
        Schema::dropIfExists('media');
    }
};

