<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAlvaras extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-alvaras';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os alvarás diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de alvarás no PostgreSQL...");

        $query = <<<SQL
            SELECT 
                p.id AS "ID",
                p.economic_registration_id AS "CODIGO_EMPRESA",
                p.number AS "NUMERO_ALVARA",
                p.year AS "ANO_ALVARA",
                p.due_date AS "DATA_VENCIMENTO",
                p.status AS "SITUACAO",
                REGEXP_REPLACE(c.cnpj, '[^0-9]', '', 'g') AS "DOCUMENTO_CNPJ",
                LEFT(TRIM(COALESCE(c.name, c.trade_name)), 100) AS "RAZAO_SOCIAL",
                LEFT(p.process_number, 50) AS "PROCESSO",
                p.created_at AS "DATA_EMISSAO"
            FROM permits p
            INNER JOIN unico_companies c 
                ON p.economic_registration_id = c.id
            WHERE c.cnpj IS NOT NULL
            ORDER BY p.id;
SQL;

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum alvará encontrado.");
            return;
        }

        $this->info("Processando {$total} alvarás...");
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $data = [
                'ID' => $row->ID,
                'CODIGO_EMPRESA' => $row->CODIGO_EMPRESA,
                'NUMERO_ALVARA' => $row->NUMERO_ALVARA,
                'ANO_ALVARA' => $row->ANO_ALVARA,
                'DATA_VENCIMENTO' => $row->DATA_VENCIMENTO,
                'SITUACAO' => $row->SITUACAO,
                'DOCUMENTO_CNPJ' => $row->DOCUMENTO_CNPJ,
                'RAZAO_SOCIAL' => $row->RAZAO_SOCIAL,
                'PROCESSO' => $row->PROCESSO,
                'DATA_EMISSAO' => $row->DATA_EMISSAO
            ];

            if (!$this->recordExists('ALVARAS', 'ID', $row->ID)) {
                $this->insertIntoFirebird('ALVARAS', $data);
                $created++;
            } else {
                $this->updateInFirebird('ALVARAS', 'ID', $row->ID, $data);
                $updated++;
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
