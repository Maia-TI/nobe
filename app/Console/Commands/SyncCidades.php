<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncCidades extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-cidades';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza as cidades diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de cidades no PostgreSQL...");

        $query = '
            SELECT 
                c.id AS "CODIGO",
                c.code AS "CODIBGE",
                LEFT(TRIM(c.name), 40) AS "DESCRICAO",
                s.acronym AS "UF"
            FROM unico_cities c
            JOIN unico_states s ON c.state_id = s.id
            WHERE c.name IS NOT NULL
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhuma cidade encontrada.");
            return;
        }

        $this->info("Processando {$total} cidades...");
        $processed = 0;
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $data = [
                'CODIGO' => $row->CODIGO,
                'CODIBGE' => $row->CODIBGE,
                'DESCRICAO' => $row->DESCRICAO,
                'UF' => $row->UF
            ];

            if (!$this->recordExists('CIDADES', 'CODIGO', $row->CODIGO)) {
                $this->insertIntoFirebird('CIDADES', $data);
                $created++;
            } else {
                // Aqui podemos tomar "outra decisão". Por padrão, atualizamos.
                $this->updateInFirebird('CIDADES', 'CODIGO', $row->CODIGO, $data);
                $updated++;
            }

            $processed++;
            if ($processed % 100 === 0) {
                $this->comment("Processados: {$processed}");
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
