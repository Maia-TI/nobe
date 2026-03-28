<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncCadastrosImobiliarios extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-cadastros-imobiliarios 
                            {--force : Força a sincronização mesmo se já estiver sincronizado}
                            {--company=57 : Código da empresa no banco principal}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os cadastros imobiliários com o banco Firebird usando Stored Procedures';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        if ($this->option('force')) {
            $this->info("Resetando flag de sincronização em export_cadastros_imobiliarios...");
            DB::table('export_cadastros_imobiliarios')->update(['synced' => false]);
        }

        $companyCode = (int) $this->option('company');
        $this->info("Iniciando busca de cadastros imobiliários no PostgreSQL...");

        $query = DB::table('export_cadastros_imobiliarios')
            ->where('synced', false)
            ->select('*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_BCI', 'asc')->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum cadastro imobiliário encontrado para sincronizar.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} cadastros imobiliários...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $stmtGrava = $pdo->prepare('SELECT RESULTADO, ID_BCI FROM MIGRACAO_GRAVAIMOVEL_1(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $params = [
                (int)$row->IID_BCI,                         // IID_BCI integer
                (int)$row->ISTATUS ?: 1,                    // ISTATUS integer (1=active, 2=inactive)
                (int)$row->IID_DISTRITO ?: null,            // IID_DISTRITO integer
                $row->VSETOR,                               // VSETOR varchar(2)
                $row->VQUADRA,                              // VQUADRA varchar(5)
                $row->VLOTE,                                // VLOTE varchar(4)
                $row->VUNIDADE,                             // VUNIDADE varchar(3)
                $row->VINSCANTERIOR,                        // VINSCANTERIOR varchar(20)
                (int)$row->ICODLOGRADOURO ?: null,          // ICODLOGRADOURO integer
                (int)$row->INUMERO ?: null,                 // INUMERO integer
                $row->VCOMPLEMENTO,                         // VCOMPLEMENTO varchar(30)
                (int)$row->ICODBAIRRO ?: null,              // ICODBAIRRO integer
                (int)$row->IID_CONTRIBUINTE ?: null,        // IID_CONTRIBUINTE integer
                (int)$row->IID_CONTRIBUINTEMORADOR ?: null, // IID_CONTRIBUINTEMORADOR integer
                (float)$row->NAREALOTE,                     // NAREALOTE numeric(15,2)
                (float)$row->NAREAEDIFICACAO,               // NAREAEDIFICACAO numeric(15,2)
                (float)$row->NFRACAOIDEAL,                  // NFRACAOIDEAL numeric(15,2)
                (int)$row->INUMPAVIMENTOS ?: null,          // INUMPAVIMENTOS integer
            ];

            $sqlLog = 'SELECT RESULTADO, ID_BCI FROM MIGRACAO_GRAVAIMOVEL_1(' . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : "'" . str_replace("'", "''", (string)$p) . "'";
            }, $params)) . ')';

            try {
                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === "0");

                if ($isSuccess) {
                    DB::table('export_cadastros_imobiliarios')
                        ->where('IID_BCI', $row->IID_BCI)
                        ->update(['synced' => true]);

                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'Nulo';
                    $failures[] = [
                        'id'    => $row->IID_BCI,
                        'setor' => $row->VSETOR,
                        'erro'  => "Resposta SP: {$resVal}",
                        'sql'   => $sqlLog,
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id'    => $row->IID_BCI,
                    'setor' => $row->VSETOR,
                    'erro'  => (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation'))
                        ? "Registro já presente ou erro de integridade: " . substr($e->getMessage(), 0, 150)
                        : $e->getMessage(),
                    'sql'   => $sqlLog,
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID BCI', 'SETOR', 'ERRO', 'SQL'], array_map(function ($f) {
                return [$f['id'], $f['setor'], substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }
}
