<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Table: agencies ---\n";
    $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'agencies'");
    foreach($cols as $c) echo $c->column_name . "\n";
    
    echo "\n--- Table: banks ---\n";
    $cols = DB::select("SELECT column_name FROM information_schema.columns WHERE table_name = 'banks'");
    foreach($cols as $c) echo $c->column_name . "\n";

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
