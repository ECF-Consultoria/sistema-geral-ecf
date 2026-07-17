---
phase: 92-ui-de-desempenho-ranking-metadados-v17-0
verified: 2026-07-17T00:00:00Z
status: human_needed
score: 8/8 must-haves verificáveis por código/teste — PASS; 1 gate humano bloqueante do plano não foi executado
overrides_applied: 0
human_verification:
  - test: "Em /performance: profissional só-Shopee (score_status='blocked') mostra badge 'Aguarda régua Shopee' e nota '—'"
    expected: "Badge âmbar visível na célula Nota, nota exibida como '—' (não 0 nem em branco)"
    why_human: "Aparência visual (cor, posicionamento, legibilidade) não é verificável por grep/teste automatizado"
  - test: "Tooltip da célula 'Empresas' em qualquer linha do ranking mostra empresas únicas / vínculos de serviço / vínculos sem fonte financeira"
    expected: "title nativo do navegador exibe os 3 números ao passar o mouse"
    why_human: "Tooltip nativo (atributo title) só é visível via hover manual no navegador"
  - test: "Select de contexto (Todos/Mercado Livre/Shopee) na toolbar de /performance muda as linhas exibidas sem alterar nenhuma nota"
    expected: "Trocar o select filtra visualmente o ranking; nota_final de cada profissional permanece idêntica em qualquer opção"
    why_human: "Comportamento de UI interativa (filtro client-side) — a lógica foi confirmada por leitura de código (useMemo) mas o comportamento renderizado precisa de confirmação visual"
  - test: "/performance/{profissional blocked} — card mostra badge de status + explicação pt-BR + os 4 metadados"
    expected: "FaixaBonusCard exibe badge 'Aguarda régua Shopee', texto explicativo sem jargão, bloco com 4 números"
    why_human: "Layout/legibilidade do card — aparência visual"
  - test: "/portfolio/{profissional blocked} logado como ele (self-view) — mensagem 'sua nota ainda não é oficial (aguarda régua Shopee)' no lugar da comparação de pares"
    expected: "Card âmbar com mensagem substitui o card de comparação — sem 0.0 fantasma nem os dois cards juntos"
    why_human: "Fluxo de navegação + estado de sessão (login como o próprio profissional) — requer teste manual"
  - test: "/portfolio/{profissional official} com pares — comparação de pares aparece normal e 'vs N analistas' NÃO conta o blocked"
    expected: "Card de comparação renderiza normalmente com tamanho_amostra correto"
    why_human: "Confirmação visual complementar ao teste automatizado (ComparacaoContextualBlockedTest já prova o número via asserção, mas o checkpoint pede confirmação visual do card renderizado)"
---

# Fase 92: UI de Desempenho — ranking + metadados (v17.0) — Relatório de Verificação

**Objetivo da Fase:** A UI de Desempenho mantém o ranking único e exibe os metadados por profissional (empresas únicas, vínculos, vínculos sem fonte, status oficial/parcial/bloqueada); filtro de auditoria por setor não cria segundo score. INCLUI a correção da pendência de bônus transferida da Fase 91 (comparacaoContextual).

**Verificado em:** 2026-07-17
**Status:** human_needed
**Re-verificação:** Não — verificação inicial

## Achievement do Objetivo

### Truths Observáveis

