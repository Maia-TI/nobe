<?php

namespace App\Console\Commands\Acordos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportAcordos extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-acordos {--prune : Limpa a tabela antes de popular}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Popula a tabela local expor_cadastro_economicos a partir de economic_registrations';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');

        if ($prune) {
            $this->info("Limpando tabela export_acordos...");
            DB::table('export_acordos')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando Acordos...");
        /* IID_LANCTOACORDO_MIGRACAO integer, */

        $query = <<<SQL
           SELECT 
            a.id as "IID_ACORDO",
            p.id as "IID_LANCTOACORDO_MIGRACAO",
            a.created_at as "DDTACORDO",
            p.person_id as "IID_CONTRIBUINTE",
            pt.revenue_id as "IID_RECEITA",
            '' as "VDESCRICAO"
        FROM agreements a
        JOIN agreement_operations ao ON a.agreement_operation_id = ao.id
        LEFT JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = ao.id
        LEFT JOIN active_debts ad ON ad.id = adao.active_debt_id
        LEFT JOIN agreement_debts adebt ON adebt.agreement_id = a.id
        LEFT JOIN payments p ON p.id = adebt.payment_id
        LEFT JOIN payment_taxables pt ON pt.payment_id = p.id
        GROUP BY a.id, a.protocol_number, a.status, pt.revenue_id, p.person_id, p.id
        ORDER BY a.protocol_number
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('export_acordos', $records, $prune);

        $totalInserted = DB::table('export_acordos')->count();
        $this->info("Sucesso! {$totalInserted} registros na tabela export_acordos.");
    }

    private function chunkedInsert($table, $records, $prune = true)
    {
        $chunks = array_chunk($records, 500);
        foreach ($chunks as $chunk) {
            $data = array_map(function ($record) {
                $item = (array) $record;

                // Fix encoding for all string fields
                foreach ($item as $key => $value) {
                    if (is_string($value)) {
                        $item[$key] = $this->fixEncoding($value);
                    }
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
                        $this->error($ex->getMessage());
                        $this->error("Erro no ID_ACORDO {$single['IID_ACORDO']}: " . $ex->getMessage());
                    }
                }
            }
        }
    }

    /**
     * Corrige problemas de encoding (double/triple UTF-8)
     */
    private function fixEncoding(?string $string): ?string
    {
        if (is_null($string) || $string === '') {
            return $string;
        }

        // Se não for UTF-8 válido, tenta converter de ISO
        if (!mb_check_encoding($string, 'UTF-8')) {
            return @mb_convert_encoding($string, 'UTF-8', 'ISO-8859-1');
        }

        $decoded = $string;
        // Padrões de dupla codificação UTF-8
        while (str_contains($decoded, 'Ã')) {
            $prev = $decoded;
            // Tenta converter de UTF-8 para ISO-8859-1
            $test = @mb_convert_encoding($decoded, 'ISO-8859-1', 'UTF-8');
            if ($test === false || $test === $decoded) {
                break;
            }

            // Converte de volta de ISO para UTF-8 assumindo que era double encoding
            $test = mb_convert_encoding($test, 'UTF-8', 'ISO-8859-1');
            if ($test === $prev) {
                break;
            }

            $decoded = $test;
        }

        return $decoded;
    }
}
