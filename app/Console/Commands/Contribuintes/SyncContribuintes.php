<?php

namespace App\Console\Commands\Contribuintes;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncContribuintes extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-contribuintes 
                            {--force : Força a sincronização mesmo se já estiver sincronizado}
                            {--company= : Código da empresa no banco principal} 
                            {--limit= : Limite de registros para sincronizar}
                            {--cnpj= : CNPJ específico para sincronizar}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os contribuintes diretamente com o banco Firebird usando Stored Procedures';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        if ($this->option('force')) {
            $this->info("Resetando flag de sincronização em export_contribuintes...");
            DB::table('export_contribuintes')->update(['synced' => false]);
        }

        // $companyCode = $this->option('company');
        $companyCode = 57;
        $this->info("Iniciando busca de contribuintes no PostgreSQL...");

        $query = DB::table('export_contribuintes as ec')
            ->where('ec.synced', false)
            ->select('ec.*');

        if ($this->option('cnpj')) {
            $query->where('ec.VCPF_CNPJ', preg_replace('/[^0-9]/', '', $this->option('cnpj')));
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('IID_CONTRIBUINTE', 'asc')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum contribuinte encontrado.");
            return;
        }

        $this->info("Conectando ao Firebird para a empresa " . ($companyCode ?: 'Padrão') . "...");
        $pdo = $this->initializeFirebird($companyCode);

        $this->info("Processando {$total} contribuintes...");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $created = 0;
        $updated = 0;
        $failures = [];
        $syncedIds = [];
        $batchSize = 50;

        /// $stmtVer = $pdo->prepare('SELECT CODCONTRIBUINTE FROM VERCONTRIBUINTE_5(?, ?)');
        $stmtGrava = $pdo->prepare('SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM MIGRACAO_GRAVACONTRIBUINTE_1(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        foreach ($results as $index => $row) {
            $cpfCnpj = str_replace(['.', '-', '/'], '', (string)$row->VCPF_CNPJ);

            try {
                // $stmtVer->execute([$cpfCnpj, '']);
                // $existingData = $stmtVer->fetch();
                // $stmtVer->closeCursor();
                // $existing = $existingData?->CODCONTRIBUINTE ?? null;
                $existing = false;

                if (!$existing) {
                    $params = [
                        $row->IID_CONTRIBUINTE,            // IID_CONTRIBUINTE (BIGINT)
                        $cpfCnpj,                          // VCPF_CNPJ (VARCHAR(18))
                        $row->DDATA_INICIO_ATIVIDADE ?? NULL, // DDATA_INICIO_ATIVIDADE (DATE)
                        substr($row->VRAZAO_SOCIAL, 0, 100),               // VRAZAO_SOCIAL (VARCHAR(100))
                        substr($row->VNOME_FANTASIA, 0, 100),              // VNOME_FANTASIA (VARCHAR(100))
                        $row->VDDD_TELEFONE_1,             // VDDD_TELEFONE_1 (VARCHAR(30))
                        $row->VEMAIL,                      // VEMAIL (VARCHAR(100))
                        (int)$row->ICODIGO_MUNICIPIO_IBGE, // ICODIGO_MUNICIPIO_IBGE (INTEGER)
                        str_replace('-', '', (string)$row->VCEP), // VCEP (VARCHAR(15))
                        $row->VBAIRRO,                     // VBAIRRO (VARCHAR(100))
                        $row->VNUMERO ?? NULL,             // VNUMERO (VARCHAR(15))
                        $row->VDESCRICAO_TIPO_DE_LOGRADOURO, // VDESCRICAO_TIPO_DE_LOGRADOURO (VARCHAR(50))
                        $row->VLOGRADOURO,                 // VLOGRADOURO (VARCHAR(100))
                        $row->VCOMPLEMENTO,                // VCOMPLEMENTO (VARCHAR(100))
                        $row->VINSCESTADUAL,               // VINSCESTADUAL (VARCHAR(12))
                        (int)($row->LOPCAO_PELO_MEI ?? 0), // LOPCAO_PELO_MEI (BOOLEAN/SMALLINT 0/1)
                        (int)($row->LOPCAO_PELO_SIMPLES ?? 0), // LOPCAO_PELO_SIMPLES (BOOLEAN/SMALLINT 0/1)
                        $row->VREGIME_TRIBUTARIO,          // VREGIME_TRIBUTARIO (VARCHAR(100))
                        $row->VNATUREZA_JURIDICA,          // VNATUREZA_JURIDICA (VARCHAR(100))
                        $row->VPORTE                       // VPORTE (VARCHAR(100))
                    ];

                    $stmtGrava->execute($params);
                    $result = $stmtGrava->fetch();
                    $stmtGrava->closeCursor();

                    if ($result && isset($result->CODCONTRIBUINTE)) {
                        $created++;
                    } else {
                        $sqlLog = 'SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM MIGRACAO_GRAVACONTRIBUINTE_1(' . implode(', ', array_map(function ($p) {
                            return is_null($p) ? 'NULL' : "'" . str_replace("'", "''", (string)$p) . "'";
                        }, $params)) . ')';

                        $failures[] = [
                            'id' => $row->IID_CONTRIBUINTE,
                            'nome' => $row->VRAZAO_SOCIAL,
                            'erro' => 'Falha ao gravar (CODCONTRIBUINTE nulo)',
                            'sql' => $sqlLog
                        ];
                    }
                } else {
                    $updated++;
                }

                $syncedIds[] = $row->IID_CONTRIBUINTE;

                if (count($syncedIds) >= $batchSize) {
                    $pdo->commit();
                    $pdo->beginTransaction();

                    DB::table('export_contribuintes')
                        ->whereIn('IID_CONTRIBUINTE', $syncedIds)
                        ->update(['synced' => true]);

                    $syncedIds = [];
                }
            } catch (\Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }

                $sqlLog = isset($params) ? 'SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM MIGRACAO_GRAVACONTRIBUINTE_1(' . implode(', ', array_map(function ($p) {
                    return is_null($p) ? 'NULL' : "'" . str_replace("'", "''", (string)$p) . "'";
                }, $params)) . ')' : 'N/A';

                $failures[] = [
                    'id' => $row->IID_CONTRIBUINTE,
                    'nome' => $row->VRAZAO_SOCIAL,
                    'erro' => $e->getMessage(),
                    'sql' => $sqlLog
                ];
                $pdo->beginTransaction();
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if (count($syncedIds) > 0) {
            DB::table('export_contribuintes')
                ->whereIn('IID_CONTRIBUINTE', $syncedIds)
                ->update(['synced' => true]);
        }

        if (count($failures) > 0) {
            $this->error("Falhas detectadas (" . count($failures) . "):");
            $this->table(['ID', 'RAZÃO SOCIAL', 'ERRO', 'SQL'], array_map(function ($f) {
                return [$f['id'], substr($f['nome'], 0, 50), substr($f['erro'], 0, 80), $f['sql']];
            }, $failures));
        }

        $this->info("Sincronização Finalizada!");
        $this->line("Total Processado: {$total}");
        $this->line("Novos Criados: <info>{$created}</info>");
        $this->line("Já Existentes: <comment>{$updated}</comment>");
        $this->line("Falhas: <error>" . count($failures) . "</error>");
    }
}
