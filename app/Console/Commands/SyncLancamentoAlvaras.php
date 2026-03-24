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

        $this->info("Processando {$total} lançamentos via Stored Procedure {$spName}...");
        $synced = 0;

        foreach ($results as $row) {
            $stmt = $pdo->prepare("SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(?, ?, ?, ?, ?, ?)");
            /*
            0,  -- simulated
            1,  -- opened 
            2,  -- canceled  → sai
            3,  -- exempt
            4,  -- active_debt
            5,  -- paid
            6,  -- annulled → sai 
            7,  -- excluded → sai
            8,  -- exemption
            9,  -- immunity → sai
            10, -- incentive
            11, -- remission
            12, -- suspended
            13, -- parceled
            14, -- iss
            15, -- without_movement
            16, -- supervised
            17, -- donation_in_payments
            18, -- prescribed
            19, -- transferred
            20  -- amnistied */
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

            // Log SQL para debug
            $sqlLog = "SELECT RESULTADO, ID_LANCAMENTO FROM {$spName}(" . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : (is_string($p) ? "'" . str_replace("'", "''", $p) . "'" : $p);
            }, $params)) . ')';

            $this->comment("\nCall: " . $sqlLog);

            try {
                $stmt->execute($params);
                $result = $stmt->fetch(\PDO::FETCH_OBJ);

                $isSuccess = $result && isset($result->RESULTADO) && ($result->RESULTADO == 1 || $result->RESULTADO === 0 || $result->RESULTADO === '0');

                if ($isSuccess) {
                    $this->info("Lançamento {$row->IID_LANCAMENTO} sincronizado com sucesso! (ID_LANCTO Firebird: {$result->ID_LANCAMENTO})");

                    DB::table('export_lancamentos_alvaras')
                        ->where('IID_LANCAMENTO', $row->IID_LANCAMENTO)
                        ->update(['synced' => true]);

                    $synced++;
                } else {
                    $resVal = $result ? json_encode($result) : 'NULO';
                    $this->error("Erro ao sincronizar {$row->IID_LANCAMENTO}: Resposta {$resVal}");
                }
            } catch (\Exception $e) {
                // Se o erro for de PK ou Unique Key, marcamos como sincronizado
                if (str_contains($e->getMessage(), 'violation of PRIMARY or UNIQUE KEY constraint') || str_contains($e->getMessage(), 'Integrity constraint violation')) {
                    $this->warn("Lançamento {$row->IID_LANCAMENTO} já presente no Firebird. Marcando como sincronizado.");

                    DB::table('export_lancamentos_alvaras')
                        ->where('IID_LANCAMENTO', $row->IID_LANCAMENTO)
                        ->update(['synced' => true]);

                    $synced++;
                } else {
                    $this->error("Erro ao processar lançamento {$row->IID_LANCAMENTO}: " . $e->getMessage());
                }
            }
        }

        $this->info("Sincronização concluída! Sucesso: {$synced}/{$total}");
        return Command::SUCCESS;
    }
}
