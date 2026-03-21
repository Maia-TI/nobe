<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $columns = DB::select("SELECT column_name, data_type FROM information_schema.columns WHERE table_name = 'payment_parcels' ORDER BY ordinal_position");
    foreach ($columns as $column) {
        echo "{$column->column_name} ({$column->data_type})\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
