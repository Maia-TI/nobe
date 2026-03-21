<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $sample = DB::table('payment_entries')
        ->join('payment_parcels', 'payment_parcels.id', '=', 'payment_entries.payment_parcel_id')
        ->join('payments', 'payments.id', '=', 'payment_parcels.payment_id')
        ->select('payments.id as assessment_id', 'payment_parcels.parcel_number', 'payment_entries.payment_date', 'payment_entries.paid_value')
        ->limit(5)
        ->get();
    foreach ($sample as $s) {
        echo "Lançamento: {$s->assessment_id}, Parcela: {$s->parcel_number}, Paga em: {$s->payment_date}, Valor Pago: {$s->paid_value}\n";
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
