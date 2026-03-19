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
