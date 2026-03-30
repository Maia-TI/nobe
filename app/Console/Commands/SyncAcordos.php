<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAcordos extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-acordos
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     * 
     * 
     */
    protected $description = 'Sincroniza os acordos com o Firebird via Stored Procedure';


    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_PARCELAMENTOS_1';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            DB::table('export_acordos')->update(['synced' => false]);
        }

        $this->info("Iniciando busca de acordos em export_acordos no PostgreSQL...");

        $query = DB::table('export_acordos as ea')
            ->where('ea.synced', false)
            ->select('ea.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_ACORDO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum acordo pendente encontrado em export_acordos.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} DAMs...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $stmt = $pdo->prepare("SELECT RESULTADO, ID_PARCELAMENTO FROM {$spName}(?, ?, ?, ?, ?)");

            $receitaMap = [
                5 => 16,
                12 => 1,
            ];

            $receitaId = $receitaMap[$row->IID_RECEITA];

            $params = [
                $row->IID_ACORDO,
                $row->DDTACORDO,
                $row->IID_CONTRIBUINTE,
                $receitaId,
                $row->VDESCRICAO,
            ];

            $sqlLog = "SELECT RESULTADO, ID_PARCELAMENTO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    DB::table('export_acordos')
                        ->where('IID_ACORDO', $row->ID_PARCELAMENTO)
                        ->update(['synced' => true]);

                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    // $failures[] = [
                    //     'id' => $row->IIDENTMIGRACAO,
                    //     'lancamento' => $row->IID_LANCAMENTO,
                    //     'erro' => "Resposta SP: {$resVal}",
                    //     'sql' => $sqlLog
                    // ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IID_ACORDO,
                    'erro' => (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation'))
                        ? "Registro já presente ou erro de integridade: " . substr($e->getMessage(), 0, 150)
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
            // $this->table(['ID MIGRACAO', 'LANÇAMENTO', 'ERRO', 'SQL'], array_map(function ($f) {
            //     return [$f['id'], $f['lancamento'], substr($f['erro'], 0, 80), $f['sql']];
            // }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }
}
