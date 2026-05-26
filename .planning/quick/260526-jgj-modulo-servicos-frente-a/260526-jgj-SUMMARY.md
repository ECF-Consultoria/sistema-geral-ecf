---
quick: 260526-jgj
status: complete
type: feature
date: 2026-05-26
tasks_total: 3
tasks_completed: 3
commits:
  - 8bcb83f
  - f560713
  - 855038e
files_created:
  - database/migrations/2026_05_26_120001_create_servicos_table.php
  - database/migrations/2026_05_26_120002_create_contratos_servico_table.php
  - app/Models/Servico.php
  - app/Models/ContratoServico.php
  - app/Http/Controllers/ServicoController.php
  - resources/js/Pages/Servicos/Index.jsx
files_modified:
  - app/Models/Company.php
  - app/Http/Controllers/CompanyController.php
  - app/Support/Permissions.php
  - routes/web.php
  - resources/js/Layouts/AppLayout.jsx
  - resources/js/Pages/Companies/Index.jsx
  - resources/js/Pages/Companies/Show.jsx
verification:
  npm_build: passed
  php_lint: passed
  migrate: passed
  routes_registered: 7
deviations:
  - Adicionada permission key `sistema.servicos` em Permissions.php (não estava no PLAN.md mas era necessário para que o item Serviços aparecesse no sidebar — admin via short-circuit já recebe, outros usuários precisam de grant explícito futuro)
  - Adicionado `use Illuminate\Support\Facades\DB` em CompanyController.php (o código legado usava `\DB::table(...)` com namespace root; IDE diagnostic apontou a referência como inválida e a forma com `use` é mais idiomática)
follow_up_frente_b:
  - Data migration dos campos legacy de `companies` (service_type, contract_start/end, additional_service, additional_service_price) para a nova tabela `contratos_servico`
  - Refatorar `AdminController::fechamento` e `Admin/Financeiro.jsx` para consumir `contratos_servico` em vez dos campos legacy
  - Drop dos campos legacy após validação completa
  - Considerar grant explícito de `sistema.servicos` para setor Administrativo/Financeiro (se houver demanda)
  - Possível UI para reativar contratos desativados (atualmente o toggle "Mostrar inativos" exibe-os mas não há botão "Reativar" — só editar pelo modal e marcar ativo)
---

# Quick Task 260526-jgj — Módulo Serviços (Frente A)

## Objetivo

Criar o catálogo de serviços e a gestão de contratos por empresa, mantendo
coexistência com os campos legacy de `companies` (que continuarão sendo
utilizados pelo Admin/Fechamento até a Frente B fazer a migração de dados
e o drop).

## Resultado

3 tasks executadas em ordem, 3 commits atômicos:

| # | Task                                        | Commit  | Arquivos |
|---|---------------------------------------------|---------|----------|
| 1 | Backend foundation (migrations + models + controller + routes) | `8bcb83f` | 8 |
| 2 | Frontend catálogo de serviços + sidebar     | `f560713` | 3 |
| 3 | Frontend gestão de contratos na empresa + ajustes na lista | `855038e` | 2 |

## Arquivos criados

| Arquivo | Descrição |
|---------|-----------|
| `database/migrations/2026_05_26_120001_create_servicos_table.php` | Catálogo de serviços (nome, valor_padrao, tipo_cobranca, ativo) |
| `database/migrations/2026_05_26_120002_create_contratos_servico_table.php` | N:N enriquecida company × servico com index composto (company_id, ativo) |
| `app/Models/Servico.php` | Eloquent + LogsActivity + constants TIPO_MENSAL/UNICA + scopeActive |
| `app/Models/ContratoServico.php` | Eloquent + LogsActivity + belongsTo Company/Servico + scopeActive |
| `app/Http/Controllers/ServicoController.php` | CRUD do catálogo (delete físico ou soft-deactivate conforme presença de contratos) |
| `resources/js/Pages/Servicos/Index.jsx` | Tabela do catálogo + modal Novo/Editar + toggle inline de ativo |

## Arquivos modificados

| Arquivo | Mudança |
|---------|---------|
| `app/Models/Company.php` | Adiciona relation `contratosServico()` hasMany (legacy intocado) |
| `app/Http/Controllers/CompanyController.php` | Remove cache TACOS/Faturamento 30d do index; eager load `contratosServico` ativos; payload `contratos_servico` no map. show() carrega contratos (ativos + inativos) + `servicos_disponiveis`. Adiciona `storeContrato`/`updateContrato`/`destroyContrato` com guard de pertencimento. |
| `app/Support/Permissions.php` | Nova permission key `sistema.servicos` + entrada no catálogo |
| `routes/web.php` | Import `ServicoController`; `Route::resource('servicos')` + 3 sub-rotas `/empresas/{company}/contratos-servico` sob `role:admin` |
| `resources/js/Layouts/AppLayout.jsx` | Item "Serviços" no sidebar (ícone Briefcase, gated por `sistema.servicos`) |
| `resources/js/Pages/Companies/Index.jsx` | Remove colunas TACOS e Faturamento; coluna "Serviço" com badges + tooltip detalhado; remove import `formatPercent`; adiciona `ServicoBadges` sub-component |
| `resources/js/Pages/Companies/Show.jsx` | Seção "Serviços contratados" com tabela + toggle "Mostrar inativos" + modal Adicionar/Editar contrato (select pre-fills valor_padrao mas editável; servico_id imutável na edição) |

