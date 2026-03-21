<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Bank Agreements Info ---\n";
    $accounts = DB::table('bank_accounts')
        ->select('id', 'name', 'number_agreement', 'portfolio', 'variation', 'account_number')
        ->get();
    foreach ($accounts as $a) {
        echo "ID: {$a->id}, NAME: {$a->name}, CONVENIO: {$a->number_agreement}, CARTEIRA: {$a->portfolio}, VAR: {$a->variation}, ACC: {$a->account_number}\n";
    }
    
    echo "\n--- Searching for Agreement tables ---\n";
    $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_name LIKE '%agreement%' OR table_name LIKE '%convenio%'");
    foreach ($tables as $t) {
        echo "Table: {$t->table_name}\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
