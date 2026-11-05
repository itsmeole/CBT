<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('siswa') && ! Schema::hasColumn('siswa', 'jk')) {
            Schema::table('siswa', function (Blueprint $table) {
                $table->enum('jk', ['L', 'P'])->default('L')->after('nis');
            });
        }

        if (Schema::hasTable('guru_mapel')) {
            Schema::table('guru_mapel', function (Blueprint $table) {
                $table->unsignedBigInteger('kelas_id')->nullable()->change();
                $table->unsignedBigInteger('tahun_ajaran_id')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('siswa') && Schema::hasColumn('siswa', 'jk')) {
            Schema::table('siswa', function (Blueprint $table) {
                $table->dropColumn('jk');
            });
        }

        if (Schema::hasTable('guru_mapel')) {
            Schema::table('guru_mapel', function (Blueprint $table) {
                $table->unsignedBigInteger('kelas_id')->nullable(false)->change();
                $table->unsignedBigInteger('tahun_ajaran_id')->nullable(false)->change();
            });
        }
    }
};

