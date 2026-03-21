<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $a = DB::table('bank_accounts')->where('id', 3)->first();
    print_r($a);
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
