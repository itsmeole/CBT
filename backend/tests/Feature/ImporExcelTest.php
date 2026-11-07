<?php

namespace Tests\Feature;

use App\Jobs\ImporExcelMasterData;
use App\Models\Kelas;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ImporExcelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<int, array<string, string>>
     */
    private function barisKelas(): array
    {
        return [
            ['nama_kelas' => '7A', 'tingkat' => '7', 'nama_tahun_ajaran' => '2026/2027', 'semester' => '1'],
            ['nama_kelas' => '7B', 'tingkat' => '7', 'nama_tahun_ajaran' => '2026/2027', 'semester' => '1'],
        ];
    }

    public function test_commit_dispatches_queued_job_and_returns_accepted_status_id(): void
    {
        Queue::fake();
        $operator = User::factory()->role('operator')->create();

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/kelas/commit', ['items' => $this->barisKelas()])
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'antre');

        Queue::assertPushed(ImporExcelMasterData::class, fn (ImporExcelMasterData $job): bool => $job->importId === $response->json('data.import_id')
            && $job->jenis === 'kelas'
            && count($job->items) === 2);
        $this->assertSame(0, Kelas::count());
    }

    public function test_queued_import_saves_rows_and_owner_can_read_final_status(): void
    {
        $operator = User::factory()->role('operator')->create();

        $importId = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/kelas/commit', ['items' => $this->barisKelas()])
            ->assertStatus(202)
            ->json('data.import_id');

        $this->assertSame(2, Kelas::count());
        $this->actingAs($operator, 'sanctum')
            ->getJson("/api/v1/import/excel/status/{$importId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'selesai')
            ->assertJsonPath('data.imported_count', 2)
            ->assertJsonMissingPath('data.user_id');
    }

    public function test_import_status_is_hidden_from_other_users(): void
    {
        Queue::fake();
        $operator = User::factory()->role('operator')->create();
        $other = User::factory()->role('operator')->create();

        $importId = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/kelas/commit', ['items' => $this->barisKelas()])
            ->json('data.import_id');

        $this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/import/excel/status/{$importId}")
            ->assertNotFound();
    }

    public function test_failed_job_marks_status_as_failed_with_message(): void
    {
        $job = new ImporExcelMasterData('abc', 'kelas', []);
        Cache::put(ImporExcelMasterData::kunciStatus('abc'), ['user_id' => 1, 'status' => 'berjalan'], 60);

        $job->failed(new \RuntimeException('Data impor tidak valid.'));

        $this->assertSame('gagal', Cache::get(ImporExcelMasterData::kunciStatus('abc'))['status']);
        $this->assertSame('Data impor tidak valid.', Cache::get(ImporExcelMasterData::kunciStatus('abc'))['message']);
    }

    public function test_commit_rejects_unknown_type_and_empty_items(): void
    {
        $operator = User::factory()->role('operator')->create();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/soal/commit', ['items' => $this->barisKelas()])
            ->assertStatus(400);
        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/kelas/commit', ['items' => []])
            ->assertStatus(422);
    }
}
