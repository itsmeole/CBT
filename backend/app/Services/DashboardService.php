<?php

namespace App\Services;

use App\Models\Guru;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\Kelas;
use App\Models\LaporanPdf;
use App\Models\LogAktivitas;
use App\Models\Mapel;
use App\Models\Pengaturan;
use App\Models\PesertaUjian;
use App\Models\SesiRuang;
use App\Models\Siswa;
use App\Models\TahunAjaran;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ringkasan dashboard staf yang digerakkan tindakan: apa yang perlu diurus sekarang menurut peran.
 * Semua agregasi memakai jumlah query yang tetap (tidak tumbuh bersama jumlah siswa/sesi).
 */
class DashboardService
{
    /** Cakupan hari ke depan untuk pemeriksaan kesiapan ujian. */
    private const HARI_KESIAPAN = 14;

    /** Jumlah baris maksimal pada daftar "terbaru" di dashboard guru (antrean koreksi dan hasil ujian). */
    private const BATAS_DAFTAR = 5;

    public function sekarang(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.timezone_sekolah'));
    }

    // ------------------------------------------------------------------
    // Operator
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function operator(): array
    {
        $hariIni = $this->sekarang()->toDateString();
        $sesi = $this->sesiHariIni($hariIni);

        return [
            'hari_ini' => $sesi->map(fn (SesiRuang $item): array => $this->ringkasSesi($item))->values()->all(),
            'langsung' => $this->ringkasanLangsung($sesi),
            'kesiapan' => $this->kesiapan($hariIni),
            'perlu_tindakan' => $this->perluTindakan($sesi),
        ];
    }

