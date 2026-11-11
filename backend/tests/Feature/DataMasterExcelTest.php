<?php

namespace Tests\Feature;

use App\Models\Guru;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use App\Services\ExcelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class DataMasterExcelTest extends TestCase
{
    use RefreshDatabase;

    private const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    private function siapkanData(): array
    {
        $ta = TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $kelas = Kelas::create(['nama' => '7A', 'tingkat' => '7', 'tahun_ajaran_id' => $ta->id]);
        $userSiswa = User::factory()->role('siswa')->create(['username' => 'abc123', 'name' => 'Ahmad', 'password' => 'rahasia123']);
        Siswa::create(['user_id' => $userSiswa->id, 'nis' => '1001', 'kelas_id' => $kelas->id, 'jk' => 'P']);
        $userGuru = User::factory()->role('guru')->create(['username' => 'guruku', 'name' => 'Bu Guru', 'password' => 'rahasia123']);
        Guru::create(['user_id' => $userGuru->id, 'nip' => '777']);

        return compact('ta', 'kelas', 'userSiswa', 'userGuru');
    }

    /** @return list<list<string>> */
    private function bacaXlsx(string $path): array
    {
        return IOFactory::load($path)->getActiveSheet()->toArray(null, true, true, false);
    }

    private function simpanXlsx(array $baris): UploadedFile
    {
        $buku = new Spreadsheet;
        $buku->getActiveSheet()->fromArray($baris, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'tes_').'.xlsx';
        (new Xlsx($buku))->save($path);

        return new UploadedFile($path, 'impor.xlsx', self::MIME, null, true);
    }

    public function test_download_requires_the_current_users_password(): void
    {
        $this->siapkanData();
        $operator = User::factory()->role('operator')->create();

        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/export/excel/siswa', [])->assertStatus(422);
        $this->actingAs($operator, 'sanctum')->postJson('/api/v1/export/excel/siswa', ['password' => 'salah'])
            ->assertStatus(422)->assertJsonPath('error.details.password.0', 'Password salah.');
        $this->actingAs($operator, 'sanctum')->post('/api/v1/export/excel/siswa', ['password' => 'password'])
            ->assertOk()->assertDownload('data_siswa.xlsx');
    }

    public function test_every_master_data_type_downloads_with_the_same_columns_as_its_template(): void
    {
        $this->siapkanData();
        Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 36]);
        $service = new ExcelService;

        foreach (ExcelService::JENIS as $jenis) {
            $unduh = $this->bacaXlsx($service->export($jenis));
            $template = $this->bacaXlsx($service->generateTemplate($jenis));

            $this->assertSame($template[0], $unduh[0], "Kolom {$jenis} harus sama dengan template impor.");
            $this->assertSame($service->kolom($jenis), $unduh[0]);
        }
    }

    public function test_exported_accounts_have_username_but_never_a_password(): void
    {
        $this->siapkanData();
        $baris = $this->bacaXlsx((new ExcelService)->export('siswa'));

        $this->assertSame('abc123', $baris[1][0]);
        $this->assertSame('', (string) $baris[1][2], 'Password tersimpan sebagai hash dan tidak diekspor.');
        $this->assertStringNotContainsString('rahasia123', json_encode($baris) ?: '');
    }

    public function test_reimporting_the_exported_file_with_one_changed_column_updates_instead_of_duplicating(): void
    {
        $data = $this->siapkanData();
        $service = new ExcelService;
        $baris = $this->bacaXlsx($service->export('siswa'));
        $baris[1][1] = 'Ahmad Rizki Baru'; // nama berubah, kolom lain tetap

        $hasil = $service->preview('siswa', $this->simpanXlsx($baris));
        $this->assertSame(0, $hasil['error_count'], json_encode($hasil['errors']) ?: '');
        $service->commit('siswa', $hasil['preview']);

        $this->assertSame(1, User::where('role', 'siswa')->count(), 'Tidak ada akun baru.');
        $this->assertSame(1, Siswa::count());
        $this->assertSame('Ahmad Rizki Baru', $data['userSiswa']->fresh()->name);
        $this->assertTrue(Hash::check('rahasia123', $data['userSiswa']->fresh()->password), 'Password kosong tidak mengubah password lama.');
        $this->assertSame('P', Siswa::first()->jk);
    }

    public function test_filled_password_changes_it_and_blank_password_is_required_for_new_accounts(): void
    {
        $data = $this->siapkanData();
        $service = new ExcelService;
        $baris = $this->bacaXlsx($service->export('guru'));
        $baris[1][2] = 'baru12345';
        $baris[] = ['guru_baru', 'Guru Baru', '', ''];

        $hasil = $service->preview('guru', $this->simpanXlsx($baris));
        $this->assertSame(1, $hasil['error_count']);
        $this->assertStringContainsString('akun baru', $hasil['errors'][0]['message']);

        unset($baris[2]);
        $hasil = $service->preview('guru', $this->simpanXlsx($baris));
        $this->assertSame(0, $hasil['error_count']);
        $service->commit('guru', $hasil['preview']);

        $this->assertTrue(Hash::check('baru12345', $data['userGuru']->fresh()->password));
        $this->assertTrue($data['userGuru']->fresh()->aktif, 'Status aktif akun tidak berubah.');
    }

    public function test_update_keeps_a_deactivated_account_deactivated(): void
    {
        $data = $this->siapkanData();
        $data['userSiswa']->update(['aktif' => false]);
        $service = new ExcelService;
        $baris = $this->bacaXlsx($service->export('siswa'));
        $baris[1][1] = 'Nama Lain';

        $service->commit('siswa', $service->preview('siswa', $this->simpanXlsx($baris))['preview']);

        $this->assertFalse($data['userSiswa']->fresh()->aktif);
    }

    public function test_mapel_ruang_and_tahun_ajaran_round_trip_and_update_by_key(): void
    {
        Mapel::create(['kode' => 'MTK', 'nama' => 'Matematika']);
        Ruang::create(['nama' => 'Lab 1', 'kapasitas' => 36]);
        TahunAjaran::create(['nama' => '2026/2027', 'semester' => 1, 'aktif' => true]);
        $service = new ExcelService;

        $mapel = $this->bacaXlsx($service->export('mapel'));
        $mapel[1][1] = 'Matematika Wajib';
        $mapel[] = ['IPA', 'Ilmu Pengetahuan Alam'];
        $service->commit('mapel', $service->preview('mapel', $this->simpanXlsx($mapel))['preview']);
        $this->assertSame(2, Mapel::count());
        $this->assertSame('Matematika Wajib', Mapel::where('kode', 'MTK')->value('nama'));

        $ruang = $this->bacaXlsx($service->export('ruang'));
        $ruang[1][1] = '40';
        $service->commit('ruang', $service->preview('ruang', $this->simpanXlsx($ruang))['preview']);
        $this->assertSame(1, Ruang::count());
        $this->assertSame(40, Ruang::first()->kapasitas);

        $ta = $this->bacaXlsx($service->export('tahun_ajaran'));
        $this->assertSame('ya', $ta[1][2]);
        $ta[] = ['2026/2027', '2', 'ya'];
        $service->commit('tahun_ajaran', $service->preview('tahun_ajaran', $this->simpanXlsx($ta))['preview']);
        $this->assertSame(2, TahunAjaran::count());
        $this->assertSame(1, TahunAjaran::where('aktif', true)->count(), 'Hanya satu tahun ajaran aktif.');
        $this->assertSame(2, (int) TahunAjaran::where('aktif', true)->value('semester'));
    }

    public function test_invalid_values_in_new_types_are_reported(): void
    {
        $service = new ExcelService;

        $hasil = $service->preview('ruang', $this->simpanXlsx([['nama', 'kapasitas'], ['Lab', 'banyak']]));
        $this->assertSame(1, $hasil['error_count']);

        $hasil = $service->preview('tahun_ajaran', $this->simpanXlsx([['nama_tahun_ajaran', 'semester', 'aktif'], ['2026/2027', '3', 'mungkin']]));
        $this->assertSame(1, $hasil['error_count']);
        $this->assertCount(2, $hasil['errors']);
    }

    public function test_only_superadmin_may_import_tahun_ajaran(): void
    {
        $operator = User::factory()->role('operator')->create();

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/v1/import/excel/tahun_ajaran/commit', ['items' => [['nama_tahun_ajaran' => '2027/2028', 'semester' => '1', 'aktif' => 'ya']]])
            ->assertForbidden();
    }
}