| # | Truth | Status | Evidência |
|---|-------|--------|-----------|
| 1 | Ranking `/performance` inclui os 6 metadados de elegibilidade por linha, lidos direto de `$resultado` sem recomputar | ✓ VERIFICADO | `PerformanceController.php:153-158` — 6 chaves lidas de `$resultado` (já calculado nas linhas 113-124, `computeCached`/`breakdown_json`); nenhuma chamada nova ao service. Teste `PerformanceIndexMetadadosTest::test_ranking_inclui_os_6_metadados_de_elegibilidade_por_linha` PASS. |
| 2 | Profissional `blocked` permanece no ranking (não some) com `score_status='blocked'` e `vinculos_sem_fonte_financeira > 0` | ✓ VERIFICADO | Teste `test_profissional_blocked_permanece_no_ranking_com_score_status_e_vinculos_sem_fonte` PASS — confirma `sem_carteira=false`, `score_status='blocked'`, `nota_final=null`. |
| 3 | `?contexto=` é whitelist (todos\|performance\|shopee), valor inválido cai em 'todos', e NUNCA chega ao `DesempenhoScoreService` | ✓ VERIFICADO | `PerformanceController.php:275-282` — `match()` explícito; `$contextoFiltro['setor']` nunca é lido em nenhum outro ponto do arquivo (grep confirma só `$contextoFiltro['param']` é usado, linha 261). Teste `test_contexto_e_view_only_nota_final_e_score_status_identicos_entre_valores` PASS — prova `nota_final`/`score_status` idênticos entre 4 valores de contexto (incluindo inválido). |
| 4 | No `comparacaoContextual`, profissional `blocked` é excluído de `$scoresPares` (mesmo `continue` do `sem_carteira`) — não vira 0.0 | ✓ VERIFICADO | `PortfolioController.php:1498-1500` — guard `score_status === 'blocked'` → `continue`, no mesmo loop do guard `sem_carteira` (linha 1489). Teste `ComparacaoContextualBlockedTest::test_blocked_nao_vira_0_ponto_0_na_comparacao_e_tamanho_amostra_bate_com_a_mediana` prova as Distorções A e B **no mesmo cenário** (3 officials + 1 blocked), com oráculo calculado via `DesempenhoScoreService::compute()` direto (sem hardcode). PASS. |
| 5 | `tamanho_amostra` conta só pares com nota calculável, batendo com a base da mediana | ✓ VERIFICADO | Mesmo teste acima — `tamanho_amostra === 3` (não 4) e mediana/percentil calculados só com os 3 officials, batendo com o oráculo externo. |
| 6 | Self-view do profissional `blocked` não gera comparação fantasma (`comparacao_contextual=null`) e `score_status` fica disponível | ✓ VERIFICADO | `PortfolioController.php:1510-1511` — guard explícito antes do `>= 2`. Teste `test_self_view_do_profissional_blocked_nao_gera_0_ponto_0_fantasma` PASS — `comparacao_contextual` null, `score_status='blocked'`, `nota_final=null` (nunca 0.0 default). |
| 7 | UI usa labels travados sem jargão (nunca slug cru `blocked`/`partial`/`official` como texto) | ✓ VERIFICADO (por leitura) | `SCORE_STATUS_LABEL` presente em `Performance/Index.jsx:58-62` e `Performance/Show.jsx:64-68` com `blocked→'Aguarda régua Shopee'`, `partial→'Parcial'`, `official→'Oficial'`/sem badge. Todo acesso a `score_status` no JSX passa pelo lookup (`SCORE_STATUS_LABEL[x] ?? x` — fallback só ativa para valores desconhecidos, nunca para os 3 valores válidos). `Portfolio/Show.jsx` usa `score_status==='blocked'` só como condicional, texto renderizado é literal pt-BR ("Sua nota ainda não é oficial"). |
| 8 | Ranking permanece único — nenhum score/ranking separado por marketplace (SC1) | ✓ VERIFICADO | `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` → 0 ocorrências (rodado de forma independente). Rota única confirmada: `/performance` (`index`) e `/performance/{user}` (`show`) — nenhuma rota adicional por setor em `routes/web.php`. |
| 9 (gate do plano) | Checkpoint visual humano (Task 3 do 92-02-PLAN.md, `gate="blocking"`) aprovado | ✗ NÃO EXECUTADO | 92-02-SUMMARY.md declara explicitamente: "Tasks: 2/2 completas (Task 3 é checkpoint visual — não executada, ver seção abaixo)". O gate bloqueante do próprio plano não foi cumprido — rota para verificação humana, não PASS automático. |

