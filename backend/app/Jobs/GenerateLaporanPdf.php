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
            'daftar-hadir-semua' => $this->daftarHadirSemuaRuang($laporan),
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
        $sesi = SesiRuang::query()->with($this->relasiDaftarHadir())->findOrFail($laporan->sesi_ruang_id);

        return $this->document('Daftar Hadir', $this->isiDaftarHadir($sesi));
    }

    /**
     * Satu PDF berisi daftar hadir setiap ruang pada sebuah jadwal ujian (satu halaman awal per ruang/sesi).
     */
    private function daftarHadirSemuaRuang(LaporanPdf $laporan): string
    {
        $jadwal = JadwalUjian::query()->findOrFail($laporan->jadwal_ujian_id);
        $sesiList = SesiRuang::query()
            ->with($this->relasiDaftarHadir())
            ->where('jadwal_ujian_id', $jadwal->id)
            ->orderBy('sesi_ke')
            ->orderBy('jam_mulai')
            ->orderBy('id')
            ->get();

        if ($sesiList->isEmpty()) {
            return $this->document('Daftar Hadir', '<h1>Daftar Hadir Peserta Ujian</h1><p>Belum ada ruang/sesi dan penempatan peserta untuk jadwal ini.</p>');
        }

        $bagian = $sesiList->map(fn (SesiRuang $sesi): string => '<section class="ruang">'.$this->isiDaftarHadir($sesi).'</section>')->all();

        return $this->document('Daftar Hadir - '.$jadwal->nama, implode('', $bagian));
    }

    /**
     * @return array<int|string, mixed>
     */
    private function relasiDaftarHadir(): array
    {
        return [
            'ruang',
            'jadwalUjian.mapel',
            'pengawasSesi.guru.user',
            'pesertaUjian' => fn ($peserta) => $peserta->orderBy('id'),
            'pesertaUjian.siswa.user',
            'pesertaUjian.siswa.kelas',
        ];
    }

    /** Isi (judul, info, pengawas, peserta) daftar hadir satu sesi ruang. */
    private function isiDaftarHadir(SesiRuang $sesi): string
    {
        $jadwal = $sesi->jadwalUjian;
        $tanggal = $jadwal?->tanggal?->locale('id')->translatedFormat('l, d F Y') ?? '-';
        $waktu = substr((string) $sesi->jam_mulai, 0, 5).' - '.substr((string) $sesi->jam_akhir, 0, 5);

        $peserta = $sesi->pesertaUjian->sortBy(fn (PesertaUjian $item): string => ($item->siswa?->kelas?->nama ?? '').'|'.($item->siswa?->user?->name ?? ''))->values();
        $hadir = $peserta->where('hadir', true)->count();

        $rows = '';
        foreach ($peserta as $index => $item) {
            $rows .= '<tr><td>'.($index + 1).'</td><td>'.$this->escape($item->siswa?->nis).'</td><td>'.$this->escape($item->siswa?->user?->name).'</td><td>'.$this->escape($item->siswa?->kelas?->nama).'</td><td>'.($item->hadir ? 'Hadir' : 'Tidak hadir').'</td><td class="ttd"></td></tr>';
        }

        $pengawasRows = '';
        foreach ($sesi->pengawasSesi as $index => $penugasan) {
            $pengawasRows .= '<tr><td>'.($index + 1).'</td><td>'.$this->escape($penugasan->guru?->user?->name).'</td><td>'.$this->escape($this->penugasanLain($penugasan->guru_id, $sesi)).'</td><td class="ttd"></td></tr>';
        }
        if ($pengawasRows === '') {
            $pengawasRows = '<tr><td colspan="4">Pengawas belum ditetapkan untuk sesi ini.</td></tr>';
        }

        $body = '<h1>Daftar Hadir Peserta Ujian</h1>'
            .'<table class="info"><tr><td>Ujian</td><td>'.$this->escape($jadwal?->nama).'</td><td>Ruang</td><td>'.$this->escape($sesi->ruang?->nama).'</td></tr>'
            .'<tr><td>Mata pelajaran</td><td>'.$this->escape($jadwal?->mapel?->nama).'</td><td>Sesi</td><td>'.$this->escape($sesi->sesi_ke).'</td></tr>'
            .'<tr><td>Hari / tanggal</td><td>'.$this->escape($tanggal).'</td><td>Waktu</td><td>'.$this->escape($waktu).'</td></tr></table>'
            .'<h2>Pengawas Ruang</h2><table><thead><tr><th>No</th><th>Nama pengawas</th><th>Penugasan lain pada hari yang sama</th><th>Tanda tangan</th></tr></thead><tbody>'.$pengawasRows.'</tbody></table>'
            .'<h2>Peserta ('.$peserta->count().' siswa)</h2><table><thead><tr><th>No</th><th>NIS</th><th>Nama</th><th>Kelas</th><th>Kehadiran</th><th>Tanda tangan</th></tr></thead><tbody>'.$rows.'</tbody></table>'
            .'<p>Hadir: '.$hadir.' &nbsp;|&nbsp; Tidak hadir: '.($peserta->count() - $hadir).'</p>';

        return $body;
    }

    /**
     * Ringkasan penugasan pengawas yang sama di sesi lain pada tanggal yang sama (pengawas dapat
     * berpindah ruang antar mata pelajaran dalam satu hari).
     */
    private function penugasanLain(int $guruId, SesiRuang $sesi): string
    {
        $lain = SesiRuang::query()
            ->with(['ruang', 'jadwalUjian.mapel'])
            ->where('id', '!=', $sesi->id)
            ->whereHas('pengawasSesi', fn ($q) => $q->where('guru_id', $guruId))
            ->whereHas('jadwalUjian', fn ($q) => $q->whereDate('tanggal', $sesi->jadwalUjian?->tanggal))
            ->orderBy('jam_mulai')
            ->get();

        if ($lain->isEmpty()) {
            return '-';
        }

        return $lain->map(fn (SesiRuang $item): string => ($item->jadwalUjian?->mapel?->nama ?? '-')
            .' ('.substr((string) $item->jam_mulai, 0, 5).'-'.substr((string) $item->jam_akhir, 0, 5)
            .', '.($item->ruang?->nama ?? '-').')')->implode('; ');
    }

    private function beritaAcara(LaporanPdf $laporan): string
    {
        $sesi = SesiRuang::query()->with(['ruang', 'jadwalUjian.mapel', 'beritaAcara.pengawas', 'pengawasSesi.guru.user'])
            ->findOrFail($laporan->sesi_ruang_id);
        $berita = $sesi->beritaAcara;
        $body = '<h1>Berita Acara Ujian</h1><p>Ujian: '.$this->escape($sesi->jadwalUjian?->nama).'</p><p>Mapel: '.$this->escape($sesi->jadwalUjian?->mapel?->nama).'</p><p>Ruang: '.$this->escape($sesi->ruang?->nama).' / Sesi '.$this->escape($sesi->sesi_ke).'</p><p>Pengawas bertugas: '.$this->escape($sesi->pengawasSesi->map(fn ($p) => $p->guru?->user?->name)->filter()->implode(', ') ?: '-').'</p><p>Pengisi berita acara: '.$this->escape($berita?->pengawas?->name ?? '-').'</p><p>Jumlah hadir: '.($berita?->jumlah_hadir ?? 0).' — Tidak hadir: '.($berita?->jumlah_absen ?? 0).'</p><h2>Catatan</h2><p>'.nl2br($this->escape($berita?->isi ?? 'Berita acara belum diisi.')).'</p>';

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
        return '<!doctype html><html lang="id"><meta charset="utf-8"><title>'.$this->escape($title).'</title><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}h1,h2{margin-bottom:8px}table{width:100%;border-collapse:collapse;margin-bottom:10px}.info td{border:none;padding:2px 5px}.ttd{width:110px}th,td{border:1px solid #444;padding:5px;text-align:left}.card{border:1px solid #222;padding:16px;margin:0 0 16px;page-break-inside:avoid}.card:not(:last-child){page-break-after:always}.ruang:not(:last-child){page-break-after:always}</style><body>'.$body.'</body></html>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
