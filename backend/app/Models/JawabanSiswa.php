<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class JawabanSiswa extends Model
{
    use HasFactory;

    protected $table = 'jawaban_siswa';

    protected $fillable = [
        'peserta_ujian_id',
        'bank_soal_id',
        'jawaban',
        'client_seq',
        'skor',
        'dikoreksi_oleh',
    ];

    protected function casts(): array
    {
        return [
            'jawaban' => 'array',
            'client_seq' => 'integer',
            'skor' => 'decimal:2',
        ];
    }

    public function pesertaUjian()
    {
        return $this->belongsTo(PesertaUjian::class);
    }

    public function bankSoal()
    {
        return $this->belongsTo(BankSoal::class);
    }

    public function pengoreksi()
    {
        return $this->belongsTo(User::class, 'dikoreksi_oleh');
    }
}

