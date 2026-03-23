<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportDamAlvaras extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-dam-alvaras {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_dam_alvaras a partir de payment_parcel_identifiers do PostgreSQL';

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
            $this->info("Limpando tabela export_dam_alvaras...");
            DB::table('export_dam_alvaras')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando DAMs de Alvarás (IDs Receita: {$idsForSql})...");

        // Esta query usa as tabelas reais do Nobe para encontrar DAMs gerados
        $query = <<<SQL
            SELECT 
                ppi.id as "IIDENTMIGRACAO",
                pp.payment_id as "IID_LANCAMENTO",
                ppi.created_at::date as "DDTCADASTRO",
                ppi.created_at::time as "THRCADASTRO",
                pp.parcel_number::varchar || '/' || 
                (SELECT COUNT(*) FROM payment_parcels pp2 WHERE pp2.payment_id = pp.payment_id AND pp2.soft_delete = false) as "VPARCELA",
                ppi.created_at::date as "DDTEMISSAO",
                pp.due_date as "DDTVENCIMENTO",
                pp.value as "NSUBTOTAL",
                COALESCE(pp.correction, 0) as "NCMONETARIA",
                COALESCE(pp.interest, 0) as "NJUROS",
                COALESCE(pp.fine, 0) as "NMULTA",
                0::numeric as "NTXEXPEDIENTE",
                COALESCE(pp.discount, 0) as "NDESCONTO",
                pp.total as "NTOTPAGAR",
                ppi.document_number as "VNOSSONUMEROMIGRACAO",
                ppi.digitable_line as "VTEXTOCODBARRAS",
                REGEXP_REPLACE(ppi.digitable_line, '[^0-9a-zA-Z]', '', 'g') as "VNUMCODBARRAS"
            FROM payment_parcel_identifiers ppi
            JOIN payment_parcels pp ON ppi.payable_id = pp.id AND ppi.payable_type = 'PaymentParcel'
            JOIN payments p ON p.id = pp.payment_id
            JOIN payment_taxables pt ON pt.payment_id = p.id
            JOIN revenues r ON r.id = pt.revenue_id
            WHERE r.id IN ({$idsForSql})
              AND pp.soft_delete = false
            ORDER BY ppi.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum DAM de alvará encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_dam_alvaras', $records, $prune);

        $totalInserted = DB::table('export_dam_alvaras')->count();
        $this->info("Sucesso! {$totalInserted} registros em export_dam_alvaras.");
        
        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;

                // Garante que o nosso número seja numérico ou nulo
                if (isset($item['VNOSSONUMEROMIGRACAO'])) {
                    $item['VNOSSONUMEROMIGRACAO'] = (int) preg_replace('/[^0-9]/', '', $item['VNOSSONUMEROMIGRACAO']);
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
                        $this->error("Erro no IIDENTMIGRACAO {$single['IIDENTMIGRACAO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
