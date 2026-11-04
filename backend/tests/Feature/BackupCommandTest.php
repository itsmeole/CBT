<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupCommandTest extends TestCase
{
    public function test_backup_command_rejects_non_mysql_connections_with_explicit_error(): void
    {
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
        ]);

        $this->artisan('app:backup-database')
            ->expectsOutput('Backup otomatis hanya mendukung koneksi MySQL/MariaDB.')
            ->assertFailed();
    }

    public function test_backup_command_writes_database_dump_to_private_configured_disk(): void
    {
        Storage::fake('local');
        $temporaryBase = tempnam(sys_get_temp_dir(), 'cbt-dump-');
        self::assertNotFalse($temporaryBase);
        unlink($temporaryBase);
        $dumpScript = $temporaryBase.'.cmd';
        self::assertNotFalse(file_put_contents($dumpScript, "@echo off\r\necho -- fake database dump\r\n"));

        try {
            config([
                'database.default' => 'mysql',
                'database.connections.mysql' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'username' => 'backup-test',
                    'password' => '',
                    'charset' => 'utf8mb4',
                    'database' => 'cbt_test',
                ],
                'backup.dump_binary' => $dumpScript,
                'backup.path' => 'test-backups',
            ]);

            $this->artisan('app:backup-database')
                ->assertSuccessful();

            $files = Storage::disk('local')->files('test-backups');
            $this->assertCount(1, $files);
            $this->assertSame('-- fake database dump', trim(Storage::disk('local')->get($files[0])));
        } finally {
            if (is_file($dumpScript)) {
                unlink($dumpScript);
            }
        }
    }
}
