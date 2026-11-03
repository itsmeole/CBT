<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LockEvent extends Model
{
    use HasFactory;

    protected $table = 'lock_event';

    protected $fillable = [
        'peserta_ujian_id',
        'pemicu',
        'waktu_kunci',
        'waktu_buka',
        'dibuka_oleh',
        'alasan',
        'user_agent',
    ];

    protected function casts(): array
    {
        return [
            'waktu_kunci' => 'datetime',
            'waktu_buka' => 'datetime',
        ];
    }

    public function pesertaUjian()
    {
        return $this->belongsTo(PesertaUjian::class);
    }

    public function pembuka()
    {
        return $this->belongsTo(User::class, 'dibuka_oleh');
    }
}

