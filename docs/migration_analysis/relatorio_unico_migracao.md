# Relatório Central de Análise de Migração: Diagnóstico e Mapeamento

Este documento unifica e centraliza a análise estrutural das bases de dados fornecidas (PostgreSQL), focando na identificação dos contribuintes, alvarás, cobranças de IPTU e do Imposto Sobre Serviços (ISS), além de mapear as incoesões para guiar scripts de extração.

---

## 👤 1. O Contribuinte e o Cadastro Único

O conceito de "Contribuinte" no banco principal não está centralizado em uma única tabela. identificadas pelo prefixo `unico_`.

### Censo de Contribuintes (Realidade dos Bancos)

Nós cruzamos as tabelas para entender a defasagem e descobrimos uma dualidade cadastral: O PostgreSQL guarda todos, mas as retenções de impostos acontecem no SQLite.

| Banco de Dados / Função            | Pessoas Físicas (PF)  | Pessoas Jurídicas (PJ) | TOTAL     |
| :--------------------------------- | :-------------------- | :--------------------- | :-------- |
| **PostgreSQL** (`unico_`)          | 5.178                 | 613                    | **5.791** |
| **SQLite (ISS)** (`contribuintes`) | _Misturado na Tabela_ | _Misturado na Tabela_  | **2.936** |

### Aonde estão os nomes das pessoas e empresas?

Foi observado a ausência de nomes das pessoas fisicas na tabela `unico_individuals`

### Query de Extração: Pessoas Físicas (PF)

```sql
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
```

#### Amostra de Dados Extraídos: Pessoas Físicas (PF)

| ID  | CODIGO | PESSOA | DESCRICAO | CPF_CNPJ    | RAZSOCIAL | CEP      | CODCIDADE | CODBAIRRO | CODLOGRADOURO | NUMERO | COMPLEMENTO | DTNASCIMENTO | RG  | TELEFONE | INSCESTADUAL | EMAIL | DTINICIOATIVIDADE | IDENTMIGRACAO |
| --- | ------ | ------ | --------- | ----------- | --------- | -------- | --------- | --------- | ------------- | ------ | ----------- | ------------ | --- | -------- | ------------ | ----- | ----------------- | ------------- |
| 2   | 2      | F      |           | 75510944234 |           | 68830000 | 246       | 15        | 122           |        |             |              |     |          |              |       |                   | 2             |
| 3   | 3      | F      |           | 22294929268 |           | 68830000 | 246       | 11        | 51            |        |             |              |     |          |              |       |                   | 3             |
| 4   | 4      | F      |           | 46196692291 |           | 68830000 | 246       | 15        | 124           |        |             |              |     |          |              |       |                   | 4             |
| 6   | 6      | F      |           | 04943538215 |           | 68830000 | 246       | 3         | 115           |        |             |              |     |          |              |       |                   | 6             |
| 7   | 7      | F      |           | 60060972220 |           | 68830000 | 246       | 11        | 7             |        |             |              |     |          |              |       |                   | 7             |

### Query de Extração: Pessoas Jurídicas (PJ)

```sql
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
```

#### Amostra de Dados Extraídos: Pessoas Jurídicas (PJ)

| ID    | CODIGO | PESSOA | DESCRICAO                          | CPF_CNPJ       | RAZSOCIAL                          | CEP      | CODCIDADE | CODBAIRRO | CODLOGRADOURO | NUMERO | COMPLEMENTO | DTNASCIMENTO | RG  | TELEFONE | INSCESTADUAL | EMAIL | DTINICIOATIVIDADE | IDENTMIGRACAO |
| ----- | ------ | ------ | ---------------------------------- | -------------- | ---------------------------------- | -------- | --------- | --------- | ------------- | ------ | ----------- | ------------ | --- | -------- | ------------ | ----- | ----------------- | ------------- |
| 50001 | 50001  | J      | GILBERTO CONCEI                    | 00000000000131 | GILBERTO CONCEI                    | 68830000 |           | 3         | 108           |        |             |              |     |          |              |       |                   | 50001         |
| 50005 | 50005  | J      | BAR DO PAO                         | 55321112000136 | BAR DO PAO                         | 68830000 |           | 46        | 547           |        |             |              |     |          | PA           |       |                   | 50005         |
| 50009 | 50009  | J      | TEREZINHA MORAIS TAVARES - ME      | 02846854000119 | TEREZINHA MORAIS TAVARES - ME      | 68830000 |           | 3         | 4             |        |             |              |     |          | 152021213    |       |                   | 50009         |
| 50012 | 50012  | J      | PEDRO DOS SANTOS BARBOSA           | 00000000000082 | PEDRO DOS SANTOS BARBOSA           | 00000000 |           | 1         | 97            |        |             |              |     |          |              |       |                   | 50012         |
| 50025 | 50025  | J      | SUPERMERCADO E MAGAZINE BRASILSERV | 07383089000161 | SUPERMERCADO E MAGAZINE BRASILSERV | 68830000 |           | 3         | 18            |        |             |              |     |          | 152471103    |       |                   | 50025         |

