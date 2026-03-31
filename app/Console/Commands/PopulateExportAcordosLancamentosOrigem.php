<?php

namespace App\Console\Commands;

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
            $this->info("Limpando tabela export_acordos_lancamentos...");
            DB::table('export_acordos_lancamentos')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando Detalhes das Dívidas Originais (active_debts + payments)...");

        $query = <<<SQL
            SELECT DISTINCT ON (p.id)
                p.id as "IID_LANCAMENTO",
                origins.agreement_id as "IID_ACORDO",
                p.created_at::date as "DDTCADASTRO",
                p.id as "IID_LANCAMENTOORIGEM",
                p.year::varchar as "VANOEXERCICIO",
                pt.revenue_id as "IID_RECEITA",
                substring('Ref. ' || r.name from 1 for 200) as "VESPECIFICACAO",
                origins.origin_due_date as "DDTVENCIMENTO",
                p.value as "NSUBTOTAL",
                p.correction as "NCMONETARIA",
                p.interest as "NJUROS",
                p.fine as "NMULTA",
                p.discount as "NDESCONTO",
                p.total as "NTOTEXERCICIO",
                p.status as "STATUS"
            FROM (
                -- Origem 1: Dívida Ativa
                SELECT a1.id as agreement_id, p1.id as payment_id, ad.due_date as origin_due_date
                FROM agreements a1
                JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = a1.agreement_operation_id
                JOIN active_debts ad ON ad.id = adao.active_debt_id
                JOIN payments p1 ON p1.id = ad.payment_id
                WHERE p1.payable_type != 'Agreement'
                
                UNION
                
                -- Origem 2: Outros Débitos (IPTU Corrente, etc) através de payment_parcels
                SELECT a2.id as agreement_id, p2.id as payment_id, pp.due_date as origin_due_date
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
        $this->chunkedInsert('export_acordos_lancamentos', $records, $prune);

        $totalInserted = DB::table('export_acordos_lancamentos')->count();
        $this->info("Sucesso! {$totalInserted} lançamentos em export_acordos_lancamentos.");

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
                        $this->error("Erro no IID_LANCAMENTO {$single['IID_LANCAMENTO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
