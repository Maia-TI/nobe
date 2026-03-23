<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportContribuintes extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-contribuintes {--pj-offset=50000 : Offset para IDs de Pessoa Jurídica} {--prune : Limpa a tabela antes de popular}';

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
        $prune = $this->option('prune');

        if ($prune) {
            $this->info("Limpando tabela export_contribuintes...");
            DB::table('export_contribuintes')->truncate();
        } else {
            $this->info("Modo incremental: buscando e inserindo apenas ausentes...");
        }

        // 1. Processar Pessoas Físicas
        $this->info("Buscando Pessoas Físicas...");
        $queryPf = <<<'SQL'
            SELECT 
                ind.id as "IID_CONTRIBUINTE",
                'F' as "PESSOA",
                TRIM(COALESCE(ind.social_name, p.name)) as "VNOME_FANTASIA",
                REGEXP_REPLACE(COALESCE(ind.cpf, p.cpf_cnpj), '[^0-9]', '', 'g') as "VCPF_CNPJ",
                TRIM(COALESCE(ind.social_name, p.name)) as "VRAZAO_SOCIAL",
                city.code as "ICODIGO_MUNICIPIO_IBGE",
                neigh.name as "VBAIRRO",
                str.name as "VLOGRADOURO",
                st_type.name as "VDESCRICAO_TIPO_DE_LOGRADOURO",
                REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "VCEP",
                a.address_city_id as "CODCIDADE",
                a.neighborhood_id as "CODBAIRRO",
                a.street_id as "CODLOGRADOURO",
                a.number::varchar as "VNUMERO",
                a.complement as "VCOMPLEMENTO",
                COALESCE(p.phone, p.mobile) as "VDDD_TELEFONE_1",
                p.email as "VEMAIL",
                NULL as "DDATA_INICIO_ATIVIDADE",
                NULL as "VINSCESTADUAL",
                NULL as "VNATUREZA_JURIDICA",
                false as "LOPCAO_PELO_MEI",
                false as "LOPCAO_PELO_SIMPLES"
            FROM unico_individuals ind
            LEFT JOIN unico_people p ON ind.id = p.personable_id AND p.personable_type = 'Individual'
            LEFT JOIN (
                SELECT addressable_id, zip_code, city_id, address_city_id, neighborhood_id, street_id, number, complement,
                       ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
                FROM unico_addresses
                WHERE addressable_type = 'Person'
            ) a ON COALESCE(p.id, ind.id) = a.addressable_id AND a.rn = 1
            LEFT JOIN unico_cities city ON a.address_city_id = city.id
            LEFT JOIN unico_neighborhoods neigh ON a.neighborhood_id = neigh.id
            LEFT JOIN unico_streets str ON a.street_id = str.id
            LEFT JOIN unico_street_types st_type ON str.street_type_id = st_type.id
            WHERE ind.cpf IS NOT NULL OR p.cpf_cnpj IS NOT NULL
            ORDER BY "IID_CONTRIBUINTE" ASC
