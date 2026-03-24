<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncLancamentoAlvaras extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-lancamento-alvaras 
                            {--company=57 : Código da empresa no banco principal} 
                            {--force : Força a sincronização}
                            {--limit= : Limite de registros para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os lançamentos de alvarás com o Firebird via Stored Procedure';

    /**
     * Stored Procedure principal
     */
    private const SP_NAME = 'MIGRACAO_LANCTOSALVARAS_1';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $companyCode = (int) $this->option('company');
        $spName = self::SP_NAME;

        if ($this->option('force')) {
            DB::table('export_lancamentos_alvaras')->update(['synced' => false]);
        }

        $this->info("Iniciando busca de lançamentos em export_lancamentos_alvaras no PostgreSQL...");

        $query = DB::table('export_lancamentos_alvaras as el')
            ->where('el.synced', false)
            ->select('el.*');

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_LANCAMENTO', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum lançamento pendente encontrado em export_lancamentos_alvaras.");
            return Command::SUCCESS;
        }

        $this->info("Conectando ao Firebird para a empresa {$companyCode}...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} lançamentos...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $synced = 0;
        $failures = [];

        foreach ($results as $row) {
            $stmt = $pdo->prepare("SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(?, ?, ?, ?, ?, ?)");
            $status = [
                1 => 'aberto',
                2 => 'cancelado',
                3 => 'isento',
                4 => 'ativo_divida',
                5 => 'pago',
                6 => 'anulado',
                7 => 'excluido',
                8 => 'isenção',
                9 => 'imunidade',
                10 => 'incentivo',
                11 => 'remissao',
                12 => 'suspenso',
                13 => 'parcelado',
                14 => 'iss',
                15 => 'sem_movimento',
                16 => 'supervisionado',
                17 => 'doacao_em_pagamentos',
                18 => 'prescrito',
                19 => 'transferido',
                20 => 'anistiado',
            ];

            $params = [
                (int)$row->IID_LANCAMENTO,
                (int)$row->IID_CADECONOMICO,
                (string)$row->VANOEXERCICIO,
                (float)$row->NVALIMPOSTOCALC,
                $status[$row->STATUS],
                (string)substr($row->DESCRICAO, 0, 250),
            ];

            $sqlLog = "SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    DB::table('export_lancamentos_alvaras')
                        ->where('IID_LANCAMENTO', $row->IID_LANCAMENTO)
                        ->update([
                            'synced' => true,
                            'id_janela_unica' => $result->ID_LANCAMENTO ?? null
                        ]);

                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $failures[] = [
                        'id' => $row->IID_LANCAMENTO,
                        'econ' => $row->IID_CADECONOMICO,
                        'erro' => "Resposta SP: {$resVal}",
                        'sql' => $sqlLog
                    ];
                }
            } catch (\Exception $e) {
                $failures[] = [
                    'id' => $row->IID_LANCAMENTO,
                    'econ' => $row->IID_CADECONOMICO,
                    'erro' => substr($e->getMessage(), 0, 150),
                    'sql' => $sqlLog
                ];
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['LANÇAMENTO', 'ECONOMICO', 'ERRO', 'SQL'], array_map(function ($f) {
                return [$f['id'], $f['econ'], substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização concluída!");
        $this->line("Sucesso: <info>{$synced}</info>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");

        return Command::SUCCESS;
    }
}
