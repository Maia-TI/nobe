<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

function queryToMarkdown($sql, $title)
{
    echo "##### " . $title . "\n\n";
    try {
        $results = DB::select($sql);
        if (count($results) === 0) {
            echo "_Nenhum registro encontrado num limite reduzido_\n\n";
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

$propSql = "SELECT id, year, taxable_type, taxable_id, status FROM tax_calculations WHERE taxable_type = 'Property' LIMIT 3;";
$econSql = "SELECT id, year, taxable_type, taxable_id, status FROM tax_calculations WHERE taxable_type = 'EconomicRegistration' LIMIT 3;";
$personSql = "SELECT payment_id, revenue_id, taxable_type, taxable_id FROM payment_taxables WHERE taxable_type = 'Person' LIMIT 3;";

$taxCalcSql = "SELECT id AS payment_id, year, payable_type, payable_id, total FROM payments WHERE payable_type = 'TaxCalculation' LIMIT 3;";
$agreementSql = "SELECT id AS payment_id, year, payable_type, payable_id, total FROM payments WHERE payable_type = 'Agreement' LIMIT 3;";
$issIntelSql = "SELECT id AS payment_id, year, payable_type, payable_id, total FROM payments WHERE payable_type = 'IssIntelPayment' LIMIT 3;";
$otherRevSql = "SELECT id AS payment_id, year, payable_type, payable_id, total FROM payments WHERE payable_type = 'OtherRevenue' LIMIT 3;";

echo "### Exemplos de Dados: Tipos de Alvo Cobrado\n\n";
queryToMarkdown($propSql, "Exemplo de 'Property' (Imóveis em tax_calculations)");
queryToMarkdown($econSql, "Exemplo de 'EconomicRegistration' (Empresas em tax_calculations)");
queryToMarkdown($personSql, "Exemplo de 'Person' (Pessoas em payment_taxables)");

echo "### Exemplos de Dados: Tipos de Origem de Pagamento\n\n";
queryToMarkdown($taxCalcSql, "Exemplo de 'TaxCalculation' (Impostos Calculados em payments)");
queryToMarkdown($agreementSql, "Exemplo de 'Agreement' (Acordos em payments)");
queryToMarkdown($issIntelSql, "Exemplo de 'IssIntelPayment' (Guias ISS Web em payments)");
queryToMarkdown($otherRevSql, "Exemplo de 'OtherRevenue' (Receitas Diversas em payments)");
