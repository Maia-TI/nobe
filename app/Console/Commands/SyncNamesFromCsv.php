<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SyncNamesFromCsv extends Command
{
    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-names-csv {--dry-run : Apenas simula as atualizações}';

    /**
     * A descrição do comando.
     */
    protected $description = 'Atualiza o social_name (PF) e name/trade_name (PJ) no banco de dados com base nos nomes contidos nos CSVs, pesquisando pelo CPF/CNPJ';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $dryRun = $this->option('dry-run');
        $csvBaseDir = app_path('Console/Commands/cpf_cnpjs');

        $this->info("Carregando nomes dos CSVs...");
        $csvNames = $this->loadCsvNames($csvBaseDir);
        $this->info("Total de documentos únicos no CSV: " . count($csvNames));

        $updatedPf = 0;
        $updatedPj = 0;

        $this->info("Iniciando sincronização...");

        // 1. Sincronizar Pessoa Física (unico_individuals)
        $this->info("Processando unico_individuals...");
        $individuals = DB::table('unico_individuals')
            ->whereNotNull('cpf')
            ->select('id', 'cpf', 'social_name')
            ->get();

        foreach ($individuals as $ind) {
            $cpfClean = str_replace(['.', '-', '/'], '', $ind->cpf);
            if (isset($csvNames[$cpfClean])) {
                $newName = $csvNames[$cpfClean];
                if ($this->normalizeName($ind->social_name) !== $this->normalizeName($newName)) {
                    $this->line("PF [{$ind->id}]: '{$ind->social_name}' -> '{$newName}'");
                    if (!$dryRun) {
                        DB::table('unico_individuals')
                            ->where('id', $ind->id)
                            ->update(['social_name' => $newName]);
                    }
                    $updatedPf++;
                }
            }
        }

        // 2. Sincronizar Pessoa Jurídica (unico_companies)
        $this->info("Processando unico_companies...");
        $companies = DB::table('unico_companies')
            ->whereNotNull('cnpj')
            ->select('id', 'cnpj', 'name', 'trade_name')
            ->get();

        foreach ($companies as $comp) {
            $cnpjClean = str_replace(['.', '-', '/'], '', $comp->cnpj);
            if (isset($csvNames[$cnpjClean])) {
                $newName = $csvNames[$cnpjClean];

                $currentName = $comp->name ?? '';
                if ($this->normalizeName($currentName) !== $this->normalizeName($newName)) {
                    $this->line("PJ [{$comp->id}]: '{$currentName}' -> '{$newName}'");
                    if (!$dryRun) {
                        DB::table('unico_companies')
                            ->where('id', $comp->id)
                            ->update(['name' => $newName]);
                    }
                    $updatedPj++;
                }
            }
        }

        if ($dryRun) {
            $this->warn("\nMODO SIMULAÇÃO (DRY RUN): Nenhuma alteração foi gravada no banco.");
        }

        $this->info("\nConcluído!");
        $this->info("PF atualizadas: {$updatedPf}");
        $this->info("PJ atualizadas: {$updatedPj}");
    }

    private function loadCsvNames($baseDir)
    {
        $names = [];
        $files = [
            'PF' => $baseDir . '/REL PESSOA FISICA.csv',
            'PJ' => $baseDir . '/REL PESSOA JURIDICA.csv'
        ];

        foreach ($files as $filePath) {
            if (!file_exists($filePath)) continue;

            if (($handle = fopen($filePath, "r")) !== FALSE) {
                fgetcsv($handle, 0, ";"); // Skip header
                while (($row = fgetcsv($handle, 0, ";")) !== FALSE) {
                    if (count($row) < 3) continue;

                    $nome = trim($row[1] ?? '');
                    $cpfCnpjRaw = $row[2] ?? '';
                    $cpfCnpjClean = str_replace(['.', '-', '/'], '', trim($cpfCnpjRaw));

                    if (!empty($cpfCnpjClean) && !empty($nome)) {
                        $names[$cpfCnpjClean] = $nome;
                    }
                }
                fclose($handle);
            }
        }
        return $names;
    }

    private function normalizeName($name)
    {
        if (is_null($name)) return '';
        $name = mb_strtoupper(trim($name));
        $name = iconv('UTF-8', 'ASCII//TRANSLIT', $name);
        $name = preg_replace('/[^A-Z0-9]/', '', $name);
        return $name;
    }
}
