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

        $this->info("Buscando Lançamentos de Origem dos Acordos (active_debts)...");

        $query = <<<SQL
            SELECT DISTINCT ON (ad.payment_id)
                ad.payment_id as "IID_LANCAMENTO",
                a.id as "IID_ACORDO",
                COALESCE(p.year, extract(year from ad.due_date))::varchar as "VANOEXERCICIO",
                p.total as "NVALIMPOSTOCALC",
                p.status as "STATUS",
                'Débito Original de Acordo - Protocolo ' || a.protocol_number as "DESCRICAO"
            FROM agreements a
            JOIN agreement_operations ao ON a.agreement_operation_id = ao.id
            JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = ao.id
            JOIN active_debts ad ON ad.id = adao.active_debt_id
            JOIN payments p ON p.id = ad.payment_id
            ORDER BY ad.payment_id ASC, a.id DESC
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