---

## 🏗️ 2. Mapeamento de Relações e Taxas

A correlação entre o município e os imóveis/taxas está fragmentada.

### A. Alvarás (Permits)

A emissão de alvarás reside na tabela `permits` (Nós encontramos **837 alvarás**) e eles são atrelados especificamente às **Empresas**:

- Mapeamento: `permits.economic_registration_id` ligando em ➔ `unico_companies.id`

#### Query de Extração: Alvarás

```sql
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
ORDER BY p.id;
```

#### Amostra de Dados Extraídos: Alvarás

| ID  | CODIGO_EMPRESA | NUMERO_ALVARA | ANO_ALVARA | DATA_VENCIMENTO | SITUACAO | DOCUMENTO_CNPJ | RAZAO_SOCIAL              | PROCESSO | DATA_EMISSAO               |
| --- | -------------- | ------------- | ---------- | --------------- | -------- | -------------- | ------------------------- | -------- | -------------------------- |
| 1   | 1678           | 1             | 2022       | 2022-12-31      | valid    | 04895728007001 |                           |          | 2022-03-28 14:37:45.63528  |
| 2   | 594            | 2             | 2022       | 2022-12-31      | valid    | 07047671000157 | ARMARINHO BARBOSA         |          | 2022-05-06 11:57:02.219886 |
| 3   | 2887           | 3             | 2022       | 2022-12-31      | valid    | 05693333000167 | CONSTRUTORA SANTA TEREZA  |          | 2022-05-06 13:28:19.115508 |
| 4   | 2011           | 4             | 2022       | 2022-12-31      | valid    | 00000000000001 | MIGUEL LALOR DE LIMA      |          | 2022-05-09 12:01:40.258255 |
| 5   | 2888           | 5             | 2022       | 2022-12-31      | valid    | 44504210000192 | MARAJO HOTEL REPONTA LTDA |          | 2022-05-09 14:49:17.511259 |

### B. Imóveis e IPTU

A base PostgreSQL estudada **não possui uma tabela mestre de características físicas imobiliárias**. A ligação de dívidas ao imóvel e seu proprietário e feito através da associação de "Donos" (`owners`):

- Mapeamento de Dono: `owners.person_id` ligando em ➔ `unico_individuals.id` ou `unico_companies.id`
- Mapeamento de Dívida: `owners.property_id` se ligando ao imóvel em `active_debts`.

### C. Pagamentos e Cáculo de Taxas / Dívidas Ativas

A estrutura de débitos e recebimentos concentra-se numa composição de quatro tabelas principais para manter o histórico de parcelas e sanções financeiras:

- `tax_calculations` / `tax_collections`: Agrupam a "coleta" original da taxa, ditando a qual arrecadação pertence a cobrança (por exemplo: IMPOSTO SOBRE A PROPRIEDADE PREDIAL E TERRITORIAL).
- `payments`: O elemento consolidador unindo o valor gerado aos alvos (`payable_type` como `TaxCalculation` e `payable_id`).
- `payment_parcels`: Quebra o `payment` em parcelas de pagamento, aplicando os descontos de antecipação e as frações se parcelado.
- `active_debts`: Quando um pagamento vence e não é pago, o sistema delega o acompanhamento financeiro para cá. Aplicando correções (Correction), juros (Interest) e multas (Fine). Essa amarração de dívida exige o identificador gerador `payment_id` e aponta diretamente para a pessoa física/jurídica (`person_id`).

#### Query de Extração: Pagamentos e Dívidas Ativas

