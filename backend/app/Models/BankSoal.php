<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class BankSoal extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'bank_soal';

    protected $fillable = [
        'guru_id',
        'mapel_id',
        'tipe',
        'konten',
        'kunci',
        'rubrik',
        'versi',
    ];

    protected function casts(): array
    {
        return [
            'kunci' => 'array',
            'versi' => 'integer',
        ];
    }

    public function guru()
    {
        return $this->belongsTo(Guru::class);
    }

    public function mapel()
    {
        return $this->belongsTo(Mapel::class);
    }

    public function opsiSoal()
    {
        return $this->hasMany(OpsiSoal::class)->orderBy('urutan');
    }

    public function paketSoalItem()
    {
        return $this->hasMany(PaketSoalItem::class);
    }

    public function jawabanSiswa()
    {
        return $this->hasMany(JawabanSiswa::class);
    }
}

