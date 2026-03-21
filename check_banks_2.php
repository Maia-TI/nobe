<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $joins = DB::table('bank_accounts')
        ->select('id', 'name', 'account_number', 'agency_code')
        ->get();
    foreach ($joins as $j) {
        echo "ID: {$j->id}, NAME: {$j->name}, ACC: {$j->account_number}, AG: {$j->agency_code}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