**Score:** 8/9 truths verificadas automaticamente/por leitura de código; 1 gate humano do plano pendente.

### Artefatos Obrigatórios

| Artefato | Esperado | Status | Detalhes |
|----------|----------|--------|----------|
| `app/Http/Controllers/PerformanceController.php` | Passthrough dos 6 metadados + `contextoFiltro()` view-only + prop `contexto` | ✓ VERIFICADO | Linhas 41, 153-158, 261, 275-282 — confirmado por leitura. |
| `app/Http/Controllers/PortfolioController.php` | Exclusão de `blocked` em `$scoresPares` + `tamanho_amostra` corrigido | ✓ VERIFICADO | Linhas 1498-1512 — confirmado por leitura + teste. |
| `tests/Feature/V16/PerformanceIndexMetadadosTest.php` | Cobre SC2 + SC3 + SC1 | ✓ VERIFICADO | 4 testes, todos PASS (rodado independentemente). |
| `tests/Feature/V16/ComparacaoContextualBlockedTest.php` | Cobre Distorção A/B + self-view | ✓ VERIFICADO | 2 testes, todos PASS (rodado independentemente). |
| `resources/js/Pages/Performance/Index.jsx` | Badge de status + metadados (tooltip) + select de contexto | ✓ VERIFICADO (código) / ? PENDENTE (visual) | `SCORE_STATUS_LABEL`/`BADGE_CLS`/`TOOLTIP` (linhas 58-70), badge na célula Nota (linha 476-484), tooltip na célula Empresas (linha 545), `rankingFiltrado` via `useMemo` (linhas 200-208). `npm run build` exit 0. Aparência não confirmada por humano. |
| `resources/js/Pages/Performance/Show.jsx` | Badge + metadados no FaixaBonusCard | ✓ VERIFICADO (código) / ? PENDENTE (visual) | Linhas 62-77 (labels), 157-161 (`temMetadados`), 208-215 (badge), 236-237 (explicação), 244-262 (bloco de 4 metadados). |
| `resources/js/Pages/Portfolio/Show.jsx` | Mensagem self-view do blocked | ✓ VERIFICADO (código) / ? PENDENTE (visual) | Linhas 1144-1166 (card de mensagem), 1170 (guard defensivo adicional excluindo blocked do card normal). `performance_profissional.score_status` preservado via spread `...v2` no `useMemo` de compat v2→v1 (linha 484-497). |

### Verificação de Key Links

| De | Para | Via | Status | Detalhes |
|----|------|-----|--------|----------|
| `PerformanceController::index()` | `resultado` (compute cacheado) | map dos 6 metadados | ✓ WIRED | Nenhuma chamada nova a `computeCached`/`compute` nas linhas 153-158; leem `$resultado` já resolvido nas linhas 113-124. |
| `PortfolioController::show()` comparacaoContextual | `$scoresPares` | `continue` quando `score_status==='blocked'` | ✓ WIRED | Linha 1498-1500, confirmado + testado. |
| `Performance/Index.jsx` `RankingConsultoria` | `u.score_status` | lookup `SCORE_STATUS_LABEL` + badge | ✓ WIRED | Linha 476-484. |
| `Performance/Index.jsx` select contexto | `rankingFiltrado` (useMemo) | filtro client-side por `vinculos_sem_fonte_financeira` | ✓ WIRED | Linhas 200-208 — nunca toca `nota_final`. |
| `Portfolio/Show.jsx` | `performance_profissional.score_status` | render condicional da mensagem | ✓ WIRED | Linha 1148, 1170. |

### Rastro de Fluxo de Dados (Nível 4)

