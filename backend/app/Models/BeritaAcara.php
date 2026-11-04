<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BeritaAcara extends Model
{
    protected $table = 'berita_acara';

    protected $fillable = [
        'sesi_ruang_id',
        'pengawas_id',
        'isi',
        'jumlah_hadir',
        'jumlah_absen',
    ];

    protected function casts(): array
    {
        return [
            'jumlah_hadir' => 'integer',
            'jumlah_absen' => 'integer',
        ];
    }

    public function sesiRuang()
    {
        return $this->belongsTo(SesiRuang::class);
    }

    public function pengawas()
    {
        return $this->belongsTo(User::class, 'pengawas_id');
    }
}
