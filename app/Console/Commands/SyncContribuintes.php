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
                            {--company= : Código da empresa no banco principal} 
                            {--all : Sincronizar todos os registros, ignorando a coluna synced}
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
        // $companyCode = $this->option('company');
        $companyCode = 57;
        $this->info("Iniciando busca de contribuintes no PostgreSQL...");

        $query = DB::table('export_contribuintes as ec')
            ->select('ec.*');

        if ($this->option('cnpj')) {
            $query->where('ec.VCPF_CNPJ', preg_replace('/[^0-9]/', '', $this->option('cnpj')));
        }

        if (!$this->option('all')) {
            $query->where('ec.synced', false);
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

        foreach ($results as $row) {
            $cpfCnpj = str_replace(['.', '-', '/'], '', (string)$row->VCPF_CNPJ);

            $stmtVer = $pdo->prepare('SELECT CODCONTRIBUINTE FROM VERCONTRIBUINTE_5(?, ?)');
            $stmtVer->execute([$cpfCnpj, '']);
            $existing = $stmtVer->fetch();
            $existing = $existing?->CODCONTRIBUINTE ?? null;

            $stmtGrava = $pdo->prepare('SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM GRAVACONTRIBUINTE_3(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $params = [
                $row->IID_CONTRIBUINTE,            // IID_CONTRIBUINTE (BIGINT)
                $cpfCnpj,                          // VCPF_CNPJ (VARCHAR(18))
                $row->DDATA_INICIO_ATIVIDADE ?? NULL, // DDATA_INICIO_ATIVIDADE (DATE)
                $row->VRAZAO_SOCIAL,               // VRAZAO_SOCIAL (VARCHAR(100))
                $row->VNOME_FANTASIA,              // VNOME_FANTASIA (VARCHAR(100))
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

            // Gerar log do SQL para debug
            $sqlLog = 'SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM GRAVACONTRIBUINTE_3(' . implode(', ', array_map(function ($p) {
                return "'" . str_replace("'", "''", (string)$p) . "'";
            }, $params)) . ')';

            $this->comment("\nCall: " . $sqlLog);

            try {
                $result = null;
                if (!$existing) {
                    $stmtGrava->execute($params);
                    $result = $stmtGrava->fetch();
                }

                if ($result->CODCONTRIBUINTE) {
                    $this->info("Contribuinte {$cpfCnpj} criado com sucesso!");
                }

                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }

                DB::table('export_contribuintes')
                    ->where('IID_CONTRIBUINTE', $row->IID_CONTRIBUINTE)
                    ->update(['synced' => true]);
            } catch (\Exception $e) {
                $this->error("Erro ao processar contribuinte {$cpfCnpj}: " . $e->getMessage());
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
