<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\OpsiSoal;
use App\Models\PaketSoal;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UrutanSoalUjianTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_questions_are_grouped_by_type_and_shuffled_within_each_type(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-04 08:00', 'Asia/Jakarta'));
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $guru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        $siswa = Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => '1', 'kelas_id' => $kelas->id]);

        // Dibuat dengan urutan bercampur sengaja.
        $tipeDibuat = ['essay', 'pg', 'menjodohkan', 'pg', 'benar_salah', 'pg_kompleks', 'pg', 'essay', 'menjodohkan', 'pg_kompleks', 'benar_salah', 'pg'];
        $soalIds = [];
        foreach ($tipeDibuat as $tipe) {
            $soal = BankSoal::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'tipe' => $tipe, 'konten' => "Soal {$tipe}", 'kunci' => 'A', 'rubrik' => null]);
            OpsiSoal::create(['bank_soal_id' => $soal->id, 'konten' => 'satu', 'sisi' => $tipe === 'menjodohkan' ? 'kiri' : null, 'urutan' => 1]);
            OpsiSoal::create(['bank_soal_id' => $soal->id, 'konten' => 'dua', 'sisi' => $tipe === 'menjodohkan' ? 'kanan' : null, 'urutan' => 2]);
            $soalIds[$soal->id] = $tipe;
        }
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'Paket']);
        $jadwal = JadwalUjian::create([
            'mapel_id' => $mapel->id, 'paket_soal_id' => $paket->id, 'tahun_ajaran_id' => $ta->id, 'nama' => 'Ujian',
            'tanggal' => '2026-11-04', 'durasi_menit' => 60, 'bobot_snapshot' => ['pg' => '1.00'], 'soal_snapshot' => array_keys($soalIds),
        ]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $jadwal->id, 'ruang_id' => Ruang::create(['nama' => 'R1', 'kapasitas' => 30])->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '12:00']);
        $peserta = PesertaUjian::create(['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesi->id, 'status' => 'menunggu', 'hadir' => true]);

        Sanctum::actingAs(User::factory()->role('operator')->create());
        $this->postJson("/api/v1/peserta/{$peserta->id}/mulai")->assertOk();

        $urutan = $peserta->fresh()->urutan_soal;
        $this->assertEqualsCanonicalizing(array_keys($soalIds), $urutan, 'Semua soal tetap ada.');

        $tipeUrut = array_map(fn (int $id): string => $soalIds[$id], $urutan);
        $this->assertSame(
            ['pg', 'pg', 'pg', 'pg', 'pg_kompleks', 'pg_kompleks', 'benar_salah', 'benar_salah', 'menjodohkan', 'menjodohkan', 'essay', 'essay'],
            $tipeUrut,
            'Soal dikelompokkan per tipe dengan urutan PG, PG kompleks, benar/salah, menjodohkan, essay.'
        );
    }
}
