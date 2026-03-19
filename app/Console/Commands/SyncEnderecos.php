<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use App\Traits\InteractsWithFirebird;

class SyncEnderecos extends Command
{
    use InteractsWithFirebird;

    /**
     * O nome e a assinatura do comando.
     */
    protected $signature = 'db:sync-enderecos';

    /**
     * A descrição do comando.
     */
    protected $description = 'Sincroniza os endereços diretamente com o banco Firebird';

    /**
     * Execute o comando.
     */
    public function handle()
    {
        $this->info("Iniciando busca de endereços no PostgreSQL...");

        $query = '
            SELECT 
                a.addressable_id AS "CONTRIBUINTE_ID",
                a.zip_code AS "CEP",
                st.name AS "TIPO_LOGRADOURO",
                s.name AS "LOGRADOURO",
                a.number AS "NUMERO",
                a.complement AS "COMPLEMENTO",
                n.name AS "BAIRRO",
                city.name AS "CIDADE",
                state.acronym AS "UF"
            FROM unico_addresses a
            LEFT JOIN unico_streets s ON a.street_id = s.id
            LEFT JOIN unico_street_types st ON a.street_type_id = st.id
            LEFT JOIN unico_neighborhoods n ON a.neighborhood_id = n.id
            LEFT JOIN unico_cities city ON a.city_id = city.id
            LEFT JOIN unico_states state ON city.state_id = state.id
            WHERE a.addressable_type = \'Person\'
        ';

        $results = DB::select($query);
        $total = count($results);

        if ($total === 0) {
            $this->error("Nenhum endereço encontrado.");
            return;
        }

        $this->info("Processando {$total} endereços...");
        $created = 0;
        $updated = 0;

        foreach ($results as $row) {
            $data = [
                'CONTRIBUINTE_ID' => $row->CONTRIBUINTE_ID,
                'CEP' => $row->CEP,
                'TIPO_LOGRADOURO' => $row->TIPO_LOGRADOURO,
                'LOGRADOURO' => $row->LOGRADOURO,
                'NUMERO' => $row->NUMERO,
                'COMPLEMENTO' => $row->COMPLEMENTO,
                'BAIRRO' => $row->BAIRRO,
                'CIDADE' => $row->CIDADE,
                'UF' => $row->UF
            ];

            // Aqui a chave primária pode ser uma composição ou ID. Como não temos um ID claro de endereço no mapeamento original,
            // vamos considerar CONTRIBUINTE_ID + CEP como uma forma de verificar se já existe ou simplesmente inserir.
            // No comando original não havia chave primária clara para endereços.
            // Para seguir o padrão do usuário, vamos verificar pelo CONTRIBUINTE_ID se existir um índice unificado no Firebird.
            
            if (!$this->recordExists('enderecos', 'CONTRIBUINTE_ID', $row->CONTRIBUINTE_ID)) {
                $this->insertIntoFirebird('enderecos', $data);
                $created++;
            } else {
                $this->updateInFirebird('enderecos', 'CONTRIBUINTE_ID', $row->CONTRIBUINTE_ID, $data);
                $updated++;
            }
        }

        $this->info("Sucesso! Criados: {$created}, Atualizados: {$updated}");
    }
}
