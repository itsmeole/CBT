<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

#[Signature('app:backup-database')]
#[Description('Membuat backup konsisten database MySQL/MariaDB ke disk private.')]
class BackupDatabase extends Command
{
    public function handle(): int
    {
        $connection = config('database.default');
        if (! in_array($connection, ['mysql', 'mariadb'], true)) {
            $this->error('Backup otomatis hanya mendukung koneksi MySQL/MariaDB.');

            return self::FAILURE;
        }

        $database = config("database.connections.{$connection}");
        if (empty($database['database']) || empty($database['host']) || empty($database['username'])) {
            $this->error('Konfigurasi koneksi database tidak lengkap.');

            return self::FAILURE;
        }

        $disk = Storage::disk(config('backup.disk'));
        $directory = trim(config('backup.path'), '/');
        $disk->makeDirectory($directory);
        $filename = 'cbt-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.sql';
        $relativePath = $directory.'/'.$filename;
        $output = tmpfile();
        if ($output === false) {
            $this->error('Tidak dapat membuat file sementara untuk backup database.');

            return self::FAILURE;
        }

        $process = new Process([
            config('backup.dump_binary'),
            '--single-transaction',
            '--quick',
            '--lock-tables=false',
            '--routines',
            '--triggers',
            '--host='.$database['host'],
            '--port='.$database['port'],
            '--user='.$database['username'],
            '--default-character-set='.$database['charset'],
            $database['database'],
        ], null, ['MYSQL_PWD' => (string) ($database['password'] ?? '')]);
        $process->setTimeout(config('backup.timeout'));

        try {
            $process->run(function (string $type, string $buffer) use ($output): void {
                if ($type === Process::OUT) {
                    $length = strlen($buffer);
                    $offset = 0;
                    while ($offset < $length) {
                        $written = fwrite($output, substr($buffer, $offset));
                        if ($written === false || $written === 0) {
                            throw new \RuntimeException('Gagal menulis hasil backup database.');
                        }
                        $offset += $written;
                    }
                }
            });
        } catch (Throwable $exception) {
            fclose($output);
            throw $exception;
        }

        if (! $process->isSuccessful()) {
            fclose($output);
            $this->error('Backup database gagal: '.$process->getErrorOutput());

            return self::FAILURE;
        }

        rewind($output);
        $stored = $disk->writeStream($relativePath, $output, ['visibility' => 'private']);
        fclose($output);
        if (! $stored) {
            $disk->delete($relativePath);
            $this->error('Gagal menyimpan file backup pada disk yang dikonfigurasi.');

            return self::FAILURE;
        }

        $this->info('Backup database berhasil: '.$relativePath);

        return self::SUCCESS;
    }
}
