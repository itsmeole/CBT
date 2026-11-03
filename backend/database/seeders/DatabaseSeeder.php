<?php

namespace Database\Seeders;

use App\Models\BobotTipe;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Bobot Tipe Default
        $tipeSoal = [
            ['tipe' => 'pg', 'bobot' => 1.00],
            ['tipe' => 'pg_kompleks', 'bobot' => 1.00],
            ['tipe' => 'benar_salah', 'bobot' => 1.00],
            ['tipe' => 'menjodohkan', 'bobot' => 1.00],
            ['tipe' => 'essay', 'bobot' => 5.00],
        ];
        foreach ($tipeSoal as $item) {
            BobotTipe::updateOrCreate(['tipe' => $item['tipe']], $item);
        }

        // 2. Tahun Ajaran Aktif
        $ta = TahunAjaran::updateOrCreate(
            ['nama' => '2026/2027', 'semester' => 1],
            ['aktif' => true]
        );

        // 3. Ruang
        $ruang1 = Ruang::firstOrCreate(['nama' => 'Lab Komputer 1'], ['kapasitas' => 36]);
        $ruang2 = Ruang::firstOrCreate(['nama' => 'Ruang 01'], ['kapasitas' => 36]);

        // 4. Mapel
        $mapelMtk = Mapel::firstOrCreate(['kode' => 'MTK'], ['nama' => 'Matematika']);
        $mapelIndo = Mapel::firstOrCreate(['kode' => 'BIND'], ['nama' => 'Bahasa Indonesia']);

        // 5. Kelas
        $kelas7A = Kelas::firstOrCreate(
            ['nama' => '7A', 'tahun_ajaran_id' => $ta->id],
            ['tingkat' => '7']
        );

        // 6. Users & Roles
        // Superadmin
        User::updateOrCreate(
            ['username' => 'superadmin'],
            [
                'name' => 'Super Administrator',
                'password' => Hash::make('password'),
                'role' => 'superadmin',
                'aktif' => true,
            ]
        );

        // Operator
        User::updateOrCreate(
            ['username' => 'operator'],
            [
                'name' => 'Operator Sekolah',
                'password' => Hash::make('password'),
                'role' => 'operator',
                'aktif' => true,
            ]
        );

        // Guru
        $userGuru = User::updateOrCreate(
            ['username' => 'guru1'],
            [
                'name' => 'Budi Santoso, S.Pd.',
                'password' => Hash::make('password'),
                'role' => 'guru',
                'aktif' => true,
            ]
        );
        $guru = Guru::firstOrCreate(
            ['user_id' => $userGuru->id],
            ['nip' => '198501012010011001']
        );

        // Plotting Guru Mapel
        GuruMapel::firstOrCreate([
            'guru_id' => $guru->id,
            'mapel_id' => $mapelMtk->id,
            'kelas_id' => $kelas7A->id,
            'tahun_ajaran_id' => $ta->id,
        ]);

        // Siswa
        $userSiswa = User::updateOrCreate(
            ['username' => 'siswa1'],
            [
                'name' => 'Ahmad Rizki',
                'password' => Hash::make('password'),
                'role' => 'siswa',
                'aktif' => true,
            ]
        );
        Siswa::firstOrCreate(
            ['user_id' => $userSiswa->id],
            [
                'nis' => '2026001',
                'kelas_id' => $kelas7A->id,
            ]
        );
    }
}
