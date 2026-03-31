<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAcordosParcelasQuitacoes extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-acordos-parcelas-quitacoes 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza as baixas de pagamento (quitações) dos boletos de acordos com o Firebird';

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
            $this->info("Resetando flags de sincronização em export_acordos_parcelas_quitacoes...");
            DB::table('export_acordos_parcelas_quitacoes')->update(['synced' => false]);
        }

        $this->info("Buscando baixas pendentes de boletos de acordos...");

        $query = DB::table('export_acordos_parcelas_quitacoes')
            ->where('synced', false);

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IIDENTDAM_MIGRACAO', 'asc')->get();
        $total = count($results);

        if ($total === 0) {
            $this->info("Nenhuma quitação pendente encontrada.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        // Prepara o SQL da Stored Procedure com os 6 parâmetros herdados do layout de IPTU
        $sql = "SELECT RESULTADO FROM {$spName}(?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        $this->info("Processando {$total} baixas...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $params = [
                (int)$row->IIDENTDAM_MIGRACAO,    // 1. IIDENTDAM_MIGRACAO
                (string)$row->DDTPAGTO,          // 2. DDTPAGTO
                (float)$row->NVALPAGO,           // 3. NVALPAGO
                (int)$row->IID_BANCO,            // 4. IID_BANCO
                (string)$row->DDTCREDITO,        // 5. DDTCREDITO
                (string)$row->VAGENCIACONTA      // 6. VAGENCIACONTA
            ];

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                // No layout de quitação, sucesso costuma ser RESULTADO = 0
                $resVal = $result ? (int) $result->RESULTADO : null;
                $isSuccess = ($resVal === 0);

                if ($isSuccess) {
                    DB::table('export_acordos_parcelas_quitacoes')
                        ->where('IIDENTDAM_MIGRACAO', $row->IIDENTDAM_MIGRACAO)
                        ->update(['synced' => true]);
                    $synced++;
                } else {
                    $resValText = $result ? json_encode($result) : 'NULO';
                    $failures[] = [
                        'id' => $row->IIDENTDAM_MIGRACAO,
                        'erro' => "Resposta SP: {$resValText}",
                        'sql' => $this->generateSqlLog($sql, $params)
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IIDENTDAM_MIGRACAO,
                    'erro' => $e->getMessage(),
                    'sql' => $this->generateSqlLog($sql, $params)
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID DAM MIGRACAO', 'ERRO', 'SQL'], array_map(function($f) {
                return [$f['id'], substr($f['erro'], 0, 80), $f['sql']];
            }, array_slice($failures, 0, 10)));
        }

        $this->info("Sincronização de baixas (acordos) concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }

    private function generateSqlLog($sql, $params)
    {
        $indexed = array_map(function ($p) {
            if (is_null($p)) return 'NULL';
            if (is_string($p)) return "'" . str_replace("'", "''", $p) . "'";
            return $p;
        }, $params);

        return preg_replace_callback('/\?/', function() use (&$indexed) {
            return array_shift($indexed);
        }, $sql);
    }
}
