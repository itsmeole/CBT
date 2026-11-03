<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Nilai extends Model
{
    use HasFactory;

    protected $table = 'nilai';

    protected $fillable = [
        'peserta_ujian_id',
        'skor_per_tipe',
        'total',
        'maksimal',
        'nilai_akhir',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'skor_per_tipe' => 'array',
            'total' => 'decimal:2',
            'maksimal' => 'decimal:2',
            'nilai_akhir' => 'decimal:2',
        ];
    }

    public function pesertaUjian()
    {
        return $this->belongsTo(PesertaUjian::class);
    }
}

