<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordosDams extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos-dams {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Extrai as parcelas (DAMs) dos acordos para a tabela local export_acordos_dams';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');

        if ($prune) {
            $this->info("Limpando tabela export_acordos_dams...");
            DB::table('export_acordos_dams')->truncate();
        }

        $this->info("Buscando DAMs de Acordos (payment_parcels)...");

        $query = <<<SQL
            SELECT 
                ppi.id as "IID_DAM",
                a.id as "IID_ACORDO",
                p.id as "IID_LANCAMENTO",
                pp.created_at::date as "DDTCADASTRO",
                pp.created_at::time as "THRCADASTRO",
                pp.parcel_number::varchar as "VPARCELA",
                p.created_at::date as "DDTEMISSAO",
                pp.due_date as "DDTVENCIMENTO",
                pp.value as "NSUBTOTAL",
                pp.correction as "NCMONETARIA",
                pp.interest as "NJUROS",
                pp.fine as "NMULTA",
                0 as "NTXEXPEDIENTE",
                0 as "NDESCONTO",
                pp.total as "NTOTPAGAR",
                rd.nosso_numero as "VNOSSONUMEROMIGRACAO",
                ppi.digitable_line as "VTEXTOCODBARRAS",
                ppi.document_number as "VDAMNUMERO"
            FROM agreements a
            JOIN agreement_debts adebt ON adebt.agreement_id = a.id
            JOIN payments p ON p.id = adebt.payment_id
            JOIN payment_parcels pp ON pp.payment_id = p.id AND pp.parcel_number = adebt.parcel_number
            LEFT JOIN payment_parcel_identifiers ppi ON ppi.payable_id = pp.id AND ppi.payable_type = 'PaymentParcel'
            LEFT JOIN register_debts rd ON rd.payment_parcel_identifier_id = ppi.id
            WHERE p.payable_type = 'Agreement'
            ORDER BY a.id, pp.parcel_number
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        if ($totalFound === 0) {
            $this->warn("Nenhum DAM de acordo encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_acordos_dams', $records, $prune);

        $totalInserted = DB::table('export_acordos_dams')->count();
        $this->info("Sucesso! {$totalInserted} DAMs em export_acordos_dams.");

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
                        $this->error("Erro no IID_DAM {$single['IID_DAM']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }
}
