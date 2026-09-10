---
phase: 139-redesenho-da-tela-de-fechamento
plan: 04
subsystem: ui
tags: [react, inertia, tailwind, fechamento, lista-empresas]

# Dependency graph
requires:
  - phase: 139-03
    provides: "estados filtroChip/empresaFocada elevados ao componente de página, com o handler focarEmpresaSubiuFaixa já ligado ao widget de upgrades"
provides:
  - "FiltroBarra em chips (Todas as empresas / Subiram de faixa / Sem integração / Maiores mensalidades) + busca de 280px, consumindo filtroChip/empresaFocada"
  - "CabecalhoColunas — grid de 5 colunas acima da lista, visível a partir de ~820px"
  - "FechamentoRow evoluída para grid de 4 colunas com barra de progresso na faixa, responsiva abaixo de ~820px"
  - "FaixaProgresso evoluída pro formato compacto de linha, reaproveitada na linha e na área expandida"
  - "FechamentoList com cards individuais (gap-2) e reabertura automática da linha via empresaFocada"
affects: [139-05, 139-06, 139-07]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "empresaFocada é sempre um objeto NOVO a cada clique ({ id }), nunca o id cru — useEffect reage por referência, então clicar duas vezes seguidas no mesmo atalho reabre a linha mesmo se ela tiver sido fechada manualmente entre um clique e outro"
    - "breakpoint arbitrário min-[820px]: usado (não um breakpoint padrão do Tailwind) para bater com a especificação do handoff (~820px é onde a linha da empresa empilha)"
    - "classes de grid-template-columns e breakpoints arbitrários precisam ser strings LITERAIS completas no JSX — concatenar um prefixo de variante ('min-[820px]:' + variavel) não é visto pelo scanner do Tailwind e gera CSS zero em silêncio (mesma armadilha do plano 03, categoria diferente)"

key-files:
  created: []
  modified:
    - resources/js/Pages/Admin/Financeiro.jsx

key-decisions:
  - "FaixaProgresso foi evoluída (não duplicada) para o formato compacto e passou a ser usada tanto na linha quanto na área expandida — o visual anterior da área expandida (ícone TrendingUp, barra mais grossa, %) não é protegido por nenhum teste; a reformulação da área expandida em si (os '3 passos') fica para o plano 05, mas a peça que ela reaproveita muda de aparência agora"
  - "'Total do grupo' na área expandida lia empresa.cobranca_mensal_grupo, a mesma prop fantasma da FechamentoRow — sempre renderizava vazio em produção. Corrigido para empresa.cobranca_mensal, que já é o total do grupo na linha-âncora (AdminController grava isso explicitamente ~linha 801). Bug pré-existente fora do arquivo indicado no plano, mas coberto pela verificação de todo o arquivo (grep cobranca_mensal_grupo → 0)"
  - "ServiceBadge perdeu a pintura ecf-yellow e passou a bg-white/[0.06] text-white/60 — segue o Color Contract do UI-SPEC (accent reservado a ação/status) e o handoff, que trata o nome do serviço como metadado neutro"
  - "AusenciaFaturamentoBadge perdeu mt-0.5/block — eram para quando ficava empilhado sob o nome da empresa; agora vive sozinho na própria célula do grid"

patterns-established:
  - "grid da linha de empresa: grid-cols-[minmax(0,1.5fr)_1fr_1.3fr_0.9fr_28px] gap-5, mesma template no cabeçalho e na linha, ativa só a partir de min-[820px]; abaixo disso a linha vira flex-col com um sub-grid 2 colunas pros três campos numéricos"

requirements-completed: [D-01, D-02, D-05]

# Metrics
duration: ~1h10min
completed: 2026-09-04
---

# Phase 139 Plan 04: Lista de empresas em 4 colunas, chips de filtro e estado vazio Summary

**`FechamentoRow` reescrita como grid de 4 colunas (empresa / faturamento do mês / faixa com barra de progresso / mensalidade) com cabeçalho de colunas, chips de filtro mutuamente exclusivos consumindo o estado elevado no plano 03, busca combinada, dois estados vazios distintos e a prop fantasma `cobranca_mensal_grupo` eliminada do arquivo inteiro — sem perder nenhum dos estados de ausência das Fases 137/138.**

## Performance

- **Duration:** ~1h10min
- **Completed:** 2026-09-04
- **Tasks:** 2/2
- **Files modified:** 1

## Accomplishments

