<?php

namespace App\Console\Commands\Acordos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncQuitacoesDamsAcordos extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-quitacoes-dams-acordos 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza as quitações de DAMs com o Firebird via Stored Procedure';

    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_DAMS_QUITACOES_1';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            $this->info("Resetando flag de sincronização em export_acordos_parcelas_quitacoes...");
            DB::table('export_acordos_parcelas_quitacoes')->update(['synced' => false]);
        }

        $this->info("Iniciando busca de quitações em export_acordos_parcelas_quitacoes no PostgreSQL...");

        $query = DB::table('export_acordos_parcelas_quitacoes as eq')
            ->where('eq.synced', false)
            ->select('eq.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IIDENTDAM_MIGRACAO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhuma quitação pendente encontrada em export_acordos_parcelas_quitacoes.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} quitações...");
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% | ETA: %estimated:-6s% | Speed: %message% | Mem: %memory:6s%");
        $bar->setMessage('Iniciando...');
        $bar->start();

        $startTime = microtime(true);
        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $stmt = $pdo->prepare("SELECT RESULTADO FROM {$spName}(?, ?, ?, ?, ?, ?)");

            $params = [
                (int)$row->IIDENTDAM_MIGRACAO,    // 1. IIDENTDAM_MIGRACAO bigint
                (string)$row->DDTPAGTO,          // 2. DDTPAGTO date
                (float)$row->NVALPAGO,           // 3. NVALPAGO numeric(15,3)
                (int)$row->IID_BANCO,            // 4. IID_BANCO integer
                (string)$row->DDTCREDITO,        // 5. DDTCREDITO date
                (string)$row->VAGENCIACONTA      // 6. VAGENCIACONTA varchar(20)
            ];

            $sqlLog = "SELECT RESULTADO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';


            // $this->info($sqlLog);

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $resVal = $result ? (int) $result->RESULTADO : null;
                $isSuccess = ($resVal === 0);

                if ($isSuccess) {
                    DB::table('export_acordos_parcelas_quitacoes')->where('IIDENTDAM_MIGRACAO', $row->IIDENTDAM_MIGRACAO)->update(['synced' => true]);
                    $synced++;

                    // Atualiza métricas de velocidade
                    $elapsed = microtime(true) - $startTime;
                    $rps = round($synced / $elapsed, 2);
                    $bar->setMessage("{$rps} reg/s");
                } else {
                    $msg = ($resVal === 1) ? "DAM não encontrado / Não inserido (1)" : "Outro Erro ({$resVal})";
                    $this->error(" -> Falha: {$msg}");

                    // Lazy Log SQL
                    $sqlError = "SELECT RESULTADO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                        return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
                    }, $params)) . ')';

                    $failures[] = [
                        'id' => $row->IIDENTDAM_MIGRACAO,
                        'erro' => $msg,
                        'sql' => $sqlError
                    ];
                }
            } catch (\Exception $e) {
                // Lazy Log SQL
                $sqlError = "SELECT RESULTADO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                    return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
                }, $params)) . ')';

                $failures[] = [
                    'id' => $row->IIDENTDAM_MIGRACAO,
                    'erro' => (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation'))
                        ? "Registro já presente ou erro de integridade."
                        : $e->getMessage(),
                    'sql' => $sqlError
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID DAM MIGRACAO', 'ERRO', 'SQL'], array_map(function ($f) {
                return [$f['id'], substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }
}
