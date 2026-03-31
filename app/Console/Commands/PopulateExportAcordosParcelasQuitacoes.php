<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordosParcelasQuitacoes extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos-parcelas-quitacoes {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Extrai as quitações (entradas de pagamento) das parcelas de acordos de IPTU';

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
            $this->info("Limpando tabela export_acordos_parcelas_quitacoes...");
            DB::table('export_acordos_parcelas_quitacoes')->truncate();
        }

        $this->info("Buscando Quitações de Acordos de IPTU...");

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
            JOIN payment_taxables pt ON pt.payment_id = p.id AND pt.taxable_type = 'Property'
            JOIN payment_entries pe ON pe.payment_parcel_id = pp.id 
            JOIN lower_payments lp ON lp.id = pe.parent_id AND pe.parent_type = 'LowerPayment'
            WHERE pt.revenue_id IN ({$idsForSql})
              AND pp.soft_delete = false
              AND p.payable_type = 'Agreement'
            ORDER BY ppi.id ASC, lp.payment_date DESC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhuma quitação de acordo encontrada.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} quitações...");
        $this->chunkedInsert('export_acordos_parcelas_quitacoes', $records, $prune);

        $totalInserted = DB::table('export_acordos_parcelas_quitacoes')->count();
        $this->info("Sucesso! {$totalInserted} registros na tabela export_acordos_parcelas_quitacoes.");

        return Command::SUCCESS;
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;
                $item['synced'] = false;
                $item['created_at'] = now();
                $item['updated_at'] = now();
                return $item;
            }, $chunk);

            try {
                if ($prune) {
                    DB::table($table)->insert($data);
                } else {
                    DB::table($table)->insertOrIgnore($data);
                }
            } catch (\Exception $e) {
                $this->warn("Falha no lote, tentando individualmente...");
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
