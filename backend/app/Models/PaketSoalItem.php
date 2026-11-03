<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaketSoalItem extends Model
{
    use HasFactory;

    protected $table = 'paket_soal_item';

    protected $fillable = [
        'paket_soal_id',
        'bank_soal_id',
        'urutan',
    ];

    protected function casts(): array
    {
        return [
            'urutan' => 'integer',
        ];
    }

    public function paketSoal()
    {
        return $this->belongsTo(PaketSoal::class);
    }

    public function bankSoal()
    {
        return $this->belongsTo(BankSoal::class);
    }
}

