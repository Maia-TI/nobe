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
    protected $signature = 'db:sync-contribuintes';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os contribuintes diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de contribuintes no PostgreSQL...");

        $results = DB::table('export_contribuintes as ec')
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
            )
            ->orderBy('ec.ID')
            ->get();

        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum contribuinte encontrado.");
            return;
        }

        $this->info("Processando {$total} contribuintes via Stored Procedures...");
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $cpfCnpj = str_replace(['.', '-', '/'], '', (string)$row->CPF_CNPJ);
            
            // 1. Verificar se o registro existe usando a procedure recomendada
            $pdo = $this->getFirebirdConnection();
            $stmtVer = $pdo->prepare('SELECT CODCONTRIBUINTE FROM VERCONTRIBUINTE_5(?, ?)');
            $stmtVer->execute([$cpfCnpj, '']);
            $existing = $stmtVer->fetch();

            // 2. Preparar os dados para a procedure de gravação (GRAVACONTRIBUINTE_2)
            // A "outra decisão" (se existe ou não) é tratada pela própria procedure GRAVACONTRIBUINTE_2
            // que geralmente faz um "CREATE OR ALTER" ou decide internamente pelo ID_CONTRIBUINTE
            
            $stmtGrava = $pdo->prepare('SELECT ID_CONTRIBUINTE, CODCONTRIBUINTE FROM GRAVACONTRIBUINTE_2(?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            
            $params = [
                $row->ID,                          // IID_CONTRIBUINTE (BIGINT)
                $cpfCnpj,                          // VCPF_CNPJ (VARCHAR(18))
                $row->DTINICIOATIVIDADE,           // DDATA_INICIO_ATIVIDADE (DATE)
                $row->RAZSOCIAL ?? $row->DESCRICAO, // VRAZAO_SOCIAL (VARCHAR(100))
                $row->DESCRICAO,                   // VNOME_FANTASIA (VARCHAR(100))
                $row->TELEFONE,                    // VDDD_TELEFONE_1 (VARCHAR(30))
                $row->EMAIL,                       // VEMAIL (VARCHAR(100))
                (int)$row->CITY_IBGE,              // ICODIGO_MUNICIPIO_IBGE (INTEGER)
                str_replace('-', '', (string)$row->CEP), // VCEP (VARCHAR(15))
                $row->BAIRRO_NAME,                 // VBAIRRO (VARCHAR(100))
                $row->NUMERO,                      // VNUMERO (VARCHAR(15))
                $row->TIPO_LOGRADOURO_NAME,        // VDESCRICAO_TIPO_DE_LOGRADOURO (VARCHAR(50))
                $row->LOGRADOURO_NAME,             // VLOGRADOURO (VARCHAR(100))
                $row->COMPLEMENTO,                 // VCOMPLEMENTO (VARCHAR(100))
                $row->INSCESTADUAL,                // VINSCESTADUAL (VARCHAR(12))
                0,                                 // LOPCAO_PELO_MEI (BOOLEAN/SMALLINT 0/1)
                0,                                 // LOPCAO_PELO_SIMPLES (BOOLEAN/SMALLINT 0/1)
                null,                              // VREGIME_TRIBUTARIO (VARCHAR(100))
                null,                              // VNATUREZA_JURIDICA (VARCHAR(100))
                null                               // VPORTE (VARCHAR(100))
            ];

            try {
                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch();
                
                if ($existing) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (\Exception $e) {
                $this->error("Erro ao processar contribuinte {$cpfCnpj}: " . $e->getMessage());
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
