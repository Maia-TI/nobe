<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FindColumn extends Command
{
    protected $signature = 'db:find-column {column}';

    public function handle()
    {
        $columnSearch = $this->argument('column');
        $this->info("Searching for column: {$columnSearch}");

        $tables = DB::select("SELECT table_name FROM information_schema.columns WHERE column_name = ?", [$columnSearch]);

        foreach ($tables as $row) {
            $this->line("- {$row->table_name}");
        }
    }
}
