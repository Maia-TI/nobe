<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    $p = DB::table('payments')->where('status', '!=', 13)->where('status', '!=', 2)->first();
    if ($p) {
        $parcel = DB::table('payment_parcels')->where('payment_id', $p->id)->first();
        if ($parcel) {
            echo "Non-parceled payment {$p->id} HAS a parcel in payment_parcels.\n";
        } else {
             echo "Non-parceled payment {$p->id} HAS NO parcel in payment_parcels.\n";
        }
    }
} catch (\Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
