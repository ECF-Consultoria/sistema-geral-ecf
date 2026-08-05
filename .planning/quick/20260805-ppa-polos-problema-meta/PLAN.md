---
quick_id: 260805-dzu
slug: ppa-polos-problema-meta
date: 2026-08-05
branch: quick/260805-dzu-ppa-polos-problema-meta
status: in-progress
---

# Quick 260805-dzu — PPA Polos + problema que não desconsidera da meta

## Contexto

Dois pedidos independentes que sobem no mesmo deploy isolado (branch própria, nunca
editar direto na VPS — `deploy.sh` faz `reset --hard` e apaga arquivo não commitado).

### 1. PPA Polos

Réplica do módulo PPA dentro da seção Polos, listando **apenas empresas polos**.

Decisões tomadas com o usuário (2026-08-05):

- **Alvo = `mlb_empresas`** (não `companies`). Motivo factual: 285 empresas POLOS ativas,
  apenas **3** têm `company_id` preenchido — um PPA Polos amarrado a `Company` nasceria vazio.
- **Mesma tabela `ppas`**, separada por coluna `escopo` (`geral` | `polos`). Reaproveita
  `ppa_tasks`, Kanban e workspace público do cliente (link por token).
- **Gate `permission:mlb.projetos`** — mesma permissão do Painel Polos / Empresas Polos.

### 2. Problema com opção "desconsiderar da meta"

Hoje `mlb_empresas.problema` é booleano único e tem precedência máxima em
`PolosController::calcularStatus()` — qualquer empresa com problema cai no status
`Problema` e some da meta. Problemas básicos não deveriam tirar a empresa da meta.

- Nova coluna `problema_desconsidera_meta` (bool, **default false**).
- `false` → empresa segue contando: No alvo / Em progresso / Não, conforme faturamento.
- `true`  → empresa fica no status `Problema` (comportamento atual).
- **Backfill**: default `false` já faz as 30 empresas com problema hoje voltarem a contar
  pra meta — exatamente o que o usuário pediu ("tire os problemas").
- Novo problema nasce contando pra meta; tirar da meta é escolha explícita.

## Tarefas

### T1 — Backend do flag de meta
- Migration `add_problema_desconsidera_meta_to_mlb_empresas` (bool, default false).
- `MlbEmpresa`: fillable + cast.
- `MlbController::marcarProblemaEmpresa`: aceita `desconsidera_meta` em marcar/editar,
  limpa em remover, registra no activity log.
- `PolosController`: helper `desconsideraDaMeta()`; aplicar nos 3 call sites de
  `calcularStatus()` (diagnóstico, `agregarPorPolo`, `distribuicaoStatus`); incluir a
  coluna no roster (`montarAtivosDoMes`) e nas props do painel.

### T2 — UI do flag de meta (Painel Polos)
- Drawer da empresa: escolha explícita ao marcar ("Marcar problema" vs "Marcar e tirar
  da meta") + toggle "Desconsiderar da meta" quando já existe problema.
- Badge da linha diferencia problema que está fora da meta.

### T3 — Backend PPA Polos
- Migration: `ppas.escopo` (default `geral`, indexado), `ppas.mlb_empresa_id` (nullable FK),
  `ppas.company_id` → nullable.
- `Ppa`: fillable, relação `mlbEmpresa()`, scope `escopo()`, nome da empresa unificado.
- `PpaController`: passa a listar/criar só escopo `geral` (não mistura com Polos).
- `PolosPpaController`: index/store/update/destroy/kanban/workspace-link no escopo `polos`,
  com select das empresas POLOS ativas.
- Rotas `mlb.polos-ppa.*` sob `permission:mlb.projetos`.

### T4 — Front PPA Polos + menu
- `Ppa/Index.jsx` e `Ppa/Kanban.jsx` passam a receber os nomes de rota por prop
  (fallback nos nomes atuais → módulo existente não muda de comportamento).
- `Pages/Polos/Ppa/Index.jsx` e `Kanban.jsx` reexportam os componentes (component name
  distinto para o highlight do menu não colidir com o item PPA).
- Item "PPA Polos" na seção Polos do `AppLayout`.
- `npm run build`.

## Verificação

- Suíte: `tests/Feature/Phase38/PolosControllerTest.php`, `tests/Feature/Polos/`.
- Migração local aplicada e conferida por reconsulta ao banco (nunca por stdout).
- Distribuição de status antes/depois: as 30 empresas com problema saem do balde
  `Problema` e se redistribuem por faturamento.

## Fora de escopo

- Deploy (só com autorização explícita do usuário).
- Backfill manual empresa a empresa do flag de meta.
