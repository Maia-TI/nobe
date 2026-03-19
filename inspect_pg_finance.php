<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

try {
    // Listar tabelas do PostgreSQL que podem conter dados financeiros (padrão 'unico_')
    $tables = DB::select("SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_name LIKE 'unico_%'");

    echo "Pesquisando estrutura financeira no PostgreSQL...\n";
    foreach ($tables as $row) {
        $table = $row->table_name;

        // Buscar colunas que remetam a finanças, dívidas, taxas e saldos
        $columns = DB::select("
            SELECT column_name, data_type 
            FROM information_schema.columns 
            WHERE table_name = ? 
            AND (column_name ~* 'debt|divida|saldo|balance|taxa|fee|amount|valor|value|price|billing|payment|paid|centavos|devedor')
        ", [$table]);

        if (count($columns) > 0) {
            echo "\n--- Tabela: $table ---\n";
            foreach ($columns as $col) {
                echo "  - {$col->column_name} ({$col->data_type})\n";
            }
        }
    }
} catch (\Exception $e) {
    echo "Erro de Conexão: " . $e->getMessage() . "\n";
    echo "Nota: Verifique se o serviço 'pgsql' está acessível a partir deste ambiente.\n";
}
