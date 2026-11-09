<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OperatorBankSoalTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Mapel, 1: Guru} */
    private function mapelDenganPengampu(bool $adaPengampu = true): array
    {
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $guru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        if ($adaPengampu) {
            GuruMapel::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'kelas_id' => $kelas->id, 'tahun_ajaran_id' => $ta->id]);
        }

        return [$mapel, $guru];
    }

    private function soalEssay(): array
    {
        return ['tipe' => 'essay', 'konten' => 'Jelaskan.', 'kunci' => 'Kunci', 'rubrik' => 'Rubrik'];
    }

    public function test_operator_creates_a_question_owned_by_the_teacher_of_that_subject(): void
    {
        [$mapel, $guru] = $this->mapelDenganPengampu();
        Sanctum::actingAs(User::factory()->role('operator')->create());

        $this->postJson('/api/v1/bank-soal', ['mapel_id' => $mapel->id] + $this->soalEssay())->assertCreated();

        $this->assertSame($guru->id, BankSoal::first()->guru_id);
    }

    public function test_operator_can_commit_a_word_import_without_choosing_a_teacher(): void
    {
        [$mapel, $guru] = $this->mapelDenganPengampu();
        Sanctum::actingAs(User::factory()->role('superadmin')->create());

        $this->postJson('/api/v1/import/word/commit', [
            'mapel_id' => $mapel->id,
            'items' => [['tipe' => 'essay', 'teks' => 'Jelaskan.', 'kunci' => 'Kunci', 'rubrik' => 'Rubrik', 'opsi' => []]],
        ])->assertOk()->assertJsonPath('imported_count', 1);

        $this->assertSame($guru->id, BankSoal::first()->guru_id);
    }

    public function test_subject_without_a_teacher_gives_a_clear_message_instead_of_generic_invalid_data(): void
    {
        [$mapel] = $this->mapelDenganPengampu(false);
        Sanctum::actingAs(User::factory()->role('operator')->create());

        $this->postJson('/api/v1/import/word/commit', ['mapel_id' => $mapel->id, 'items' => [['tipe' => 'essay', 'teks' => 'x', 'kunci' => 'k', 'rubrik' => 'r']]])
            ->assertStatus(422)
            ->assertJsonPath('error.details.mapel_id.0', 'Mapel ini belum memiliki guru pengampu. Tetapkan pengampu di Master Data terlebih dahulu.');
    }
}
