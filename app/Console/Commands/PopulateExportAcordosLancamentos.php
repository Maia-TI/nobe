<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordosLancamentos extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos-lancamentos {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_acordos_lancamentos a partir de agreement_debts do PostgreSQL';

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
            SELECT DISTINCT ON (ad.id)
                ad.id as "IID_LANCAMENTO",
                a.id as "IID_ACORDO",
                ad.created_at::date as "DDTCADASTRO",
                p.id as "IID_LANCAMENTOORIGEM",
                p.year::varchar as "VANOEXERCICIO",
                pt.revenue_id as "IID_RECEITA",
                substring('Ref. ' || r.name from 1 for 200) as "VESPECIFICACAO",
                ad.due_date as "DDTVENCIMENTO",
                ad.value as "NSUBTOTAL",
                ad.correction as "NCMONETARIA",
                ad.interest as "NJUROS",
                ad.fine as "NMULTA",
                0 as "NDESCONTO",
                (ad.value + ad.correction + ad.interest + ad.fine) as "NTOTEXERCICIO",
                p.status as "STATUS"
            FROM agreements a
            JOIN agreement_operations ao ON a.agreement_operation_id = ao.id
            JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = ao.id
            JOIN active_debts ad ON ad.id = adao.active_debt_id
            JOIN payments p ON p.id = ad.payment_id
            JOIN payment_taxables pt ON pt.payment_id = p.id
            JOIN revenues r ON r.id = pt.revenue_id
            ORDER BY ad.id ASC
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
