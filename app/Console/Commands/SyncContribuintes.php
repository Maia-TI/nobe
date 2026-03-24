<?php

namespace App\Console\Commands;

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
                            { --force=false : Força a sincronização mesmo se já estiver sincronizado}
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
        $force = $this->option('force');

        if ($force) {
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

        $this->info("Processando {$total} contribuintes via Stored Procedures...");
        $created = 0;
        $updated = 0;
        $syncedIds = [];
        $batchSize = 50; // Reduzido para maior estabilidade

        // Preparar comandos fora do loop para performance
        $stmtVer = $pdo->prepare('SELECT CODCONTRIBUINTE FROM VERCONTRIBUINTE_5(?, ?)');
        $stmtGrava = $pdo->prepare('SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM MIGRACAO_GRAVACONTRIBUINTE_1(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        $errors = 0;
        foreach ($results as $index => $row) {
            $progress = ($index + 1) . '/' . $total;
            $cpfCnpj = str_replace(['.', '-', '/'], '', (string)$row->VCPF_CNPJ);

            try {
                // Verificar existência
                $stmtVer->execute([$cpfCnpj, '']);
                $existingData = $stmtVer->fetch();
                $stmtVer->closeCursor();
                $existing = $existingData?->CODCONTRIBUINTE ?? null;

                if (!$existing) {
                    $this->comment("[{$progress}] Checking: $cpfCnpj - Not found. Inserting...");

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
                        $this->info("[{$progress}] Created: $cpfCnpj - ID: {$result->CODCONTRIBUINTE}");
                        $created++;
                    }
                } else {
                    $this->comment("[{$progress}] Checking: $cpfCnpj - Already exists (ID: $existing)");
                    $updated++;
                }

                $syncedIds[] = $row->IID_CONTRIBUINTE;

                // Commit parcial para evitar locks longos e gerenciar memória
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

                $this->error("\n[{$progress}] Erro no contribuinte {$cpfCnpj}: " . $e->getMessage());
                $errors++;

                // Reiniciar transação após o erro para o próximo item
                $pdo->beginTransaction();
            }
        }

        // Finalizar última leva
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }

        if (count($syncedIds) > 0) {
            DB::table('export_contribuintes')
                ->whereIn('IID_CONTRIBUINTE', $syncedIds)
                ->update(['synced' => true]);
        }

        $this->newLine();
        $this->info("========================================");
        $this->info("Sincronização Finalizada!");
        $this->info("Total Processado: {$total}");
        $this->info("Novos Criados: {$created}");
        $this->info("Já Existentes: {$updated}");
        $this->error("Erros: {$errors}");
        $this->info("========================================");
    }
}
