<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $sample = DB::table('payment_entries')
        ->join('lower_payments', function($join) {
            $join->on('lower_payments.id', '=', 'payment_entries.parent_id')
                 ->where('payment_entries.parent_type', '=', 'LowerPayment');
        })
        ->leftJoin('bank_accounts', 'bank_accounts.id', '=', 'lower_payments.bank_account_id')
        ->select('payment_entries.id', 'payment_entries.payment_date', 'bank_accounts.name as bank_name', 'payment_entries.paid_value')
        ->limit(5)
        ->get();
    foreach ($sample as $s) {
        echo "Entry: {$s->id}, Date: {$s->payment_date}, Bank: {$s->bank_name}, Value: {$s->paid_value}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
