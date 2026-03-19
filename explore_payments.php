<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$targetTables = [
    'payments',
    'payment_parcels',
    'tax_calculations',
    'tax_collections',
    'active_debts'
];

foreach ($targetTables as $t) {
    if (!DB::selectOne("SELECT EXISTS (SELECT FROM information_schema.tables WHERE table_schema = 'public' AND table_name = ?)", [$t])->exists) {
        echo "Table $t not found.\n";
        continue;
    }
    echo "\n### Schema for $t\n";
    $columns = DB::select("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = ?
    ", [$t]);
    foreach ($columns as $c) {
        echo "  - {$c->column_name} ({$c->data_type})\n";
    }

    echo "\nSample rows from $t:\n";
    try {
        $rows = DB::select("SELECT * FROM $t LIMIT 2");
        foreach ($rows as $row) {
            echo json_encode($row) . "\n";
        }
    } catch (\Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}
