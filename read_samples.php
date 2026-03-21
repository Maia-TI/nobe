<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['taxable_debts', 'active_debts', 'agreement_debts', 'payments', 'subjects'];

foreach ($tables as $table) {
    echo "--- Table: $table ---\n";
    try {
        $record = DB::table($table)->first();
        if ($record) {
            print_r($record);
        } else {
            echo "No records found.\n";
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
