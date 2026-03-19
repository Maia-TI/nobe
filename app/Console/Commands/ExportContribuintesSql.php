<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ExportContribuintesSql extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:export-contribuintes {--file=contribuintes_export.sql}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Gera um arquivo SQL com comandos INSERT para os contribuintes (Firebird)';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $filename = $this->option('file');

        // Define as chaves para a tabela de destino no Firebird
        $keys = [
            'ID',
            'CODIGO',
            'PESSOA',
            'DESCRICAO',
            'CPF_CNPJ',
            'RAZSOCIAL',
            'CEP',
            'CODCIDADE',
            'CODBAIRRO',
            'CODLOGRADOURO',
            'NUMERO',
            'COMPLEMENTO',
            'DTNASCIMENTO',
            'RG',
            'TELEFONE',
            'INSCESTADUAL',
            'EMAIL',
            'DTINICIOATIVIDADE',
            'IDENTMIGRACAO'
        ];

        $this->info("Iniciando busca de dados na tabela export_contribuintes...");

        $results = DB::table('export_contribuintes')->orderBy('ID')->get();
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum dado encontrado para exportar.");
            return;
        }

        $this->info("Convertendo {$total} registros para formato Firebird...");

        $sqlContent = "-- Exportação de Contribuintes (" . now() . ")\n";
        $sqlContent .= "-- Formato: Firebird SQL\n\n";
        $sqlContent .= "SET TRANSACTION;\n\n";

        $columnsStr = implode(', ', $keys);

        foreach ($results as $row) {
            $values = [];
            foreach ($keys as $key) {
                $val = $row->$key;

                if (is_null($val) || $val === '') {
                    $values[] = "NULL";
                } elseif (is_bool($val)) {
                    $values[] = $val ? '1' : '0';
                } elseif (is_numeric($val) && !in_array($key, ['CPF_CNPJ', 'CEP', 'RG', 'TELEFONE', 'INSCESTADUAL'])) {
                    $values[] = $val;
                } else {
                    $cleanVal = (string)$val;
                    if (in_array($key, ['CPF_CNPJ', 'CEP'])) {
                        $cleanVal = str_replace(['.', '-', '/'], '', $cleanVal);
                    }
                    $safeVal = str_replace("'", "''", $cleanVal);
                    $values[] = "'{$safeVal}'";
                }
            }

            $valuesStr = implode(', ', $values);
            $sqlContent .= "INSERT INTO CONTRIBUINTES ({$columnsStr}) VALUES ({$valuesStr});\n";
        }

        $sqlContent .= "\nCOMMIT;\n";

        Storage::put($filename, $sqlContent);

        $path = storage_path("app/{$filename}");
        $this->info("Sucesso! Arquivo gerado em: {$path}");
    }
}
