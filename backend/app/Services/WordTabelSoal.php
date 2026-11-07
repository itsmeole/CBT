<?php

namespace App\Services;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use DOMXPath;
use PhpOffice\PhpWord\Element\Cell;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;

/**
 * Format dokumen soal berbentuk tabel. Satu soal = satu tabel dua kolom: kolom kiri berisi label tetap
 * (TIPE SOAL, TEKS SOAL, OPSI A, ...), kolom kanan diisi guru. Bentuk tabel meminimalkan salah ketik
 * penanda dibanding format teks bebas, dan tiap baris yang salah isi dapat dilaporkan dengan jelas.
 *
 * Kelas ini menulis tabel (template dan ekspor) serta membacanya kembali menjadi data soal terstruktur.
 */
class WordTabelSoal
{
    private const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    public const LEBAR_LABEL = 2300;

    public const LEBAR_ISI = 6700;

    private const GAYA_TABEL = ['borderSize' => 6, 'borderColor' => '94A3B8', 'cellMargin' => 70];

    private const WARNA_LABEL = 'E2E8F0';

    /** Baris tidak boleh terpotong antar halaman. */
    private const BARIS_UTUH = ['cantSplit' => true];

    /** Paragraf menempel ke baris berikutnya (soal satu halaman utuh bila muat). */
    private const PARAGRAF_TAHAN = ['keepNext' => true, 'keepLines' => true];

    private const WARNA_JUDUL = '1E40AF';

    private const TIPE_VALID = ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'];

    /** @var array<string, string> */
    private const SINONIM_TIPE = [
        'PILIHAN_GANDA' => 'pg',
        'PG' => 'pg',
        'PG_KOMPLEKS' => 'pg_kompleks',
        'PILIHAN_GANDA_KOMPLEKS' => 'pg_kompleks',
        'BENAR_SALAH' => 'benar_salah',
        'B_S' => 'benar_salah',
        'MENJODOHKAN' => 'menjodohkan',
        'ESSAY' => 'essay',
        'ESAI' => 'essay',
        'URAIAN' => 'essay',
    ];

    // ------------------------------------------------------------------
    // Menulis tabel
    // ------------------------------------------------------------------

    /**
     * Tulis satu soal sebagai tabel.
     *
     * @param  array{tipe: string, teks: string, opsi?: list<string>, kunci?: string, pernyataan?: list<array{teks: string, jawaban: string}>, kiri?: list<string>, kanan?: list<string>, kunci_pasangan?: string, rubrik?: string, gambar?: string|null, font?: array<string, mixed>}  $soal
     */
    public function tulisSoal(Section $section, int $nomor, array $soal): void
    {
        $tabel = $section->addTable(self::GAYA_TABEL);
        $font = $soal['font'] ?? [];

        $tabel->addRow(null, self::BARIS_UTUH);
        $judul = $tabel->addCell(self::LEBAR_LABEL + self::LEBAR_ISI, ['gridSpan' => 2, 'bgColor' => self::WARNA_JUDUL]);
        $judul->addText('SOAL NOMOR '.$nomor, ['bold' => true, 'color' => 'FFFFFF'], self::PARAGRAF_TAHAN);

        $this->baris($tabel, 'TIPE SOAL', strtoupper($soal['tipe']));
        $this->baris($tabel, 'TEKS SOAL', $soal['teks'], $font, $soal['gambar'] ?? null);

        switch ($soal['tipe']) {
            case 'pg':
            case 'pg_kompleks':
                foreach ($soal['opsi'] ?? [] as $i => $teks) {
                    $this->baris($tabel, 'OPSI '.chr(65 + $i), $teks, $font);
                }
                $this->baris($tabel, 'KUNCI JAWABAN', $soal['kunci'] ?? '');
                break;

            case 'benar_salah':
                foreach ($soal['pernyataan'] ?? [] as $i => $pernyataan) {
                    $this->baris($tabel, 'PERNYATAAN '.($i + 1), $pernyataan['teks'], $font);
                    $this->baris($tabel, 'JAWABAN '.($i + 1), $pernyataan['jawaban']);
                }
                break;

            case 'menjodohkan':
                foreach ($soal['kiri'] ?? [] as $i => $teks) {
                    $this->baris($tabel, 'KIRI '.($i + 1), $teks, $font);
                }
                foreach ($soal['kanan'] ?? [] as $i => $teks) {
                    $this->baris($tabel, 'KANAN '.chr(65 + $i), $teks, $font);
                }
                $this->baris($tabel, 'KUNCI PASANGAN', $soal['kunci_pasangan'] ?? '');
                break;

            case 'essay':
                $this->baris($tabel, 'KUNCI JAWABAN', $soal['kunci'] ?? '');
                $this->baris($tabel, 'RUBRIK PENILAIAN', $soal['rubrik'] ?? '');
                break;
        }

        $this->lepasTahanBarisTerakhir($tabel);
        $section->addTextBreak();
    }

