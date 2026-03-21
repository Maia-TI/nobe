<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $results = DB::select("SELECT payment_id, count(*) as c FROM taxable_debts GROUP BY payment_id HAVING count(*) > 1 LIMIT 5");
    foreach ($results as $r) {
        echo "Payment ID: {$r->payment_id} has {$r->c} taxable_debts\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
