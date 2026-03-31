<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordosLancamentosParcelas extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos-lancamentos-parcelas {--prune : Limpa os lançamentos de acordo antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Extrai as parcelas (Agreement) resultantes dos acordos para a tabela export_lancamentos_iptu';

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
            $this->info("Limpando lançamentos de acordo em export_lancamentos_iptu...");
            DB::table('export_lancamentos_iptu')->whereNotNull('IID_ACORDO')->delete();
        }

        $this->info("Buscando Parcelas resultantes de Acordos de IPTU (payments tipo Agreement)...");

        // Busca IDs de configuração para VVT e VVE
        $settings = DB::table('settings')->first();
        $idVvt = $settings->terrain_market_value_id ?? 0;
        $idVve = $settings->construction_market_value_id ?? 0;

        $query = <<<SQL
            SELECT DISTINCT ON (p.id)
                p.id as "CODLANCAMENTO",
                prop.id as "CODBCI",
                a.id as "IID_ACORDO",
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
                'Parcela de acordo de IPTU' as "INFORMACOESCALCULO"
            FROM agreements a
            -- Encontra as origens para saber se é IPTU e qual a propriedade
            JOIN (
                SELECT a1.id as agreement_id, p1.id as origin_payment_id
                FROM agreements a1
                JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = a1.agreement_operation_id
                JOIN active_debts ad ON ad.id = adao.active_debt_id
                JOIN payments p1 ON p1.id = ad.payment_id
                WHERE p1.payable_type != 'Agreement'
                UNION
                SELECT a2.id as agreement_id, p2.id as payment_id
                FROM agreements a2
                JOIN other_debts_agreement_operations odao ON odao.agreement_operation_id = a2.agreement_operation_id
                JOIN payment_parcels pp ON pp.id = odao.payment_parcel_id
                JOIN payments p2 ON p2.id = pp.payment_id
                WHERE p2.payable_type != 'Agreement'
            ) as origins ON origins.agreement_id = a.id
            JOIN payments p_origin ON p_origin.id = origins.origin_payment_id
            JOIN payment_taxables pt_origin ON pt_origin.payment_id = p_origin.id AND pt_origin.taxable_type = 'Property'
            JOIN properties prop ON prop.id = pt_origin.taxable_id
            -- Agora pega as parcelas (payments resultantes) do acordo
            JOIN agreement_debts adebt ON adebt.agreement_id = a.id
            JOIN payments p ON p.id = adebt.payment_id
            WHERE pt_origin.revenue_id IN ({$idsForSql})
              AND p.payable_type = 'Agreement'
              AND p.status NOT IN (2,6,7,9)
            ORDER BY p.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhuma parcela de lançamento encontrada.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} parcelas...");
        $this->chunkedInsert('export_lancamentos_iptu', $records);

        $totalInserted = DB::table('export_lancamentos_iptu')->whereNotNull('IID_ACORDO')->count();
        $this->info("Sucesso! {$totalInserted} parcelas em export_lancamentos_iptu.");

        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records)
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
                DB::table($table)->insertOrIgnore($data);
            } catch (\Exception $e) {
                $this->warn("Falha no lote, tentando individualmente...");
                foreach ($data as $single) {
                    try {
                        DB::table($table)->insertOrIgnore($single);
                    } catch (\Exception $ex) {
                        $this->error("Erro no CODLANCAMENTO {$single['CODLANCAMENTO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
