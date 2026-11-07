<?php

namespace App\Jobs;

use App\Services\ExcelService;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Tahap 2 impor Excel (FR-BE-11): commit seluruh baris dalam satu transaksi database di antrean.
 * Payload dienkripsi karena baris siswa/guru memuat password awal.
 */
class ImporExcelMasterData implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public string $importId,
        public string $jenis,
        public array $items,
    ) {}

    public static function kunciStatus(string $importId): string
    {
        return 'import-excel:'.$importId;
    }

    public function handle(ExcelService $excelService): void
    {
        $this->perbaruiStatus(['status' => 'berjalan']);

        $jumlah = $excelService->commit($this->jenis, $this->items);

        $this->perbaruiStatus([
            'status' => 'selesai',
            'imported_count' => $jumlah,
            'message' => "Berhasil mengimpor {$jumlah} data {$this->jenis}.",
        ]);
    }

    public function failed(Throwable $exception): void
    {
        $this->perbaruiStatus(['status' => 'gagal', 'message' => $exception->getMessage()]);
    }

    /**
     * @param  array<string, mixed>  $perubahan
     */
    private function perbaruiStatus(array $perubahan): void
    {
        $kunci = self::kunciStatus($this->importId);
        $lama = Cache::get($kunci, []);

        Cache::put($kunci, array_merge($lama, $perubahan), now()->addHour());
    }
}
