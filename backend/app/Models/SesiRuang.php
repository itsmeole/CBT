<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SesiRuang extends Model
{
    use HasFactory;

    protected $table = 'sesi_ruang';

    protected $fillable = [
        'jadwal_ujian_id',
        'ruang_id',
        'sesi_ke',
        'jam_mulai',
        'jam_akhir',
        'jenis',
    ];

    protected function casts(): array
    {
        return [
            'sesi_ke' => 'integer',
        ];
    }

    public function jadwalUjian()
    {
        return $this->belongsTo(JadwalUjian::class);
    }

    public function ruang()
    {
        return $this->belongsTo(Ruang::class);
    }

    public function pengawasSesi()
    {
        return $this->hasMany(PengawasSesi::class);
    }

    public function tokenUjian()
    {
        return $this->hasOne(TokenUjian::class);
    }

    public function pesertaUjian()
    {
        return $this->hasMany(PesertaUjian::class);
    }
}

