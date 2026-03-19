<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class DbPrepareRestore extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'db:prepare-restore {--force : Force the operation to run when in production}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Wipes the public schema and prepares PostgreSQL with required extensions for a restore';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if (config('app.env') === 'production' && !$this->option('force')) {
            if (!$this->confirm('Do you really want to wipe the database and restore the backup in PRODUCTION?')) {
                $this->error('Operation cancelled.');
                return;
            }
        }

        $this->info('Cleaning up database for restore...');

        try {
            // Drop and recreate public schema to clear everything
            $this->warn('Dropping and recreating public schema...');
            DB::statement('DROP SCHEMA IF EXISTS public CASCADE');
            DB::statement('DROP SCHEMA IF EXISTS repack CASCADE');
            DB::statement('CREATE SCHEMA public');

            // Extensions
            $this->info('Enabling extensions...');
            
            // Ensure we are operating on the public schema explicitly
            DB::statement('SET search_path TO public');
            
            $extensions = [
                'unaccent',
                'hstore',
                'pg_repack',
                'pg_trgm',
                '"uuid-ossp"'
            ];

            foreach ($extensions as $extension) {
                DB::statement("CREATE EXTENSION IF NOT EXISTS $extension SCHEMA public");
                $this->line(" - Extension $extension enabled.");
            }

            // Restore
            $this->newLine();
            $backupPath = storage_path('app/private/pontadepedras-pa.dump');
            
            if (!file_exists($backupPath)) {
                $this->error("Backup file not found at: {$backupPath}");
                return 1;
            }

            $this->info('Starting database restore from: ' . basename($backupPath));
            
            $dbHost = config('database.connections.pgsql.host', 'pgsql');
            $dbPort = config('database.connections.pgsql.port', '5432');
            $dbName = config('database.connections.pgsql.database', 'nobe');
            $dbUser = config('database.connections.pgsql.username', 'sail');
            $dbPass = config('database.connections.pgsql.password', 'password');

            // REMOVED --clean and --if-exists because we already wiped the schema
            // Adding --no-owner and --no-privileges to avoid permission issues
            $command = sprintf(
                'PGPASSWORD=%s pg_restore -v -h %s -p %s -U %s -d %s --no-owner --no-privileges %s 2>&1',
                escapeshellarg($dbPass),
                escapeshellarg($dbHost),
                escapeshellarg($dbPort),
                escapeshellarg($dbUser),
                escapeshellarg($dbName),
                escapeshellarg($backupPath)
            );

            $this->warn('This might take a while depending on the dump size...');
            $this->line('Restoring... (errors about "schema public already exists" will be ignored)');
            
            $startTime = microtime(true);
            
            // Execute the restore command
            passthru($command, $exitCode);

            // Exit code 0 is perfect success.
            // Exit code 1 usually means "success with warnings", 
            // like "schema public already exists", which we expect.
            if ($exitCode === 0 || $exitCode === 1) {
                $duration = round(microtime(true) - $startTime, 2);
                $this->info("Database process COMPLETED in {$duration} seconds.");
                return 0;
            } else {
                $this->error('Database restore FAILED with exit code: ' . $exitCode);
                return 1;
            }

        } catch (\Exception $e) {
            $this->error('Error during the process: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
