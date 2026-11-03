<?php

namespace App\Services;

use App\Models\JawabanSiswa;
use App\Models\Nilai;
use App\Models\PesertaUjian;

class PenilaianService
{
    /**
     * Hitung skor per butir soal secara murni dan deterministik.
     *
     * @param string $tipe
     * @param mixed $jawaban
     * @param mixed $kunci
     * @param float $bobot
     * @return float
     */
    public function hitungSkorSoal(string $tipe, mixed $jawaban, mixed $kunci, float $bobot): float
    {
        if ($bobot <= 0) {
            return 0.00;
        }

        switch ($tipe) {
            case 'pg':
                // Kunci: string (opsi_id / teks), Jawaban: string
                if (empty($jawaban) || empty($kunci)) {
                    return 0.00;
                }
                return (string) $jawaban === (string) $kunci ? $bobot : 0.00;

            case 'pg_kompleks':
                // All-or-nothing: himpunan pilihan harus persis sama
                if (!is_array($jawaban) || !is_array($kunci) || empty($jawaban) || empty($kunci)) {
                    return 0.00;
                }
                $jawabanSorted = array_values(array_unique(array_map('strval', $jawaban)));
                $kunciSorted = array_values(array_unique(array_map('strval', $kunci)));
                sort($jawabanSorted);
                sort($kunciSorted);

                return $jawabanSorted === $kunciSorted ? $bobot : 0.00;

            case 'benar_salah':
                // Kunci: associative array ['1' => 'B', '2' => 'S']
                // Jawaban: associative array ['1' => 'B', '2' => 'B']
                if (!is_array($jawaban) || !is_array($kunci) || empty($kunci)) {
                    return 0.00;
                }
                $totalPernyataan = count($kunci);
                if ($totalPernyataan === 0) {
                    return 0.00;
                }

                $benar = 0;
                foreach ($kunci as $key => $expected) {
                    if (isset($jawaban[$key]) && strtoupper((string) $jawaban[$key]) === strtoupper((string) $expected)) {
                        $benar++;
                    }
                }

                return round(($benar / $totalPernyataan) * $bobot, 2);

            case 'menjodohkan':
                // Kunci: associative array premis => respons ['1' => 'a', '2' => 'b']
                // Jawaban: associative array premis => respons ['1' => 'a', '2' => 'c']
                if (!is_array($jawaban) || !is_array($kunci) || empty($kunci)) {
                    return 0.00;
                }
                $totalPremis = count($kunci);
                if ($totalPremis === 0) {
                    return 0.00;
                }

                $pasanganBenar = 0;
                foreach ($kunci as $premis => $expectedRespons) {
                    if (isset($jawaban[$premis]) && (string) $jawaban[$premis] === (string) $expectedRespons) {
                        $pasanganBenar++;
                    }
                }

                return round(($pasanganBenar / $totalPremis) * $bobot, 2);

            case 'essay':
                // Essay dinilai manual oleh guru (rentang 0 s/d bobot)
                $nilaiManual = is_numeric($jawaban) ? (float) $jawaban : 0.00;
                return min($bobot, max(0.00, $nilaiManual));

            default:
                return 0.00;
        }
    }

    /**
     * Hitung nilai akhir untuk peserta ujian berdasarkan snapshot bobot jadwal.
     */
    public function nilaiPeserta(PesertaUjian $peserta): Nilai
    {
        $jadwal = $peserta->sesiRuang->jadwalUjian;
        $bobotSnapshot = $jadwal->bobot_snapshot ?? [];

        $soalList = $jadwal->paketSoal->items()->with('bankSoal')->get()->pluck('bankSoal');

        $skorPerTipe = [
            'pg' => 0.00,
            'pg_kompleks' => 0.00,
            'benar_salah' => 0.00,
            'menjodohkan' => 0.00,
            'essay' => 0.00,
        ];

        $maksimalPerTipe = [
            'pg' => 0.00,
            'pg_kompleks' => 0.00,
            'benar_salah' => 0.00,
            'menjodohkan' => 0.00,
            'essay' => 0.00,
        ];

        $adaEssayBelumKoreksi = false;

        $jawabanMap = $peserta->jawaban()->get()->keyBy('bank_soal_id');

        foreach ($soalList as $soal) {
            $tipe = $soal->tipe;
            $bobotSoal = isset($bobotSnapshot[$tipe]) ? (float) $bobotSnapshot[$tipe] : 1.00;
            $maksimalPerTipe[$tipe] += $bobotSoal;

            $jawabanRecord = $jawabanMap->get($soal->id);
            $jawabanData = $jawabanRecord ? $jawabanRecord->jawaban : null;

            if ($tipe === 'essay') {
                if ($jawabanRecord && $jawabanRecord->dikoreksi_oleh !== null) {
                    $skor = (float) $jawabanRecord->skor;
                } else {
                    $skor = 0.00;
                    $adaEssayBelumKoreksi = true;
                }
            } else {
                $skor = $this->hitungSkorSoal($tipe, $jawabanData, $soal->kunci, $bobotSoal);
                if ($jawabanRecord) {
                    $jawabanRecord->skor = $skor;
                    $jawabanRecord->save();
                }
            }

            $skorPerTipe[$tipe] += $skor;
        }

        $totalSkor = array_sum($skorPerTipe);
        $totalMaksimal = array_sum($maksimalPerTipe);

        $nilaiAkhir = $totalMaksimal > 0 ? round(($totalSkor / $totalMaksimal) * 100, 2) : 0.00;
        $status = $adaEssayBelumKoreksi ? 'menunggu_koreksi' : 'final';

        return Nilai::updateOrCreate(
            ['peserta_ujian_id' => $peserta->id],
            [
                'skor_per_tipe' => $skorPerTipe,
                'total' => $totalSkor,
                'maksimal' => $totalMaksimal,
                'nilai_akhir' => $nilaiAkhir,
                'status' => $status,
            ]
        );
    }
}

