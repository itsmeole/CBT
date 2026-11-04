<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LogAktivitas extends Model
{
    protected $table = 'log_aktivitas';

    public $timestamps = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function aktor()
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
