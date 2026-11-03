<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BobotTipe extends Model
{
    use HasFactory;

    protected $table = 'bobot_tipe';

    protected $fillable = [
        'tipe',
        'bobot',
    ];

    protected function casts(): array
    {
        return [
            'bobot' => 'decimal:2',
        ];
    }
}