    /**
     * @return Collection<int, SesiRuang>
     */
    private function sesiHariIni(string $hariIni): Collection
    {
        return SesiRuang::query()
            ->with([
                'jadwalUjian.mapel',
                'ruang',
                'tokenUjian',
                'pengawasSesi.guru.user:id,name',
            ])
            ->withCount([
                'pesertaUjian as total',
                'pesertaUjian as hadir' => fn ($q) => $q->where('hadir', true),
                'pesertaUjian as menunggu' => fn ($q) => $q->where('status', 'menunggu'),
                'pesertaUjian as mengerjakan' => fn ($q) => $q->where('status', 'mengerjakan'),
                'pesertaUjian as terkunci' => fn ($q) => $q->where('status', 'terkunci'),
                'pesertaUjian as selesai' => fn ($q) => $q->where('status', 'selesai'),
            ])
            ->whereHas('jadwalUjian', fn ($q) => $q->whereDate('tanggal', $hariIni))
            ->orderBy('jam_mulai')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return array<string, mixed>
     */
    private function ringkasSesi(SesiRuang $sesi): array
    {
        $total = (int) $sesi->total;
        $status = ($sesi->mengerjakan + $sesi->terkunci) > 0
            ? 'berjalan'
            : ($total > 0 && (int) $sesi->selesai === $total ? 'selesai' : 'belum_mulai');

        return [
            'id' => $sesi->id,
            'jadwal' => $sesi->jadwalUjian?->nama,
            'mapel' => $sesi->jadwalUjian?->mapel?->nama,
            'ruang' => $sesi->ruang?->nama,
            'sesi_ke' => $sesi->sesi_ke,
            'jam_mulai' => substr((string) $sesi->jam_mulai, 0, 5),
            'jam_akhir' => substr((string) $sesi->jam_akhir, 0, 5),
            'token' => $sesi->tokenUjian?->kode,
            'pengawas' => $sesi->pengawasSesi->map(fn ($p) => $p->guru?->user?->name)->filter()->values()->all(),
            'peserta' => [
                'total' => $total,
                'hadir' => (int) $sesi->hadir,
                'menunggu' => (int) $sesi->menunggu,
                'mengerjakan' => (int) $sesi->mengerjakan,
                'terkunci' => (int) $sesi->terkunci,
                'selesai' => (int) $sesi->selesai,
            ],
            'status' => $status,
        ];
    }

    /**
     * @param  Collection<int, SesiRuang>  $sesi
     * @return array<string, int>
     */
    private function ringkasanLangsung(Collection $sesi): array
    {
        return [
            'sesi_hari_ini' => $sesi->count(),
            'sesi_berjalan' => $sesi->filter(fn (SesiRuang $s): bool => ($s->mengerjakan + $s->terkunci) > 0)->count(),
            'peserta' => (int) $sesi->sum('total'),
            'menunggu' => (int) $sesi->sum('menunggu'),
            'mengerjakan' => (int) $sesi->sum('mengerjakan'),
            'terkunci' => (int) $sesi->sum('terkunci'),
            'selesai' => (int) $sesi->sum('selesai'),
        ];
    }

    /**
     * Pemeriksaan kesiapan jadwal dalam beberapa hari ke depan.
     *
     * @return list<array<string, mixed>>
     */
    private function kesiapan(string $hariIni): array
    {
        $batas = CarbonImmutable::parse($hariIni)->addDays(self::HARI_KESIAPAN)->toDateString();
        $jadwal = JadwalUjian::query()
            ->with([
                'mapel',
                'sesiRuang' => fn ($sesi) => $sesi->withCount(['pesertaUjian', 'pengawasSesi'])->orderBy('sesi_ke'),
                'sesiRuang.tokenUjian',
            ])
            ->whereDate('tanggal', '>=', $hariIni)
            ->whereDate('tanggal', '<=', $batas)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->limit(20)
            ->get();

        if ($jadwal->isEmpty()) {
            return [];
        }

        $belumDitempatkan = $this->siswaBelumDitempatkan($jadwal);

        return $jadwal->map(function (JadwalUjian $item) use ($belumDitempatkan): array {
            $masalah = [];
            if ($item->sesiRuang->isEmpty()) {
                $masalah[] = ['kode' => 'tanpa_penempatan', 'pesan' => 'Belum ada penempatan peserta ke ruang.', 'jumlah' => 1];
            }
            $tanpaPengawas = $item->sesiRuang->filter(fn (SesiRuang $s): bool => $s->pengawas_sesi_count === 0)->count();
            if ($tanpaPengawas > 0) {
                $masalah[] = ['kode' => 'tanpa_pengawas', 'pesan' => "{$tanpaPengawas} sesi belum punya pengawas.", 'jumlah' => $tanpaPengawas];
            }
            $kosong = $item->sesiRuang->filter(fn (SesiRuang $s): bool => $s->peserta_ujian_count === 0)->count();
            if ($kosong > 0) {
                $masalah[] = ['kode' => 'sesi_kosong', 'pesan' => "{$kosong} sesi belum berisi peserta.", 'jumlah' => $kosong];
            }
            $tanpaToken = $item->sesiRuang->filter(fn (SesiRuang $s): bool => $s->tokenUjian === null)->count();
            if ($tanpaToken > 0) {
                $masalah[] = ['kode' => 'tanpa_token', 'pesan' => "{$tanpaToken} sesi belum punya token aktif.", 'jumlah' => $tanpaToken];
            }
            $belum = $belumDitempatkan[$item->id] ?? 0;
            if ($belum > 0 && $item->sesiRuang->isNotEmpty()) {
                $masalah[] = ['kode' => 'belum_ditempatkan', 'pesan' => "{$belum} siswa kelas pengampu belum ditempatkan di ruang.", 'jumlah' => $belum];
            }

            return [
                'jadwal_id' => $item->id,
                'nama' => $item->nama,
                'mapel' => $item->mapel?->nama,
                'tanggal' => $item->tanggal?->toDateString(),
                'jumlah_sesi' => $item->sesiRuang->count(),
                'masalah' => $masalah,
                'siap' => $masalah === [],
            ];
        })->values()->all();
    }

    /**
     * Siswa pada kelas yang diampu untuk mapel jadwal tetapi belum ditempatkan di ruang manapun pada jadwal itu.
     * Kelas peserta diturunkan dari data pengampu mapel (guru_mapel) pada tahun ajaran jadwal.
     *
     * @param  Collection<int, JadwalUjian>  $jadwal
     * @return array<int, int>
     */
    private function siswaBelumDitempatkan(Collection $jadwal): array
    {
        $kelasPerKunci = GuruMapel::query()
            ->whereIn('mapel_id', $jadwal->pluck('mapel_id')->unique())
            ->whereIn('tahun_ajaran_id', $jadwal->pluck('tahun_ajaran_id')->unique())
            ->whereNotNull('kelas_id')
            ->get(['mapel_id', 'tahun_ajaran_id', 'kelas_id'])
            ->groupBy(fn (GuruMapel $g): string => $g->mapel_id.'|'.$g->tahun_ajaran_id)
            ->map(fn (Collection $g): array => $g->pluck('kelas_id')->unique()->values()->all());

        $semuaKelas = $kelasPerKunci->flatten()->unique()->values();
        if ($semuaKelas->isEmpty()) {
            return [];
        }

        $siswaPerKelas = Siswa::query()->whereIn('kelas_id', $semuaKelas)
            ->select('kelas_id', DB::raw('count(*) as jumlah'))->groupBy('kelas_id')->pluck('jumlah', 'kelas_id');

        $ditempatkan = DB::table('peserta_ujian as pu')
            ->join('sesi_ruang as sr', 'sr.id', '=', 'pu.sesi_ruang_id')
            ->join('siswa as s', 's.id', '=', 'pu.siswa_id')
            ->whereIn('sr.jadwal_ujian_id', $jadwal->pluck('id'))
            ->select('sr.jadwal_ujian_id as jadwal_id', 's.kelas_id', DB::raw('count(distinct s.id) as jumlah'))
            ->groupBy('sr.jadwal_ujian_id', 's.kelas_id')
            ->get()
            ->groupBy('jadwal_id');

        $hasil = [];
        foreach ($jadwal as $item) {
            $kelas = $kelasPerKunci[$item->mapel_id.'|'.$item->tahun_ajaran_id] ?? [];
            if ($kelas === []) {
                continue;
            }
            $sudah = ($ditempatkan[$item->id] ?? collect())->pluck('jumlah', 'kelas_id');
            $kurang = 0;
            foreach ($kelas as $kelasId) {
                $kurang += max(0, (int) ($siswaPerKelas[$kelasId] ?? 0) - (int) ($sudah[$kelasId] ?? 0));
            }
            $hasil[$item->id] = $kurang;
        }

        return $hasil;
    }

    /**
     * @param  Collection<int, SesiRuang>  $sesi
     * @return list<array<string, mixed>>
     */
    private function perluTindakan(Collection $sesi): array
    {
        $item = [];
        $aktif = $sesi->filter(fn (SesiRuang $s): bool => (int) $s->selesai !== (int) $s->total || $s->total === 0);

        $belumHadir = (int) $aktif->sum(fn (SesiRuang $s): int => max(0, $s->total - $s->hadir));
        if ($belumHadir > 0) {
            $item[] = ['kode' => 'kehadiran', 'pesan' => "{$belumHadir} siswa hari ini belum diverifikasi hadir.", 'jumlah' => $belumHadir, 'tautan' => '/staf/kontrol-sesi'];
        }

        $terkunci = (int) $sesi->sum('terkunci');
        if ($terkunci > 0) {
            $item[] = ['kode' => 'terkunci', 'pesan' => "{$terkunci} siswa sedang terkunci dan menunggu dibuka pengawas.", 'jumlah' => $terkunci, 'tautan' => '/staf/monitoring'];
        }

        $ambang = (int) (Pengaturan::query()->where('key', 'ambang_kunci')->value('value') ?? 3);
        $lewatAmbang = $sesi->isEmpty() ? 0 : PesertaUjian::query()
            ->whereIn('sesi_ruang_id', $sesi->pluck('id'))
            ->where('status', '!=', 'selesai')
            ->where('jumlah_kunci', '>=', $ambang)
            ->count();
        if ($lewatAmbang > 0) {
            $item[] = ['kode' => 'ambang_kunci', 'pesan' => "{$lewatAmbang} siswa sudah terkunci {$ambang} kali atau lebih. Periksa langsung.", 'jumlah' => $lewatAmbang, 'tautan' => '/staf/monitoring'];
        }

        $pdfGagal = LaporanPdf::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(2))->count();
        if ($pdfGagal > 0) {
            $item[] = ['kode' => 'pdf_gagal', 'pesan' => "{$pdfGagal} dokumen PDF gagal dibuat dalam 2 hari terakhir.", 'jumlah' => $pdfGagal, 'tautan' => '/staf/cetak'];
        }

        return $item;
    }

