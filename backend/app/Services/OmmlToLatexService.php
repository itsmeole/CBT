<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * Konversi rumus Word (OMML, namespace `m:`) ke LaTeX untuk dirender KaTeX di frontend (FR-BE-33).
 * Hasil memakai pembatas `$...$` (inline) atau `$$...$$` (display) sesuai renderer frontend.
 * Unsur yang tidak dikenali tetap diproses isinya (graceful degradation), tidak melempar error.
 */
class OmmlToLatexService
{
    private const NS_M = 'http://schemas.openxmlformats.org/officeDocument/2006/math';

    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    /** @var array<string, string> */
    private const SIMBOL = [
        'α' => '\alpha ', 'β' => '\beta ', 'γ' => '\gamma ', 'δ' => '\delta ', 'ε' => '\epsilon ', 'ζ' => '\zeta ',
        'η' => '\eta ', 'θ' => '\theta ', 'ι' => '\iota ', 'κ' => '\kappa ', 'λ' => '\lambda ', 'μ' => '\mu ',
        'ν' => '\nu ', 'ξ' => '\xi ', 'π' => '\pi ', 'ρ' => '\rho ', 'σ' => '\sigma ', 'τ' => '\tau ',
        'υ' => '\upsilon ', 'φ' => '\phi ', 'χ' => '\chi ', 'ψ' => '\psi ', 'ω' => '\omega ',
        'Γ' => '\Gamma ', 'Δ' => '\Delta ', 'Θ' => '\Theta ', 'Λ' => '\Lambda ', 'Ξ' => '\Xi ', 'Π' => '\Pi ',
        'Σ' => '\Sigma ', 'Φ' => '\Phi ', 'Ψ' => '\Psi ', 'Ω' => '\Omega ',
        '×' => '\times ', '÷' => '\div ', '±' => '\pm ', '∓' => '\mp ', '·' => '\cdot ', '⋅' => '\cdot ',
        '≤' => '\leq ', '≥' => '\geq ', '≠' => '\neq ', '≈' => '\approx ', '≡' => '\equiv ', '∼' => '\sim ',
        '∞' => '\infty ', '→' => '\to ', '←' => '\leftarrow ', '↔' => '\leftrightarrow ', '⇒' => '\Rightarrow ',
        '⇔' => '\Leftrightarrow ', '∈' => '\in ', '∉' => '\notin ', '⊂' => '\subset ', '⊆' => '\subseteq ',
        '∪' => '\cup ', '∩' => '\cap ', '∅' => '\emptyset ', '∀' => '\forall ', '∃' => '\exists ',
        '∂' => '\partial ', '∇' => '\nabla ', '√' => '\sqrt{}', '°' => '^\circ ', '′' => "'", '…' => '\ldots ',
        '−' => '-', '<' => '\lt ', '>' => '\gt ', '%' => '\% ', '#' => '\# ', '&' => '\& ', '$' => '\$ ',
        '{' => '\{', '}' => '\}', '\\' => '\backslash ', '∝' => '\propto ', '∠' => '\angle ', '⊥' => '\perp ',
        '∥' => '\parallel ', '∴' => '\therefore ', 'ℝ' => '\mathbb{R}', 'ℕ' => '\mathbb{N}', 'ℤ' => '\mathbb{Z}',
        'ℚ' => '\mathbb{Q}', 'ℂ' => '\mathbb{C}',
    ];

    /** @var array<string, string> */
    private const NARY = [
        '∑' => '\sum', '∏' => '\prod', '∫' => '\int', '∬' => '\iint', '∭' => '\iiint', '∮' => '\oint',
        '⋃' => '\bigcup', '⋂' => '\bigcap',
    ];

    /** @var list<string> */
    private const FUNGSI = [
        'sin', 'cos', 'tan', 'cot', 'sec', 'csc', 'arcsin', 'arccos', 'arctan', 'sinh', 'cosh', 'tanh',
        'log', 'ln', 'lg', 'exp', 'lim', 'max', 'min', 'det', 'gcd', 'sup', 'inf', 'deg', 'dim', 'mod',
    ];

    /** @var array<string, string> */
    private const AKSEN = [
        '' => '\hat', '̂' => '\hat', 'ˇ' => '\check', '̌' => '\check', '~' => '\tilde', '̃' => '\tilde',
        '¯' => '\bar', '̄' => '\bar', '→' => '\vec', '⃗' => '\vec', '˙' => '\dot', '̇' => '\dot',
        '¨' => '\ddot', '̈' => '\ddot', '´' => '\acute', '`' => '\grave', '^' => '\hat',
    ];

