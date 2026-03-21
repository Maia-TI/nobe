<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Table: bank_accounts ---\n";
    $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'bank_accounts'");
    foreach($cols as $c) echo $c->column_name . "\n";
    
    $joins = DB::table('bank_accounts')
        ->join('banks', 'banks.id', '=', 'bank_accounts.bank_id')
        ->select('bank_accounts.id', 'banks.name as bank_name', 'bank_accounts.number')
        ->get();
    foreach ($joins as $j) {
        echo "ID: {$j->id}, BANK: {$j->bank_name}, ACC: {$j->number}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