    // ------------------------------------------------------------------
    // Guru
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function guru(User $user): array
    {
        $guruId = (int) ($user->guru?->id ?? 0);
        $hariIni = $this->sekarang()->toDateString();
        $mapelIds = GuruMapel::query()->where('guru_id', $guruId)->pluck('mapel_id')->unique()->values();

        return [
            'koreksi' => $this->antreanKoreksi($guruId),
            'pengawasan' => $this->pengawasan($guruId, $hariIni),
            'jadwal_saya' => $this->jadwalSaya($mapelIds, $hariIni),
            'hasil_terbaru' => $this->hasilTerbaru($guruId, $hariIni),
        ];
    }

    /**
     * Jawaban essay yang sudah dikumpulkan siswa pada kelas/mapel yang diampu dan belum diberi skor.
     * `total` dan `jumlah_jadwal` mencakup semua data; `per_jadwal` hanya {@see self::BATAS_DAFTAR} jadwal terbaru.
     *
     * @return array{total: int, jumlah_jadwal: int, per_jadwal: list<array<string, mixed>>}
     */
    private function antreanKoreksi(int $guruId): array
    {
        $baris = DB::table('jawaban_siswa as js')
            ->join('peserta_ujian as pu', 'pu.id', '=', 'js.peserta_ujian_id')
            ->join('sesi_ruang as sr', 'sr.id', '=', 'pu.sesi_ruang_id')
            ->join('jadwal_ujian as ju', 'ju.id', '=', 'sr.jadwal_ujian_id')
            ->join('mapel as m', 'm.id', '=', 'ju.mapel_id')
            ->join('bank_soal as bs', 'bs.id', '=', 'js.bank_soal_id')
            ->join('siswa as s', 's.id', '=', 'pu.siswa_id')
            ->join('guru_mapel as gm', function ($join) use ($guruId): void {
                $join->on('gm.mapel_id', '=', 'ju.mapel_id')
                    ->on('gm.tahun_ajaran_id', '=', 'ju.tahun_ajaran_id')
                    ->on('gm.kelas_id', '=', 's.kelas_id')
                    ->where('gm.guru_id', '=', $guruId);
            })
            ->where('bs.tipe', 'essay')
            ->where('pu.status', 'selesai')
            ->whereNull('js.dikoreksi_oleh')
            ->whereNotNull('js.jawaban')
            ->groupBy('ju.id', 'ju.nama', 'm.nama', 'ju.tanggal')
            ->orderByDesc('ju.tanggal')
            ->orderByDesc('ju.id')
            ->select('ju.id as jadwal_id', 'ju.nama', 'm.nama as mapel', 'ju.tanggal', DB::raw('count(*) as menunggu'))
            ->get();

        return [
            'total' => (int) $baris->sum('menunggu'),
            'jumlah_jadwal' => $baris->count(),
            'per_jadwal' => $baris->take(self::BATAS_DAFTAR)->map(fn ($b): array => [
                'jadwal_id' => (int) $b->jadwal_id,
                'nama' => $b->nama,
                'mapel' => $b->mapel,
                'tanggal' => substr((string) $b->tanggal, 0, 10),
                'menunggu' => (int) $b->menunggu,
            ])->values()->all(),
        ];
    }

