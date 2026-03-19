<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function queryToMarkdown($sql, $title) {
    echo "#### " . $title . "\n\n";
    try {
        $results = DB::select($sql);
        if (count($results) === 0) {
            echo "_Nenhum registro encontrado_\n\n";
            return;
        }

        $headers = array_keys((array)$results[0]);
        echo "| " . implode(" | ", $headers) . " |\n";
        echo "|" . str_repeat("--|", count($headers)) . "\n";

        foreach ($results as $row) {
            $rowArray = (array)$row;
            // Escape pipe characters to not break markdown table
            $rowValues = array_map(function($val) {
                return str_replace('|', '\|', (string)$val);
            }, array_values($rowArray));
            echo "| " . implode(" | ", $rowValues) . " |\n";
        }
        echo "\n";
    } catch (\Exception $e) {
        echo "_Erro ao executar a query: " . $e->getMessage() . "_\n\n";
    }
}

$pfSql = <<<SQL
SELECT
    ind.id as "ID",
    ind.id as "CODIGO",
    'F' as "PESSOA",
    LEFT(TRIM(ind.social_name), 50) as "DESCRICAO",
    REGEXP_REPLACE(ind.cpf, '[^0-9]', '', 'g') as "CPF_CNPJ",
    NULL as "RAZSOCIAL",
    REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "CEP",
    a.address_city_id as "CODCIDADE",
    a.neighborhood_id as "CODBAIRRO",
    a.street_id as "CODLOGRADOURO",
    a.number as "NUMERO",
    LEFT(a.complement, 30) as "COMPLEMENTO",
    ind.birthdate as "DTNASCIMENTO",
    ident.rg as "RG",
    NULL as "TELEFONE",
    NULL as "INSCESTADUAL",
    NULL as "EMAIL",
    NULL as "DTINICIOATIVIDADE",
    ind.id as "IDENTMIGRACAO"
FROM unico_individuals ind
LEFT JOIN (
    SELECT addressable_id, zip_code, address_city_id, neighborhood_id, street_id, number, complement,
           ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
    FROM unico_addresses
    WHERE addressable_type = 'Person'
) a ON ind.id = a.addressable_id AND a.rn = 1
LEFT JOIN (
    SELECT individual_id, MAX(number) as rg
    FROM unico_identities
    GROUP BY individual_id
) ident ON ind.id = ident.individual_id
WHERE ind.cpf IS NOT NULL
LIMIT 5;
SQL;

$pjSql = <<<SQL
SELECT
    comp.id + 50000 as "ID",
    comp.id + 50000 as "CODIGO",
    'J' as "PESSOA",
    LEFT(TRIM(COALESCE(comp.name, comp.trade_name)), 50) as "DESCRICAO",
    REGEXP_REPLACE(comp.cnpj, '[^0-9]', '', 'g') as "CPF_CNPJ",
    LEFT(TRIM(COALESCE(comp.name, comp.trade_name)), 75) as "RAZSOCIAL",
    REGEXP_REPLACE(a.zip_code, '[^0-9]', '', 'g') as "CEP",
    a.city_id as "CODCIDADE",
    a.neighborhood_id as "CODBAIRRO",
    a.street_id as "CODLOGRADOURO",
    a.number as "NUMERO",
    LEFT(a.complement, 30) as "COMPLEMENTO",
    NULL as "DTNASCIMENTO",
    NULL as "RG",
    NULL as "TELEFONE",
    comp.state_registration as "INSCESTADUAL",
    NULL as "EMAIL",
    comp.register_date as "DTINICIOATIVIDADE",
    comp.id + 50000 as "IDENTMIGRACAO"
FROM unico_companies comp
LEFT JOIN (
    SELECT addressable_id, zip_code, city_id, neighborhood_id, street_id, number, complement,
           ROW_NUMBER() OVER(PARTITION BY addressable_id ORDER BY created_at DESC) as rn
    FROM unico_addresses
    WHERE addressable_type = 'Person'
) a ON comp.id = a.addressable_id AND a.rn = 1
WHERE comp.cnpj IS NOT NULL
LIMIT 5;
SQL;

$alvarasSql = <<<SQL
SELECT
    p.id AS "ID",
    p.economic_registration_id AS "CODIGO_EMPRESA",
    p.number AS "NUMERO_ALVARA",
    p.year AS "ANO_ALVARA",
    p.due_date AS "DATA_VENCIMENTO",
    p.status AS "SITUACAO",
    REGEXP_REPLACE(c.cnpj, '[^0-9]', '', 'g') AS "DOCUMENTO_CNPJ",
    LEFT(TRIM(COALESCE(c.name, c.trade_name)), 100) AS "RAZAO_SOCIAL",
    LEFT(p.process_number, 50) AS "PROCESSO",
    p.created_at AS "DATA_EMISSAO"
FROM permits p
INNER JOIN unico_companies c
    ON p.economic_registration_id = c.id
WHERE c.cnpj IS NOT NULL
ORDER BY p.id
LIMIT 5;
SQL;

queryToMarkdown($pfSql, "Amostra de Dados: Pessoas Físicas (PF)");
queryToMarkdown($pjSql, "Amostra de Dados: Pessoas Jurídicas (PJ)");
queryToMarkdown($alvarasSql, "Amostra de Dados: Alvarás");

