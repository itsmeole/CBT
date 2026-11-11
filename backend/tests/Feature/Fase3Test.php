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
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class Fase3Test extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_is_read_only_and_superadmin_can_filter_records(): void
    {
        $fixture = $this->createFixture();
        LogAktivitas::query()->forceCreate([
            'user_id' => $fixture['teacherUser']->id,
            'aksi' => 'ujian.lock',
            'target_type' => 'peserta_ujian',
            'target_id' => $fixture['participant']->id,
            'data' => ['pemicu' => 'blur'],
            'created_at' => now(),
        ]);

        $this->actingAs(User::factory()->role('siswa')->create(), 'sanctum')
            ->getJson('/api/v1/log-aktivitas')
            ->assertForbidden();

        $this->actingAs(User::factory()->role('superadmin')->create(), 'sanctum')
            ->getJson('/api/v1/log-aktivitas?aksi=ujian.lock&per_page=10')
            ->assertOk()
            ->assertJsonPath('data.0.aksi', 'ujian.lock')
            ->assertJsonPath('data.0.data.pemicu', 'blur');
    }

    public function test_audit_log_can_be_searched_by_actor_name_or_username(): void
    {
        $fixture = $this->createFixture();
        LogAktivitas::query()->forceCreate([
            'user_id' => $fixture['teacherUser']->id,
            'aksi' => 'ujian.lock',
            'target_type' => 'peserta_ujian',
            'target_id' => $fixture['participant']->id,
            'data' => [],
            'created_at' => now(),
        ]);
        $superadmin = User::factory()->role('superadmin')->create();

        $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/v1/log-aktivitas?cari='.urlencode($fixture['teacherUser']->username))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($superadmin, 'sanctum')
            ->getJson('/api/v1/log-aktivitas?cari=tidak-ada-orang-ini')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_item_analysis_calculates_difficulty_and_discrimination_only_for_final_students(): void
    {
        $fixture = $this->createFixture();
        $fixture['question']->update(['tipe' => 'pg', 'kunci' => 'A']);
        $fixture['schedule']->update([
            'bobot_snapshot' => ['pg' => '2.00'],
            'soal_snapshot' => [$fixture['question']->id],
        ]);
        $scores = [40, 30, 20, 10];
        $answers = ['A', 'A', 'B', 'B'];
        $participants = [$fixture['participant']];
        for ($index = 1; $index < 4; $index++) {
            $user = User::factory()->role('siswa')->create();
            $student = Siswa::create([
                'user_id' => $user->id,
                'nis' => '2026100'.($index + 2),
                'kelas_id' => $fixture['class']->id,
            ]);
            $participants[] = PesertaUjian::create([
                'siswa_id' => $student->id,
                'sesi_ruang_id' => $fixture['session']->id,
                'status' => 'selesai',
                'hadir' => true,
            ]);
        }

        foreach ($participants as $index => $participant) {
            $participant->update(['status' => 'selesai']);
            JawabanSiswa::create([
                'peserta_ujian_id' => $participant->id,
                'bank_soal_id' => $fixture['question']->id,
                'jawaban' => $answers[$index],
                'client_seq' => 1,
            ]);
            Nilai::create([
                'peserta_ujian_id' => $participant->id,
                'skor_per_tipe' => ['pg' => '2.00'],
                'total' => (string) $scores[$index],
                'maksimal' => '100.00',
                'nilai_akhir' => (string) $scores[$index],
                'status' => 'final',
            ]);
        }

        $ungradedUser = User::factory()->role('siswa')->create();
        $ungradedStudent = Siswa::create([
            'user_id' => $ungradedUser->id,
            'nis' => '20261006',
            'kelas_id' => $fixture['class']->id,
        ]);
        $ungradedParticipant = PesertaUjian::create([
            'siswa_id' => $ungradedStudent->id,
            'sesi_ruang_id' => $fixture['session']->id,
            'status' => 'selesai',
            'hadir' => true,
        ]);
        Nilai::create([
            'peserta_ujian_id' => $ungradedParticipant->id,
            'skor_per_tipe' => ['pg' => '0.00'],
            'total' => '0.00',
            'maksimal' => '2.00',
            'nilai_akhir' => '0.00',
            'status' => 'menunggu_koreksi',
        ]);

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->getJson("/api/v1/analisis-butir/{$fixture['schedule']->id}")
            ->assertOk()
            ->assertJsonPath('data.jumlah_peserta_final', 4)
            ->assertJsonPath('data.butir.0.indeks_kesukaran', 0.5)
            ->assertJsonPath('data.butir.0.daya_pembeda', 1);
    }

    public function test_operator_can_queue_and_download_a_private_pdf_report(): void
    {
        Storage::fake('local');
        $fixture = $this->createFixture();
        $operator = User::factory()->role('operator')->create();

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/laporan-pdf/rekap-nilai', ['jadwal_id' => $fixture['schedule']->id])
            ->assertStatus(202);

        $reportId = $response->json('data.id');
        $this->assertDatabaseHas('laporan_pdf', [
            'id' => $reportId,
            'diminta_oleh' => $operator->id,
            'jenis' => 'rekap-nilai',
            'status' => 'completed',
        ]);
        $storedPath = LaporanPdf::findOrFail($reportId)->file_path;
        Storage::disk('local')->assertExists($storedPath);
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($storedPath));

        $this->actingAs(User::factory()->role('operator')->create(), 'sanctum')
            ->getJson("/api/v1/laporan-pdf/{$reportId}")
            ->assertNotFound();

        $download = $this->actingAs($operator, 'sanctum')
            ->get("/api/v1/laporan-pdf/{$reportId}")
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        foreach (['kartu-peserta', 'berita-acara'] as $jenisDihapus) {
            $this->actingAs($operator, 'sanctum')
                ->postJson("/api/v1/laporan-pdf/{$jenisDihapus}", ['jadwal_id' => $fixture['schedule']->id])
                ->assertNotFound();
        }
    }

    private function createFixture(): array
    {
        $academicYear = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $class = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $academicYear->id]);
        $subject = Mapel::create(['kode' => 'IPA', 'nama' => 'Ilmu Pengetahuan Alam']);
        $teacherUser = User::factory()->role('guru')->create();
        $teacher = Guru::create(['user_id' => $teacherUser->id]);
        GuruMapel::create([
            'guru_id' => $teacher->id,
            'mapel_id' => $subject->id,
            'kelas_id' => $class->id,
            'tahun_ajaran_id' => $academicYear->id,
        ]);
        $studentUser = User::factory()->role('siswa')->create();
        $student = Siswa::create([
            'user_id' => $studentUser->id,
            'nis' => '20261001',
            'kelas_id' => $class->id,
        ]);
        $question = BankSoal::create([
            'guru_id' => $teacher->id,
            'mapel_id' => $subject->id,
            'tipe' => 'essay',
            'konten' => 'Jelaskan proses fotosintesis.',
            'kunci' => 'Kunci panduan',
            'rubrik' => 'Menyebutkan komponen utama.',
        ]);
        $package = PaketSoal::create([
            'mapel_id' => $subject->id,
            'guru_id' => $teacher->id,
            'nama' => 'Paket IPA',
        ]);
        PaketSoalItem::create([
            'paket_soal_id' => $package->id,
            'bank_soal_id' => $question->id,
            'urutan' => 1,
        ]);
        $schedule = JadwalUjian::create([
            'mapel_id' => $subject->id,
            'paket_soal_id' => $package->id,
            'tahun_ajaran_id' => $academicYear->id,
            'nama' => 'Ujian IPA',
            'tanggal' => '2026-11-04',
            'durasi_menit' => 60,
            'bobot_snapshot' => ['essay' => '5.00'],
            'soal_snapshot' => [$question->id],
        ]);
        $room = Ruang::create(['nama' => 'Ruang 1', 'kapasitas' => 30]);
        $session = SesiRuang::create([
            'jadwal_ujian_id' => $schedule->id,
            'ruang_id' => $room->id,
            'sesi_ke' => 1,
            'jam_mulai' => '08:00',
            'jam_akhir' => '09:00',
        ]);
        $participant = PesertaUjian::create([
            'siswa_id' => $student->id,
            'sesi_ruang_id' => $session->id,
            'status' => 'selesai',
            'hadir' => true,
        ]);

        return compact(
            'academicYear',
            'class',
            'subject',
            'teacherUser',
            'teacher',
            'studentUser',
            'student',
            'question',
            'package',
            'schedule',
            'room',
            'session',
            'participant',
        );
    }
}