    /**
     * @return array{sesi: list<array<string, mixed>>}
     */
    private function pengawasan(int $guruId, string $hariIni): array
    {
        $sesi = SesiRuang::query()
            ->with(['jadwalUjian.mapel', 'ruang'])
            ->whereHas('pengawasSesi', fn ($q) => $q->where('guru_id', $guruId))
            ->whereHas('jadwalUjian', fn ($q) => $q->whereDate('tanggal', '>=', $hariIni))
            ->get()
            ->sortBy(fn (SesiRuang $s): string => $s->jadwalUjian?->tanggal?->toDateString().' '.$s->jam_mulai)
            ->take(8)
            ->values();

        return [
            'sesi' => $sesi->map(fn (SesiRuang $s): array => [
                'id' => $s->id,
                'jadwal' => $s->jadwalUjian?->nama,
                'mapel' => $s->jadwalUjian?->mapel?->nama,
                'ruang' => $s->ruang?->nama,
                'tanggal' => $s->jadwalUjian?->tanggal?->toDateString(),
                'sesi_ke' => $s->sesi_ke,
                'jam_mulai' => substr((string) $s->jam_mulai, 0, 5),
                'jam_akhir' => substr((string) $s->jam_akhir, 0, 5),
                'hari_ini' => $s->jadwalUjian?->tanggal?->toDateString() === $hariIni,
            ])->all(),
        ];
    }

