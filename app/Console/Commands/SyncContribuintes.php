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
                            {--limit= : Limite de registros para sincronizar}';

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
            ->leftJoin('unico_cities as city', 'ec.CODCIDADE', '=', 'city.id')
            ->leftJoin('unico_neighborhoods as neigh', 'ec.CODBAIRRO', '=', 'neigh.id')
            ->leftJoin('unico_streets as str', 'ec.CODLOGRADOURO', '=', 'str.id')
            ->leftJoin('unico_street_types as st_type', 'str.street_type_id', '=', 'st_type.id')
            ->select(
                'ec.*',
                'city.code as CITY_IBGE',
                'neigh.name as BAIRRO_NAME',
                'str.name as LOGRADOURO_NAME',
                'st_type.name as TIPO_LOGRADOURO_NAME'
            );

        if (!$this->option('all')) {
            $query->where('ec.synced', false);
        }

        if ($this->option('limit')) {
            $query->limit((int) $this->option('limit'));
        }

        $results = $query->orderBy('ec.IID_CONTRIBUINTE')->get();

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

            // 1. Verificar se o registro existe usando a procedure recomendada
            $stmtVer = $pdo->prepare('SELECT CODCONTRIBUINTE FROM VERCONTRIBUINTE_5(?, ?)');
            $stmtVer->execute([$cpfCnpj, '']);
            $existing = $stmtVer->fetch();

            // 2. Preparar os dados para a procedure de gravação (GRAVACONTRIBUINTE_3)
            // A "outra decisão" (se existe ou não) é tratada pela própria procedure GRAVACONTRIBUINTE_3
            // que geralmente faz um "CREATE OR ALTER" ou decide internamente pelo ID_CONTRIBUINTE

            $stmtGrava = $pdo->prepare('SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM GRAVACONTRIBUINTE_3(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');

            $params = [
                $row->IID_CONTRIBUINTE,            // IID_CONTRIBUINTE (BIGINT)
                $cpfCnpj,                          // VCPF_CNPJ (VARCHAR(18))
                $row->DDATA_INICIO_ATIVIDADE,      // DDATA_INICIO_ATIVIDADE (DATE)
                $row->VRAZAO_SOCIAL,               // VRAZAO_SOCIAL (VARCHAR(100))
                $row->VNOME_FANTASIA,              // VNOME_FANTASIA (VARCHAR(100))
                $row->VDDD_TELEFONE_1,             // VDDD_TELEFONE_1 (VARCHAR(30))
                $row->VEMAIL,                      // VEMAIL (VARCHAR(100))
                (int)$row->ICODIGO_MUNICIPIO_IBGE, // ICODIGO_MUNICIPIO_IBGE (INTEGER)
                str_replace('-', '', (string)$row->VCEP), // VCEP (VARCHAR(15))
                $row->BAIRRO_NAME,                 // VBAIRRO (VARCHAR(100))
                $row->VNUMERO,                     // VNUMERO (VARCHAR(15))
                $row->TIPO_LOGRADOURO_NAME,        // VDESCRICAO_TIPO_DE_LOGRADOURO (VARCHAR(50))
                $row->LOGRADOURO_NAME,             // VLOGRADOURO (VARCHAR(100))
                $row->VCOMPLEMENTO,                // VCOMPLEMENTO (VARCHAR(100))
                $row->VINSCESTADUAL,               // VINSCESTADUAL (VARCHAR(12))
                (int)($row->LOPCAO_PELO_MEI ?? 0), // LOPCAO_PELO_MEI (BOOLEAN/SMALLINT 0/1)
                (int)($row->LOPCAO_PELO_SIMPLES ?? 0), // LOPCAO_PELO_SIMPLES (BOOLEAN/SMALLINT 0/1)
                $row->VREGIME_TRIBUTARIO,          // VREGIME_TRIBUTARIO (VARCHAR(100))
                $row->VNATUREZA_JURIDICA,          // VNATUREZA_JURIDICA (VARCHAR(100))
                $row->VPORTE                       // VPORTE (VARCHAR(100))
            ];

            // Gerar log do SQL para debug
            $sqlLog = 'SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM GRAVACONTRIBUINTE_3(' . implode(', ', array_map(function($p) {
                return "'" . str_replace("'", "''", (string)$p) . "'";
            }, $params)) . ')';
            
            $this->comment("\nCall: " . $sqlLog);

            try {
                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch();

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