- **Tarefa 1** — `FiltroBarra` reescrita no formato do handoff: quatro chips mutuamente exclusivos (`Todas as empresas` / `Subiram de faixa` / `Sem integração` / `Maiores mensalidades`, este último só reordena por `cobranca_mensal` desc) + busca de 280px à direita. `filtroChip`/`empresaFocada` (elevados sem consumidor no plano 03) agora são consumidos de verdade: `useMemo` de `filtradas` aplica busca → chip → serviço → pagamento → ordenação. `onFocarEmpresa` limpa a busca, ativa o chip "Subiram de faixa" e marca a empresa focada como **objeto novo a cada clique** (`{ id }`) — decisão deliberada para o clique duplo no mesmo atalho reabrir a linha em vez de ser ignorado por `Object.is` no `useState`. Select "Estado" removido (chips cobrem o que ele fazia); selects Serviço/Pagamento preservados, discretos, à esquerda da busca (D-05).
- **Tarefa 2** — `CabecalhoColunas` novo (grid de 5 colunas, visível a partir de ~820px, "Faturamento do mês" por extenso conforme trava de teste). `FechamentoRow` evoluída mantendo o nome: grid de 4 colunas no desktop, empilha em coluna única com sub-grid 2 colunas abaixo de ~820px. Tags neutras (`ServiceBadge` deixou de pintar de `ecf-yellow`), `↑ subiu de faixa` amarelo, `IntegrationBadge` âmbar reaproveitado, `Grupo · N`/`Vinculada · nome` preservados, `EvolucaoBadge` só para `desceu`/`manteve` (a subida virou a tag amarela). Coluna de faturamento nomeia os três estados de ausência sem nunca cair em `R$ 0` ou traço mudo. Coluna de mensalidade lê `cobranca_mensal` (nunca a chave fantasma). `FaixaProgresso` evoluída pro formato compacto de linha (nome da faixa + "falta R$X" / "faixa máxima · acima de R$X" / "—" + barra de 5px mínimo 3%, amarela quando subiu de faixa) — mesma função, reaproveitada também na área expandida. `FechamentoList` passou a renderizar cards individuais (`rounded-[14px]`, `gap-2`) em vez de uma lista única com `divide-y`, com `useEffect` que reabre a linha quando `empresaFocada` muda, e dois estados vazios distintos ("nenhuma empresa cadastrada" vs. "filtro sem resultado").
- Corrigido, fora do escopo nominal da `FechamentoRow` mas dentro da verificação do plano inteiro: o bloco "Total do grupo" na área expandida também lia `cobranca_mensal_grupo` (a mesma prop fantasma) e sempre renderizava vazio em produção — trocado por `cobranca_mensal`, que já é o total do grupo na linha-âncora (confirmado lendo `AdminController::fechamento()` ~linha 801).
- Duas armadilhas de auto-referência descobertas e corrigidas antes do commit: meus próprios comentários explicativos citavam literalmente as strings proibidas pelos testes de contrato (`"Fat. do mês"` e `cobranca_mensal_grupo`) — os testes fazem busca de texto crua no arquivo inteiro, sem distinguir comentário de copy renderizada. Reescritos sem a substring proibida, mantendo a explicação.

## Task Commits

Cada tarefa foi commitada atomicamente:

1. **Tarefa 1: Filtros em chips, busca e o foco vindo do widget de upgrades** - `3f8e708c` (feat)
2. **Tarefa 2: Cabeçalho de colunas, linha de empresa em 4 colunas e estado vazio** - `0493f7cf` (feat)

## Files Created/Modified

- `resources/js/Pages/Admin/Financeiro.jsx` — `FiltroBarra`, `CabecalhoColunas` (novo), `FechamentoRow`, `FaixaProgresso`, `FechamentoList`, `ServiceBadge`, `AusenciaFaturamentoBadge`, ajuste do "Total do grupo" na área expandida.

## Decisions Made

- **`FaixaProgresso` evolui (não duplica) e passa a valer para linha + área expandida.** O plano mandou "evoluir `FaixaProgresso` para o formato compacto de linha e usá-lo aqui, mantendo o nome" — como a função só tinha um ponto de uso (área expandida) antes deste plano, evoluir o corpo dela muda a aparência nos dois lugares que agora a chamam. Nenhum teste protege o visual antigo (ícone `TrendingUp` + `%` + "Falta R$X para a próxima faixa"), então não há regressão de contrato — só de aparência num trecho que o plano 05 vai reformular de qualquer forma (os "3 passos" da área expandida).
- **Correção do "Total do grupo" tratada como parte deste plano, não como item separado.** A instrução da prop fantasma no prompt cita especificamente "FechamentoRow (~linhas 760/763)", mas a `<verification>` de todo o plano exige `grep -c cobranca_mensal_grupo` → 0 no arquivo inteiro, e havia uma segunda ocorrência na área expandida. Corrigida com a mesma solução (ler `cobranca_mensal`), documentada aqui como Rule 1 (bug pré-existente, dado que a chave inexistente sempre fez essa linha renderizar vazia).
- **`ServiceBadge` perde a cor `ecf-yellow`.** Passa a `bg-white/[0.06] text-white/60`, seguindo o handoff e o Color Contract (accent reservado a ação/status, não a metadado de serviço contratado).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] "Total do grupo" na área expandida lia a mesma prop fantasma da FechamentoRow**
- **Found during:** Tarefa 2 (verificação de `grep -c cobranca_mensal_grupo` antes do commit)
- **Issue:** `FechamentoAccordion` também usava `empresa.cobranca_mensal_grupo` (chave que o backend nunca emitiu) para mostrar o total do grupo — `fmtValorFaixa(undefined, ...)` sempre retornava `null`, então o rótulo "Total do grupo" ficava sempre vazio em produção.
- **Fix:** Trocado por `empresa.cobranca_mensal`, confirmado no `AdminController::fechamento()` (~linha 801) que já grava o total do grupo nessa chave para a linha-âncora.
- **Files modified:** `resources/js/Pages/Admin/Financeiro.jsx`
- **Verification:** `grep -c cobranca_mensal_grupo` → 0 no arquivo inteiro; gate completo sem regressão.
- **Committed in:** `0493f7cf` (Tarefa 2)

