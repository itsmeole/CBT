<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\Mapel;
use App\Models\OpsiSoal;
use App\Models\User;
use App\Services\WordSoalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

/**
 * Format dokumen soal: satu tabel kisi-kisi dengan kolom Soal bertanda. Sistem hanya membaca teks yang
 * diapit tag soal; kolom administrasi lain diabaikan sehingga dokumen yang sama dipakai panitia dan sistem.
 */
class WordTemplateTest extends TestCase
{
    use RefreshDatabase;

    private const MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private function xmlDokumen(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();

        return $xml;
    }

    /**
     * Dokumen mirip kisi-kisi guru: tabel 7 kolom dengan kolom Soal berisi tag.
     *
     * @param  list<array{admin?: list<string>, soal: list<string>}>  $baris
     */
    private function dokumenKisi(array $baris, ?callable $sebelumTabel = null): UploadedFile
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection(['orientation' => 'landscape']);
        if ($sebelumTabel) {
            $sebelumTabel($section);
        }
        $tabel = $section->addTable();
        $tabel->addRow();
        foreach (['No', 'Elemen', 'Tujuan Pembelajaran', 'Indikator Soal', 'Level Kognitif', 'Soal', 'Catatan Pemeriksa'] as $judul) {
            $tabel->addCell(2000)->addText($judul);
        }
        foreach ($baris as $i => $item) {
            $admin = $item['admin'] ?? [(string) ($i + 1), 'Analisis Data', 'Peserta didik mampu menjelaskan data.', 'Disajikan tabel siswa.', 'C2'];
            $tabel->addRow();
            foreach ($admin as $teks) {
                $tabel->addCell(2000)->addText($teks);
            }
            $sel = $tabel->addCell(6000);
            foreach ($item['soal'] as $baris2) {
                $sel->addText($baris2);
            }
            $tabel->addCell(2000)->addText('Perlu direvisi');
        }
        $path = tempnam(sys_get_temp_dir(), 'kisi').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return new UploadedFile($path, 'kisi.docx', self::MIME, null, true);
    }

    /**
     * @return array<string, mixed>
     */
    private function preview(UploadedFile $file): array
    {
        $hasil = (new WordSoalService)->preview($file);
        @unlink($file->getRealPath());

        return $hasil;
    }

    /**
     * @param  array<string, mixed>  $hasil
     */
    private function pesan(array $hasil): string
    {
        return collect($hasil['errors'])->pluck('message')->implode(' | ');
    }

    public function test_template_has_school_header_landscape_kisi_table_and_35_empty_slots(): void
    {
        $path = (new WordSoalService)->generateTemplate();
        $xml = $this->xmlDokumen($path);
        unlink($path);

        $this->assertStringContainsString('w:orient="landscape"', $xml);
        foreach (['FORMAT TEMPLATE SOAL', 'SMPS 2 AL-MUHAJIRIN PURWAKARTA', 'Satuan Pendidikan', 'Alokasi Waktu'] as $kop) {
            $this->assertStringContainsString($kop, $xml, "Kop {$kop}");
        }
        $this->assertStringNotContainsString('Cara mengisi', $xml, 'Template tidak memuat petunjuk pengisian.');
        foreach (['No', 'Elemen', 'Tujuan Pembelajaran', 'Indikator Soal', 'Level Kognitif', 'Soal', 'Catatan Pemeriksa'] as $kolom) {
            $this->assertStringContainsString('>'.$kolom.'<', $xml, "Kolom {$kolom}");
        }
        $this->assertSame(35, substr_count($xml, '>[SOAL]<'), 'PG 15 + PG_KOMPLEKS 10 + BENAR_SALAH 5 + MENJODOHKAN 3 + ESSAY 2.');
        $this->assertSame(15, substr_count($xml, 'TIPE: PG<'));
        $this->assertSame(10, substr_count($xml, 'TIPE: PG_KOMPLEKS<'));
        $this->assertSame(5, substr_count($xml, 'TIPE: BENAR_SALAH<'));
        $this->assertSame(3, substr_count($xml, 'TIPE: MENJODOHKAN<'));
        $this->assertSame(2, substr_count($xml, 'TIPE: ESSAY<'));
    }

    public function test_template_reads_only_the_five_examples_and_skips_empty_slots(): void
    {
        $path = (new WordSoalService)->generateTemplate();
        $hasil = $this->preview(new UploadedFile($path, 't.docx', self::MIME, null, true));

        $this->assertSame(5, $hasil['total_soal'], 'Slot kosong dan kop dokumen tidak boleh terbaca sebagai soal.');
        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame(['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'], array_column($hasil['items'], 'tipe'));
        [$pg, $kompleks, $bs, $jodoh, $essay] = $hasil['items'];

        $this->assertSame('B', $pg['kunci']);
        $this->assertSame(['A', 'C'], $kompleks['kunci']);
        $this->assertStringContainsString('الْعَزْمِ', $kompleks['teks'], 'Soal berbahasa Arab terbaca utuh.');
        $this->assertSame(['1' => 'B', '2' => 'S'], $bs['kunci']);
        $this->assertStringContainsString('<img', $bs['teks'], 'Gambar di dalam kolom Soal dipertahankan.');
        $this->assertSame(['1' => 'b', '2' => 'a'], $jodoh['kunci']);
        $this->assertStringContainsString('klorofil', (string) $essay['kunci']);

        $semua = json_encode($hasil['items'], JSON_UNESCAPED_UNICODE);
        foreach (['Level Kognitif', 'Catatan Pemeriksa', 'Satuan Pendidikan', 'CAPAIAN', 'SMPS 2'] as $admin) {
            $this->assertStringNotContainsString($admin, (string) $semua, "Teks administrasi \"{$admin}\" tidak boleh ikut terbaca.");
        }
    }

    public function test_template_with_only_empty_slots_reports_that_nothing_was_filled(): void
    {
        $hasil = $this->preview($this->dokumenKisi([['soal' => ['[SOAL]', 'TIPE: PG', 'TEKS:', '[OPSI]', '[OPSI]', '[/SOAL]']]]));

        $this->assertSame(0, $hasil['total_soal']);
        $this->assertStringContainsString('masih kosong', $this->pesan($hasil));
    }

    public function test_only_the_soal_column_is_read_even_when_other_cells_look_like_tags(): void
    {
        $hasil = $this->preview($this->dokumenKisi([[
            'admin' => ['1', 'TIPE: ESSAY', 'KUNCI: abaikan ini', 'RUBRIK: abaikan juga', 'C3'],
            'soal' => ['[SOAL]', 'TIPE: PG', 'TEKS: Ibu kota Indonesia?', '[OPSI] Bandung', '[OPSI_BENAR] Jakarta', '[/SOAL]'],
        ]]));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame(1, $hasil['valid_count']);
        $soal = $hasil['items'][0];
        $this->assertSame('pg', $soal['tipe']);
        $this->assertSame('Ibu kota Indonesia?', $soal['teks']);
        $this->assertSame('B', $soal['kunci']);
        $this->assertNull($soal['rubrik']);
        $this->assertStringNotContainsString('abaikan', json_encode($soal, JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_questions_can_be_in_the_table_and_outside_it_in_document_order(): void
    {
        $hasil = $this->preview($this->dokumenKisi(
            [['soal' => ['[SOAL]', 'TIPE: ESSAY', 'TEKS: Soal dalam tabel', 'KUNCI: k', 'RUBRIK: r', '[/SOAL]']]],
            function ($section): void {
                foreach (['[SOAL]', 'TIPE: ESSAY', 'TEKS: Soal di luar tabel', 'KUNCI: k', 'RUBRIK: r', '[/SOAL]'] as $baris) {
                    $section->addText($baris);
                }
            }
        ));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame(['Soal di luar tabel', 'Soal dalam tabel'], array_column($hasil['items'], 'teks'));
    }

    public function test_missing_closing_tag_is_reported_and_does_not_swallow_the_next_row(): void
    {
        $hasil = $this->preview($this->dokumenKisi([
            ['soal' => ['[SOAL]', 'TIPE: ESSAY', 'TEKS: Soal pertama lupa ditutup', 'KUNCI: k']],
            ['soal' => ['[SOAL]', 'TIPE: ESSAY', 'TEKS: Soal kedua benar', 'KUNCI: k2', 'RUBRIK: r2', '[/SOAL]']],
        ]));

        $this->assertSame(2, $hasil['total_soal']);
        $this->assertSame(1, $hasil['valid_count']);
        $this->assertSame('Soal kedua benar', $hasil['items'][0]['teks']);
        $this->assertStringContainsString('Soal #1: penanda [/SOAL] penutup tidak ditemukan', $this->pesan($hasil));
        $this->assertStringNotContainsString('Analisis Data', json_encode($hasil['items'], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function test_unclosed_last_question_is_reported(): void
    {
        $hasil = $this->preview($this->dokumenKisi([['soal' => ['[SOAL]', 'TIPE: ESSAY', 'TEKS: tidak ditutup']]]));

        $this->assertSame(0, $hasil['valid_count']);
        $this->assertStringContainsString('sampai akhir dokumen', $this->pesan($hasil));
    }

    public function test_common_tag_typos_are_tolerated(): void
    {
        $hasil = $this->preview($this->dokumenKisi([['soal' => [
            '[ soal ]', 'tipe : pg', 'Teks :  Warna langit siang hari?', '[opsi] Merah', '[ Opsi Benar ] Biru', '[ / SOAL ]',
        ]]]));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $soal = $hasil['items'][0];
        $this->assertSame('Warna langit siang hari?', $soal['teks']);
        $this->assertSame('B', $soal['kunci']);
        $this->assertSame(['Merah', 'Biru'], array_column($soal['opsi'], 'konten'));
    }

    public function test_multi_paragraph_question_text_and_options_are_kept(): void
    {
        $hasil = $this->preview($this->dokumenKisi([['soal' => [
            '[SOAL]', 'TIPE: PG', 'TEKS: Baris satu', 'Baris dua', '[OPSI] Opsi satu', 'lanjutan opsi satu', '[OPSI_BENAR] Opsi dua', '[/SOAL]',
        ]]]));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame("Baris satu\nBaris dua", $hasil['items'][0]['teks']);
        $this->assertSame("Opsi satu\nlanjutan opsi satu", $hasil['items'][0]['opsi'][0]['konten']);
    }

    public function test_formula_inside_the_soal_cell_is_converted_to_latex(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $tabel = $section->addTable();
        $tabel->addRow();
        $tabel->addCell(2000)->addText('1');
        $sel = $tabel->addCell(6000);
        foreach (['[SOAL]', 'TIPE: ESSAY', 'TEKS: RUMUS_DI_SINI', 'KUNCI: k', 'RUBRIK: r', '[/SOAL]'] as $baris) {
            $sel->addText($baris);
        }
        $path = tempnam(sys_get_temp_dir(), 'rumus').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $xml = $this->xmlDokumen($path);
        $oMath = '<m:oMath><m:f><m:num><m:r><m:t>1</m:t></m:r></m:num><m:den><m:r><m:t>2</m:t></m:r></m:den></m:f></m:oMath>';
        $xml = str_replace('<w:t xml:space="preserve">TEKS: RUMUS_DI_SINI</w:t>', '<w:t xml:space="preserve">TEKS: Hitung </w:t></w:r>'.$oMath.'<w:r><w:t xml:space="preserve"> lalu jelaskan</w:t>', $xml);
        $zip = new ZipArchive;
        $zip->open($path);
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $hasil = $this->preview(new UploadedFile($path, 'r.docx', self::MIME, null, true));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertStringContainsString('$\frac{1}{2}$', $hasil['items'][0]['teks']);
        $this->assertStringContainsString('lalu jelaskan', $hasil['items'][0]['teks']);
    }

    public function test_export_is_one_table_and_round_trips_every_question_type(): void
    {
        $guru = Guru::create(['user_id' => User::factory()->role('guru')->create()->id]);
        $mapel = Mapel::create(['kode' => 'IPA', 'nama' => 'IPA']);
        $buat = function (string $tipe, mixed $kunci, array $opsi = [], ?string $rubrik = null) use ($guru, $mapel): BankSoal {
            $soal = BankSoal::create(['guru_id' => $guru->id, 'mapel_id' => $mapel->id, 'tipe' => $tipe, 'konten' => "<p>Soal {$tipe}</p>", 'kunci' => $kunci, 'rubrik' => $rubrik]);
            foreach ($opsi as $i => $o) {
                OpsiSoal::create(['bank_soal_id' => $soal->id, 'konten' => $o['konten'], 'sisi' => $o['sisi'] ?? null, 'urutan' => $o['urutan'] ?? $i + 1]);
            }

            return $soal;
        };
        $buat('pg', 'B', [['konten' => 'satu'], ['konten' => 'dua'], ['konten' => 'tiga']]);
        $buat('pg_kompleks', ['A', 'C'], [['konten' => 'a'], ['konten' => 'b'], ['konten' => 'c']]);
        $buat('benar_salah', ['1' => 'B', '2' => 'S'], [['konten' => 'p1'], ['konten' => 'p2']]);
        $buat('menjodohkan', ['1' => 'b', '2' => 'a'], [
            ['konten' => 'kiri1', 'sisi' => 'kiri', 'urutan' => 1], ['konten' => 'kiri2', 'sisi' => 'kiri', 'urutan' => 2],
            ['konten' => 'kananA', 'sisi' => 'kanan', 'urutan' => 1], ['konten' => 'kananB', 'sisi' => 'kanan', 'urutan' => 2],
        ]);
        $buat('essay', 'kunci essay', [], 'rubrik essay');

        $service = new WordSoalService;
        $path = $service->exportToWord(BankSoal::with('opsiSoal')->orderBy('id')->get());
        $this->assertSame(1, substr_count($this->xmlDokumen($path), '<w:tbl>'));

        $hasil = $service->preview(new UploadedFile($path, 'e.docx', self::MIME, null, true));
        unlink($path);

        $this->assertSame(5, $hasil['total_soal'], 'Kalimat pengantar ekspor tidak boleh terbaca sebagai soal.');
        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        [$pg, $kompleks, $bs, $jodoh, $essay] = $hasil['items'];
        $this->assertSame('B', $pg['kunci']);
        $this->assertSame(['A', 'C'], $kompleks['kunci']);
        $this->assertSame(['1' => 'B', '2' => 'S'], $bs['kunci']);
        $this->assertSame(['1' => 'b', '2' => 'a'], $jodoh['kunci']);
        $this->assertSame('kunci essay', $essay['kunci']);
        $this->assertSame('rubrik essay', $essay['rubrik']);
    }

    public function test_document_without_any_question_tag_gets_a_helpful_message(): void
    {
        $hasil = $this->preview($this->dokumenKisi([['soal' => ['hanya teks biasa tanpa tag']]]));

        $this->assertSame(0, $hasil['total_soal']);
        $this->assertStringContainsString('Tandai setiap soal', $this->pesan($hasil));
    }
}
