<?php

namespace App\Services;

use App\Models\BankSoal;
use App\Models\JawabanSiswa;
use App\Models\Nilai;
use App\Models\PesertaUjian;

class PenilaianService
{
    public function hitungSkorSoal(string $tipe, mixed $jawaban, mixed $kunci, string|int|float $bobot): string
    {
        $bobotSatuan = $this->keSatuanDesimal($bobot);
        if ($bobotSatuan <= 0) {
            return '0.00';
        }

        switch ($tipe) {
            case 'pg':
                if ($jawaban === null || $jawaban === '' || $kunci === null || $kunci === '') {
                    return '0.00';
                }

                return (string) $jawaban === (string) $kunci ? $this->dariSatuanDesimal($bobotSatuan) : '0.00';

            case 'pg_kompleks':
                if (! is_array($jawaban) || ! is_array($kunci) || $jawaban === [] || $kunci === []) {
                    return '0.00';
                }

                $jawabanSorted = array_values(array_unique(array_map('strval', $jawaban)));
                $kunciSorted = array_values(array_unique(array_map('strval', $kunci)));
                sort($jawabanSorted);
                sort($kunciSorted);

                return $jawabanSorted === $kunciSorted ? $this->dariSatuanDesimal($bobotSatuan) : '0.00';

            case 'benar_salah':
                if (! is_array($jawaban) || ! is_array($kunci) || $kunci === []) {
                    return '0.00';
                }

                $benar = 0;
                foreach ($kunci as $key => $expected) {
                    if (isset($jawaban[$key]) && strtoupper((string) $jawaban[$key]) === strtoupper((string) $expected)) {
                        $benar++;
                    }
                }

                return $this->dariSatuanDesimal($this->hitungBagian($bobotSatuan, $benar, count($kunci)));

            case 'menjodohkan':
                if (! is_array($jawaban) || ! is_array($kunci) || $kunci === []) {
                    return '0.00';
                }

                $pasanganBenar = 0;
                foreach ($kunci as $premis => $expectedRespons) {
                    if (isset($jawaban[$premis]) && (string) $jawaban[$premis] === (string) $expectedRespons) {
                        $pasanganBenar++;
                    }
                }

                return $this->dariSatuanDesimal($this->hitungBagian($bobotSatuan, $pasanganBenar, count($kunci)));

            case 'essay':
                $nilaiManual = is_numeric($jawaban) ? $this->keSatuanDesimal($jawaban) : 0;

                return $this->dariSatuanDesimal(min($bobotSatuan, max(0, $nilaiManual)));

            default:
                return '0.00';
        }
    }

    public function nilaiPeserta(PesertaUjian $peserta): Nilai
    {
        $jadwal = $peserta->sesiRuang->jadwalUjian;
        $bobotSnapshot = $jadwal->bobot_snapshot ?? [];
        $soalIds = $jadwal->soal_snapshot
            ?? $jadwal->paketSoal->items()->pluck('bank_soal_id')->all();
        $soalList = BankSoal::whereIn('id', $soalIds)->get();
        $tipeSoal = ['pg', 'pg_kompleks', 'benar_salah', 'menjodohkan', 'essay'];
        $skorPerTipe = array_fill_keys($tipeSoal, 0);
        $maksimalPerTipe = $skorPerTipe;
        $adaEssayBelumKoreksi = false;
        $jawabanMap = $peserta->jawaban()->get()->keyBy('bank_soal_id');

        foreach ($soalList as $soal) {
            $tipe = $soal->tipe;
            $bobot = $bobotSnapshot[$tipe] ?? '1.00';
            $bobotSatuan = $this->keSatuanDesimal($bobot);
            $maksimalPerTipe[$tipe] += $bobotSatuan;
            $jawabanRecord = $jawabanMap->get($soal->id);

            if ($tipe === 'essay') {
                if (! $jawabanRecord) {
                    $jawabanRecord = JawabanSiswa::create([
                        'peserta_ujian_id' => $peserta->id,
                        'bank_soal_id' => $soal->id,
                        'jawaban' => null,
                        'client_seq' => 0,
                        'skor' => '0.00',
                    ]);
                    $jawabanMap->put($soal->id, $jawabanRecord);
                }

                if ($jawabanRecord && $jawabanRecord->dikoreksi_oleh !== null) {
                    $skor = $this->keSatuanDesimal($jawabanRecord->skor);
                } else {
                    $skor = 0;
                    $adaEssayBelumKoreksi = true;
                }
            } else {
                $skor = $this->keSatuanDesimal($this->hitungSkorSoal(
                    $tipe,
                    $jawabanRecord?->jawaban,
                    $soal->kunci,
                    $bobot
                ));

                if ($jawabanRecord) {
                    $jawabanRecord->skor = $this->dariSatuanDesimal($skor);
                    $jawabanRecord->save();
                }
            }

            $skorPerTipe[$tipe] += $skor;
        }

        $totalSkor = array_sum($skorPerTipe);
        $totalMaksimal = array_sum($maksimalPerTipe);
        $nilaiAkhir = $totalMaksimal > 0
            ? $this->dariSatuanDesimal($this->hitungBagian($totalSkor * 10000, 1, $totalMaksimal))
            : '0.00';
        $skorPerTipeDesimal = array_map(fn (int $skor): string => $this->dariSatuanDesimal($skor), $skorPerTipe);

        return Nilai::updateOrCreate(
            ['peserta_ujian_id' => $peserta->id],
            [
                'skor_per_tipe' => $skorPerTipeDesimal,
                'total' => $this->dariSatuanDesimal($totalSkor),
                'maksimal' => $this->dariSatuanDesimal($totalMaksimal),
                'nilai_akhir' => $nilaiAkhir,
                'status' => $adaEssayBelumKoreksi ? 'menunggu_koreksi' : 'final',
            ]
        );
    }

    public function nilaiKeSatuanDesimal(string|int|float $nilai): int
    {
        return $this->keSatuanDesimal($nilai);
    }

    private function keSatuanDesimal(string|int|float $nilai): int
    {
        $nilai = trim((string) $nilai);
        if (! preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', $nilai, $bagian)) {
            return 0;
        }

        $desimal = str_pad(substr($bagian[3] ?? '', 0, 2), 2, '0');
        $satuan = ((int) $bagian[2] * 100) + (int) $desimal;
        if (isset($bagian[3][2]) && (int) $bagian[3][2] >= 5) {
            $satuan++;
        }

        return ($bagian[1] ?? '') === '-' ? -$satuan : $satuan;
    }

    private function dariSatuanDesimal(int $satuan): string
    {
        $tanda = $satuan < 0 ? '-' : '';
        $satuan = abs($satuan);

        return sprintf('%s%d.%02d', $tanda, intdiv($satuan, 100), $satuan % 100);
    }

    private function hitungBagian(int $jumlahSatuan, int $pembilang, int $penyebut): int
    {
        if ($penyebut <= 0 || $pembilang <= 0) {
            return 0;
        }

        $hasil = $jumlahSatuan * $pembilang;
        $satuan = intdiv($hasil, $penyebut);
        if (($hasil % $penyebut) * 2 >= $penyebut) {
            $satuan++;
        }

        return $satuan;
    }
}
