<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\Kelas;
use App\Models\LaporanPdf;
use App\Models\LogAktivitas;
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
use App\Models\TokenUjian;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private string $hariIni;

    private TahunAjaran $tahun;

    private Kelas $kelasA;

    private Kelas $kelasB;

    private Mapel $mapel;

    private Guru $guru;

    private User $guruUser;

    private User $operator;

    private int $urutan = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hariIni = CarbonImmutable::now(config('app.timezone_sekolah'))->toDateString();
        $this->tahun = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $this->kelasA = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $this->tahun->id]);
        $this->kelasB = Kelas::create(['nama' => '7B', 'tingkat' => '7', 'tahun_ajaran_id' => $this->tahun->id]);
        $this->mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA']);
        $this->guruUser = User::factory()->role('guru')->create();
        $this->guru = Guru::create(['user_id' => $this->guruUser->id]);
        // Guru mengampu IPA hanya di kelas 7A.
        GuruMapel::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mapel->id, 'kelas_id' => $this->kelasA->id, 'tahun_ajaran_id' => $this->tahun->id]);
        $this->operator = User::factory()->role('operator')->create();
    }

    private function jadwal(string $tanggal, ?Mapel $mapel = null, string $nama = 'Ujian'): JadwalUjian
    {
        $mapel ??= $this->mapel;
        $soal = BankSoal::create(['guru_id' => $this->guru->id, 'mapel_id' => $mapel->id, 'tipe' => 'essay', 'konten' => 'x', 'kunci' => 'k', 'rubrik' => 'r']);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $this->guru->id, 'nama' => 'Paket '.$nama]);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);

        return JadwalUjian::create([
            'mapel_id' => $mapel->id, 'paket_soal_id' => $paket->id, 'tahun_ajaran_id' => $this->tahun->id,
            'nama' => $nama, 'tanggal' => $tanggal, 'durasi_menit' => 60,
            'bobot_snapshot' => ['essay' => '5.00'], 'soal_snapshot' => [$soal->id],
        ]);
    }

    private function sesi(JadwalUjian $jadwal, string $ruang = 'R1', int $sesiKe = 1, string $mulai = '08:00', bool $token = true): SesiRuang
    {
        $sesi = SesiRuang::create([
            'jadwal_ujian_id' => $jadwal->id,
            'ruang_id' => Ruang::create(['nama' => $ruang.$this->urutan++, 'kapasitas' => 40])->id,
            'sesi_ke' => $sesiKe, 'jam_mulai' => $mulai, 'jam_akhir' => '23:00',
        ]);
        if ($token) {
            TokenUjian::create(['sesi_ruang_id' => $sesi->id, 'kode' => 'ABC'.random_int(100, 999), 'aktif' => true, 'dibuat_oleh' => $this->operator->id]);
        }

        return $sesi;
    }

    private function peserta(SesiRuang $sesi, Kelas $kelas, string $status = 'belum_login', bool $hadir = false, int $kunci = 0): PesertaUjian
    {
        $siswa = Siswa::create([
            'user_id' => User::factory()->role('siswa')->create()->id,
            'nis' => 'N'.$this->urutan++,
            'kelas_id' => $kelas->id,
        ]);

        return PesertaUjian::create(['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesi->id, 'status' => $status, 'hadir' => $hadir, 'jumlah_kunci' => $kunci]);
    }

    public function test_only_staff_roles_can_open_the_dashboard(): void
    {
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
        $this->actingAs(User::factory()->role('siswa')->create(), 'sanctum')->getJson('/api/v1/dashboard')->assertForbidden();
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->assertOk();
    }

    public function test_each_role_only_receives_its_own_sections(): void
    {
        $operator = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->json('data');
        $this->assertArrayHasKey('operator', $operator);
        $this->assertArrayNotHasKey('guru', $operator);
        $this->assertArrayNotHasKey('sistem', $operator);

        $guru = $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data');
        $this->assertArrayHasKey('guru', $guru);
        $this->assertArrayNotHasKey('operator', $guru);
        $this->assertArrayNotHasKey('sistem', $guru);

        $super = $this->actingAs(User::factory()->role('superadmin')->create(), 'sanctum')->getJson('/api/v1/dashboard')->json('data');
        $this->assertArrayHasKey('operator', $super);
        $this->assertArrayHasKey('sistem', $super);
        $this->assertArrayNotHasKey('guru', $super);
        $this->assertSame('Asia/Jakarta', $super['zona_waktu']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_session_whose_end_time_has_passed_is_not_shown_as_running_even_with_stale_participants(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($this->hariIni.' 12:30', config('app.timezone_sekolah')));
        $jadwal = $this->jadwal($this->hariIni, null, 'UTS IPA');
        $lewat = $this->sesi($jadwal, 'R', 1, '10:00');
        $lewat->update(['jam_akhir' => '12:00']);
        $this->peserta($lewat, $this->kelasA, 'mengerjakan', true);
        $masihJalan = $this->sesi($jadwal, 'R', 2, '12:00');
        $masihJalan->update(['jam_akhir' => '13:00']);
        $this->peserta($masihJalan, $this->kelasA, 'mengerjakan', true);

        $data = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->json('data.operator');

        $this->assertSame(['selesai', 'berjalan'], array_column($data['hari_ini'], 'status'));
        $this->assertSame(1, $data['langsung']['sesi_berjalan']);
    }

    public function test_operator_sees_todays_sessions_with_status_counts_and_state(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($this->hariIni.' 10:30', config('app.timezone_sekolah')));
        $jadwal = $this->jadwal($this->hariIni, null, 'UTS IPA');
        $berjalan = $this->sesi($jadwal, 'R', 1, '08:00');
        $this->peserta($berjalan, $this->kelasA, 'mengerjakan', true);
        $this->peserta($berjalan, $this->kelasA, 'terkunci', true);
        $this->peserta($berjalan, $this->kelasA, 'belum_login', false);
        PengawasSesi::create(['sesi_ruang_id' => $berjalan->id, 'guru_id' => $this->guru->id]);
        $selesai = $this->sesi($jadwal, 'R', 2, '09:00');
        $this->peserta($selesai, $this->kelasA, 'selesai', true);
        $mulai = $this->sesi($jadwal, 'R', 3, '10:00');
        $this->peserta($mulai, $this->kelasA, 'menunggu', true);
        $this->jadwal(CarbonImmutable::parse($this->hariIni)->addDay()->toDateString(), null, 'Besok');

        $data = $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->json('data.operator');

        $this->assertCount(3, $data['hari_ini'], 'Sesi jadwal besok tidak termasuk hari ini.');
        [$a, $b, $c] = $data['hari_ini'];
        $this->assertSame(['berjalan', 'selesai', 'belum_mulai'], [$a['status'], $b['status'], $c['status']]);
        $this->assertSame(['total' => 3, 'hadir' => 2, 'menunggu' => 0, 'mengerjakan' => 1, 'terkunci' => 1, 'selesai' => 0], $a['peserta']);
        $this->assertSame('08:00', $a['jam_mulai']);
        $this->assertNotEmpty($a['token']);
        $this->assertSame([$this->guruUser->name], $a['pengawas']);
        $this->assertSame(
            ['sesi_hari_ini' => 3, 'sesi_berjalan' => 1, 'peserta' => 5, 'menunggu' => 1, 'mengerjakan' => 1, 'terkunci' => 1, 'selesai' => 1],
            $data['langsung']
        );
    }

    public function test_readiness_flags_missing_placement_supervisors_empty_sessions_tokens_and_unplaced_students(): void
    {
        $besok = CarbonImmutable::parse($this->hariIni)->addDay()->toDateString();

        $tanpaPenempatan = $this->jadwal($besok, null, 'Tanpa Penempatan');

        $bermasalah = $this->jadwal($besok, null, 'Bermasalah');
        $sesiOk = $this->sesi($bermasalah, 'A', 1, '08:00');
        $this->peserta($sesiOk, $this->kelasA);
        $this->sesi($bermasalah, 'B', 2, '09:00', token: false); // tanpa pengawas, kosong, tanpa token
        PengawasSesi::create(['sesi_ruang_id' => $sesiOk->id, 'guru_id' => $this->guru->id]);
        Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => 'X1', 'kelas_id' => $this->kelasA->id]);
        Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => 'X2', 'kelas_id' => $this->kelasA->id]);
        // Kelas 7B tidak diampu untuk IPA, jadi siswanya tidak dihitung sebagai peserta yang belum ditempatkan.
        Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => 'X3', 'kelas_id' => $this->kelasB->id]);

        $siap = $this->jadwal($besok, null, 'Siap');
        $sesiSiap = $this->sesi($siap, 'C', 1, '08:00');
        PengawasSesi::create(['sesi_ruang_id' => $sesiSiap->id, 'guru_id' => $this->guru->id]);
        // Semua siswa 7A yang ada sudah ditempatkan di jadwal ini.
        foreach (Siswa::where('kelas_id', $this->kelasA->id)->get() as $siswa) {
            PesertaUjian::create(['siswa_id' => $siswa->id, 'sesi_ruang_id' => $sesiSiap->id, 'status' => 'belum_login', 'hadir' => false]);
        }

        $kesiapan = collect($this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->json('data.operator.kesiapan'))->keyBy('nama');

        $this->assertSame(['tanpa_penempatan'], array_column($kesiapan['Tanpa Penempatan']['masalah'], 'kode'));
        $this->assertFalse($kesiapan['Tanpa Penempatan']['siap']);

        $kode = collect($kesiapan['Bermasalah']['masalah'])->keyBy('kode');
        $this->assertSame(1, $kode['tanpa_pengawas']['jumlah']);
        $this->assertSame(1, $kode['sesi_kosong']['jumlah']);
        $this->assertSame(1, $kode['tanpa_token']['jumlah']);
        $this->assertSame(2, $kode['belum_ditempatkan']['jumlah'], 'Hanya siswa 7A yang belum ditempatkan (7B tidak diampu).');

        $this->assertTrue($kesiapan['Siap']['siap']);
        $this->assertSame([], $kesiapan['Siap']['masalah']);
        $this->assertNotNull($tanpaPenempatan->id);
    }

    public function test_operator_action_items_cover_attendance_locked_students_threshold_and_failed_pdfs(): void
    {
        $sesi = $this->sesi($this->jadwal($this->hariIni), 'R', 1);
        $this->peserta($sesi, $this->kelasA, 'mengerjakan', true);
        $this->peserta($sesi, $this->kelasA, 'terkunci', true, 3);
        $this->peserta($sesi, $this->kelasA, 'belum_login', false);
        LaporanPdf::create(['diminta_oleh' => $this->operator->id, 'jenis' => 'daftar-hadir', 'status' => 'failed']);

        $item = collect($this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')->json('data.operator.perlu_tindakan'))->keyBy('kode');

        $this->assertSame(1, $item['kehadiran']['jumlah']);
        $this->assertSame(1, $item['terkunci']['jumlah']);
        $this->assertSame(1, $item['ambang_kunci']['jumlah']);
        $this->assertSame(1, $item['pdf_gagal']['jumlah']);
        $this->assertSame('/staf/monitoring', $item['terkunci']['tautan']);
    }

    public function test_no_action_items_when_everything_is_in_order(): void
    {
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.operator.perlu_tindakan', [])
            ->assertJsonPath('data.operator.hari_ini', [])
            ->assertJsonPath('data.operator.kesiapan', []);
    }

    public function test_teacher_correction_queue_counts_only_unscored_essays_in_own_classes(): void
    {
        $jadwal = $this->jadwal($this->hariIni, null, 'UTS IPA');
        $soalEssay = BankSoal::query()->where('tipe', 'essay')->first();
        $sesi = $this->sesi($jadwal);
        $tulis = function (Kelas $kelas, ?int $dikoreksi, string $status = 'selesai') use ($sesi, $soalEssay): void {
            $peserta = $this->peserta($sesi, $kelas, $status, true);
            JawabanSiswa::create(['peserta_ujian_id' => $peserta->id, 'bank_soal_id' => $soalEssay->id, 'jawaban' => 'jawaban', 'client_seq' => 1, 'dikoreksi_oleh' => $dikoreksi]);
        };
        $tulis($this->kelasA, null);
        $tulis($this->kelasA, null);
        $tulis($this->kelasA, $this->operator->id);     // sudah dikoreksi
        $tulis($this->kelasB, null);                     // kelas yang tidak diampu
        $tulis($this->kelasA, null, 'mengerjakan');      // belum dikumpulkan

        $koreksi = $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data.guru.koreksi');

        $this->assertSame(2, $koreksi['total']);
        $this->assertSame(1, $koreksi['jumlah_jadwal']);
        $this->assertCount(1, $koreksi['per_jadwal']);
        $this->assertSame('UTS IPA', $koreksi['per_jadwal'][0]['nama']);
        $this->assertSame(2, $koreksi['per_jadwal'][0]['menunggu']);
    }

    public function test_teacher_supervision_lists_only_own_upcoming_sessions_and_counts_missing_minutes(): void
    {
        $kemarin = CarbonImmutable::parse($this->hariIni)->subDay()->toDateString();
        $besok = CarbonImmutable::parse($this->hariIni)->addDay()->toDateString();

        $hariIni = $this->sesi($this->jadwal($this->hariIni, null, 'Hari Ini'));
        $lusa = $this->sesi($this->jadwal($besok, null, 'Besok'));
        $orangLain = $this->sesi($this->jadwal($this->hariIni, null, 'Milik Orang Lain'));
        $lalu = $this->sesi($this->jadwal($kemarin, null, 'Kemarin'));
        $lainGuru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        foreach ([$hariIni, $lusa, $lalu] as $s) {
            PengawasSesi::create(['sesi_ruang_id' => $s->id, 'guru_id' => $this->guru->id]);
        }
        PengawasSesi::create(['sesi_ruang_id' => $orangLain->id, 'guru_id' => $lainGuru->id]);

        $pengawasan = $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data.guru.pengawasan');

        $this->assertSame(['Hari Ini', 'Besok'], array_column($pengawasan['sesi'], 'jadwal'));
        $this->assertTrue($pengawasan['sesi'][0]['hari_ini']);
        $this->assertArrayNotHasKey('berita_acara_tertunggak', $pengawasan);
    }

    public function test_teacher_upcoming_schedules_and_recent_results_are_scoped_to_own_subjects(): void
    {
        $mtk = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $besok = CarbonImmutable::parse($this->hariIni)->addDay()->toDateString();
        $this->jadwal($besok, null, 'IPA Besok');
        $this->jadwal($besok, $mtk, 'MTK Besok');

        $lalu = $this->jadwal(CarbonImmutable::parse($this->hariIni)->subDays(2)->toDateString(), null, 'IPA Lalu');
        $sesi = $this->sesi($lalu);
        foreach ([[$this->kelasA, 'final', 80], [$this->kelasA, 'final', 60], [$this->kelasA, 'menunggu_koreksi', 50], [$this->kelasB, 'final', 10]] as [$kelas, $status, $nilai]) {
            Nilai::query()->forceCreate([
                'peserta_ujian_id' => $this->peserta($sesi, $kelas, 'selesai', true)->id,
                'skor_per_tipe' => [], 'total' => $nilai, 'maksimal' => 100, 'nilai_akhir' => $nilai, 'status' => $status,
            ]);
        }

        $guru = $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data.guru');

        $this->assertSame(['IPA Besok'], array_column($guru['jadwal_saya'], 'nama'));
        $this->assertSame(1, $guru['jadwal_saya'][0]['jumlah_butir']);
        $this->assertCount(1, $guru['hasil_terbaru']);
        $hasil = $guru['hasil_terbaru'][0];
        $this->assertSame('IPA Lalu', $hasil['nama']);
        $this->assertSame(2, $hasil['final']);
        $this->assertSame(1, $hasil['menunggu']);
        $this->assertEquals(70.0, $hasil['rata_rata'], 'Rata-rata hanya dari nilai final di kelas yang diampu.');
    }

    public function test_teacher_lists_show_only_the_five_newest_while_totals_cover_everything(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            $jadwal = $this->jadwal(CarbonImmutable::parse($this->hariIni)->subDays($i)->toDateString(), null, "Ujian H-{$i}");
            $soal = BankSoal::query()->whereIn('id', $jadwal->soal_snapshot)->first();
            $peserta = $this->peserta($this->sesi($jadwal), $this->kelasA, 'selesai', true);
            JawabanSiswa::create(['peserta_ujian_id' => $peserta->id, 'bank_soal_id' => $soal->id, 'jawaban' => 'jawaban', 'client_seq' => 1]);
            Nilai::query()->forceCreate([
                'peserta_ujian_id' => $peserta->id, 'skor_per_tipe' => [], 'total' => 50, 'maksimal' => 100, 'nilai_akhir' => 50, 'status' => 'final',
            ]);
        }

        $guru = $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/dashboard')->json('data.guru');

        $this->assertSame(7, $guru['koreksi']['total'], 'Total mencakup semua jadwal.');
        $this->assertSame(7, $guru['koreksi']['jumlah_jadwal']);
        $this->assertSame(
            ['Ujian H-1', 'Ujian H-2', 'Ujian H-3', 'Ujian H-4', 'Ujian H-5'],
            array_column($guru['koreksi']['per_jadwal'], 'nama'),
            'Daftar antrean hanya 5 jadwal terbaru, dari yang paling baru.'
        );
        $this->assertSame(
            ['Ujian H-1', 'Ujian H-2', 'Ujian H-3', 'Ujian H-4', 'Ujian H-5'],
            array_column($guru['hasil_terbaru'], 'nama'),
            'Hasil ujian juga dibatasi 5 terbaru.'
        );
    }

    public function test_superadmin_system_panel_reports_health_activity_data_quality_and_users(): void
    {
        $super = User::factory()->role('superadmin')->create();
        LaporanPdf::create(['diminta_oleh' => $this->operator->id, 'jenis' => 'rekap-nilai', 'status' => 'failed']);
        LogAktivitas::query()->forceCreate(['user_id' => $this->operator->id, 'aksi' => 'ujian.unlock', 'created_at' => now()]);
        Guru::create(['user_id' => User::factory()->role('guru')->create()->id]); // tanpa pengampu
        Mapel::create(['kode' => 'SENI', 'nama' => 'Seni']);                       // tanpa pengampu
        User::factory()->role('siswa')->create(['aktif' => false]);

        $sistem = $this->actingAs($super, 'sanctum')->getJson('/api/v1/dashboard')->json('data.sistem');

        $this->assertSame(1, $sistem['kesehatan']['pdf_gagal_7_hari']);
        $this->assertArrayHasKey('antrean_menunggu', $sistem['kesehatan']);
        $this->assertArrayHasKey('backup_terakhir', $sistem['kesehatan']);
        $this->assertSame('ujian.unlock', $sistem['aktivitas'][0]['aksi']);
        $this->assertSame($this->operator->name, $sistem['aktivitas'][0]['aktor']);
        $this->assertSame(['nama' => '2026/2027', 'semester' => 1], $sistem['kualitas_data']['tahun_ajaran_aktif']);
        $this->assertSame(1, $sistem['kualitas_data']['guru_tanpa_pengampu']);
        $this->assertSame(1, $sistem['kualitas_data']['mapel_tanpa_pengampu']);
        $this->assertSame(2, $sistem['kualitas_data']['kelas_tanpa_siswa'], 'Kelas 7A dan 7B belum memiliki siswa.');
        $this->assertSame(1, $sistem['kualitas_data']['akun_nonaktif']);
        $this->assertSame(1, $sistem['pengguna']['superadmin']);
        $this->assertSame(1, $sistem['pengguna']['operator']);
        $this->assertSame(2, $sistem['pengguna']['guru']);
    }

    public function test_query_count_for_operator_and_teacher_does_not_grow_with_sessions_and_students(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        $hitung = function (User $user): int {
            $this->actingAs($user, 'sanctum');
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/v1/dashboard')->assertOk();
            $jumlah = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $jumlah;
        };
        $isi = function (int $jumlahSesi): void {
            for ($i = 0; $i < $jumlahSesi; $i++) {
                $jadwal = $this->jadwal($this->hariIni, null, 'J'.$this->urutan++);
                $sesi = $this->sesi($jadwal, 'Q');
                PengawasSesi::create(['sesi_ruang_id' => $sesi->id, 'guru_id' => $this->guru->id]);
                $this->peserta($sesi, $this->kelasA, 'mengerjakan', true);
                $this->peserta($sesi, $this->kelasA, 'terkunci', true, 4);
            }
        };

        $isi(2);
        $hitung($this->operator);
        $hitung($this->guruUser);
        $operatorSedikit = $hitung($this->operator);
        $guruSedikit = $hitung($this->guruUser);

        $isi(10);

        $this->assertSame($operatorSedikit, $hitung($this->operator), 'Dashboard operator memicu N+1.');
        $this->assertSame($guruSedikit, $hitung($this->guruUser), 'Dashboard guru memicu N+1.');
    }
}
