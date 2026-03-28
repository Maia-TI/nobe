# Cadastro Imobiliário (Urbano e Rural) e Cálculo de IPTU

Este documento fornece uma visão detalhada do **Cadastro Imobiliário** do sistema, abrangendo tanto imóveis urbanos quanto rurais, e explica a mecânica de cálculo para o **IPTU (Imposto Predial e Territorial Urbano)**.

---

## 🏗️ 1. Estrutura do Cadastro de Imóveis

O cadastro de imóveis é multifinalitário, servindo tanto para fins tributários quanto para o planejamento urbano.

### 📍 A. Entidade Principal: `properties`
A tabela `properties` armazena a unidade imobiliária básica.

| Campo | Descrição | Observação |
| :--- | :--- | :--- |
| `id` | Identificador único interno. | Chave Primária (PK). |
| `registration` | **Inscrição Imobiliária (BCI)**. | Código oficial do carnê de IPTU. |
| `type` | Tipo de Imóvel. | Geralmente '1' para Urbano e '2' para Rural. |
| `block` / `plot` | Quadra e Lote. | Localização técnica na planta cadastral. |
| `responsible_id` | Proprietário Principal. | FK para `unico_people`. |
| `building_aliquot` | Alíquota Predial. | Aplicada se houver construção. |
| `territorial_aliquot`| Alíquota Territorial. | Aplicada se for terreno vago. |

### 🌳 B. Cadastro Rural vs. Urbano
No sistema, a distinção entre imóveis urbanos e rurais é feita de duas formas:
1.  **Campo `type`:** Classificação direta na tabela `properties`.
2.  **Bairros Especiais:** Imóveis vinculados ao bairro **"ZONA RURAL"** via `unico_addresses`.

> [!NOTE]
> Para imóveis rurais (INCRA), o sistema pode utilizar campos de variáveis dinâmicas para armazenar o código do INCRA e o Valor da Terra Nua (VTN), caso não haja colunas específicas.

---

## 📊 2. Atributos Físicos e Variáveis Dinâmicas

O sistema utiliza um modelo **EAV (Entity-Attribute-Value)** para características físicas que variam por município.

### 🧩 Tabela `property_variable_values`
Esta tabela armazena os valores (áreas, pavimentação, topografia) vinculados a cada imóvel.

| Variável | Código Comum | Descrição |
| :--- | :--- | :--- |
| **Área do Terreno** | `554` | Metragem total da área do lote. |
| **Área Construída** | `679` | Soma das áreas de todas as edificações. |
| **Testada Principal** | `553` | Metragem da frente do imóvel para o logradouro. |

---

## 💸 3. Regras de Cálculo do IPTU

O cálculo do IPTU é baseado no **Valor Venal** do imóvel.

### 🔢 A. Fórmula Base
A base de cálculo é a soma do valor do terreno e o valor da construção:

$$ \text{Valor Venal Total (VVT)} = \text{Valor Venal Territorial (VVT)} + \text{Valor Venal de Edificação (VVE)} $$

$$ \text{Imposto} = \text{VVT} \times \text{Alíquota} + \text{Taxas (TSU)} $$

### 🛠️ B. Mapeamento de Bases de Cálculo
Os valores venais são extraídos de configurações específicas na tabela `settings`:

| Valor | Origem no Sistema | Descrição |
| :--- | :--- | :--- |
| **VVT** | `terrain_market_value_id` | Valor de mercado do terreno (lote). |
| **VVE** | `construction_market_value_id` | Valor de mercado da área construída. |
| **VVIMOVEL** | Soma (VVT + VVE) | Base de cálculo consolidada do imóvel. |

---

## 📋 4. Mapeamento da Tabela de Exportação (`export_lancamentos_iptu`)

Para fins de integração e Business Intelligence, os dados são consolidados na tabela local de exportação.

