<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $types = DB::table('payment_entries')->select('parent_type')->distinct()->get();
    foreach ($types as $t) {
        echo "Parent Type: {$t->parent_type}\n";
    }
    
    // Check if there are many banks in register_debts
    $banks = DB::table('register_debts')->select('bank')->distinct()->get();
    foreach ($banks as $b) {
        echo "Register Bank: {$b->bank}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
