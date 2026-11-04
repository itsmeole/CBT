<?php

return [
    'disk' => env('BACKUP_DISK', 'local'),
    'path' => env('BACKUP_PATH', 'backups/database'),
    'dump_binary' => env('DB_DUMP_PATH', 'mysqldump'),
    'timeout' => (int) env('DB_DUMP_TIMEOUT', 3600),
];
