<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportBairrosSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-bairros {--file=bairros_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para os bairros (Firebird)';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $filename = $this->option('file');

        // Define as chaves para a tabela de destino no Firebird
        $keys = [
            'CODIGO',
            'CODCIDADE',
            'DESCRICAO'
        ];

        $this->info("Iniciando busca de bairros...");

        // Query para buscar bairros da tabela unico_neighborhoods
        $query = '
            SELECT 
                id AS "CODIGO",
                city_id AS "CODCIDADE",
                LEFT(TRIM(name), 40) AS "DESCRICAO"
            FROM unico_neighborhoods
            WHERE name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum bairro encontrado.");
            return;
        }

        $this->info("Convertendo {$total} bairros para formato Firebird...");

        $sqlContent = "-- Exportação de Bairros (" . now() . ")\n";
        $sqlContent .= "-- Formato: Firebird SQL\n\n";
        $sqlContent .= "SET TRANSACTION;\n\n";

        $columnsStr = implode(', ', $keys);

        foreach ($results as $row) {
            $values = [];
            foreach ($keys as $key) {
                $val = $row->$key;

                if (is_null($val)) {
                    $values[] = "NULL";
                } elseif (is_bool($val)) {
                    $values[] = $val ? '1' : '0';
                } elseif (is_numeric($val) && $key !== 'DESCRICAO') {
                    $values[] = $val;
                } else {
                    $safeVal = str_replace("'", "''", (string)$val);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO BAIRROS ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put($filename, $sqlContent);

        $path = storage_path("app/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
    }
}
