<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $rows = DB::table('nobebank_accounts')->get();
    foreach ($rows as $r) {
        print_r($r);
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