**2. [Rule 1 - Bug] Comentários explicativos citavam literalmente as strings proibidas pelos testes de contrato**
- **Found during:** Tarefa 2 (rodada de verificação por grep antes do commit)
- **Issue:** Escrevi comentários JSX explicando por que `cobranca_mensal_grupo` não deve voltar e por que "Faturamento do mês" nunca deve virar "Fat. do mês" — mas os testes `Phase137CompetenciaUiTest`/verificação do plano fazem `file_get_contents` + busca de substring crua no arquivo inteiro, sem diferenciar comentário (que não renderiza) de copy real. Os próprios comentários teriam derrubado o gate.
- **Fix:** Reescritos para explicar o mesmo raciocínio sem conter a substring proibida por extenso.
- **Files modified:** `resources/js/Pages/Admin/Financeiro.jsx`
- **Verification:** `grep -c "Fat. do mês"` → 0, `grep -c cobranca_mensal_grupo` → 0.
- **Committed in:** `0493f7cf` (Tarefa 2)

---

**Total deviations:** 2 auto-fixadas (ambas Rule 1 — bugs descobertos durante a própria execução da tarefa, corrigidos antes do commit).
**Impact on plan:** Nenhum no escopo funcional pedido; ambas as correções são estritamente dentro do que a `<verification>` do plano já exigia.

## Issues Encountered

Nenhum além do já documentado acima. Duas rodadas de `phpunit --filter="Phase137|Phase138"` confirmaram que `Phase138AvisoMudancaFaixaTest::refazer_e_mudar_a_faixa_de_uma_empresa_gera_aviso_novo_so_sobre_ela` é **flaky pré-existente** (colisão de nome gerado por Faker entre empresas na suíte cheia — "Urias e Serrano" batendo como substring de outra asserção) — passa isolado, falha ocasionalmente na suíte completa, não relacionado a este plano (nenhum arquivo PHP foi tocado). Confirmado no gate final: 302/1584/0 falhas.

## User Setup Required

None — nenhuma configuração externa. Sem deploy (fora do escopo e das travas da fase).

## Next Phase Readiness

- A lista de empresas está em 4 colunas com cabeçalho, barra de progresso na faixa e chips funcionando; o atalho do widget "Subiram de faixa este mês" filtra e abre a linha da empresa (inclusive em cliques repetidos na mesma empresa).
- `RecebidoToggle` permanece definido no arquivo mas sem chamador — migra para a área expandida no plano 05, conforme o plano determinou.
- A área expandida (`FechamentoAccordion`, os "3 passos", `TabelaFaixasSection`) não foi tocada além da correção pontual do "Total do grupo" e da propagação de `subiuDeFaixa` para `FaixaProgresso` — segue no estado da Fase 138/137 para o plano 05 reformular.
- Gate `Phase122|Phase136|Phase137|Phase138|Phase139`: **302 testes / 1584 asserções / 0 falhas** — idêntico ao baseline medido antes deste plano (nenhuma regressão).
- `npm run build`/`npx vite build` roda limpo; CSS compilado conferido para as classes arbitrárias (`py-[18px]`, `h-[5px]`, `min-[820px]:grid-cols-[...]`, `min-[820px]:contents`, etc.) — todas geraram regra real, nenhuma silenciosamente vazia.
- `grep -c "Faturamento do mês"` = 5, `"A DEFINIR"` = 4, `"Sem faturamento neste mês"` = 1, `"cobranca_mensal_grupo"` = 0, `"acumulado"` (case-insensitive) = 0, `"Fat. do mês"` = 0.

---
*Phase: 139-redesenho-da-tela-de-fechamento*
*Completed: 2026-09-04*

## Self-Check: PASSED

`resources/js/Pages/Admin/Financeiro.jsx` conferido por reconsulta: `function FechamentoRow`, `function ProgressaoModal`, `function EvolucaoBadge`, `function CabecalhoColunas`, `CHIPS_FILTRO` presentes (linhas 667, 533, 155, 636, 1249 respectivamente). Os 2 commits de tarefa confirmados por `git log --oneline` (`3f8e708c`, `0493f7cf`). Gate completo rodado após a Tarefa 2: 302 testes / 1584 asserções / 0 falhas — idêntico ao baseline medido antes de qualquer mudança.
