<?php

namespace Tests\Feature;

use App\Services\WordSoalService;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;
use ZipArchive;

class WordRumusTest extends TestCase
{
    private function buatDocx(string $bodyXml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rumus').'.docx';
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:m="http://schemas.openxmlformats.org/officeDocument/2006/math"><w:body>'.$bodyXml.'</w:body></w:document>');
        $zip->close();

        return $path;
    }

    private function paragraf(string $teks): string
    {
        return '<w:p><w:r><w:t xml:space="preserve">'.$teks.'</w:t></w:r></w:p>';
    }

    public function test_word_formula_is_converted_to_latex_in_question_and_option(): void
    {
        $pecahan = '<m:oMath><m:f><m:num><m:r><m:t>1</m:t></m:r></m:num><m:den><m:r><m:t>2</m:t></m:r></m:den></m:f></m:oMath>';
        $pangkat = '<m:oMath><m:sSup><m:e><m:r><m:t>x</m:t></m:r></m:e><m:sup><m:r><m:t>2</m:t></m:r></m:sup></m:sSup></m:oMath>';

        $body = $this->paragraf('[SOAL]')
            .$this->paragraf('TIPE: PG')
            .'<w:p><w:r><w:t xml:space="preserve">TEKS: Hitung </w:t></w:r>'.$pecahan.'<w:r><w:t> kali dua</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t xml:space="preserve">[OPSI_BENAR] </w:t></w:r>'.$pangkat.'</w:p>'
            .$this->paragraf('[OPSI] Tidak ada')
            .$this->paragraf('[/SOAL]');
        $path = $this->buatDocx($body);

        $file = new UploadedFile($path, 'soal.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
        $hasil = (new WordSoalService)->preview($file);
        unlink($path);

        $this->assertSame(0, $hasil['error_count'], json_encode($hasil['errors']));
        $this->assertSame(1, $hasil['valid_count']);
        $soal = $hasil['items'][0];
        $this->assertStringContainsString('$\frac{1}{2}$', $soal['konten'] ?? $soal['teks']);
        $konten = collect($soal['opsi'])->pluck('konten')->implode('|');
        $this->assertStringContainsString('${x}^{2}$', $konten);
    }
}
