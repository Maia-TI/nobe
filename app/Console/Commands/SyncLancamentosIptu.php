<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncLancamentosIptu extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-lancamentos-iptu 
                            {--limit= : Limita o número de lançamentos a serem sincronizados}
                            {--force : Força a sincronização mesmo se já estiver sincronizado}
                            {--company=57 : Código da empresa no banco principal}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os lançamentos de IPTU com o banco Firebird usando Stored Procedures';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        if ($this->option('force')) {
            $this->info("Resetando flag de sincronização em export_lancamentos_iptu...");
            DB::table('export_lancamentos_iptu')->update(['synced' => false]);
        }

        $companyCode = $this->option('company') ?: 57;
        $this->info("Buscando lançamentos de IPTU não sincronizados...");

        if ($this->option('limit')) {
            $results = DB::table('export_lancamentos_iptu')
                ->where('synced', false)
                ->limit($this->option('limit'))
                ->get();
        } else {
            $results = DB::table('export_lancamentos_iptu')
                ->where('synced', false)
                ->get();
        }

        $total = count($results);

        if ($total === 0) {
            $this->warn("Nenhum lançamento pendente de sincronização.");
            return;
        }

        $this->info("Conectando ao Firebird (Empresa {$companyCode})...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Sincronizando {$total} lançamentos...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        // Procedure MIGRACAO_LANCTOSIPTU_1 fornecida pelo usuário
        $stmtGrava = $pdo->prepare('SELECT RESULTADO, ID_LANCAMENTO FROM MIGRACAO_LANCTOSIPTU_1(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $successCount = 0;
        $failures = [];
        $syncedIds = [];

        foreach ($results as $row) {
            try {
                $params = [
                    (int)$row->CODLANCAMENTO,
                    (int)$row->CODBCI,
                    (string)$row->ANOEXERCICIO,
                    (float)($row->VVT ?? 0),
                    (float)($row->VVE ?? 0),
                    (float)($row->VVIMOVEL ?? 0),
                    (float)($row->TSU1 ?? 0),
                    (float)($row->TSU2 ?? 0),
                    (float)($row->TSU3 ?? 0),
                    (float)($row->ALIQUOTAIPTU ?? 0),
                    (float)($row->VALIPTU ?? 0),
                    (float)($row->VALIMPOSTO ?? 0),
                    substr($row->INFORMACOESCALCULO ?? '', 0, 1000)
                ];

                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch();
                $stmtGrava->closeCursor();

                // No Firebird, se RESULTADO for 1 (ou conforme lógica da proc), consideramos sucesso
                if ($result && isset($result->ID_LANCAMENTO)) {
                    $syncedIds[] = $row->CODLANCAMENTO;
                    $successCount++;
                } else {
                    $failures[] = "Lançamento {$row->CODLANCAMENTO}: Procedura não retornou ID_LANCAMENTO.";
                }

                if (count($syncedIds) >= 50) {
                    $pdo->commit();
                    $pdo->beginTransaction();
                    DB::table('export_lancamentos_iptu')->whereIn('CODLANCAMENTO', $syncedIds)->update(['synced' => true]);
                    $syncedIds = [];
                }
            } catch (\Exception $e) {
                $failures[] = "Lançamento {$row->CODLANCAMENTO}: " . $e->getMessage();
            }
            $bar->advance();
        }

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if (count($syncedIds) > 0) {
            DB::table('export_lancamentos_iptu')->whereIn('CODLANCAMENTO', $syncedIds)->update(['synced' => true]);
        }

        $bar->finish();
        $this->newLine(2);

        $this->info("Sincronização de IPTU concluída!");
        $this->line("Sucesso: <info>{$successCount}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        if (count($failures) > 0) {
            foreach (array_slice($failures, 0, 10) as $error) {
                $this->error($error);
            }
        }
    }
}
