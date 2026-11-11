<?php

namespace Tests\Feature;

use App\Jobs\GenerateLaporanPdf;
use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\LaporanPdf;
use App\Models\Mapel;
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class PenempatanRuangTest extends TestCase
{
    use RefreshDatabase;

    private TahunAjaran $tahun;

    private Kelas $kelas;

    private Mapel $mapel;

    private Guru $guru;

    private User $guruUser;

    private User $operator;

    private JadwalUjian $jadwal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tahun = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $this->kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $this->tahun->id]);
        $this->mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA']);
        $this->guruUser = User::factory()->role('guru')->create();
        $this->guru = Guru::create(['user_id' => $this->guruUser->id]);
        GuruMapel::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mapel->id, 'kelas_id' => $this->kelas->id, 'tahun_ajaran_id' => $this->tahun->id]);
        $this->operator = User::factory()->role('operator')->create();
        $this->jadwal = $this->buatJadwal($this->mapel, '2026-11-10');
    }

    private function buatJadwal(Mapel $mapel, string $tanggal): JadwalUjian
    {
        $soal = BankSoal::create(['guru_id' => $this->guru->id, 'mapel_id' => $mapel->id, 'tipe' => 'essay', 'konten' => 'x', 'kunci' => 'k', 'rubrik' => 'r']);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $this->guru->id, 'nama' => 'P'.$mapel->id]);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);

        return JadwalUjian::create([
            'mapel_id' => $mapel->id, 'paket_soal_id' => $paket->id, 'tahun_ajaran_id' => $this->tahun->id,
            'nama' => 'Ujian '.$mapel->nama, 'tanggal' => $tanggal, 'durasi_menit' => 60,
            'bobot_snapshot' => ['essay' => '5.00'], 'soal_snapshot' => [$soal->id],
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function buatSiswa(int $jumlah): array
    {
        $ids = [];
        for ($i = 0; $i < $jumlah; $i++) {
            $ids[] = Siswa::create([
                'user_id' => User::factory()->role('siswa')->create()->id,
                'nis' => 'N'.uniqid(),
                'kelas_id' => $this->kelas->id,
            ])->id;
        }

        return $ids;
    }

    private function payload(array $penempatan, array $ekstra = []): array
    {
        return array_merge(['sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30', 'penempatan' => $penempatan], $ekstra);
    }

    public function test_operator_places_students_into_rooms_and_sessions_get_tokens(): void
    {
        $siswa = $this->buatSiswa(5);
        $r1 = Ruang::create(['nama' => 'R1', 'kapasitas' => 3]);
        $r2 = Ruang::create(['nama' => 'R2', 'kapasitas' => 3]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([
                ['ruang_id' => $r1->id, 'siswa_ids' => array_slice($siswa, 0, 3)],
                ['ruang_id' => $r2->id, 'siswa_ids' => array_slice($siswa, 3)],
            ]))
            ->assertCreated()
            ->assertJsonCount(2, 'data');

        $this->assertSame(2, SesiRuang::where('jadwal_ujian_id', $this->jadwal->id)->count());
        $this->assertSame(5, PesertaUjian::count());
        $this->assertSame(2, TokenUjian::where('aktif', true)->count());
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'jadwal.penempatan_dibuat', 'target_id' => $this->jadwal->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan")
            ->assertOk()
            ->assertJsonCount(2, 'data.sesi')
            ->assertJsonPath('data.sesi.0.ruang_id', $r1->id)
            ->assertJsonPath('data.sesi.1.ruang_id', $r2->id)
            ->assertJsonCount(3, 'data.sesi.0.peserta');
    }

    public function test_guru_cannot_place_students(): void
    {
        $this->actingAs($this->guruUser, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([]))
            ->assertForbidden();
    }

    public function test_placement_rejects_room_over_capacity_duplicates_and_foreign_year_students(): void
    {
        $siswa = $this->buatSiswa(4);
        $kecil = Ruang::create(['nama' => 'Kecil', 'kapasitas' => 2]);
        $besar = Ruang::create(['nama' => 'Besar', 'kapasitas' => 10]);
        $url = "/api/v1/jadwal/{$this->jadwal->id}/penempatan";

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $this->payload([['ruang_id' => $kecil->id, 'siswa_ids' => $siswa]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CAPACITY_EXCEEDED');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $this->payload([
                ['ruang_id' => $kecil->id, 'siswa_ids' => [$siswa[0]]],
                ['ruang_id' => $besar->id, 'siswa_ids' => [$siswa[0], $siswa[1]]],
            ]))
            ->assertStatus(422);

        $tahunLain = TahunAjaran::create(['nama' => '2025/2026', 'semester' => 1, 'aktif' => false]);
        $kelasLain = Kelas::create(['nama' => '9Z', 'tingkat' => '9', 'tahun_ajaran_id' => $tahunLain->id]);
        $asing = Siswa::create(['user_id' => User::factory()->role('siswa')->create()->id, 'nis' => 'ASING', 'kelas_id' => $kelasLain->id]);
        $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $this->payload([['ruang_id' => $besar->id, 'siswa_ids' => [$asing->id]]]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertSame(0, PesertaUjian::count());
    }

    public function test_student_cannot_be_placed_twice_but_placement_can_be_reset_before_exam_starts(): void
    {
        $siswa = $this->buatSiswa(2);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $url = "/api/v1/jadwal/{$this->jadwal->id}/penempatan";
        $body = $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => $siswa]]);

        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body)->assertCreated();
        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ALREADY_PLACED');

        $this->actingAs($this->operator, 'sanctum')->deleteJson($url)->assertOk();
        $this->assertSame(0, PesertaUjian::count());
        $this->assertSame(0, SesiRuang::count());
        $this->actingAs($this->operator, 'sanctum')->postJson($url, $body)->assertCreated();
    }

    public function test_reset_is_blocked_once_attendance_is_recorded(): void
    {
        $siswa = $this->buatSiswa(1);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $url = "/api/v1/jadwal/{$this->jadwal->id}/penempatan";
        $this->actingAs($this->operator, 'sanctum')
            ->postJson($url, $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => $siswa]]))->assertCreated();
        PesertaUjian::query()->update(['hadir' => true]);

        $this->actingAs($this->operator, 'sanctum')->deleteJson($url)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IN_USE');
        $this->assertSame(1, PesertaUjian::count());
    }

    public function test_unstarted_schedule_can_be_deleted_and_frees_its_package_and_questions(): void
    {
        $siswa = $this->buatSiswa(2);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => $siswa]]))->assertCreated();
        $paketId = $this->jadwal->paket_soal_id;
        $soalId = $this->jadwal->soal_snapshot[0];

        $this->actingAs($this->guruUser, 'sanctum')->deleteJson("/api/v1/jadwal/{$this->jadwal->id}")->assertForbidden();
        $this->actingAs($this->guruUser, 'sanctum')->deleteJson("/api/v1/paket-soal/{$paketId}")->assertStatus(422);

        $this->actingAs($this->operator, 'sanctum')->deleteJson("/api/v1/jadwal/{$this->jadwal->id}")->assertOk();

        $this->assertNull(JadwalUjian::find($this->jadwal->id));
        $this->assertSame(0, SesiRuang::count());
        $this->assertSame(0, PesertaUjian::count());
        $this->assertDatabaseHas('log_aktivitas', ['aksi' => 'jadwal.dihapus']);

        $this->actingAs($this->guruUser, 'sanctum')->putJson("/api/v1/bank-soal/{$soalId}", ['konten' => 'diubah'])->assertOk();
        $this->actingAs($this->guruUser, 'sanctum')->deleteJson("/api/v1/paket-soal/{$paketId}")->assertOk();
    }

    public function test_schedule_deletion_is_blocked_after_attendance_or_exam_start(): void
    {
        $siswa = $this->buatSiswa(1);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => $siswa]]))->assertCreated();
        PesertaUjian::query()->update(['hadir' => true]);

        $this->actingAs($this->operator, 'sanctum')->deleteJson("/api/v1/jadwal/{$this->jadwal->id}")
            ->assertStatus(422)->assertJsonPath('error.code', 'IN_USE');
        $this->assertNotNull(JadwalUjian::find($this->jadwal->id));
        $this->assertSame(1, PesertaUjian::count());
    }

    public function test_room_and_student_schedule_conflicts_on_the_same_day_are_rejected(): void
    {
        $siswa = $this->buatSiswa(2);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $lain = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => [$siswa[0]]]]))
            ->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$lain->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => [$siswa[1]]]], ['jam_mulai' => '09:00', 'jam_akhir' => '10:00']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'ROOM_CONFLICT');

        $ruang2 = Ruang::create(['nama' => 'R2', 'kapasitas' => 5]);
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$lain->id}/penempatan", $this->payload([['ruang_id' => $ruang2->id, 'siswa_ids' => [$siswa[0]]]], ['jam_mulai' => '09:00', 'jam_akhir' => '10:00']))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SCHEDULE_CONFLICT');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$lain->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => [$siswa[0]]]], ['sesi_ke' => 2, 'jam_mulai' => '10:00', 'jam_akhir' => '11:00']))
            ->assertCreated();
    }

    public function test_supervisor_can_change_room_between_subjects_but_not_overlap(): void
    {
        $ruang1 = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $ruang2 = Ruang::create(['nama' => 'R2', 'kapasitas' => 5]);
        $mtk = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        $sesiIpa = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang1->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30']);
        $sesiMtkBentrok = SesiRuang::create(['jadwal_ujian_id' => $mtk->id, 'ruang_id' => $ruang2->id, 'sesi_ke' => 1, 'jam_mulai' => '09:00', 'jam_akhir' => '10:30']);
        $sesiMtkAman = SesiRuang::create(['jadwal_ujian_id' => $mtk->id, 'ruang_id' => $ruang2->id, 'sesi_ke' => 2, 'jam_mulai' => '10:00', 'jam_akhir' => '11:30']);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesiIpa->id}/pengawas", ['guru_ids' => [$this->guru->id]])->assertOk();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesiMtkBentrok->id}/pengawas", ['guru_ids' => [$this->guru->id]])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPERVISOR_CONFLICT');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesiMtkAman->id}/pengawas", ['guru_ids' => [$this->guru->id]])->assertOk();

        // Menyimpan ulang pengawas pada sesi yang sama tidak dianggap bentrok dengan dirinya sendiri.
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesiIpa->id}/pengawas", ['guru_ids' => [$this->guru->id]])->assertOk();
    }

    public function test_attendance_pdf_lists_supervisors_with_their_other_assignments_and_sorted_students(): void
    {
        $ruang1 = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $ruang2 = Ruang::create(['nama' => 'R2', 'kapasitas' => 5]);
        $mtk = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        $sesiIpa = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang1->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30']);
        $sesiMtk = SesiRuang::create(['jadwal_ujian_id' => $mtk->id, 'ruang_id' => $ruang2->id, 'sesi_ke' => 2, 'jam_mulai' => '10:00', 'jam_akhir' => '11:30']);
        PengawasSesi::create(['sesi_ruang_id' => $sesiIpa->id, 'guru_id' => $this->guru->id]);
        PengawasSesi::create(['sesi_ruang_id' => $sesiMtk->id, 'guru_id' => $this->guru->id]);
        $siswaId = $this->buatSiswa(1)[0];
        PesertaUjian::create(['siswa_id' => $siswaId, 'sesi_ruang_id' => $sesiIpa->id, 'status' => 'belum_login', 'hadir' => true]);

        $laporan = LaporanPdf::create(['diminta_oleh' => $this->operator->id, 'jenis' => 'daftar-hadir', 'sesi_ruang_id' => $sesiIpa->id, 'status' => 'pending']);
        $metode = new ReflectionMethod(GenerateLaporanPdf::class, 'daftarHadir');
        $html = $metode->invoke(new GenerateLaporanPdf($laporan->id), $laporan);

        $this->assertStringContainsString($this->guruUser->name, $html, 'Pengawas ruang tercantum.');
        $this->assertStringContainsString('08:00 - 09:30', $html);
        $this->assertStringContainsString('Selasa, 10 November 2026', $html);
        $this->assertStringContainsString('Tanda tangan', $html);
        $this->assertStringNotContainsString('Tidak hadir', $html, 'Daftar hadir cetak berupa tabel nama kosong untuk ditandatangani, tanpa status kehadiran.');
        $this->assertStringNotContainsString('Penugasan lain', $html);
    }

    public function test_attendance_list_for_all_rooms_contains_every_room_of_the_schedule_and_is_operator_only(): void
    {
        $ruang1 = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $ruang2 = Ruang::create(['nama' => 'R2', 'kapasitas' => 5]);
        $sesi1 = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang1->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30']);
        $sesi2 = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang2->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30']);
        [$a, $b] = $this->buatSiswa(2);
        PesertaUjian::create(['siswa_id' => $a, 'sesi_ruang_id' => $sesi1->id, 'status' => 'belum_login', 'hadir' => true]);
        PesertaUjian::create(['siswa_id' => $b, 'sesi_ruang_id' => $sesi2->id, 'status' => 'belum_login', 'hadir' => false]);

        $this->actingAs($this->guruUser, 'sanctum')->postJson('/api/v1/laporan-pdf/daftar-hadir-semua', ['jadwal_id' => $this->jadwal->id])->assertForbidden();
        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/laporan-pdf/daftar-hadir-semua', ['sesi_ruang_id' => $sesi1->id])->assertStatus(422);
        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/laporan-pdf/daftar-hadir-semua', ['jadwal_id' => $this->jadwal->id])->assertStatus(202);

        $laporan = LaporanPdf::where('jenis', 'daftar-hadir-semua')->firstOrFail();
        $metode = new ReflectionMethod(GenerateLaporanPdf::class, 'daftarHadirSemuaRuang');
        $html = $metode->invoke(new GenerateLaporanPdf($laporan->id), $laporan);

        $this->assertSame(2, substr_count($html, 'Daftar Hadir Peserta Ujian'), 'Satu bagian per ruang.');
        $this->assertStringContainsString('R1', $html);
        $this->assertStringContainsString('R2', $html);
        $this->assertStringContainsString('Tanda tangan', $html);
    }

    public function test_different_exams_can_share_a_room_at_the_exact_same_time_within_the_combined_capacity(): void
    {
        $siswa = $this->buatSiswa(4);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 3]);
        $lain = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => array_slice($siswa, 0, 2)]]))
            ->assertCreated();

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$lain->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => array_slice($siswa, 2, 2)]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'CAPACITY_EXCEEDED');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$lain->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => [$siswa[2]]]]))
            ->assertCreated();

        $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/jadwal/{$lain->id}/penempatan")
            ->assertJsonPath('data.sesi.0.terisi_lain', 2);
    }

    public function test_attendance_sheet_of_a_shared_room_lists_every_exam_once_with_a_subject_column(): void
    {
        $siswa = $this->buatSiswa(2);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $lain = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        foreach ([[$this->jadwal, $siswa[0]], [$lain, $siswa[1]]] as [$jadwal, $id]) {
            $this->actingAs($this->operator, 'sanctum')
                ->postJson("/api/v1/jadwal/{$jadwal->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => [$id]]]))->assertCreated();
        }
        $laporan = LaporanPdf::create(['jenis' => 'daftar-hadir-semua', 'jadwal_ujian_id' => $this->jadwal->id, 'diminta_oleh' => $this->operator->id, 'status' => 'pending']);

        $metode = new ReflectionMethod(GenerateLaporanPdf::class, 'daftarHadirSemuaRuang');
        $html = $metode->invoke(new GenerateLaporanPdf($laporan->id), $laporan);

        $this->assertSame(1, substr_count($html, 'Daftar Hadir Peserta Ujian'));
        $this->assertStringContainsString('<th>Mapel</th>', $html);
        $this->assertStringContainsString('Matematika', $html);
        $this->assertSame(2, substr_count($html, '<td class="ttd"></td>'));
    }

    public function test_combined_exam_creates_one_schedule_per_subject_in_a_single_request(): void
    {
        $lain = $this->buatJadwal(Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']), '2026-11-10');
        $baris = [
            ['mapel_id' => $this->mapel->id, 'paket_soal_id' => $this->jadwal->paket_soal_id],
            ['mapel_id' => $lain->mapel_id, 'paket_soal_id' => $lain->paket_soal_id],
        ];
        $jumlahAwal = JadwalUjian::count();

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/jadwal/gabungan', [
            'nama' => 'UTS', 'tanggal' => '2026-11-20', 'durasi_menit' => 60, 'butir' => $baris,
        ])->assertCreated()->assertJsonCount(2, 'data');
        $this->assertSame($jumlahAwal + 2, JadwalUjian::count());

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/jadwal/gabungan', [
            'nama' => 'UTS', 'tanggal' => '2026-11-20', 'durasi_menit' => 60,
            'butir' => [$baris[0], ['mapel_id' => $this->mapel->id, 'paket_soal_id' => $lain->paket_soal_id]],
        ])->assertStatus(422);
        $this->assertSame($jumlahAwal + 2, JadwalUjian::count());
    }

    public function test_editing_a_teacher_removes_unselected_subjects_even_when_assigned_per_class(): void
    {
        $mtk = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        GuruMapel::create(['guru_id' => $this->guru->id, 'mapel_id' => $mtk->id, 'kelas_id' => $this->kelas->id, 'tahun_ajaran_id' => $this->tahun->id]);

        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/guru/{$this->guru->id}", ['mapel_ids' => [$this->mapel->id]])->assertOk();

        $this->assertSame([$this->mapel->id], GuruMapel::where('guru_id', $this->guru->id)->pluck('mapel_id')->unique()->values()->all());

        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/guru/{$this->guru->id}", ['mapel_ids' => []])->assertOk();
        $this->assertSame(0, GuruMapel::where('guru_id', $this->guru->id)->count());
    }

    public function test_a_grade_specific_subject_only_accepts_students_from_that_grade(): void
    {
        $siswa = $this->buatSiswa(1);
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $mapel8 = Mapel::create(['kode' => 'MTK8', 'nama' => 'Matematika Kelas 8', 'tingkat' => '8']);
        $jadwal8 = $this->buatJadwal($mapel8, '2026-11-10');

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/jadwal/{$jadwal8->id}/penempatan", $this->payload([['ruang_id' => $ruang->id, 'siswa_ids' => $siswa]]))
            ->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');
    }

    public function test_operator_can_edit_a_teacher_subject_assignment_but_not_into_a_duplicate(): void
    {
        $gm = GuruMapel::first();
        $mapelBaru = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $lain = GuruMapel::create(['guru_id' => $this->guru->id, 'mapel_id' => $mapelBaru->id, 'kelas_id' => $this->kelas->id, 'tahun_ajaran_id' => $this->tahun->id]);
        $data = ['guru_id' => $this->guru->id, 'mapel_id' => $mapelBaru->id, 'kelas_id' => $this->kelas->id, 'tahun_ajaran_id' => $this->tahun->id];

        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/guru-mapel/{$gm->id}", $data)->assertStatus(422);

        $kelasB = Kelas::create(['nama' => '7B', 'tingkat' => '7', 'tahun_ajaran_id' => $this->tahun->id]);
        $this->actingAs($this->operator, 'sanctum')->putJson("/api/v1/guru-mapel/{$gm->id}", [...$data, 'kelas_id' => $kelasB->id])->assertOk();

        $this->assertSame($kelasB->id, $gm->fresh()->kelas_id);
        $this->assertSame($this->kelas->id, $lain->fresh()->kelas_id);
    }

    public function test_grade_recap_uses_the_configured_queue(): void
    {
        Queue::fake();
        config(['cbt.queue_laporan' => 'default']);

        $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/laporan-pdf/rekap-nilai', ['jadwal_id' => $this->jadwal->id])->assertStatus(202);

        Queue::assertPushedOn('default', GenerateLaporanPdf::class);
    }

    public function test_attendance_list_is_generated_immediately_without_a_worker(): void
    {
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:30']);

        $id = $this->actingAs($this->operator, 'sanctum')->postJson('/api/v1/laporan-pdf/daftar-hadir', ['sesi_ruang_id' => $sesi->id])
            ->assertStatus(202)->json('data.id');

        $this->actingAs($this->operator, 'sanctum')->get("/api/v1/laporan-pdf/{$id}")->assertOk();
    }

    public function test_schedule_and_placement_show_the_active_token_not_a_stale_one_after_regeneration(): void
    {
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:00']);
        TokenUjian::create(['sesi_ruang_id' => $sesi->id, 'kode' => 'LAMA22', 'aktif' => true, 'dibuat_oleh' => $this->operator->id]);

        $baru = $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/token/generate")
            ->assertOk()
            ->json('data.kode');

        $this->assertNotSame('LAMA22', $baru);
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/jadwal')
            ->assertOk()
            ->assertJsonPath('data.0.sesi_ruang.0.token_ujian.kode', $baru);
        $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan")
            ->assertOk()
            ->assertJsonPath('data.sesi.0.token', $baru);
    }

    public function test_session_without_an_active_token_has_no_token(): void
    {
        $ruang = Ruang::create(['nama' => 'R1', 'kapasitas' => 5]);
        $sesi = SesiRuang::create(['jadwal_ujian_id' => $this->jadwal->id, 'ruang_id' => $ruang->id, 'sesi_ke' => 1, 'jam_mulai' => '08:00', 'jam_akhir' => '09:00']);
        TokenUjian::create(['sesi_ruang_id' => $sesi->id, 'kode' => 'MATI22', 'aktif' => false, 'dibuat_oleh' => $this->operator->id]);

        $this->actingAs($this->operator, 'sanctum')->getJson("/api/v1/jadwal/{$this->jadwal->id}/penempatan")
            ->assertOk()
            ->assertJsonPath('data.sesi.0.token', null);
    }

    public function test_operator_can_create_a_question_package_without_sending_a_teacher_id(): void
    {
        $soal = BankSoal::create(['guru_id' => $this->guru->id, 'mapel_id' => $this->mapel->id, 'tipe' => 'essay', 'konten' => 'q', 'kunci' => 'k', 'rubrik' => 'r']);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/v1/paket-soal', ['mapel_id' => $this->mapel->id, 'nama' => 'Paket Operator', 'soal_ids' => [$soal->id]])
            ->assertCreated();

        $this->assertDatabaseHas('paket_soal', ['nama' => 'Paket Operator', 'guru_id' => $this->guru->id]);
    }

    public function test_teacher_only_sees_own_subjects_classes_and_schedules(): void
    {
        $mapelLain = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $kelasLain = Kelas::create(['nama' => '8B', 'tingkat' => '8', 'tahun_ajaran_id' => $this->tahun->id]);
        $this->buatJadwal($mapelLain, '2026-11-11');

        $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/mapel')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->mapel->id);
        $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/kelas')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->kelas->id);
        $this->actingAs($this->guruUser, 'sanctum')->getJson('/api/v1/jadwal')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->jadwal->id);

        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/mapel')->assertJsonCount(2, 'data');
        $this->actingAs($this->operator, 'sanctum')->getJson('/api/v1/kelas')->assertJsonCount(2, 'data');
        $this->assertNotNull($kelasLain->id);
    }
}
