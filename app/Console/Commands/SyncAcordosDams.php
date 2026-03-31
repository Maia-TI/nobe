<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAcordosDams extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-acordos-dams 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os DAMs (parcelas) dos acordos com o Firebird';

    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_DAMS_1';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            $this->info("Resetando flags de sincronização em export_acordos_dams...");
            DB::table('export_acordos_dams')->update(['synced' => false]);
        }

        $this->info("Buscando DAMs de acordos pendentes...");

        $query = DB::table('export_acordos_dams')
            ->where('synced', false);

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_DAM', 'asc')->get();
        $total = count($results);

        if ($total === 0) {
            $this->info("Nenhum DAM de acordo pendente encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        // Prepara o SQL da Stored Procedure com os 17 parâmetros
        $sql = "SELECT RESULTADO, ID_DAM FROM {$spName}(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        $this->info("Processando {$total} registros...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $params = [
                (int)$row->IID_DAM,             // 1. IIDENTMIGRACAO
                (string)$row->DDTCADASTRO,      // 2. DDTCADASTRO
                (string)$row->THRCADASTRO,      // 3. THRCADASTRO
                (int)$row->IID_LANCAMENTO,      // 4. IID_LANCAMENTO
                (string)$row->VPARCELA,         // 5. VPARCELA
                (string)$row->DDTEMISSAO,       // 6. DDTEMISSAO
                (string)$row->DDTVENCIMENTO,    // 7. DDTVENCIMENTO
                (float)$row->NSUBTOTAL,         // 8. NSUBTOTAL
                (float)$row->NCMONETARIA,       // 9. NCMONETARIA
                (float)$row->NJUROS,            // 10. NJUROS
                (float)$row->NMULTA,            // 11. NMULTA
                (float)$row->NTXEXPEDIENTE,     // 12. NTXEXPEDIENTE
                (float)$row->NDESCONTO,         // 13. NDESCONTO
                (float)$row->NTOTPAGAR,         // 14. NTOTPAGAR
                (int)$row->VNOSSONUMEROMIGRACAO, // 15. VNOSSONUMEROMIGRACAO
                (string)$row->VTEXTOCODBARRAS,  // 16. VTEXTOCODBARRAS
                (string)$row->VDAMNUMERO         // 17. VDAMNUMERO
            ];

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    DB::table('export_acordos_dams')
                        ->where('IID_DAM', $row->IID_DAM)
                        ->update(['synced' => true]);
                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $failures[] = [
                        'id' => $row->IID_DAM,
                        'erro' => "Resposta SP: {$resVal}",
                        'sql' => $this->generateSqlLog($sql, $params)
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IID_DAM,
                    'erro' => (str_contains($e->getMessage(), 'violation') || str_contains($e->getMessage(), 'Integrity')) 
                        ? "Erro de integridade/duplicidade: " . substr($e->getMessage(), 0, 100)
                        : $e->getMessage(),
                    'sql' => $this->generateSqlLog($sql, $params)
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID Lote', 'Erro', 'SQL'], array_map(function($f) {
                return [$f['id'], substr($f['erro'], 0, 80), substr($f['sql'], 0, 100)];
            }, array_slice($failures, 0, 20)));
        }

        $this->info("Sincronização de DAMs concluída!");
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
