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

        $this->info("Processando {$total} cadastros econômicos via Stored Procedures...");
        $synced = 0;

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

            // Gerar log do SQL para debug
            $sqlLog = 'SELECT RESULTADO, ID_CADECONOMICO FROM MIGRACAO_GRAVACADECONOMICO_1(' . implode(', ', array_map(function ($p) {
                return is_null($p) ? 'NULL' : "'" . str_replace("'", "''", (string)$p) . "'";
            }, $params)) . ')';

            $this->comment("\nCall: " . $sqlLog);

            try {
                $stmtGrava->execute($params);
                $result = $stmtGrava->fetch(\PDO::FETCH_OBJ);

                if ($result && isset($result->RESULTADO) && $result->RESULTADO == 1) {
                    $this->info("Cadastro Econômico {$row->IID_CADECONOMICO} sincronizado com sucesso!");
                    
                    DB::table('expor_cadastro_economicos')
                        ->where('IID_CADECONOMICO', $row->IID_CADECONOMICO)
                        ->update(['synced' => true]);
                        
                    $synced++;
                } else {
                    $resVal = $result->RESULTADO ?? 'Nulo';
                    $this->error("Erro ao sincronizar {$row->IID_CADECONOMICO}: Resultado {$resVal}");
                }

            } catch (\Exception $e) {
                $this->error("Erro ao processar cadastro econômico {$row->IID_CADECONOMICO}: " . $e->getMessage());
            }
        }

        $this->info("Sucesso! Sincronizados: {$synced}");
    }
}
