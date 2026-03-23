<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncLancamentoAlvaras extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-lancamento-alvaras 
                            {--company=57 : Código da empresa no banco principal} 
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os lançamentos de alvarás com o Firebird via Stored Procedure';

    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_LANCTOSALVARAS_1';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        $this->info("Iniciando busca de lançamentos em export_lancamento_alvaras no PostgreSQL...");

        $query = DB::table('export_lancamento_alvaras as el')
            ->where('el.synced', false)
            ->select('el.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_LANCAMENTO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum lançamento pendente encontrado em export_lancamento_alvaras.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} lançamentos via Stored Procedure {$spName}...");
        $synced = 0;

        foreach ($results as $row) {
            $stmt = $pdo->prepare("SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(?, ?, ?, ?)");

            $params = [
                (int)$row->IID_LANCAMENTO,      // IID_LANCAMENTO bigint
                (int)$row->IID_CADECONOMICO,   // IID_CADECONOMICO integer
                (string)$row->VANOEXERCICIO,   // VANOEXERCICIO varchar(4)
                (float)$row->NVALIMPOSTOCALC,  // NVALIMPOSTOCALC numeric(15,2)
            ];

            // Log SQL para debug
            $sqlLog = "SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';

            $this->comment("\nCall: " . $sqlLog);

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    $this->info("Lançamento {$row->IID_LANCAMENTO} sincronizado com sucesso! (ID_LANCTO Firebird: {$result->ID_LANCAMENTO})");
                    
                    DB::table('export_lancamento_alvaras')
                        ->where('IID_LANCAMENTO', $row->IID_LANCAMENTO)
                        ->update(['synced' => true]);
                        
                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $this->error("Erro ao sincronizar {$row->IID_LANCAMENTO}: Resposta {$resVal}");
                }

            } catch (\Exception $e) {
                // Se o erro for de PK ou Unique Key, marcamos como sincronizado
                if (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation')) {
                    $this->warn("Lançamento {$row->IID_LANCAMENTO} já presente no Firebird. Marcando como sincronizado.");
                    
                    DB::table('export_lancamento_alvaras')
                        ->where('IID_LANCAMENTO', $row->IID_LANCAMENTO)
                        ->update(['synced' => true]);
                        
                    $synced++;
                } else {
                    $this->error("Erro ao processar lançamento {$row->IID_LANCAMENTO}: " . $e->getMessage());
                }
            }
        }

        $this->info("Sincronização concluída! Sucesso: {$synced}/{$total}");
        return Command::SUCCESS;
    }
}
