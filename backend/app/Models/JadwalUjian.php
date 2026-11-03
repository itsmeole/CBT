<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class JadwalUjian extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'jadwal_ujian';

    protected $fillable = [
        'mapel_id',
        'paket_soal_id',
        'tahun_ajaran_id',
        'nama',
        'tanggal',
        'durasi_menit',
        'bobot_snapshot',
        'soal_snapshot',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'durasi_menit' => 'integer',
            'bobot_snapshot' => 'array',
            'soal_snapshot' => 'array',
        ];
    }

    public function mapel()
    {
        return $this->belongsTo(Mapel::class);
    }

    public function paketSoal()
    {
        return $this->belongsTo(PaketSoal::class);
    }

    public function tahunAjaran()
    {
        return $this->belongsTo(TahunAjaran::class);
    }

    public function sesiRuang()
    {
        return $this->hasMany(SesiRuang::class);
    }
}