    /** @var array<string, string> */
    private const KURUNG = [
        '(' => '(', ')' => ')', '[' => '[', ']' => ']', '{' => '\{', '}' => '\}', '|' => '|', '‖' => '\|',
        '⟨' => '\langle', '⟩' => '\rangle', '⌊' => '\lfloor', '⌋' => '\rfloor', '⌈' => '\lceil', '⌉' => '\rceil',
        '<' => '\langle', '>' => '\rangle', '' => '.',
    ];

    /**
     * Ubah satu blok `<m:oMath>` atau `<m:oMathPara>` (XML mentah dari document.xml) menjadi teks LaTeX berpembatas.
     */
    public function konversiBlok(string $omml): string
    {
        $dokumen = new DOMDocument('1.0', 'UTF-8');
        $bungkus = '<root xmlns:m="'.self::NS_M.'" xmlns:w="'.self::NS_W.'">'.$omml.'</root>';
        $sebelumnya = libxml_use_internal_errors(true);
        $berhasil = $dokumen->loadXML($bungkus, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($sebelumnya);
        if (! $berhasil || ! $dokumen->documentElement) {
            return '';
        }

        $akar = $dokumen->documentElement->firstChild;
        if (! $akar instanceof DOMElement) {
            return '';
        }

        if ($akar->localName === 'oMathPara') {
            $isi = [];
            foreach ($akar->childNodes as $anak) {
                if ($anak instanceof DOMElement && $anak->localName === 'oMath') {
                    $isi[] = trim($this->anak($anak));
                }
            }
            $latex = implode(' \\\\ ', array_filter($isi, fn (string $baris): bool => $baris !== ''));

            return $latex === '' ? '' : '$$'.$latex.'$$';
        }

        $latex = trim($this->anak($akar));

        return $latex === '' ? '' : '$'.$latex.'$';
    }

    /**
     * Ganti semua blok rumus pada XML dokumen dengan teks LaTeX.
     */
    public function gantiRumusDiXml(string $xml): string
    {
        return (string) preg_replace_callback(
            '/<m:oMathPara\b[\s\S]*?<\/m:oMathPara>|<m:oMath\b[\s\S]*?<\/m:oMath>/u',
            fn (array $cocok): string => ' '.$this->konversiBlok($cocok[0]).' ',
            $xml
        );
    }

    private function anak(DOMNode $node): string
    {
        $hasil = '';
        foreach ($node->childNodes as $anak) {
            if ($anak instanceof DOMElement) {
                $hasil .= $this->konversi($anak);
            }
        }

        return $hasil;
    }

    private function bagian(DOMElement $induk, string $nama): string
    {
        foreach ($induk->childNodes as $anak) {
            if ($anak instanceof DOMElement && $anak->localName === $nama) {
                return trim($this->anak($anak));
            }
        }

        return '';
    }

    private function properti(DOMElement $induk, string $wadah, string $nama): ?string
    {
        foreach ($induk->childNodes as $anak) {
            if ($anak instanceof DOMElement && $anak->localName === $wadah) {
                foreach ($anak->childNodes as $prop) {
                    if ($prop instanceof DOMElement && $prop->localName === $nama) {
                        return $prop->getAttributeNS(self::NS_M, 'val');
                    }
                }
            }
        }

        return null;
    }

    private function konversi(DOMElement $el): string
    {
        switch ($el->localName) {
            case 'r':
                return $this->teks($el);

            case 'f':
                $tipe = $this->properti($el, 'fPr', 'type');
                $pembilang = $this->bagian($el, 'num');
                $penyebut = $this->bagian($el, 'den');
                if ($tipe === 'noBar') {
                    return '\binom{'.$pembilang.'}{'.$penyebut.'}';
                }
                if ($tipe === 'lin') {
                    return '{'.$pembilang.'}/{'.$penyebut.'}';
                }

                return '\frac{'.$pembilang.'}{'.$penyebut.'}';

            case 'sSup':
                return '{'.$this->bagian($el, 'e').'}^{'.$this->bagian($el, 'sup').'}';

            case 'sSub':
                return '{'.$this->bagian($el, 'e').'}_{'.$this->bagian($el, 'sub').'}';

            case 'sSubSup':
                return '{'.$this->bagian($el, 'e').'}_{'.$this->bagian($el, 'sub').'}^{'.$this->bagian($el, 'sup').'}';

            case 'sPre':
                return '{}_{'.$this->bagian($el, 'sub').'}^{'.$this->bagian($el, 'sup').'}{'.$this->bagian($el, 'e').'}';

            case 'rad':
                $derajat = $this->bagian($el, 'deg');
                $isi = $this->bagian($el, 'e');

                return $derajat === '' || $this->properti($el, 'radPr', 'degHide') === '1'
                    ? '\sqrt{'.$isi.'}'
                    : '\sqrt['.$derajat.']{'.$isi.'}';

            case 'nary':
                $simbol = $this->properti($el, 'naryPr', 'chr') ?? '∫';
                $perintah = self::NARY[$simbol] ?? '\int';
                $bawah = $this->properti($el, 'naryPr', 'subHide') === '1' ? '' : $this->bagian($el, 'sub');
                $atas = $this->properti($el, 'naryPr', 'supHide') === '1' ? '' : $this->bagian($el, 'sup');
                $hasil = $perintah;
                if ($bawah !== '') {
                    $hasil .= '_{'.$bawah.'}';
                }
                if ($atas !== '') {
                    $hasil .= '^{'.$atas.'}';
                }

                return $hasil.' {'.$this->bagian($el, 'e').'}';

            case 'd':
                $awal = $this->properti($el, 'dPr', 'begChr') ?? '(';
                $akhir = $this->properti($el, 'dPr', 'endChr') ?? ')';
                $pemisah = $this->properti($el, 'dPr', 'sepChr') ?? '|';
                $bagian = [];
                foreach ($el->childNodes as $anak) {
                    if ($anak instanceof DOMElement && $anak->localName === 'e') {
                        $bagian[] = trim($this->anak($anak));
                    }
                }
                $pemisahLatex = self::KURUNG[$pemisah] ?? $pemisah;

                return '\left'.(self::KURUNG[$awal] ?? '(').' '
                    .implode(' '.($pemisah === '|' ? '\mid' : $pemisahLatex).' ', $bagian)
                    .' \right'.(self::KURUNG[$akhir] ?? ')');

            case 'func':
                $nama = $this->bagian($el, 'fName');
                $polos = trim(str_replace(['\mathrm{', '}'], '', $nama));
                $perintah = in_array($polos, self::FUNGSI, true)
                    ? '\\'.$polos.' '
                    : '\operatorname{'.$polos.'} ';

                return $perintah.$this->bagian($el, 'e');

            case 'acc':
                $aksen = $this->properti($el, 'accPr', 'chr') ?? '^';

                return (self::AKSEN[$aksen] ?? '\hat').'{'.$this->bagian($el, 'e').'}';

            case 'bar':
                return $this->properti($el, 'barPr', 'pos') === 'bot'
                    ? '\underline{'.$this->bagian($el, 'e').'}'
                    : '\overline{'.$this->bagian($el, 'e').'}';

            case 'limLow':
                $isi = $this->bagian($el, 'e');
                $polos = trim(str_replace(['\mathrm{', '}'], '', $isi));
                $dasar = in_array($polos, self::FUNGSI, true) ? '\\'.$polos : '{'.$isi.'}';

                return $dasar.'_{'.$this->bagian($el, 'lim').'}';

            case 'limUpp':
                return '{'.$this->bagian($el, 'e').'}^{'.$this->bagian($el, 'lim').'}';

            case 'm':
                $baris = [];
                foreach ($el->childNodes as $mr) {
                    if ($mr instanceof DOMElement && $mr->localName === 'mr') {
                        $sel = [];
                        foreach ($mr->childNodes as $e) {
                            if ($e instanceof DOMElement && $e->localName === 'e') {
                                $sel[] = trim($this->anak($e));
                            }
                        }
                        $baris[] = implode(' & ', $sel);
                    }
                }

                return '\begin{matrix} '.implode(' \\\\ ', $baris).' \end{matrix}';

            case 'eqArr':
                $baris = [];
                foreach ($el->childNodes as $e) {
                    if ($e instanceof DOMElement && $e->localName === 'e') {
                        $baris[] = trim($this->anak($e));
                    }
                }

                return '\begin{aligned} '.implode(' \\\\ ', $baris).' \end{aligned}';

            case 'rPr':
            case 'ctrlPr':
            case 'fPr':
            case 'dPr':
            case 'naryPr':
            case 'radPr':
            case 'accPr':
            case 'barPr':
                return '';

            default:
                return $this->anak($el);
        }
    }

    private function teks(DOMElement $run): string
    {
        $polos = $this->properti($run, 'rPr', 'sty') === 'p';
        $hasil = '';
        foreach ($run->childNodes as $anak) {
            if (! $anak instanceof DOMElement || ! in_array($anak->localName, ['t'], true)) {
                continue;
            }
            $hasil .= $this->escapeTeks($anak->textContent, $polos);
        }

        return $hasil;
    }

    private function escapeTeks(string $teks, bool $polos): string
    {
        $hasil = '';
        foreach (preg_split('//u', $teks, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $karakter) {
            $hasil .= self::SIMBOL[$karakter] ?? $karakter;
        }

        if ($polos && preg_match('/^[A-Za-z ]+$/', $teks) === 1) {
            return '\mathrm{'.trim($hasil).'}';
        }

        return $hasil;
    }
}
