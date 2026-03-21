# Estrutura de Lançamentos Tributários

Este documento detalha o mapeamento da entidade de **Lançamentos** (Assessments) no banco de dados, fundamental para a constituição do crédito tributário.

## 1. Entidade Principal: `payments`

A tabela **`payments`** é o cabeçalho do lançamento. Ela agrega todos os débitos fiscais de um contribuinte em um determinado exercício.

| Campo | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | `integer` | ID único do lançamento. |
| `person_id` | `integer` | ID do contribuinte (`unico_people`). |
| `year` | `integer` | Ano de exercício do imposto. |
| `total` | `numeric` | Valor total com acréscimos (o crédito constituído). |
| `status` | `integer` | Situação (5=Pago, 1=Aberto, 2=Vencido, etc). |
| `payable_type` | `string` | Origem técnica do lançamento (ver seção abaixo). |

## 2. Tipos de Lançamento (`payable_type`)

O campo `payable_type` na tabela `payments` define qual processo gerou a dívida. Os tipos identificados são:

*   **`TaxCalculation`**: Lançamentos de tributos calculados pelo sistema (IPTU, ISS Fixo, Taxas de Licença).
*   **`OtherRevenue`**: Receitas diversas, multas isoladas ou taxas avulsas solicitadas pelo contribuinte.
*   **`IssIntelPayment`**: Lançamentos específicos de ISSQN (Imposto sobre Serviços).
*   **`Agreement`**: Refere-se a **Acordos** ou **Parcelamentos** de dívidas anteriores.

## 3. Integração com Receitas (`revenues`)

Para obter o nome e o código da receita (ex: "ISS", "Taxa de Localização"), utilizamos a tabela intermediária **`payment_taxables`**.

| Tabela | Coluna Vínculo | Descrição |
| :--- | :--- | :--- |
| `payment_taxables` | `payment_id` | Liga cada item de receita ao lançamento (`payments`). |
| `payment_taxables` | `revenue_id` | Liga o item à definição da receita (`revenues`). |

---

## 4. Exemplos de Queries SQL

### A. Listar Lançamentos de um Cadastro Econômico com Nomes de Receita
Esta query é a mais completa para relatórios e exportação, unindo o lançamento ao nome da taxa cobrada.

```sql
SELECT 
    er.id as "ID_CAD_ECONOMICO",
    p.id as "ID_LANCAMENTO",
    p.year as "EXERCICIO",
    r.name as "NOME_RECEITA",
    r.acronym as "SIGLA_RECEITA",
    p.total as "VALOR_LANCADO",
    p.status as "STATUS_PAGAMENTO",
    p.payable_type as "TIPO_ORIGEM"
FROM economic_registrations er
-- Vínculo polimórfico fundamental
JOIN payment_taxables pt ON pt.taxable_id = er.id AND pt.taxable_type = 'EconomicRegistration'
JOIN payments p ON p.id = pt.payment_id
JOIN revenues r ON r.id = pt.revenue_id
ORDER BY er.id ASC, p.year DESC;
```

### B. Detalhar Débitos Individuais de um Lançamento
Caso seja necessário ver o valor detalhado de cada rubrica dentro de um lançamento parcelado.

```sql
SELECT 
    td.revenue_acronym as "TAXA",
    td.value as "VALOR_ITEM",
    td.year as "EXERCICIO",
    p.id as "LANCAMENTO_ID"
FROM taxable_debts td
JOIN payments p ON p.id = td.payment_id
WHERE td.taxable_id = :ECON_REG_ID 
  AND td.taxable_type = 'EconomicRegistration';
```

### C. Lançamentos de Alvará (Taxa de Localização e Funcionamento)
Query específica para identificar os alvarás emitidos para os cadastros econômicos.

**IDs de Receita de Alvará Identificados:**
- `2` e `51`: Taxa de Licença para Localização e Funcionamento.
- `63` e `64`: Taxa de Licença para Funcionamento em Horário Especial.

```sql
SELECT 
    er.id as "ID_CAD_ECONOMICO",
    p.year as "EXERCICIO",
    p.id as "ID_LANCAMENTO",
    r.name as "TIPO_ALVARA",
    p.total as "VALOR_ALVARA",
    p.status as "STATUS_PAGAMENTO"
FROM economic_registrations er
JOIN payment_taxables pt ON pt.taxable_id = er.id AND pt.taxable_type = 'EconomicRegistration'
JOIN payments p ON p.id = pt.payment_id
JOIN revenues r ON r.id = pt.revenue_id
WHERE r.id IN (2, 51, 63, 64) 
ORDER BY er.id, p.year DESC;
```

### D. Lançamentos Parcelados vs Não Parcelados
Consultas baseadas no status de parcelamento (`status = 13`).

**Mapa de Status do Campo `payments.status`:**
- `1`: Aberto | `2`: Cancelado | `4`: Dívida Ativa | `5`: Pago | `13`: **Parcelado**

