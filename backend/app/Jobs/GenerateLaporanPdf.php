<?php

namespace App\Jobs;

use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\LaporanPdf;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class GenerateLaporanPdf implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $laporanId) {}

    public function handle(): void
    {
        $laporan = LaporanPdf::query()->findOrFail($this->laporanId);
        $laporan->update(['status' => 'processing']);

        $html = match ($laporan->jenis) {
            'kartu-peserta' => $this->kartuPeserta($laporan),
            'daftar-hadir' => $this->daftarHadir($laporan),
            'berita-acara' => $this->beritaAcara($laporan),
            'rekap-nilai' => $this->rekapNilai($laporan),
            default => throw new \LogicException('Jenis laporan PDF tidak dikenal.'),
        };

        $path = 'reports/'.$laporan->id.'-'.Str::uuid().'.pdf';
        $options = new Options;
        $options->setDefaultFont('DejaVu Sans');
        $options->setIsRemoteEnabled(false);
        $pdf = new Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('a4');
        $pdf->render();
        $stored = Storage::disk('local')->put($path, $pdf->output(), 'private');
        if (! $stored) {
            Storage::disk('local')->delete($path);
            throw new \RuntimeException('Gagal menyimpan PDF laporan pada disk privat.');
        }
        $laporan->update(['status' => 'completed', 'file_path' => $path]);
    }

    public function failed(?Throwable $exception): void
    {
        LaporanPdf::query()->whereKey($this->laporanId)->update(['status' => 'failed']);
    }

    private function kartuPeserta(LaporanPdf $laporan): string
    {
        $jadwal = JadwalUjian::query()->with([
            'mapel',
            'sesiRuang.ruang',
            'sesiRuang.pesertaUjian.siswa.user',
            'sesiRuang.pesertaUjian.siswa.kelas',
        ])->findOrFail($laporan->jadwal_ujian_id);
        $rows = [];
        foreach ($jadwal->sesiRuang as $sesi) {
            foreach ($sesi->pesertaUjian as $peserta) {
                $rows[] = '<section class="card"><h2>Kartu Peserta Ujian</h2><p>Nama: '.$this->escape($peserta->siswa?->user?->name).'</p><p>NIS: '.$this->escape($peserta->siswa?->nis).'</p><p>Kelas: '.$this->escape($peserta->siswa?->kelas?->nama).'</p><p>Ujian: '.$this->escape($jadwal->nama).'</p><p>Mapel: '.$this->escape($jadwal->mapel?->nama).'</p><p>Ruang: '.$this->escape($sesi->ruang?->nama).' / Sesi '.$this->escape($sesi->sesi_ke).'</p></section>';
            }
        }

        return $this->document('Kartu Peserta Ujian - '.$jadwal->nama, implode('', $rows));
    }

    private function daftarHadir(LaporanPdf $laporan): string
    {
        $sesi = SesiRuang::query()->with([
            'ruang',
            'jadwalUjian.mapel',
            'pesertaUjian.siswa.user',
            'pesertaUjian.siswa.kelas',
        ])->findOrFail($laporan->sesi_ruang_id);
        $rows = '';
        foreach ($sesi->pesertaUjian as $index => $peserta) {
            $rows .= '<tr><td>'.($index + 1).'</td><td>'.$this->escape($peserta->siswa?->nis).'</td><td>'.$this->escape($peserta->siswa?->user?->name).'</td><td>'.$this->escape($peserta->siswa?->kelas?->nama).'</td><td>'.($peserta->hadir ? 'Hadir' : 'Tidak hadir').'</td><td></td></tr>';
        }
        $body = '<h1>Daftar Hadir</h1><p>'.$this->escape($sesi->jadwalUjian?->nama).' — '.$this->escape($sesi->jadwalUjian?->mapel?->nama).'</p><p>Ruang '.$this->escape($sesi->ruang?->nama).' / Sesi '.$this->escape($sesi->sesi_ke).'</p><table><thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Kehadiran</th><th>Tanda tangan</th></tr></thead><tbody>'.$rows.'</tbody></table>';

        return $this->document('Daftar Hadir', $body);
    }

    private function beritaAcara(LaporanPdf $laporan): string
    {
        $sesi = SesiRuang::query()->with(['ruang', 'jadwalUjian.mapel', 'beritaAcara.pengawas'])
            ->findOrFail($laporan->sesi_ruang_id);
        $berita = $sesi->beritaAcara;
        $body = '<h1>Berita Acara Ujian</h1><p>Ujian: '.$this->escape($sesi->jadwalUjian?->nama).'</p><p>Mapel: '.$this->escape($sesi->jadwalUjian?->mapel?->nama).'</p><p>Ruang: '.$this->escape($sesi->ruang?->nama).' / Sesi '.$this->escape($sesi->sesi_ke).'</p><p>Pengawas: '.$this->escape($berita?->pengawas?->name).'</p><p>Jumlah hadir: '.($berita?->jumlah_hadir ?? 0).' — Tidak hadir: '.($berita?->jumlah_absen ?? 0).'</p><h2>Catatan</h2><p>'.nl2br($this->escape($berita?->isi ?? 'Berita acara belum diisi.')).'</p>';

        return $this->document('Berita Acara Ujian', $body);
    }

    private function rekapNilai(LaporanPdf $laporan): string
    {
        $jadwal = JadwalUjian::query()->with('mapel')->findOrFail($laporan->jadwal_ujian_id);
        $peserta = PesertaUjian::query()->with(['siswa.user', 'siswa.kelas', 'nilai'])
            ->whereIn('sesi_ruang_id', $jadwal->sesiRuang()->select('id'))
            ->orderBy('id')
            ->when($laporan->kelas_id, fn ($query) => $query->whereHas('siswa', fn ($siswa) => $siswa->where('kelas_id', $laporan->kelas_id)));
        if ($laporan->peminta?->role === 'guru') {
            $kelasDiampu = GuruMapel::query()
                ->where('guru_id', $laporan->peminta->guru?->id)
                ->where('mapel_id', $jadwal->mapel_id)
                ->where('tahun_ajaran_id', $jadwal->tahun_ajaran_id)
                ->when($laporan->kelas_id, fn ($query) => $query->where('kelas_id', $laporan->kelas_id))
                ->pluck('kelas_id');
            $peserta->whereHas('siswa', fn ($siswa) => $siswa->whereIn('kelas_id', $kelasDiampu));
        }
        $peserta = $peserta->get();
        $rows = '';
        foreach ($peserta as $index => $item) {
            $nilai = $item->nilai;
            $rows .= '<tr><td>'.($index + 1).'</td><td>'.$this->escape($item->siswa?->nis).'</td><td>'.$this->escape($item->siswa?->user?->name).'</td><td>'.$this->escape($item->siswa?->kelas?->nama).'</td><td>'.$this->escape($nilai?->nilai_akhir ?? '-').'</td><td>'.$this->escape($nilai?->status ?? 'belum dinilai').'</td></tr>';
        }
        $body = '<h1>Rekap Nilai</h1><p>'.$this->escape($jadwal->nama).' — '.$this->escape($jadwal->mapel?->nama).'</p><table><thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Nilai akhir</th><th>Status</th></tr></thead><tbody>'.$rows.'</tbody></table>';

        return $this->document('Rekap Nilai', $body);
    }

    private function document(string $title, string $body): string
    {
        return '<!doctype html><html lang="id"><meta charset="utf-8"><title>'.$this->escape($title).'</title><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}h1,h2{margin-bottom:8px}table{width:100%;border-collapse:collapse}th,td{border:1px solid #444;padding:5px;text-align:left}.card{border:1px solid #222;padding:16px;margin:0 0 16px;page-break-inside:avoid}.card:not(:last-child){page-break-after:always}</style><body>'.$body.'</body></html>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
