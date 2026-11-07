<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
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
use Tests\TestCase;

/**
 * Otorisasi channel private lewat endpoint `/broadcasting/auth` dengan driver Reverb sungguhan
 * (phpunit.xml memakai driver `null`, jadi driver dinyalakan khusus untuk kelas tes ini).
 */
class BroadcastAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('BROADCAST_CONNECTION=reverb');
        $_ENV['BROADCAST_CONNECTION'] = $_SERVER['BROADCAST_CONNECTION'] = 'reverb';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        putenv('BROADCAST_CONNECTION=null');
        $_ENV['BROADCAST_CONNECTION'] = $_SERVER['BROADCAST_CONNECTION'] = 'null';

        parent::tearDown();
    }

    /**
     * @return array{sesi: SesiRuang, pengawas: User, guruLain: User, siswaUser: User, siswaLain: User, peserta: PesertaUjian}
     */
    private function siapkan(): array
    {
        $tahun = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $tahun->id]);
        $mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA']);
        $pengawas = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $pengawas->id]);
        $soal = BankSoal::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'tipe' => 'essay', 'konten' => 'x', 'kunci' => 'k', 'rubrik' => 'r']);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'P']);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);
        $jadwal = JadwalUjian::create([
            'mapel_id' => $mapel->id, 'paket_soal_id' => $paket->id, 'tahun_ajaran_id' => $tahun->id,
            'nama' => 'U', 'tanggal' => '2026-11-04', 'durasi_menit' => 60,
            'bobot_snapshot' => ['essay' => '5.00'], 'soal_snapshot' => [$soal->id],
        ]);
        $sesi = SesiRuang::create([
            'jadwal_ujian_id' => $jadwal->id, 'ruang_id' => Ruang::create(['nama' => 'R', 'kapasitas' => 30])->id,
            'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:00',
        ]);
        PengawasSesi::create(['sesi_ruang_id' => $sesi->id, 'guru_id' => $guru->id]);

        $siswaUser = User::factory()->role('siswa')->create();
        $siswa = Siswa::create(['user_id' => $siswaUser->id, 'nis' => '1', 'kelas_id' => $kelas->id]);
        $peserta = PesertaUjian::create(['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesi->id, 'status' => 'mengerjakan', 'hadir' => true]);

        return [
            'sesi' => $sesi,
            'pengawas' => $pengawas,
            'guruLain' => User::factory()->role('guru')->create(),
            'siswaUser' => $siswaUser,
            'siswaLain' => User::factory()->role('siswa')->create(),
            'peserta' => $peserta,
        ];
    }

    private function otorisasi(User $user, string $channel)
    {
        return $this->actingAs($user, 'sanctum')->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => $channel,
        ]);
    }

    public function test_only_the_assigned_supervisor_gets_a_signature_for_the_session_channel(): void
    {
        $data = $this->siapkan();

        $this->otorisasi($data['pengawas'], "private-sesi.{$data['sesi']->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
        $this->otorisasi($data['guruLain'], "private-sesi.{$data['sesi']->id}")->assertForbidden();
        $this->otorisasi($data['siswaUser'], "private-sesi.{$data['sesi']->id}")->assertForbidden();
    }

    public function test_only_the_participant_owner_gets_a_signature_for_their_own_channel(): void
    {
        $data = $this->siapkan();

        $this->otorisasi($data['siswaUser'], "private-peserta.{$data['peserta']->id}")
            ->assertOk()
            ->assertJsonStructure(['auth']);
        $this->otorisasi($data['siswaLain'], "private-peserta.{$data['peserta']->id}")->assertForbidden();
    }

    public function test_channel_authorization_preflight_is_allowed_from_the_frontend_origin(): void
    {
        $response = $this->call('OPTIONS', '/broadcasting/auth', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ]);

        $response->assertSuccessful();
        $this->assertSame('http://localhost:5173', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertStringContainsStringIgnoringCase('authorization', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    public function test_channel_authorization_requires_a_valid_token(): void
    {
        $this->postJson('/broadcasting/auth', [
            'socket_id' => '123.456',
            'channel_name' => 'private-sesi.1',
        ])->assertUnauthorized();
    }
}
