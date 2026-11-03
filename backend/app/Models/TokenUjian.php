<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TokenUjian extends Model
{
    use HasFactory;

    protected $table = 'token_ujian';

    protected $fillable = [
        'sesi_ruang_id',
        'kode',
        'aktif',
        'dibuat_oleh',
    ];

    protected function casts(): array
    {
        return [
            'aktif' => 'boolean',
        ];
    }

    public function sesiRuang()
    {
        return $this->belongsTo(SesiRuang::class);
    }

    public function pembuat()
    {
        return $this->belongsTo(User::class, 'dibuat_oleh');
    }
}

