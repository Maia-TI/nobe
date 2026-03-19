<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class UpdateExportContribuintesBrasilApi extends Command
{
    /**
     * O nome e a assinatura do comando.
     *
     * @var string
     */
    protected $signature = 'db:update-contribuintes-brasilapi 
                            {--limit=100 : Limite de registros para processar} 
                            {--cnpj= : CNPJ específico para atualizar} 
                            {--sleep=500 : Milissegundos de espera entre requisições para não sobrecarregar a conexão}';

    /**
     * A descrição do comando.
     *
     * @var string
     */
    protected $description = 'Atualiza campos PJ via BrasilAPI com controle de velocidade (throttling) para evitar quedas de conexão';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $limit = (int) $this->option('limit');
        $specificCnpj = $this->option('cnpj');
        $sleepMs = (int) $this->option('sleep');

        $query = DB::table('export_contribuintes')
            ->where('PESSOA', 'J');

        if ($specificCnpj) {
            $query->where('VCPF_CNPJ', preg_replace('/[^0-9]/', '', $specificCnpj));
        } else {
            // Se não for CNPJ específico, busca os que estão com campos nulos ou falsos (padrão)
            $query->where(function ($q) {
                $q->whereNull('VNATUREZA_JURIDICA')
                    ->orWhere('LOPCAO_PELO_MEI', false)
                    ->orWhere('LOPCAO_PELO_SIMPLES', false);
            })
                ->limit($limit);
        }

        $records = $query->orderBy('IID_CONTRIBUINTE', 'asc')->get();

        if ($records->isEmpty()) {
            $this->info("Nenhum registro encontrado para atualizar.");
            return;
        }

        $this->info("Processando " . $records->count() . " registros com intervalo de {$sleepMs}ms...");

        foreach ($records as $index => $record) {
            $cnpj = preg_replace('/[^0-9]/', '', $record->VCPF_CNPJ);
            $this->comment("\n[" . ($index + 1) . "/" . $records->count() . "] Consultando CNPJ: {$cnpj}...");

            if (strlen($cnpj) !== 14) {
                $this->warn("CNPJ inválido: {$cnpj}");
                continue;
            }

            try {
                $response = Http::get("https://brasilapi.com.br/api/cnpj/v1/{$cnpj}");

                if ($response->successful()) {
                    $data = $response->json();

                    $updates = [
                        'VNATUREZA_JURIDICA' => $data['natureza_juridica'] ?? $record->VNATUREZA_JURIDICA,
                        'LOPCAO_PELO_MEI' => isset($data['opcao_pelo_mei']) ? (bool) $data['opcao_pelo_mei'] : $record->LOPCAO_PELO_MEI,
                        'LOPCAO_PELO_SIMPLES' => isset($data['opcao_pelo_simples']) ? (bool) $data['opcao_pelo_simples'] : $record->LOPCAO_PELO_SIMPLES,
                        'VNOME_FANTASIA' => !empty($data['nome_fantasia']) ? $data['nome_fantasia'] : ($data['razao_social'] ?? $record->VNOME_FANTASIA),
                        'VRAZAO_SOCIAL' => $data['razao_social'] ?? $record->VRAZAO_SOCIAL,
                        'VPORTE' => $data['porte'] ?? $record->VPORTE,
                        'ICODIGO_MUNICIPIO_IBGE' => $data['codigo_municipio_ibge'] ?? $record->ICODIGO_MUNICIPIO_IBGE,
                        'VBAIRRO' => !empty($data['bairro']) ? $data['bairro'] : $record->VBAIRRO,
                        'VCEP' => !empty($data['cep']) ? preg_replace('/[^0-9]/', '', $data['cep']) : $record->VCEP,
                        'VLOGRADOURO' => !empty($data['logradouro']) ? $data['logradouro'] : $record->VLOGRADOURO,
                        'VNUMERO' => !empty($data['numero']) ? $data['numero'] : $record->VNUMERO,
                        'VCOMPLEMENTO' => !empty($data['complemento']) ? $data['complemento'] : $record->VCOMPLEMENTO,
                    ];

                    $changes = [];
                    foreach ($updates as $field => $newValue) {
                        $oldValue = $record->{$field};

                        // Normalização para comparação (ex: bool vs int)
                        if ($oldValue != $newValue) {
                            $changes[] = [
                                'campo' => $field,
                                'atual' => is_bool($oldValue) ? ($oldValue ? 'true' : 'false') : (string) $oldValue,
                                'novo' => is_bool($newValue) ? ($newValue ? 'true' : 'false') : (string) $newValue,
                            ];
                        }
                    }

                    if (!empty($changes)) {
                        $this->info("Mudanças encontradas para: {$record->VRAZAO_SOCIAL}");
                        $this->table(['Campo', 'Atual', 'Novo'], $changes);

                        DB::table('export_contribuintes')
                            ->where('IID_CONTRIBUINTE', $record->IID_CONTRIBUINTE)
                            ->update(array_merge($updates, [
                                'synced' => false,
                                'updated_at' => now(),
                            ]));
                        $this->info("Registro atualizado com sucesso!");
                    } else {
                        $this->info("Nenhuma mudança necessária para este registro.");
                    }
                } elseif ($response->status() === 404) {
                    $this->warn("CNPJ {$cnpj} não encontrado na BrasilAPI.");
                } elseif ($response->status() === 429) {
                    $this->error("Rate limit atingido na BrasilAPI. Aguardando 10 segundos...");
                    sleep(10);
                } else {
                    $this->error("Erro ao consultar CNPJ {$cnpj}: Code " . $response->status());
                }
            } catch (\Exception $e) {
                $this->error("Exceção ao processar CNPJ {$cnpj}: " . $e->getMessage());
            }

            // Delay para evitar sobrecarga (throttling)
            if ($sleepMs > 0) {
                usleep($sleepMs * 1000);
            }
        }

        $this->info("\nConcluído!");
    }
}
