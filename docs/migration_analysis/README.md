# Índice de Relatórios: Migração de Dados (Nobe -> Firebird)

Este diretório contém os diagnósticos técnicos realizados para subsidiar a migração.

---

## 📑 Relatório de Análise

1.  **[Relatório Único de Migração (Anseio Estrutural)](./relatorio_unico_migracao.md)**: **Documento Refatorado e Unificado**. Análise do Cadastro Único (PF/PJ), Detalhamento de como as taxas e imóveis se conectam às pessoas no PostgreSQL, Investigação da base paralela (`new_db.sqlite`) das Notas Fiscais e ISS, além da Formalização das dificuldades de extração de saldos e dívidas (Incoesão).

---

## 🔍 Resumo de Chaves de Ligação

- **Contribuinte (PJ) ➔ Alvará**: `unico_companies.id` = `permits.economic_registration_id` (No PostgreSQL)
- **Contribuinte (PF/PJ) ➔ Imóvel (IPTU)**: `unico_individuals.id` / `unico_companies.id` = `owners.person_id` (No PostgreSQL)
- **Contribuinte ➔ Dívida Ativa**: `unico_individuals.id` = `active_debts.person_id` (No PostgreSQL)
- **Contribuinte (ISS) ➔ NFS-e**: Tabela separada `contribuintes` vincula para as `nfeas` (No SQLite Secundário)
