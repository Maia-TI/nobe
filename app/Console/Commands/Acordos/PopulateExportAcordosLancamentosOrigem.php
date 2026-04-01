<?php

namespace App\Console\Commands\Acordos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordosLancamentosOrigem extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos-lancamentos-origem {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_acordos_lancamentos a partir das dívidas de origem do acordo';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');

        if ($prune) {
            $this->info("Limpando tabela export_acordos_lancamentos_origem...");
            DB::table('export_acordos_lancamentos_origem')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando Detalhes das Dívidas Originais (active_debts + payments)...");
        $query = <<<SQL
            SELECT DISTINCT ON (p.id)
                origins.agreement_id as "IID_ACORDO",
                p.created_at::date as "DDTCADASTRO",   
                p.id as "IID_LANCAMENTOORIGEM",
                p.year::varchar as "VANOEXERCICIO",
                NULL as "VMESEXERCICIO",
                pt.revenue_id as "IID_RECEITA",
                '' as "VESPECIFICACAO",
                origins.origin_due_date as "DDTVENCIMENTO",
                origins.value as "NSUBTOTAL",
                origins.correction as "NCMONETARIA",
                origins.interest as "NJUROS",
                origins.fine as "NMULTA",
                origins.discount as "NDESCONTO",
                (origins.value + origins.correction + origins.interest + origins.fine - origins.discount) as "NTOTEXERCICIO"
            FROM (
                -- Origem 1: Dívida Ativa (Extrair composição de active_debts)
                SELECT 
                    a1.id as agreement_id, 
                    p1.id as payment_id, 
                    ad.due_date as origin_due_date,
                    ad.value as value,
                    ad.correction as correction,
                    ad.interest as interest,
                    ad.fine as fine,
                    (ad.value - ad.value_with_discount) as discount
                FROM agreements a1
                JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = a1.agreement_operation_id
                JOIN active_debts ad ON ad.id = adao.active_debt_id
                JOIN payments p1 ON p1.id = ad.payment_id
                WHERE p1.payable_type != 'Agreement'
                
                UNION
                
                -- Origem 2: Outros Débitos (IPTU Corrente, etc)
                SELECT 
                    a2.id as agreement_id, 
                    p2.id as payment_id, 
                    pp.due_date as origin_due_date,
                    p2.value as value,
                    p2.correction as correction,
                    p2.interest as interest,
                    p2.fine as fine,
                    p2.discount as discount
                FROM agreements a2
                JOIN other_debts_agreement_operations odao ON odao.agreement_operation_id = a2.agreement_operation_id
                JOIN payment_parcels pp ON pp.id = odao.payment_parcel_id
                JOIN payments p2 ON p2.id = pp.payment_id
                WHERE p2.payable_type != 'Agreement'
            ) as origins
            JOIN payments p ON p.id = origins.payment_id
            JOIN payment_taxables pt ON pt.payment_id = p.id
            JOIN revenues r ON r.id = pt.revenue_id
            ORDER BY p.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum lançamento de acordo encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_acordos_lancamentos_origem', $records, $prune);

        $totalInserted = DB::table('export_acordos_lancamentos_origem')->count();
        $this->info("Sucesso! {$totalInserted} lançamentos em export_acordos_lancamentos_origem.");

        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;

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
                        dd($ex->getMessage());
                        $this->error("Erro no IID_LANCAMENTO {$single['IID_LANCAMENTO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
