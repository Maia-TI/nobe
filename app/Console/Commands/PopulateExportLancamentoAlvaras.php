<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportLancamentoAlvaras extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-lancamento-alvaras {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_lancamento_alvaras a partir de payments do PostgreSQL';

    /**
     * IDs de Receita (revenues table) considerados Alvarás
     */
    private const ALVARAS_REVENUE_IDS = [2, 51, 63, 64];

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');
        $idsForSql = implode(',', self::ALVARAS_REVENUE_IDS);

        if ($prune) {
            $this->info("Limpando tabela export_lancamento_alvaras...");
            DB::table('export_lancamento_alvaras')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando Lançamentos de Alvarás (IDs Receita: {$idsForSql})...");

        $query = <<<SQL
            SELECT 
                p.id as "IID_LANCAMENTO",
                er.id as "IID_CADECONOMICO",
                p.year::varchar as "VANOEXERCICIO",
                p.total as "NVALIMPOSTOCALC",
                p.status as "status_nobe"
            FROM economic_registrations er
            JOIN payment_taxables pt ON pt.taxable_id = er.id AND pt.taxable_type = 'EconomicRegistration'
            JOIN payments p ON p.id = pt.payment_id
            JOIN revenues r ON r.id = pt.revenue_id
            WHERE r.id IN ({$idsForSql})
            ORDER BY p.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum lançamento de alvará encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_lancamento_alvaras', $records, $prune);

        $totalInserted = DB::table('export_lancamento_alvaras')->count();
        $this->info("Sucesso! {$totalInserted} lançamentos em export_lancamento_alvaras.");
        
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
