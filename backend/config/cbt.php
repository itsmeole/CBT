<?php

return [
    // 'tolak' = login baru ditolak jika akun aktif di perangkat lain; 'kick' = sesi lama dicabut.
    // Nanti dipindah ke pengaturan Superadmin (/settings).
    'login_policy' => env('CBT_LOGIN_POLICY', 'tolak'),

    // Antrean untuk job PDF. Produksi memakai worker terpisah ('reports'); saat pengembangan lokal cukup
    // satu worker, set CBT_QUEUE_LAPORAN=default agar PDF tidak tersangkut menunggu worker 'reports'.
    'queue_laporan' => env('CBT_QUEUE_LAPORAN', 'reports'),
];
