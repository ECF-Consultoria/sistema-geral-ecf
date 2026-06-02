# Phase 18 — Deferred items

## Itens fora do escopo de Phase 18 (regressões pré-existentes)

Suítes que falham na execução completa de `artisan test`, NÃO causadas pelas mudanças
de Phase 18 W5. Cada item identificado durante o W5-T5 gate; confirmação por
inspeção (nenhum arquivo da Phase 18 é tocado por esses testes).

### 44 falhas pré-existentes em outras fases

Categorias principais identificadas em `artisan test` (rodado em 2026-06-02 pós-W5-T5):

1. **Phase 13/14 migration tests** — `Phase13MigrationTest`, `Phase14MigrationTest`,
   `Phase13ComercialTest`, `Phase14ComercialTest`. Falhas relacionadas a
   `InvalidFormatException: Could not parse 'contract_start'` no Carbon. Aparenta ser
   issue com seeds/factories de migrations Phase 13/14 quebrando após drop de
   `service_type` em 2026_05_27_100003. **Não tocados em Phase 18.**

2. **AdminFechamentoControllerTest** — 5 testes (`update persiste service type`,
   `update rejeita service type invalido`, etc). `service_type` foi removido em
   Phase 14 (drop legacy columns). Tests devem ser atualizados/removidos para refletir
   o estado atual do schema. **Não tocados em Phase 18.**

3. **DevControllerTest** — 5 testes (`index retorna empresas com synced at`,
   `dispatch sync enfileira job`, etc). Pré-existente. **Não tocado em Phase 18.**

4. **CalcularFaixaTest (Unit)** — 9 testes com `ArgumentCountError`. Mudança de
   assinatura prévia. **Não tocado em Phase 18.**

5. **Phase14VerificarCobrancaTest** — 2 testes. Pré-existente.

6. **ExampleTest** — 1 teste base do framework. Pré-existente.

7. **CompanyServiceTypeTest** — 1 teste. Aceita "polo" que não é mais válido.
   **Não tocado em Phase 18.**

8. **FechamentoMigrationTest** — `migration adiciona colunas`. Pré-existente.

### Comprovação

`Phase18` (filtro): **25/25 verdes** em 69.55s.

- Phase 18 plans tocaram: DashboardController, CompanyController, SugadorController,
  Company model, migration + 2 commands novos. Nenhum dos arquivos acima
  (Phase13/14 controllers/services/migrations, DevController, ExampleTest,
  CalcularFaixa, FechamentoMigration) é modificado por Phase 18.
- Cobertura aditiva: adição de `cust_id_status` é um campo NOVO opcional que não
  quebra contratos existentes (ENUM com default, controllers só leem/expõem).

### Ação

Endereçar em phases futuras dedicadas a limpeza de testes legacy. Não em Phase 18.