| Artefato | Variável de dado | Fonte | Dados reais? | Status |
|----------|-------------------|-------|----------------|--------|
| `ranking[].score_status` (Performance/Index.jsx) | `resultado['score_status']` | `DesempenhoScoreService::compute()`/`computeCached()` (Fase 91, intacto) | Sim — service confirmado ainda retorna as 6 chaves (`app/Services/DesempenhoScoreService.php:124-129, 308-313`) | ✓ FLOWING |
| `comparacao_contextual` (Portfolio/Show.jsx) | `$comparacaoContextual` | Loop `$scoresPares` sobre `User::whereIn(...)->get()` + `computeCached()` real | Sim — nenhum fallback estático; teste prova valores calculados batem com oráculo externo | ✓ FLOWING |

### Testes Executados (independentemente pelo verificador)

| Comando | Resultado |
|---------|-----------|
| `php vendor/bin/phpunit --filter="PerformanceIndexMetadadosTest\|ComparacaoContextualBlockedTest"` | 6/6 PASS, 64 assertions |
| `grep -rin "score_shopee\|score_ml\|ranking_shopee\|ranking_ml" app/ resources/js/` | 0 ocorrências (gate SC1 limpo) |
| `php vendor/bin/phpunit --filter="Desempenho\|Bonus\|Portfolio"` | 104 testes, 657 assertions, **4 falhas — todas pré-existentes e não relacionadas** (ver abaixo) |
| `npm run build` | exit 0 |

**Falhas pré-existentes confirmadas (não causadas pela Fase 92):**
- `Phase61\PortfolioMultiFonteE2ETest` (1 teste) + `Phase61\PortfolioSourceEnrichmentTest` (1 teste) — `user_portfolios` size 0 vs 1. Testes datam de commit `e22ce96`/`be2813f`, anteriores à Fase 92; o diff desta fase em `PortfolioController.php` toca apenas o bloco `comparacaoContextual` (linhas 1472-1584), não relacionado a `user_portfolios`.
- `PublicacaoDesempenhoRouteTest::test_user_com_mlb_dashboard_acessa_rota_e_recebe_200` — 403 em vez de 200; falha documentada desde 91-01-SUMMARY.md, ortogonal a esta fase (permissão de rota `mlb.dashboard`).

### Verificação de Fronteira (escopo dos 4 commits)

```
6ac70de — app/Http/Controllers/PerformanceController.php, tests/Feature/V16/PerformanceIndexMetadadosTest.php
a3abb22 — app/Http/Controllers/PortfolioController.php, tests/Feature/V16/ComparacaoContextualBlockedTest.php
3e6944c — resources/js/Pages/Performance/Index.jsx
d287327 — resources/js/Pages/Performance/Show.jsx, resources/js/Pages/Portfolio/Show.jsx
```

✓ Confirmado — nenhum commit toca `DesempenhoScoreService.php`, `CarteiraContextService.php`, `User.php`, `Company.php` ou qualquer arquivo `Nps/*`. Fronteira respeitada.

### Cobertura de Requisitos

| Requisito | Plano fonte | Descrição | Status | Evidência |
|-----------|-------------|------------|--------|-----------|
| DESEMP-08 | 92-01, 92-02 | UI de Desempenho mantém ranking único e exibe metadados por profissional; filtro de auditoria não cria segundo score | ✓ SATISFEITO (código+teste) / pendente confirmação visual | Ver truths 1-8 acima. `.planning/REQUIREMENTS.md:40` ainda marcado `[ ]` (não atualizado — esperado, é responsabilidade do orquestrador pós-verificação). |

### Anti-Patterns Encontrados

Nenhum marcador de dívida (`TBD`/`FIXME`/`XXX`/`TODO`/`HACK`/`PLACEHOLDER`) nos arquivos modificados desta fase. Os 4 matches de "TODO" em `PortfolioController.php` são falsos positivos (substring de "TODOS", palavra pt-BR).

### Verificação Humana Necessária

