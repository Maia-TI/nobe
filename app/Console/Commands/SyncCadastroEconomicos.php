<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncCadastroEconomicos extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-cadastro-economicos 
                            {--force : Força a sincronização mesmo se já estiver sincronizado}
                            {--company=57 : Código da empresa no banco principal} 
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os cadastros econômicos diretamente com o banco Firebird usando Stored Procedures';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        if ($this->option('force')) {
            $this->info("Resetando flag de sincronização em expor_cadastro_economicos...");
            DB::table('expor_cadastro_economicos')->update(['synced' => false]);
        }

        $companyCode = (int) $this->option('company');
        $this->info("Iniciando busca de cadastros econômicos no PostgreSQL...");

        $query = DB::table('expor_cadastro_economicos as ece')
            ->where('ece.synced', false)
            ->select('ece.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_CADECONOMICO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum cadastro econômico encontrado para sincronizar.");
            return;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} cadastros econômicos...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $stmtGrava = $pdo->prepare('SELECT RESULTADO, ID_CADECONOMICO FROM MIGRACAO_GRAVACADECONOMICO_1(?, ?, ?, ?, ?, ?)');

            $params = [
                (int)$row->IID_CADECONOMICO,      // IID_CADECONOMICO integer
                (int)$row->IID_CONTRIBUINTE,     // IID_CONTRIBUINTE integer
                (int)$row->ISITUACAO,           // ISITUACAO integer
                $row->VINSCMUNICIPAL,            // VINSCMUNICIPAL varchar(15)
                $row->VANOINSCMUNICIPAL,         // VANOINSCMUNICIPAL varchar(4)
                $row->VOBSERVACOES               // VOBSERVACOES varchar(250)
            ];

            $sqlLog = 'SELECT RESULTADO, ID_CADECONOMICO FROM MIGRACAO_GRAVACADECONOMICO_1(' . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : "'" . str_replace("'", "''", (string)$p) . "'";
            }, $params)) . ')';

            try {
                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === "0");

                if ($isSuccess) {
                    DB::table('expor_cadastro_economicos')
                        ->where('IID_CADECONOMICO', $row->IID_CADECONOMICO)
                        ->update(['synced' => true]);

                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'Nulo';
                    $failures[] = [
                        'id' => $row->IID_CADECONOMICO,
                        'contribuinte' => $row->IID_CONTRIBUINTE,
                        'erro' => "Resposta SP: {$resVal}",
                        'sql' => $sqlLog
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IID_CADECONOMICO,
                    'contribuinte' => $row->IID_CONTRIBUINTE,
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
            $this->table(['ID ECON', 'CONTRIBUINTE', 'ERRO', 'SQL'], array_map(function($f) {
                return [$f['id'], $f['contribuinte'], substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");
        
        return Command::SUCCESS;
    }
}
