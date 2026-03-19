<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportCidadesSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-cidades {--file=cidades_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para as cidades (Firebird)';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $filename = $this->option('file');

        // Define as chaves para a tabela de destino no Firebird
        // Seguindo o padrão: CODIGO, DESCRICAO, UF
        $keys = [
            'CODIGO',
            'CODIBGE',
            'DESCRICAO',
            'UF'
        ];

        $this->info("Iniciando busca de cidades...");

        // Query para buscar cidades com join nos estados para pegar a UF
        $query = '
            SELECT 
                c.id AS "CODIGO",
                c.code AS "CODIBGE",
                LEFT(TRIM(c.name), 40) AS "DESCRICAO",
                s.acronym AS "UF"
            FROM unico_cities c
            JOIN unico_states s ON c.state_id = s.id
            WHERE c.name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhuma cidade encontrada.");
            return;
        }

        $this->info("Convertendo {$total} cidades para formato Firebird...");

        $sqlContent = "-- Exportação de Cidades (" . now() . ")\n";
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
                } elseif (is_numeric($val) && $key !== 'DESCRICAO' && $key !== 'UF') {
                    $values[] = $val;
                } else {
                    $safeVal = str_replace("'", "''", (string)$val);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO CIDADES ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put($filename, $sqlContent);

        $path = storage_path("app/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
    }
}
