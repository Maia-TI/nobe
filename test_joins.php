<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    echo "--- Testing link via revenue_acronym ---\n";
    $sample = DB::table('taxable_debts')->whereNotNull('revenue_acronym')->first();
    if ($sample) {
        echo "Example acronym: {$sample->revenue_acronym}\n";
        $rev = DB::table('revenues')->where('acronym', $sample->revenue_acronym)->first();
        if ($rev) {
            echo "MATCH FOUND in revenues: ID: {$rev->id}, NAME: {$rev->name}\n";
        } else {
            echo "MATCH NOT FOUND in revenues. Trying name...\n";
            $rev = DB::table('revenues')->where('name', $sample->revenue_acronym)->first();
            if ($rev) {
                 echo "MATCH FOUND in name: ID: {$rev->id}\n";
            } else {
                 echo "No match in revenues table.\n";
            }
        }
    }

    echo "\n--- Testing link via payment_taxables ---\n";
    $ptMatch = DB::table('payment_taxables')->join('revenues', 'revenues.id', '=', 'payment_taxables.revenue_id')
                ->select('payment_taxables.payment_id', 'revenues.name', 'revenues.acronym')
                ->first();
    if ($ptMatch) {
         echo "Link found via payment_taxables for payment {$ptMatch->payment_id}: {$ptMatch->name} ({$ptMatch->acronym})\n";
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
