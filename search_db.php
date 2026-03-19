<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$search = $argv[1] ?? 'alvar';
$connection = 'new_mysql';

$tables = DB::connection($connection)->select('SHOW TABLES');

foreach ($tables as $tableRow) {
    $table = current((array)$tableRow);
    $columns = DB::connection($connection)->select("SHOW COLUMNS FROM `$table` ");

    foreach ($columns as $column) {
        $colName = $column->Field;
        $count = DB::connection($connection)->table($table)
            ->where($colName, 'LIKE', "%$search%")
            ->count();

        if ($count > 0) {
            echo "Table: $table, Column: $colName, Matches: $count\n";
        }
    }
}
