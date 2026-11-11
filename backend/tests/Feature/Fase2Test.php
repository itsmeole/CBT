<?php

namespace Tests\Feature;

use App\Events\ForceLogout;
use App\Events\LockThresholdReached;
use App\Events\PesertaLocked;
use App\Events\PesertaSubmitted;
use App\Events\PesertaUnlocked;
use App\Events\SesiUnlocked;
use App\Events\WaktuHabis;
use App\Models\BankSoal;
use App\Models\DeviceSession;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\Kelas;
use App\Models\LockEvent;
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
use App\Services\PenilaianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class Fase2Test extends TestCase
{
    use RefreshDatabase;

    public function test_student_lock_is_idempotent_records_history_and_emits_warning_at_threshold(): void
    {
        Event::fake();
        $fixture = $this->createFixture();
        $operator = User::factory()->role('operator')->create();

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/lock-threshold', ['ambang_kunci' => 1])
            ->assertOk()
            ->assertJsonPath('data.ambang_kunci', 1);

        $this->actingAs($fixture['studentUser'], 'sanctum')
            ->postJson('/api/v1/ujian/lock', ['pemicu' => 'visibilitychange'])
            ->assertOk()
            ->assertJsonPath('data.status', 'terkunci')
            ->assertJsonPath('data.jumlah_kunci', 1);

        $this->actingAs($fixture['studentUser'], 'sanctum')
            ->postJson('/api/v1/ujian/lock', ['pemicu' => 'pagehide'])
            ->assertOk();

        $this->assertDatabaseCount('lock_event', 1);
        $this->assertDatabaseHas('peserta_ujian', [
            'id' => $fixture['participant']->id,
            'status' => 'terkunci',
            'jumlah_kunci' => 1,
            'waktu_selesai' => $fixture['deadline']->toDateTimeString(),
        ]);
        Event::assertDispatched(PesertaLocked::class);
        Event::assertDispatched(LockThresholdReached::class);
    }

    public function test_unlock_requires_assigned_supervisor_and_reason_without_changing_deadline(): void
    {
        Event::fake();
        $fixture = $this->createFixture('terkunci');
        $supervisorUser = User::factory()->role('guru')->create();
        $supervisor = Guru::create(['user_id' => $supervisorUser->id]);
        PengawasSesi::create(['sesi_ruang_id' => $fixture['session']->id, 'guru_id' => $supervisor->id]);
        $otherTeacher = User::factory()->role('guru')->create();
        $this->assertTrue(Broadcast::getChannels()->has('sesi.{sesiRuangId}'));

        $this->actingAs($supervisorUser, 'sanctum')
            ->postJson("/api/v1/peserta/{$fixture['participant']->id}/unlock", ['alasan' => 'No'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($otherTeacher, 'sanctum')
            ->postJson("/api/v1/peserta/{$fixture['participant']->id}/unlock", ['alasan' => 'Gangguan perangkat'])
            ->assertForbidden();

        $lockEvent = LockEvent::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'pemicu' => 'blur',
            'waktu_kunci' => now()->subMinute(),
        ]);

        $this->actingAs($supervisorUser, 'sanctum')
            ->postJson("/api/v1/peserta/{$fixture['participant']->id}/unlock", ['alasan' => 'Layar mati'])
            ->assertOk()
            ->assertJsonPath('data.status', 'mengerjakan')
            ->assertJsonPath('data.waktu_selesai', $fixture['deadline']->toIso8601String());

        $this->assertDatabaseHas('peserta_ujian', [
            'id' => $fixture['participant']->id,
            'status' => 'mengerjakan',
            'waktu_selesai' => $fixture['deadline']->toDateTimeString(),
        ]);
        $this->assertDatabaseHas('lock_event', [
            'id' => $lockEvent->id,
            'dibuka_oleh' => $supervisorUser->id,
            'alasan' => 'Layar mati',
        ]);
        Event::assertDispatched(PesertaUnlocked::class);
        Event::assertDispatched(SesiUnlocked::class);
    }

    public function test_monitoring_is_restricted_to_assigned_supervisor_and_returns_only_safe_summary(): void
    {
        $fixture = $this->createFixture('mengerjakan');
        JawabanSiswa::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'bank_soal_id' => $fixture['question']->id,
            'jawaban' => 'Jawaban siswa',
            'client_seq' => 1,
        ]);
        $supervisorUser = User::factory()->role('guru')->create();
        $supervisor = Guru::create(['user_id' => $supervisorUser->id]);
        PengawasSesi::create(['sesi_ruang_id' => $fixture['session']->id, 'guru_id' => $supervisor->id]);
        $otherTeacher = User::factory()->role('guru')->create();

        $response = $this->actingAs($supervisorUser, 'sanctum')
            ->getJson("/api/v1/sesi-ruang/{$fixture['session']->id}/monitoring")
            ->assertOk()
            ->assertJsonPath('data.peserta.0.status', 'mengerjakan')
            ->assertJsonPath('data.peserta.0.terjawab', 1)
            ->assertJsonPath('data.peserta.0.user_id', $fixture['participant']->siswa->user_id);

        $this->assertArrayNotHasKey('jawaban', $response->json('data.peserta.0'));
        $this->assertArrayNotHasKey('nilai', $response->json('data.peserta.0'));
        $this->assertArrayNotHasKey('kunci', $response->json('data.peserta.0'));

        $this->actingAs($otherTeacher, 'sanctum')
            ->getJson("/api/v1/sesi-ruang/{$fixture['session']->id}/monitoring")
            ->assertForbidden();
    }

    public function test_supervision_session_list_only_contains_sessions_the_user_may_supervise(): void
    {
        $fixture = $this->createFixture();
        $otherSession = SesiRuang::create([
            'jadwal_ujian_id' => $fixture['session']->jadwal_ujian_id,
            'ruang_id' => $fixture['session']->ruang_id,
            'sesi_ke' => 2,
            'jam_mulai' => '10:00',
            'jam_akhir' => '11:00',
        ]);
        $supervisorUser = User::factory()->role('guru')->create();
        $supervisor = Guru::create(['user_id' => $supervisorUser->id]);
        PengawasSesi::create(['sesi_ruang_id' => $fixture['session']->id, 'guru_id' => $supervisor->id]);
        $unassignedTeacher = User::factory()->role('guru')->create();

        $this->actingAs($supervisorUser, 'sanctum')
            ->getJson('/api/v1/pengawasan/sesi')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $fixture['session']->id);

        $this->actingAs($unassignedTeacher, 'sanctum')
            ->getJson('/api/v1/pengawasan/sesi')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs(User::factory()->role('operator')->create(), 'sanctum')
            ->getJson('/api/v1/pengawasan/sesi')
            ->assertOk()
            ->assertJsonCount(2, 'data');
        $this->assertNotNull($otherSession->id);
    }

    public function test_private_channels_are_limited_to_assigned_supervisors_and_participant_owner(): void
    {
        $fixture = $this->createFixture();
        $supervisorUser = User::factory()->role('guru')->create();
        $supervisor = Guru::create(['user_id' => $supervisorUser->id]);
        PengawasSesi::create(['sesi_ruang_id' => $fixture['session']->id, 'guru_id' => $supervisor->id]);
        $otherTeacher = User::factory()->role('guru')->create();

        $sessionChannel = Broadcast::getChannels()->get('sesi.{sesiRuangId}');
        $participantChannel = Broadcast::getChannels()->get('peserta.{pesertaId}');

        $this->assertIsCallable($sessionChannel);
        $this->assertIsCallable($participantChannel);
        $this->assertTrue($sessionChannel($supervisorUser, $fixture['session']->id));
        $this->assertFalse($sessionChannel($otherTeacher, $fixture['session']->id));
        $this->assertTrue($participantChannel($fixture['studentUser'], $fixture['participant']->id));
        $this->assertFalse($participantChannel($otherTeacher, $fixture['participant']->id));
    }

    public function test_operator_can_reset_student_login_without_changing_exam_work(): void
    {
        Event::fake();
        $fixture = $this->createFixture('terkunci');
        $answer = JawabanSiswa::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'bank_soal_id' => $fixture['question']->id,
            'jawaban' => 'Teks jawaban',
            'client_seq' => 7,
        ]);
        $token = $fixture['studentUser']->createToken('test-device');
        DeviceSession::create([
            'user_id' => $fixture['studentUser']->id,
            'token_id' => (string) $token->accessToken->id,
            'aktif' => true,
        ]);

        $this->actingAs(User::factory()->role('operator')->create(), 'sanctum')
            ->postJson("/api/v1/auth/reset-login/{$fixture['studentUser']->id}")
            ->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $fixture['studentUser']->id]);
        $this->assertDatabaseHas('device_sessions', ['user_id' => $fixture['studentUser']->id, 'aktif' => false]);
        $this->assertDatabaseHas('jawaban_siswa', ['id' => $answer->id, 'client_seq' => 7]);
        $this->assertDatabaseHas('peserta_ujian', [
            'id' => $fixture['participant']->id,
            'status' => 'terkunci',
            'waktu_selesai' => $fixture['deadline']->toDateTimeString(),
        ]);
        Event::assertDispatched(ForceLogout::class);
    }

    public function test_expired_exam_state_submits_and_notifies_student_and_supervisor_channels(): void
    {
        Event::fake();
        $fixture = $this->createFixture('mengerjakan');
        $fixture['participant']->update(['waktu_selesai' => now()->subSecond()]);

        $this->actingAs($fixture['studentUser'], 'sanctum')
            ->getJson('/api/v1/ujian/state')
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai');

        Event::assertDispatched(PesertaSubmitted::class, fn (PesertaSubmitted $event): bool => $event->pesertaId === $fixture['participant']->id
            && $event->sesiRuangId === $fixture['session']->id);
        Event::assertDispatched(WaktuHabis::class, fn (WaktuHabis $event): bool => $event->pesertaId === $fixture['participant']->id);
    }

    public function test_essay_queue_and_scoring_are_scoped_and_recalculate_until_final(): void
    {
        $fixture = $this->createFixture('selesai');
        $secondQuestion = BankSoal::create([
            'guru_id' => $fixture['teacher']->id,
            'mapel_id' => $fixture['subject']->id,
            'tipe' => 'essay',
            'konten' => 'Pertanyaan esai kedua',
            'kunci' => 'Kunci panduan kedua',
            'rubrik' => 'Rubrik kedua',
        ]);
        $thirdQuestion = BankSoal::create([
            'guru_id' => $fixture['teacher']->id,
            'mapel_id' => $fixture['subject']->id,
            'tipe' => 'essay',
            'konten' => 'Pertanyaan esai ketiga',
            'kunci' => 'Kunci panduan ketiga',
            'rubrik' => 'Rubrik ketiga',
        ]);
        PaketSoalItem::create([
            'paket_soal_id' => $fixture['package']->id,
            'bank_soal_id' => $secondQuestion->id,
            'urutan' => 2,
        ]);
        PaketSoalItem::create([
            'paket_soal_id' => $fixture['package']->id,
            'bank_soal_id' => $thirdQuestion->id,
            'urutan' => 3,
        ]);
        $fixture['schedule']->update(['soal_snapshot' => [$fixture['question']->id, $secondQuestion->id, $thirdQuestion->id]]);

        $firstAnswer = JawabanSiswa::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'bank_soal_id' => $fixture['question']->id,
            'jawaban' => 'Essay satu',
            'client_seq' => 1,
        ]);
        $secondAnswer = JawabanSiswa::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'bank_soal_id' => $secondQuestion->id,
            'jawaban' => 'Essay dua',
            'client_seq' => 2,
        ]);

        app(PenilaianService::class)->nilaiPeserta($fixture['participant']->fresh());
        $this->assertDatabaseHas('nilai', ['peserta_ujian_id' => $fixture['participant']->id, 'status' => 'menunggu_koreksi']);
        $unanswered = JawabanSiswa::where('peserta_ujian_id', $fixture['participant']->id)
            ->where('bank_soal_id', $thirdQuestion->id)
            ->firstOrFail();

        $queue = $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->getJson('/api/v1/koreksi/essay?jadwal_id='.$fixture['schedule']->id)
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.kunci', 'Kunci panduan');
        $this->assertArrayNotHasKey('nilai_akhir', $queue->json('data.0'));

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->putJson("/api/v1/koreksi/essay/{$firstAnswer->id}", ['skor' => '5.01'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->putJson("/api/v1/koreksi/essay/{$firstAnswer->id}", [
                'skor' => '4.50',
                'catatan' => 'Penjelasan cukup lengkap.',
            ])
            ->assertOk()
            ->assertJsonPath('data.nilai_status', 'menunggu_koreksi');
        $this->assertDatabaseHas('jawaban_siswa', [
            'id' => $firstAnswer->id,
            'catatan_koreksi' => 'Penjelasan cukup lengkap.',
        ]);

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->putJson("/api/v1/koreksi/essay/{$secondAnswer->id}", ['skor' => '3.00'])
            ->assertOk()
            ->assertJsonPath('data.nilai_status', 'menunggu_koreksi');

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->putJson("/api/v1/koreksi/essay/{$unanswered->id}", ['skor' => '0.00'])
            ->assertOk()
            ->assertJsonPath('data.nilai_status', 'final');

        $this->assertDatabaseHas('nilai', [
            'peserta_ujian_id' => $fixture['participant']->id,
            'status' => 'final',
            'total' => '7.50',
            'maksimal' => '15.00',
            'nilai_akhir' => '50.00',
        ]);
    }

    public function test_operator_and_superadmin_can_see_all_essay_answers_but_only_superadmin_and_teacher_can_score(): void
    {
        $fixture = $this->createFixture('selesai');
        $jawaban = JawabanSiswa::create([
            'peserta_ujian_id' => $fixture['participant']->id,
            'bank_soal_id' => $fixture['question']->id,
            'jawaban' => 'Jawaban siswa',
            'client_seq' => 1,
        ]);
        $url = '/api/v1/koreksi/essay?jadwal_id='.$fixture['schedule']->id;
        $operator = User::factory()->role('operator')->create();
        $superadmin = User::factory()->role('superadmin')->create();

        $this->actingAs($operator, 'sanctum')->getJson($url)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.jawaban', 'Jawaban siswa')->assertJsonPath('data.0.sudah_dikoreksi', false);
        $this->actingAs($operator, 'sanctum')->putJson("/api/v1/koreksi/essay/{$jawaban->id}", ['skor' => '1.00'])->assertForbidden();

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->putJson("/api/v1/koreksi/essay/{$jawaban->id}", ['skor' => '2.00', 'catatan' => 'ok'])->assertOk();

        // Antrean biasa tidak lagi memuatnya; mode lihat semua tetap menampilkan jawaban yang sudah dikoreksi.
        $this->actingAs($operator, 'sanctum')->getJson($url)->assertOk()->assertJsonCount(0, 'data');
        foreach ([$operator, $superadmin] as $pengguna) {
            $this->actingAs($pengguna, 'sanctum')->getJson($url.'&termasuk_dikoreksi=1')
                ->assertOk()->assertJsonCount(1, 'data')
                ->assertJsonPath('data.0.jawaban', 'Jawaban siswa')
                ->assertJsonPath('data.0.skor', '2.00')
                ->assertJsonPath('data.0.sudah_dikoreksi', true);
        }
    }

    public function test_teacher_grade_recap_is_limited_to_taught_classes_and_students_cannot_read_it(): void
    {
        $fixture = $this->createFixture('selesai');
        $otherClass = Kelas::create([
            'nama' => '8B',
            'tingkat' => '8',
            'tahun_ajaran_id' => $fixture['academicYear']->id,
        ]);
        $otherStudentUser = User::factory()->role('siswa')->create();
        $otherStudent = Siswa::create([
            'user_id' => $otherStudentUser->id,
            'nis' => '12345678',
            'kelas_id' => $otherClass->id,
        ]);
        PesertaUjian::create([
            'siswa_id' => $otherStudent->id,
            'sesi_ruang_id' => $fixture['session']->id,
            'status' => 'selesai',
            'hadir' => true,
        ]);

        $this->actingAs($fixture['teacherUser'], 'sanctum')
            ->getJson('/api/v1/nilai?jadwal_id='.$fixture['schedule']->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($fixture['studentUser'], 'sanctum')
            ->getJson('/api/v1/nilai?jadwal_id='.$fixture['schedule']->id)
            ->assertForbidden();
    }

    private function createFixture(string $status = 'mengerjakan'): array
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
            'nis' => '20260001',
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
        $deadline = now()->addMinutes(30)->setMicrosecond(0);
        $participant = PesertaUjian::create([
            'siswa_id' => $student->id,
            'sesi_ruang_id' => $session->id,
            'status' => $status,
            'hadir' => true,
            'waktu_mulai' => now()->subMinutes(30),
            'waktu_selesai' => $deadline,
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
            'deadline',
        );
    }
}