```sql
-- Análise de Pagamento e Coleta
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
WHERE p.value > 0;

-- Análise de Dívida Ativa
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
WHERE ad.value > 0;
```

#### Amostra de Dados Extraídos: Pagamentos e Resumo de Parcelas

| ID_PAGAMENTO | ANO  | TIPO_TRIBUTO   | ID_ALVO | VALOR_BASE | VALOR_TOTAL | DATA_VENCIMENTO_MIN | TOTAL_PAGO_PARCELAS | TOTAL_ARRECADADO_COLETA |
| ------------ | ---- | -------------- | ------- | ---------- | ----------- | ------------------- | ------------------- | ----------------------- |
| 1            | 2014 | TaxCalculation | 1       | 30.00      | 24.00       | 2014-06-30          | 24.00               | 30.00000000             |
| 2            | 2014 | TaxCalculation | 2       | 516.89     | 413.51      | 2014-06-30          | 715.85              | 894.82000000            |
| 3            | 2014 | TaxCalculation | 3       | 30.00      | 24.00       | 2014-06-30          | 24.00               | 30.00000000             |
| 4            | 2014 | TaxCalculation | 4       | 30.00      | 24.00       | 2014-06-30          | 72.35               | 90.44000000             |
| 5            | 2014 | TaxCalculation | 5       | 75.98      | 75.98       | 2014-06-30          | 60.78               | 75.98000000             |

#### Amostra de Dados Extraídos: Dívidas Ativas

| ID_DIVIDA | ID_PAGAMENTO_GERADOR | CO_PESSOA | DATA_VENCIMENTO_ORIGINAL | VALOR_PRINCIPAL | CORRECAO | MULTA | JUROS | TOTAL_DIVIDA | NUMERO_REGISTRO | STATUS_DIVIDA |
| --------- | -------------------- | --------- | ------------------------ | --------------- | -------- | ----- | ----- | ------------ | --------------- | ------------- |
| 12967     | 23460                | 1480      | 2023-12-29               | 73.00           | 0.92     | 3.33  | 2.22  | 79.47        | 12967           | 7             |
| 10831     | 23459                | 1480      | 2022-08-31               | 73.00           | 5.43     | 15.69 | 14.90 | 109.02       | 10831           | 7             |
| 6234      | 16295                | 1480      | 2021-12-20               | 73.00           | 9.48     | 16.50 | 22.27 | 121.25       | 6234            | 7             |
| 5709      | 13881                | 1480      | 2020-12-20               | 73.00           | 19.28    | 18.46 | 35.99 | 146.73       | 5709            | 7             |
| 4034      | 11482                | 1480      | 2019-07-30               | 73.00           | 23.50    | 19.30 | 54.04 | 169.84       | 4034            | 7             |

## ⚠️ 3. Incoesão e Complexidade Técnica da Migração

A extração de dados tributários destas plataformas revela uma alta falta de coesão (incoesão) estrutural, que eleva o risco e o esforço de migração:

### A. Conflito de IDs (Sobreposição de Domínios)

Como o Cadastro Único separa PF e PJ em tabelas distintas, ambas iniciam suas sequências de `id` em 1.

