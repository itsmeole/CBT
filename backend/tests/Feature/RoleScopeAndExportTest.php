<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\OpsiSoal;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\ExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class RoleScopeAndExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_manage_academic_years(): void
    {
        $operator = User::factory()->role('operator')->create();
        $superadmin = User::factory()->role('superadmin')->create();

        // Operator boleh membaca (dropdown kelas/jadwal), tetapi tidak boleh mengubah tahun ajaran.
        $this->actingAs($operator, 'sanctum')->getJson('/api/v1/tahun-ajaran')->assertOk();
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/tahun-ajaran', [
            'nama' => '2026/2027',
            'semester' => 1,
        ])->assertForbidden();
        $this->actingAs($operator, 'sanctum')->putJson('/api/v1/tahun-ajaran/1', ['nama' => 'x'])->assertForbidden();
        $this->actingAs($operator, 'sanctum')->deleteJson('/api/v1/tahun-ajaran/1')->assertForbidden();

        $this->actingAs($superadmin, 'sanctum')->postJson('/api/v1/tahun-ajaran', [
            'nama' => '2026/2027',
            'semester' => 1,
            'aktif' => true,
        ])->assertCreated();
    }

    public function test_teacher_can_only_access_own_bank_questions_and_packages_for_assigned_subjects(): void
    {
        $fixture = $this->teachingFixture();
        $otherTeacher = $this->createTeacher($fixture['mapel'], $fixture['kelas'], $fixture['tahun']);
        $ownQuestion = $this->createQuestion($fixture['teacher'], $fixture['mapel']);
        $otherQuestion = $this->createQuestion($otherTeacher, $fixture['mapel']);
        $ownPackage = $this->createPackage($fixture['teacher'], $fixture['mapel'], $ownQuestion);
        $otherPackage = $this->createPackage($otherTeacher, $fixture['mapel'], $otherQuestion);
        $packageWithForeignQuestion = $this->createPackage($fixture['teacher'], $fixture['mapel'], $otherQuestion);

        $this->actingAs($fixture['user'], 'sanctum')
            ->getJson('/api/v1/bank-soal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownQuestion->id);

        $this->actingAs($fixture['user'], 'sanctum')
            ->getJson("/api/v1/bank-soal/{$otherQuestion->id}")
            ->assertNotFound();

        $this->actingAs($fixture['user'], 'sanctum')
            ->putJson("/api/v1/bank-soal/{$otherQuestion->id}", ['konten' => 'Tidak berwenang'])
            ->assertForbidden();

        $this->actingAs($fixture['user'], 'sanctum')
            ->getJson('/api/v1/paket-soal')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPackage->id);

        $this->actingAs($fixture['user'], 'sanctum')
            ->postJson('/api/v1/paket-soal', [
                'mapel_id' => $fixture['mapel']->id,
                'nama' => 'Paket silang',
                'soal_ids' => [$otherQuestion->id],
            ])
            ->assertForbidden();

        $this->assertDatabaseHas('paket_soal', ['id' => $otherPackage->id]);
        $this->assertDatabaseHas('paket_soal', ['id' => $packageWithForeignQuestion->id]);
    }

    public function test_excel_master_export_returns_a_download(): void
    {
        $superadmin = User::factory()->role('superadmin')->create();
        $this->teachingFixture();

        $this->actingAs($superadmin, 'sanctum')
            ->post('/api/v1/export/excel/guru_mapel', ['password' => 'password'])
            ->assertOk()
            ->assertDownload('data_guru_mapel.xlsx');

        $this->actingAs($superadmin, 'sanctum')
            ->postJson('/api/v1/export/excel/soal', ['password' => 'password'])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'BAD_REQUEST');
    }

    public function test_word_package_export_download_is_limited_to_its_teacher(): void
    {
        $fixture = $this->teachingFixture();
        $otherTeacher = $this->createTeacher($fixture['mapel'], $fixture['kelas'], $fixture['tahun']);
        $question = $this->createQuestion($fixture['teacher'], $fixture['mapel']);
        $ownPackage = $this->createPackage($fixture['teacher'], $fixture['mapel'], $question);
        $otherPackage = $this->createPackage($otherTeacher, $fixture['mapel'], $question);

        $this->actingAs($fixture['user'], 'sanctum')
            ->getJson("/api/v1/export/word/paket/{$otherPackage->id}")
            ->assertForbidden();

        $this->actingAs($fixture['user'], 'sanctum')
            ->get("/api/v1/export/word/paket/{$ownPackage->id}")
            ->assertOk()
            ->assertDownload('paket_soal_'.$ownPackage->id.'.docx');
    }

    public function test_excel_export_never_includes_password_values(): void
    {
        $fixture = $this->teachingFixture();
        $filePath = app(ExcelService::class)->export('guru');
        try {
            $contents = IOFactory::load($filePath)->getActiveSheet()->toArray();
        } finally {
            unlink($filePath);
        }

        // Kolom password ada (agar sama dengan template impor) tetapi selalu kosong: hash tidak pernah diekspor.
        $this->assertSame(['username', 'nama', 'password', 'nip'], $contents[0]);
        $this->assertSame($fixture['user']->username, $contents[1][0]);
        $this->assertSame('', (string) $contents[1][2]);
        $this->assertStringNotContainsString('$2y$', json_encode($contents) ?: '');
    }

    public function test_schedule_rejects_package_with_soft_deleted_question(): void
    {
        $fixture = $this->teachingFixture();
        $question = $this->createQuestion($fixture['teacher'], $fixture['mapel']);
        $package = $this->createPackage($fixture['teacher'], $fixture['mapel'], $question);
        $question->delete();
        $operator = User::factory()->role('operator')->create();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/jadwal', [
                'mapel_id' => $fixture['mapel']->id,
                'paket_soal_id' => $package->id,
                'tahun_ajaran_id' => $fixture['tahun']->id,
                'nama' => 'Ujian Matematika',
                'tanggal' => '2026-11-06',
                'durasi_menit' => 60,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $this->assertDatabaseMissing('jadwal_ujian', ['paket_soal_id' => $package->id]);
    }

    private function teachingFixture(): array
    {
        $tahun = TahunAjaran::query()->create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::query()->create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $tahun->id]);
        $mapel = Mapel::query()->create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $user = User::factory()->role('guru')->create();
        $teacher = Guru::query()->create(['user_id' => $user->id]);
        GuruMapel::query()->create([
            'guru_id' => $teacher->id,
            'mapel_id' => $mapel->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $tahun->id,
        ]);

        return compact('tahun', 'kelas', 'mapel', 'user', 'teacher');
    }

    private function createTeacher(Mapel $mapel, Kelas $kelas, TahunAjaran $tahun): Guru
    {
        $user = User::factory()->role('guru')->create();
        $teacher = Guru::query()->create(['user_id' => $user->id]);
        GuruMapel::query()->create([
            'guru_id' => $teacher->id,
            'mapel_id' => $mapel->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $tahun->id,
        ]);

        return $teacher;
    }

    private function createQuestion(Guru $teacher, Mapel $mapel): BankSoal
    {
        $question = BankSoal::query()->create([
            'guru_id' => $teacher->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => 'Pertanyaan?',
            'kunci' => 'A',
        ]);
        OpsiSoal::query()->create([
            'bank_soal_id' => $question->id,
            'konten' => 'Pilihan A',
            'urutan' => 1,
        ]);

        return $question;
    }

    private function createPackage(Guru $teacher, Mapel $mapel, BankSoal $question): PaketSoal
    {
        $package = PaketSoal::query()->create([
            'guru_id' => $teacher->id,
            'mapel_id' => $mapel->id,
            'nama' => 'Paket',
        ]);
        PaketSoalItem::query()->create([
            'paket_soal_id' => $package->id,
            'bank_soal_id' => $question->id,
            'urutan' => 1,
        ]);

        return $package;
    }
}
