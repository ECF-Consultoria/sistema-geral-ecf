---
quick_id: 260805-dzu
slug: ppa-polos-problema-meta
date: 2026-08-05
branch: quick/260805-dzu-ppa-polos-problema-meta
status: complete
deployed: false
---

# Sumário — PPA Polos + problema que não desconsidera da meta

Branch criada a partir de `origin/main` (a9864c9b). **Não deployado** — aguardando
autorização explícita.

## O que mudou

### 1. Problema deixou de tirar a empresa da meta por padrão

Nova coluna `mlb_empresas.problema_desconsidera_meta` (bool, default `false`).

| Situação | Antes | Agora |
|---|---|---|
| Empresa com problema | sempre status `Problema`, fora da meta | segue contando (No alvo / Em progresso / Não) |
| Problema marcado como "desconsiderar" | — | status `Problema`, fora da meta |

- `PolosController::desconsideraDaMeta()` é o único ponto que decide; aplicado nos
  três call sites de `calcularStatus()` (diagnóstico, `agregarPorPolo`, `distribuicaoStatus`).
- Roster histórico do CSV não tem os flags → `false` (comportamento inalterado em mês fechado).
- `MlbController::marcarProblemaEmpresa` ganhou a ação `meta` e aceita `desconsidera_meta`
  em marcar/editar; `remover` zera os dois campos. Tudo no activity log.
- **Backfill**: o default `false` fez as **30 empresas polos já marcadas com problema**
  voltarem a contar pra meta no momento da migration — conferido por reconsulta ao banco
  (`30 com problema | 0 fora da meta`).

UI no Painel Polos (drawer da empresa):
- Sem problema: **"Marcar problema · conta pra meta"** e **"Marcar e tirar da meta"**.
- Com problema: checkbox **"Desconsiderar da meta"** (ação `meta`, preserva a nota).
- Badge da linha fica roxo (cor do status `Problema`) e escreve `· fora da meta`.
- Filtro Situação ganhou o recorte **"Desconsiderada da meta"**.

### 2. PPA Polos

Item **PPA Polos** na seção Polos do menu, gated por `permission:mlb.projetos`.

- Mesma tabela `ppas`, separada pela coluna `escopo` (`geral` | `polos`) — tarefas,
  Kanban e workspace público do cliente continuam únicos.
- Alvo é `MlbEmpresa` (novo `ppas.mlb_empresa_id`), não `Company`: das 285 empresas
  POLOS ativas apenas 3 têm `company_id`, então `company_id` virou nullable.
- `PolosPpaController`: index/store/update/destroy/kanban/workspace-link no escopo polos;
  select lista só empresas POLOS **ativas** (arquivadas fora); `store` recusa (422) empresa
  de outro projeto; rotas de Polos dão 404 em PPA de carteira.
- `PpaController` passou a filtrar `escopo = geral` — os dois módulos não se misturam.
- Telas do PPA recebem os nomes de rota por prop (fallback nos nomes atuais);
  `Pages/Polos/Ppa/{Index,Kanban}.jsx` são wrappers finos, necessários para o menu casar
  o item ativo pelo prefixo do nome do componente.

## Verificação

- `tests/Feature/Polos/ProblemaDesconsideraMetaTest.php` — 8 testes (regra de cálculo +
  persistência pela rota do painel).
- `tests/Feature/Polos/PolosPpaTest.php` — 8 testes (listagem por escopo, select, criação,
  escopo cruzado, gate de permissão). A migration roda limpa no SQLite dos testes e no
  MariaDB local (guard por driver no `change()` do `company_id`).
- Migrations aplicadas no banco local e conferidas por `SHOW COLUMNS`.
- `npm run build` OK — inclusive as duas páginas novas no manifest do Vite.

## Pegadinhas encontradas

- **Re-export puro não entra no manifest do Vite.** `export { default } from '...'` some
  no bundle e a página quebra com *"Unable to locate file in Vite manifest"*. Página nova
  que só delega precisa ser um componente de verdade (`function X(props) { return <Y {...props}/> }`).
- **`tests/Feature/Phase38/PolosControllerTest.php` já tinha 10 falhas em `origin/main`**
  (confirmado rodando a suíte num worktree limpo do baseline): o faturamento migrou do CSV
  para a Adman e a assinatura do `SyncPolosFaturamentoJob` mudou, mas os testes não
  acompanharam. Nenhuma delas foi introduzida aqui — e não foram corrigidas (fora de escopo).

## Pendências

- Deploy isolado desta branch (aguardando autorização).
- Curadoria: decidir empresa a empresa quais problemas devem, de fato, sair da meta.
- Dívida pré-existente: atualizar os 10 testes quebrados de `PolosControllerTest`/`PolosFaturamentoSnapshotTest`.
