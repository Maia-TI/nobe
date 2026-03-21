<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Testing link between Parcel and Identifier ---\n";
    $sample = DB::table('payment_parcel_identifiers')
        ->select('id', 'payable_id', 'payable_type', 'document_number')
        ->limit(5)
        ->get();
    foreach ($sample as $s) {
        echo "Identifier ID: {$s->id}, Document: {$s->document_number}, Payable: {$s->payable_type} (#{$s->payable_id})\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
