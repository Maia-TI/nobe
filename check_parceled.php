<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $count = DB::table('payments')->where('status', 13)->count();
    echo "Parceled payments (status 13): $count\n";
    
    if ($count > 0) {
        $parcelsCount = DB::table('payment_parcels')
            ->join('payments', 'payments.id', '=', 'payment_parcels.payment_id')
            ->where('payments.status', 13)
            ->count();
        echo "Total parcels associated: $parcelsCount\n";
        
        $sample = DB::table('payments')
            ->join('payment_parcels', 'payments.id', '=', 'payment_parcels.payment_id')
            ->where('payments.status', 13)
            ->select('payments.id as p_id', 'payments.total as p_total', 'payment_parcels.parcel_number', 'payment_parcels.due_date', 'payment_parcels.total as pp_total')
            ->limit(5)
            ->get();
        foreach ($sample as $s) {
            echo "PayID: {$s->p_id}, Total: {$s->p_total}, Parcel: {$s->parcel_number}, Due: {$s->due_date}, ParcelTotal: {$s->pp_total}\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