    /**
     * Tiap baris "menahan" baris berikutnya agar satu soal tidak terpotong di batas halaman;
     * baris terakhir dilepas supaya tabel soal berikutnya tidak ikut tertahan.
     */
    private function lepasTahanBarisTerakhir(Table $tabel): void
    {
        $baris = $tabel->getRows();
        $terakhir = end($baris);
        if ($terakhir === false) {
            return;
        }
        foreach ($terakhir->getCells() as $sel) {
            foreach ($sel->getElements() as $elemen) {
                if (method_exists($elemen, 'getParagraphStyle')) {
                    $elemen->getParagraphStyle()?->setKeepNext(false);
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $font
     */
    private function baris(Table $tabel, string $label, string $isi, array $font = [], ?string $gambar = null): void
    {
        $tabel->addRow(null, self::BARIS_UTUH);
        $tabel->addCell(self::LEBAR_LABEL, ['bgColor' => self::WARNA_LABEL])->addText($label, ['bold' => true], self::PARAGRAF_TAHAN);
        $sel = $tabel->addCell(self::LEBAR_ISI);
        $this->isiSel($sel, $isi, $font);
        if ($gambar !== null && is_file($gambar)) {
            $sel->addImage($gambar, ['width' => 160, 'height' => 80]);
        }
    }

    /**
     * @param  array<string, mixed>  $font
     */
    private function isiSel(Cell $sel, string $isi, array $font): void
    {
        $baris = preg_split('/\R/u', $isi) ?: [''];
        foreach ($baris as $teks) {
            $sel->addText($teks, $font, self::PARAGRAF_TAHAN);
        }
    }

    // ------------------------------------------------------------------
    // Membaca tabel dari document.xml
    // ------------------------------------------------------------------

    /**
     * Cari tabel soal pada dokumen, ganti masing-masing dengan penanda `[[SOALTABEL:n]]`, dan kembalikan
     * baris mentahnya (label + isi) menurut urutan kemunculan.
     *
     * @return array<int, list<array{label: string, isi: string}>>
     */
    public function ekstrak(DOMDocument $dom): array
    {
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('w', self::NS_W);
        $hasil = [];

        foreach (iterator_to_array($xpath->query('//w:tbl[not(ancestor::w:tbl)]') ?: []) as $tabel) {
            $baris = $this->barisTabel($tabel, $xpath);
            $adaTipe = collect($baris)->contains(fn (array $b): bool => $b['label'] === 'TIPE SOAL');
            if (! $adaTipe) {
                continue;
            }

            $indeks = count($hasil);
            $hasil[$indeks] = $baris;

            $paragraf = $dom->createElementNS(self::NS_W, 'w:p');
            $run = $dom->createElementNS(self::NS_W, 'w:r');
            $teks = $dom->createElementNS(self::NS_W, 'w:t', "[[SOALTABEL:{$indeks}]]");
            $run->appendChild($teks);
            $paragraf->appendChild($run);
            $tabel->parentNode?->replaceChild($paragraf, $tabel);
        }

        return $hasil;
    }

    /**
     * @return list<array{label: string, isi: string}>
     */
    private function barisTabel(DOMElement $tabel, DOMXPath $xpath): array
    {
        $hasil = [];
        foreach ($xpath->query('./w:tr', $tabel) ?: [] as $tr) {
            $sel = iterator_to_array($xpath->query('./w:tc', $tr) ?: []);
            if (count($sel) < 2) {
                continue;
            }
            $hasil[] = [
                'label' => $this->normalisasiLabel($this->teksSel($sel[0], $xpath)),
                'isi' => $this->teksSel($sel[1], $xpath),
            ];
        }

        return $hasil;
    }

    private function normalisasiLabel(string $label): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', mb_strtoupper($label)));
    }

    /**
     * Teks sebuah sel: paragraf dipisah baris baru, tabel bersarang diratakan menjadi baris "a | b",
     * gambar (sudah diganti elemen `img`) dipertahankan sebagai tag.
     */
    private function teksSel(DOMNode $sel, DOMXPath $xpath): string
    {
        $baris = [];
        foreach ($sel->childNodes as $anak) {
            if (! $anak instanceof DOMElement) {
                continue;
            }
            if ($anak->localName === 'p') {
                $baris[] = $this->teksParagraf($anak);
            } elseif ($anak->localName === 'tbl') {
                foreach ($xpath->query('./w:tr', $anak) ?: [] as $tr) {
                    $sel2 = array_map(fn (DOMNode $c): string => trim($this->teksSel($c, $xpath)), iterator_to_array($xpath->query('./w:tc', $tr) ?: []));
                    $baris[] = implode(' | ', $sel2);
                }
            }
        }

        return trim(implode("\n", array_filter(array_map('rtrim', $baris), fn (string $b): bool => trim($b) !== '')));
    }

    private function teksParagraf(DOMElement $paragraf): string
    {
        $hasil = '';
        $telusuri = function (DOMNode $node) use (&$telusuri, &$hasil): void {
            foreach ($node->childNodes as $anak) {
                if ($anak instanceof DOMText) {
                    // Teks langsung di bawah w:p berasal dari rumus yang sudah dikonversi ke LaTeX.
                    if ($anak->parentNode?->localName === 't' || $anak->parentNode?->localName === 'p') {
                        $hasil .= $anak->nodeValue;
                    }

                    continue;
                }
                if (! $anak instanceof DOMElement) {
                    continue;
                }
                match ($anak->localName) {
                    'tab' => $hasil .= ' ',
                    'br', 'cr' => $hasil .= "\n",
                    'img' => $hasil .= '<img src="'.htmlspecialchars($anak->getAttribute('src'), ENT_QUOTES).'" alt="Gambar Soal" />',
                    default => $telusuri($anak),
                };
            }
        };
        $telusuri($paragraf);

        return $hasil;
    }

    // ------------------------------------------------------------------
    // Mengubah baris tabel menjadi data soal
    // ------------------------------------------------------------------

    /**
     * Bentuk keluaran sama dengan parser teks (`errors` + `data`) agar validasi dan commit dipakai bersama.
     *
     * @param  list<array{label: string, isi: string}>  $baris
     * @return array{errors: list<string>, data: array<string, mixed>}
     */
    public function parse(array $baris, int $nomor): array
    {
        $errors = [];
        $awal = "Soal #{$nomor}: ";

        $nilai = fn (string $label): string => (string) (collect($baris)->firstWhere('label', $label)['isi'] ?? '');
        $daftar = fn (string $pola): array => collect($baris)
            ->map(fn (array $b): ?array => preg_match($pola, $b['label'], $m) === 1 ? ['kunci' => $m[1], 'isi' => $b['isi']] : null)
            ->filter()
            ->values()
            ->all();

        $tipe = $this->normalisasiTipe($nilai('TIPE SOAL'));
        if ($tipe === null) {
            $errors[] = $awal.'Tipe soal "'.trim($nilai('TIPE SOAL')).'" tidak dikenali. Gunakan PG, PG_KOMPLEKS, BENAR_SALAH, MENJODOHKAN, atau ESSAY.';
        }

        $teks = $nilai('TEKS SOAL');
        $kunci = null;
        $rubrik = null;
        $opsi = [];

        $barisKunci = collect($baris)->first(fn (array $b): bool => str_starts_with($b['label'], 'KUNCI'));
        $kunciMentah = trim($barisKunci['isi'] ?? '');

        switch ($tipe) {
            case 'pg':
            case 'pg_kompleks':
                $peta = [];
                foreach ($daftar('/^OPSI ([A-Z])$/u') as $item) {
                    $konten = $this->bersihkanAwalan(trim($item['isi']));
                    if ($konten === '') {
                        continue;
                    }
                    $huruf = chr(65 + count($opsi));
                    $peta[$item['kunci']] = $huruf;
                    $opsi[] = ['label' => $huruf, 'konten' => $konten, 'sisi' => null];
                }
                $kunciHuruf = $this->hurufKunci($kunciMentah);
                if ($kunciHuruf === null && $kunciMentah !== '') {
                    $errors[] = $awal.'Kunci jawaban "'.$kunciMentah.'" tidak valid. Tulis huruf opsi, contoh: C'.($tipe === 'pg_kompleks' ? ' atau A, C.' : '.');
                } elseif ($kunciHuruf !== null) {
                    $dipetakan = [];
                    foreach ($kunciHuruf as $huruf) {
                        if (! isset($peta[$huruf])) {
                            $errors[] = $awal."Kunci jawaban {$huruf} menunjuk opsi yang kosong atau tidak ada.";

                            continue;
                        }
                        $dipetakan[] = $peta[$huruf];
                    }
                    if ($dipetakan !== []) {
                        $kunci = $tipe === 'pg' ? $dipetakan[0] : array_values(array_unique($dipetakan));
                    }
                }
                break;

            case 'benar_salah':
                $kunci = [];
                $jawaban = collect($daftar('/^JAWABAN (\d+)$/u'))->pluck('isi', 'kunci');
                foreach ($daftar('/^PERNYATAAN (\d+)$/u') as $item) {
                    $konten = $this->bersihkanAwalan(trim($item['isi']));
                    if ($konten === '') {
                        continue;
                    }
                    $nomorBaris = (string) (count($opsi) + 1);
                    $nilaiBS = $this->jawabanBenarSalah((string) ($jawaban[$item['kunci']] ?? ''));
                    if ($nilaiBS === null) {
                        $errors[] = $awal."Jawaban pernyataan {$item['kunci']} harus BENAR atau SALAH (boleh B/S).";

                        continue;
                    }
                    $opsi[] = ['label' => $nomorBaris, 'konten' => $konten, 'sisi' => null];
                    $kunci[$nomorBaris] = $nilaiBS;
                }
                if ($kunci === []) {
                    $kunci = null;
                }
                break;

            case 'menjodohkan':
                $petaKiri = [];
                $petaKanan = [];
                foreach ($daftar('/^KIRI (\d+)$/u') as $item) {
                    $konten = $this->bersihkanAwalan(trim($item['isi']));
                    if ($konten === '') {
                        continue;
                    }
                    $label = (string) (count($petaKiri) + 1);
                    $petaKiri[$item['kunci']] = $label;
                    $opsi[] = ['label' => $label, 'konten' => $konten, 'sisi' => 'kiri'];
                }
                foreach ($daftar('/^KANAN ([A-Z])$/u') as $item) {
                    $konten = $this->bersihkanAwalan(trim($item['isi']));
                    if ($konten === '') {
                        continue;
                    }
                    $label = chr(97 + count($petaKanan));
                    $petaKanan[$item['kunci']] = $label;
                    $opsi[] = ['label' => $label, 'konten' => $konten, 'sisi' => 'kanan'];
                }
                $kunci = [];
                preg_match_all('/(\d+)\s*[-=:>→–—]+\s*([A-Za-z])/u', $kunciMentah, $pasangan, PREG_SET_ORDER);
                foreach ($pasangan as $p) {
                    $kiri = $petaKiri[$p[1]] ?? null;
                    $kanan = $petaKanan[strtoupper($p[2])] ?? null;
                    if ($kiri === null || $kanan === null) {
                        $errors[] = $awal."Pasangan {$p[1]}-".strtoupper($p[2]).' menunjuk baris yang kosong atau tidak ada.';

                        continue;
                    }
                    $kunci[$kiri] = $kanan;
                }
                if ($kunci === []) {
                    if ($kunciMentah !== '') {
                        $errors[] = $awal.'Kunci pasangan harus berbentuk 1-A, 2-B, dan seterusnya.';
                    }
                    $kunci = null;
                }
                break;

            case 'essay':
                $kunci = $kunciMentah !== '' ? $kunciMentah : null;
                $rubrikMentah = trim($nilai('RUBRIK PENILAIAN') ?: $nilai('RUBRIK'));
                $rubrik = $rubrikMentah !== '' ? $rubrikMentah : null;
                break;
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

    private function normalisasiTipe(string $tipe): ?string
    {
        $kode = trim((string) preg_replace('/[^A-Z]+/', '_', strtoupper($tipe)), '_');
        $hasil = self::SINONIM_TIPE[$kode] ?? null;

        return $hasil !== null && in_array($hasil, self::TIPE_VALID, true) ? $hasil : null;
    }

    /** Hapus awalan "A." / "1)" yang tidak sengaja diketik di isi opsi. */
    private function bersihkanAwalan(string $teks): string
    {
        return trim((string) preg_replace('/^(?:[A-Za-z]|\d+)[\.\)]\s+/u', '', $teks));
    }

    /**
     * Huruf kunci dari isi sel. Mengembalikan null bila ada token yang bukan satu huruf.
     *
     * @return list<string>|null
     */
    private function hurufKunci(string $teks): ?array
    {
        $token = array_values(array_filter(preg_split('/[\s,;&]+|\bdan\b/iu', $teks) ?: [], fn (string $t): bool => $t !== ''));
        if ($token === []) {
            return null;
        }
        foreach ($token as $t) {
            if (preg_match('/^[A-Za-z]$/', $t) !== 1) {
                return null;
            }
        }

        return array_map('strtoupper', $token);
    }

    private function jawabanBenarSalah(string $teks): ?string
    {
        return match (strtoupper(trim($teks))) {
            'B', 'BENAR', 'TRUE', '1' => 'B',
            'S', 'SALAH', 'FALSE', '0' => 'S',
            default => null,
        };
    }
}