#### i. Somente Lançamentos Parcelados (Mais de uma Parcela)
```sql
SELECT 
    p.id as "ID_LANCAMENTO",
    p.year as "EXERCICIO",
    pp.parcel_number as "PARCELA",
    pp.due_date as "VENCIMENTO",
    pp.total as "VALOR_PARCELA"
FROM payments p
INNER JOIN payment_parcels pp ON pp.payment_id = p.id
WHERE p.status = 13
ORDER BY p.id, pp.parcel_number ASC;
```

#### ii. Lançamentos Não Parcelados (Cota Única ou Outros Status)
```sql
SELECT 
    p.id as "ID_LANCAMENTO",
    p.year as "EXERCICIO",
    p.total as "VALOR_LANCADO",
    p.status as "COD_STATUS"
FROM payments p
WHERE p.status != 13 -- Tudo que não é parcelado
ORDER BY p.id ASC;
```

### E. Histórico de Pagamentos e Datas
Informações sobre quando os lançamentos foram efetivamente pagos/baixados.

**Entidade de Baixa:** `payment_entries` (Vinculada via `payment_parcel_id`).

```sql
SELECT 
    p.id as "ID_LANCAMENTO",
    pp.parcel_number as "PARCELA",
    pp.due_date as "VENCIMENTO",
    pe.payment_date as "DATA_PAGAMENTO",
    pe.paid_value as "VALOR_PAGO",
    pe.status as "STATUS_BAIXA"
FROM payments p
JOIN payment_parcels pp ON pp.payment_id = p.id
JOIN payment_entries pe ON pe.payment_parcel_id = pp.id
WHERE p.person_id = :CONTRIBUINTE_ID
ORDER BY p.id, pp.parcel_number;
```

### F. Consolidação de Arrecadação (Lançamento -> DAM -> Pagamento)
Query mestre para rastreabilidade total desde a origem do débito até o documento bancário e o canal de recebimento.

```sql
SELECT 
    p.id as "ID_LANCAMENTO",
    pp.parcel_number as "PARCELA",
    ppi.document_number as "NUMERO_DAM", 
    ppi.digitable_line as "CODG_BARRAS",
    ba.name as "CANAL_RECEBIMENTO", -- Ex: BANCO DO BRASIL, TESOURARIA
    pe.payment_date as "DATA_PAGAMENTO",
    pe.paid_value as "VALOR_RECEBIDO"
FROM payments p
JOIN payment_parcels pp ON pp.payment_id = p.id
-- Busca o documento gerado (DAM)
LEFT JOIN payment_parcel_identifiers ppi ON ppi.payable_id = pp.id AND ppi.payable_type = 'PaymentParcel'
-- Busca a baixa e o canal (Banco/Tesouraria)
LEFT JOIN payment_entries pe ON pe.payment_parcel_id = pp.id
LEFT JOIN lower_payments lp ON lp.id = pe.parent_id AND pe.parent_type = 'LowerPayment'
LEFT JOIN bank_accounts ba ON ba.id = lp.bank_account_id
ORDER BY p.id, pp.parcel_number;
```

### G. Detalhamento de Convênios e Canais Bancários
Identificação técnica dos contratos bancários (Convênios) onde os pagamentos são creditados.

**Campos Chave para o Convênio:**
- `bank_accounts.number_agreement`: O número do Convênio/Contrato com o banco.
- `bank_accounts.portfolio` / `variation`: Carteira e Variação bancária.
- `banks.name` / `agencies.number`: Identificação da agência e banco.

```sql
SELECT 
    p.id as "ID_LANCAMENTO",
    pe.payment_date as "DATA_RECEBIMENTO",
    b.name as "BANCO_NOME",
    b.code as "FEBRABAN",
    a.number as "AGENCIA",
    ba.account_number as "CONTA_CORRENTE",
    ba.number_agreement as "CONVENIO",
    ba.portfolio as "CARTEIRA",
    pe.paid_value as "VALOR_RECEBIDO"
FROM payment_entries pe
JOIN payment_parcels pp ON pp.id = pe.payment_parcel_id
JOIN payments p ON p.id = pp.payment_id
JOIN lower_payments lp ON lp.id = pe.parent_id AND pe.parent_type = 'LowerPayment'
JOIN bank_accounts ba ON ba.id = lp.bank_account_id
JOIN agencies a ON a.id = ba.agency_id
JOIN banks b ON b.id = a.bank_id
ORDER BY pe.payment_date DESC;
```

---

## 5. Próximos Passos Sugeridos

1.  **Criação de Tabela de Exportação:** `expor_lancamentos`.
2.  **Comando de População:** Desenvolver `PopulateExportLancamentos.php` usando a Query A acima.
3.  **Mapeamento de Status:** Criar uma tabela ou enum para traduzir os códigos numéricos de `status` para nomes legíveis (Pago, Aberto, etc).
