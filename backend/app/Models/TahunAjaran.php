<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class TahunAjaran extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'tahun_ajaran';

    protected $fillable = [
        'nama',
        'semester',
        'aktif',
    ];

    protected function casts(): array
    {
        return [
            'semester' => 'integer',
            'aktif' => 'boolean',
        ];
    }

    public function kelas()
    {
        return $this->hasMany(Kelas::class);
    }
}

