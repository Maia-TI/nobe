<?php

namespace App\Console\Commands\Acordos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAcordosLancamentos extends Command
{
    use InteractsWithFirebird;

    protected $signature = 'db:sync-acordos-lancamentos-origem 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os itens (lançamentos de origem) dos acordos com o Firebird';

    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_PARCELAMENTO_LANCTO_1';


    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            $this->info("Resetando flags de sincronização em export_acordos_lancamentos_origem...");
            DB::table('export_acordos_lancamentos_origem')->update(['synced' => false]);
        }

        $this->info("Buscando itens de acordos pendentes...");

        $query = DB::table('export_acordos_lancamentos_origem')
            ->where('synced', false);

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_LANCAMENTOORIGEM', 'asc')->get();
        $total = count($results);

        if ($total === 0) {
            $this->info("Nenhum item de acordo pendente encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        // Prepara o SQL da Stored Procedure com os 18 parâmetros
        $sql = "SELECT RESULTADO, ID_PARCELAMENTO_LANCTO FROM {$spName}(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);

        $this->info("Processando {$total} registros...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $params = [
                (int) $row->IID_ACORDO,             // 2. IID_ACORDO
                (string) $row->DDTCADASTRO,         // 3. DDTCADASTRO
                (int) $row->IID_LANCAMENTOORIGEM,   // 4. IID_LANCAMENTOORIGEMORIGEM
                (string) $row->VANOEXERCICIO,       // 5. VANOEXERCICIO
                (string) $row->VMESEXERCICIO,
                1,                                  // 7. IID_RECEITA
                (string) $row->VESPECIFICACAO,      // 8. VESPECIFICACAO
                (string) $row->DDTVENCIMENTO,       // 9. DDTVENCIMENTO
                (float) $row->NSUBTOTAL,            // 11. NSUBTOTAL
                (float) $row->NCMONETARIA,          // 12. NCMONETARIA
                (float) $row->NJUROS,               // 13. NJUROS
                (float) $row->NMULTA,               // 14. NMULTA
                (float) $row->NDESCONTO,            // 15. NDESCONTO
                (float) $row->NTOTEXERCICIO          // 18. NTOTEXERCICIO
            ];

            try {
                $sqlLog = $this->generateSqlLog($sql, $params);
                // $this->info("\nExecutando: " . $sqlLog);

                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    DB::table('export_acordos_lancamentos_origem')
                        ->where('IID_LANCAMENTOORIGEM', $row->IID_LANCAMENTOORIGEM)
                        ->update(['synced' => true]);
                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $failures[] = [
                        'id' => $row->IID_LANCAMENTOORIGEM,
                        'acordo' => $row->IID_ACORDO,
                        'erro' => "Resposta SP: {$resVal}",
                        'sql' => $sqlLog
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IID_LANCAMENTOORIGEM,
                    'acordo' => $row->IID_ACORDO,
                    'erro' => (str_contains($e->getMessage(), 'violation') || str_contains($e->getMessage(), 'Integrity'))
                        ? "Erro de integridade/duplicidade: " . substr($e->getMessage(), 0, 100)
                        : $e->getMessage(),
                    'sql' => $sqlLog
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID Lote', 'ID Acordo', 'Erro', 'SQL'], array_map(function ($f) {
                return [$f['id'], $f['acordo'], substr($f['erro'], 0, 80), substr($f['sql'], 0, 100)];
            }, array_slice($failures, 0, 20)));
        }

        $this->info("Sincronização concluída!");
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

        return preg_replace_callback('/\?/', function () use (&$indexed) {
            return array_shift($indexed);
        }, $sql);
    }
}
