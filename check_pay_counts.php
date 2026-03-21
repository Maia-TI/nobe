<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $stats = DB::table('payment_entries')
        ->join('lower_payments', 'lower_payments.id', '=', 'payment_entries.parent_id')
        ->join('bank_accounts', 'bank_accounts.id', '=', 'lower_payments.bank_account_id')
        ->select('bank_accounts.name', DB::raw('count(*) as c'))
        ->groupBy('bank_accounts.name')
        ->get();
    foreach ($stats as $s) {
        echo "Bank Name: {$s->name}, Count: {$s->c}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
