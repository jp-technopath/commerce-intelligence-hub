<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class DbBackupAndMigrateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:backup-and-migrate {--force : Force execution without confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Perform a verified database backup before executing pending database migrations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('=== Safe Database Migration & Backup Tool ===');

        $connection = config('database.default', 'mysql');
        $dbConfig = config("database.connections.{$connection}");

        $host = $dbConfig['host'] ?? '127.0.0.1';
        $port = $dbConfig['port'] ?? 3306;
        $database = $dbConfig['database'] ?? '';
        $username = $dbConfig['username'] ?? '';
        $password = $dbConfig['password'] ?? '';

        if (empty($database)) {
            $this->error('Database name configuration is missing.');
            return self::FAILURE;
        }

        $backupDir = storage_path('app/backups');
        if (! File::exists($backupDir)) {
            File::makeDirectory($backupDir, 0755, true);
        }

        $timestamp = now()->format('Ymd_His');
        $backupFile = "{$backupDir}/backup_{$database}_{$timestamp}.sql";

        $this->info("Taking database backup: {$backupFile}");

        $dumpBinary = $this->findMysqldumpBinary();

        if (! $dumpBinary) {
            $this->error('mysqldump binary not found on system PATH.');
            return self::FAILURE;
        }

        // Build mysqldump command
        $cmd = sprintf(
            'MYSQL_PWD=%s %s -h %s -P %s -u %s --single-transaction --quick %s > %s 2>&1',
            escapeshellarg($password),
            escapeshellarg($dumpBinary),
            escapeshellarg($host),
            escapeshellarg((string) $port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($backupFile)
        );

        $returnVar = 0;
        $output = [];
        exec($cmd, $output, $returnVar);

        if ($returnVar !== 0 || ! File::exists($backupFile) || File::size($backupFile) === 0) {
            $this->error('Database backup failed! Aborting database migrations to protect data integrity.');
            Log::error('DbBackupAndMigrateCommand: Backup failed', ['file' => $backupFile, 'output' => implode("\n", $output)]);
            return self::FAILURE;
        }

        $sizeKb = round(File::size($backupFile) / 1024, 2);
        $this->info("✓ Backup successfully verified! Size: {$sizeKb} KB ({$backupFile})");
        Log::info("DbBackupAndMigrateCommand: Backup verified ({$backupFile}, {$sizeKb} KB)");

        // Now run migrations safely!
        $this->info('Executing pending database migrations...');

        $exitCode = Artisan::call('migrate', [
            '--force' => true,
        ], $this->output);

        if ($exitCode === 0) {
            $this->info('✓ All database migrations executed cleanly!');
            return self::SUCCESS;
        }

        $this->error('Database migration encountered an error!');
        return self::FAILURE;
    }

    /**
     * Find mysqldump binary path.
     */
    protected function findMysqldumpBinary(): ?string
    {
        foreach (['/usr/bin/mysqldump', '/usr/local/bin/mysqldump', 'mysqldump'] as $path) {
            if ($path === 'mysqldump') {
                $returnVar = 0;
                exec('which mysqldump', $output, $returnVar);
                if ($returnVar === 0 && ! empty($output[0])) {
                    return trim($output[0]);
                }
            } elseif (File::exists($path) && is_executable($path)) {
                return $path;
            }
        }
        return null;
    }
}