    /**
     * @param  Collection<int, int>  $mapelIds
     * @return list<array<string, mixed>>
     */
    private function jadwalSaya(Collection $mapelIds, string $hariIni): array
    {
        if ($mapelIds->isEmpty()) {
            return [];
        }

        return JadwalUjian::query()
            ->with(['mapel', 'paketSoal.items'])
            ->whereIn('mapel_id', $mapelIds)
            ->whereDate('tanggal', '>=', $hariIni)
            ->orderBy('tanggal')
            ->orderBy('id')
            ->limit(5)
            ->get()
            ->map(fn (JadwalUjian $j): array => [
                'jadwal_id' => $j->id,
                'nama' => $j->nama,
                'mapel' => $j->mapel?->nama,
                'tanggal' => $j->tanggal?->toDateString(),
                'paket' => $j->paketSoal?->nama,
                'jumlah_butir' => $j->paketSoal?->items->count() ?? 0,
            ])->all();
    }

    /**
     * Jadwal terbaru (maksimal {@see self::BATAS_DAFTAR}) yang sudah memiliki nilai pada kelas/mapel yang diampu.
     *
     * @return list<array<string, mixed>>
     */
    private function hasilTerbaru(int $guruId, string $hariIni): array
    {
        return DB::table('nilai as n')
            ->join('peserta_ujian as pu', 'pu.id', '=', 'n.peserta_ujian_id')
            ->join('sesi_ruang as sr', 'sr.id', '=', 'pu.sesi_ruang_id')
            ->join('jadwal_ujian as ju', 'ju.id', '=', 'sr.jadwal_ujian_id')
            ->join('mapel as m', 'm.id', '=', 'ju.mapel_id')
            ->join('siswa as s', 's.id', '=', 'pu.siswa_id')
            ->join('guru_mapel as gm', function ($join) use ($guruId): void {
                $join->on('gm.mapel_id', '=', 'ju.mapel_id')
                    ->on('gm.tahun_ajaran_id', '=', 'ju.tahun_ajaran_id')
                    ->on('gm.kelas_id', '=', 's.kelas_id')
                    ->where('gm.guru_id', '=', $guruId);
            })
            ->whereDate('ju.tanggal', '<=', $hariIni)
            ->groupBy('ju.id', 'ju.nama', 'm.nama', 'ju.tanggal')
            ->orderByDesc('ju.tanggal')
            ->orderByDesc('ju.id')
            ->limit(self::BATAS_DAFTAR)
            ->select(
                'ju.id as jadwal_id',
                'ju.nama',
                'm.nama as mapel',
                'ju.tanggal',
                DB::raw("sum(case when n.status = 'final' then 1 else 0 end) as jml_final"),
                DB::raw("sum(case when n.status = 'final' then 0 else 1 end) as jml_menunggu"),
                DB::raw("avg(case when n.status = 'final' then n.nilai_akhir end) as rata_rata")
            )
            ->get()
            ->map(fn ($b): array => [
                'jadwal_id' => (int) $b->jadwal_id,
                'nama' => $b->nama,
                'mapel' => $b->mapel,
                'tanggal' => substr((string) $b->tanggal, 0, 10),
                'final' => (int) $b->jml_final,
                'menunggu' => (int) $b->jml_menunggu,
                'rata_rata' => $b->rata_rata === null ? null : round((float) $b->rata_rata, 1),
            ])->all();
    }

