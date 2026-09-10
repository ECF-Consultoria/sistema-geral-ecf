---
phase: 139-redesenho-da-tela-de-fechamento
plan: 05
subsystem: ui
tags: [react, inertia, tailwind, fechamento, area-expandida, tabela-progressiva]

# Dependency graph
requires:
  - phase: 139-04
    provides: "lista de empresas em 4 colunas, chips de filtro, correção da prop fantasma cobranca_mensal_grupo no arquivo inteiro"
provides:
  - "FechamentoAccordion reorganizado em três passos (faturou no mês / faixa do contrato / mensalidade a cobrar), com a comparação contra a faixa anterior no passo 3"
  - "TabelaFaixasSection destacando a faixa aplicada nas listas somente leitura (serviço e grupo), sem duplicar tabela"
  - "RecebidoToggle com rótulo, migrado da linha para a barra de ações da área expandida"
  - "Copy sem a palavra \"competência\" nos dois textos de FecharCompetenciaButton e no aviso de bloqueio do TabelaFaixasSection"
affects: [139-06, 139-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "AusenciaTabelaPendencia variant=\"full\" trocou onCadastrar (callback) por href (link de âncora #tabela-faixas-{id}) — único ponto de uso é o passo 2 do accordion, que só precisa pular para a seção, não abrir um modal"
    - "faixaOrdemAtual (int|null) é a única prop nova que atravessa FechamentoAccordion → TabelaFaixasSection; a comparação f.ordem === faixaOrdemAtual roda dentro do .map() de cada lista somente leitura (grupo e serviço), nunca uma segunda tabela"
    - "px-4.5/py-5.5 não existem na escala do Tailwind (pula de 3.5 para 4) — usado px-[18px] (arbitrário) para bater com o padding 16px/18px do handoff; conferido no CSS compilado antes de commitar"

key-files:
  created: []
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx
    - resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx

key-decisions:
  - "Container dos três passos usa px-5 pt-1 pb-6 flex flex-col gap-5 (conforme o plano) — isso exigiu remover o px-4 py-4 do wrapper externo do accordion (que duplicaria a margem) e mover esse padding para dentro; ContratosSection ganhou px-5 pb-5 próprio para manter o alinhamento horizontal com o resto do conteúdo"
  - "Card 1 (\"Faturou no mês\") usa `empresa.faturamento == null` como gate para o AusenciaFaturamentoBadge, cobrindo tanto o estado sem_faturamento quanto sem_integracao — o plano só citava \"sem faturamento\" explicitamente, mas os dois estados sempre produzem faturamento null no backend (confirmado lendo AdminController::fechamentoDadosPorEmpresaAoVivo), então a mesma checagem cobre os dois sem inventar um terceiro texto que o plano não pediu"
  - "Card 3 (\"Mensalidade a cobrar\") mostra '—' quando cobranca_mensal é null, em vez de esconder o card inteiro (como o código antigo fazia) — segue o mesmo padrão já usado na 'Composição do grupo' no mesmo arquivo (linha e ~e.cobranca_mensal != null ? fmtValorFaixa(...) : '—')"
  - "\"Este mês está fechado — a tabela não pode ser alterada.\" substitui \"Competência fechada — ...\" no aviso de bloqueio do TabelaFaixasSection — não estava nas duas correções obrigatórias do plan-checker (que citavam só FecharCompetenciaButton), mas é a mesma palavra proibida pelo CONTEXT e nenhum teste prendia o texto antigo; corrigido por ser a mesma regra, não uma tarefa nova"

patterns-established:
  - "Sub-linha do passo 3 é uma função pura (subLinhaMensalidade(empresa)) com 6 ramos em cascata na ordem exata do plano — retorna null quando nenhum se aplica, nunca um texto de preenchimento tipo 'sem comparação'"

requirements-completed: [D-01, D-02, D-04, D-05]

# Metrics
duration: ~1h40min
completed: 2026-09-04
---

# Phase 139 Plan 05: Área expandida em três passos + tabela progressiva com faixa atual destacada Summary

**`FechamentoAccordion` reescrito como a conta em três passos (faturou no mês / faixa do contrato / mensalidade a cobrar, com a comparação contra a faixa anterior) e `TabelaFaixasSection` destacando em amarelo a linha da faixa aplicada dentro das listas que já existiam — sem criar uma segunda tabela e sem perder nenhuma capacidade das Fases 137/138.**

## Performance

- **Duration:** ~1h40min
- **Completed:** 2026-09-04
- **Tasks:** 2/2
- **Files modified:** 2

## Accomplishments

- **Tarefa 1** — `FechamentoAccordion` reorganizado em três cards (`grid grid-cols-1 md:grid-cols-3 gap-3`): passo 1 mostra o faturamento do mês (ou `AusenciaFaturamentoBadge` quando não há dado) com o breakdown Mercado Livre + Shopee só quando as duas plataformas têm valor; passo 2 mostra a faixa e o intervalo de faturamento (ou vira `AusenciaTabelaPendencia variant="full"` com link para `#tabela-faixas-{id}` quando `estado === 'sem_tabela'`); passo 3 mostra a mensalidade e, na sub-linha, de onde a empresa veio quando mudou de faixa — seguindo a cascata exata do plano (subiu com valor conhecido → subiu sem valor → desceu com valor → desceu sem valor → manteve → nada). A copy do passo 3 usa "Na faixa anterior, eram R$ X", nunca "Era R$ X no mês passado" (o valor é da FAIXA, não necessariamente o que foi cobrado). Composição do grupo, `TabelaFaixasSection` e `ContratosSection` preservados na ordem definida pelo plano, com uma nova barra de ações reunindo "Ver progressão", "Gerar relatório PDF" e o `RecebidoToggle` (agora com rótulo "Marcar como recebido"/"Recebido" em vez do círculo sem texto).
- **Tarefa 2** — `TabelaFaixasSection` recebe a prop `faixaOrdemAtual` (int|null) e a repassa nas duas listas somente leitura que já tinha (tabela do serviço e tabela do grupo): a linha cuja `ordem` bate com `faixaOrdemAtual` ganha `bg-ecf-yellow/10` e texto em `text-ecf-yellow`. Cabeçalho das colunas trocado para o formato do handoff ("Faixa" / "Faturamento até" / "Mensalidade"), com a última faixa mostrando "acima" em vez de "Sem limite superior". Rótulo do bloco trocado de "Tabela de faixas aplicada" para "Tabela progressiva" + `· {nome do serviço/grupo}` quando existe.
- Duas correções de copy sem jargão, aplicadas como parte da Tarefa 1 (correções obrigatórias do plan-checker + a mesma regra estendida a um terceiro texto encontrado durante a execução): `confirm()` e mensagem de sucesso de `FecharCompetenciaButton` reescritos sem a palavra "competência"; e o aviso de bloqueio do `TabelaFaixasSection` ("Competência fechada — ...") também reescrito pela mesma razão, mesmo não estando nas duas correções citadas pelo plan-checker — é a mesma palavra proibida pelo `139-CONTEXT.md` e nenhum teste prendia o texto antigo.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Os três passos da conta** - `0d595c48` (feat)
2. **Tarefa 2: Tabela progressiva com a faixa atual destacada** - `b000dd84` (feat)

## Files Created/Modified

- `resources/js/Pages/Admin/Financeiro.jsx` — `FechamentoAccordion` reescrito, `AusenciaTabelaPendencia` (variant="full" trocou `onCadastrar` por `href`), `RecebidoToggle` com rótulo, `FecharCompetenciaButton` sem a palavra "competência", duas funções puras novas (`faixaContratoIntervalo`, `subLinhaMensalidade`).
- `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` — prop `faixaOrdemAtual`, destaque nas duas listas somente leitura, cabeçalho de colunas e rótulo do bloco no formato do handoff, aviso de bloqueio sem a palavra "competência", import de `cn`.

## Decisions Made

Ver `key-decisions` no frontmatter — resumo: o container dos três passos ficou exatamente como o plano descreveu (`px-5 pt-1 pb-6 flex flex-col gap-5`), o que exigiu mover o padding horizontal do wrapper externo do accordion para dentro (`ContratosSection` ganhou seu próprio `px-5 pb-5`); Card 1 trata `sem_faturamento` e `sem_integracao` com a mesma checagem porque os dois sempre produzem faturamento `null`; Card 3 mostra "—" em vez de sumir quando não há mensalidade, seguindo o padrão já usado na "Composição do grupo" no mesmo arquivo; e o aviso de bloqueio do `TabelaFaixasSection` foi corrigido pela mesma regra de "competência" mesmo não estando explicitamente nas duas correções obrigatórias do plan-checker.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Aviso de bloqueio do TabelaFaixasSection ainda tinha a palavra "competência"**
- **Found during:** Tarefa 2, ao revisar o arquivo inteiro em busca da palavra banida pelo `139-CONTEXT.md`
- **Issue:** `TabelaFaixasSection.jsx` tinha "Competência fechada — a tabela não pode ser alterada para este mês." como texto visível quando a competência está fechada — a mesma palavra proibida que as duas correções obrigatórias do plan-checker mandaram tirar de `FecharCompetenciaButton`, só que num terceiro lugar que o plan-checker não tinha visto.
- **Fix:** Reescrito para "Este mês está fechado — a tabela não pode ser alterada." — mesmo significado, sem o jargão.
- **Files modified:** `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx`
- **Verification:** `grep -n "Competência\|competência"` nos dois arquivos só encontra a palavra dentro de comentários de código (não renderizados) depois da correção; nenhum teste (`Phase137CompetenciaUiTest`, `Phase138FaixasGrupoCrudTest`) prendia o texto antigo.
- **Committed in:** `b000dd84` (Tarefa 2)

---

**Total deviations:** 1 auto-fixada (Rule 2 — funcionalidade/copy ausente, mesma regra do CONTEXT aplicada a um terceiro texto).
**Impact on plan:** Nenhum no escopo funcional pedido; extensão direta da mesma correção que o plano já exigia em outro componente.

## Issues Encountered

Verificação inicial do CSS compilado deu falso-negativo: buscas por `grep -F`/regex mal escapadas em bash/node não encontravam classes arbitrárias (`px-[18px]`, `tracking-[0.05em]`, `bg-ecf-yellow/10`, `md:grid-cols-3`) que na verdade estavam presentes — o problema era a forma de escapar `\[`, `\]`, `\/`, `\.` e `\:` no CSS minificado do Tailwind (que escapa TODOS esses caracteres, inclusive o ponto dentro do colchete), não um bug de geração de CSS. Confirmado com um script Node que monta os needles por código de caractere (sem escaping ambíguo de shell/regex): todas as classes usadas nesta tarefa compilaram para regras reais, nenhuma silenciosamente vazia.

## User Setup Required

None — nenhuma configuração externa. Sem deploy (fora do escopo e das travas da fase).

## Next Phase Readiness

- Área expandida da empresa agora responde às três perguntas do handoff (quanto faturou, em que faixa caiu, quanto vai cobrar), com a faixa atual destacada na tabela progressiva que já existia — nada foi duplicado.
- `TabelaFaixasSection` continua com os quatro ramos intactos (grupo com tabela própria, grupo herdando de empresa, empresa herdando de serviço, empresa com exceção própria, "A DEFINIR") — `Phase138FaixasGrupoCrudTest` (contrato de `tabela_herdada_de_nome`, rota `admin.financeiro.faixas.grupo` e ausência de "âncora") continua verde.
- Prop fantasma `cobranca_mensal_grupo`: confirmado `grep -c` → 0 no arquivo inteiro, tanto antes quanto depois desta plano — não foi reintroduzida.
- Gate `Phase122|Phase136|Phase137|Phase138|Phase139`: **302 testes / 1584 asserções / 0 falhas** — idêntico ao baseline medido antes deste plano (nenhuma regressão). `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela` não foi re-executado isoladamente nesta rodada (já documentado como flaky pré-existente em `.planning/todos/pending/260904-teste-flaky-aviso-mudanca-faixa.md`); não apareceu como falha na suíte completa desta execução.
- `npm run build` roda limpo; CSS compilado conferido (via script Node, não regex de shell) para todas as classes arbitrárias novas (`px-[18px]`, `tracking-[0.05em]`, `bg-emerald-500/[0.07]`, `border-emerald-400/30`, `bg-ecf-yellow/10`, `bg-amber-500/[0.06]`, `border-amber-500/20`) — todas geraram regra real.
- Fica para o plano 06 (se houver): qualquer teste de contrato adicional sobre a área expandida (ex.: `Phase139FechamentoUiContratoTest` citado nas correções obrigatórias do plan-checker) deve encontrar a prop fantasma já em 0 e a copy já sem "competência".

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*

## Self-Check: PASSED

Arquivos confirmados por reconsulta: `resources/js/Pages/Admin/Financeiro.jsx`, `resources/js/Pages/Admin/Financeiro/TabelaFaixasSection.jsx` e este SUMMARY. Os 2 commits de tarefa confirmados por `git log --oneline --all` (`0d595c48`, `b000dd84`). Gate completo rodado após a Tarefa 2: 302 testes / 1584 asserções / 0 falhas — idêntico ao baseline medido antes deste plano. `npm run build` verde, CSS compilado conferido.
