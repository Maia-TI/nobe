<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncBairros extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-bairros';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os bairros diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de bairros no PostgreSQL...");

        $query = '
            SELECT 
                id AS "CODIGO",
                city_id AS "CODCIDADE",
                LEFT(TRIM(name), 40) AS "DESCRICAO"
            FROM unico_neighborhoods
            WHERE name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum bairro encontrado.");
            return;
        }

        $this->info("Processando {$total} bairros...");
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $data = [
                'CODIGO' => $row->CODIGO,
                'CODCIDADE' => $row->CODCIDADE,
                'DESCRICAO' => $row->DESCRICAO
            ];

            if (!$this->recordExists('BAIRROS', 'CODIGO', $row->CODIGO)) {
                $this->insertIntoFirebird('BAIRROS', $data);
                $created++;
            } else {
                $this->updateInFirebird('BAIRROS', 'CODIGO', $row->CODIGO, $data);
                $updated++;
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
