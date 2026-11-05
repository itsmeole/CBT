<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Guru extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'guru';

    protected $fillable = [
        'user_id',
        'nip',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function guruMapel()
    {
        return $this->hasMany(GuruMapel::class);
    }

    public function mapel()
    {
        return $this->belongsToMany(Mapel::class, 'guru_mapel', 'guru_id', 'mapel_id')->distinct();
    }

    public function bankSoal()
    {
        return $this->hasMany(BankSoal::class);
    }

    public function paketSoal()
    {
        return $this->hasMany(PaketSoal::class);
    }

    public function pengawasSesi()
    {
        return $this->hasMany(PengawasSesi::class);
    }
}