- **O Problema**: O `ID 10` que aparece vinculado em um imóvel pode ser do "João" (PF #10) ou da "Empresa X" (PJ #10).
- **A Incoesão**: Não existe um identificador único global (UUID/Global ID) nativo no sistema. A extração exige forte intervenção no Backend para aplicar regras de OFFSET (ex: Somar `500.000` em todo ID de empresa).

### B. Relações no Domínio do Código-Fonte (Ausência de Foreign Keys)

Tabelas financeiras vitais como `active_debts` (Dívidas Ativas) possuem o `person_id` para localizar quem é o devedor, mas o banco de dados **não impõe e não possui Foreign Keys**. A falta destas chaves estruturais evidencia que **as relações entre entidades não podem ser estabelecidas de forma segura apenas escaneando o banco de dados**.

Isso indica claramente que toda a integridade e a amarração lógica foram construídas para residir **exclusivamente no domínio do código-fonte da aplicação**. Sem o conhecimento da lógica abstrata das models de programação, extrair dados puramente via banco (SQL) se torna altamente complexo, exigindo que o migrador adivinhe regras de negócio (como desempate de IDs e soft-deletes) que existiam apenas no código-fonte.

### C. Abstrações Polimórficas (Código vs Banco)

Um contribuinte não possui um elo fixo "Pessoa -> Endereço" em colunas tradicionais. Eles se utilizam da teia polimórfica estruturada (`unico_addresses` vinculados por strings em código como `addressable_id` e `addressable_type ='Person'`). Sendo preciso emular o comportamento do código-fonte (varrendo a base com **Window Functions** do SQL como `ROW_NUMBER() OVER`) ou carregando todos os Models atrelados para o backend do exportador para pegar somente as entidades corretas.

---

## 📞 4. Solicitações Necessárias à Proprietária do Software

Com base neste diagnóstico, é evidente que **o banco de dados depende fortemente da lógica da aplicação e suas Models para estabelecer suas relações**. Para mitigar os riscos e evitar refatorações complexas de engenharia reversa na extração dos dados (que geram falsos positivos, furos de chaves e custo excessivo de tempo), é **mandatório que solicitemos à empresa responsável os seguintes artefatos**:

### 1. Dicionário de Dados e Diagrama (DER)

- **Objetivo:** O Dicionário de Dados oficial e o Diagrama de Entidade-Relacionamento (DER).
- **Por quê:** Dada a ausência de _Foreign Keys_, precisamos ter uma garantia visual e documental de quais colunas se inter-relacionam, evitando o cruzamento incorreto de entidades, especialmente em tabelas financeiras.

### 2. Mapeamento do Cadastro Físico Imobiliário

- **Objetivo:** A localização da tabela mestre e o fluxo relacional do **Cadastro Físico do Imóvel** (onde são guardados lotes, terrenos, logradouros, testada, valor venal).
- **Por quê:** O diagnóstico identificou as tabelas associativas (`owners`) e financeiras (`active_debts`), porém a raiz documental que rege o cadastro físico tributário não foi facilmente correlacionada.

### 3. Materialização de Nomes e Entidades Isoladas

- **Objetivo:** O caminho relacional exato (cláusulas `JOIN`) ou as documentações recomendadas que materializam os dados faltantes, como os Nomes Reais das Pessoas Físicas pertencentes à `unico_individuals`.
- **Por quê:** Sem tabelas coesas de nomenclatura, hoje os mapeadores têm de ser improvisados via JSON ou tabelas anexas de difícil ligação direta.

### 4. _Golden Path_ do Relacionamento Polimórfico

- **Objetivo:** O mapeamento exato de como o sistema deles lida na extração ou gravação de _Addresses_ (endereços) fora das models da aplicação.
- **Por quê:** Compreender como eles amarram tabelas puramente textuais (`addressable_type = 'Person'`) garantirá que a exportação de Contribuintes traga o endereço legal correto.

### 5. (Opcional/Ideal) Fornecimento de Views ou Scripts Pivot

- **Objetivo:** A disponibilidade ou construção de _Views SQL_ no banco que já nos devolvam os espelhos consolidados dos objetos (Contribuintes, Imóveis, Alvarás e Dívidas). Em alternativa, **o acesso as Models do Backend** (arquivos-fonte) do software.
- **Por quê:** Se o próprio banco expuser as _Views_, nosso labor é drasticamente minimizado. Se nos cederem acesso ao projeto back-end, conseguiremos varrer a definição dos relacionamentos da estrutura (_HasMany_, _BelongsTo_, etc.) com o que eles programaram nativamente sem necessitar de "tentativa e erro" por Query SQL.

---

## ❓ 6. Dúvidas e Esclarecimentos Necessários

### A. Identificação do Alvo Tributável (`taxable_type` e `taxable_id`)

Durante a análise estrutural, identificamos que o sistema utiliza **polimorfismo** para vincular cobranças tributárias aos diferentes tipos de cadastro. Isso aparece nas tabelas `payment_taxables` ou `tax_calculations` através dos campos `taxable_type` e `taxable_id`, que determinam a natureza e o identificador do objeto tributado.

#### Tipos de Alvo Tributável Identificados:

- **`Property` (Imóvel):** A taxa ou dívida (IPTU/Lixo) está atrelada a um Cadastro Imobiliário (`taxable_id` aponta para um cadastro de imóvel).
- **`EconomicRegistration` (Cadastro Econômico):** A taxa (ISS/Alvará) está atrelada a uma Inscrição Municipal ou Empresa.
- **`Person` (Pessoa Avulsa):** Cobrança expedida sob o CPF/CNPJ (Ex: ITBI ou Taxa de Balcão).

### B. Questões Críticas Pendentes

#### 1. Mapeamento de `taxable_type` → Tabelas Destino da Base Arrecadatória

- **Dúvida:** Quais são as tabelas exatas que armazenam os dados mestres quando o sistema aponta para `Property` (Cadastro Imobiliário) e `EconomicRegistration`?
- **Impacto:** Sem essa informação, as tabelas financeiras (`tax_calculations` e `payment_taxables`) apontam para um ID órfão. Precisamos da tabela _Properties Registration_ para vincular os pagamentos aos imóveis e alvarás.

#### 2. Relacionamento `EconomicRegistration` ↔ `unico_companies`

- **Dúvida:** O `taxable_id` quando tipo `'EconomicRegistration'` aponta diretamente para `unico_companies.id` ou existe uma tabela intermediária de Inscrições/Alvarás (como a tabela `permits`)?
- **Impacto:** Afeta a extração de débitos de ISS e taxas de alvará. Precisamos esclarecer o fluxo relacional entre o fato gerador e a tabela de empresas.

#### 3. Desambiguação de `Person` em Contexto Polimórfico

- **Dúvida:** Quando `taxable_type = 'Person'`, o `taxable_id` pode referenciar tanto `unico_individuals.id` (Pessoas Físicas) quanto `unico_companies.id` (Jurídicas). Como ambas as tabelas reiniciam a contagem de IDs do `1`, como o sistema distingue de quem é a dívida?
- **Impacto:** Traz à tona o problema crítico de **sobreposição de IDs**. Pode gerar cobrança indevida no nome de um terceiro.
- **Solicitação:** Definição clara de como o sistema desambigua PF vs PJ neste contexto (se existe um campo discriminador que não mapeamos).

#### 4. Abstração de Endereçamentos (`addressable_type`)

- **Dúvida:** Seguindo a mesma lógica polimórfica das taxas, a tabela `unico_addresses` usa `addressable_type` para ligar um endereço ao seu dono. Quando a string é `Person`, `EconomicRegistration` ou `Property`, a qual tabela/model física de banco de dados ela corresponde estritamente? (Ex: Property = properties?).
- **Impacto:** Como "Person" é ambíguo entre PF e PJ, sem uma regra rígida da empresa, o extrator pode associar o endereço da "Empresa X" à "Pessoa X" equivocadamente ao carregar `addressable_id`.
- **Solicitação:** Lista definitiva (De/Para) dos termos usados pela model `addressable_type` direcionando às suas respectivas tabelas.

#### 5. Cadastro Físico Imobiliário & Histórico de Proprietários

- **Dúvida:** Onde estão armazenados os dados físicos dos imóveis (lote, quadra, setor, testada, área construída, valor venal) e como é registrado a **transferência de titularidade** ao longo do tempo?
- **Impacto:** A dívida de 2014 pertence ao dono antigo, a de 2024 ao novo. Se olharmos apenas a tabela atual de `owners`, atrelaremos a dívida velha incorretamente ao dono novo.
- **Solicitação:** Identificação da(s) tabela(s) de infraestrutura imobiliária e do contrato de posse temporal.

#### 6. Amarração de Acordos e Parcelamentos (`Agreements`)

- **Dúvida:** Quando um Pagamento nasce do tipo `payable_type = 'Agreement'`, ele renegociou uma Dívida Ativa (`active_debts`) anterior. Como o sistema suspende a Dívida original e vincula ela a este novo acordo?
- **Impacto:** Sem mapear a tabela de _Agreements/Acordos_, o extrator vai exportar a Dívida Ativa original + As Parcelas do Acordo, causando **cobrança em duplicidade** no novo sistema.

#### 7. Processamento de Baixas e Retorno Bancário (Febraban)

- **Dúvida:** Qual tabela armazena o histórico do Arquivo de Retorno (CNAB) atrelado à tabela `rubric_entries`?
- **Impacto:** Durante uma migração contábil é comum precisarmos retroagir Lotes de Arquivos de Retorno. Se não trouxermos a origem bancária da baixa, perdemos o rastro de auditoria.

### C. Informações Adicionais (Regras de Negócio Ocultas)

#### 1. Lógica de Cálculo de Correção Monetária

- Como são calculados os campos `correction`, `fine` e `interest` em `active_debts`? É consolidado diário ou dinâmico? Existem tabelas auxiliares de índices financeiros (ex: UFM, Selic)?

#### 2. Dicionário de Status Enumerados

- Solicitamos o mapeamento completo dos valores de status (Ex: `status = 7` em `active_debts` ou `status = 5` em `payment_parcels` significa o quê? Pendente, Cancelado, Ajuizado, Quitado?).

#### 3. Tratamento de Exclusões (Soft-Deletes)

- Existem dezenas de tabelas financeiras. Qual a política de registros inativados/cancelados? O sistema exclui a linha fisicamente, ou preenche uma coluna como `deleted_at`, `status = cancelado` ou `active = false`? Isso previne transferirmos lixo eletrônico.

---

### Resumo de Solicitações Prioritárias

Para dar continuidade segura ao processo de extração e transição fiscal, é **imprescindível** que a provedora de software forneça:

1. Mapeamento documentado de `taxable_type` → Tabelas destino exatas de Cadastro.
2. Lógica de desambiguação de IDs coincidentes entre PF/PJ (`Person` genérico).
3. Entendimento do link entre Dívida Velha ↔ Novo Acordo.
4. Tabela "Dicionário de Domínios", revelando o que significa cada `status` numérico financeiro.
5. (Ideal) O modelo do DER Bancário resumido.

---

## 📚 Apêndice: Dicionário de Tradução de Tabelas (Contexto de Arrecadação)

Com base no levantamento estrutural de baixas e pagamentos, documentamos o propósito exato das entidades financeiras centrais para guiar a extração e a engenharia reversa. A query de consolidação de valores serve como roteiro de como a arrecadação é composta no banco original:

### Tabela-Chave da Arrecadação:

```sql
SELECT payment_parcel_revenues.id,
    payment_parcels.year,
    COALESCE(payment_parcel_revenues.value_paid + payment_parcel_revenues.fine_paid + payment_parcel_revenues.interest_paid + payment_parcel_revenues.correction_paid) AS total_paid,
    revenues.name AS revenue_name,
    payment_taxables.revenue_id,
    payment_taxables.taxable_type,
    payment_taxables.payment_id,
    subrevenues.name AS subrevenue_name,
    payment_parcel_revenues.revenue_id AS subrevenue_id,
    rubric_entries.payment_date,
    EXTRACT(month FROM rubric_entries.payment_date) AS payment_month
FROM payment_parcel_revenues
    JOIN payment_parcels ON payment_parcels.id = payment_parcel_revenues.payment_parcel_id
    JOIN payment_taxables ON payment_parcels.payment_id = payment_taxables.payment_id
    JOIN rubric_entries ON payment_parcel_revenues.id = rubric_entries.payment_parcel_revenue_id
    JOIN revenues ON payment_taxables.revenue_id = revenues.id
    JOIN revenues subrevenues ON payment_parcel_revenues.revenue_id = subrevenues.id
ORDER BY payment_parcel_revenues.id, payment_parcels.year, rubric_entries.payment_date;
```

### Dicionário de Entidades (De/Para)

1. **`payment_parcel_revenues`**: A tabela de rateio fino de um pagamento. Ela detalha como os centavos de uma parcela (`payment_parcel_id`) foram divididos entre o principal (`value_paid`), a multa (`fine_paid`), os juros (`interest_paid`) e a correção monetária (`correction_paid`). Também liga essa fração de valor financeiro a uma sub-receita/taxa específica (`revenue_id`), mostrando que um único boleto (parcela) pode estar pagando várias taxas distintas.
2. **`payment_parcels`**: Representa uma fatura ou boleto isolado. Um carnê de imposto é quebrado em múltiplas linhas nesta tabela (ex: Cota Única versus Parcela 1, 2, 3), estipulando os vencimentos e guardando o ano (`year`) da referência fiscal.
3. **`payment_taxables`**: Tabela associativa (Polimórfica). Responsável por vincular um grande pagamento (`payment_id`) ao seu objeto gerador. Através do `taxable_type` e `taxable_id` nós descobrimos se aquele pagamento nasceu de uma fiscalização de Empresa, Imóvel ou Atividade Autônoma. Também atrela a grande Dívida/Pagamento à "Receita Mestre" (O tributo raiz como ISS ou IPTU).
4. **`rubric_entries`**: São as "baixas bancárias" propriamente ditas. Guarda a data real em que o dinheiro entrou no cofre do município (`payment_date`), marcando quando a transação foi efetivamente compensada por retorno de arquivo e finalizando o fluxo contábil.
5. **`revenues`**: É o catálogo de "Receitas" ou Taxas/Impostos municipais. Pode atuar como a receita principal/raiz (ex: "IPTU") quando conectada via `payment_taxables` ou como uma sub-receita/rubrica (ex: "Taxa de Lixo", "Taxa Expediente") quando conectada no rateio fino da `payment_parcel_revenues`.

---

### Mapeamento das Abstrações Polimórficas (Taxables e Payables)

Como o sistema é abstraído para cobrar impostos de diferentes origens e modalidades, ele recorre a colunas como `taxable_type` ou `payable_type` para apontar "do que" estamos cobrando (o alvo). Segue o mapeamento:

#### 1. Tipos de Alvo Cobrado (`taxable_type`)

Isto costuma aparecer em `payment_taxables` ou `tax_calculations` para ligar a taxa ao Cadastro Físico ou Econômico:

- **`Property`** (Imóvel): A taxa ou dívida está atrelada a um Cadastro Imobiliário. Trata-se tipicamente da cobrança de IPTU, Taxa de Coleta de Lixo, etc. O ID (`taxable_id`) vai em direção a tabela de Imóveis (properties/properties_registration).
- **`EconomicRegistration`** (Cadastro Econômico): A taxa está atrelada a uma Inscrição Municipal ou Alvará. Trata-se tipicamente do ISSQN Fixo, Taxas de Alvará de Licença e Funcionamento (TLLF), e Vigilância Sanitária para Empresas ou Profissionais Autônomos.
- **`Person`** (Pessoa/Contribuinte Avulso): Utilizado quando a cobrança é expedida diretamente sob o CPF/CNPJ de um ente, sem passar por um imóvel ou empresa formatada (Exemplo: Taxa expediente balcão ou ITBI).

**Exemplos de Dados:**

| id / payment_id | year / revenue_id | taxable_type         | taxable_id |
| --------------- | ----------------- | -------------------- | ---------- |
| 1               | 2014              | Property             | 7812       |
| 21157           | 2024              | EconomicRegistration | 2879       |
| 28703           | 69 (taxa)         | Person               | 10         |

#### 2. Tipos de Origem de Pagamento (`payable_type`)

Isto aparece nativamente na tabela `payments` para identificar qual foi o Evento Gerador da Dívida e do Boleto expedido:

- **`TaxCalculation`**: A origem é um imposto gerado pelos cálculos (lançamentos) anuais em massa da Prefeitura. Essa é a base de pagamentos comuns de IPTU e Alvarás de cada exercício.
- **`Agreement`**: A origem deste pagamento é um Acordo ou Parcelamento de Dívida Ativa (`agreements`). O valor embutido engloba parcelas renegociadas que o contribuinte concordou em pagar.
- **`IssIntelPayment`**: A origem é uma guia variável oriunda da declaração de serviços do portal de Nota Fiscal (ISS Inteligente ou similar).
- **`OtherRevenue`**: A origem é uma receita financeira não previsível ou "Receita Diversa" (Exemplos: Taxa de emissão de 2ª via, aluguel de solo, entre outros lançamentos manuais do balcão de atendimento).

**Exemplos de Dados (Tabela `payments`):**

| payment_id | year | payable_type    | payable_id (ID da Origem Geradora) | total  |
| ---------- | ---- | --------------- | ---------------------------------- | ------ |
| 34027      | 2024 | TaxCalculation  | 21908 (id de tax_calculations)     | 101.01 |
| 38667      | 2024 | Agreement       | 2869 (id de agreements)            | 227.03 |
| 38238      | 2024 | IssIntelPayment | 823                                | 77.50  |
| 38634      | 2024 | OtherRevenue    | 5348                               | 500.00 |
