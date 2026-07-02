---
plan: 52-03
status: complete
completed_at: 2026-07-02
---

# Plan 52-03 — SUMMARY (Wave 3, features UI drilldown)

Wave 3 entrega as features novas sobre a UI limpa da Wave 2:
`ConfigResumoCard` lateral (A3) + botão "Rodar análise" com cronômetro 30s
(A8), ambos no drilldown `Sugadores/Show.jsx`. As tarefas T1 (migrar
`copyMlbsLinha`) e T2 (bulk copy MLBs na barra sticky) ficaram **superseded**
pela decisão Opção Z da Wave 2 — os alvos foram removidos completamente do
Index.jsx (tabela lista global + barra bulk + checkboxes).

## Commits criados (2)

| SHA | Mensagem | Tarefas |
|---|---|---|
| `7f37c60` | feat(52-03): SugadorController::show expoe sugador_config e can_manage_config (A3) | T3 backend |
| `8b54fc9` | feat(52-03): ConfigResumoCard lateral + botao Rodar analise com cronometro 30s (A3+A8) | T3+T4 frontend |

## Arquivos modificados

- `app/Http/Controllers/SugadorController.php` — `show()` ganha props
  `sugador_config` (payload compacto: `ativo`, `dias_analise`,
  `gasto_minimo_sem_venda`, `cpc_maximo`, `acos_maximo_pct`,
  `cliques_minimos_sem_venda`) e `can_manage_config` (`Gate::allows('manage', Sugador::class)`).
- `resources/js/Pages/Sugadores/Show.jsx` — 847 → 1038 linhas (+191).
  Novos: props desestruturadas, estados `analyzing`/`elapsed`, `useEffect`
  cronômetro, handler `rodarAnalise`, grid `lg:grid-cols-4` envolvendo
  `MlbsDoAdgroup` (3 cols) + `ConfigResumoCard` (1 col lateral), componente
  local `ConfigResumoCard` completo com badge ATIVA/INATIVA/DEFAULT, dl de
  thresholds e 2 botões (Configurar + Rodar análise).

## Build

```
✓ built in 11.95s (npm run build) — zero errors, zero warnings novos.
```

Nenhum arquivo dependente quebrou. Show.jsx bundle passou de ~35.34 kB
(commit anterior) para ~38.55 kB — dentro do esperado.

## Desvios do plano original

### T1 (copyMlbsLinha → mlbs-hint) — SUPERSEDED pela Wave 2

**Situação encontrada:** a função `copyMlbsLinha` **não existe mais** no
Index.jsx. A Wave 2 removeu a tabela lista global inteira (Opção Z documentada
no 52-02-SUMMARY.md), eliminando todos os call-sites do endpoint
`sugadores.mlbs` no Index. O único endpoint remanescente em Index.jsx é
`sugadores.mlbs-by-company` no `CompanyCard`, que já funciona para empresas
ML-only via provider ML (endpoint dedicado, serialização por `Cache::lock`
por custId).

**Decisão do executor:** manter `sugadores.mlbs-by-company` no CompanyCard
sem migrar para `mlbs-hint`. Razão: os dois endpoints têm shapes e granulari-
dades diferentes — `mlbs-hint` opera por sugador (adgroup), `mlbs-by-company`
agrega N sugadores por empresa. A migração forçada exigiria N chamadas em
loop no frontend, degradando UX. O endpoint `mlbs-hint` criado na Wave 1
continua disponível e é consumido dentro do `MlbsDoAdgroup` no Show.jsx via
prop `mlbsHint` (SSR) — o bug 422 ML-only já está resolvido.

### T2 (Barra bulk "Copiar MLBs dos selecionados") — SUPERSEDED pela Wave 2

**Situação encontrada:** a barra sticky de bulk actions (linhas 1128-1159 do
Index.jsx original) **foi removida na Wave 2** junto com a tabela lista
completa. Não há mais checkboxes, `selectedIds` (Set), nem barra sticky no
Index. O drilldown `Show.jsx` é de UM sugador, então "bulk selection" não
faz sentido lá.

**Decisão do executor:** endpoint backend `POST /sugadores/bulk-copy-mlbs`
(criado na Wave 1, rota registrada) **fica preservado** para uso futuro —
não regride nem quebra. Se demanda de bulk actions ressurgir (novo widget,
página dedicada de auditoria etc), o endpoint já está pronto. Não introduzir
UI de bulk agora porque não há paradigma de seleção múltipla ativa no fluxo
atual.

## Success criteria (6/6 atendidos ou justificados)

- [x] `copyMlbsLinha` — **superseded** (T1 documentado como resolvido-por-
      remoção; RESEARCH §A5 previa que a Wave 2 poderia remover; Wave 2 confirmou).
      Zero regressão para empresas ML-only (o único botão de copiar em Index
      é o do CompanyCard, que usa endpoint dedicado provider ML).
