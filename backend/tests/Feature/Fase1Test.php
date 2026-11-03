<?php

namespace Tests\Feature;

use App\Jobs\ProsesPenilaianPeserta;
use App\Models\BankSoal;
use App\Models\BobotTipe;
use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\JawabanSiswa;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Media;
use App\Models\OpsiSoal;
use App\Models\PaketSoal;
use App\Models\PaketSoalItem;
use App\Models\PesertaUjian;
use App\Models\Ruang;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\TokenUjian;
use App\Models\User;
use App\Services\ExcelService;
use App\Services\PenilaianService;
use App\Services\WordSoalService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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
        $service = new PenilaianService;

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

        $this->assertSame('2.35', $service->hitungSkorSoal('pg', 'A', 'A', '2.35'));
        $this->assertSame('0.67', $service->hitungSkorSoal('benar_salah', ['1' => 'B', '2' => 'S', '3' => 'B'], ['1' => 'B', '2' => 'S', '3' => 'S'], '1.00'));
        $this->assertSame('5.00', $service->hitungSkorSoal('essay', '6.50', null, '5.00'));
        $this->assertSame('0.00', $service->hitungSkorSoal('essay', '-1', null, '5.00'));
        $this->assertSame('0.00', $service->hitungSkorSoal('pg_kompleks', [], ['A', 'B'], '1.00'));
    }

    public function test_excel_rejects_soal_import(): void
    {
        $operator = User::factory()->role('operator')->create();

        $response = $this->actingAs($operator, 'sanctum')->getJson('/api/v1/templates/excel/soal');
        $response->assertStatus(400);
        $this->assertStringContainsString('Bank soal tidak boleh diimpor/diekspor lewat Excel', $response->json('error.message'));
    }

    public function test_bank_soal_create_validates_choice_structure_and_normalizes_answer_key(): void
    {
        $tahunAjaran = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $tahunAjaran->id]);
        $mapel = Mapel::create(['kode' => 'MTK2', 'nama' => 'Matematika']);
        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);
        GuruMapel::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'kelas_id' => $kelas->id,
            'tahun_ajaran_id' => $tahunAjaran->id,
        ]);

        $this->actingAs($guruUser, 'sanctum')->postJson('/api/v1/bank-soal', [
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => '<script>alert(1)</script><p>Soal aman</p>',
            'kunci' => 'C',
            'opsi' => [['konten' => 'A'], ['konten' => 'B']],
        ])->assertStatus(422)->assertJsonPath('error.code', 'VALIDATION_ERROR');

        $response = $this->actingAs($guruUser, 'sanctum')->postJson('/api/v1/bank-soal', [
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => '<script>alert(1)</script><p>Soal aman</p>',
            'kunci' => 'A',
            'opsi' => [['konten' => '<img src=x onerror=alert(1)>Pilihan A'], ['konten' => 'Pilihan B']],
        ])->assertCreated();

        $soal = BankSoal::with('opsiSoal')->findOrFail($response->json('data.id'));
        $this->assertSame('<p>Soal aman</p>', $soal->konten);
        $this->assertSame('A', $soal->kunci);
        $this->assertStringNotContainsString('onerror', $soal->opsiSoal->first()->konten);
    }

    public function test_excel_import_requires_valid_headers_and_revalidates_commit_rows(): void
    {
        TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create([
            'nama' => '7A',
            'tingkat' => '7',
            'tahun_ajaran_id' => TahunAjaran::first()->id,
        ]);
        $service = new ExcelService;
        $templatePath = $service->generateTemplate('siswa');
        $file = new UploadedFile($templatePath, 'siswa.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);

        $preview = $service->preview('siswa', $file);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame(0, $preview['error_count']);
        $this->assertSame(1, $service->commit('siswa', $preview['preview']));
        $this->assertDatabaseHas('siswa', ['kelas_id' => $kelas->id, 'nis' => '2026001']);

        $validRow = $preview['preview'][0];
        $validRow['username'] = 'siswa-baru';
        $validRow['nis'] = '2026002';
        $invalidRow = array_replace($validRow, ['username' => 'siswa-invalid', 'password' => '']);

        try {
            $service->commit('siswa', [$validRow, $invalidRow]);
            $this->fail('Commit harus menolak seluruh batch ketika satu baris tidak valid.');
        } catch (\Exception $exception) {
            $this->assertStringContainsString('Data impor tidak valid', $exception->getMessage());
        }

        $this->assertDatabaseMissing('users', ['username' => 'siswa-baru']);
        unlink($templatePath);
    }

    public function test_media_upload_rejects_invalid_mime_and_resizes_images(): void
    {
        Storage::fake('public');
        $guru = User::factory()->role('guru')->create();
        $temporaryPath = tempnam(sys_get_temp_dir(), 'cbt_test_image_');
        $image = imagecreatetruecolor(1400, 40);
        imagejpeg($image, $temporaryPath, 95);
        imagedestroy($image);

        $upload = new UploadedFile($temporaryPath, 'soal.jpg', 'image/jpeg', null, true);
        $response = $this->actingAs($guru, 'sanctum')->post('/api/v1/media', ['file' => $upload], ['Accept' => 'application/json']);
        $response->assertCreated();

        $media = Media::findOrFail($response->json('data.id'));
        $relativePath = str_replace('/storage/', '', $media->path);
        $storedPath = Storage::disk('public')->path($relativePath);
        $this->assertSame(1280, getimagesize($storedPath)[0]);

        $invalidPath = tempnam(sys_get_temp_dir(), 'cbt_test_invalid_');
        file_put_contents($invalidPath, 'not an image');
        $invalid = new UploadedFile($invalidPath, 'not-image.txt', 'text/plain', null, true);
        $this->actingAs($guru, 'sanctum')
            ->post('/api/v1/media', ['file' => $invalid], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVALID_MIME');

        unlink($temporaryPath);
        unlink($invalidPath);
    }

    public function test_word_template_generation_and_preview(): void
    {
        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);
        $mapel = Mapel::create(['kode' => 'BIND', 'nama' => 'Bahasa Indonesia']);

        $service = new WordSoalService;
        $templatePath = $service->generateTemplate();
        $this->assertFileExists($templatePath);

        // Preview file template tersebut
        $file = new UploadedFile($templatePath, 'template_soal.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $preview = $service->preview($file);

        $this->assertSame(5, $preview['valid_count']);
        $this->assertEquals(0, $preview['error_count']);
        $this->assertSame(5, $service->commit($guru->id, $mapel->id, $preview['items']));

        $exportPath = $service->exportToWord(BankSoal::with('opsiSoal')->get());
        $exportFile = new UploadedFile($exportPath, 'export.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $roundTrip = $service->preview($exportFile);
        $this->assertSame(5, $roundTrip['valid_count']);
        $this->assertSame(0, $roundTrip['error_count']);

        unlink($templatePath);
        unlink($exportPath);
    }

    public function test_operator_can_control_exam_but_guru_is_forbidden(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 08:00:00', 'Asia/Jakarta'));

        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $mapel = Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        $ruang = Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 36]);
        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);
        $siswaUser = User::factory()->role('siswa')->create();
        $siswa = Siswa::create(['user_id' => $siswaUser->id, 'nis' => '112233', 'kelas_id' => $kelas->id]);

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
        $peserta = PesertaUjian::create([
            'siswa_id' => $siswa->id,
            'sesi_ruang_id' => $sesi->id,
            'status' => 'menunggu',
            'hadir' => false,
        ]);

        // 1. GURU MENCOBA TEKAN MULAI -> HARUS 403 FORBIDDEN!
        $this->actingAs($guruUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertStatus(403);

        // 2. OPERATOR MENCOBA TEKAN MULAI -> HARUS 200 OK!
        $operatorUser = User::factory()->role('operator')->create();
        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertOk()
            ->assertJsonPath('message', 'Ujian berhasil dimulai untuk 0 peserta.');
        $this->assertDatabaseHas('peserta_ujian', ['id' => $peserta->id, 'status' => 'menunggu']);

        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/kehadiran", [
                'kehadiran' => [['peserta_id' => $peserta->id, 'hadir' => true]],
            ])
            ->assertOk();
        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/mulai")
            ->assertOk()
            ->assertJsonPath('message', 'Ujian berhasil dimulai untuk 1 peserta.');
        $this->assertDatabaseHas('peserta_ujian', ['id' => $peserta->id, 'status' => 'mengerjakan']);
    }

    public function test_jadwal_snapshots_questions_and_weights_and_freezes_used_questions(): void
    {
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $mapel = Mapel::create(['kode' => 'IPS', 'nama' => 'Ilmu Pengetahuan Sosial']);
        $guruUser = User::factory()->role('guru')->create();
        $guru = Guru::create(['user_id' => $guruUser->id]);
        $soal = BankSoal::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => 'Soal snapshot',
            'kunci' => 'A',
        ]);
        $paket = PaketSoal::create(['mapel_id' => $mapel->id, 'guru_id' => $guru->id, 'nama' => 'Paket IPS']);
        PaketSoalItem::create(['paket_soal_id' => $paket->id, 'bank_soal_id' => $soal->id, 'urutan' => 1]);
        $operator = User::factory()->role('operator')->create();

        $jadwalResponse = $this->actingAs($operator, 'sanctum')->postJson('/api/v1/jadwal', [
            'mapel_id' => $mapel->id,
            'paket_soal_id' => $paket->id,
            'tahun_ajaran_id' => $ta->id,
            'nama' => 'Ujian IPS',
            'tanggal' => '2026-11-03',
            'durasi_menit' => 60,
        ])->assertCreated();

        $jadwalId = $jadwalResponse->json('data.id');
        $this->assertSame([$soal->id], $jadwalResponse->json('data.soal_snapshot'));
        $this->assertSame('1.00', (string) $jadwalResponse->json('data.bobot_snapshot.pg'));

        $this->actingAs($operator, 'sanctum')
            ->putJson('/api/v1/bobot-tipe', ['bobot' => ['pg' => 9]])
            ->assertOk();
        $this->assertSame('1.00', (string) JadwalUjian::findOrFail($jadwalId)->bobot_snapshot['pg']);

        $admin = User::factory()->role('superadmin')->create();
        $this->actingAs($admin, 'sanctum')
            ->putJson("/api/v1/bank-soal/{$soal->id}", ['konten' => 'Tidak boleh berubah'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'IN_USE');
    }

    public function test_complete_siswa_exam_lifecycle_without_exposing_score(): void
    {
        $this->travelTo(Carbon::parse('2026-11-03 08:00:00', 'Asia/Jakarta'));
        Queue::fake();

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
        $peserta = PesertaUjian::create([
            'siswa_id' => $siswa->id,
            'sesi_ruang_id' => $sesi->id,
            'status' => 'belum_login',
            'hadir' => false,
        ]);

        // 1. Siswa join dengan token
        $joinRes = $this->actingAs($siswaUser, 'sanctum')
            ->postJson('/api/v1/ujian/join', ['token' => 'ABC234'])
            ->assertOk();
        $this->assertEquals('menunggu', $joinRes->json('data.status'));

        $operatorUser = User::factory()->role('operator')->create();
        $this->actingAs($operatorUser, 'sanctum')
            ->postJson("/api/v1/sesi-ruang/{$sesi->id}/kehadiran", [
                'kehadiran' => [['peserta_id' => $peserta->id, 'hadir' => true]],
            ])
            ->assertOk();

        // 2. Siswa coba ambil soal saat masih 'menunggu' -> HARUS DITOLAK
        $this->actingAs($siswaUser, 'sanctum')
            ->getJson('/api/v1/ujian/soal')
            ->assertStatus(403);

        // 3. Operator mulai ujian
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
        $jawabanBenar = collect($soalData['opsi'])->firstWhere('konten', 'Karnivora');
        $this->assertNotSame((string) $opsiA->id, $jawabanBenar['id']);

        $soalLuarPaket = BankSoal::create([
            'guru_id' => $guru->id,
            'mapel_id' => $mapel->id,
            'tipe' => 'pg',
            'konten' => 'Soal yang tidak dijadwalkan',
            'kunci' => 'A',
        ]);
        $this->actingAs($siswaUser, 'sanctum')
            ->putJson('/api/v1/ujian/jawaban', [
                'items' => [['soal_id' => $soalLuarPaket->id, 'jawaban' => 'A', 'client_seq' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR');

        // 5. Autosave jawaban
        $this->actingAs($siswaUser, 'sanctum')
            ->putJson('/api/v1/ujian/jawaban', [
                'items' => [
                    ['soal_id' => $soal->id, 'jawaban' => $jawabanBenar['id'], 'client_seq' => 2],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'saved');

        $this->actingAs($siswaUser, 'sanctum')
            ->putJson('/api/v1/ujian/jawaban', [
                'items' => [['soal_id' => $soal->id, 'jawaban' => 'B', 'client_seq' => 1]],
            ])
            ->assertOk();
        $this->assertDatabaseHas('jawaban_siswa', [
            'peserta_ujian_id' => $peserta->id,
            'bank_soal_id' => $soal->id,
        ]);
        $jawabanTersimpan = JawabanSiswa::where('peserta_ujian_id', $peserta->id)->where('bank_soal_id', $soal->id)->firstOrFail();
        $this->assertSame(2, $jawabanTersimpan->client_seq);
        $this->assertSame('A', $jawabanTersimpan->jawaban);
        $this->actingAs($siswaUser, 'sanctum')
            ->getJson('/api/v1/ujian/state')
            ->assertOk()
            ->assertJsonPath("data.jawaban_tersimpan.{$soal->id}", $jawabanBenar['id']);

        PesertaUjian::whereKey($peserta->id)->update(['status' => 'terkunci']);
        $this->actingAs($siswaUser, 'sanctum')
            ->putJson('/api/v1/ujian/jawaban', [
                'items' => [['soal_id' => $soal->id, 'jawaban' => 'B', 'client_seq' => 3]],
            ])
            ->assertStatus(423)
            ->assertJsonPath('error.code', 'SESSION_LOCKED');
        PesertaUjian::whereKey($peserta->id)->update(['status' => 'mengerjakan']);

        // 6. Submit ujian
        $submitRes = $this->actingAs($siswaUser, 'sanctum')
            ->postJson('/api/v1/ujian/submit')
            ->assertOk();

        // Pastikan TIDAK ADA nilai atau skor yang dikembalikan ke siswa! (FR-BE-69, FR-BE-92)
        $this->assertEquals('selesai', $submitRes->json('status'));
        $this->assertArrayNotHasKey('nilai', $submitRes->json());
        $this->assertArrayNotHasKey('skor', $submitRes->json());

        Queue::assertPushed(ProsesPenilaianPeserta::class, fn ($job) => $job->pesertaId === $peserta->id);
        (new ProsesPenilaianPeserta($peserta->id))->handle(app(PenilaianService::class));

        // Verifikasi penilaian berjalan dari job queue.
        $this->assertDatabaseHas('nilai', [
            'total' => 10.00,
            'nilai_akhir' => 100.00,
            'status' => 'final',
        ]);

        $siswaLainUser = User::factory()->role('siswa')->create();
        $siswaLain = Siswa::create(['user_id' => $siswaLainUser->id, 'nis' => '999002', 'kelas_id' => $kelas->id]);
        $batasWaktu = Carbon::parse('2026-11-03 00:59:00', 'UTC');
        $pesertaTerkunci = PesertaUjian::create([
            'siswa_id' => $siswaLain->id,
            'sesi_ruang_id' => $sesi->id,
            'status' => 'terkunci',
            'hadir' => true,
            'waktu_selesai' => $batasWaktu,
        ]);
        $this->travelTo(Carbon::parse('2026-11-03 09:00:00', 'Asia/Jakarta'));
        $this->artisan('app:submit-peserta-kedaluwarsa')->assertExitCode(0);
        $this->assertDatabaseHas('peserta_ujian', ['id' => $pesertaTerkunci->id, 'status' => 'selesai']);
        $this->assertSame($batasWaktu->toDateTimeString(), $pesertaTerkunci->fresh()->waktu_selesai->toDateTimeString());
        Queue::assertPushed(ProsesPenilaianPeserta::class, fn ($job) => $job->pesertaId === $pesertaTerkunci->id);
    }
}
