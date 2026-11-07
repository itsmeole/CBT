<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\OpsiSoal;
use Exception;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use ZipArchive;

class WordSoalService
{
    /**
     * Baris mentah tabel soal hasil ekstraksi dokumen terakhir, menurut nomor penanda `[[SOALTABEL:n]]`.
     *
     * @var array<int, list<array{label: string, isi: string}>>
     */
    private array $tabelSoal = [];

    /**
     * Buat file template Word (.docx) resmi dengan dukungan Bahasa Arab, Gambar, dan Opsi Tanpa A/B/C.
     */
    public function generateTemplate(): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $tabel = new WordTabelSoal;

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
        $section->addText('Cara mengisi:', ['bold' => true]);
        $section->addText('1. Satu soal = satu tabel. Isi HANYA kolom kanan; jangan mengubah tulisan di kolom kiri.');
        $section->addText('2. Untuk soal berikutnya, salin-tempel (copy-paste) satu tabel contoh lalu ganti isinya. Ubah angka pada baris "SOAL NOMOR" bila perlu.');
        $section->addText('3. Baris opsi/pernyataan yang tidak dipakai cukup dikosongkan. Urutan huruf dan nomor disusun otomatis, jadi tidak perlu rapat.');
        $section->addText('4. Jangan mengetik huruf A, B, C di depan isi opsi: sistem mengacak opsi di layar siswa dan huruf akan menjadi salah.');
        $section->addText('5. Gambar dan rumus: gunakan Insert > Picture dan Insert > Equation langsung di dalam sel TEKS SOAL atau sel opsi.');
        $section->addText('6. Teks Arab (berharakat, RTL) didukung penuh.');
        $section->addTextBreak();

        $panduan = $section->addTable(['borderSize' => 6, 'borderColor' => '94A3B8', 'cellMargin' => 70]);
        $panduan->addRow();
        foreach (['JENIS SOAL', 'BARIS YANG DIISI', 'CARA MENGISI KUNCI'] as $judul) {
            $panduan->addCell(3000, ['bgColor' => 'E2E8F0'])->addText($judul, ['bold' => true]);
        }
        foreach ([
            ['PG', 'TEKS SOAL, OPSI A sampai E', 'Satu huruf, contoh: C'],
            ['PG_KOMPLEKS', 'TEKS SOAL, OPSI A sampai E', 'Beberapa huruf dipisah koma, contoh: A, C'],
            ['BENAR_SALAH', 'TEKS SOAL, PERNYATAAN dan JAWABAN', 'Tulis BENAR atau SALAH pada baris JAWABAN'],
            ['MENJODOHKAN', 'TEKS SOAL, KIRI (angka), KANAN (huruf)', 'Pasangan angka-huruf, contoh: 1-B, 2-A'],
            ['ESSAY', 'TEKS SOAL, KUNCI JAWABAN, RUBRIK PENILAIAN', 'Kunci dan rubrik berupa teks bebas'],
        ] as $baris) {
            $panduan->addRow();
            foreach ($baris as $isi) {
                $panduan->addCell(3000)->addText($isi);
            }
        }
        $section->addTextBreak();

        $tabel->tulisSoal($section, 1, [
            'tipe' => 'pg',
            'teks' => 'مَنْ هُوَ خَاتَمُ الأَنْبِيَاءِ وَالمُرْسَلِينَ عَلَيْهِمُ السَّلَامُ؟ (Siapakah penutup para Nabi dan Rasul?)',
            'opsi' => ['نَبِيُّ اللهِ مُوْسَى عَلَيْهِ السَّلَامُ', 'نَبِيُّ اللهِ عِيْسَى عَلَيْهِ السَّلَامُ', 'نَبِيُّنَا مُحَمَّدٌ صَلَّى اللهُ عَلَيْهِ وَسَلَّمَ', 'نَبِيُّ اللهِ إِبْرَاهِيمُ عَلَيْهِ السَّلَامُ', ''],
            'kunci' => 'C',
            'font' => ['name' => 'Traditional Arabic', 'size' => 13],
        ]);

