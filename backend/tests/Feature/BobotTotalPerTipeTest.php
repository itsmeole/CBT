<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\BobotTipe;
use App\Models\Guru;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\PenilaianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BobotTotalPerTipeTest extends TestCase
{
    use RefreshDatabase;

    private function soal(int $id, string $tipe): BankSoal
    {
        $soal = new BankSoal(['tipe' => $tipe]);
        $soal->id = $id;

        return $soal;
    }

    public function test_total_per_type_is_split_evenly_and_always_adds_up_to_the_configured_weight(): void
    {
        $jadwal = new JadwalUjian(['bobot_mode' => 'total_tipe', 'bobot_snapshot' => ['pg' => '20.00', 'essay' => '20.00']]);
        $soalList = [$this->soal(3, 'pg'), $this->soal(1, 'pg'), $this->soal(2, 'pg'), $this->soal(9, 'essay'), $this->soal(8, 'essay')];

        $bobot = (new PenilaianService)->bobotPerSoal($jadwal, $soalList);

        $this->assertSame(['1' => '6.67', '2' => '6.67', '3' => '6.66'], array_map('strval', array_intersect_key($bobot, [1 => 1, 2 => 1, 3 => 1])));
        $this->assertSame('10.00', $bobot[8]);
        $this->assertSame('10.00', $bobot[9]);
        $this->assertEqualsWithDelta(40.0, array_sum(array_map('floatval', $bobot)), 0.0001, 'Jumlah poin sama dengan jumlah bobot.');
    }

    public function test_legacy_schedules_keep_the_per_item_weight(): void
    {
        $jadwal = new JadwalUjian(['bobot_snapshot' => ['pg' => '20.00']]);

        $bobot = (new PenilaianService)->bobotPerSoal($jadwal, [$this->soal(1, 'pg'), $this->soal(2, 'pg')]);

        $this->assertSame(['1' => '20.00', '2' => '20.00'], array_map('strval', $bobot));
    }

    public function test_new_schedules_use_total_per_type_and_maximum_score_is_the_sum_of_weights(): void
    {
        foreach (['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'] as $tipe) {
            BobotTipe::create(['tipe' => $tipe, 'bobot' => '20.00']);
        }
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $guru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'P']);
        $soalIds = [];
        // 3 PG dan 2 essay; tipe lain tidak ada (maksimal hanya dari tipe yang ada).
        foreach (['pg', 'pg', 'pg', 'essay', 'essay'] as $i => $tipe) {
            $soal = BankSoal::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'tipe' => $tipe, 'konten' => 'x', 'kunci' => $tipe === 'pg' ? 'A' : 'k', 'rubrik' => $tipe === 'essay' ? 'r' : null]);
            PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => $i + 1]);
            $soalIds[] = $soal->id;
        }

        $operator = User::factory()->role('operator')->create();
        $id = $this->actingAs($operator, 'sanctum')->postJson('/api/v1/jadwal', [
            'mapel_id' => $mapel->id, 'paket_soal_id' => $paket->id, 'nama' => 'Ujian', 'tanggal' => '2026-11-10', 'durasi_menit' => 60,
        ])->assertCreated()->json('data.id');
        $jadwal = JadwalUjian::findOrFail($id);
        $this->assertSame('total_tipe', $jadwal->bobot_mode);

        $siswa = Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => '1', 'kelas_id' => $kelas->id]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $jadwal->id, 'ruang_id' => Ruang::create(['nama' => 'R', 'kapasitas' => 5])->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:00']);
        $peserta = PesertaUjian::create(['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesi->id, 'status' => 'selesai', 'hadir' => true]);
        foreach (array_slice($soalIds, 0, 3) as $soalId) {
            JawabanSiswa::create(['peserta_ujian_id' => $peserta->id, 'bank_soal_id' => $soalId, 'jawaban' => 'A', 'client_seq' => 1]);
        }

        $nilai = app(PenilaianService::class)->nilaiPeserta($peserta->fresh());

        $this->assertSame('40.00', $nilai->maksimal, 'Dua tipe x bobot 20 = 40, berapa pun banyak soalnya.');
        $this->assertSame('20.00', $nilai->total, 'Tiga PG benar = seluruh jatah PG (6.67 + 6.67 + 6.66).');
        $this->assertSame('50.00', $nilai->nilai_akhir);
    }
}
