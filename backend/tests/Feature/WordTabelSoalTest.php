<?php

namespace Tests\Feature;

use App\Models\BankSoal;
use App\Models\Guru;
use App\Models\Mapel;
use App\Models\OpsiSoal;
use App\Models\User;
use App\Services\WordSoalService;
use App\Services\WordTabelSoal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use Tests\TestCase;
use ZipArchive;

class WordTabelSoalTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  list<array<string, mixed>>  $soalList
     */
    private function buatDocx(array $soalList): UploadedFile
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        foreach ($soalList as $i => $soal) {
            (new WordTabelSoal)->tulisSoal($section, $i + 1, $soal);
        }
        $path = tempnam(sys_get_temp_dir(), 'tabel').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        return new UploadedFile($path, 'soal.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
    }

    private function preview(UploadedFile $file): array
    {
        $hasil = (new WordSoalService)->preview($file);
        @unlink($file->getRealPath());

        return $hasil;
    }

    private function pesan(array $hasil): string
    {
        return collect($hasil['errors'])->pluck('message')->implode(' | ');
    }

    public function test_template_is_made_of_tables_with_fixed_labels_and_instructions(): void
    {
        $path = (new WordSoalService)->generateTemplate();
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        unlink($path);

        $this->assertGreaterThanOrEqual(6, substr_count($xml, '<w:tbl>'), 'Satu tabel panduan dan lima tabel contoh soal.');
        foreach (['TIPE SOAL', 'TEKS SOAL', 'OPSI A', 'KUNCI JAWABAN', 'PERNYATAAN 1', 'JAWABAN 1', 'KIRI 1', 'KANAN A', 'KUNCI PASANGAN', 'RUBRIK PENILAIAN', 'CARA MENGISI KUNCI'] as $label) {
            $this->assertStringContainsString($label, $xml);
        }
        $this->assertStringNotContainsString('[SOAL]', $xml, 'Template baru tidak lagi memakai penanda kurung siku.');
    }

    public function test_template_previews_into_five_valid_questions_with_correct_keys(): void
    {
        $path = (new WordSoalService)->generateTemplate();
        $file = new UploadedFile($path, 't.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $hasil = $this->preview($file);

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame(['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'], array_column($hasil['items'], 'tipe'));
        [$pg, $kompleks, $bs, $jodoh, $essay] = $hasil['items'];

        $this->assertSame('C', $pg['kunci']);
        $this->assertCount(4, $pg['opsi'], 'Baris OPSI E yang kosong diabaikan.');
        $this->assertSame(['A', 'C'], $kompleks['kunci']);
        $this->assertSame(['1' => 'B', '2' => 'S'], $bs['kunci']);
        $this->assertSame(['1' => 'b', '2' => 'a'], $jodoh['kunci']);
        $this->assertCount(2, array_filter($jodoh['opsi'], fn (array $o): bool => $o['sisi'] === 'kiri'));
        $this->assertCount(3, array_filter($jodoh['opsi'], fn (array $o): bool => $o['sisi'] === 'kanan'));
        $this->assertStringContainsString('klorofil', (string) $essay['kunci']);
        $this->assertStringContainsString('Skor maksimal 5', (string) $essay['rubrik']);
        $this->assertStringContainsString('<img', $kompleks['teks'], 'Gambar di dalam sel dipertahankan.');
    }

    public function test_blank_option_rows_are_skipped_and_the_answer_key_is_remapped(): void
    {
        $hasil = $this->preview($this->buatDocx([[
            'tipe' => 'pg',
            'teks' => 'Ibu kota Indonesia?',
            'opsi' => ['Bandung', '', 'Jakarta', 'Surabaya'],
            'kunci' => 'C',
        ]]));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $soal = $hasil['items'][0];
        $this->assertSame(['A', 'B', 'C'], array_column($soal['opsi'], 'label'));
        $this->assertSame(['Bandung', 'Jakarta', 'Surabaya'], array_column($soal['opsi'], 'konten'));
        $this->assertSame('B', $soal['kunci'], 'Kunci C (Jakarta) menjadi B setelah baris kosong dilewati.');
    }

    public function test_letter_prefixes_typed_inside_options_are_removed(): void
    {
        $hasil = $this->preview($this->buatDocx([[
            'tipe' => 'pg', 'teks' => 'Soal', 'opsi' => ['A. Merah', 'B) Biru'], 'kunci' => 'B',
        ]]));

        $this->assertSame(['Merah', 'Biru'], array_column($hasil['items'][0]['opsi'], 'konten'));
    }

    public function test_complex_multiple_choice_accepts_several_separators_and_true_false_accepts_b_s_aliases(): void
    {
        $hasil = $this->preview($this->buatDocx([
            ['tipe' => 'pg_kompleks', 'teks' => 'Pilih', 'opsi' => ['a', 'b', 'c', 'd'], 'kunci' => 'A dan C; D'],
            ['tipe' => 'benar_salah', 'teks' => 'BS', 'pernyataan' => [['teks' => 'x', 'jawaban' => 'b'], ['teks' => 'y', 'jawaban' => 'Salah']]],
        ]));

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertSame(['A', 'C', 'D'], $hasil['items'][0]['kunci']);
        $this->assertSame(['1' => 'B', '2' => 'S'], $hasil['items'][1]['kunci']);
    }

    public function test_mistakes_are_reported_with_clear_messages(): void
    {
        $hasil = $this->preview($this->buatDocx([
            ['tipe' => 'pg', 'teks' => 'a', 'opsi' => ['x', '', 'z'], 'kunci' => 'B'],
            ['tipe' => 'pg', 'teks' => 'b', 'opsi' => ['x', 'y'], 'kunci' => 'Opsi pertama'],
            ['tipe' => 'benar_salah', 'teks' => 'c', 'pernyataan' => [['teks' => 'x', 'jawaban' => 'mungkin']]],
            ['tipe' => 'menjodohkan', 'teks' => 'd', 'kiri' => ['a', 'b'], 'kanan' => ['p', 'q'], 'kunci_pasangan' => '1-Z, 2-A'],
            ['tipe' => 'FOTOSINTESIS', 'teks' => 'e'],
        ]));

        $pesan = $this->pesan($hasil);
        $this->assertSame(0, $hasil['valid_count']);
        $this->assertStringContainsString('Soal #1: Kunci jawaban B menunjuk opsi yang kosong', $pesan);
        $this->assertStringContainsString('Soal #2: Kunci jawaban "Opsi pertama" tidak valid', $pesan);
        $this->assertStringContainsString('Soal #3: Jawaban pernyataan 1 harus BENAR atau SALAH', $pesan);
        $this->assertStringContainsString('Soal #4: Pasangan 1-Z menunjuk baris yang kosong atau tidak ada', $pesan);
        $this->assertStringContainsString('Soal #5: Tipe soal "FOTOSINTESIS" tidak dikenali', $pesan);
    }

    public function test_formula_inside_a_table_cell_is_converted_and_other_tables_are_ignored(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        $biasa = $section->addTable();
        $biasa->addRow();
        $biasa->addCell(3000)->addText('Tabel biasa');
        $biasa->addCell(3000)->addText('bukan soal');
        (new WordTabelSoal)->tulisSoal($section, 1, ['tipe' => 'essay', 'teks' => 'RUMUS_DI_SINI', 'kunci' => 'k', 'rubrik' => 'r']);
        $path = tempnam(sys_get_temp_dir(), 'rumus').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $oMath = '<m:oMath><m:f><m:num><m:r><m:t>1</m:t></m:r></m:num><m:den><m:r><m:t>2</m:t></m:r></m:den></m:f></m:oMath>';
        $xml = str_replace('<w:t xml:space="preserve">RUMUS_DI_SINI</w:t>', '<w:t>Hitung </w:t></w:r>'.$oMath.'<w:r><w:t> lalu jelaskan</w:t>', $xml);
        $this->assertStringContainsString('xmlns:m=', $xml, 'Dokumen PhpWord sudah mendeklarasikan namespace math.');
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();

        $hasil = $this->preview(new UploadedFile($path, 'r.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true));

        $this->assertSame(1, $hasil['total_soal'], 'Tabel tanpa baris TIPE SOAL bukan soal.');
        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        $this->assertStringContainsString('Hitung', $hasil['items'][0]['teks']);
        $this->assertStringContainsString('$\frac{1}{2}$', $hasil['items'][0]['teks']);
        $this->assertStringContainsString('lalu jelaskan', $hasil['items'][0]['teks']);
    }

    public function test_multi_paragraph_question_text_keeps_line_breaks(): void
    {
        $hasil = $this->preview($this->buatDocx([[
            'tipe' => 'essay', 'teks' => "Baris satu\nBaris dua\nBaris tiga", 'kunci' => 'k', 'rubrik' => 'r',
        ]]));

        $this->assertSame("Baris satu\nBaris dua\nBaris tiga", $hasil['items'][0]['teks']);
    }

    public function test_export_writes_tables_and_round_trips_every_question_type(): void
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
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $this->assertSame(5, substr_count($xml, '<w:tbl>'));
        $this->assertStringContainsString('SOAL NOMOR 5', $xml);

        $hasil = $service->preview(new UploadedFile($path, 'e.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true));
        unlink($path);

        $this->assertSame(0, $hasil['error_count'], $this->pesan($hasil));
        [$pg, $kompleks, $bs, $jodoh, $essay] = $hasil['items'];
        $this->assertSame('B', $pg['kunci']);
        $this->assertSame(['A', 'C'], $kompleks['kunci']);
        $this->assertSame(['1' => 'B', '2' => 'S'], $bs['kunci']);
        $this->assertSame(['1' => 'b', '2' => 'a'], $jodoh['kunci']);
        $this->assertSame('kunci essay', $essay['kunci']);
        $this->assertSame('rubrik essay', $essay['rubrik']);
    }

    public function test_legacy_text_marker_documents_are_still_accepted(): void
    {
        $phpWord = new PhpWord;
        $section = $phpWord->addSection();
        foreach (['[SOAL]', 'TIPE: PG', 'TEKS: Soal lama', '[OPSI] satu', '[OPSI_BENAR] dua', '[/SOAL]'] as $baris) {
            $section->addText($baris);
        }
        $path = tempnam(sys_get_temp_dir(), 'lama').'.docx';
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);

        $hasil = $this->preview(new UploadedFile($path, 'l.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true));

        $this->assertSame(1, $hasil['valid_count'], $this->pesan($hasil));
        $this->assertSame('B', $hasil['items'][0]['kunci']);
    }
}
