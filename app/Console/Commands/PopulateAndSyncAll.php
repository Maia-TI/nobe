<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class PopulateAndSyncAll extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:populate-and-sync {--prune : Limpa as tabelas locais antes de popular} {--force : Força a sincronização mesmo se já estiver sincronizado}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Executa o ciclo completo de população e sincronização em ordem (Contribuintes, Econômico, Imobiliário, Lançamentos, DAMs, Quitações e Acordos)';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $prune = $this->option('prune');
        $force = $this->option('force');

        $this->info("Iniciando processo completo de migração...");
        if ($prune) {
            $this->warn("Aviso: O modo --prune está ATIVADO (tabelas locais serão truncadas).");
        }
        if ($force) {
            $this->warn("Aviso: O modo --force está ATIVADO (registros serão reenviados ao Firebird).");
        }

        $steps = [
            [
                'label' => 'CONTRIBUINTES',
                'populate' => 'db:populate-export-contribuintes',
                'sync' => 'db:sync-contribuintes'
            ],
            [
                'label' => 'CADASTRO ECONÔMICO',
                'populate' => 'db:populate-export-cadastro-economicos',
                'sync' => 'db:sync-cadastro-economicos'
            ],
            [
                'label' => 'LANÇAMENTOS ALVARÁS',
                'populate' => 'db:populate-export-lancamento-alvaras',
                'sync' => 'db:sync-lancamento-alvaras'
            ],
            [
                'label' => 'DAMs ALVARÁS',
                'populate' => 'db:populate-export-dam-alvaras',
                'sync' => 'db:sync-dam-alvaras'
            ],
            [
                'label' => 'QUITAÇÕES DAMs ALVARÁS',
                'populate' => 'db:populate-export-quitacoes-dams-alvaras',
                'sync' => 'db:sync-quitacoes-dams-alvaras'
            ],
            [
                'label' => 'CADASTRO IMOBILIÁRIO',
                'populate' => 'db:populate-export-cadastros-imobiliarios',
                'sync' => 'db:sync-cadastros-imobiliarios'
            ],
            [
                'label' => 'LANÇAMENTOS IPTU',
                'populate' => 'db:populate-export-lancamentos-iptu',
                'sync' => 'db:sync-lancamentos-iptu'
            ],
            [
                'label' => 'DAMs IPTU',
                'populate' => 'db:populate-export-dam-iptu',
                'sync' => 'db:sync-dam-iptu'
            ],
            [
                'label' => 'QUITAÇÕES DAMs IPTU',
                'populate' => 'db:populate-export-quitacoes-dams-iptu',
                'sync' => 'db:sync-quitacoes-dams-iptu'
            ],
            [
                'label' => 'ACORDOS',
                'populate' => 'db:populate-export-acordos',
                'sync' => 'db:sync-acordos'
            ],
        ];

        foreach ($steps as $step) {
            $this->newLine();
            $this->info("==================================================================");
            $this->info(" ETAPA: {$step['label']}");
            $this->info("==================================================================");

            $this->info("-> Executando POPULATE: {$step['populate']}");
            $this->call($step['populate'], ['--prune' => $prune]);

            $this->newLine();
            $this->info("-> Executando SYNC: {$step['sync']}");
            $this->call($step['sync'], ['--force' => $force]);
        }

        $this->newLine();
        $this->info("==================================================================");
        $this->info(" PROCESSO FINALIZADO COM SUCESSO!");
        $this->info("==================================================================");

        return Command::SUCCESS;
    }
}
