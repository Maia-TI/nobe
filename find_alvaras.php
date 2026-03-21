<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $search = ['ALVARA', 'ALV', 'PERMIT', 'LOCA', 'FUNC', 'TFL'];
    $query = DB::table('revenues');
    foreach ($search as $s) {
        $query->orWhere('name', 'ILIKE', "%$s%")->orWhere('acronym', 'ILIKE', "%$s%");
    }
    
    $results = $query->select('id', 'name', 'acronym', 'code')->get();
    foreach ($results as $r) {
        echo "ID: {$r->id}, NAME: {$r->name}, ACRONYM: {$r->acronym}, CODE: {$r->code}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
