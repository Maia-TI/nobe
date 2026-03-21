<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = ['subjects', 'payments', 'taxable_debts', 'active_debts', 'agreement_debts', 'register_debts', 'nobe_credit_values'];

foreach ($tables as $table) {
    try {
        $count = DB::table($table)->count();
        echo "$table: $count records\n";
    } catch (\Exception $e) {
        //echo "$table: Error " . $e->getMessage() . "\n";
    }
}
