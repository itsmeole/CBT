<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Nilai;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\PengawasSesi;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Profiling N+1 (NFR-BE-03): jumlah query endpoint daftar harus konstan,
 * tidak bertambah ketika jumlah peserta bertambah.
 */
class QueryCountTest extends TestCase
{
    use RefreshDatabase;

    private TahunAjaran $tahun;

    private Kelas $kelas;

    private BankSoal $soal;

    private SesiRuang $sesi;

    private JadwalUjian $jadwal;

    private User $guruUser;

    private int $urutan = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tahun = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $this->kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $this->tahun->id]);
        $mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA']);
        $this->guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $this->guruUser->id]);
        GuruMapel::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'kelas_id' => $this->kelas->id,
            'tahun_ajaran_id' => $this->tahun->id,
        ]);
        $this->soal = BankSoal::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'essay',
            'konten' => 'Jelaskan.',
            'kunci' => 'kunci',
            'rubrik' => 'rubrik',
        ]);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'Paket']);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $this->soal->id, 'urutan' => 1]);
        $this->jadwal = JadwalUjian::create([
            'mapel_id' => $mapel->id,
            'paket_soal_id' => $paket->id,
            'tahun_ajaran_id' => $this->tahun->id,
            'nama' => 'Ujian',
            'tanggal' => '2026-11-04',
            'durasi_menit' => 60,
            'bobot_snapshot' => ['essay' => '5.00'],
            'soal_snapshot' => [$this->soal->id],
        ]);
        $this->sesi = SesiRuang::create([
            'jadwal_ujian_id' => $this->jadwal->id,
            'ruang_id' => Ruang::create(['nama' => 'R1', 'kapasitas' => 100])->id,
            'sesi_ke' => 1,
            'jam_mulai' => '08:00',
            'jam_akhir' => '09:00',
        ]);
        PengawasSesi::create(['sesi_ruang_id' => $this->sesi->id, 'guru_id' => $guru->id]);
    }

    private function tambahPeserta(int $jumlah): void
    {
        for ($i = 0; $i < $jumlah; $i++) {
            $this->urutan++;
            $siswa = Siswa::create([
                'user_id' => User::factory()->role('siswa')->create()->id,
                'nis' => 'NIS'.$this->urutan,
                'kelas_id' => $this->kelas->id,
            ]);
            $peserta = PesertaUjian::create([
                'siswa_id' => $siswa->id,
                'sesi_ruang_id' => $this->sesi->id,
                'status' => 'selesai',
                'hadir' => true,
                'waktu_mulai' => now()->subHour(),
                'waktu_selesai' => now(),
            ]);
            JawabanSiswa::create([
                'peserta_ujian_id' => $peserta->id,
                'bank_soal_id' => $this->soal->id,
                'jawaban' => 'Jawaban '.$this->urutan,
                'client_seq' => 1,
            ]);
            Nilai::query()->forceCreate([
                'peserta_ujian_id' => $peserta->id,
                'skor_per_tipe' => ['essay' => 0],
                'total' => $this->urutan % 5,
                'maksimal' => 5,
                'nilai_akhir' => ($this->urutan % 5) * 20,
                'status' => 'final',
            ]);
        }
    }

    private function hitungQuery(User $aktor, string $url): int
    {
        $this->actingAs($aktor, 'sanctum');
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson($url)->assertOk();
        $jumlah = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $jumlah;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function endpoints(): array
    {
        return [
            'monitoring pengawas' => ['monitoring'],
            'daftar peserta operator' => ['peserta'],
            'rekap nilai' => ['nilai'],
            'antrean koreksi essay' => ['koreksi'],
            'analisis butir' => ['analisis'],
        ];
    }

    #[DataProvider('endpoints')]
    public function test_query_count_does_not_grow_with_number_of_participants(string $endpoint): void
    {
        $operator = User::factory()->role('operator')->create();
        $aktor = $this->guruUser;
        $url = match ($endpoint) {
            'monitoring' => "/api/v1/sesi-ruang/{$this->sesi->id}/monitoring",
            'peserta' => "/api/v1/sesi-ruang/{$this->sesi->id}/peserta",
            'nilai' => "/api/v1/nilai?jadwal_id={$this->jadwal->id}",
            'koreksi' => "/api/v1/koreksi/essay?jadwal_id={$this->jadwal->id}",
            'analisis' => "/api/v1/analisis-butir/{$this->jadwal->id}",
        };
        if ($endpoint === 'peserta') {
            $aktor = $operator;
        }

        $this->tambahPeserta(3);
        // Request pemanasan: query sekali-jalan (mis. cache/skema) tidak boleh dihitung sebagai pertumbuhan.
        $this->hitungQuery($aktor, $url);
        $sedikit = $this->hitungQuery($aktor, $url);

        $this->tambahPeserta(27);
        $banyak = $this->hitungQuery($aktor, $url);

        $this->assertSame(
            $sedikit,
            $banyak,
            "Endpoint {$endpoint} memakai {$sedikit} query untuk 3 peserta tetapi {$banyak} untuk 30 peserta (indikasi N+1)."
        );
    }
}
