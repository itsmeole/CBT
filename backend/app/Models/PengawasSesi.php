<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PengawasSesi extends Model
{
    use HasFactory;

    protected $table = 'pengawas_sesi';

    protected $fillable = [
        'sesi_ruang_id',
        'guru_id',
    ];

    public function sesiRuang()
    {
        return $this->belongsTo(SesiRuang::class);
    }

    public function guru()
    {
        return $this->belongsTo(Guru::class);
    }
}

