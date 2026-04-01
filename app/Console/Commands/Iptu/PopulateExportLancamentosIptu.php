<?php

namespace App\Console\Commands\Iptu;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportLancamentosIptu extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-lancamentos-iptu {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_lancamentos_iptu a partir de payments e properties do PostgreSQL';

    /**
     * IDs de Receita (revenues table) considerados IPTU
     */
    private const IPTU_REVENUE_IDS = [12, 27];

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');
        $idsForSql = implode(',', self::IPTU_REVENUE_IDS);

        if ($prune) {
            $this->info("Limpando tabela export_lancamentos_iptu...");
            DB::table('export_lancamentos_iptu')->truncate();
        }

        $this->info("Buscando Lançamentos de IPTU (IDs Receita: {$idsForSql})...");

        // Busca IDs de configuração para VVT e VVE
        $settings = DB::table('settings')->first();
        $idVvt = $settings->terrain_market_value_id ?? 0;
        $idVve = $settings->construction_market_value_id ?? 0;

        $query = <<<SQL
            SELECT DISTINCT ON (p.id)
                p.id as "CODLANCAMENTO",
                prop.id as "CODBCI",
                p.year::varchar as "ANOEXERCICIO",
                (SELECT CAST(NULLIF(regexp_replace(v.value, '[^0-9.]', '', 'g'), '') AS NUMERIC) 
                 FROM property_variable_values v 
                 WHERE v.property_id = prop.id AND v.property_variable_setting_id = {$idVvt} LIMIT 1) as "VVT",
                (SELECT CAST(NULLIF(regexp_replace(v.value, '[^0-9.]', '', 'g'), '') AS NUMERIC) 
                 FROM property_variable_values v 
                 WHERE v.property_id = prop.id AND v.property_variable_setting_id = {$idVve} LIMIT 1) as "VVE",
                p.total as "VALIPTU",
                p.total as "VALIMPOSTO",
                COALESCE(prop.building_aliquot, prop.territorial_aliquot) as "ALIQUOTAIPTU",
                'Lançamento de IPTU via export_nobe' as "INFORMACOESCALCULO"
            FROM properties prop
            JOIN payment_taxables pt ON pt.taxable_id = prop.id AND pt.taxable_type = 'Property'
            JOIN payments p ON p.id = pt.payment_id
            WHERE pt.revenue_id IN ({$idsForSql})
             AND p.status NOT IN (2,6,7,9)
             AND p.payable_type != 'Agreement'
            --  Acredito que não se limita ao tax calculations. na importacao dos dams com esse filtro aplicado dara divergencia.
            --  AND p.payable_type = 'TaxCalculation'
            ORDER BY p.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum lançamento de IPTU encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_lancamentos_iptu', $records, $prune);

        $totalInserted = DB::table('export_lancamentos_iptu')->count();
        $this->info("Sucesso! {$totalInserted} lançamentos em export_lancamentos_iptu.");

        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;

                // Calcula VVIMOVEL se tiver VVT e VVE
                $vvt = (float) ($item['VVT'] ?? 0);
                $vve = (float) ($item['VVE'] ?? 0);
                $item['VVIMOVEL'] = $vvt + $vve;

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
                        $this->error("Erro no CODLANCAMENTO {$single['CODLANCAMENTO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
