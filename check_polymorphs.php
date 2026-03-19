<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

echo "--- DISTINCT taxable_type IN payment_taxables ---\n";
try {
    $taxables = DB::select("SELECT DISTINCT taxable_type FROM payment_taxables");
    foreach ($taxables as $t) {
        echo "- " . $t->taxable_type . "\n";
    }
} catch (\Exception $e) {
    echo $e->getMessage() . "\n";
}

echo "\n--- DISTINCT payable_type IN payments ---\n";
try {
    $payables = DB::select("SELECT DISTINCT payable_type FROM payments");
    foreach ($payables as $p) {
        echo "- " . $p->payable_type . "\n";
    }
} catch (\Exception $e) {
    echo $e->getMessage() . "\n";
}

echo "\n--- DISTINCT taxable_type IN tax_calculations ---\n";
try {
    $calcs = DB::select("SELECT DISTINCT taxable_type FROM tax_calculations");
    foreach ($calcs as $c) {
        echo "- " . $c->taxable_type . "\n";
    }
} catch (\Exception $e) {
    echo $e->getMessage() . "\n";
}