| Coluna | Origem Técnica | Descrição |
| :--- | :--- | :--- |
| `CODLANCAMENTO` | `payments.id` | ID do lançamento no PostgreSQL. |
| `CODBCI` | `properties.id` | ID interno do imóvel. |
| `ANOEXERCICIO` | `payments.year` | Ano do imposto (ex: 2026). |
| `VVT` | `property_variable_values` | Valor Venal Territorial. |
| `VVE` | `property_variable_values` | Valor Venal de Edificação. |
| `VVIMOVEL` | Calculado (VVT + VVE) | Valor Venal Total. |
| `ALIQUOTAIPTU` | `properties.aliquot` | Alíquota aplicada (Predial ou Territorial). |
| `VALIPTU` | `payments.total` | Valor total do imposto lançado. |

---

## 🔍 5. Exemplos de Consultas (SQL)

### A. Extração de Cadastro Completo (Urbano e Rural)
```sql
SELECT 
    p.registration as "BCI",
    CASE WHEN p.type = '1' THEN 'URBANO' ELSE 'RURAL' END as "TIPO",
    n.name as "BAIRRO",
    p.block as "QUADRA",
    p.plot as "LOTE",
    pe.name as "PROPRIETARIO"
FROM properties p
JOIN unico_people pe ON pe.id = p.responsible_id
LEFT JOIN unico_addresses a ON a.addressable_id = p.id AND a.addressable_type = 'Property'
LEFT JOIN unico_neighborhoods n ON n.id = a.neighborhood_id
ORDER BY p.type, p.registration;

SELECT 
    p.registration as "BCI",
    CASE 
        WHEN p.type = '1' THEN 'URBANO' 
        ELSE 'RURAL' 
    END as "TIPO",
    n.name as "BAIRRO",
    p.block as "QUADRA",
    p.plot as "LOTE",
    COALESCE(pe.name, 'NOME NAO IDENTIFICADO') as "PROPRIETARIO"
FROM properties p
LEFT JOIN unico_people pe 
    ON pe.id = p.responsible_id
LEFT JOIN unico_addresses a 
    ON a.addressable_id = p.id 
    AND a.addressable_type = 'Property'
LEFT JOIN unico_neighborhoods n 
    ON n.id = a.neighborhood_id
ORDER BY p.type, p.registration;
```

### B. Detalhamento de Base de Cálculo IPTU
Esta query simula a lógica do comando `db:populate-export-lancamentos-iptu`.
```sql
SELECT 
    prop.registration as "BCI",
    p.year as "EXERCICIO",
    -- Busca VVT dinamicamente
    (SELECT CAST(v.value AS NUMERIC) FROM property_variable_values v 
     WHERE v.property_id = prop.id AND v.property_variable_setting_id = 
     (SELECT terrain_market_value_id FROM settings LIMIT 1)) as "VVT",
    -- Busca VVE dinamicamente
    (SELECT CAST(v.value AS NUMERIC) FROM property_variable_values v 
     WHERE v.property_id = prop.id AND v.property_variable_setting_id = 
     (SELECT construction_market_value_id FROM settings LIMIT 1)) as "VVE",
    p.total as "VALOR_TOTAL_LANCADO"
FROM properties prop
JOIN payment_taxables pt ON pt.taxable_id = prop.id AND pt.taxable_type = 'Property'
JOIN payments p ON p.id = pt.payment_id
WHERE pt.revenue_id IN (12, 27) -- IDs de Receita de IPTU
ORDER BY p.year DESC;
```

---

## 🚀 6. Considerações para o Desenvolvedor

1.  **Taxas de Serviço (TSU):** Algumas taxas (Lixo, Iluminação) podem estar embutidas no total. Verifique se há necessidade de separar rubricas via `taxable_debts`.
2.  **Sincronização:** Após popular a tabela `export_lancamentos_iptu`, utilize o comando de Sync para enviar os dados para o ambiente de destino (ex: Firebird).
3.  **Zona Rural:** Para exportações específicas de ITR, filtre por `p.type = '2'` ou `bairro = 'ZONA RURAL'`.
