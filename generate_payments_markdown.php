<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function queryToMarkdown($sql, $title)
{
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
            $rowValues = array_map(function ($val) {
                return str_replace('|', '\|', (string)$val);
            }, array_values($rowArray));
            echo "| " . implode(" | ", $rowValues) . " |\n";
        }
        echo "\n";
    } catch (\Exception $e) {
        echo "_Erro ao executar a query: " . $e->getMessage() . "_\n\n";
    }
}

$pagamentosSql = <<<SQL
SELECT
    p.id AS "ID_PAGAMENTO",
    p.year AS "ANO",
    p.payable_type AS "TIPO_TRIBUTO",
    p.payable_id AS "ID_ALVO",
    p.value AS "VALOR_BASE",
    p.total AS "VALOR_TOTAL",
    pp.min_due_date AS "DATA_VENCIMENTO_MIN",
    pp.sum_value_paid AS "TOTAL_PAGO_PARCELAS",
    c.total_paid AS "TOTAL_ARRECADADO_COLETA"
FROM payments p
LEFT JOIN (
    SELECT payment_id, MIN(due_date) as min_due_date, SUM(value_paid) AS sum_value_paid
    FROM payment_parcels
    GROUP BY payment_id
) pp ON p.id = pp.payment_id
LEFT JOIN (
    SELECT payment_id, SUM(total_paid) AS total_paid
    FROM tax_collections
    GROUP BY payment_id
) c ON p.id = c.payment_id
WHERE p.value > 0
LIMIT 5;
SQL;

$dividasAtivasSql = <<<SQL
SELECT
    ad.id AS "ID_DIVIDA",
    ad.payment_id AS "ID_PAGAMENTO_GERADOR",
    ad.person_id AS "CO_PESSOA",
    ad.due_date AS "DATA_VENCIMENTO_ORIGINAL",
    ad.value AS "VALOR_PRINCIPAL",
    ad.correction AS "CORRECAO",
    ad.fine AS "MULTA",
    ad.interest AS "JUROS",
    (ad.value + ad.correction + ad.fine + ad.interest) AS "TOTAL_DIVIDA",
    ad.registration AS "NUMERO_REGISTRO",
    ad.status AS "STATUS_DIVIDA"
FROM active_debts ad
WHERE ad.value > 0
LIMIT 5;
SQL;

queryToMarkdown($pagamentosSql, "Amostra de Dados Extraídos: Pagamentos e Resumo de Parcelas");
queryToMarkdown($dividasAtivasSql, "Amostra de Dados Extraídos: Dívidas Ativas");
