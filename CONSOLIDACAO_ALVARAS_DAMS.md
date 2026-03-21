# Consolidação de Alvarás e Documentos de Arrecadação (DAM)

Este documento centraliza as informações necessárias para extrair o ciclo completo de vida de um Alvará, desde a sua constituição (Lançamento) até a sua baixa financeira (Pagamento).

## 1. Mapeamento do Fluxo

A rastreabilidade dos Alvarás de um Cadastro Econômico segue esta hierarquia:

1.  **Constituição (Assessment):** `payments` + `payment_taxables` (IDs 2, 51, 63, 64).
2.  **Financeiro (Parcels):** `payment_parcels` (Desdobramento em parcelas e vencimentos).
3.  **Documentação (DAMs):** `payment_parcel_identifiers` (Onde reside o Código de Barras e Nosso Número).
4.  **Arrecadação (Baixas):** `payment_entries` + `bank_accounts` + `lower_payments` (Quando e onde foi pago).

---

## 2. SQL Mestre de Extração

Esta query consolida todos os dados em um relatório único de auditoria e exportação:

```sql
SELECT 
    er.id as "ID_CAD_ECONOMICO",
    p.id as "ID_LANCAMENTO",
    p.year as "EXERCICIO",
    r.name as "TIPO_ALVARA",
    pp.parcel_number as "PARCELA",
    pp.due_date as "VENCIMENTO",
    pp.total as "VALOR_PARCELA",
    -- Identificadores Bancários (DAM)
    ppi.document_number as "DAM_NUMERO",
    ppi.digitable_line as "CODG_BARRAS",
    rd.nosso_numero as "NOSSO_NUMERO",
    -- Situação Financeira
    CASE 
        WHEN p.status = 1 THEN 'ABERTO'
        WHEN p.status = 5 THEN 'PAGO'
        WHEN p.status = 13 THEN 'PARCELADO'
        WHEN p.status = 4 THEN 'DIVIDA ATIVA'
        ELSE 'OUTROS (' || p.status || ')'
    END as "SITUACAO",
    -- Detalhamento do Pagamento
    pe.payment_date as "DATA_PAGAMENTO",
    pe.paid_value as "VALOR_RECEBIDO",
    ba.name as "CANAL_RECEBIMENTO",
    ba.number_agreement as "CONVENIO_BANCARIO"
FROM economic_registrations er
-- 1. Vinculação com Receitas de Alvará
JOIN payment_taxables pt ON pt.taxable_id = er.id AND pt.taxable_type = 'EconomicRegistration'
JOIN revenues r ON r.id = pt.revenue_id AND r.id IN (2, 51, 63, 64)
-- 2. Vinculação com o Lançamento e suas Parcelas
JOIN payments p ON p.id = pt.payment_id
JOIN payment_parcels pp ON pp.payment_id = p.id
-- 3. Vinculação com o DAM (Documento Emitido)
LEFT JOIN payment_parcel_identifiers ppi ON ppi.payable_id = pp.id AND ppi.payable_type = 'PaymentParcel'
LEFT JOIN register_debts rd ON rd.payment_parcel_identifier_id = ppi.id
-- 4. Vinculação com o Histórico Bancário de Recebimento
LEFT JOIN payment_entries pe ON pe.payment_parcel_id = pp.id
LEFT JOIN lower_payments lp ON lp.id = pe.parent_id AND pe.parent_type = 'LowerPayment'
LEFT JOIN bank_accounts ba ON ba.id = lp.bank_account_id
ORDER BY er.id, p.year DESC, pp.parcel_number ASC;
```

---

## 3. Guia Técnico de Campos

| Unidade | Campo | Importância |
| :--- | :--- | :--- |
| **Lançamento** | `ID_LANCAMENTO` | A chave primária que agrupa todas as parcelas de um Alvará. |
| **DAM** | `DAM_NUMERO` | O número oficial do documento impresso para o contribuinte. |
| **DAM** | `NOSSO_NUMERO` | O identificador que o banco retorna no arquivo de retorno (CNAB). |
| **Arrecadação** | `DATA_PAGAMENTO` | A data efetiva da baixa no sistema (vinda do banco ou tesouraria). |
| **Canal** | `CONVENIO` | O contrato da prefeitura com o banco para aquela cobrança específica. |
