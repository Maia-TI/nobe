<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$tables = DB::select("
    SELECT table_name 
    FROM information_schema.tables 
    WHERE table_schema = 'public' 
    AND (
        table_name ILIKE '%pay%' 
        OR table_name ILIKE '%tax%' 
        OR table_name ILIKE '%debt%' 
        OR table_name ILIKE '%invoice%'
        OR table_name ILIKE '%iss%'
        OR table_name ILIKE '%iptu%'
        OR table_name ILIKE '%receit%'
        OR table_name ILIKE '%lancamento%'
        OR table_name ILIKE '%baixa%'
        OR table_name ILIKE '%guia%'
    )
    ORDER BY table_name;
");

echo "Tables found mapping out Payments/Taxes/Debts related concepts:\n";
foreach ($tables as $t) {
    echo "- " . $t->table_name . "\n";
}

echo "\n--- Exploring Table Schemas ---\n";

foreach ($tables as $t) {
    $columns = DB::select("
        SELECT column_name, data_type 
        FROM information_schema.columns 
        WHERE table_name = ?
    ", [$t->table_name]);

    echo "\n### {$t->table_name}\n";
    foreach ($columns as $c) {
        echo "  - {$c->column_name} ({$c->data_type})\n";
    }
}