- [x] Ação em massa "Copiar MLBs" — **superseded** (T2 documentado; endpoint
      backend preservado).
- [x] `ConfigResumoCard` renderiza no drilldown Show.jsx com threshold,
      status ATIVA/INATIVA/DEFAULT e botão "Configurar" que navega para
      `sugadores.config.show`.
- [x] Botão "Rodar análise" chama `sugadores.analyze-company`, com cronômetro
      client-side fixo de 30s, seguido de `router.reload({ only: ['sugador'] })`.
      Estado `analyzing` NÃO é resetado no `onFinish` (para o cronômetro
      completar); apenas `onError` volta idle.
- [x] Backend `show()` retorna `sugador_config` (schema real: `ativo`,
      `dias_analise`, `gasto_minimo_sem_venda`, `cpc_maximo`, `acos_maximo_pct`,
      `cliques_minimos_sem_venda`) e `can_manage_config`.
- [x] `npm run build` verde (11.95s, zero warnings/errors novos).

## Decisões de UX registradas

- **Onde ficou o botão "Rodar análise":** dentro do `ConfigResumoCard`
  lateral, logo abaixo do "Configurar". Preferência do plano confirmada —
  fica próximo do contexto de config, discreto sem competir com o header.
  Alternativa (banner no topo) descartada para preservar o header limpo.

- **Grid do drilldown:** `lg:grid-cols-4` com `MlbsDoAdgroup` em
  `lg:col-span-3` e `ConfigResumoCard` em `lg:col-span-1`. Em telas menores
  (`< lg`) empilha (card lateral vira card no topo via `order-first
  lg:order-last`, para preservar a hierarquia "config primeiro" mobile e
  "MLBs primeiro" desktop). Para `tipo=campanha` (que não tem `MlbsDoAdgroup`),
  o card ocupa linha inteira sozinho.

- **Fields expostos no ConfigResumoCard:** apenas os thresholds mais
  operacionais (`dias_analise`, `gasto_minimo_sem_venda`, `cpc_maximo`,
  `acos_maximo_pct`, `cliques_minimos_sem_venda`). Campos avançados
  (`gasto_minimo_logic`, `pct_anuncios_para_flag_campanha`, `incluir_*`)
  ficam para o operador editar via botão "Configurar" na página completa —
  evita poluir card lateral compacto com >8 linhas.

- **Estado DEFAULT:** quando a empresa não tem `SugadorConfig` própria
  (payload `sugador_config = null`), o card renderiza badge cinza "DEFAULT"
  + mensagem "Nenhuma configuração personalizada — usando defaults do
  sistema.". Mais claro que só esconder o card.

- **Rota `sugadores.config.show` recebe `company.id`:** conforme rota
  `routes/web.php:329` (`/sugadores/configs/{company}`), o param é o id da
  Company, não do Sugador. Testado — `router.visit(route('sugadores.config.show',
  companyId))` compila sem erro no Ziggy.

- **Comportamento se user navegar durante análise:** aceitável. Job continua
  na queue (background). Ao voltar, `router.reload` do próximo drilldown já
  puxa os novos sugadores identificados.

## Notas finais

- Todos os commits em pt-BR conforme feedback_gsd_language_pt_br.
- ROADMAP.md e STATE.md não alterados (fora do escopo do executor).
- Deploy NÃO executado.
- Próximo: Wave 4 (Plan 52-04) — UAT + smoke tests visuais nos fluxos:
  botão Copiar MLBs em empresas ML-only (via CompanyCard), abrir drilldown
  → ver card lateral com config + status → clicar Rodar análise → cronômetro
  1s..30s → reload silencioso.

## Self-Check: PASSED

- `app/Http/Controllers/SugadorController.php` — modificado (T3 backend).
- `resources/js/Pages/Sugadores/Show.jsx` — modificado (T3+T4 frontend).
- Commit `7f37c60` presente no git log.
- Commit `8b54fc9` presente no git log.
- SUMMARY criado em
  `.planning/phases/52-melhorias-ui-ux-comportamento-sugadores/52-03-SUMMARY.md`.
- `npm run build` verde (11.95s, sem novos warnings/errors).
- Greps de verificação:
  * `ConfigResumoCard|sugador_config` em Show.jsx: 7 matches (>= 2 required).
  * `rodarAnalise|sugadores.analyze-company|setAnalyzing|elapsed` em Show.jsx:
    15 matches (>= 3 required).
  * Total `sugadores.mlbs-hint|sugadores.bulk-copy-mlbs|sugadores.analyze-company|ConfigResumoCard`
    em Index.jsx + Show.jsx: 5 (>= 4 required).
