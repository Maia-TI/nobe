<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $count = DB::table('payment_taxables')
    ->join('payments', 'payments.id', '=', 'payment_taxables.payment_id')
    ->where('payments.payable_type', 'Agreement')
    ->count();
    echo "Agreement cases in payment_taxables: $count\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
