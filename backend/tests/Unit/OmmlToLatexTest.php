<?php

namespace Tests\Unit;

use App\Services\OmmlToLatexService;
use PHPUnit\Framework\TestCase;

class OmmlToLatexTest extends TestCase
{
    private function konversi(string $isi, bool $display = false): string
    {
        $tag = $display ? 'oMathPara' : 'oMath';
        $xml = $display
            ? "<m:oMathPara><m:oMath>{$isi}</m:oMath></m:oMathPara>"
            : "<m:{$tag}>{$isi}</m:{$tag}>";

        return (new OmmlToLatexService)->konversiBlok($xml);
    }

    private function teks(string $teks, string $sty = ''): string
    {
        $pr = $sty !== '' ? "<m:rPr><m:sty m:val=\"{$sty}\"/></m:rPr>" : '';

        return "<m:r>{$pr}<m:t>{$teks}</m:t></m:r>";
    }

    public function test_fraction_becomes_frac(): void
    {
        $hasil = $this->konversi('<m:f><m:num>'.$this->teks('a').'</m:num><m:den>'.$this->teks('b').'</m:den></m:f>');

        $this->assertSame('$\frac{a}{b}$', $hasil);
    }

    public function test_superscript_subscript_and_greek_symbols(): void
    {
        $hasil = $this->konversi(
            '<m:sSup><m:e>'.$this->teks('x').'</m:e><m:sup>'.$this->teks('2').'</m:sup></m:sSup>'
            .$this->teks('+')
            .'<m:sSub><m:e>'.$this->teks('α').'</m:e><m:sub>'.$this->teks('i').'</m:sub></m:sSub>'
        );

        $this->assertSame('${x}^{2}+{\alpha}_{i}$', $hasil);
    }

    public function test_square_root_with_and_without_degree(): void
    {
        $akar = '<m:rad><m:radPr><m:degHide m:val="1"/></m:radPr><m:deg/><m:e>'.$this->teks('x').'</m:e></m:rad>';
        $this->assertSame('$\sqrt{x}$', $this->konversi($akar));

        $pangkat = '<m:rad><m:deg>'.$this->teks('3').'</m:deg><m:e>'.$this->teks('x').'</m:e></m:rad>';
        $this->assertSame('$\sqrt[3]{x}$', $this->konversi($pangkat));
    }

    public function test_summation_with_limits_and_delimiters(): void
    {
        $sigma = '<m:nary><m:naryPr><m:chr m:val="∑"/></m:naryPr><m:sub>'.$this->teks('i=1').'</m:sub><m:sup>'.$this->teks('n').'</m:sup><m:e>'.$this->teks('i').'</m:e></m:nary>';
        $this->assertSame('$\sum_{i=1}^{n} {i}$', $this->konversi($sigma));

        $kurung = '<m:d><m:e>'.$this->teks('a').'</m:e><m:e>'.$this->teks('b').'</m:e></m:d>';
        $this->assertSame('$\left( a \mid b \right)$', $this->konversi($kurung));
    }

    public function test_named_function_and_comparison_operators_avoid_raw_angle_brackets(): void
    {
        $fungsi = '<m:func><m:fName>'.$this->teks('sin', 'p').'</m:fName><m:e>'.$this->teks('x').'</m:e></m:func>';
        $this->assertSame('$\sin x$', $this->konversi($fungsi));

        $hasil = $this->konversi($this->teks('a').$this->teks('&lt;').$this->teks('b').$this->teks('≤').$this->teks('c'));
        $this->assertSame('$a\lt b\leq c$', $hasil);
        $this->assertStringNotContainsString('<', $hasil);
    }

    public function test_display_paragraph_uses_double_dollar_and_matrix_rows(): void
    {
        $matriks = '<m:m><m:mr><m:e>'.$this->teks('1').'</m:e><m:e>'.$this->teks('2').'</m:e></m:mr><m:mr><m:e>'.$this->teks('3').'</m:e><m:e>'.$this->teks('4').'</m:e></m:mr></m:m>';

        $this->assertSame('$$\begin{matrix} 1 & 2 \\\\ 3 & 4 \end{matrix}$$', $this->konversi($matriks, true));
    }

    public function test_replaces_formulas_inside_document_xml_and_keeps_surrounding_text(): void
    {
        $xml = '<w:p><w:r><w:t>Nilai </w:t></w:r><m:oMath><m:sSup><m:e>'.$this->teks('x').'</m:e><m:sup>'.$this->teks('2').'</m:sup></m:sSup></m:oMath><w:r><w:t> adalah</w:t></w:r></w:p>';

        $hasil = (new OmmlToLatexService)->gantiRumusDiXml($xml);

        $this->assertStringContainsString(' ${x}^{2}$ ', $hasil);
        $this->assertStringNotContainsString('<m:', $hasil);
        $this->assertStringContainsString('<w:t>Nilai </w:t>', $hasil);
    }

    public function test_invalid_xml_returns_empty_string_without_throwing(): void
    {
        $this->assertSame('', (new OmmlToLatexService)->konversiBlok('<m:oMath><m:f>'));
    }
}
