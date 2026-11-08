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

    /**
     * Token AKTIF terbaru sesi ini (null bila belum ada). Token lama yang sudah dinonaktifkan
     * (mis. setelah rilis token baru) tidak boleh ikut tampil.
     */
    public function tokenUjian()
    {
        return $this->hasOne(TokenUjian::class)->ofMany(['id' => 'max'], function ($query): void {
            $query->where('aktif', true);
        });
    }

    public function pesertaUjian()
    {
        return $this->hasMany(PesertaUjian::class);
    }

    public function beritaAcara()
    {
        return $this->hasOne(BeritaAcara::class);
    }
}
