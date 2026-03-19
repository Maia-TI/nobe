<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportAlvarasSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-alvaras {--file=alvaras_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para Alvarás, unificando os dados das empresas (PJ) pelo CNPJ';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $filename = $this->option('file');

        // Define as colunas equivalentes na tabela ALVARAS no Firebird
        $keys = [
            'ID',
            'CODIGO_EMPRESA',
            'NUMERO_ALVARA',
            'ANO_ALVARA',
            'DATA_VENCIMENTO',
            'SITUACAO',
            'DOCUMENTO_CNPJ',
            'RAZAO_SOCIAL',
            'PROCESSO',
            'DATA_EMISSAO'
        ];

        $this->info("Buscando dados de Alvarás no PostgreSQL e relacionando com Pessoas Jurídicas...");

        $query = <<<SQL
            SELECT 
                p.id AS "ID",
                p.economic_registration_id AS "CODIGO_EMPRESA",
                p.number AS "NUMERO_ALVARA",
                p.year AS "ANO_ALVARA",
                p.due_date AS "DATA_VENCIMENTO",
                p.status AS "SITUACAO",
                REGEXP_REPLACE(c.cnpj, '[^0-9]', '', 'g') AS "DOCUMENTO_CNPJ",
                LEFT(TRIM(COALESCE(c.name, c.trade_name)), 100) AS "RAZAO_SOCIAL",
                LEFT(p.process_number, 50) AS "PROCESSO",
                p.created_at AS "DATA_EMISSAO"
            FROM permits p
            INNER JOIN unico_companies c 
                ON p.economic_registration_id = c.id
            WHERE c.cnpj IS NOT NULL
            ORDER BY p.id;
SQL;

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum Alvará vinculado a um CNPJ válido foi encontrado para exportar.");
            return;
        }

        $this->info("Convertendo {$total} Alvarás para o formato de inserção SQL (Firebird)...");

        $sqlContent = "-- Exportação de Alvarás (" . now() . ")\n";
        $sqlContent .= "-- Mapeamento feito utilizando a ligação permits.economic_registration_id -> unico_companies.id\n\n";
        $sqlContent .= "SET TRANSACTION;\n\n";

        $columnsStr = implode(', ', $keys);

        foreach ($results as $row) {
            $values = [];
            foreach ($keys as $key) {
                // Forçando conversão de objeto stdClass para propriedade direta
                $val = $row->$key;

                if (is_null($val) || $val === '') {
                    $values[] = "NULL";
                } elseif (is_numeric($val) && !in_array($key, ['DOCUMENTO_CNPJ'])) {
                    $values[] = $val;
                } else {
                    // Limpar aspas simples para compatibilidade SQL
                    $safeVal = str_replace("'", "''", (string)$val);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO ALVARAS ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put("public/" . $filename, $sqlContent);

        $path = storage_path("app/public/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
        $this->warn("A tabela ALVARAS e colunas utilizadas no insert do Firebird devem ser consistidas de acordo com o esquema real de destino. As colunas podem precisar de ajuste.");
    }
}
