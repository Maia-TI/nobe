<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $rows = DB::table('bank_accounts')
        ->join('agencies', 'agencies.id', '=', 'bank_accounts.agency_id')
        ->join('banks', 'banks.id', '=', 'agencies.bank_id')
        ->select('bank_accounts.name as cuenta', 'banks.name as banco', 'bank_accounts.number_agreement', 'bank_accounts.portfolio', 'bank_accounts.variation', 'bank_accounts.account_number')
        ->get();
    foreach ($rows as $r) {
        echo "CANAL: {$r->cuenta} | BANCO: {$r->banco} | CONVENIO: {$r->number_agreement} | CARTEIRA: {$r->portfolio} | VAR: {$r->variation}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
