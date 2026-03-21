<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Column Search: convenio ---\n";
    $res = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE column_name LIKE '%convenio%'");
    foreach($res as $r) echo "T: {$r->table_name}, C: {$r->column_name}\n";
    
    echo "\n--- Column Search: agreement ---\n";
    $res = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE column_name LIKE '%agreement%'");
    foreach($res as $r) echo "T: {$r->table_name}, C: {$r->column_name}\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
