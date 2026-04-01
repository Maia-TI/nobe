<?php

namespace App\Console\Commands\Acordos;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncAcordos extends Command
{
    use InteractsWithFirebird;

    protected $signature = 'db:sync-acordos
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    protected $description = 'Sincroniza os acordos com o Firebird via Stored Procedure';

    private const SP_NAME = 'MIGRACAO_PARCELAMENTOS_1';

    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            DB::table('export_acordos')->update(['synced' => false]);
            $this->warn('⚠ Forçando reprocessamento de todos os registros.');
        }

        $this->info("Buscando acordos pendentes...");

        $query = DB::table('export_acordos as ea')
            ->where('ea.synced', false)
            ->select('ea.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_ACORDO', 'asc')->get();
        $total = $results->count();

        if ($total === 0) {
            $this->info("Nenhum acordo pendente encontrado.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird (empresa {$companyCode})...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} registros...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            // 🔁 Mapeamento de receita
            $receitaMap = [
                5  => 16,
                12 => 1,
                2 => 3,
                67 => 256,
                50 => 270,
            ];

            $receitaId = $receitaMap[(int)$row->IID_RECEITA] ?? null;

            if (!$receitaId) {
                $failures[] = [
                    'id' => $row->IID_ACORDO,
                    'erro' => "Receita não mapeada: {$row->IID_RECEITA}",
                    'sql' => null
                ];
                $bar->advance();
                continue;
            }

            $params = [
                $row->IID_ACORDO,
                $row->IID_LANCTOACORDO_MIGRACAO,
                $row->DDTACORDO,
                $row->IID_CONTRIBUINTE,
                $receitaId,
                $row->VDESCRICAO,
            ];

            // 🧾 Log SQL "simulado"
            $sqlLog = "SELECT RESULTADO, ID_PARCELAMENTO FROM {$spName}(" .
                implode(', ', array_map(function ($p) {
                    if (is_null($p)) return 'NULL';
                    if (is_string($p)) return "'" . str_replace("'", "''", $p) . "'";
                    return $p;
                }, $params)) . ')';

            try {

                $stmt = $pdo->prepare("SELECT RESULTADO, ID_PARCELAMENTO FROM {$spName}(?,?, ?, ?, ?, ?)");
                $stmt->execute($params);

                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && (
                    $result->RESULTADO == 1 ||
                    $result->RESULTADO === 0 ||
                    $result->RESULTADO === '0'
                );

                if ($isSuccess) {

                    // ✅ FIX PRINCIPAL AQUI
                    DB::table('export_acordos')
                        ->where('IID_ACORDO', $row->IID_ACORDO)
                        ->update(['synced' => true]);

                    $synced++;

                    // Log leve de sucesso (opcional comentar depois)
                    $this->line("✔ ID {$row->IID_ACORDO} | Ret: " . json_encode($result));
                } else {

                    $resVal = $result ? json_encode($result) : 'NULO';

                    $failures[] = [
                        'id' => $row->IID_ACORDO,
                        'erro' => "Resposta inválida da SP: {$resVal}",
                        'sql' => $sqlLog
                    ];
                }
            } catch (\Exception $e) {

                $failures[] = [
                    'id' => $row->IID_ACORDO,
                    'erro' => (
                        str_contains($e->getMessage(), 'violation') ||
                        str_contains($e->getMessage(), 'Integrity')
                    )
                        ? "Duplicado / integridade: " . substr($e->getMessage(), 0, 150)
                        : $e->getMessage(),
                    'sql' => $sqlLog
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        // ❌ MOSTRAR FALHAS
        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");

            $this->table(
                ['ID', 'ERRO', 'SQL'],
                array_map(function ($f) {
                    return [
                        $f['id'],
                        substr($f['erro'], 0, 120),
                        $f['sql'] ? substr($f['sql'], 0, 120) : 'N/A'
                    ];
                }, $failures)
            );
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }
}
