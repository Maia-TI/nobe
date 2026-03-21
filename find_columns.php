<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $cols = DB::select("SELECT table_name, column_name FROM information_schema.columns WHERE column_name LIKE '%lanc%' OR column_name LIKE '%trib%' OR column_name LIKE '%post%'");
    foreach ($cols as $c) {
        echo "{$c->table_name}.{$c->column_name}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
