<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $banks = DB::table('register_debts')->select('bank')->distinct()->get();
    foreach ($banks as $b) {
        echo "Register Debt Bank: {$b->bank}\n";
    }
    
    // Let's check a sample join to see the real relationship
    $sample = DB::table('payment_entries')
        ->join('lower_payments', 'lower_payments.id', '=', 'payment_entries.parent_id')
        ->join('bank_accounts', 'bank_accounts.id', '=', 'lower_payments.bank_account_id')
        ->select('bank_accounts.name', 'bank_accounts.number_agreement', 'bank_accounts.account_number')
        ->limit(5)
        ->get();
    foreach ($sample as $s) {
        echo "Bank: {$s->name}, Conv: {$s->number_agreement}, Acc: {$s->account_number}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
