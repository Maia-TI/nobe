<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportContribuintes extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-contribuintes {--pj-offset=50000 : Offset para IDs de Pessoa Jurídica}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_contribuintes combinando PF e PJ sem conflito de IDs';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $pjOffset = (int) $this->option('pj-offset');

        $this->info("Limpando tabela export_contribuintes...");
        DB::table('export_contribuintes')->truncate();

        // 1. Processar Pessoas Físicas
        $this->info("Buscando Pessoas Físicas...");
        $queryPf = <<<'SQL'
            SELECT 
                ind.id as "ID",
                ind.id as "CODIGO",
                'F' as "PESSOA",
                LEFT(TRIM(ind.social_name), 50) as "DESCRICAO",
                REGEXP_REPLACE(ind.cpf, '[^0-9]', '', 'g') as "CPF_CNPJ",
                NULL as "RAZSOCIAL",
                REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "CEP",
                a.address_city_id as "CODCIDADE",
                a.neighborhood_id as "CODBAIRRO",
                a.street_id as "CODLOGRADOURO",
                a.number as "NUMERO",
                LEFT(a.complement, 30) as "COMPLEMENTO",
                ind.birthdate as "DTNASCIMENTO",
                ident.rg as "RG",
                NULL as "TELEFONE",
                NULL as "INSCESTADUAL",
                NULL as "EMAIL",
                NULL as "DTINICIOATIVIDADE",
                ind.id as "IDENTMIGRACAO"
            FROM unico_individuals ind
            LEFT JOIN (
                SELECT addressable_id, zip_code, address_city_id, neighborhood_id, street_id, number, complement,
                       ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
                FROM unico_addresses
                WHERE addressable_type = 'Person'
            ) a ON ind.id = a.addressable_id AND a.rn = 1
            LEFT JOIN (
                SELECT individual_id, MAX(number) as rg
                FROM unico_identities
                GROUP BY individual_id
            ) ident ON ind.id = ident.individual_id
            WHERE ind.cpf IS NOT NULL
SQL;
        $pfRecords = DB::select($queryPf);
        $this->info("Inserindo " . count($pfRecords) . " Pessoas Físicas...");
        $this->chunkedInsert('export_contribuintes', $pfRecords);

        // 2. Processar Pessoas Jurídicas
        $this->info("Buscando Pessoas Jurídicas...");
        $queryPj = <<<SQL
            SELECT 
                comp.id + {$pjOffset} as "ID",
                comp.id + {$pjOffset} as "CODIGO",
                'J' as "PESSOA",
                LEFT(TRIM(COALESCE(comp.name, comp.trade_name)), 50) as "DESCRICAO",
                REGEXP_REPLACE(comp.cnpj, '[^0-9]', '', 'g') as "CPF_CNPJ",
                LEFT(TRIM(COALESCE(comp.name, comp.trade_name)), 75) as "RAZSOCIAL",
                REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "CEP",
                a.city_id as "CODCIDADE",
                a.neighborhood_id as "CODBAIRRO",
                a.street_id as "CODLOGRADOURO",
                a.number as "NUMERO",
                LEFT(a.complement, 30) as "COMPLEMENTO",
                NULL as "DTNASCIMENTO",
                NULL as "RG",
                NULL as "TELEFONE",
                comp.state_registration as "INSCESTADUAL",
                NULL as "EMAIL",
                comp.register_date as "DTINICIOATIVIDADE",
                comp.id + {$pjOffset} as "IDENTMIGRACAO"
            FROM unico_companies comp
            LEFT JOIN (
                SELECT addressable_id, zip_code, city_id, neighborhood_id, street_id, number, complement,
                       ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
                FROM unico_addresses
                WHERE addressable_type = 'Person'
            ) a ON comp.id = a.addressable_id AND a.rn = 1
            WHERE comp.cnpj IS NOT NULL
SQL;
        $pjRecords = DB::select($queryPj);
        $this->info("Inserindo " . count($pjRecords) . " Pessoas Jurídicas...");
        $this->chunkedInsert('export_contribuintes', $pjRecords);

        $total = DB::table('export_contribuintes')->count();
        $this->info("Sucesso! {$total} registros na tabela export_contribuintes.");
    }

    private function chunkedInsert($table, $records)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;
                $item['created_at'] = now();
                $item['updated_at'] = now();
                return $item;
            }, $chunk);

            try {
                DB::table($table)->insert($data);
            } catch (\Exception $e) {
                $this->warn("Falha no lote, tentando inserção individual para identificar erro...");
                foreach ($data as $single) {
                    try {
                        DB::table($table)->insert($single);
                    } catch (\Exception $ex) {
                        $this->error("Erro no ID {$single['ID']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
