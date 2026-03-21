<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $res = DB::select("SELECT table_schema, table_name FROM information_schema.tables WHERE table_name = 'nobebank_accounts'");
    foreach($res as $r) echo "Schema: {$r->table_schema}, Table: {$r->table_name}\n";
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