O `92-02-PLAN.md` define **Task 3 como `checkpoint:human-verify` com `gate="blocking"`** — um gate explícito que o plano exige antes de considerar a fase pronta. O `92-02-SUMMARY.md` confirma que essa task **não foi executada** ("Tasks: 2/2 completas (Task 3 é checkpoint visual — não executada)"). Isso não é uma falha de código — a lógica está implementada e coberta por leitura/testes onde é automatizável — mas é um gate formal do próprio plano que ainda não foi cumprido, então a fase não pode ser marcada `passed` sem essa confirmação.

Itens a confirmar (harvested do `<how-to-verify>` do checkpoint + dos `<human-check>` das Tasks 1/2):

### 1. Badge "Aguarda régua Shopee" no ranking

**Teste:** Abrir `/performance`. Localizar profissional só-Shopee (ex.: Matheus/Gustavo/Felipe conforme dados reais).
**Esperado:** Badge âmbar "Aguarda régua Shopee" visível, nota exibida como "—" (não some do ranking, não vira 0).
**Por que humano:** Aparência visual — cor, contraste, legibilidade do badge.

### 2. Tooltip de metadados na célula Empresas

**Teste:** Passar o mouse sobre a célula "Empresas" de qualquer linha do ranking.
**Esperado:** Tooltip nativo mostra empresas únicas / vínculos de serviço / vínculos sem fonte financeira.
**Por que humano:** Tooltip via atributo `title` só é visível em hover manual no navegador.

### 3. Select de contexto filtra sem recalcular nota

**Teste:** Trocar o select Todos/Mercado Livre/Shopee na toolbar de `/performance`.
**Esperado:** Linhas exibidas mudam; nenhuma nota muda de valor ao trocar.
**Por que humano:** Confirmação visual do comportamento interativo (lógica já confirmada por leitura de `useMemo`, mas o checkpoint pede validação end-to-end no navegador).

### 4. Drill-down do blocked (`/performance/{id}`)

**Teste:** Abrir `/performance/{profissional blocked}`.
**Esperado:** Card mostra badge de status + explicação pt-BR + os 4 metadados, sem quebrar layout.
**Por que humano:** Layout/legibilidade do card.

### 5. Self-view do blocked (`/portfolio/{id}`)

**Teste:** Logar como o profissional blocked e abrir a própria carteira.
**Esperado:** Mensagem "sua nota ainda não é oficial (aguarda régua Shopee)" no lugar do card de comparação — sem 0.0 fantasma, sem os dois cards juntos.
**Por que humano:** Requer sessão autenticada como o profissional específico — fluxo de navegação real.

### 6. Comparação de pares do official não conta o blocked

**Teste:** Abrir `/portfolio/{profissional official}` com pares no mesmo cargo (incluindo pelo menos 1 blocked).
**Esperado:** Card de comparação normal, "vs N analistas" reflete só os pares com nota calculável.
**Por que humano:** Confirmação visual complementar — o número já é provado corretamente por `ComparacaoContextualBlockedTest`, mas o checkpoint pede visualização do card renderizado.

## Resumo de Gaps

Não há gaps de implementação — toda a lógica de backend (passthrough dos metadados, filtro view-only, correção da Distorção A/B do `comparacaoContextual`) está implementada corretamente e coberta por testes que o verificador executou de forma independente (6/6 PASS). O frontend está implementado consistentemente com o plano (labels travados, sem slug cru, filtro client-side, self-view do blocked) confirmado por leitura de código, e `npm run build` passa.

O único item pendente é o **checkpoint visual humano bloqueante** definido no próprio `92-02-PLAN.md` (Task 3), que a execução admite não ter rodado. Como esse checkpoint cobre exclusivamente comportamento visual/interativo (aparência de badges, tooltips nativos, fluxo de navegação logado como usuário específico) que não é verificável por grep/teste automatizado, a fase é classificada `human_needed` — não `gaps_found` — pois não há evidência de código incorreto, apenas ausência da confirmação visual exigida pelo plano.

---

_Verificado em: 2026-07-17_
_Verificador: Claude (gsd-verifier)_
