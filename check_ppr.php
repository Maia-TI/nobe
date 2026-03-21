<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Table: payment_parcel_revenues ---\n";
    $columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'payment_parcel_revenues' ORDER BY ordinal_position");
    foreach ($columns as $column) {
        echo "{$column->column_name} ({$column->data_type})\n";
    }
    
    echo "\n--- Sample Data ---\n";
    $sample = DB::table('payment_parcel_revenues')->limit(3)->get();
    foreach ($sample as $s) {
        print_r($s);
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