    // ------------------------------------------------------------------
    // Superadmin
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function sistem(): array
    {
        return [
            'kesehatan' => $this->kesehatan(),
            'aktivitas' => LogAktivitas::query()
                ->with('aktor:id,name,username,role')
                ->latest('id')
                ->limit(8)
                ->get()
                ->map(fn (LogAktivitas $log): array => [
                    'id' => $log->id,
                    'aksi' => $log->aksi,
                    'aktor' => $log->aktor?->name,
                    'peran' => $log->aktor?->role,
                    'waktu' => $log->created_at?->toIso8601String(),
                ])->all(),
            'kualitas_data' => $this->kualitasData(),
            'pengguna' => $this->pengguna(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kesehatan(): array
    {
        $antrean = null;
        try {
            $antrean = Queue::size('default') + Queue::size('reports');
        } catch (Throwable) {
            // Driver antrean tidak mendukung penghitungan ukuran.
        }

        $backupTerakhir = null;
        try {
            $disk = Storage::disk(config('backup.disk'));
            $waktu = collect($disk->files(trim((string) config('backup.path'), '/')))
                ->map(fn (string $berkas): int => $disk->lastModified($berkas))
                ->max();
            $backupTerakhir = $waktu ? CarbonImmutable::createFromTimestamp($waktu)->toIso8601String() : null;
        } catch (Throwable) {
            // Disk backup belum dikonfigurasi atau belum ada berkas.
        }

        return [
            'antrean_menunggu' => $antrean,
            'job_gagal' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
            'pdf_gagal_7_hari' => LaporanPdf::query()->where('status', 'failed')->where('created_at', '>=', now()->subDays(7))->count(),
            'backup_terakhir' => $backupTerakhir,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kualitasData(): array
    {
        $tahun = TahunAjaran::query()->where('aktif', true)->first();

        return [
            'tahun_ajaran_aktif' => $tahun ? ['nama' => $tahun->nama, 'semester' => $tahun->semester] : null,
            'guru_tanpa_pengampu' => Guru::query()->whereDoesntHave('guruMapel')->count(),
            'mapel_tanpa_pengampu' => Mapel::query()->whereDoesntHave('guruMapel')->count(),
            'kelas_tanpa_siswa' => Kelas::query()->whereDoesntHave('siswa')->count(),
            'akun_nonaktif' => User::query()->where('aktif', false)->count(),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function pengguna(): array
    {
        $perRole = User::query()->select('role', DB::raw('count(*) as jumlah'))->groupBy('role')->pluck('jumlah', 'role');

        return [
            'superadmin' => (int) ($perRole['superadmin'] ?? 0),
            'operator' => (int) ($perRole['operator'] ?? 0),
            'guru' => (int) ($perRole['guru'] ?? 0),
            'siswa' => (int) ($perRole['siswa'] ?? 0),
        ];
    }
}
