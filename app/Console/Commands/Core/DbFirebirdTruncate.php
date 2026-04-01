<?php

namespace App\Console\Commands\Core;

use Illuminate\Console\Command;
use App\Traits\InteractsWithFirebird;
use Exception;

class DbFirebirdTruncate extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     *
     * @var string
     */
    protected $signature = 'db:firebird-truncate 
                            {--table= : Nome da tabela no Firebird para truncar}
                            {--company=57 : Código da empresa no banco principal}
                            {--force : Pular confirmação}';

    /**
     * A descrição do comando.
     *
     * @var string
     */
    protected $description = 'Executa um DELETE FROM na tabela especificada no banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $table = $this->option('table');
        $companyCode = $this->option('company');

        if (!$table) {
            $this->error("O parâmetro --table é obrigatório.");
            return Command::FAILURE;
        }

        if (!$this->option('force')) {
            if (!$this->confirm("Isso irá APAGAR TODOS os registros da tabela '{$table}' no Firebird. Deseja continuar?", false)) {
                $this->info("Operação cancelada.");
                return Command::SUCCESS;
            }
        }

        try {
            $this->info("Conectando ao Firebird...");
            $pdo = $this->initializeFirebird($companyCode);

            // Nota: Firebird utiliza DELETE FROM para truncar.
            $this->warn("Executando: DELETE FROM {$table}");
            
            $start = microtime(true);
            $deletedCount = $pdo->exec("DELETE FROM {$table}");
            $executionTime = round(microtime(true) - $start, 2);

            $this->info("Tabela '{$table}' truncada com sucesso!");
            $this->line("Registros afetados: <info>{$deletedCount}</info>");
            $this->line("Tempo de execução: <comment>{$executionTime}s</comment>");

            return Command::SUCCESS;
        } catch (Exception $e) {
            $this->error("Erro ao truncar tabela '{$table}': " . $e->getMessage());
            return Command::FAILURE;
        }
    }
}
