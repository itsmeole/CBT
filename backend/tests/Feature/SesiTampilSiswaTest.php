<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\TokenUjian;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SesiTampilSiswaTest extends TestCase
{
    use RefreshDatabase;

    private User $siswaUser;

    private Siswa $siswa;

    private TahunAjaran $ta;

    private Mapel $mapel;

    private Guru $guru;

    private Ruang $ruang;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:00', 'Asia/Jakarta'));
        $this->ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $this->ta->id]);
        $this->mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $this->guru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        $this->siswaUser = User::factory()->role('siswa')->create();
        $this->siswa = Siswa::create(['user_id' => $this->siswaUser->id, 'nis' => '1', 'kelas_id' => $kelas->id]);
        $this->ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 30]);
        Sanctum::actingAs($this->siswaUser);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sesi(string $nama, string $tanggal, string $mulai, string $akhir, bool $ditempatkan = true): SesiRuang
    {
        $soal = BankSoal::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mapel->id, 'tipe' => 'essay', 'konten' => 'x', 'kunci' => 'k', 'rubrik' => 'r']);
        $paket = PaketSoal::create(['mapel_id' => $this->mapel->id, 'guru_id' => $this->guru->id, 'nama' => 'P '.$nama]);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);
        $jadwal = JadwalUjian::create([
            'mapel_id' => $this->mapel->id, 'paket_soal_id' => $paket->id, 'tahun_ajaran_id' => $this->ta->id, 'nama' => $nama,
            'tanggal' => $tanggal, 'durasi_menit' => 30, 'bobot_snapshot' => ['essay' => '5.00'], 'soal_snapshot' => [$soal->id],
        ]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $jadwal->id, 'ruang_id' => $this->ruang->id, 'sesi_ke' => 1, 'jam_mulai' => $mulai, 'jam_akhir' => $akhir]);
        if ($ditempatkan) {
            PesertaUjian::create(['siswa_id' => $this->siswa->id, 'sesi_ruang_id' => $sesi->id, 'status' => 'belum_login', 'hadir' => false]);
        }

        return $sesi;
    }

    /** @return list<string> */
    private function namaUjianTampil(): array
    {
        return collect($this->getJson('/api/v1/ujian/sesi')->assertOk()->json('data'))->pluck('nama_ujian')->sort()->values()->all();
    }

    public function test_only_sessions_inside_their_time_window_are_listed(): void
    {
        $this->sesi('Sedang berlangsung', '2026-11-10', '08:00', '10:00');
        $this->sesi('Belum mulai', '2026-11-10', '10:30', '12:00');
        $this->sesi('Sudah selesai', '2026-11-10', '06:00', '08:30');
        $this->sesi('Kemarin', '2026-11-09', '08:00', '10:00');
        $this->sesi('Besok', '2026-11-11', '08:00', '10:00');

        $this->assertSame(['Sedang berlangsung'], $this->namaUjianTampil());
    }

    public function test_sessions_the_student_is_not_placed_in_are_never_listed(): void
    {
        $this->sesi('Ruang lain', '2026-11-10', '08:00', '10:00', false);

        $this->assertSame([], $this->namaUjianTampil());
    }

    public function test_a_student_still_working_stays_visible_until_the_time_is_up(): void
    {
        $sesi = $this->sesi('Sedang dikerjakan', '2026-11-10', '08:00', '09:30');
        PesertaUjian::where('sesi_ruang_id', $sesi->id)->update(['status' => 'mengerjakan', 'hadir' => true, 'waktu_selesai' => Carbon::now()->addMinutes(10)]);
        Carbon::setTestNow(Carbon::parse('2026-11-10 09:35', 'Asia/Jakarta'));
        PesertaUjian::where('sesi_ruang_id', $sesi->id)->update(['waktu_selesai' => Carbon::now()->addMinutes(5)]);

        $this->assertSame(['Sedang dikerjakan'], $this->namaUjianTampil());

        Carbon::setTestNow(Carbon::parse('2026-11-10 09:45', 'Asia/Jakarta'));
        $this->assertSame([], $this->namaUjianTampil());
    }

    public function test_joining_before_the_session_opens_is_rejected_and_works_inside_the_window(): void
    {
        $belum = $this->sesi('Belum mulai', '2026-11-10', '10:30', '12:00');
        TokenUjian::create(['sesi_ruang_id' => $belum->id, 'kode' => 'ABC123', 'aktif' => true, 'dibuat_oleh' => User::factory()->role('operator')->create()->id]);

        $this->postJson('/api/v1/ujian/join', ['token' => 'ABC123'])
            ->assertStatus(422)->assertJsonPath('error.message', 'Sesi ujian belum dibuka.');

        Carbon::setTestNow(Carbon::parse('2026-11-10 10:31', 'Asia/Jakarta'));
        $this->postJson('/api/v1/ujian/join', ['token' => 'ABC123'])->assertOk();
    }
}
