<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$payableClasses = [
    'TaxCalculation' => 'tax_calculations',
    'OtherRevenue' => 'other_revenues',
    'IssIntelPayment' => 'iss_intel_payments'
];

foreach ($payableClasses as $class => $table) {
    echo "--- Testing $class ---\n";
    try {
        $p = DB::table('payments')->where('payable_type', $class)->first();
        if ($p) {
             echo "Payment ID: {$p->id}, Payable ID: {$p->payable_id}\n";
             $rev = DB::table($table)->where('id', $p->payable_id)->first();
             if ($rev && isset($rev->revenue_id)) {
                 echo "Found Revenue ID: {$rev->revenue_id}\n";
             } else {
                 echo "No record in $table or no revenue_id.\n";
             }
        } else {
             echo "No $class record in payments.\n";
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
