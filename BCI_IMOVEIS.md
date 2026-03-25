# Mapeamento do Cadastro Imobiliário (BCI - Boletim de Cadastro Imobiliário)

Este documento detalha o mapeamento da entidade de **Imóveis** no banco de dados, essencial para a gestão do cadastro técnico multifinalitário e para o cálculo do IPTU (Imposto Predial e Territorial Urbano).

## 1. Entidade Principal: `properties`

A tabela **`properties`** centraliza o cadastro de cada unidade imobiliária. Ela contém a identificação fiscal (Inscrição/BCI) e os dados de localização física (Quadra/Lote).

| Campo | Tipo | Descrição |
| :--- | :--- | :--- |
| `id` | `integer` | ID único interno (PK). |
| `registration` | `string` | **Inscrição Imobiliária** (O número do BCI). |
| `responsible_id` | `integer` | ID do proprietário/responsável (`unico_people.id`). |
| `block` | `string` | Quadra (Setor/Quadra). |
| `plot` | `string` | Lote. |
| `status` | `string` | Situação do cadastro (ex: '1'=Ativo). |
| `construction_date` | `date` | Data de averbação da construção. |
| `type` | `string` | Tipo de imóvel (Urbano/Rural). |
| `plain_address` | `text` | Endereço formatado (cache). |

---

## 2. Atributos Dinâmicos (`property_variable_values`)

Muitas características físicas do imóvel são armazenadas como variáveis dinâmicas, permitindo a evolução do cadastro sem alteração de schema. Estas variáveis são vinculadas via `property_variable_settings`.

### Principais Códigos de Variáveis Identificados:
| Código | Nome Técnico | Descrição |
| :--- | :--- | :--- |
| `554` | `area_terreno` | Área total do terreno em m². |
| `678` | `area_edificada` | Área construída da unidade principal. |
| `679` | `area_total_edificada` | Soma de todas as áreas construídas no terreno. |
| `553` | `testada_principal` | Metragem da frente principal do terreno. |
| `555` | `numero_pavimentos` | Quantidade de andares da edificação. |
| `552` | `numero_frentes` | Quantidade de frentes do imóvel. |

---

## 3. Endereçamento e Localização

Diferente de pessoas, o endereço do imóvel é o local onde a terra se encontra. O vínculo é feito através da tabela polimórfica **`unico_addresses`**.

| Tabela | Condição de Vínculo | Descrição |
| :--- | :--- | :--- |
| `unico_addresses` | `addressable_id` = `properties.id` | ID do Imóvel. |
| `unico_addresses` | `addressable_type` = `'Property'` | Identificador do polimorfismo. |

---

## 4. Integração com Proprietários (`unico_people`)

O proprietário principal é vinculado pela coluna `responsible_id`. Para co-proprietários, o sistema utiliza `third_responsible_id` ou tabelas históricas.

| Campo Vínculo | Tabela Destino | Descrição |
| :--- | :--- | :--- |
| `responsible_id` | `unico_people` | Proprietário constante no registro fiscal. |

---

## 5. Exemplos de Queries SQL

### A. Listar Imóveis com Proprietário e Endereço Completo
Esta query consolida os dados básicos para uma exportação de cadastro.

```sql
SELECT 
    p.id as "ID_IMOVEL",
    p.registration as "INSCRICAO_BCI",
    pe.name as "PROPRIETARIO",
    regexp_replace(pe.cpf_cnpj, '[^0-9]', '', 'g') as "CPF_CNPJ",
    p.block as "QUADRA",
    p.plot as "LOTE",
    ua.street_name as "LOGRADOURO",
    ua.number as "NUMERO",
    ua.neighborhood_name as "BAIRRO"
FROM properties p
JOIN unico_people pe ON pe.id = p.responsible_id
LEFT JOIN unico_addresses ua ON ua.addressable_id = p.id AND ua.addressable_type = 'Property'
ORDER BY p.registration ASC;
```

### B. Obter Áreas Físicas do Imóvel (Pivot de Variáveis)
Busca os valores de área de terreno e área construída utilizando os códigos identificados.

```sql
SELECT 
    p.registration as "BCI",
    (SELECT v.value FROM property_variable_values v 
     JOIN property_variable_settings s ON s.id = v.property_variable_setting_id 
     WHERE v.property_id = p.id AND s.code = '554' LIMIT 1) as "AREA_TERRENO",
    (SELECT v.value FROM property_variable_values v 
     JOIN property_variable_settings s ON s.id = v.property_variable_setting_id 
     WHERE v.property_id = p.id AND s.code = '679' LIMIT 1) as "AREA_CONSTRUIDA"
FROM properties p
WHERE p.status = '1';
```

### C. Identificar Imóveis por Logradouro (Planta de Valores)
Query útil para relatórios de valorização por rua.

```sql
SELECT 
    vss.description as "SECAO_LOGRADOURO",
    p.registration as "BCI",
    p.territorial_square_meter_value as "VALOR_M2_TERRENO",
    p.territorial_aliquot as "ALIQUOTA_IPTU"
FROM properties p
JOIN value_section_streets vss ON vss.id = p.value_section_street_id
ORDER BY vss.description;
```

---

## 6. Próximos Passos Sugeridos

1.  **Mapeamento de Áreas:** Validar se os códigos `554` e `679` são universais para este município ou se variam por exercício.
2.  **Criação de Tabela de Exportação:** `export_bci_imoveis`.
3.  **Desenvolvimento de Comando:** Criar `PopulateExportBciImoveis.php` para extrair estes dados.
