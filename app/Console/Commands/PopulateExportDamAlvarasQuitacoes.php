<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportDamAlvarasQuitacoes extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-quitacoes-dams {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local export_quitacoes_dams a partir de payment_entries do PostgreSQL';

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
            $this->info("Limpando tabela export_quitacoes_dams...");
            DB::table('export_quitacoes_dams')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando DAMs de Alvarás (IDs Receita: {$idsForSql})...");

        $query = <<<SQL
           SELECT DISTINCT ON (ppi.id)
                ppi.id as "IIDENTDAM_MIGRACAO",
                lp.payment_date as "DDTPAGTO",
                pe.paid_value as "NVALPAGO",
                lp.bank_account_id as "IID_BANCO",
                pe.credit_date  as "DDTCREDITO",
                null as "VAGENCIACONTA"
            FROM payment_parcel_identifiers ppi
            JOIN payment_parcels pp ON ppi.payable_id = pp.id AND ppi.payable_type = 'PaymentParcel'
            JOIN payments p ON p.id = pp.payment_id
            JOIN payment_taxables pt ON pt.payment_id = p.id AND pt.taxable_type = 'EconomicRegistration'
            JOIN economic_registrations er ON er.id = pt.taxable_id
            JOIN revenues r ON r.id = pt.revenue_id
            JOIN payment_entries pe ON pe.payment_parcel_id = pp.id 
            JOIN lower_payments lp ON lp.id = pe.parent_id AND pe.parent_type = 'LowerPayment'
            LEFT JOIN lower_payment_payment_parcel_identifiers lpppi ON lpppi.payment_parcel_identifier_id = ppi.id AND lpppi.lower_payment_id = lp.id
            WHERE r.id IN ({$idsForSql})
              AND pp.soft_delete = false
            ORDER BY ppi.id ASC, (lpppi.id IS NOT NULL) DESC, lp.payment_date DESC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhuma quitação de DAM de alvará encontrada.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_quitacoes_dams', $records, $prune);

        $totalInserted = DB::table('export_quitacoes_dams')->count();
        $this->info("Sucesso! {$totalInserted} registros em export_quitacoes_dams.");

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
                        $this->error("Erro no IIDENTDAM_MIGRACAO {$single['IIDENTDAM_MIGRACAO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
