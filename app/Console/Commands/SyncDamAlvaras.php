<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncDamAlvaras extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-dam-alvaras 
                            {--company=57 : Código da empresa no banco principal} 
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os DAMs de alvarás com o Firebird via Stored Procedure';

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

        $this->info("Iniciando busca de DAMs em export_dam_alvaras no PostgreSQL...");

        $query = DB::table('export_dam_alvaras as ed')
            ->where('ed.synced', false)
            ->select('ed.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IIDENTMIGRACAO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum DAM pendente encontrado em export_dam_alvaras.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} DAMs via Stored Procedure {$spName}...");
        $synced = 0;

        foreach ($results as $row) {
            // MIGRACAO_DAMS_1 (IIDENTMIGRACAO, DDTCADASTRO, THRCADASTRO, IID_LANCAMENTO, VPARCELA, DDTEMISSAO, DDTVENCIMENTO, NSUBTOTAL, NCMONETARIA, NJUROS, NMULTA, NTXEXPEDIENTE, NDESCONTO, NTOTPAGAR, VNOSSONUMEROMIGRACAO, VTEXTOCODBARRAS, VNUMCODBARRAS)
            $stmt = $pdo->prepare("SELECT RESULTADO, ID_DAM FROM {$spName}(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");

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
                (int)$row->VNOSSONUMEROMIGRACAO,// 15. VNOSSONUMEROMIGRACAO DM_BIGINT
                (string)$row->VTEXTOCODBARRAS,  // 16. VTEXTOCODBARRAS DM_VARCHAR_75
                (string)$row->VNUMCODBARRAS     // 17. VNUMCODBARRAS DM_VARCHAR_50
            ];

            // Log SQL para debug
            $sqlLog = "SELECT RESULTADO, ID_DAM FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';

            $this->comment("\nCall: " . $sqlLog);

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    $this->info("DAM {$row->IIDENTMIGRACAO} (Lançamento: {$row->IID_LANCAMENTO}) sincronizado com sucesso! (ID_DAM Firebird: {$result->ID_DAM})");
                    
                    DB::table('export_dam_alvaras')
                        ->where('IIDENTMIGRACAO', $row->IIDENTMIGRACAO)
                        ->update(['synced' => true]);
                        
                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $this->error("Erro ao sincronizar DAM {$row->IIDENTMIGRACAO}: Resposta {$resVal}");
                }

            } catch (\Exception $e) {
                // Se o erro for de PK ou Unique Key, marcamos como sincronizado
                if (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation')) {
                    $this->warn("DAM {$row->IIDENTMIGRACAO} já presente no Firebird. Marcando como sincronizado.");
                    
                    DB::table('export_dam_alvaras')
                        ->where('IIDENTMIGRACAO', $row->IIDENTMIGRACAO)
                        ->update(['synced' => true]);
                        
                    $synced++;
                } else {
                    $this->error("Erro ao processar DAM {$row->IIDENTMIGRACAO}: " . $e->getMessage());
                }
            }
        }

        $this->info("Sincronização de DAMs concluída! Sucesso: {$synced}/{$total}");
        return Command::SUCCESS;
    }
}
