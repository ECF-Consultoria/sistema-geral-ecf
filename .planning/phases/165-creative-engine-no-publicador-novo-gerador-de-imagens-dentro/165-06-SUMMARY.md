---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 06
subsystem: ui
tags: [react, publicador, creative-engine, kit-criativos, inertia]

requires:
  - phase: 165-04
    provides: "MlbPublicadorCriativoController (atual/status/planejar/gerar/referencia/imagem/aprovar) + PublicadorCriativoKitPresenter (kit_id numérico, sem token, validacao_status/pode_aprovar/exige_confirmacao_risco por slot)"
  - phase: 165-05
    provides: "endpoints regenerar (criativos.slot.regenerar) e aprovar o kit inteiro (criativos.kit.aprovar)"
provides:
  - "useCriativosDoPublicador: hook + contexto CriativosDoPublicador (estado, polling com teto e limpeza, retomada por sessionStorage, painel por instância via reivindicar())"
  - "Mesa/PainelCriativos.jsx: apresentação pura do kit (escolher fotos → planejar → confirmar custo em dólar → grade de slots → regenerar/usar/usar o kit), na identidade visual do editor em 3 etapas"
affects: [165-07]

tech-stack:
  added: []
  patterns:
    - "Painel pertence a UMA instância do bloco de fotos (reivindicar()), não ao grupo — duas variações que dividem o mesmo grupo de fotos não disputam o mesmo painel"
    - "aprovar(indice, confirmarRisco) com confirmarRisco opcional: o gate de validação da Fase 162 (reprovada/pendente) é refletido na tela via validacao_status/pode_aprovar/exige_confirmacao_risco, nunca recalculado no front"

key-files:
  created:
    - resources/js/Components/Publicador/useCriativosDoPublicador.js
    - resources/js/Components/Publicador/Mesa/PainelCriativos.jsx
  modified:
    - tests/js/publicador-editor.test.js
    - tests/js/publicador-mesa.test.js

key-decisions:
  - "aprovar() do hook ganhou um 2º parâmetro opcional confirmarRisco (default false), enviando { confirmar_risco: true } no corpo quando true — não estava no 165-06-PLAN.md (anterior à Fase 162), mas o presenter da 165-04 já manda validacao_status/pode_aprovar/exige_confirmacao_risco por slot; sem isso o painel teria aprovado em silêncio (ou simplesmente falhado com 422 sem explicação) uma imagem que o juiz Gemini reprovou"
  - "O painel mostra a reprovada com o texto do servidor (validacao_mensagem/validacao_problemas) e só libera 'Usar no anúncio' quando pode_aprovar é true; com exige_confirmacao_risco, exige um segundo clique explícito ('Aprovar mesmo assim' → 'Sim, usar esta imagem') — réplica do padrão de dois passos do KitCriativosGrade.jsx antigo, só que LIDO como referência, nunca importado"
  - "fase 'parado' devolve null no painel — o componente não se desmonta enquanto 'escolhendo'/'kit' (disabled só desabilita ações), mas também não ocupa espaço quando c.fechar() foi chamado"

requirements-completed: [CE165-10, CE165-11]

duration: ~70min
completed: 2026-10-05
---

# Fase 165 Plano 06: Hook e painel nativo do kit de criativos no Publicador Summary

**`useCriativosDoPublicador` (estado + polling + retomada + contexto) e `Mesa/PainelCriativos.jsx` (apresentação pura: escolher fotos → planejar → confirmar custo em dólar → grade → regenerar/usar/usar o kit), reproduzindo a experiência do painel antigo numa tela nova, na identidade do editor em 3 etapas — com o gate de validação da Fase 162 (imagem reprovada nunca aparece como aprovável) incorporado, divergência não prevista no plano original de 04/10.**

Executado seguindo o desenho do ECF Dev (dono do Publicador) — ver aviso de coordenação em CLAUDE.md; a trava de não tocar em `Publicador/` não vale nesta fase por decisão do usuário.

## Performance

- **Duration:** ~70 min
- **Tasks:** 2/2
- **Files modified:** 4 (2 criados, 2 de teste modificados)

## Accomplishments

