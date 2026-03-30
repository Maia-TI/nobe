<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncDamIptu extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-dam-iptu 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os DAMs de IPTU com o Firebird via Stored Procedure';

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
            $this->info("Resetando flags de sincronização em export_dam_iptu...");
            DB::table('export_dam_iptu')->update(['synced' => false]);
        }

        $this->info("Iniciando busca de DAMs em export_dam_iptu no PostgreSQL...");

        $query = DB::table('export_dam_iptu as ed')
            ->where('ed.synced', false)
            ->select('ed.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IIDENTMIGRACAO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum DAM pendente encontrado em export_dam_iptu.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        // Prepara a Stored Procedure uma única vez fora do loop
        $stmt = $pdo->prepare("SELECT RESULTADO, ID_DAM FROM MIGRACAO_DAMS_1(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

        $this->info("Processando {$total} DAMs...");
        $bar = $this->output->createProgressBar($total);
        $bar->setFormat(" %current%/%max% [%bar%] %percent:3s%% | ETA: %estimated:-6s% | Speed: %message% | Mem: %memory:6s%");
        $bar->setMessage('Iniciando...');
        $bar->start();
        
        $startTime = microtime(true);
        $synced = 0;
        $failures = [];
        $syncedIds = []; // IDs para atualização em lote no PostgreSQL

        foreach ($results as $row) {


            $params = [
                (int)$row->IIDENTMIGRACAO,      // 1. IIDENTMIGRACAO bigint
                (string)$row->DDTCADASTRO,      // 2. DDTCADASTRO DM_DATE
                (string)$row->THRCADASTRO,      // 3. THRCADASTRO DM_TIME
                (int)$row->IID_LANCAMENTO,      // 4. IID_LANCAMENTO bigint
                (string)$row->VPARCELA,         // 5. VPARCELA DM_VARCHAR_05
                (string)$row->DDTEMISSAO,       // 6. DDTEMISSAO DM_DATE
                (string)$row->DDTVENCIMENTO,    // 7. DDTVENCIMENTO DM_DATE
                (float)$row->NSUBTOTAL,         // 8. NSUBTOTAL DM_NUMERIC_15_2
                (float)$row->NCMONETARIA,       // 9. NCMONETARIA DM_NUMERIC_15_2
                (float)$row->NJUROS,            // 10. NJUROS DM_NUMERIC_15_2
                (float)$row->NMULTA,            // 11. NMULTA DM_NUMERIC_15_2
                (float)$row->NTXEXPEDIENTE,     // 12. NTXEXPEDIENTE DM_NUMERIC_15_2
                (float)$row->NDESCONTO,         // 13. NDESCONTO DM_NUMERIC_15_2
                (float)$row->NTOTPAGAR,         // 14. NTOTPAGAR DM_NUMERIC_15_2
                (int)$row->VNOSSONUMEROMIGRACAO, // 15. VNOSSONUMEROMIGRACAO DM_BIGINT
                (string)$row->VTEXTOCODBARRAS,  // 16. VTEXTOCODBARRAS DM_VARCHAR_75
                (string)$row->VNUMCODBARRAS     // 17. VNUMCODBARRAS DM_VARCHAR_50
            ];

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    $syncedIds[] = $row->IIDENTMIGRACAO;
                    $synced++;

                    // Atualiza o PostgreSQL em lotes de 200 registros
                    if (count($syncedIds) >= 200) {
                        DB::table('export_dam_iptu')->whereIn('IIDENTMIGRACAO', $syncedIds)->update(['synced' => true]);
                        $syncedIds = [];
                        
                        // Atualiza métricas de velocidade a cada lote
                        $elapsed = microtime(true) - $startTime;
                        $rps = round($synced / $elapsed, 2);
                        $bar->setMessage("{$rps} reg/s");
                    }
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $failures[] = [
                        'id' => $row->IIDENTMIGRACAO,
                        'lancamento' => $row->IID_LANCAMENTO,
                        'erro' => "Resposta SP: {$resVal}",
                        'sql' => "SP Call Fail for ID: {$row->IIDENTMIGRACAO}"
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IIDENTMIGRACAO,
                    'lancamento' => $row->IID_LANCAMENTO,
                    'erro' => (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation')) 
                        ? "Registro já presente ou erro de integridade: " . substr($e->getMessage(), 0, 150)
                        : $e->getMessage(),
                    'sql' => "Exception for ID: {$row->IIDENTMIGRACAO}"
                ];
            }
            
            $bar->advance();
        }

        // Atualiza os registros restantes do último lote
        if (!empty($syncedIds)) {
            DB::table('export_dam_iptu')->whereIn('IIDENTMIGRACAO', $syncedIds)->update(['synced' => true]);
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID MIGRACAO', 'LANÇAMENTO', 'ERRO', 'SQL'], array_map(function($f) {
                return [$f['id'], $f['lancamento'], substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");
        
        return Command::SUCCESS;
    }
}
