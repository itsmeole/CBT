<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\OpsiSoal;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;

class WordSoalService
{
    /**
     * Buat file template Word (.docx) resmi dengan penanda baku 5 tipe soal.
     */
    public function generateTemplate(): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        $section->addTitle('TEMPLATE SOAL CBT', 1);
        $section->addText('Petunjuk: Jangan ubah struktur penanda kurung siku pembuka dan penutup soal. Tipe soal: PG, PG_KOMPLEKS, BENAR_SALAH, MENJODOHKAN, ESSAY.');
        $section->addTextBreak();

        // 1. PG
        $section->addText("[SOAL]");
        $section->addText("TIPE: PG");
        $section->addText("TEKS: Ibu kota negara Indonesia saat ini adalah ...");
        $section->addText("A. Bandung");
        $section->addText("B. Jakarta");
        $section->addText("C. Surabaya");
        $section->addText("D. Medan");
        $section->addText("KUNCI: B");
        $section->addText("[/SOAL]");
        $section->addTextBreak();

        // 2. PG Kompleks
        $section->addText("[SOAL]");
        $section->addText("TIPE: PG_KOMPLEKS");
        $section->addText("TEKS: Manakah di bawah ini yang merupakan bilangan prima?");
        $section->addText("A. 2");
        $section->addText("B. 4");
        $section->addText("C. 5");
        $section->addText("D. 9");
        $section->addText("KUNCI: A, C");
        $section->addText("[/SOAL]");
        $section->addTextBreak();

        // 3. Benar / Salah
        $section->addText("[SOAL]");
        $section->addText("TIPE: BENAR_SALAH");
        $section->addText("TEKS: Tentukan apakah pernyataan berikut Benar atau Salah.");
        $section->addText("1. Air mendidih pada suhu 100 derajat Celcius pada tekanan 1 atm. | B");
        $section->addText("2. Matahari mengelilingi planet bumi. | S");
        $section->addText("[/SOAL]");
        $section->addTextBreak();

        // 4. Menjodohkan
        $section->addText("[SOAL]");
        $section->addText("TIPE: MENJODOHKAN");
        $section->addText("TEKS: Jodohkan nama negara dengan nama ibu kotanya.");
        $section->addText("KIRI:");
        $section->addText("1. Jepang");
        $section->addText("2. Prancis");
        $section->addText("KANAN:");
        $section->addText("a. Paris");
        $section->addText("b. Tokyo");
        $section->addText("c. Roma");
        $section->addText("KUNCI: 1-b, 2-a");
        $section->addText("[/SOAL]");
        $section->addTextBreak();

        // 5. Essay
        $section->addText("[SOAL]");
        $section->addText("TIPE: ESSAY");
        $section->addText("TEKS: Jelaskan proses fotosintesis pada tumbuhan secara singkat.");
        $section->addText("KUNCI: Tumbuhan menyerap air dan CO2 dengan bantuan cahaya matahari untuk menghasilkan glukosa dan oksigen.");
        $section->addText("RUBRIK: Skor maksimal 5 jika menyebutkan klorofil, air, CO2, dan oksigen.");
        $section->addText("[/SOAL]");

        $tempPath = tempnam(sys_get_temp_dir(), 'word_tpl_') . '.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Preview dan validasi isi dokumen Word sebelum disimpan.
     */
    public function preview(UploadedFile $file): array
    {
        $rawText = $this->extractTextFromDocx($file->getRealPath());

        // Cari blok [SOAL] ... [/SOAL]
        preg_match_all('/\[SOAL\](.*?)\[\/SOAL\]/is', $rawText, $matches);

        if (empty($matches[1])) {
            return [
                'total_soal' => 0,
                'valid_count' => 0,
                'error_count' => 1,
                'items' => [],
                'errors' => [['soal_index' => 0, 'message' => 'Tidak ditemukan format penanda [SOAL] ... [/SOAL] di dalam dokumen.']],
            ];
        }

        $items = [];
        $errors = [];

        foreach ($matches[1] as $idx => $block) {
            $soalNum = $idx + 1;
            $parsed = $this->parseSoalBlock(trim($block), $soalNum);

            if (!empty($parsed['errors'])) {
                foreach ($parsed['errors'] as $errMsg) {
                    $errors[] = ['soal_index' => $soalNum, 'message' => $errMsg];
                }
            } else {
                $items[] = $parsed['data'];
            }
        }

        return [
            'total_soal' => count($matches[1]),
            'valid_count' => count($items),
            'error_count' => count($errors),
            'items' => $items,
            'errors' => $errors,
        ];
    }

