<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $ids = [2, 51, 63, 64];
    $count = DB::table('payment_taxables')
        ->whereIn('revenue_id', $ids)
        ->where('taxable_type', 'EconomicRegistration')
        ->count();
    
    echo "Assessments (Lançamentos de Alvará) for Economic Registrations: $count\n";
    
    if ($count > 0) {
        $sample = DB::table('payment_taxables')
            ->join('revenues', 'revenues.id', '=', 'payment_taxables.revenue_id')
            ->join('payments', 'payments.id', '=', 'payment_taxables.payment_id')
            ->whereIn('payment_taxables.revenue_id', $ids)
            ->where('payment_taxables.taxable_type', 'EconomicRegistration')
            ->select('payment_taxables.taxable_id', 'payments.id as assessment_id', 'payments.year', 'revenues.name')
            ->limit(5)
            ->get();
        foreach ($sample as $s) {
            echo "Econ.Reg: {$s->taxable_id}, Year: {$s->year}, Assessment ID: {$s->assessment_id}, Revenue: {$s->name}\n";
        }
    }

} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
