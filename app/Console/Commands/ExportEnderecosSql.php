<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportEnderecosSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-enderecos {--file=enderecos_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para os endereços completos (Firebird)';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $filename = $this->option('file');

        // Define as chaves para a tabela de destino
        $keys = [
            'CONTRIBUINTE_ID',
            'CEP',
            'TIPO_LOGRADOURO',
            'LOGRADOURO',
            'NUMERO',
            'COMPLEMENTO',
            'BAIRRO',
            'CIDADE',
            'UF'
        ];

        $this->info("Iniciando busca de endereços completos...");

        $query = '
            SELECT 
                a.addressable_id AS "CONTRIBUINTE_ID",
                a.zip_code AS "CEP",
                st.name AS "TIPO_LOGRADOURO",
                s.name AS "LOGRADOURO",
                a.number AS "NUMERO",
                a.complement AS "COMPLEMENTO",
                n.name AS "BAIRRO",
                city.name AS "CIDADE",
                state.acronym AS "UF"
            FROM unico_addresses a
            LEFT JOIN unico_streets s ON a.street_id = s.id
            LEFT JOIN unico_street_types st ON a.street_type_id = st.id
            LEFT JOIN unico_neighborhoods n ON a.neighborhood_id = n.id
            LEFT JOIN unico_cities city ON a.city_id = city.id
            LEFT JOIN unico_states state ON city.state_id = state.id
            WHERE a.addressable_type = \'Person\'
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum endereço encontrado.");
            return;
        }

        $this->info("Convertendo {$total} endereços para formato Firebird...");

        $sqlContent = "-- Exportação de Endereços (" . now() . ")\n";
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
                } elseif (is_numeric($val) && !in_array($key, ['CEP', 'UF'])) {
                    $values[] = $val;
                } else {
                    $safeVal = str_replace("'", "''", (string)$val);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO enderecos ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put($filename, $sqlContent);

        $path = storage_path("app/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
    }
}