        $tabel->tulisSoal($section, 2, [
            'tipe' => 'pg_kompleks',
            'teks' => "Perhatikan gambar bangun datar di bawah ini:\nManakah pernyataan yang BENAR mengenai sifat-sifat bangun persegi panjang di atas?",
            'gambar' => $tempImgPath,
            'opsi' => ['Memiliki 4 sisi dengan sisi-sisi yang berhadapan sejajar dan sama panjang', 'Memiliki 3 buah sudut lancip', 'Memiliki 4 buah sudut siku-siku berukuran 90 derajat', 'Memiliki keliling lingkaran sempurna', ''],
            'kunci' => 'A, C',
        ]);

        $tabel->tulisSoal($section, 3, [
            'tipe' => 'benar_salah',
            'teks' => 'Tentukan apakah pernyataan ilmiah berikut Benar atau Salah.',
            'pernyataan' => [
                ['teks' => 'Air mendidih pada suhu 100 derajat Celcius pada tekanan atmosfer normal 1 atm.', 'jawaban' => 'BENAR'],
                ['teks' => 'Matahari mengelilingi planet bumi setiap 24 jam sekali.', 'jawaban' => 'SALAH'],
                ['teks' => '', 'jawaban' => ''],
                ['teks' => '', 'jawaban' => ''],
            ],
        ]);

        $tabel->tulisSoal($section, 4, [
            'tipe' => 'menjodohkan',
            'teks' => 'Jodohkan nama negara di kolom kiri dengan nama ibu kotanya di kolom kanan.',
            'kiri' => ['Jepang', 'Prancis', '', ''],
            'kanan' => ['Paris', 'Tokyo', 'Roma', '', ''],
            'kunci_pasangan' => '1-B, 2-A',
        ]);

        $tabel->tulisSoal($section, 5, [
            'tipe' => 'essay',
            'teks' => 'Jelaskan proses fotosintesis pada tumbuhan hijau secara singkat.',
            'kunci' => 'Tumbuhan menyerap air dan CO2 dengan bantuan klorofil dan cahaya matahari untuk menghasilkan glukosa dan oksigen.',
            'rubrik' => 'Skor maksimal 5 jika menyebutkan klorofil, air, CO2, dan oksigen.',
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'word_tpl_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        if ($tempImgPath && file_exists($tempImgPath)) {
            @unlink($tempImgPath);
        }

        return $tempPath;
    }

