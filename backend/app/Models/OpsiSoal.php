<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OpsiSoal extends Model
{
    use HasFactory;

    protected $table = 'opsi_soal';

    protected $fillable = [
        'bank_soal_id',
        'konten',
        'sisi',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    public function bankSoal()
    {
        return $this->belongsTo(BankSoal::class);
    }
}

