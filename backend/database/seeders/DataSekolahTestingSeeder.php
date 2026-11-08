<?php

namespace Database\Seeders;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Memasukkan data sekolah dari public/Data_Siswa_Mapel_Guru.xlsx untuk keperluan pengujian.
 *
 * SEMENTARA: username acak dan password seragam. Ganti password seluruh akun sebelum dipakai sungguhan.
 * Aman dijalankan ulang (data yang sudah ada dilewati). Daftar akun ditulis ke storage/app/private/akun-testing.csv.
 */
class DataSekolahTestingSeeder extends Seeder
{
    private const PASSWORD_SEMENTARA = 'password123';

    /** @var array<string, true> */
    private array $usernameTerpakai = [];

    public function run(): void
    {
        $berkas = public_path('Data_Siswa_Mapel_Guru.xlsx');
        $buku = IOFactory::load($berkas);
        $hash = Hash::make(self::PASSWORD_SEMENTARA);
        $akun = [];

        DB::transaction(function () use ($buku, $hash, &$akun): void {
            $ta = TahunAjaran::where('aktif', true)->first()
                ?? TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);

            $mapelId = $this->imporMapel($this->baris($buku->getSheetByName('Data Mapel')));
            $kelasId = $this->imporKelas($this->baris($buku->getSheetByName('Data Kelas')), $ta->id);
            $guruId = $this->imporGuru($this->baris($buku->getSheetByName('Data Guru')), $hash, $akun);
            $this->imporSiswa($this->baris($buku->getSheetByName('Data Siswa')), $kelasId, $hash, $akun);
            $this->imporPengampu($this->baris($buku->getSheetByName('Guru-Mapel-Kelas')), $guruId, $mapelId, $kelasId, $ta->id);
        });

