# Exemplos de SQL para Acordos de Dívidas Ativas

Este documento apresenta exemplos de consultas SQL para listar acordos de dívida ativa, relacionando as dívidas originais, os detalhes do acordo e os lançamentos de pagamento.

## Estrutura de Tabelas e Relacionamentos

Com base na análise do banco de dados, o fluxo de dados para um acordo segue esta lógica:

1.  **agreements**: Tabela mestre do acordo (ID, Número do Protocolo, Status).
2.  **agreement_operations**: Contém a operação de negociação que gerou o acordo.
3.  **active_debts_agreement_operations**: Tabela de ligação que associa a operação do acordo às dívidas ativas originais.
4.  **active_debts**: As dívidas ativas originais que foram incluídas no acordo.
5.  **agreement_debts**: Representa as novas parcelas geradas pelo acordo.
6.  **payments**: Lançamentos financeiros (caixa/banco) associados a cada parcela do acordo.

---

## 1. Listar Acordos e suas Dívidas Ativas Originais

Esta consulta mostra quais dívidas ativas compõem cada acordo.

```sql
SELECT 
    a.protocol_number AS num_protocolo,
    a.status AS status_acordo,
    ad.inscription_number AS num_inscricao_da,
    ad.value AS valor_original_da,
    ad.inscription_date AS data_inscricao,
    ao.date_agreement AS data_acordo
FROM agreements a
JOIN agreement_operations ao ON a.agreement_operation_id = ao.id
JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = ao.id
JOIN active_debts ad ON ad.id = adao.active_debt_id
ORDER BY a.protocol_number;
```

---

## 2. Listar Parcelas do Acordo e Status de Pagamento

Esta consulta detalha o novo parcelamento gerado pelo acordo e se cada parcela já foi paga.

```sql
SELECT 
    a.protocol_number AS num_protocolo,
    adebt.parcel_number AS num_parcela,
    adebt.due_date AS vencimento_parcela,
    adebt.value AS valor_parcela,
    CASE 
        WHEN p.id IS NOT NULL THEN 'Pago'
        ELSE 'Pendente'
    END AS status_pagamento,
    p.total AS valor_pago,
    p.created_at AS data_pagamento
FROM agreements a
JOIN agreement_debts adebt ON adebt.agreement_id = a.id
LEFT JOIN payments p ON p.id = adebt.payment_id
ORDER BY a.protocol_number, adebt.parcel_number;
```

---

## 3. Consulta Completa (Dívidas Originais + Parcelas + Pagamentos)

Uma visão consolidada que une a origem da dívida com o resultado financeiro do acordo.

```sql
SELECT 
    a.protocol_number,
    a.status AS status_acordo,
    STRING_AGG(DISTINCT ad.inscription_number, ', ') AS dividas_originais,
    COUNT(DISTINCT adebt.id) AS total_parcelas,
    SUM(DISTINCT adebt.value) AS valor_total_acordado,
    COUNT(p.id) AS parcelas_pagas,
    SUM(p.total) AS total_recebido
FROM agreements a
JOIN agreement_operations ao ON a.agreement_operation_id = ao.id
JOIN active_debts_agreement_operations adao ON adao.agreement_operation_id = ao.id
JOIN active_debts ad ON ad.id = adao.active_debt_id
LEFT JOIN agreement_debts adebt ON adebt.agreement_id = a.id
LEFT JOIN payments p ON p.id = adebt.payment_id
GROUP BY a.id, a.protocol_number, a.status
ORDER BY a.protocol_number;
```

> [!NOTE]
> A tabela `agreement_debts` é utilizada para o controle das parcelas do acordo. O campo `payment_id` nesta tabela liga a parcela diretamente ao lançamento financeiro na tabela `payments`.
