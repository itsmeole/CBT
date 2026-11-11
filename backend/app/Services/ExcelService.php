<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Ruang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelService
{
    /** Jenis data master yang dapat diunduh, diimpor, dan diunduh sebagai template. */
    public const JENIS = ['siswa', 'guru', 'kelas', 'guru_mapel', 'mapel', 'ruang', 'tahun_ajaran'];

    /**
     * Kolom dokumen per jenis. Urutan sama untuk template dan hasil unduh data, sehingga hasil unduh
     * dapat diedit lalu diimpor kembali (baris yang sudah ada diperbarui, bukan digandakan).
     *
     * @return list<string>
     */
    public function kolom(string $jenis): array
    {
        return match ($jenis) {
            'siswa' => ['username', 'nama', 'password', 'nis', 'nama_kelas', 'nama_tahun_ajaran', 'semester', 'jk'],
            'guru' => ['username', 'nama', 'password', 'nip'],
            'kelas' => ['nama_kelas', 'tingkat', 'nama_tahun_ajaran', 'semester'],
            'guru_mapel' => ['username_guru', 'kode_mapel', 'nama_kelas', 'nama_tahun_ajaran', 'semester'],
            'mapel' => ['kode', 'nama', 'tingkat'],
            'ruang' => ['nama', 'kapasitas'],
            'tahun_ajaran' => ['nama_tahun_ajaran', 'semester', 'aktif'],
            default => [],
        };
    }

    /**
     * Download template Excel resmi.
     */
    public function generateTemplate(string $jenis): string
    {
        $this->validateJenis($jenis);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        $headers = $this->kolom($jenis);
        $example = match ($jenis) {
            'siswa' => ['2026001', 'Ahmad Rizki', 'password123', '2026001', '7A', '2026/2027', '1', 'L'],
            'guru' => ['guru_budi', 'Budi Santoso, S.Pd.', 'password123', '198501012010011001'],
            'kelas' => ['7A', '7', '2026/2027', '1'],
            'guru_mapel' => ['guru_budi', 'MTK', '7A', '2026/2027', '1'],
            'mapel' => ['MTK7', 'Matematika Kelas 7', '7'],
            'ruang' => ['Lab Komputer 1', '36'],
            'tahun_ajaran' => ['2026/2027', '1', 'ya'],
        };

        $sheet->fromArray([$headers, $example], null, 'A1');

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $petunjuk = $this->petunjuk($jenis);
        if ($petunjuk !== []) {
            $lembar = $spreadsheet->createSheet();
            $lembar->setTitle('Petunjuk');
            foreach ($petunjuk as $i => $baris) {
                $lembar->setCellValue('A'.($i + 1), $baris);
            }
            $lembar->getColumnDimension('A')->setWidth(110);
            $spreadsheet->setActiveSheetIndex(0);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Ekspor master data tanpa mengekspor kredensial akun.
     */
    public function export(string $jenis): string
    {
        $this->validateJenis($jenis);

        $headers = $this->kolom($jenis);
        // Password tersimpan sebagai hash satu arah sehingga tidak dapat diekspor; kolomnya dikosongkan.
        // Saat diimpor kembali, password kosong berarti password akun yang sudah ada tidak diubah.
        $rows = match ($jenis) {
            'siswa' => Siswa::query()->with(['user', 'kelas.tahunAjaran'])->orderBy('id')->get()
                ->map(fn (Siswa $siswa): array => [
                    $siswa->user?->username,
                    $siswa->user?->name,
                    '',
                    $siswa->nis,
                    $siswa->kelas?->nama,
                    $siswa->kelas?->tahunAjaran?->nama,
                    $siswa->kelas?->tahunAjaran?->semester,
                    $siswa->jk,
                ])->all(),
            'guru' => Guru::query()->with('user')->orderBy('id')->get()
                ->map(fn (Guru $guru): array => [
                    $guru->user?->username,
                    $guru->user?->name,
                    '',
                    $guru->nip,
                ])->all(),
            'kelas' => Kelas::query()->with('tahunAjaran')->orderBy('id')->get()
                ->map(fn (Kelas $kelas): array => [
                    $kelas->nama,
                    $kelas->tingkat,
                    $kelas->tahunAjaran?->nama,
                    $kelas->tahunAjaran?->semester,
                ])->all(),
            'guru_mapel' => GuruMapel::query()->with(['guru.user', 'mapel', 'kelas.tahunAjaran'])->orderBy('id')->get()
                ->map(fn (GuruMapel $guruMapel): array => [
                    $guruMapel->guru?->user?->username,
                    $guruMapel->mapel?->kode,
                    $guruMapel->kelas?->nama,
                    $guruMapel->tahunAjaran?->nama,
                    $guruMapel->tahunAjaran?->semester,
                ])->all(),
            'mapel' => Mapel::query()->orderBy('id')->get()
                ->map(fn (Mapel $mapel): array => [$mapel->kode, $mapel->nama, $mapel->tingkat])->all(),
            'ruang' => Ruang::query()->orderBy('id')->get()
                ->map(fn (Ruang $ruang): array => [$ruang->nama, $ruang->kapasitas])->all(),
            'tahun_ajaran' => TahunAjaran::query()->orderBy('id')->get()
                ->map(fn (TahunAjaran $ta): array => [$ta->nama, $ta->semester, $ta->aktif ? 'ya' : 'tidak'])->all(),
        };

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $this->writeExportRows($sheet, $headers, $rows);

        $tempPath = tempnam(sys_get_temp_dir(), 'export_');
        if ($tempPath === false) {
            throw new Exception('File sementara ekspor tidak dapat dibuat.');
        }

        (new Xlsx($spreadsheet))->save($tempPath);

        return $tempPath;
    }

    /**
     * Preview dan validasi baris tanpa menyimpan ke DB.
     */
    public function preview(string $jenis, UploadedFile $file): array
    {
        $this->validateJenis($jenis);

        $spreadsheet = IOFactory::load($file->getRealPath());
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, false);
        $headers = array_map('trim', array_map('strtolower', (array) array_shift($rows)));
        $headersWajib = $this->headersWajib($jenis);
        $missingHeaders = array_values(array_diff($headersWajib, $headers));
        $duplicateHeaders = array_values(array_unique(array_diff_assoc($headers, array_unique($headers))));
        if ($missingHeaders !== [] || $duplicateHeaders !== []) {
            $errors = [];
            foreach ($missingHeaders as $header) {
                $errors[] = ['row' => 1, 'column' => $header, 'message' => "Kolom {$header} wajib tersedia."];
            }
            foreach ($duplicateHeaders as $header) {
                $errors[] = ['row' => 1, 'column' => $header, 'message' => "Kolom {$header} tidak boleh duplikat."];
            }

            return ['total_rows' => 0, 'valid_count' => 0, 'error_count' => 1, 'preview' => [], 'errors' => $errors];
        }

        if ($rows === []) {
            return [
                'total_rows' => 0,
                'valid_count' => 0,
                'error_count' => 1,
                'preview' => [],
                'errors' => [['row' => 1, 'column' => 'all', 'message' => 'File Excel kosong atau hanya berisi header.']],
            ];
        }

        $errors = [];
        $previewData = [];
        $rowNumbersWithErrors = [];
        $totalRows = 0;
        $seenRows = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // 1-based, setelah header
            if (empty(array_filter($row, fn ($val) => ! is_null($val) && trim((string) $val) !== ''))) {
                continue; // skip baris kosong
            }

            $totalRows++;
            $mapped = [];
            foreach ($headers as $colIdx => $header) {
                $mapped[$header] = isset($row[$colIdx]) ? trim((string) $row[$colIdx]) : '';
            }

            $rowErrors = array_merge(
                $this->validateRow($jenis, $mapped, $rowNumber),
                $this->validateDuplikatBaris($jenis, $mapped, $rowNumber, $seenRows)
            );
            if (! empty($rowErrors)) {
                $errors = array_merge($errors, $rowErrors);
                $rowNumbersWithErrors[] = $rowNumber;
            } else {
                $previewData[] = $mapped;
            }
        }

        return [
            'total_rows' => $totalRows,
            'valid_count' => count($previewData),
            'error_count' => count(array_unique($rowNumbersWithErrors)),
            'preview' => array_slice($previewData, 0, 10), // cuplikan 10 baris
            'errors' => $errors,
        ];
    }

    /**
     * Commit impor dalam satu database transaction.
     */
    public function commit(string $jenis, array $items): int
    {
        $this->validateJenis($jenis);
        $errors = [];
        $seenRows = [];
        foreach ($items as $index => $row) {
            if (! is_array($row)) {
                $errors[] = 'Baris '.($index + 2).': format data tidak valid.';

                continue;
            }

            $rowErrors = array_merge(
                $this->validateRow($jenis, $row, $index + 2),
                $this->validateDuplikatBaris($jenis, $row, $index + 2, $seenRows)
            );
            foreach ($rowErrors as $error) {
                $errors[] = 'Baris '.$error['row'].', kolom '.$error['column'].': '.$error['message'];
            }
        }

        if ($errors !== []) {
            throw new Exception('Data impor tidak valid. '.implode(' ', $errors));
        }

        return DB::transaction(function () use ($jenis, $items) {
            $count = 0;
            foreach ($items as $row) {
                switch ($jenis) {
                    case 'siswa':
                        $tahunAjaran = TahunAjaran::where('nama', $row['nama_tahun_ajaran'])
                            ->where('semester', (int) $row['semester'])
                            ->first();
                        $kelas = $tahunAjaran
                            ? Kelas::where('nama', $row['nama_kelas'])->where('tahun_ajaran_id', $tahunAjaran->id)->first()
                            : null;
                        if (! $kelas) {
                            throw new Exception("Kelas {$row['nama_kelas']} pada tahun ajaran tersebut tidak ditemukan.");
                        }
                        $user = $this->simpanAkun($row, 'siswa');
                        $dataSiswa = ['nis' => $row['nis'], 'kelas_id' => $kelas->id];
                        if (($row['jk'] ?? '') !== '') {
                            $dataSiswa['jk'] = strtoupper($row['jk']);
                        }
                        Siswa::updateOrCreate(['user_id' => $user->id], $dataSiswa);
                        $count++;
                        break;

                    case 'guru':
                        $user = $this->simpanAkun($row, 'guru');
                        Guru::updateOrCreate(
                            ['user_id' => $user->id],
                            ['nip' => ($row['nip'] ?? '') !== '' ? $row['nip'] : null]
                        );
                        $count++;
                        break;

                    case 'kelas':
                        $ta = TahunAjaran::firstOrCreate(
                            ['nama' => $row['nama_tahun_ajaran'], 'semester' => (int) $row['semester']],
                            ['aktif' => true]
                        );
                        Kelas::updateOrCreate(
                            ['nama' => $row['nama_kelas'], 'tahun_ajaran_id' => $ta->id],
                            ['tingkat' => $row['tingkat']]
                        );
                        $count++;
                        break;

                    case 'guru_mapel':
                        $user = User::where('username', $row['username_guru'])->where('role', 'guru')->first();
                        if (! $user || ! $user->guru) {
                            throw new Exception("Guru dengan username {$row['username_guru']} tidak ditemukan.");
                        }
                        $mapel = Mapel::where('kode', $row['kode_mapel'])->first();
                        if (! $mapel) {
                            throw new Exception("Mata pelajaran dengan kode {$row['kode_mapel']} tidak ditemukan.");
                        }
                        $ta = TahunAjaran::where('nama', $row['nama_tahun_ajaran'])->where('semester', (int) $row['semester'])->first();
                        if (! $ta) {
                            throw new Exception("Tahun ajaran {$row['nama_tahun_ajaran']} semester {$row['semester']} tidak ditemukan.");
                        }
                        $kelas = Kelas::where('nama', $row['nama_kelas'])->where('tahun_ajaran_id', $ta->id)->first();
                        if (! $kelas) {
                            throw new Exception("Kelas {$row['nama_kelas']} pada tahun ajaran tersebut tidak ditemukan.");
                        }

                        GuruMapel::updateOrCreate([
                            'guru_id' => $user->guru->id,
                            'mapel_id' => $mapel->id,
                            'kelas_id' => $kelas->id,
                            'tahun_ajaran_id' => $ta->id,
                        ]);
                        $count++;
                        break;

                    case 'mapel':
                        Mapel::updateOrCreate(['kode' => $row['kode']], ['nama' => $row['nama'], 'tingkat' => ($row['tingkat'] ?? '') !== '' ? (string) $row['tingkat'] : null]);
                        $count++;
                        break;

                    case 'ruang':
                        Ruang::updateOrCreate(['nama' => $row['nama']], ['kapasitas' => (int) $row['kapasitas']]);
                        $count++;
                        break;

                    case 'tahun_ajaran':
                        $ta = TahunAjaran::firstOrNew(['nama' => $row['nama_tahun_ajaran'], 'semester' => (int) $row['semester']]);
                        if (($row['aktif'] ?? '') !== '') {
                            $ta->aktif = $this->boolDariTeks($row['aktif']);
                        }
                        $ta->save();
                        if ($ta->aktif) {
                            TahunAjaran::where('id', '!=', $ta->id)->update(['aktif' => false]);
                        }
                        $count++;
                        break;
                }
            }

            return $count;
        });
    }

    private function validateRow(string $jenis, array $row, int $rowNumber): array
    {
        $errs = [];
        switch ($jenis) {
            case 'siswa':
                if (empty($row['username'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'username', 'message' => 'Username wajib diisi.'];
                }
                if (empty($row['nama'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama siswa wajib diisi.'];
                }
                $errs = array_merge($errs, $this->validasiPassword('siswa', $row, $rowNumber));
                if (($row['jk'] ?? '') !== '' && ! in_array(strtoupper($row['jk']), ['L', 'P'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'jk', 'message' => 'Jenis kelamin harus L atau P.'];
                }
                if (empty($row['nis'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nis', 'message' => 'NIS wajib diisi.'];
                }
                if (empty($row['nama_kelas'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_kelas', 'message' => 'Nama kelas wajib diisi.'];
                }
                if (empty($row['nama_tahun_ajaran'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran wajib diisi.'];
                }
                if (! in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'semester', 'message' => 'Semester harus 1 atau 2.'];
                }
                if (! empty($row['username'])) {
                    $user = User::where('username', $row['username'])->first();
                    if ($user && $user->role !== 'siswa') {
                        $errs[] = ['row' => $rowNumber, 'column' => 'username', 'message' => 'Username sudah digunakan oleh role lain.'];
                    }
                }
                if (! empty($row['nis'])) {
                    $siswa = Siswa::where('nis', $row['nis'])->with('user')->first();
                    if ($siswa && $siswa->user?->username !== ($row['username'] ?? null)) {
                        $errs[] = ['row' => $rowNumber, 'column' => 'nis', 'message' => 'NIS sudah digunakan siswa lain.'];
                    }
                }
                $ta = TahunAjaran::where('nama', $row['nama_tahun_ajaran'] ?? '')
                    ->where('semester', (int) ($row['semester'] ?? 0))
                    ->first();
                if ($ta && ! empty($row['nama_kelas']) && ! Kelas::where('nama', $row['nama_kelas'])->where('tahun_ajaran_id', $ta->id)->exists()) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_kelas', 'message' => 'Kelas pada tahun ajaran tersebut tidak ditemukan.'];
                } elseif (! $ta && ! empty($row['nama_tahun_ajaran']) && in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran dan semester tidak ditemukan.'];
                }
                break;

            case 'guru':
                if (empty($row['username'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'username', 'message' => 'Username wajib diisi.'];
                }
                if (empty($row['nama'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama guru wajib diisi.'];
                }
                $errs = array_merge($errs, $this->validasiPassword('guru', $row, $rowNumber));
                if (! empty($row['username'])) {
                    $user = User::where('username', $row['username'])->first();
                    if ($user && $user->role !== 'guru') {
                        $errs[] = ['row' => $rowNumber, 'column' => 'username', 'message' => 'Username sudah digunakan oleh role lain.'];
                    }
                }
                if (! empty($row['nip']) && Guru::where('nip', $row['nip'])->whereHas('user', fn ($query) => $query->where('username', '!=', $row['username'] ?? ''))->exists()) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nip', 'message' => 'NIP sudah digunakan guru lain.'];
                }
                break;

            case 'kelas':
                if (empty($row['nama_kelas'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_kelas', 'message' => 'Nama kelas wajib diisi.'];
                }
                if (empty($row['tingkat'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'tingkat', 'message' => 'Tingkat kelas wajib diisi.'];
                }
                if (empty($row['nama_tahun_ajaran'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran wajib diisi.'];
                }
                if (! in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'semester', 'message' => 'Semester harus 1 atau 2.'];
                }
                break;

            case 'mapel':
                if (empty($row['kode'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'kode', 'message' => 'Kode mapel wajib diisi.'];
                } elseif (strlen($row['kode']) > 30 || ! preg_match('/^[A-Za-z0-9._-]+$/', $row['kode'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'kode', 'message' => 'Kode mapel maksimal 30 karakter (huruf, angka, titik, garis bawah, strip).'];
                }
                if (empty($row['nama'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama mapel wajib diisi.'];
                } elseif (strlen($row['nama']) > 100) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama mapel maksimal 100 karakter.'];
                }
                if (($row['tingkat'] ?? '') !== '' && ! in_array($row['tingkat'], ['7', '8', '9'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'tingkat', 'message' => 'Tingkat harus 7, 8, atau 9 (kosongkan bila berlaku untuk semua tingkat).'];
                }
                break;

            case 'ruang':
                if (empty($row['nama'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama ruang wajib diisi.'];
                } elseif (strlen($row['nama']) > 50) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama', 'message' => 'Nama ruang maksimal 50 karakter.'];
                }
                if (! ctype_digit((string) ($row['kapasitas'] ?? '')) || (int) $row['kapasitas'] < 1 || (int) $row['kapasitas'] > 1000) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'kapasitas', 'message' => 'Kapasitas harus bilangan bulat 1 sampai 1000.'];
                }
                break;

            case 'tahun_ajaran':
                if (empty($row['nama_tahun_ajaran'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran wajib diisi.'];
                } elseif (strlen($row['nama_tahun_ajaran']) > 50) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran maksimal 50 karakter.'];
                }
                if (! in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'semester', 'message' => 'Semester harus 1 atau 2.'];
                }
                if (($row['aktif'] ?? '') !== '' && ! in_array(strtolower($row['aktif']), ['ya', 'tidak', '1', '0', 'true', 'false'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'aktif', 'message' => 'Kolom aktif harus ya atau tidak.'];
                }
                break;

            case 'guru_mapel':
                if (empty($row['username_guru'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'username_guru', 'message' => 'Username guru wajib diisi.'];
                }
                if (empty($row['kode_mapel'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'kode_mapel', 'message' => 'Kode mapel wajib diisi.'];
                }
                if (empty($row['nama_kelas'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_kelas', 'message' => 'Nama kelas wajib diisi.'];
                }
                if (empty($row['nama_tahun_ajaran'])) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran wajib diisi.'];
                }
                if (! in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'semester', 'message' => 'Semester harus 1 atau 2.'];
                }
                if (! empty($row['username_guru']) && ! User::where('username', $row['username_guru'])->where('role', 'guru')->whereHas('guru')->exists()) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'username_guru', 'message' => 'Guru tidak ditemukan.'];
                }
                if (! empty($row['kode_mapel']) && ! Mapel::where('kode', $row['kode_mapel'])->exists()) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'kode_mapel', 'message' => 'Mata pelajaran tidak ditemukan.'];
                }
                $ta = TahunAjaran::where('nama', $row['nama_tahun_ajaran'] ?? '')
                    ->where('semester', (int) ($row['semester'] ?? 0))
                    ->first();
                if (! $ta && ! empty($row['nama_tahun_ajaran']) && in_array($row['semester'] ?? '', ['1', '2'], true)) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_tahun_ajaran', 'message' => 'Tahun ajaran dan semester tidak ditemukan.'];
                } elseif ($ta && ! empty($row['nama_kelas']) && ! Kelas::where('nama', $row['nama_kelas'])->where('tahun_ajaran_id', $ta->id)->exists()) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'nama_kelas', 'message' => 'Kelas pada tahun ajaran tersebut tidak ditemukan.'];
                }
                break;
        }

        return $errs;
    }

    private function headersWajib(string $jenis): array
    {
        // Kolom opsional (jk pada siswa) boleh tidak ada pada dokumen lama.
        return array_values(array_diff($this->kolom($jenis), ['jk', 'tingkat']));
    }

    private function validateDuplikatBaris(string $jenis, array $row, int $rowNumber, array &$seen): array
    {
        $identifiers = match ($jenis) {
            'siswa' => ['username' => ['username'], 'nis' => ['nis']],
            'guru' => ['username' => ['username'], 'nip' => ['nip']],
            'kelas' => ['kelas' => ['nama_kelas', 'nama_tahun_ajaran', 'semester']],
            'guru_mapel' => ['guru_mapel' => ['username_guru', 'kode_mapel', 'nama_kelas', 'nama_tahun_ajaran', 'semester']],
            'mapel' => ['kode' => ['kode']],
            'ruang' => ['nama' => ['nama']],
            'tahun_ajaran' => ['tahun_ajaran' => ['nama_tahun_ajaran', 'semester']],
            default => [],
        };
        $errors = [];

        foreach ($identifiers as $column => $fields) {
            $values = array_map(fn ($field) => trim((string) ($row[$field] ?? '')), $fields);
            if (in_array('', $values, true)) {
                continue;
            }

            $key = $jenis.':'.$column.':'.implode('|', $values);
            if (isset($seen[$key])) {
                $errors[] = [
                    'row' => $rowNumber,
                    'column' => $column,
                    'message' => 'Nilai duplikat pada file (pertama kali di baris '.$seen[$key].').',
                ];
            } else {
                $seen[$key] = $rowNumber;
            }
        }

        return $errors;
    }

    /**
     * Password wajib (minimal 6 karakter) untuk akun baru. Untuk akun yang sudah ada boleh kosong
     * (password tidak diubah); bila diisi tetap minimal 6 karakter.
     *
     * @return list<array{row: int, column: string, message: string}>
     */
    private function validasiPassword(string $role, array $row, int $rowNumber): array
    {
        $password = (string) ($row['password'] ?? '');
        $sudahAda = ! empty($row['username']) && User::where('username', $row['username'])->where('role', $role)->exists();

        if ($password === '' && $sudahAda) {
            return [];
        }
        if ($password === '' || strlen($password) < 6) {
            return [['row' => $rowNumber, 'column' => 'password', 'message' => $sudahAda
                ? 'Password minimal 6 karakter (kosongkan bila tidak ingin mengubah).'
                : 'Password wajib diisi minimal 6 karakter untuk akun baru.']];
        }

        return [];
    }

    /**
     * Buat atau perbarui akun berdasarkan username. Password dan status aktif akun yang sudah ada
     * tidak diubah kecuali password diisi.
     */
    private function simpanAkun(array $row, string $role): User
    {
        $atribut = ['name' => $row['nama'], 'role' => $role];
        if (($row['password'] ?? '') !== '') {
            $atribut['password'] = Hash::make($row['password']);
        }

        $user = User::where('username', $row['username'])->first();
        if ($user) {
            $user->update($atribut);

            return $user;
        }

        return User::create($atribut + ['username' => $row['username'], 'aktif' => true]);
    }

    private function boolDariTeks(string $nilai): bool
    {
        return in_array(strtolower(trim($nilai)), ['ya', '1', 'true'], true);
    }

    /**
     * Catatan singkat pada lembar "Petunjuk" di template.
     *
     * @return list<string>
     */
    private function petunjuk(string $jenis): array
    {
        $umum = [
            'Isi data mulai baris 2 pada lembar pertama. Baris contoh boleh dihapus.',
            'Dokumen hasil "Unduh Data" memakai kolom yang sama dengan template ini. Ubah kolom yang perlu lalu impor kembali: baris yang sudah ada akan diperbarui, bukan digandakan.',
        ];

        return match ($jenis) {
            'siswa' => [...$umum,
                'Data dicocokkan berdasarkan kolom username. NIS tidak boleh sama dengan siswa lain.',
                'Kolom password: wajib untuk akun baru. Untuk akun yang sudah ada, kosongkan bila password tidak ingin diubah (password lama tidak dapat diunduh karena disimpan terenkripsi).',
                'Kolom jk (L atau P) boleh dikosongkan.',
            ],
            'guru' => [...$umum,
                'Data dicocokkan berdasarkan kolom username.',
                'Kolom password: wajib untuk akun baru. Untuk akun yang sudah ada, kosongkan bila password tidak ingin diubah (password lama tidak dapat diunduh karena disimpan terenkripsi).',
            ],
            'tahun_ajaran' => [...$umum, 'Kolom aktif: ya atau tidak. Hanya satu tahun ajaran yang aktif; mengisi ya menonaktifkan yang lain.'],
            'mapel' => [...$umum, 'Data dicocokkan berdasarkan kolom kode.', 'Kolom tingkat (7, 8, atau 9) boleh dikosongkan bila mapel berlaku untuk semua tingkat.'],
            'ruang' => [...$umum, 'Data dicocokkan berdasarkan kolom nama.'],
            default => $umum,
        };
    }

    private function validateJenis(string $jenis): void
    {
        // FR-BE-14: Proteksi ketat! Bank soal dilarang diimpor/diekspor lewat Excel.
        if (in_array(strtolower($jenis), ['soal', 'bank_soal', 'bank-soal', 'paket_soal'])) {
            throw new Exception('Bank soal tidak boleh diimpor/diekspor lewat Excel. Gunakan format Word (.docx).');
        }

        if (! in_array($jenis, self::JENIS, true)) {
            throw new Exception("Jenis data master '{$jenis}' tidak didukung.");
        }
    }

    private function writeExportRows(Worksheet $sheet, array $headers, array $rows): void
    {
        foreach ($headers as $index => $header) {
            $sheet->setCellValueExplicit(
                Coordinate::stringFromColumnIndex($index + 1).'1',
                $header,
                DataType::TYPE_STRING,
            );
        }

        foreach ($rows as $rowIndex => $row) {
            foreach ($row as $columnIndex => $value) {
                $sheet->setCellValueExplicit(
                    Coordinate::stringFromColumnIndex($columnIndex + 1).($rowIndex + 2),
                    (string) ($value ?? ''),
                    DataType::TYPE_STRING,
                );
            }
        }
    }
}
