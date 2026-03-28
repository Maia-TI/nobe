<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportCadastrosImobiliarios extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-cadastros-imobiliarios {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_cadastros_imobiliarios com dados exaustivos de imóveis';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');

        if ($prune) {
            $this->info("Limpando tabela export_cadastros_imobiliarios...");
            DB::table('export_cadastros_imobiliarios')->truncate();
        }

        $this->info("Buscando dados exaustivos do cadastro imobiliário...");

        // Configurações globais para valores venais
        $settings = DB::connection('pgsql')->table('settings')->first();
        $idVvt = $settings?->terrain_market_value_id ?? 0;
        $idVve = $settings?->construction_market_value_id ?? 0;

        // Mapeamentos conhecidos
        $idAreaTerreno = 554;
        $idAreaEdifUnidade = 678;
        $idAreaEdifTotal = 679;
        $idTestadaReal = 553;

        $idPavimento = 513; // Variável "Pavimento" (code 13) - valores são IDs de opções

        $query = <<<SQL
            SELECT
                p.id as "IID_BCI",
                CASE WHEN p.status = 'active' THEN 1 ELSE 2 END as "ISTATUS",
                CAST(NULLIF(SUBSTRING(SPLIT_PART(p.registration::text, '.', 1) FROM 1 FOR 2), '') AS INTEGER) as "IID_DISTRITO",
                SUBSTRING(SPLIT_PART(p.registration::text, '.', 2) FROM 1 FOR 2) as "VSETOR",
                SUBSTRING(SPLIT_PART(p.registration::text, '.', 3) FROM 1 FOR 5) as "VQUADRA",
                SUBSTRING(SPLIT_PART(p.registration::text, '.', 4) FROM 1 FOR 4) as "VLOTE",
                SUBSTRING(SPLIT_PART(p.registration::text, '.', 5) FROM 1 FOR 3) as "VUNIDADE",
                SUBSTRING(p.previous_registration::text FROM 1 FOR 20) as "VINSCANTERIOR",
                ua.street_id as "ICODLOGRADOURO",
                ua.number as "INUMERO",
                SUBSTRING(ua.complement FROM 1 FOR 30) as "VCOMPLEMENTO",
                un.id as "ICODBAIRRO",
                p.responsible_id as "IID_CONTRIBUINTE",
                p.responsible_id as "IID_CONTRIBUINTEMORADOR",
                COALESCE((SELECT CAST(v.value AS NUMERIC) FROM property_variable_values v WHERE v.property_id = p.id AND v.property_variable_setting_id = {$idAreaTerreno} LIMIT 1), 0) as "NAREALOTE",
                COALESCE((SELECT CAST(v.value AS NUMERIC) FROM property_variable_values v WHERE v.property_id = p.id AND v.property_variable_setting_id = {$idAreaEdifTotal} LIMIT 1), 0) as "NAREAEDIFICACAO",
                100.00 as "NFRACAOIDEAL",
                COALESCE(
                    CAST(NULLIF((
                        SELECT pvso.code
                        FROM property_variable_values pvv2
                        JOIN property_variable_setting_options pvso ON pvso.id = CAST(pvv2.value AS INTEGER)
                        WHERE pvv2.property_id = p.id AND pvv2.property_variable_setting_id = {$idPavimento}
                          AND pvv2.value ~ '^\d+$'
                        LIMIT 1
                    ), '') AS INTEGER),
                    1
                ) as "INUMPAVIMENTOS",
                CASE WHEN p.status = 'active' THEN 1 ELSE 2 END as "ISTATUS"
            FROM properties p
            LEFT JOIN unico_addresses ua ON ua.addressable_id = p.id AND ua.addressable_type = 'Property'
            LEFT JOIN unico_neighborhoods un ON un.id = ua.neighborhood_id
            ORDER BY p.id ASC
SQL;

        $records = DB::connection('pgsql')->select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum imóvel encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_cadastros_imobiliarios', $records, $prune);

        $totalInserted = DB::table('export_cadastros_imobiliarios')->count();
        $this->info("Sucesso! {$totalInserted} registros em export_cadastros_imobiliarios.");

        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                return array_merge((array)$record, [
                    'created_at' => now(),
                    'updated_at' => now(),
                    'synced' => false
                ]);
            }, $chunk);

            try {
                if ($prune) {
                    DB::table($table)->insert($data);
                } else {
                    DB::table($table)->insertOrIgnore($data);
                }
            } catch (\Exception $e) {
                // Em caso de erro no lote, tentamos individualmente
                foreach ($data as $single) {
                    try {
                        DB::table($table)->insert($single);
                    } catch (\Exception $ex) {
                        $this->error("Erro no BCI {$single['IID_BCI']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
