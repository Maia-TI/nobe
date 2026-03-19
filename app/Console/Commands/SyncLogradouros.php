<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncLogradouros extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-logradouros';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os logradouros diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de logradouros no PostgreSQL...");

        $query = '
            SELECT 
                s.id AS "CODIGO",
                s.city_id AS "CODCIDADE",
                st.name AS "TIPOLOGRADOURO",
                LEFT(TRIM(s.name), 40) AS "DESCRICAO"
            FROM unico_streets s
            JOIN unico_street_types st ON s.street_type_id = st.id
            WHERE s.name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum logradouro encontrado.");
            return;
        }

        $this->info("Processando {$total} logradouros...");
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $data = [
                'CODIGO' => $row->CODIGO,
                'CODCIDADE' => $row->CODCIDADE,
                'TIPOLOGRADOURO' => $row->TIPOLOGRADOURO,
                'DESCRICAO' => $row->DESCRICAO
            ];

            if (!$this->recordExists('logradouros', 'CODIGO', $row->CODIGO)) {
                $this->insertIntoFirebird('logradouros', $data);
                $created++;
            } else {
                $this->updateInFirebird('logradouros', 'CODIGO', $row->CODIGO, $data);
                $updated++;
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