- `useCriativosDoPublicador.js`: hook completo com `abrir`/`reivindicar`/`fechar`/`planejar`/`gerar`/`recusarConfirmacao`/`mostrarConfirmacao`/`mudarMotivo`/`regenerar`/`aprovar`/`aprovarKit`/`novoKit`/`limparErro`. Rotas só aqui (`criarRota('mlb.anuncios.publicador', 'produto')`), kit endereçado só por `kit_id` numérico (D-13 — confirmado por gate `doesNotMatch(/kit_token|\btoken\b/)`), polling com teto de 27 min que para sozinho (kit terminado, 404, ou teto), retomada por sessionStorage guardando só `{grupo, titulo}` (nunca instância nem id de kit), e `CriativosDoPublicador` (contexto React) para o 165-07 ligar sem prop-drilling.
- `Mesa/PainelCriativos.jsx`: painel nativo com todos os estados do objective (carregando/escolhendo/planejando/planejado-com-confirmação/gerando/grade/footer/aprovado-ou-erro), confirmação de custo em dólar antes de gerar ("Agora não" só esconde), "Gerar de novo" só com kit aberto e referência viva (`kit.status !== 'aprovado' && kit.referencias.length > 0`) e regenerações restantes > 0, usar/pôr de novo/usar o kit inteiro, aviso de capacidade (avisa, não corta) e o texto fixo de que as imagens só entram no anúncio na publicação. Visual espelhado em `FotosPorGrupo.jsx`/`CartaoVariante.jsx` (bloco `rounded-lg border border-white/20 bg-black/40 p-4`, texto 13/15px, `LINK`/`BotaoAcao` secundário — nunca `primario`).
- Gates de tipografia/peso/vocabulário do editor em 3 etapas verdes para o painel (adicionado a `CARDS` em `publicador-mesa.test.js`); teste dedicado de fonte cobrindo custo, confirmação, motivo ≤ 300, regenerar/aprovar/pôr de novo/aprovar kit, aviso de capacidade e ausência de qualquer vestígio do assistente antigo.

## Divergência do plano (código manda) — gate de validação da Fase 162 no painel

O `165-06-PLAN.md` é de 04/10/2026, anterior ao merge da Fase 162 (validador Gemini-juiz). O presenter da 165-04 (`PublicadorCriativoKitPresenter::paraTela()`) já manda, por slot, `validacao_status` (`pendente`/`aprovada`/`reprovada`/`indisponivel`), `validacao_mensagem`, `validacao_problemas`, `pode_aprovar` e `exige_confirmacao_risco` — nenhum desses campos está na lista do contrato que o `165-06-PLAN.md` documenta nas `<interfaces>`.

Sem tratar isso, o painel teria dois problemas: (1) o botão "Usar no anúncio" apareceria para um slot `pronto` mesmo com `validacao_status: 'reprovada'`, e o clique só falharia com um 422 sem explicação visível — a instrução explícita do prompt de execução ("imagem reprovada não pode parecer aprovada") teria sido violada; (2) não haveria como aprovar mesmo assim uma imagem com risco assumido, porque o hook `aprovar(indice)` não mandava `confirmar_risco`.

**Fix:**
- `useCriativosDoPublicador.aprovar()` ganhou um 2º parâmetro opcional `confirmarRisco = false`; quando `true`, manda `{ confirmar_risco: true }` no corpo do POST — mesma disciplina do `MlbPublicadorCriativoController::aprovar()` (165-04 Task 3), que exige esse campo explicitamente para aprovar uma imagem `reprovada`.
- `Mesa/PainelCriativos.jsx` (função `CartaoSlot`): "Usar no anúncio" só aparece com `s.pode_aprovar`; com `s.exige_confirmacao_risco`, mostra primeiro "Aprovar mesmo assim" e, num segundo passo explícito, "Sim, usar esta imagem" → `c.aprovar(s.indice, true)`. Com `validacao_status === 'reprovada'`, o cartão mostra a mensagem e os problemas do servidor (nunca um motivo inventado no front — mesma disciplina de T-162-13). Com `'pendente'`, mostra "Validando automaticamente…" e não oferece nenhum botão de aprovar (o servidor também recusaria com 422).
- Arquivo afetado fora do `files_modified` original do plano: `resources/js/Components/Publicador/useCriativosDoPublicador.js` (committed junto da Task 2, pois o painel depende da nova assinatura).

