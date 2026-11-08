<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\OpsiSoal;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

class WordSoalService
{
    /**
     * Buat file template Word (.docx) resmi dengan dukungan Bahasa Arab, Gambar, dan Opsi Tanpa A/B/C.
     */
    public function generateTemplate(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection(['orientation' => 'landscape', 'marginLeft' => 720, 'marginRight' => 720, 'marginTop' => 720, 'marginBottom' => 720]);

        // Gambar sampel dibuat via GD agar file template self-contained
        $tempImgPath = null;
        if (function_exists('imagecreatetruecolor')) {
            $im = imagecreatetruecolor(240, 120);
            $bg = imagecolorallocate($im, 240, 245, 255);
            imagefill($im, 0, 0, $bg);
            $border = imagecolorallocate($im, 37, 99, 235);
            imagerectangle($im, 2, 2, 237, 117, $border);
            $textColor = imagecolorallocate($im, 30, 41, 59);
            imagestring($im, 5, 25, 50, 'Contoh Gambar CBT', $textColor);
            $tempImgPath = tempnam(sys_get_temp_dir(), 'tpl_img_').'.png';
            imagepng($im, $tempImgPath);
            imagedestroy($im);
        }

        $section->addText('TEMPLATE SOAL CBT', ['bold' => true, 'size' => 18, 'color' => '1E40AF']);
        $section->addText('Satu dokumen untuk dua keperluan: pemeriksaan oleh panitia dan impor langsung ke sistem tanpa diubah.');
        $section->addTextBreak();
        $section->addText('Cara mengisi:', ['bold' => true]);
        $section->addText('1. Sistem HANYA membaca teks yang diapit tag pembuka (kurung siku SOAL) dan tag penutup (kurung siku /SOAL) pada kolom Soal. Kolom No, Elemen, Tujuan Pembelajaran, Indikator Soal, Level Kognitif, dan Catatan Pemeriksa hanya untuk administrasi: diabaikan sistem, boleh diubah, ditambah, atau dihapus.');
        $section->addText('2. Satu baris tabel = satu soal. Soal bertanda juga boleh ditulis di luar tabel. Tag tidak peka huruf besar/kecil. Contoh penulisan ada di kolom Soal di bawah.');
        $section->addText('3. Di antara penanda, tulis satu baris per bagian: TIPE:, TEKS:, lalu isi sesuai tipe seperti contoh pada kolom Soal.');
        $section->addText('4. Tipe yang didukung: PG, PG_KOMPLEKS, BENAR_SALAH, MENJODOHKAN, ESSAY.');
        $section->addText('5. Pilihan jawaban: [OPSI] untuk pilihan biasa dan [OPSI_BENAR] untuk kunci. Jangan mengetik huruf A, B, C di depan pilihan karena opsi diacak untuk siswa.');
        $section->addText('6. Benar/Salah: tulis "nomor. pernyataan | B" atau "| S". Menjodohkan: daftar KIRI: (angka), KANAN: (huruf), lalu KUNCI: 1-b, 2-a. Essay: KUNCI: dan RUBRIK:.');
        $section->addText('7. Gambar dan rumus: gunakan Insert > Picture dan Insert > Equation langsung di dalam kolom Soal. Teks Arab berharakat didukung.');
        $section->addTextBreak();

        // Tabel dimulai di halaman baru agar baris judul tidak tertinggal sendirian di halaman petunjuk.
        $section->addPageBreak();
        $tabel = $section->addTable(self::GAYA_TABEL);
        $tabel->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach (self::KOLOM_KISI as [$judul, $lebar]) {
            $tabel->addCell($lebar, ['bgColor' => 'E2E8F0', 'valign' => 'center'])->addText($judul, ['bold' => true], ['alignment' => 'center']);
        }

        $arab = ['name' => 'Traditional Arabic', 'size' => 13];
        $contoh = [
            [
                'admin' => ['1', 'Bahasa Arab', 'Peserta didik mampu memahami nama-nama Nabi dan Rasul.', 'Disajikan pertanyaan, peserta didik dapat menentukan penutup para Nabi.', 'C1'],
                'baris' => [
                    '[SOAL]',
                    'TIPE: PG',
                    ['runs' => [['TEKS: ', []], ['مَنْ هُوَ خَاتَمُ الأَنْبِيَاءِ وَالمُرْسَلِينَ عَلَيْهِمُ السَّلَامُ؟', $arab], [' (Siapakah penutup para Nabi dan Rasul?)', []]]],
                    ['runs' => [['[OPSI] ', []], ['نَبِيُّ اللهِ مُوْسَى عَلَيْهِ السَّلَامُ', $arab]]],
                    ['runs' => [['[OPSI] ', []], ['نَبِيُّ اللهِ عِيْسَى عَلَيْهِ السَّلَامُ', $arab]]],
                    ['runs' => [['[OPSI_BENAR] ', []], ['نَبِيُّنَا مُحَمَّدٌ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ', $arab]]],
                    ['runs' => [['[OPSI] ', []], ['نَبِيُّ اللهِ إِبْرَاهِيمُ عَلَيْهِ السَّلَامُ', $arab]]],
                    '[/SOAL]',
                ],
            ],
            [
                'admin' => ['2', 'Geometri', 'Peserta didik mampu menjelaskan sifat bangun datar.', 'Disajikan gambar persegi panjang, peserta didik dapat menentukan sifat-sifatnya.', 'C2'],
                'baris' => [
                    '[SOAL]',
                    'TIPE: PG_KOMPLEKS',
                    'TEKS: Perhatikan gambar bangun datar di bawah ini:',
                    ['gambar' => $tempImgPath],
                    'Manakah pernyataan yang BENAR mengenai sifat-sifat bangun persegi panjang di atas?',
                    '[OPSI_BENAR] Memiliki 4 sisi dengan sisi-sisi yang berhadapan sejajar dan sama panjang',
                    '[OPSI] Memiliki 3 buah sudut lancip',
                    '[OPSI_BENAR] Memiliki 4 buah sudut siku-siku berukuran 90 derajat',
                    '[OPSI] Memiliki keliling lingkaran sempurna',
                    '[/SOAL]',
                ],
            ],
            [
                'admin' => ['3', 'Fisika Dasar', 'Peserta didik mampu menyebutkan fakta ilmiah sederhana.', 'Disajikan dua pernyataan, peserta didik dapat menentukan benar atau salahnya.', 'C1'],
                'baris' => [
                    '[SOAL]',
                    'TIPE: BENAR_SALAH',
                    'TEKS: Tentukan apakah pernyataan ilmiah berikut Benar (B) atau Salah (S).',
                    '1. Air mendidih pada suhu 100 derajat Celcius pada tekanan atmosfer normal 1 atm. | B',
                    '2. Matahari mengelilingi planet bumi setiap 24 jam sekali. | S',
                    '[/SOAL]',
                ],
            ],
            [
                'admin' => ['4', 'Geografi', 'Peserta didik mampu mengenali ibu kota negara.', 'Disajikan daftar negara, peserta didik dapat menjodohkannya dengan ibu kota.', 'C1'],
                'baris' => [
                    '[SOAL]',
                    'TIPE: MENJODOHKAN',
                    'TEKS: Jodohkan nama negara di kolom kiri dengan nama ibu kotanya di kolom kanan.',
                    'KIRI:',
                    '1. Jepang',
                    '2. Prancis',
                    'KANAN:',
                    'a. Paris',
                    'b. Tokyo',
                    'c. Roma',
                    'KUNCI: 1-b, 2-a',
                    '[/SOAL]',
                ],
            ],
            [
                'admin' => ['5', 'Biologi', 'Peserta didik mampu menjelaskan proses fotosintesis.', 'Disajikan pertanyaan, peserta didik dapat menguraikan proses fotosintesis.', 'C2'],
                'baris' => [
                    '[SOAL]',
                    'TIPE: ESSAY',
                    'TEKS: Jelaskan proses fotosintesis pada tumbuhan hijau secara singkat.',
                    'KUNCI: Tumbuhan menyerap air dan CO2 dengan bantuan klorofil dan cahaya matahari untuk menghasilkan glukosa dan oksigen.',
                    'RUBRIK: Skor maksimal 5 jika menyebutkan klorofil, air, CO2, dan oksigen.',
                    '[/SOAL]',
                ],
            ],
        ];

        foreach ($contoh as $baris) {
            $this->tulisBarisKisi($tabel, $baris['admin'], $baris['baris']);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'word_tpl_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        if ($tempImgPath && file_exists($tempImgPath)) {
            @unlink($tempImgPath);
        }

        return $tempPath;
    }

