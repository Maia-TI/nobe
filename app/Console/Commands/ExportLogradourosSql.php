<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportLogradourosSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-logradouros {--file=logradouros_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para os logradouros (Firebird)';

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
            'TIPOLOGRADOURO',
            'DESCRICAO',
        ];

        $this->info("Iniciando busca de logradouros únicos...");

        // Query para buscar logradouros únicos baseados na tabela de streets
        // Ajustado para o esquema: CODIGO, CODCIDADE, CODTIPOLOGRADOURO, DESCRICAO
        $query = '
            SELECT 
                s.id AS "CODIGO",
                s.city_id AS "CODCIDADE",
                st.name AS "TIPOLOGRADOURO",
                LEFT(TRIM(s.name), 40) AS "DESCRICAO"
            FROM unico_streets s
            JOIN unico_street_types st ON s.street_type_id = st.id
            WHERE s.name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum logradouro encontrado.");
            return;
        }

        $this->info("Convertendo {$total} logradouros para formato Firebird...");

        $sqlContent = "-- Exportação de Logradouros (" . now() . ")\n";
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
                } elseif (is_numeric($val) && !in_array($key, ['DESCRICAO'])) {
                    $values[] = $val;
                } else {
                    $safeVal = str_replace("'", "''", (string)$val);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO logradouros ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put($filename, $sqlContent);

        $path = storage_path("app/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
    }
}