Esta é a MESMA divergência já registrada em `165-04-SUMMARY.md` (controller/presenter) e em `165-05` (regenerar/aprovar) — aqui ela chega à tela.

## Task Commits

1. **Task 1: Hook useCriativosDoPublicador + contexto CriativosDoPublicador** — `4903f52b` (feat)
2. **Task 2: Painel nativo Mesa/PainelCriativos.jsx + gates de identidade do editor + extensão do hook (confirmarRisco)** — `d69bdcb2` (feat)

Nenhum commit de metadados (SUMMARY/STATE/ROADMAP não commitados, por instrução do prompt de execução).

## Files Created/Modified

- `resources/js/Components/Publicador/useCriativosDoPublicador.js` — hook (estado, polling, retomada, contexto); `aprovar(indice, confirmarRisco)` estendido na Task 2
- `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx` — painel nativo do kit (321 linhas)
- `tests/js/publicador-editor.test.js` — gate de fonte do hook
- `tests/js/publicador-mesa.test.js` — `PainelCriativos.jsx` em `CARDS` + teste dedicado

## Decisions Made

Ver seção "Divergência do plano" acima. Além disso:
- `fase` interna do hook usa 4 valores (`parado`/`carregando`/`escolhendo`/`kit`) exatamente como especificado; `kit` é o `Kit | null` do servidor, nunca recomputado no front.
- `podeRegenerarKit`/`podeUsarKit`/`temSlots` no painel são calculados só depois de confirmar `kit !== null` (guarda explícita), para poder usar o literal `kit.status !== 'aprovado'` exigido pelo gate de fonte sem optional chaining.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] Gate de validação da Fase 162 ausente no hook/painel do Publicador**
- **Found during:** Task 2 (lendo `KitCriativosGrade.jsx`/`PainelCriativosIa.jsx` por instrução do prompt de execução, e o presenter da 165-04)
- **Issue:** sem isso, uma imagem reprovada pelo juiz apareceria com o mesmo botão "Usar no anúncio" de uma imagem aprovada pela validação, e não haveria como confirmar o risco
- **Fix:** `aprovar(indice, confirmarRisco)` no hook + UI de dois passos no painel (`pode_aprovar`/`exige_confirmacao_risco`/`validacao_status`/`validacao_mensagem`/`validacao_problemas`)
- **Files modified:** `resources/js/Components/Publicador/useCriativosDoPublicador.js`, `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx`
- **Verification:** gates de fonte verdes (47 + 156 testes); leitura manual do presenter confirmando os 5 campos
- **Commit:** `d69bdcb2`

---

**Total deviations:** 1 auto-fixado (Rule 2 — funcionalidade crítica ausente)
**Impact on plan:** Sem este ajuste a tela nova seria estritamente menos segura que a seguinte onda exigiria; nenhum scope creep — só o mesmo gate, já provado no backend (165-04), refletido na apresentação.

## Issues Encountered

Nenhum bloqueio. Durante a execução, `routes/mlb_anuncios.php` e `app/Http/Controllers/MlbPublicadorCriativoController.php` mudaram em disco (sessão paralela do plano 165-05 adicionando `regenerar`/`aprovarKit`) — conferido que os nomes de rota (`criativos.slot.regenerar`, `criativos.kit.aprovar`) batem com o que este hook já chamava; nenhum arquivo dessa sessão foi commitado por este executor.

## Known Stubs

Nenhum. O painel e o hook estão prontos, mas **não estão montados em nenhuma página ainda** — isso é esperado: o 165-07 é quem liga `useCriativosDoPublicador`/`Mesa/PainelCriativos.jsx` ao `BlocoDeFotos` (`FotosPorGrupo.jsx`). Até lá, nada deste plano aparece na tela renderizada — confirmado que `npm run build` não falhou (o componente simplesmente não entra em nenhum bundle de página por ainda não ser importado).

## Threat Flags

