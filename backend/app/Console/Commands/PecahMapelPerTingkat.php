<?php

namespace App\Console\Commands;

use App\Models\BankSoal;
use App\Models\GuruMapel;
use App\Models\JadwalUjian;
use App\Models\Mapel;
use App\Models\PaketSoal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PecahMapelPerTingkat extends Command
{
    protected $signature = 'app:pecah-mapel-per-tingkat {--dry-run : Hanya tampilkan rencana}';

    protected $description = 'Memecah mapel "semua tingkat" menjadi mapel per tingkat sesuai kelas yang diampu guru.';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $dibuat = 0;
        $dipindah = 0;

        foreach (Mapel::query()->whereNull('tingkat')->orderBy('id')->get() as $mapel) {
            $tingkat = GuruMapel::query()
                ->where('guru_mapel.mapel_id', $mapel->id)
                ->join('kelas', 'kelas.id', '=', 'guru_mapel.kelas_id')
                ->distinct()->orderBy('kelas.tingkat')->pluck('kelas.tingkat');
            if ($tingkat->isEmpty()) {
                continue;
            }

            // Soal, paket, dan jadwal lama tidak diketahui tingkatnya: mapel lama dipertahankan agar tetap dapat diakses.
            $adaIsi = BankSoal::where('mapel_id', $mapel->id)->exists()
                || PaketSoal::where('mapel_id', $mapel->id)->exists()
                || JadwalUjian::where('mapel_id', $mapel->id)->exists();

            $this->line("{$mapel->kode}: tingkat ".$tingkat->implode(', ').($adaIsi ? ' (mapel lama dipertahankan karena sudah punya soal/jadwal)' : ''));
            if ($dry) {
                continue;
            }

            DB::transaction(function () use ($mapel, $tingkat, $adaIsi, &$dibuat, &$dipindah): void {
                foreach ($tingkat as $t) {
                    $baru = Mapel::firstOrCreate(
                        ['kode' => $mapel->kode.$t],
                        ['nama' => $mapel->nama.' Kelas '.$t, 'tingkat' => (string) $t],
                    );
                    $dibuat += $baru->wasRecentlyCreated ? 1 : 0;

                    $baris = GuruMapel::where('mapel_id', $mapel->id)
                        ->whereIn('kelas_id', fn ($q) => $q->select('id')->from('kelas')->where('tingkat', $t))
                        ->get();
                    foreach ($baris as $gm) {
                        if ($adaIsi) {
                            GuruMapel::firstOrCreate([
                                'guru_id' => $gm->guru_id, 'mapel_id' => $baru->id,
                                'kelas_id' => $gm->kelas_id, 'tahun_ajaran_id' => $gm->tahun_ajaran_id,
                            ]);
                        } else {
                            $gm->update(['mapel_id' => $baru->id]);
                        }
                        $dipindah++;
                    }
                }
                if (! $adaIsi) {
                    $mapel->delete();
                }
            });
        }

        $this->info($dry ? 'Dry-run selesai.' : "Selesai: {$dibuat} mapel per tingkat dibuat, {$dipindah} pengampu disesuaikan.");

        return self::SUCCESS;
    }
}