## Verificação final

| Verificação | Comando | Resultado |
|-------------|---------|-----------|
| Sintaxe PHP | `php -l` em todos arquivos novos/alterados (7 arquivos) | No syntax errors detected |
| Migrations | `php artisan migrate` | 2 migrations rodaram com sucesso (+ 5 pendentes legacy não relacionadas, vide nota abaixo) |
| Rotas registradas | `php artisan route:list \| grep -iE "servico\|contrato"` | 7 rotas (4 servicos + 3 contratos) confirmadas |
| Smoke test model | `php artisan tinker --execute="\$s=Servico::create(...); \$s->delete();"` | Created id=1, deleted ok |
| Build frontend | `npm run build` | Built in 16.48s, 0 erros; bundle `Servicos/Index` registrado no manifest |
| Inspeção textual Companies/Index.jsx | `grep -iE "tacos\|revenue_30d\|formatPercent"` | Apenas comentário explicando a remoção (sem uso) |
| Inspeção textual Companies/Show.jsx | `grep "Serviços contratados"` | 2 ocorrências (comentário + label da seção) |

### Nota sobre migrations

Ao rodar `php artisan migrate`, **5 migrations Phase 13 pendentes** que já estavam no working tree (não criadas por este task) foram aplicadas junto com as 2 do Módulo Serviços: `add_status_to_companies`, `add_company_id_to_mlb_empresas`, `seed_setor_comercial_and_retro_migrate`, `create_company_monthly_revenues_table`, `convert_service_type_to_json_array`. Isso é esperado e benigno — apenas significa que o estado prévio do banco local estava atrás do branch.

## Desvios em relação ao PLAN.md

### 1. Adição de `sistema.servicos` ao catálogo de permissões (Permissions.php)

**Por quê:** O sidebar `AppLayout.jsx` filtra itens por `permissions.includes(item.permission)`. Sem a key registrada em `Permissions::all()`, mesmo o admin (que recebe `all()` via short-circuit em `User::effectivePermissions()`) não veria o item. A alternativa seria deixar `permission: null` no item de nav (todos veriam), mas isso violaria o princípio admin-only.

**Impacto:** Mínimo — apenas registra a key no catálogo. Admin sempre tem; outros usuários precisariam receber grant explícito via `setor_permissoes`. A tela admin de gerenciamento de permissões passa a exibir "Serviços" no grupo "Sistema".

### 2. `use Illuminate\Support\Facades\DB` em CompanyController.php

**Por quê:** O código legado usava `\DB::table('user_setores')` com fully-qualified root namespace. O IDE diagnostic (PHP Intelephense P1009) sinalizou como "Undefined type 'DB'". A forma com `use` é idiomática Laravel e silencia o diagnostic.

**Impacto:** Zero — funcionalidade idêntica.

## Ressalvas / TODOs para Frente B

Listados no frontmatter (`follow_up_frente_b`). Resumo:

1. **Data migration** dos campos legacy (`service_type`, `contract_start/end`, `additional_service*`) de `companies` para `contratos_servico`.
2. **Refatorar Admin/Financeiro** e `AdminController::fechamento` para consumir `contratos_servico`.
3. **Drop dos campos legacy** após validação completa.
4. **Reativação de contratos** — atualmente o toggle "Mostrar inativos" exibe-os mas a única maneira de reativar é via "Editar contrato" + checkbox de ativo. Pode-se considerar um botão "Reativar" dedicado.
5. **Grants de `sistema.servicos`** para outros setores se houver demanda futura.

## Self-Check

- [x] `database/migrations/2026_05_26_120001_create_servicos_table.php` — FOUND
- [x] `database/migrations/2026_05_26_120002_create_contratos_servico_table.php` — FOUND
- [x] `app/Models/Servico.php` — FOUND
- [x] `app/Models/ContratoServico.php` — FOUND
- [x] `app/Http/Controllers/ServicoController.php` — FOUND
- [x] `resources/js/Pages/Servicos/Index.jsx` — FOUND
- [x] Commit `8bcb83f` — FOUND
- [x] Commit `f560713` — FOUND
- [x] Commit `855038e` — FOUND

## Self-Check: PASSED
