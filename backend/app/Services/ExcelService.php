<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\Kelas;
use App\Models\Mapel;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class ExcelService
{
    /**
     * Download template Excel resmi.
     */
    public function generateTemplate(string $jenis): string
    {
        $this->validateJenis($jenis);

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        switch ($jenis) {
            case 'siswa':
                $headers = ['username', 'nama', 'password', 'nis', 'nama_kelas', 'nama_tahun_ajaran', 'semester'];
                $example = ['2026001', 'Ahmad Rizki', 'password123', '2026001', '7A', '2026/2027', '1'];
                break;

            case 'guru':
                $headers = ['username', 'nama', 'password', 'nip'];
                $example = ['guru_budi', 'Budi Santoso, S.Pd.', 'password123', '198501012010011001'];
                break;

            case 'kelas':
                $headers = ['nama_kelas', 'tingkat', 'nama_tahun_ajaran', 'semester'];
                $example = ['7A', '7', '2026/2027', '1'];
                break;

            case 'guru_mapel':
                $headers = ['username_guru', 'kode_mapel', 'nama_kelas', 'nama_tahun_ajaran', 'semester'];
                $example = ['guru_budi', 'MTK', '7A', '2026/2027', '1'];
                break;

            default:
                throw new Exception('Jenis data master tidak valid.');
        }

        $sheet->fromArray([$headers, $example], null, 'A1');

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'tpl_').'.xlsx';
        $writer = new Xlsx($spreadsheet);
        $writer->save($tempPath);

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
                        $user = User::updateOrCreate(
                            ['username' => $row['username']],
                            [
                                'name' => $row['nama'],
                                'password' => Hash::make($row['password']),
                                'role' => 'siswa',
                                'aktif' => true,
                            ]
                        );
                        Siswa::updateOrCreate(
                            ['user_id' => $user->id],
                            ['nis' => $row['nis'], 'kelas_id' => $kelas->id]
                        );
                        $count++;
                        break;

                    case 'guru':
                        $user = User::updateOrCreate(
                            ['username' => $row['username']],
                            [
                                'name' => $row['nama'],
                                'password' => Hash::make($row['password']),
                                'role' => 'guru',
                                'aktif' => true,
                            ]
                        );
                        Guru::updateOrCreate(
                            ['user_id' => $user->id],
                            ['nip' => $row['nip'] ?: null]
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
                if (strlen($row['password'] ?? '') < 6) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'password', 'message' => 'Password wajib diisi minimal 6 karakter.'];
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
                if (strlen($row['password'] ?? '') < 6) {
                    $errs[] = ['row' => $rowNumber, 'column' => 'password', 'message' => 'Password wajib diisi minimal 6 karakter.'];
                }
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
        return match ($jenis) {
            'siswa' => ['username', 'nama', 'password', 'nis', 'nama_kelas', 'nama_tahun_ajaran', 'semester'],
            'guru' => ['username', 'nama', 'password', 'nip'],
            'kelas' => ['nama_kelas', 'tingkat', 'nama_tahun_ajaran', 'semester'],
            'guru_mapel' => ['username_guru', 'kode_mapel', 'nama_kelas', 'nama_tahun_ajaran', 'semester'],
            default => [],
        };
    }

    private function validateDuplikatBaris(string $jenis, array $row, int $rowNumber, array &$seen): array
    {
        $identifiers = match ($jenis) {
            'siswa' => ['username' => ['username'], 'nis' => ['nis']],
            'guru' => ['username' => ['username'], 'nip' => ['nip']],
            'kelas' => ['kelas' => ['nama_kelas', 'nama_tahun_ajaran', 'semester']],
            'guru_mapel' => ['guru_mapel' => ['username_guru', 'kode_mapel', 'nama_kelas', 'nama_tahun_ajaran', 'semester']],
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

    private function validateJenis(string $jenis): void
    {
        // FR-BE-14: Proteksi ketat! Bank soal dilarang diimpor/diekspor lewat Excel.
        if (in_array(strtolower($jenis), ['soal', 'bank_soal', 'bank-soal', 'paket_soal'])) {
            throw new Exception('Bank soal tidak boleh diimpor/diekspor lewat Excel. Gunakan format Word (.docx).');
        }

        if (! in_array($jenis, ['siswa', 'guru', 'kelas', 'guru_mapel'])) {
            throw new Exception("Jenis data master '{$jenis}' tidak didukung.");
        }
    }
}
