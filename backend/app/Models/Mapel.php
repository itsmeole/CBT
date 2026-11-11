<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Mapel extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'mapel';

    protected $fillable = [
        'kode',
        'nama',
        'tingkat',
    ];

    public function guruMapel()
    {
        return $this->hasMany(GuruMapel::class);
    }

    public function guru()
    {
        return $this->belongsToMany(Guru::class, 'guru_mapel', 'mapel_id', 'guru_id')->distinct();
    }

    public function bankSoal()
    {
        return $this->hasMany(BankSoal::class);
    }

    public function paketSoal()
    {
        return $this->hasMany(PaketSoal::class);
    }

    public function jadwalUjian()
    {
        return $this->hasMany(JadwalUjian::class);
    }
}