SQL;
        $pfRecords = DB::select($queryPf);
        $this->info("Processando " . count($pfRecords) . " Pessoas Físicas...");
        $this->chunkedInsert('export_contribuintes', $pfRecords, $prune);

        // 2. Processar Pessoas Jurídicas
        $this->info("Buscando Pessoas Jurídicas...");
        $queryPj = <<<SQL
            SELECT 
                comp.id as "IID_CONTRIBUINTE",
                'J' as "PESSOA",
                TRIM(COALESCE(comp.trade_name, comp.name, p.name)) as "VNOME_FANTASIA",
                REGEXP_REPLACE(COALESCE(comp.cnpj, p.cpf_cnpj), '[^0-9]', '', 'g') as "VCPF_CNPJ",
                TRIM(COALESCE(comp.name, comp.trade_name, p.name)) as "VRAZAO_SOCIAL",
                city.code as "ICODIGO_MUNICIPIO_IBGE",
                neigh.name as "VBAIRRO",
                str.name as "VLOGRADOURO",
                st_type.name as "VDESCRICAO_TIPO_DE_LOGRADOURO",
                REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "VCEP",
                a.address_city_id as "CODCIDADE",
                a.neighborhood_id as "CODBAIRRO",
                a.street_id as "CODLOGRADOURO",
                a.number::varchar as "VNUMERO",
                a.complement as "VCOMPLEMENTO",
                COALESCE(p.mobile, p.phone) as "VDDD_TELEFONE_1",
                comp.state_registration as "VINSCESTADUAL",
                p.email as "VEMAIL",
                comp.commercial_registration_date as "DDATA_INICIO_ATIVIDADE",
                COALESCE(comp.choose_simple, false) as "LOPCAO_PELO_SIMPLES",
                false as "LOPCAO_PELO_MEI",
                NULL as "VNATUREZA_JURIDICA"
            FROM unico_companies comp
            LEFT JOIN unico_people p ON comp.id = p.personable_id AND p.personable_type = 'Company'
            LEFT JOIN (
                SELECT addressable_id, zip_code, city_id, address_city_id, neighborhood_id, street_id, number, complement,
                       ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
                FROM unico_addresses
                WHERE addressable_type = 'Person'
            ) a ON COALESCE(p.id, comp.id) = a.addressable_id AND a.rn = 1
            LEFT JOIN unico_cities city ON a.address_city_id = city.id
            LEFT JOIN unico_neighborhoods neigh ON a.neighborhood_id = neigh.id
            LEFT JOIN unico_streets str ON a.street_id = str.id
            LEFT JOIN unico_street_types st_type ON str.street_type_id = st_type.id
            WHERE comp.cnpj IS NOT NULL OR p.cpf_cnpj IS NOT NULL
            ORDER BY comp.id ASC
SQL;
        $pjRecords = DB::select($queryPj);
        $this->info("Processando " . count($pjRecords) . " Pessoas Jurídicas...");
        $this->chunkedInsert('export_contribuintes', $pjRecords, $prune);

        $total = DB::table('export_contribuintes')->count();
        $this->info("Sucesso! {$total} registros na tabela export_contribuintes.");
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;
                // Fix encoding for all string fields
                foreach ($item as $key => $value) {
                    if (is_string($value)) {
                        $item[$key] = $this->fixEncoding($value);
                    }
                }

                $item['created_at'] = now();
                $item['updated_at'] = now();
                $item['synced'] = false;
                return $item;
            }, $chunk);

            try {
                if ($prune) {
                    DB::table($table)->insert($data);
                } else {
                    DB::table($table)->insertOrIgnore($data);
                }
            } catch (\Exception $e) {
                $this->warn("Falha no lote, tentando inserção individual para identificar erro...");
                foreach ($data as $single) {
                    try {
                        if ($prune) {
                            DB::table($table)->insert($single);
                        } else {
                            DB::table($table)->insertOrIgnore($single);
                        }
                    } catch (\Exception $ex) {
                        $this->error("Erro no ID {$single['IID_CONTRIBUINTE']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Corrige problemas de encoding (double/triple UTF-8)
     */
    private function fixEncoding(?string $string): ?string
    {
        if (is_null($string) || $string === '') {
            return $string;
        }

        // Se não for UTF-8 válido, tenta converter de ISO
        if (!mb_check_encoding($string, 'UTF-8')) {
            return @mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
        }

        $decoded = $string;
        // Padrões de dupla codificação UTF-8
        while (str_contains($decoded, 'Ã')) {
            $prev = $decoded;
            // Tenta converter de UTF-8 para ISO-8859-1
            $test = @mb_convert_encoding($decoded, 'ISO-8859-1', 'UTF-8');
            if ($test === false || $test === $decoded) {
                break;
            }

            // Converte de volta de ISO para UTF-8 assumindo que era double encoding
            $test = mb_convert_encoding($test, 'UTF-8', 'ISO-8859-1');
            if ($test === $prev) {
                break;
            }

            $decoded = $test;
        }

        return $decoded;
    }
}