    /**
     * Kolom tabel kisi-kisi: [judul, lebar dalam twips]. Hanya kolom Soal yang dibaca sistem.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const KOLOM_KISI = [
        ['No', 600],
        ['Elemen', 1400],
        ['Tujuan Pembelajaran', 2300],
        ['Indikator Soal', 2200],
        ['Level Kognitif', 900],
        ['Soal', 6200],
        ['Catatan Pemeriksa', 1500],
    ];

    private const GAYA_TABEL = ['borderSize' => 6, 'borderColor' => '64748B', 'cellMargin' => 60];

    /**
     * @param  list<string>  $admin  isi kolom No, Elemen, Tujuan Pembelajaran, Indikator Soal, Level Kognitif
     * @param  list<string|array<string, mixed>>  $barisSoal  baris kolom Soal: teks, ['teks' => ..., 'font' => ...], ['runs' => [[teks, font], ...]], atau ['gambar' => path]
     */
    private function tulisBarisKisi(Table $tabel, array $admin, array $barisSoal): void
    {
        $tabel->addRow();
        foreach (self::KOLOM_KISI as $i => [, $lebar]) {
            $sel = $tabel->addCell($lebar);
            if ($i < 5) {
                $sel->addText($admin[$i] ?? '', [], $i === 0 || $i === 4 ? ['alignment' => 'center'] : []);
            } elseif ($i === 5) {
                $this->isiSelSoal($sel, $barisSoal);
            } else {
                $sel->addText('');
            }
        }
    }