        $this->tulisDaftarAkun($akun);
        $this->command?->info(count($akun).' akun baru dibuat. Daftar akun: storage/app/private/akun-testing.csv');
    }

    /**
     * @return list<array<int, mixed>> baris data tanpa judul dan tanpa baris kosong (indeks kolom 0 = kolom A)
     */
    private function baris($sheet): array
    {
        $hasil = [];
        foreach ($sheet->toArray(null, false, false, false) as $i => $baris) {
            if ($i === 0 || trim((string) ($baris[1] ?? '')) === '') {
                continue;
            }
            $hasil[] = $baris;
        }

        return $hasil;
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     * @return array<string, int> singkatan mapel (di jadwal) => id
     */
    private function imporMapel(array $baris): array
    {
        $peta = [];
        foreach ($baris as $b) {
            $singkatan = trim((string) $b[1]);
            $nama = trim((string) $b[2]);
            if ($singkatan === '' || $nama === '' || str_starts_with($singkatan, 'Catatan')) {
                continue;
            }
            $kode = Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $singkatan));
            $mapel = Mapel::whereRaw('LOWER(nama) = ?', [Str::lower($nama)])->first()
                ?? Mapel::where('kode', $kode)->first()
                ?? Mapel::create(['kode' => $kode, 'nama' => $nama]);
            $peta[$singkatan] = $mapel->id;
        }

        return $peta;
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     * @return array<string, int> nama kelas tanpa keterangan program, mis. "7 Fusshilat" => id
     */
    private function imporKelas(array $baris, int $tahunAjaranId): array
    {
        $peta = [];
        foreach ($baris as $b) {
            $nama = trim((string) $b[1]);
            if ($nama === 'TOTAL' || ! is_numeric($b[2] ?? null)) {
                continue;
            }
            $kelas = Kelas::firstOrCreate(
                ['nama' => $nama, 'tahun_ajaran_id' => $tahunAjaranId],
                ['tingkat' => (string) (int) $b[2]]
            );
            $peta[$this->kunciKelas($nama)] = $kelas->id;
        }

        return $peta;
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     * @param  list<array<string, string>>  $akun
     * @return array<string, int> nama guru => id guru
     */
    private function imporGuru(array $baris, string $hash, array &$akun): array
    {
        $peta = [];
        foreach ($baris as $b) {
            $nama = trim((string) $b[1]);
            $user = User::where('role', 'guru')->where('name', $nama)->first();
            if (! $user) {
                $user = $this->buatUser($nama, 'guru', $hash);
                $akun[] = ['peran' => 'guru', 'nama' => $nama, 'keterangan' => '', 'username' => $user->username];
            }
            $peta[$nama] = Guru::firstOrCreate(['user_id' => $user->id])->id;
        }

        return $peta;
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     * @param  array<string, int>  $kelasId
     * @param  list<array<string, string>>  $akun
     */
    private function imporSiswa(array $baris, array $kelasId, string $hash, array &$akun): void
    {
        foreach ($baris as $b) {
            $nis = trim((string) $b[1]);
            $nama = trim((string) $b[2]);
            $kelas = $kelasId[$this->kunciKelas((string) $b[3])] ?? null;
            if ($nis === '' || $kelas === null || Siswa::where('nis', $nis)->exists()) {
                continue;
            }
            $user = $this->buatUser($nama, 'siswa', $hash);
            Siswa::create([
                'user_id' => $user->id,
                'nis' => $nis,
                'jk' => strtoupper(trim((string) ($b[5] ?? 'L'))) === 'P' ? 'P' : 'L',
                'kelas_id' => $kelas,
            ]);
            $akun[] = ['peran' => 'siswa', 'nama' => $nama, 'keterangan' => 'NIS '.$nis.' / '.trim((string) $b[3]), 'username' => $user->username];
        }
    }

    /**
     * @param  list<array<int, mixed>>  $baris
     * @param  array<string, int>  $guruId
     * @param  array<string, int>  $mapelId
     * @param  array<string, int>  $kelasId
     */
    private function imporPengampu(array $baris, array $guruId, array $mapelId, array $kelasId, int $tahunAjaranId): void
    {
        foreach ($baris as $b) {
            $guru = $guruId[trim((string) $b[1])] ?? null;
            $mapel = $mapelId[trim((string) $b[2])] ?? null;
            $kelas = $kelasId[$this->kunciKelas((string) $b[4])] ?? null;
            if ($guru === null || $mapel === null || $kelas === null) {
                $this->command?->warn('Pengampu dilewati: '.trim((string) $b[1]).' / '.trim((string) $b[2]).' / '.trim((string) $b[4]));

                continue;
            }
            GuruMapel::firstOrCreate(['guru_id' => $guru, 'mapel_id' => $mapel, 'kelas_id' => $kelas, 'tahun_ajaran_id' => $tahunAjaranId]);
        }
    }

    private function kunciKelas(string $nama): string
    {
        return Str::lower(trim((string) preg_replace('/\s*\(.*\)\s*$/', '', trim($nama))));
    }

    private function buatUser(string $nama, string $peran, string $hash): User
    {
        do {
            $username = Str::lower(Str::random(8));
        } while (isset($this->usernameTerpakai[$username]) || User::where('username', $username)->exists());
        $this->usernameTerpakai[$username] = true;

        return User::create(['name' => $nama, 'username' => $username, 'password' => $hash, 'role' => $peran, 'aktif' => true]);
    }

    /**
     * @param  list<array<string, string>>  $akun
     */
    private function tulisDaftarAkun(array $akun): void
    {
        $jalur = storage_path('app/private/akun-testing.csv');
        if (! is_dir(dirname($jalur))) {
            mkdir(dirname($jalur), 0775, true);
        }
        $berkas = fopen($jalur, 'w');
        fputcsv($berkas, ['peran', 'nama', 'keterangan', 'username', 'password'], ',', '"', '');
        foreach ($akun as $a) {
            fputcsv($berkas, [$a['peran'], $a['nama'], $a['keterangan'], $a['username'], self::PASSWORD_SEMENTARA], ',', '"', '');
        }
        fclose($berkas);
    }
}
