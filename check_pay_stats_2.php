<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Payment Entry Parent Types ---\n";
    $stats = DB::table('payment_entries')->select('parent_type', DB::raw('count(*) as c'))->groupBy('parent_type')->get();
    foreach ($stats as $s) {
        echo "Type: {$s->parent_type}, Count: {$s->c}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