    /**
     * @param  list<string|array<string, mixed>>  $barisSoal
     */
    private function isiSelSoal(Cell $sel, array $barisSoal): void
    {
        foreach ($barisSoal as $baris) {
            if (is_array($baris) && array_key_exists('gambar', $baris)) {
                if ($baris['gambar'] && is_file($baris['gambar'])) {
                    $sel->addImage($baris['gambar'], ['width' => 160, 'height' => 80]);
                }

                continue;
            }
            if (is_array($baris) && isset($baris['runs'])) {
                $paragraf = $sel->addTextRun();
                foreach ($baris['runs'] as [$teks, $font]) {
                    $paragraf->addText($teks, $font);
                }

                continue;
            }
            if (is_array($baris)) {
                $sel->addText($baris['teks'], $baris['font'] ?? []);

                continue;
            }
            $sel->addText($baris);
        }
    }

    /**
     * Preview dan validasi isi dokumen Word sebelum disimpan.
     */
    public function preview(UploadedFile $file): array
    {
        $rawText = $this->extractTextFromDocx($file->getRealPath());

        // Hanya teks di antara [SOAL] dan [/SOAL] yang dibaca; teks lain (kisi-kisi, catatan, dll.) diabaikan.
        $blokSoal = $this->cariBlokSoal($rawText);

        if ($blokSoal === []) {
            return [
                'total_soal' => 0,
                'valid_count' => 0,
                'error_count' => 1,
                'items' => [],
                'errors' => [['soal_index' => 0, 'message' => 'Tidak ditemukan soal di dalam dokumen. Tandai setiap soal dengan [SOAL] di awal dan [/SOAL] di akhir (lihat template resmi).']],
            ];
        }

        $items = [];
        $errors = [];

        foreach ($blokSoal as $idx => $blok) {
            $soalNum = $idx + 1;
            if (isset($blok['error'])) {
                $errors[] = ['soal_index' => $soalNum, 'message' => "Soal #{$soalNum}: ".$blok['error']];

                continue;
            }
            $parsed = $this->parseSoalBlock(trim($blok['isi']), $soalNum);
            $parsed['errors'] = array_merge($parsed['errors'], $this->validasiSoal($parsed['data'], $soalNum));

            if (! empty($parsed['errors'])) {
                foreach ($parsed['errors'] as $errMsg) {
                    $errors[] = ['soal_index' => $soalNum, 'message' => $errMsg];
                }
            } else {
                $items[] = $parsed['data'];
            }
        }

        return [
            'total_soal' => count($blokSoal),
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
        foreach ($items as $index => $item) {
            $errors = $this->validasiSoal($item, $index + 1);
            if ($errors !== []) {
                throw new Exception(implode(' ', $errors));
            }
        }

        return DB::transaction(function () use ($guruId, $mapelId, $items) {
            $count = 0;
            foreach ($items as $item) {
                $rawTeks = $item['konten'] ?? $item['teks'] ?? '';
                $rawTeks = str_replace("\r", '', (string) $rawTeks);
                if (! str_contains($rawTeks, '<p>') && ! str_contains($rawTeks, '<br')) {
                    $rawTeks = nl2br($rawTeks);
                }
                $sanitizedTeks = HtmlSanitizerService::sanitize($rawTeks);

                $bankSoal = BankSoal::create([
                    'guru_id' => $guruId,
                    'mapel_id' => $mapelId,
                    'tipe' => $item['tipe'],
                    'konten' => $sanitizedTeks,
                    'kunci' => $item['kunci'] ?? null,
                    'rubrik' => $item['rubrik'] ?? null,
                    'versi' => 1,
                ]);

                if (! empty($item['opsi'])) {
                    $urutanPerSisi = [];
                    foreach ($item['opsi'] as $idx => $opsi) {
                        $sisi = $opsi['sisi'] ?? null;
                        $urutan = $sisi
                            ? $this->urutanLabel((string) $opsi['label'])
                            : ($opsi['label'] ? $this->urutanLabel((string) $opsi['label']) : $idx + 1);
                        if ($sisi) {
                            $urutanPerSisi[$sisi] = max($urutanPerSisi[$sisi] ?? 0, $urutan);
                        }

                        $rawOpsiKonten = (string) ($opsi['konten'] ?? '');
                        if (! str_contains($rawOpsiKonten, '<p>') && ! str_contains($rawOpsiKonten, '<br')) {
                            $rawOpsiKonten = nl2br($rawOpsiKonten);
                        }

                        OpsiSoal::create([
                            'bank_soal_id' => $bankSoal->id,
                            'konten' => HtmlSanitizerService::sanitize($rawOpsiKonten),
                            'sisi' => $sisi,
                            'urutan' => $urutan,
                        ]);
                    }
                }
                $count++;
            }

            return $count;
        });
    }

    /**
     * Samakan variasi penulisan penanda yang wajar: spasi di dalam kurung siku, huruf kecil,
     * "OPSI BENAR" tanpa garis bawah, dan spasi tak terputus (NBSP) hasil salin-tempel.
     */
    private function normalisasiPenanda(string $teks): string
    {
        $teks = str_replace("\xC2\xA0", ' ', $teks);
        $teks = (string) preg_replace('/\[\s*(\/?)\s*SOAL\s*\]/iu', '[${1}SOAL]', $teks);
        $teks = (string) preg_replace('/\[\s*OPSI[\s_]*BENAR\s*\]/iu', '[OPSI_BENAR]', $teks);

        return (string) preg_replace('/\[\s*OPSI\s*\]/iu', '[OPSI]', $teks);
    }

    /**
     * Samakan penulisan nama bagian di awal baris: "tipe :", "Teks:", "KUNCI :" menjadi "TIPE:", "TEKS:", "KUNCI:".
     */
    private function normalisasiBaris(string $baris): string
    {
        $baris = (string) preg_replace_callback(
            '/^(TIPE|TEKS|KUNCI|RUBRIK|KIRI|KANAN)\s*:[ \t]*/iu',
            fn (array $cocok): string => strtoupper($cocok[1]).': ',
            $baris
        );

        return trim($baris);
    }

    /**
     * Ambil blok soal dari teks dokumen. Blok tidak pernah melintasi penanda [SOAL] berikutnya, sehingga
     * [/SOAL] yang terlupa pada satu soal tidak menelan soal sesudahnya beserta kolom administrasinya.
     *
     * @return list<array{isi: string}|array{error: string}>
     */
    private function cariBlokSoal(string $teks): array
    {
        $bagian = preg_split('/(\[SOAL\]|\[\/SOAL\])/iu', $this->normalisasiPenanda($teks), -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $hasil = [];
        $buka = false;
        $isi = '';

        foreach ($bagian as $potong) {
            $tanda = strtoupper($potong);
            if ($tanda === '[SOAL]') {
                if ($buka) {
                    $hasil[] = ['error' => 'penanda [/SOAL] penutup tidak ditemukan sebelum soal berikutnya dimulai.'];
                }
                $buka = true;
                $isi = '';
            } elseif ($tanda === '[/SOAL]') {
                if ($buka) {
                    $hasil[] = ['isi' => $isi];
                    $buka = false;
                }
            } elseif ($buka) {
                $isi .= $potong;
            }
        }
        if ($buka) {
            $hasil[] = ['error' => 'penanda [/SOAL] penutup tidak ditemukan sampai akhir dokumen.'];
        }

        return $hasil;
    }

    /**
     * Ekspor daftar soal ke file .docx dengan format baku non-ABC (round-trip).
     */
    public function exportToWord(iterable $soalList): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $section->addText('BANK SOAL CBT', ['bold' => true, 'size' => 16, 'color' => '1E40AF']);
        $section->addText('Hanya teks pada kolom Soal (yang diapit tag soal) yang dibaca sistem saat dokumen ini diimpor kembali. Kolom Catatan Pemeriksa boleh diisi panitia.');
        $section->addTextBreak();

        $tabel = $section->addTable(self::GAYA_TABEL);
        $tabel->addRow(null, ['tblHeader' => true, 'cantSplit' => true]);
        foreach ([['No', 700], ['Soal', 6500], ['Catatan Pemeriksa', 1800]] as [$judul, $lebar]) {
            $tabel->addCell($lebar, ['bgColor' => 'E2E8F0', 'valign' => 'center'])->addText($judul, ['bold' => true], ['alignment' => 'center']);
        }

        $nomor = 0;
        foreach ($soalList as $soal) {
            $nomor++;
            $tabel->addRow();
            $tabel->addCell(700)->addText((string) $nomor, [], ['alignment' => 'center']);
            $this->isiSelSoal($tabel->addCell(6500), $this->barisSoalUntukEkspor($soal));
            $tabel->addCell(1800)->addText('');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_word_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Baris bertanda untuk satu soal (format yang sama dengan template, sehingga dapat diimpor kembali).
     *
     * @return list<string>
     */
    private function barisSoalUntukEkspor(BankSoal $soal): array
    {
        $baris = ['[SOAL]', 'TIPE: '.strtoupper($soal->tipe)];
        $teks = preg_split('/\R/u', strip_tags((string) $soal->konten)) ?: [''];
        foreach ($teks as $i => $potong) {
            $baris[] = $i === 0 ? 'TEKS: '.$potong : $potong;
        }
        $opsi = $soal->opsiSoal->sortBy('urutan')->values();

        switch ($soal->tipe) {
            case 'pg':
            case 'pg_kompleks':
                foreach ($opsi as $item) {
                    $huruf = chr(64 + (int) $item->urutan);
                    $kunci = $soal->kunci;
                    $benar = $soal->tipe === 'pg'
                        ? strtoupper((string) $kunci) === $huruf
                        : (is_array($kunci) && in_array($huruf, array_map('strtoupper', $kunci), true));
                    $baris[] = ($benar ? '[OPSI_BENAR] ' : '[OPSI] ').strip_tags((string) $item->konten);
                }
                break;

            case 'benar_salah':
                foreach ($opsi as $item) {
                    $status = is_array($soal->kunci) ? ($soal->kunci[(string) $item->urutan] ?? '') : '';
                    $baris[] = "{$item->urutan}. ".strip_tags((string) $item->konten)." | {$status}";
                }
                break;

            case 'menjodohkan':
                $baris[] = 'KIRI:';
                foreach ($opsi->where('sisi', 'kiri') as $kiri) {
                    $baris[] = "{$kiri->urutan}. ".strip_tags((string) $kiri->konten);
                }
                $baris[] = 'KANAN:';
                foreach ($opsi->where('sisi', 'kanan')->values() as $i => $kanan) {
                    $baris[] = chr(97 + $i).'. '.strip_tags((string) $kanan->konten);
                }
                if (is_array($soal->kunci)) {
                    $pasangan = [];
                    foreach ($soal->kunci as $k => $v) {
                        $pasangan[] = "{$k}-{$v}";
                    }
                    $baris[] = 'KUNCI: '.implode(', ', $pasangan);
                }
                break;

            case 'essay':
                if ($soal->kunci) {
                    $baris[] = 'KUNCI: '.(is_string($soal->kunci) ? $soal->kunci : json_encode($soal->kunci));
                }
                if ($soal->rubrik) {
                    $baris[] = 'RUBRIK: '.$soal->rubrik;
                }
                break;
        }

        $baris[] = '[/SOAL]';

        return $baris;
    }

    /**
     * Parsing blok teks satu soal ke bentuk terstruktur.
     */
    private function parseSoalBlock(string $block, int $soalNum): array
    {
        $lines = array_values(array_filter(
            array_map(fn (string $baris): string => $this->normalisasiBaris(trim($baris)), explode("\n", str_replace("\r", '', $block))),
            fn (string $baris): bool => $baris !== ''
        ));
        $errors = [];

        $tipe = null;
        $teks = '';
        $kunci = null;
        $rubrik = null;
        $opsi = [];

        $readingTeks = false;
        $readingKiri = false;
        $readingKanan = false;
        $currentOpsiIdx = null;

        foreach ($lines as $line) {
            if (preg_match('/^TIPE:\s*([A-Z_]+)/iu', $line, $m)) {
                $tipe = strtolower(trim($m[1]));
                $readingTeks = false;
                $currentOpsiIdx = null;

                continue;
            }

            if (preg_match('/^TEKS:\s*(.*)/iu', $line, $m)) {
                $teks = trim($m[1]);
                $readingTeks = true;
                $currentOpsiIdx = null;

                continue;
            }

            if (preg_match('/^KUNCI:\s*(.*)/iu', $line, $m)) {
                $rawKunci = trim($m[1]);
                $readingTeks = false;
                $readingKiri = false;
                $readingKanan = false;
                $currentOpsiIdx = null;

                if ($tipe === 'pg') {
                    if (ctype_digit($rawKunci)) {
                        $kunci = chr(64 + (int) $rawKunci);
                    } elseif (preg_match('/^[A-Z]$/i', $rawKunci)) {
                        $kunci = strtoupper($rawKunci);
                    } else {
                        // Cocokkan jika kunci berupa teks konten pilihan
                        $matchedLabel = null;
                        foreach ($opsi as $op) {
                            if (strcasecmp((string) $op['konten'], $rawKunci) === 0) {
                                $matchedLabel = $op['label'];
                                break;
                            }
                        }
                        $kunci = $matchedLabel ?? strtoupper($rawKunci);
                    }
                } elseif ($tipe === 'pg_kompleks') {
                    $rawItems = array_map('trim', explode(',', $rawKunci));
                    $resolvedKeys = [];
                    foreach ($rawItems as $ri) {
                        if (ctype_digit($ri)) {
                            $resolvedKeys[] = chr(64 + (int) $ri);
                        } elseif (preg_match('/^[A-Z]$/i', $ri)) {
                            $resolvedKeys[] = strtoupper($ri);
                        } else {
                            foreach ($opsi as $op) {
                                if (strcasecmp((string) $op['konten'], $ri) === 0) {
                                    $resolvedKeys[] = $op['label'];
                                    break;
                                }
                            }
                        }
                    }
                    if (! empty($resolvedKeys)) {
                        $kunci = array_values(array_unique($resolvedKeys));
                    }
                } elseif ($tipe === 'menjodohkan') {
                    // 1-b, 2-a
                    $pairs = array_map('trim', explode(',', $rawKunci));
                    $kunci = [];
                    foreach ($pairs as $p) {
                        $parts = explode('-', $p);
                        if (count($parts) === 2) {
                            $kunci[trim($parts[0])] = strtolower(trim($parts[1]));
                        }
                    }
                } else {
                    $kunci = $rawKunci;
                }

                continue;
            }

            if (preg_match('/^RUBRIK:\s*(.*)/iu', $line, $m)) {
                $rubrik = trim($m[1]);
                $readingTeks = false;
                $currentOpsiIdx = null;

                continue;
            }

            if (strtoupper($line) === 'KIRI:') {
                $readingTeks = false;
                $readingKiri = true;
                $readingKanan = false;
                $currentOpsiIdx = null;

                continue;
            }

            if (strtoupper($line) === 'KANAN:') {
                $readingTeks = false;
                $readingKiri = false;
                $readingKanan = true;
                $currentOpsiIdx = null;

                continue;
            }

            // Opsi baku baru: [OPSI] atau [OPSI_BENAR] (Bebas dari A, B, C)
            if (preg_match('/^\[(OPSI_BENAR|OPSI)\]\s*(.*)/iu', $line, $m)) {
                $readingTeks = false;
                $isBenar = (strtoupper($m[1]) === 'OPSI_BENAR');
                $kontenOpsi = trim($m[2]);
                // Bersihkan awalan A. atau B) jika pengguna tidak sengaja mengetiknya
                $kontenOpsi = preg_replace('/^[A-Za-z][\.\)]\s*/u', '', $kontenOpsi);

                $huruf = chr(65 + count($opsi));
                $opsi[] = [
                    'label' => $huruf,
                    'konten' => $kontenOpsi,
                    'sisi' => null,
                ];
                $currentOpsiIdx = count($opsi) - 1;

                if ($isBenar) {
                    if ($tipe === 'pg') {
                        $kunci = $huruf;
                    } elseif ($tipe === 'pg_kompleks') {
                        if (! is_array($kunci)) {
                            $kunci = [];
                        }
                        if (! in_array($huruf, $kunci, true)) {
                            $kunci[] = $huruf;
                        }
                    }
                }

                continue;
            }

            // Opsi format butir simbol: - Opsi [BENAR] atau * Opsi
            if (in_array($tipe, ['pg', 'pg_kompleks'], true) && preg_match('/^[-*•]\s*(.*)/iu', $line, $m)) {
                $readingTeks = false;
                $rawContent = trim($m[1]);
                $isBenar = false;
                if (preg_match('/\[BENAR\]/i', $rawContent)) {
                    $isBenar = true;
                    $rawContent = trim(preg_replace('/\[BENAR\]/i', '', $rawContent));
                }
                $rawContent = preg_replace('/^[A-Za-z][\.\)]\s*/u', '', $rawContent);

                $huruf = chr(65 + count($opsi));
                $opsi[] = [
                    'label' => $huruf,
                    'konten' => $rawContent,
                    'sisi' => null,
                ];
                $currentOpsiIdx = count($opsi) - 1;

                if ($isBenar) {
                    if ($tipe === 'pg') {
                        $kunci = $huruf;
                    } elseif ($tipe === 'pg_kompleks') {
                        if (! is_array($kunci)) {
                            $kunci = [];
                        }
                        if (! in_array($huruf, $kunci, true)) {
                            $kunci[] = $huruf;
                        }
                    }
                }

                continue;
            }

            // Opsi legacy kompatibilitas: A. Teks (Huruf dihilangkan dari konten agar saat di-shuffle siswa tidak rancu)
            if (! $readingKiri && ! $readingKanan && preg_match('/^([A-Z])[\.\)]\s*(.*)/iu', $line, $m)) {
                $huruf = strtoupper($m[1]);
                $kontenOpsi = trim($m[2]);
                $opsi[] = [
                    'label' => $huruf,
                    'konten' => $kontenOpsi,
                    'sisi' => null,
                ];
                $readingTeks = false;
                $currentOpsiIdx = count($opsi) - 1;

                continue;
            }

            // Benar / Salah: 1. Pernyataan | B
            if ($tipe === 'benar_salah' && str_contains($line, '|')) {
                $currentOpsiIdx = null;
                $parts = explode('|', $line);
                if (count($parts) !== 2) {
                    $errors[] = "Soal #{$soalNum}: Format pernyataan benar/salah harus memakai satu pemisah |.";

                    continue;
                }

                $pernyataan = trim($parts[0]);
                $jawabanBS = strtoupper(trim($parts[1]));
                if (! in_array($jawabanBS, ['B', 'S', 'BENAR', 'SALAH', 'TRUE', 'FALSE', '1', '0'], true)) {
                    $errors[] = "Soal #{$soalNum}: Jawaban benar/salah harus B atau S.";

                    continue;
                }

                $idxBS = count($opsi) + 1;
                if (preg_match('/^([0-9]+)[\.\)]\s*(.*)/u', $pernyataan, $pm)) {
                    $idxBS = (int) $pm[1];
                    $pernyataan = trim($pm[2]);
                }

                $opsi[] = [
                    'label' => (string) $idxBS,
                    'konten' => $pernyataan,
                    'sisi' => null,
                ];
                if (! is_array($kunci)) {
                    $kunci = [];
                }
                $kunci[(string) $idxBS] = in_array($jawabanBS, ['B', 'BENAR', 'TRUE', '1'], true) ? 'B' : 'S';

                continue;
            }

            // Menjodohkan
            if ($readingKiri && preg_match('/^([0-9]+)[\.\)]\s*(.*)/u', $line, $m)) {
                $currentOpsiIdx = null;
                $opsi[] = [
                    'label' => trim($m[1]),
                    'konten' => trim($m[2]),
                    'sisi' => 'kiri',
                ];

                continue;
            }

            if ($readingKanan && preg_match('/^([a-z])[\.\)]\s*(.*)/iu', $line, $m)) {
                $currentOpsiIdx = null;
                $opsi[] = [
                    'label' => strtolower(trim($m[1])),
                    'konten' => trim($m[2]),
                    'sisi' => 'kanan',
                ];

                continue;
            }

            // Baris kelanjutan teks / gambar
            if ($readingTeks) {
                $teks .= "\n".$line;
            } elseif ($currentOpsiIdx !== null && isset($opsi[$currentOpsiIdx])) {
                $opsi[$currentOpsiIdx]['konten'] .= "\n".$line;
            }
        }

        return [
            'errors' => $errors,
            'data' => [
                'tipe' => $tipe,
                'teks' => trim($teks),
                'konten' => trim($teks),
                'kunci' => $kunci,
                'rubrik' => $rubrik,
                'opsi' => $opsi,
            ],
        ];
    }

    private function validasiSoal(array $soal, int $soalNum): array
    {
        $errors = [];
        $tipe = $soal['tipe'] ?? null;
        $opsi = is_array($soal['opsi'] ?? null) ? $soal['opsi'] : [];
        $kunci = $soal['kunci'] ?? null;
        $labelOpsi = array_map(fn ($item) => is_array($item) ? (string) ($item['label'] ?? '') : '', $opsi);

        if (! in_array($tipe, ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'], true)) {
            $errors[] = "Soal #{$soalNum}: Tipe soal tidak valid.";

            return $errors;
        }

        if (! is_string($soal['teks'] ?? null) || trim($soal['teks']) === '') {
            $errors[] = "Soal #{$soalNum}: Teks soal tidak boleh kosong.";
        }

        foreach ($opsi as $item) {
            if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '' || trim((string) ($item['konten'] ?? '')) === '') {
                $errors[] = "Soal #{$soalNum}: Setiap pilihan/pernyataan harus memiliki label dan teks.";
                break;
            }
        }

        if (in_array($tipe, ['pg', 'pg_kompleks'], true)) {
            $maksOpsi = $tipe === 'pg' ? 5 : 6;
            $expectedLabels = $labelOpsi === [] ? [] : range('A', chr(64 + count($labelOpsi)));
            if (count($labelOpsi) < 2 || count($labelOpsi) > $maksOpsi || $labelOpsi !== $expectedLabels) {
                $errors[] = "Soal #{$soalNum}: Jumlah/label pilihan tidak valid untuk tipe {$tipe}.";
            }
        }

        if ($tipe === 'pg') {
            if (! is_string($kunci) || ! in_array($kunci, $labelOpsi, true)) {
                $errors[] = "Soal #{$soalNum}: Kunci PG harus menunjuk tepat satu pilihan yang tersedia.";
            }
        } elseif ($tipe === 'pg_kompleks') {
            $kunci = is_array($kunci) ? array_map(fn ($item) => strtoupper(trim((string) $item)), $kunci) : [];
            if (count($kunci) < 2 || count(array_unique($kunci)) !== count($kunci) || array_diff($kunci, $labelOpsi) !== []) {
                $errors[] = "Soal #{$soalNum}: Kunci PG Kompleks harus memuat minimal dua pilihan tersedia tanpa duplikat.";
            }
        } elseif ($tipe === 'benar_salah') {
            $kunci = is_array($kunci) ? $kunci : [];
            $expectedLabels = $labelOpsi === [] ? [] : array_map('strval', range(1, count($labelOpsi)));
            $actualKeys = array_map('strval', array_keys($kunci));
            sort($actualKeys);
            sort($expectedLabels);
            $values = array_map(fn ($value) => strtoupper((string) $value), array_values($kunci));
            $labelPernyataan = $labelOpsi === [] ? [] : array_map('strval', range(1, count($labelOpsi)));
            if ($labelOpsi !== $labelPernyataan || $kunci === [] || $actualKeys !== $expectedLabels || array_diff($values, ['B', 'S']) !== []) {
                $errors[] = "Soal #{$soalNum}: Setiap pernyataan benar/salah wajib memiliki kunci B atau S.";
            }
        } elseif ($tipe === 'menjodohkan') {
            $kiri = array_values(array_filter($opsi, fn ($item) => ($item['sisi'] ?? null) === 'kiri'));
            $kanan = array_values(array_filter($opsi, fn ($item) => ($item['sisi'] ?? null) === 'kanan'));
            $labelKiri = array_map(fn ($item) => (string) $item['label'], $kiri);
            $labelKanan = array_map(fn ($item) => (string) $item['label'], $kanan);
            $kunci = is_array($kunci) ? $kunci : [];

            if ($kiri === [] || count($kanan) < count($kiri) || count(array_unique($labelKiri)) !== count($labelKiri)
                || count(array_unique($labelKanan)) !== count($labelKanan) || $kunci === [] || count($kunci) !== count($kiri)) {
                $errors[] = "Soal #{$soalNum}: Premis, respons, dan kunci menjodohkan belum lengkap.";
            } elseif (array_diff(array_keys($kunci), $labelKiri) !== [] || array_diff(array_values($kunci), $labelKanan) !== []) {
                $errors[] = "Soal #{$soalNum}: Kunci menjodohkan merujuk label yang tidak tersedia.";
            }
        } elseif ($tipe === 'essay' && (! is_string($kunci) || trim($kunci) === '' || ! is_string($soal['rubrik'] ?? null) || trim($soal['rubrik']) === '')) {
            $errors[] = "Soal #{$soalNum}: Kunci dan rubrik essay wajib diisi.";
        }

        return $errors;
    }

    private function urutanLabel(string $label): int
    {
        if (ctype_digit($label)) {
            return (int) $label;
        }

        return ord(strtolower($label)) - 96;
    }

    /**
     * Ekstraksi teks & gambar dari .docx file via ZipArchive membaca word/document.xml dan word/media/*.
     */
    private function extractTextFromDocx(string $filePath): string
    {
        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new Exception('Gagal membuka file Word (.docx).');
        }

        $xmlIndex = $zip->locateName('word/document.xml');
        if ($xmlIndex === false) {
            $zip->close();
            throw new Exception('File Word tidak valid atau tidak memuat word/document.xml.');
        }

        // 1. Petakan relasi gambar dari word/_rels/document.xml.rels
        $relMap = [];
        $relsXml = $zip->getFromName('word/_rels/document.xml.rels');
        if ($relsXml) {
            $dom = new \DOMDocument;
            if (@$dom->loadXML($relsXml)) {
                foreach ($dom->getElementsByTagName('Relationship') as $rel) {
                    $type = (string) $rel->getAttribute('Type');
                    if (str_contains($type, '/image')) {
                        $id = $rel->getAttribute('Id');
                        $target = $rel->getAttribute('Target');

                        // Normalisasi path target di dalam zip
                        if (str_starts_with($target, '/')) {
                            $zipEntry = ltrim($target, '/');
                        } elseif (str_starts_with($target, 'media/')) {
                            $zipEntry = 'word/'.$target;
                        } elseif (str_starts_with($target, '../media/')) {
                            $zipEntry = 'word/'.substr($target, 3);
                        } else {
                            $zipEntry = 'word/'.ltrim($target, '/');
                        }

                        $imageData = $zip->getFromName($zipEntry);
                        if ($imageData !== false && strlen($imageData) > 0) {
                            $ext = strtolower(pathinfo($zipEntry, PATHINFO_EXTENSION));
                            if (! in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg'], true)) {
                                $ext = 'png';
                            }
                            $filename = 'word_img_'.substr(md5($imageData), 0, 16).'_'.time().'.'.$ext;

                            // Simpan berkas ke storage/app/public/soal dan public/storage/soal
                            $publicDir = public_path('storage'.DIRECTORY_SEPARATOR.'soal');
                            if (! is_dir($publicDir)) {
                                @mkdir($publicDir, 0755, true);
                            }
                            @file_put_contents($publicDir.DIRECTORY_SEPARATOR.$filename, $imageData);

                            $appPublicDir = storage_path('app'.DIRECTORY_SEPARATOR.'public'.DIRECTORY_SEPARATOR.'soal');
                            if (is_dir($appPublicDir) || @mkdir($appPublicDir, 0755, true)) {
                                @file_put_contents($appPublicDir.DIRECTORY_SEPARATOR.$filename, $imageData);
                            }

                            $relMap[$id] = '/storage/soal/'.$filename;
                        }
                    }
                }
            }
        }

        $xmlData = $zip->getFromIndex($xmlIndex);
        $zip->close();

        // 1b. Rumus Word (OMML) -> LaTeX berpembatas $...$ / $$...$$ agar dirender KaTeX (FR-BE-33)
        $xmlData = app(OmmlToLatexService::class)->gantiRumusDiXml($xmlData);

        // 2. Ganti elemen drawing & pict dengan tag <img>
        $xmlData = preg_replace_callback('/<w:(?:drawing|pict)[\s\S]*?<\/w:(?:drawing|pict)>/u', function ($match) use ($relMap) {
            if (preg_match('/(?:r:embed|r:id)=["\']([^"\']+)["\']/u', $match[0], $m)) {
                $rId = $m[1];
                if (isset($relMap[$rId])) {
                    return '<img src="'.htmlspecialchars($relMap[$rId], ENT_QUOTES, 'UTF-8').'" alt="Gambar Soal" />';
                }
            }

            return '';
        }, $xmlData);

        // 3. Konversi paragraf & baris baru. Batas sel tabel menjadi tab dan batas baris tabel menjadi baris baru,
        // sehingga isi sel yang berdampingan (kolom kisi-kisi, kolom Soal, catatan) tidak menempel satu sama lain.
        $xmlData = str_replace(['</w:p>', '<w:br/>', '<w:cr/>', '</w:tr>', '</w:tc>'], ["\n", "\n", "\n", "\n", "\t"], $xmlData);
        $text = strip_tags($xmlData, '<img>');

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
