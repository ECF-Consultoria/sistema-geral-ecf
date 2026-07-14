---
phase: 75-empresas-shopee-habilitar-nps-para-clientes-atendidos-na-sho
plan: 03
subsystem: comercial
tags: [shopee, comercial, cadastro, nps, testing]
requires:
  - "75-01 (serviço 'Shopee' semeado + setor 'shopee' no enum)"
provides:
  - "Prova de regressão: cadastro Comercial de empresa Shopee sem dado ML"
affects:
  - "ComercialController::store (comportamento validado, não alterado)"
tech-stack:
  added: []
  patterns:
    - "Feature test Inertia via viewData('page')['props']"
    - "ReflectionMethod para asserção de helper privado (slugSetorParaServico)"
key-files:
  created:
    - "tests/Feature/Phase75/Phase75CadastroShopeeTest.php"
    - ".planning/phases/75-.../deferred-items.md"
  modified: []
decisions:
  - "Verificação-only: ComercialController NÃO precisou de edição — os helpers já retornam null para o nome exato 'Shopee' (Pitfall 3 confirmado empiricamente)"
metrics:
  duration: "~15min"
  tasks_completed: 1
  files_created: 2
  files_modified: 0
  completed_date: 2026-07-14
---

# Phase 75 Plan 03: Cadastro Comercial de Empresa Shopee sem ML — Summary

Feature test que PROVA end-to-end que `ComercialController::store()` cadastra uma empresa atendida apenas na Shopee — contrato de serviço setor `shopee`, sem nenhum dado ML (`adman_account_id`/`ml_store_id` nulos) e sem disparar `MlbEmpresa` — confirmando que nenhuma alteração de código era necessária (verificação-only).

## O que foi construído

`tests/Feature/Phase75/Phase75CadastroShopeeTest.php` (namespace `Tests\Feature\Phase75`, `RefreshDatabase`), com 5 casos verdes:

1. **`test_cadastro_shopee_cria_company_sem_dado_ml`** — POST `route('comercial.empresas.store')` com o serviço "Shopee" cria 1 `Company` com `adman_account_id` e `ml_store_id` NULOS, `status='pendente'`, sem exception (`assertSessionHasNoErrors` + `assertSessionHas('success')`).
2. **`test_cadastro_shopee_cria_um_contrato_ativo_setor_shopee`** — exatamente 1 `ContratoServico` ativo cujo `servico.setor === Servico::SETOR_SHOPEE`.
3. **`test_cadastro_shopee_nao_cria_mlb_empresa`** — zero `MlbEmpresa` para a company (`count()===0` + `assertDatabaseMissing`).
4. **`test_helpers_de_roteamento_ml_retornam_null_para_shopee`** — trava a regressão do Pitfall 3: `ComercialController::servicoDisparaImplementacao('Shopee')` retorna `null` (helper público estático) e `slugSetorParaServico('Shopee')` retorna `null` (helper privado, acessado via `ReflectionMethod`).
5. **`test_servico_shopee_aparece_em_servicos_disponiveis`** — guard extra: o serviço "Shopee" aparece em `servicos_disponiveis` no wizard (`comercial.empresas.novo`).

## Como funciona

- O serviço "Shopee" é semeado pela migration `2026_07_14_100002_seed_servico_shopee` (Plan 75-01), que roda no `RefreshDatabase`. O helper `servicoShopee()` usa `firstOrCreate` como cinto de segurança contra variação de ordem.
- O nome EXATO "Shopee" não casa nenhum prefixo do `str_contains` dos helpers de roteamento ML (`Polos`/`Assessoria`/`Incubadora`), então o loop de `MlbEmpresa` em `store()` (:609-633) simplesmente não executa nenhum ramo — a company Shopee cai no caminho "apenas company".
- `adman_account_id`/`ml_store_id` sequer aparecem no `Company::create` de `store()` (:556-576), logo permanecem nulos por design.

## Desvios do Plano

Nenhum desvio de código. Conforme antecipado pela PATTERNS (Pitfall 3, A3), a **ação foi verificação-only**: o comportamento correto já existia e o `ComercialController` não foi editado. O `files_modified` do plano listava o controller como possível alvo apenas caso o teste revelasse um caminho quebrado — não revelou.

## Deferred Issues

A verificação de regressão `php artisan test --filter=Comercial` acusou 11 falhas em `Phase13ComercialTest`/`Phase14ComercialTest`. **Pré-existentes e não relacionadas** — esses testes usam o payload legacy `service_type` (removido no refactor da Phase 14 que migrou para `servicos[]`) e falham identicamente em isolamento, com ou sem as mudanças da Phase 75. Registrado em `deferred-items.md` para uma quick task de manutenção de suíte. Fora do escopo do executor (SCOPE BOUNDARY — falhas pré-existentes em arquivos não relacionados).

## Verificação

- `php artisan test --filter=Phase75CadastroShopeeTest` → **5 passed (17 assertions)**.
- `php artisan test --filter=Comercial` → 38 passed; 11 falhas pré-existentes (legacy, documentadas acima).

## Self-Check: PASSED

- `tests/Feature/Phase75/Phase75CadastroShopeeTest.php` — FOUND
- Commit `49952f7` — FOUND