    /**
     * Commit impor soal ke database dalam satu database transaction.
     */
    public function commit(int $guruId, int $mapelId, array $items): int
    {
        return DB::transaction(function () use ($guruId, $mapelId, $items) {
            $count = 0;
            foreach ($items as $item) {
                $sanitizedTeks = HtmlSanitizerService::sanitize($item['teks']);

                $bankSoal = BankSoal::create([
                    'guru_id' => $guruId,
                    'mapel_id' => $mapelId,
                    'tipe' => $item['tipe'],
                    'konten' => $sanitizedTeks,
                    'kunci' => $item['kunci'] ?? null,
                    'rubrik' => $item['rubrik'] ?? null,
                    'versi' => 1,
                ]);

                if (!empty($item['opsi'])) {
                    foreach ($item['opsi'] as $idx => $opsi) {
                        OpsiSoal::create([
                            'bank_soal_id' => $bankSoal->id,
                            'konten' => HtmlSanitizerService::sanitize($opsi['konten']),
                            'sisi' => $opsi['sisi'] ?? null,
                            'urutan' => $idx + 1,
                        ]);
                    }
                }
                $count++;
            }
            return $count;
        });
    }

    /**
     * Ekspor daftar soal ke file .docx dengan format template yang sama (round-trip).
     */
    public function exportToWord(iterable $soalList): string
    {
        $phpWord = new PhpWord();
        $section = $phpWord->addSection();

        foreach ($soalList as $soal) {
            $tipeUpper = strtoupper($soal->tipe);
            $section->addText("[SOAL]");
            $section->addText("TIPE: {$tipeUpper}");
            $section->addText("TEKS: " . strip_tags($soal->konten));

            switch ($soal->tipe) {
                case 'pg':
                case 'pg_kompleks':
                    foreach ($soal->opsiSoal as $opsi) {
                        $huruf = chr(64 + (int) $opsi->urutan);
                        $section->addText("{$huruf}. " . strip_tags($opsi->konten));
                    }
                    if ($soal->tipe === 'pg') {
                        $section->addText("KUNCI: " . (is_string($soal->kunci) ? $soal->kunci : ''));
                    } else {
                        $section->addText("KUNCI: " . (is_array($soal->kunci) ? implode(', ', $soal->kunci) : ''));
                    }
                    break;

                case 'benar_salah':
                    if (is_array($soal->kunci)) {
                        foreach ($soal->kunci as $pId => $status) {
                            $section->addText("{$pId}. Pernyataan {$pId} | {$status}");
                        }
                    }
                    break;

                case 'menjodohkan':
                    $section->addText("KIRI:");
                    foreach ($soal->opsiSoal->where('sisi', 'kiri') as $opsiKiri) {
                        $section->addText("{$opsiKiri->urutan}. " . strip_tags($opsiKiri->konten));
                    }
                    $section->addText("KANAN:");
                    foreach ($soal->opsiSoal->where('sisi', 'kanan') as $opsiKanan) {
                        $hurufKanan = chr(96 + (int) $opsiKanan->urutan);
                        $section->addText("{$hurufKanan}. " . strip_tags($opsiKanan->konten));
                    }
                    if (is_array($soal->kunci)) {
                        $pairs = [];
                        foreach ($soal->kunci as $k => $v) {
                            $pairs[] = "{$k}-{$v}";
                        }
                        $section->addText("KUNCI: " . implode(', ', $pairs));
                    }
                    break;

                case 'essay':
                    if ($soal->kunci) {
                        $section->addText("KUNCI: " . (is_string($soal->kunci) ? $soal->kunci : json_encode($soal->kunci)));
                    }
                    if ($soal->rubrik) {
                        $section->addText("RUBRIK: " . $soal->rubrik);
                    }
                    break;
            }

            $section->addText("[/SOAL]");
            $section->addTextBreak();
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_word_') . '.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Parsing blok teks satu soal ke bentuk terstruktur.
     */
    private function parseSoalBlock(string $block, int $soalNum): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", "", $block)))));
        $errors = [];

        $tipe = null;
        $teks = '';
        $kunci = null;
        $rubrik = null;
        $opsi = [];

        $readingTeks = false;
        $readingKiri = false;
        $readingKanan = false;

        foreach ($lines as $line) {
            if (preg_match('/^TIPE:\s*([A-Z_]+)/i', $line, $m)) {
                $tipe = strtolower(trim($m[1]));
                $readingTeks = false;
                continue;
            }

            if (preg_match('/^TEKS:\s*(.*)/i', $line, $m)) {
                $teks = trim($m[1]);
                $readingTeks = true;
                continue;
            }

            if (preg_match('/^KUNCI:\s*(.*)/i', $line, $m)) {
                $rawKunci = trim($m[1]);
                $readingTeks = false;
                $readingKiri = false;
                $readingKanan = false;

                if ($tipe === 'pg') {
                    $kunci = strtoupper($rawKunci);
                } elseif ($tipe === 'pg_kompleks') {
                    $kunci = array_map('trim', explode(',', strtoupper($rawKunci)));
                } elseif ($tipe === 'menjodohkan') {
                    // 1-b, 2-a
                    $pairs = array_map('trim', explode(',', $rawKunci));
                    $kunci = [];
                    foreach ($pairs as $p) {
                        $parts = explode('-', $p);
                        if (count($parts) === 2) {
                            $kunci[trim($parts[0])] = trim($parts[1]);
                        }
                    }
                } else {
                    $kunci = $rawKunci;
                }
                continue;
            }

            if (preg_match('/^RUBRIK:\s*(.*)/i', $line, $m)) {
                $rubrik = trim($m[1]);
                $readingTeks = false;
                continue;
            }

            if (strtoupper($line) === 'KIRI:') {
                $readingTeks = false;
                $readingKiri = true;
                $readingKanan = false;
                continue;
            }

            if (strtoupper($line) === 'KANAN:') {
                $readingTeks = false;
                $readingKiri = false;
                $readingKanan = true;
                continue;
            }

            // Opsi PG / PG_KOMPLEKS: A. Teks
            if (preg_match('/^([A-Z])[\.\)]\s*(.*)/i', $line, $m)) {
                $opsi[] = [
                    'label' => strtoupper($m[1]),
                    'konten' => trim($m[2]),
                    'sisi' => null,
                ];
                $readingTeks = false;
                continue;
            }

            // Benar / Salah: 1. Pernyataan | B
            if ($tipe === 'benar_salah' && str_contains($line, '|')) {
                $parts = explode('|', $line);
                $pernyataan = trim($parts[0]);
                $jawabanBS = strtoupper(trim($parts[1]));
                $idxBS = count($opsi) + 1;

                // Cek nomor di awal pernyataan
                if (preg_match('/^([0-9]+)[\.\)]\s*(.*)/', $pernyataan, $pm)) {
                    $idxBS = (int) $pm[1];
                    $pernyataan = trim($pm[2]);
                }

                $opsi[] = [
                    'label' => (string) $idxBS,
                    'konten' => $pernyataan,
                    'sisi' => null,
                ];
                if (!is_array($kunci)) {
                    $kunci = [];
                }
                $kunci[(string) $idxBS] = in_array($jawabanBS, ['B', 'BENAR', 'TRUE', '1']) ? 'B' : 'S';
                continue;
            }

            // Menjodohkan
            if ($readingKiri && preg_match('/^([0-9]+)[\.\)]\s*(.*)/', $line, $m)) {
                $opsi[] = [
                    'label' => trim($m[1]),
                    'konten' => trim($m[2]),
                    'sisi' => 'kiri',
                ];
                continue;
            }

            if ($readingKanan && preg_match('/^([a-z])[\.\)]\s*(.*)/i', $line, $m)) {
                $opsi[] = [
                    'label' => strtolower(trim($m[1])),
                    'konten' => trim($m[2]),
                    'sisi' => 'kanan',
                ];
                continue;
            }

            if ($readingTeks) {
                $teks .= "\n" . $line;
            }
        }

        // Validasi
        if (!in_array($tipe, ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'])) {
            $errors[] = "Soal #{$soalNum}: Tipe soal '{$tipe}' tidak valid.";
        }
        if (empty(trim($teks))) {
            $errors[] = "Soal #{$soalNum}: Teks soal tidak boleh kosong.";
        }
        if (in_array($tipe, ['pg', 'pg_kompleks']) && count($opsi) < 2) {
            $errors[] = "Soal #{$soalNum}: Minimal harus memiliki 2 pilihan jawaban.";
        }
        if ($tipe === 'pg' && empty($kunci)) {
            $errors[] = "Soal #{$soalNum}: Kunci jawaban PG tidak boleh kosong.";
        }
        if ($tipe === 'pg_kompleks' && (!is_array($kunci) || count($kunci) < 2)) {
            $errors[] = "Soal #{$soalNum}: Kunci jawaban PG Kompleks harus memiliki minimal 2 jawaban benar.";
        }

        return [
            'errors' => $errors,
            'data' => [
                'tipe' => $tipe,
                'teks' => trim($teks),
                'kunci' => $kunci,
                'rubrik' => $rubrik,
                'opsi' => $opsi,
            ],
        ];
    }

    /**
     * Ekstraksi teks dari .docx file via ZipArchive membaca word/document.xml.
     */
    private function extractTextFromDocx(string $filePath): string
    {
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Gagal membuka file Word (.docx).");
        }

        $xmlIndex = $zip->locateName('word/document.xml');
        if ($xmlIndex === false) {
            $zip->close();
            throw new Exception("File Word tidak valid atau tidak memuat word/document.xml.");
        }

        $xmlData = $zip->getFromIndex($xmlIndex);
        $zip->close();

        // Ganti w:p dengan newline
        $xmlData = str_replace(['</w:p>', '<w:br/>'], "\n", $xmlData);
        $text = strip_tags($xmlData);

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
