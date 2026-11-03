<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->unique()->after('id');
            $table->string('role', 20)->default('siswa')->index()->after('password');
            $table->boolean('aktif')->default(true)->after('role');
            $table->string('email')->nullable()->change();
        });

        Schema::create('tahun_ajaran', function (Blueprint $table) {
            $table->id();
            $table->string('nama');
            $table->unsignedTinyInteger('semester');
            $table->boolean('aktif')->default(false);
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tahun_ajaran');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['username', 'role', 'aktif']);
        });
    }
};