Nenhuma superfície nova além do `threat_model` do próprio plano (T-165-31 a T-165-34, todos com disposição `mitigate`/`accept` já cobertos pela implementação: XSS via JSX puro sem `dangerouslySetInnerHTML`; sessionStorage só com `{grupo, titulo}`; confirmação de custo + botões desabilitados contra gasto duplicado).

## User Setup Required

Nenhum.

## Resultado dos testes (medido nesta execução)

| Suíte | Comando | Resultado |
|---|---|---|
| `tests/js/publicador-mesa.test.js` + `tests/js/publicador-editor.test.js` | `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` | **156 testes / 0 falhas** |
| `tests/js/**/*.test.js` (suíte JS inteira, baseline) | `npm run test:js` | **965 testes / 963 passam / 2 falhas pré-existentes** (`estrutura-grade-glide.test.js` e `polosEntrantes.test.js` — nada em `Publicador/`; mesmas falhas documentadas nos learnings do projeto, não são regressão desta execução) |
| Build de produção | `npm run build` | **✓ built in 1m 6s**, sem erros |
| Sintaxe isolada (esbuild) | `esbuild useCriativosDoPublicador.js` e `esbuild Mesa/PainelCriativos.jsx` (`--bundle --format=esm --loader:.js=jsx --jsx=automatic --packages=external --external:@/*`) | ambos saem 0 |

Confirmações pedidas pelo prompt de execução:
- **(a) nenhum token no front (D-13):** confirmado por gate `doesNotMatch(/kit_token|\btoken\b/)` no hook (fora de comentário) e por leitura manual — o kit é só `kit.kit_id` em toda a árvore.
- **(b) o polling para sozinho:** `useEffect` com `clearInterval(t)` no retorno, só roda enquanto `kit?.em_andamento`; para ao: kit deixar de estar em andamento, 404 (kit sumiu), ou passar de `LIMITE = 27 * 60 * 1000` (mesmo teto do kit no servidor).
- **(c) `AnunciarML.jsx` e os dois painéis antigos intocados:** `git status --short` e `git log --oneline bb1b61f8..HEAD -- resources/js/Pages/Mlb/components resources/js/Pages/Mlb/AnunciarML.jsx` confirmam que o único commit nesses arquivos desde `bb1b61f8` é `8abc6e9f` (Fase 162, anterior a esta sessão) — nada desta execução tocou neles. `PainelCriativosIa.jsx`/`KitCriativosGrade.jsx` foram só LIDOS (para aprender o fluxo), nunca importados pelo painel novo (confirmado pelo gate `doesNotMatch(/PainelCriativosIa|KitCriativosGrade/)`).
- **(d) espelho visual:** `FotosPorGrupo.jsx`/`CartaoVariante.jsx` (bloco de fotos: `rounded-lg border border-white/20 bg-black/40 p-4`, textos 13px/15px) e `Mesa/comum.jsx`/`Mesa/botoes.jsx` (`LINK`, `BotaoAcao` secundário — nunca `primario`, nenhum amarelo sólido).

## Next Phase Readiness

`useCriativosDoPublicador` e `Mesa/PainelCriativos.jsx` estão prontos para o 165-07 ligar ao `BlocoDeFotos`: o contexto `CriativosDoPublicador` evita prop-drilling, e o painel já cobre o estado "reprovada pelo validador" que o 165-06-PLAN.md (anterior à Fase 162) não previa. Falta só: montar o `<CriativosDoPublicador.Provider value={c}>` em algum ponto do editor, calcular `sugeridas`/`fotosNoGrupo` a partir das fotos do grupo, e o botão "Gerar com IA" que abre o painel dentro de `BlocoDeFotos`.

## Self-Check: PASSED

- `resources/js/Components/Publicador/useCriativosDoPublicador.js` — FOUND
- `resources/js/Components/Publicador/Mesa/PainelCriativos.jsx` — FOUND (321 linhas)
- Commit `4903f52b` — FOUND em `git log --oneline --all`
- Commit `d69bdcb2` — FOUND em `git log --oneline --all`
- `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js`: 156/156 verde
- `npm run test:js`: 965 testes, 963 passam, 2 falhas pré-existentes não relacionadas
- `npm run build`: sucesso

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
