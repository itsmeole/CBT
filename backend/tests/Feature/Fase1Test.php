<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\BobotTipe;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\OpsiSoal;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\TokenUjian;
use App\Models\User;
use App\Services\PenilaianService;
use App\Services\WordSoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class Fase1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed bobot default
        BobotTipe::create(['tipe' => 'pg', 'bobot' => 1.00]);
        BobotTipe::create(['tipe' => 'pg_kompleks', 'bobot' => 1.00]);
        BobotTipe::create(['tipe' => 'benar_salah', 'bobot' => 1.00]);
        BobotTipe::create(['tipe' => 'menjodohkan', 'bobot' => 1.00]);
        BobotTipe::create(['tipe' => 'essay', 'bobot' => 5.00]);
    }

    public function test_penilaian_service_calculates_all_types_correctly(): void
    {
        $service = new PenilaianService();

        // 1. PG: 1.00 jika benar, 0.00 jika salah
        $this->assertEquals(1.00, $service->hitungSkorSoal('pg', 'B', 'B', 1.00));
        $this->assertEquals(0.00, $service->hitungSkorSoal('pg', 'A', 'B', 1.00));

        // 2. PG Kompleks: All-or-nothing!
        $this->assertEquals(1.00, $service->hitungSkorSoal('pg_kompleks', ['A', 'C'], ['A', 'C'], 1.00));
        $this->assertEquals(0.00, $service->hitungSkorSoal('pg_kompleks', ['A'], ['A', 'C'], 1.00)); // partial = 0
        $this->assertEquals(0.00, $service->hitungSkorSoal('pg_kompleks', ['A', 'B', 'C'], ['A', 'C'], 1.00));

        // 3. Benar / Salah: proporsional
        // 2 pernyataan, benar 1 -> 0.50
        $this->assertEquals(0.50, $service->hitungSkorSoal('benar_salah', ['1' => 'B', '2' => 'B'], ['1' => 'B', '2' => 'S'], 1.00));
        $this->assertEquals(1.00, $service->hitungSkorSoal('benar_salah', ['1' => 'B', '2' => 'S'], ['1' => 'B', '2' => 'S'], 1.00));

        // 4. Menjodohkan: proporsional
        // 2 premis, benar 1 -> 0.50
        $this->assertEquals(0.50, $service->hitungSkorSoal('menjodohkan', ['1' => 'b', '2' => 'c'], ['1' => 'b', '2' => 'a'], 1.00));
        $this->assertEquals(1.00, $service->hitungSkorSoal('menjodohkan', ['1' => 'b', '2' => 'a'], ['1' => 'b', '2' => 'a'], 1.00));
    }

    public function test_excel_rejects_soal_import(): void
    {
        $operator = User::factory()->role('operator')->create();

        $response = $this->actingAs($operator, 'sanctum')->getJson('/api/v1/templates/excel/soal');
        $response->assertStatus(400);
        $this->assertStringContainsString('Bank soal tidak boleh diimpor/diekspor lewat Excel', $response->json('error.message'));
    }

    public function test_word_template_generation_and_preview(): void
    {
        $guruUser = User::factory()->role('guru')->create();
        Guru::create(['user_id' => $guruUser->id]);

        $service = new WordSoalService();
        $templatePath = $service->generateTemplate();
        $this->assertFileExists($templatePath);

        // Preview file template tersebut
        $file = new UploadedFile($templatePath, 'template_soal.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $preview = $service->preview($file);

        $this->assertGreaterThanOrEqual(4, $preview['valid_count']);
        $this->assertEquals(0, $preview['error_count']);

        unlink($templatePath);
    }

    public function test_operator_can_control_exam_but_guru_is_forbidden(): void
    {
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $ruang = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 36]);
        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);

        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'Paket 1']);

        // Tambah 1 soal ke paket
        $soal = BankSoal::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => '1 + 1 = ...',
            'kunci' => 'A',
        ]);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);

        $jadwal = JadwalUjian::create([
            'mapel_id' => $mapel->id,
            'paket_soal_id' => $paket->id,
            'tahun_ajaran_id' => $ta->id,
            'nama' => 'UTS Matematika',
            'tanggal' => '2026-11-03',
            'durasi_menit' => 60,
            'bobot_snapshot' => ['pg' => 1.00],
        ]);

        $sesi = SesiRuang::create([
            'jadwal_ujian_id' => $jadwal->id,
            'ruang_id' => $ruang->id,
            'sesi_ke' => 1,
            'jam_mulai' => '08:00',
            'jam_akhir' => '09:00',
        ]);

        // 1. GURU MENCOBA TEKAN MULAI -> HARUS 403 FORBIDDEN!
        $this->actingAs($guruUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertStatus(403);

        // 2. OPERATOR MENCOBA TEKAN MULAI -> HARUS 200 OK!
        $operatorUser = User::factory()->role('operator')->create();
        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertOk();
    }

    public function test_complete_siswa_exam_lifecycle_without_exposing_score(): void
    {
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'Ilmu Pengetahuan Alam']);
        $ruang = Ruang::create(['nama' => 'Lab IPA', 'kapasitas' => 36]);

        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);

        $siswaUser = User::factory()->role('siswa')->create(['username' => 'siswa_test']);
        $siswa = Siswa::create(['user_id' => $siswaUser->id, 'nis' => '999001', 'kelas_id' => $kelas->id]);

        $soal = BankSoal::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => 'Hewan pemakan daging disebut ...',
            'kunci' => 'A',
            'rubrik' => 'Karnivora',
        ]);
        $opsiA = OpsiSoal::create(['bank_soal_id' => $soal->id, 'konten' => 'Karnivora', 'urutan' => 1]);
        $opsiB = OpsiSoal::create(['bank_soal_id' => $soal->id, 'konten' => 'Herbivora', 'urutan' => 2]);

        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'Paket IPA']);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);

        $jadwal = JadwalUjian::create([
            'mapel_id' => $mapel->id,
            'paket_soal_id' => $paket->id,
            'tahun_ajaran_id' => $ta->id,
            'nama' => 'Ujian IPA',
            'tanggal' => '2026-11-03',
            'durasi_menit' => 90,
            'bobot_snapshot' => ['pg' => 10.00],
        ]);

        $sesi = SesiRuang::create([
            'jadwal_ujian_id' => $jadwal->id,
            'ruang_id' => $ruang->id,
            'sesi_ke' => 1,
            'jam_mulai' => '07:30',
            'jam_akhir' => '09:00',
        ]);

        $token = TokenUjian::create([
            'sesi_ruang_id' => $sesi->id,
            'kode' => 'ABC234',
            'aktif' => true,
            'dibuat_oleh' => $guruUser->id,
        ]);

        // 1. Siswa join dengan token
        $joinRes = $this->actingAs($siswaUser, 'sanctum')
            ->postJson('/api/v1/ujian/join', ['token' => 'ABC234'])
            ->assertOk();
        $this->assertEquals('menunggu', $joinRes->json('data.status'));

        // 2. Siswa coba ambil soal saat masih 'menunggu' -> HARUS DITOLAK
        $this->actingAs($siswaUser, 'sanctum')
            ->getJson('/api/v1/ujian/soal')
            ->assertStatus(403);

        // 3. Operator mulai ujian
        $operatorUser = User::factory()->role('operator')->create();
        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertOk();

        // 4. Siswa ambil soal -> HARUS BERHASIL & KUNCI/RUBRIK TIDAK BOLEH MUNCUL!
        $soalRes = $this->actingAs($siswaUser, 'sanctum')
            ->getJson('/api/v1/ujian/soal')
            ->assertOk();

        $soalData = $soalRes->json('data.0');
        $this->assertEquals($soal->id, $soalData['soal_id']);
        $this->assertArrayNotHasKey('kunci', $soalData);
        $this->assertArrayNotHasKey('rubrik', $soalData);
        $this->assertArrayNotHasKey('skor', $soalData);

        // 5. Autosave jawaban
        $this->actingAs($siswaUser, 'sanctum')
            ->putJson('/api/v1/ujian/jawaban', [
                'items' => [
                    ['soal_id' => $soal->id, 'jawaban' => 'A', 'client_seq' => 1]
                ]
            ])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        // 6. Submit ujian
        $submitRes = $this->actingAs($siswaUser, 'sanctum')
            ->postJson('/api/v1/ujian/submit')
            ->assertOk();

        // Pastikan TIDAK ADA nilai atau skor yang dikembalikan ke siswa! (FR-BE-69, FR-BE-92)
        $this->assertEquals('selesai', $submitRes->json('status'));
        $this->assertArrayNotHasKey('nilai', $submitRes->json());
        $this->assertArrayNotHasKey('skor', $submitRes->json());

        // Verifikasi di DB nilai dihitung otomatis
        $this->assertDatabaseHas('nilai', [
            'total' => 10.00,
            'nilai_akhir' => 100.00,
            'status' => 'final',
        ]);
    }
}

