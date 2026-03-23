<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PopulateExportCadastroEconomicos extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-export-cadastro-economicos {--pj-offset=50000 : Offset para IDs de Pessoa Jurídica} {--prune : Limpa a tabela antes de popular}';

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
            $this->info("Limpando tabela expor_cadastro_economicos...");
            DB::table('expor_cadastro_economicos')->truncate();
        } else {
            $this->info("Modo incremental: buscando apenas ausentes...");
        }

        $this->info("Buscando Cadastros Econômicos...");

        $query = <<<SQL
            SELECT 
                er.id as "IID_CADECONOMICO",
                ind.id as "IID_CONTRIBUINTE",
                CASE 
                    WHEN er.status = 'active' THEN 1
                    WHEN er.status = 'inactive' THEN 2
                    ELSE 1
                END as "ISITUACAO",
                er.id::varchar as "VINSCMUNICIPAL",
                extract(YEAR FROM COALESCE(er.date_enrollment, er.started_in, er.created_at))::varchar as "VANOINSCMUNICIPAL",
                SUBSTRING(COALESCE(er.observations, ''), 1, 250) as "VOBSERVACOES"
            FROM economic_registrations er
            JOIN unico_people p ON er.person_id = p.id
            LEFT JOIN unico_individuals ind ON p.personable_id = ind.id AND p.personable_type = 'Individual'
            LEFT JOIN unico_companies comp ON p.personable_id = comp.id AND p.personable_type = 'Company'
            ORDER BY er.id ASC
SQL;

        $records = DB::select($query);
        $totalFound = count($records);

        $this->info("Processando {$totalFound} registros...");
        $this->chunkedInsert('expor_cadastro_economicos', $records, $prune);

        $totalInserted = DB::table('expor_cadastro_economicos')->count();
        $this->info("Sucesso! {$totalInserted} registros na tabela expor_cadastro_economicos.");
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
                        $this->error("Erro no ID_CADECONOMICO {$single['IID_CADECONOMICO']}: " . $ex->getMessage());
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
