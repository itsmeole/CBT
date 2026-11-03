<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PesertaUjian extends Model
{
    use HasFactory;

    protected $table = 'peserta_ujian';

    protected $fillable = [
        'siswa_id',
        'sesi_ruang_id',
        'status',
        'hadir',
        'waktu_mulai',
        'waktu_selesai',
        'urutan_soal',
        'urutan_opsi',
        'jumlah_kunci',
        'last_client_seq',
    ];

    protected function casts(): array
    {
        return [
            'hadir' => 'boolean',
            'waktu_mulai' => 'datetime',
            'waktu_selesai' => 'datetime',
            'urutan_soal' => 'array',
            'urutan_opsi' => 'array',
            'jumlah_kunci' => 'integer',
            'last_client_seq' => 'integer',
        ];
    }

    public function siswa()
    {
        return $this->belongsTo(Siswa::class);
    }

    public function sesiRuang()
    {
        return $this->belongsTo(SesiRuang::class);
    }

    public function jawaban()
    {
        return $this->hasMany(JawabanSiswa::class);
    }

    public function nilai()
    {
        return $this->hasOne(Nilai::class);
    }

    public function lockEvents()
    {
        return $this->hasMany(LockEvent::class);
    }
}

