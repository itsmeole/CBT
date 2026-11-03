<?php

return [
    // 'tolak' = login baru ditolak jika akun aktif di perangkat lain; 'kick' = sesi lama dicabut.
    // Nanti dipindah ke pengaturan Superadmin (/settings).
    'login_policy' => env('CBT_LOGIN_POLICY', 'tolak'),
];

