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
            'daftar-hadir' => $this->daftarHadir($laporan),
            'daftar-hadir-semua' => $this->daftarHadirSemuaRuang($laporan),
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

        // Ruang yang dipakai bersama beberapa ujian pada jam yang sama hanya dicetak sekali.
        $sudah = [];
        $bagian = [];
        foreach ($sesiList as $sesi) {
            $kunci = $sesi->ruang_id.'|'.$sesi->jam_mulai.'|'.$sesi->jam_akhir;
            if (isset($sudah[$kunci])) {
                continue;
            }
            $sudah[$kunci] = true;
            $bagian[] = '<section class="ruang">'.$this->isiDaftarHadir($sesi).'</section>';
        }

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

    /** Isi daftar hadir satu ruang: judul, keterangan singkat, dan tabel nama siswa di ruang itu untuk ditandatangani. */
    private function isiDaftarHadir(SesiRuang $sesi): string
    {
        $jadwal = $sesi->jadwalUjian;
        $tanggal = $jadwal?->tanggal?->locale('id')->translatedFormat('l, d F Y') ?? '-';
        $waktu = substr((string) $sesi->jam_mulai, 0, 5).' - '.substr((string) $sesi->jam_akhir, 0, 5);
        $pengawas = $sesi->pengawasSesi->map(fn ($p) => $p->guru?->user?->name)->filter()->implode(', ');

        // Ujian lain yang berbagi ruang dan jam yang sama (kelas/mapel berbeda) digabung dalam satu lembar.
        $serumpun = SesiRuang::query()
            ->with($this->relasiDaftarHadir())
            ->where('ruang_id', $sesi->ruang_id)
            ->where('jam_mulai', $sesi->jam_mulai)
            ->where('jam_akhir', $sesi->jam_akhir)
            ->whereHas('jadwalUjian', fn ($j) => $j->whereDate('tanggal', $jadwal?->tanggal))
            ->orderBy('id')
            ->get();
        if ($serumpun->isEmpty()) {
            $serumpun = collect([$sesi]);
        }
        $gabungan = $serumpun->count() > 1;
        $pengawas = $serumpun->flatMap(fn (SesiRuang $s) => $s->pengawasSesi->map(fn ($p) => $p->guru?->user?->name))->filter()->unique()->implode(', ');

        $baris = $serumpun->flatMap(fn (SesiRuang $s) => $s->pesertaUjian->map(fn (PesertaUjian $item): array => ['peserta' => $item, 'mapel' => $s->jadwalUjian?->mapel?->nama]));
        $baris = $baris->sortBy(fn (array $b): string => ($b['peserta']->siswa?->kelas?->nama ?? '').'|'.($b['peserta']->siswa?->user?->name ?? ''))->values();

        $rows = '';
        foreach ($baris as $index => $b) {
            $item = $b['peserta'];
            $rows .= '<tr><td>'.($index + 1).'</td><td>'.$this->escape($item->siswa?->nis).'</td><td>'.$this->escape($item->siswa?->user?->name).'</td><td>'.$this->escape($item->siswa?->kelas?->nama).'</td>'
                .($gabungan ? '<td>'.$this->escape($b['mapel']).'</td>' : '').'<td class="ttd"></td></tr>';
        }
        if ($rows === '') {
            $rows = '<tr><td colspan="'.($gabungan ? 6 : 5).'">Belum ada siswa yang ditempatkan di ruang ini.</td></tr>';
        }
        $namaUjian = $gabungan
            ? $serumpun->map(fn (SesiRuang $s): string => ($s->jadwalUjian?->mapel?->nama ?? '-'))->unique()->implode(', ')
            : ($jadwal?->nama.' - '.$jadwal?->mapel?->nama);

        return '<h1>Daftar Hadir Peserta Ujian</h1>'
            .'<table class="info"><tr><td>Ujian</td><td>'.$this->escape($namaUjian).'</td><td>Ruang</td><td>'.$this->escape($sesi->ruang?->nama).'</td></tr>'
            .'<tr><td>Hari / tanggal</td><td>'.$this->escape($tanggal).'</td><td>Waktu</td><td>'.$this->escape($waktu).'</td></tr>'
            .'<tr><td>Pengawas</td><td colspan="3">'.$this->escape($pengawas !== '' ? $pengawas : '-').'</td></tr></table>'
            .'<table class="daftar"><thead><tr><th class="no">No</th><th>NIS</th><th>Nama</th><th>Kelas</th>'.($gabungan ? '<th>Mapel</th>' : '').'<th class="ttd">Tanda tangan</th></tr></thead><tbody>'.$rows.'</tbody></table>';
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
        return '<!doctype html><html lang="id"><meta charset="utf-8"><title>'.$this->escape($title).'</title><style>body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#111}h1,h2{margin-bottom:8px}table{width:100%;border-collapse:collapse;margin-bottom:10px}.info td{border:none;padding:2px 5px}.ttd{width:150px}.no{width:30px}thead{display:table-header-group}tr{page-break-inside:avoid}.daftar td{height:20px}th,td{border:1px solid #444;padding:5px;text-align:left}.card{border:1px solid #222;padding:16px;margin:0 0 16px;page-break-inside:avoid}.card:not(:last-child){page-break-after:always}.ruang:not(:last-child){page-break-after:always}</style><body>'.$body.'</body></html>';
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
