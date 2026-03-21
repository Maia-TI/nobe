<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

$search = ['debt', 'credit', 'levy', 'assess', 'issue', 'release', 'tribute', 'tributo', 'lanc', 'cred', 'deb'];

try {
    $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public'");
    foreach ($tables as $table) {
        $name = $table->table_name;
        foreach ($search as $s) {
            if (str_contains($name, $s)) {
                echo $name . "\n";
                break;
            }
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
