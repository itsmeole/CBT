<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PaketSoal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'paket_soal';

    protected $fillable = [
        'mapel_id',
        'guru_id',
        'nama',
        'deskripsi',
    ];

    public function mapel()
    {
        return $this->belongsTo(Mapel::class);
    }

    public function guru()
    {
        return $this->belongsTo(Guru::class);
    }

    public function items()
    {
        return $this->hasMany(PaketSoalItem::class)->orderBy('urutan');
    }

    public function jadwalUjian()
    {
        return $this->hasMany(JadwalUjian::class);
    }
}