    /**
     * Preview dan validasi isi dokumen Word sebelum disimpan.
     */
    public function preview(UploadedFile $file): array
    {
        $rawText = $this->extractTextFromDocx($file->getRealPath());

        // Soal berbentuk tabel (penanda [[SOALTABEL:n]]) atau blok teks [SOAL] ... [/SOAL], menurut urutan dokumen
        preg_match_all('/\[SOAL\](.*?)\[\/SOAL\]|\[\[SOALTABEL:(\d+)\]\]/isu', $rawText, $matches, PREG_SET_ORDER);

        if ($matches === []) {
            return [
                'total_soal' => 0,
                'valid_count' => 0,
                'error_count' => 1,
                'items' => [],
                'errors' => [['soal_index' => 0, 'message' => 'Tidak ditemukan soal di dalam dokumen. Gunakan template tabel resmi (satu soal satu tabel dengan baris TIPE SOAL) atau penanda [SOAL] ... [/SOAL].']],
            ];
        }

        $items = [];
        $errors = [];

        foreach ($matches as $idx => $cocok) {
            $soalNum = $idx + 1;
            $parsed = isset($cocok[2]) && $cocok[2] !== ''
                ? (new WordTabelSoal)->parse($this->tabelSoal[(int) $cocok[2]] ?? [], $soalNum)
                : $this->parseSoalBlock(trim($cocok[1]), $soalNum);
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
            'total_soal' => count($matches),
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
     * Ekspor daftar soal ke file .docx dengan format baku non-ABC (round-trip).
     */
    public function exportToWord(iterable $soalList): string
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $tabel = new WordTabelSoal;
        $nomor = 0;

        foreach ($soalList as $soal) {
            $nomor++;
            $data = ['tipe' => $soal->tipe, 'teks' => strip_tags((string) $soal->konten)];
            $opsi = $soal->opsiSoal->sortBy('urutan')->values();

            switch ($soal->tipe) {
                case 'pg':
                case 'pg_kompleks':
                    $benar = [];
                    foreach ($opsi as $i => $item) {
                        $hurufAsli = chr(64 + (int) $item->urutan);
                        $kunci = $soal->kunci;
                        $adalahBenar = $soal->tipe === 'pg'
                            ? strtoupper((string) $kunci) === $hurufAsli
                            : (is_array($kunci) && in_array($hurufAsli, array_map('strtoupper', $kunci), true));
                        if ($adalahBenar) {
                            $benar[] = chr(65 + $i);
                        }
                    }
                    $data['opsi'] = $opsi->map(fn ($item): string => strip_tags((string) $item->konten))->all();
                    $data['kunci'] = implode(', ', $benar);
                    break;

                case 'benar_salah':
                    $data['pernyataan'] = $opsi->map(function ($item) use ($soal): array {
                        $status = is_array($soal->kunci) ? strtoupper((string) ($soal->kunci[(string) $item->urutan] ?? '')) : '';

                        return [
                            'teks' => strip_tags((string) $item->konten),
                            'jawaban' => $status === 'B' ? 'BENAR' : ($status === 'S' ? 'SALAH' : $status),
                        ];
                    })->all();
                    break;

                case 'menjodohkan':
                    $data['kiri'] = $opsi->where('sisi', 'kiri')->map(fn ($item): string => strip_tags((string) $item->konten))->values()->all();
                    $data['kanan'] = $opsi->where('sisi', 'kanan')->map(fn ($item): string => strip_tags((string) $item->konten))->values()->all();
                    $pasangan = [];
                    if (is_array($soal->kunci)) {
                        foreach ($soal->kunci as $kiri => $kanan) {
                            $pasangan[] = $kiri.'-'.strtoupper((string) $kanan);
                        }
                    }
                    $data['kunci_pasangan'] = implode(', ', $pasangan);
                    break;

                case 'essay':
                    $data['kunci'] = $soal->kunci ? (is_string($soal->kunci) ? $soal->kunci : (string) json_encode($soal->kunci)) : '';
                    $data['rubrik'] = (string) $soal->rubrik;
                    break;
            }

            $tabel->tulisSoal($section, $nomor, $data);
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'export_word_').'.docx';
        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($tempPath);

        return $tempPath;
    }

    /**
     * Parsing blok teks satu soal ke bentuk terstruktur.
     */
    private function parseSoalBlock(string $block, int $soalNum): array
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", str_replace("\r", '', $block)))));
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

        // 2b. Tabel soal -> penanda [[SOALTABEL:n]] (isi tabel dibaca terstruktur oleh WordTabelSoal)
        $this->tabelSoal = [];
        $dom = new \DOMDocument;
        $sebelumnya = libxml_use_internal_errors(true);
        $berhasil = $dom->loadXML($xmlData, LIBXML_NONET | LIBXML_PARSEHUGE);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);
        if ($berhasil) {
            $this->tabelSoal = (new WordTabelSoal)->ekstrak($dom);
            if ($this->tabelSoal !== []) {
                $xmlData = (string) $dom->saveXML();
            }
        }

        // 3. Konversi paragraf & baris baru
        $xmlData = str_replace(['</w:p>', '<w:br/>', '<w:cr/>'], "\n", $xmlData);
        $text = strip_tags($xmlData, '<img>');

        return html_entity_decode($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
