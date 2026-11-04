<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LaporanPdf extends Model
{
    protected $table = 'laporan_pdf';

    protected $fillable = [
        'diminta_oleh',
        'jenis',
        'jadwal_ujian_id',
        'sesi_ruang_id',
        'kelas_id',
        'status',
        'file_path',
    ];

    public function peminta()
    {
        return $this->belongsTo(User::class, 'diminta_oleh');
    }

    public function jadwalUjian()
    {
        return $this->belongsTo(JadwalUjian::class);
    }

    public function sesiRuang()
    {
        return $this->belongsTo(SesiRuang::class);
    }
}
