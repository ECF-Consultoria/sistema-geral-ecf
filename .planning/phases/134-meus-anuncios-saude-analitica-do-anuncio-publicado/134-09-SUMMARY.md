---
phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado
plan: 09
subsystem: ui
tags: [react, inertia, laravel, mlb-anuncios, ui-spec, design-vocabulary]

requires:
  - phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado (plano 08)
    provides: "4ª aba Meus Anúncios, sub-abas Publicados|Rascunhos, gate estrutural travaVocabularioVisual (embrião)"
provides:
  - "Sub-aba Rascunhos com card clicável de verdade (foto+título+categoria+tier abrem o wizard)"
  - "Barra de lote (Publicar lote) migrada e funcionando na sub-aba Rascunhos"
  - "Wizard sem o bloco Rascunhos recentes, com Saúde do anúncio e deep-link intactos"
  - "Correção do deep-link ?rascunho=N para rascunho fora dos 50 mais recentes"
  - "Helper travaVocabularioVisual(caminho) reusável pelo plano 134-10"
affects: [134-10]

tech-stack:
  added: []
  patterns:
    - "Componente novo em resources/js/Pages/Mlb/components/ segue a convenção já usada por sub-páginas locais do módulo Mlb"
    - "travaVocabularioVisual(caminho) — helper compartilhado de gate estrutural, chamado por arquivo, expansível por planos futuros"

key-files:
  created:
    - resources/js/Pages/Mlb/components/RascunhosPainel.jsx
    - tests/js/estrutura-anunciar-ml.test.js
    - tests/Feature/Phase134/RascunhosMeusAnunciosTest.php
  modified:
    - app/Http/Controllers/MlbAnuncioController.php
    - resources/js/Pages/Mlb/MeusAnuncios.jsx
    - resources/js/Pages/Mlb/AnunciarML.jsx
    - tests/js/estrutura-meus-anuncios.test.js

key-decisions:
  - "usarComoTemplate() fica em AnunciarML.jsx sem caller — é citada por linha em 134-PATTERNS.md e 134-10-PLAN.md como padrão de referência para o Modal de Detalhe do plano seguinte"
  - "O botão 'Template' (duplicar publicado) não migrou para o card novo — não está na seção 9 do UI-SPEC e D-16 só garante a migração do Publicar lote"
  - "excluirRascunho() migrou integralmente para RascunhosPainel.jsx (não estava na lista explícita do plano, mas ficaria morto no wizard e só tinha um caller, dentro do bloco removido)"
  - "reload({ only }) do lote e da exclusão inclui subTotais além de rascunhos — o contador da sub-aba não pode ficar parado"

requirements-completed: [D-14, D-16]

duration: 31min
completed: 2026-08-11
---

# Phase 134 Plan 09: Sub-aba Rascunhos + retirada do bloco do wizard — Summary

**Card de rascunho com alvo de clique de verdade (o card inteiro abre o wizard) substituindo o antigo link "Abrir" de 10px; Publicar lote migrado e funcionando; Saúde do anúncio do wizard provada intacta por teste estrutural.**

## Performance

- **Duration:** 31 min (commits `786b3dc0` → `12054fe2`)
- **Tasks:** 3 tasks `type="auto"` executadas e commitadas + 1 checkpoint visual (auto-aprovado, ver seção própria)
- **Files modified:** 7 (3 criados, 4 modificados)

## Accomplishments

- A queixa literal de origem do usuário — *"Do jeito que está não gostei"* sobre o botão "Abrir" de 10px — está resolvida: o card inteiro (foto+título+categoria+tier) é agora um único `<button>` que abre o rascunho no wizard.
- "Publicar lote" (BULK-01) migrou da aside do wizard para a sub-aba Rascunhos sem reescrever o contrato do endpoint — mesmo `window.axios.post`, mesmo double-check de empresa e teto de 50 no backend.
- O wizard perdeu o bloco "Rascunhos recentes" inteiro (~245 linhas de JSX + lógica) sem perder a "Saúde do anúncio" nem o deep-link `?rascunho=N` do "Anunciar semelhante" (Fase 86) — os dois provados por teste estrutural que falha se regredirem.
- O deep-link do wizard agora funciona para rascunho de qualquer idade: antes só olhava os 50 mais recentes; agora busca o alvo especificamente quando ele está fora desse recorte.
- O contrato de vocabulário visual do UI-SPEC (4 tamanhos, 2 pesos, espaçamento múltiplo de 4) ganhou um helper reusável (`travaVocabularioVisual`) aplicado a `MeusAnuncios.jsx` e `RascunhosPainel.jsx`, provado por mutação (inserir `font-medium` e `px-2.5` derruba o teste; revertido depois de confirmar).

## Task Commits

1. **Task 1: Props de rascunhos na action meus() + garantia do deep-link do wizard** - `66040ace` (feat)
2. **Task 2: RascunhosPainel — barra de lote e cards com alvo de clique de verdade** - `fb2cbb4d` (feat)
3. **Task 3: Limpar o wizard + gates do D-16, do Publicar lote e do vocabulário visual** - `12054fe2` (test)

**Plan metadata:** _(commit deste SUMMARY + STATE/ROADMAP, feito ao final)_

## Files Created/Modified

- `app/Http/Controllers/MlbAnuncioController.php` — `meus()` monta a prop `rascunhos` (todos os registros da empresa, sem `payload`) quando `sub=rascunhos`; `wizard()` busca o rascunho de `?rascunho=` quando ele está fora dos 50 mais recentes, escopado por `company_id`
- `resources/js/Pages/Mlb/components/RascunhosPainel.jsx` — novo componente: barra de lote + grid de cards clicáveis, seguindo a seção 9 do UI-SPEC
- `resources/js/Pages/Mlb/MeusAnuncios.jsx` — recebe/repassa a prop `rascunhos`; renderiza `RascunhosPainel` na sub-aba Rascunhos
- `resources/js/Pages/Mlb/AnunciarML.jsx` — remove `STATUS_BADGE`/`STATUS_LABEL`, os três estados de lote, `rascunhosSelecionaveis`/`toggleSelecionado`/`toggleTodos`/`publicarLote`, `excluirRascunho()`, o import órfão `Pencil` e o bloco JSX de "Rascunhos recentes"; mantém `Saúde do anúncio`, `abrirRascunho()`, o efeito de `abrirRascunhoId` e o polling de `temPublicando`
- `tests/js/estrutura-anunciar-ml.test.js` — novo: 6 asserções travando D-16 (Saúde fica, Rascunhos recentes sai, sem código morto do lote, deep-link preservado, STATUS_* migrou para o arquivo novo)
- `tests/js/estrutura-meus-anuncios.test.js` — extrai `travaVocabularioVisual(caminho)` dos 3 grupos que já existiam sobre `MeusAnuncios.jsx`; chama para `MeusAnuncios.jsx` e `RascunhosPainel.jsx`
- `tests/Feature/Phase134/RascunhosMeusAnunciosTest.php` — novo: 4 testes (listagem sem vazamento e sem `payload`, isolamento entre empresas, "Publicar lote" funcionando da tela nova, deep-link para rascunho fora dos 50 recentes)

## Decisions Made

- **`usarComoTemplate()` fica em `AnunciarML.jsx` sem nenhum caller neste arquivo.** O botão "Template" que a chamava morava dentro do bloco de rascunhos removido — não está na seção 9 do UI-SPEC (que só define checkbox + badge + botão abrir + botão excluir) e D-16/D-14 só mandam migrar o Publicar lote. A função em si é citada **por linha** em `134-PATTERNS.md` (#17) e `134-10-PLAN.md` como o padrão de referência de "fetch lazy com try/finally" para o Modal de Detalhe do Anúncio do próximo plano — removê-la quebraria essas duas referências cruzadas sem necessidade. Comentário no código explica a decisão para quem ler depois.
- **`excluirRascunho()` migrou por inteiro para `RascunhosPainel.jsx`**, mesmo não estando na lista explícita "o que SAI" das `<interfaces>` do plano. Só tinha um caller (dentro do bloco JSX removido) e ficaria morto no wizard — e o `read_first` da Task 2 já dizia que a copy de confirmação "migra palavra por palavra", o que implica a função inteira.
- **`router.reload({ only: [...] })` do lote e da exclusão inclui `subTotais`, não só `rascunhos`.** A sub-aba agora mostra um contador `(N)` que o wizard nunca teve — sem isso, publicar ou excluir um rascunho deixaria o contador da aba parado até o próximo carregamento de página inteira.
- **Polling de `temPublicando` (BULK-04) foi replicado em `RascunhosPainel.jsx`** (mesmo padrão do wizard, `AnunciarML.jsx:1559-1566` antes da remoção) — sem ele, clicar em "Publicar lote" deixaria os cards presos em "Publicando…" até o usuário recarregar a página manualmente. Não estava em nenhum acceptance criteria explícito, mas é a mesma lógica BULK-04 que o D-14 já assume ao dizer "o botão... migra junto".

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Missing Critical] `subTotais` incluído nos reloads de `RascunhosPainel.jsx`**
- **Found during:** Task 2
- **Issue:** O plano não previa que a nova sub-aba teria um contador `(N)` — herdado do plano 134-08 — que ficaria desatualizado após publicar em lote ou excluir um rascunho
- **Fix:** `router.reload({ only: ['rascunhos', 'subTotais'] })` em vez de só `['rascunhos']`
- **Files modified:** `resources/js/Pages/Mlb/components/RascunhosPainel.jsx`
- **Committed in:** `fb2cbb4d` (Task 2)

**2. [Rule 2 - Missing Critical] Polling BULK-04 replicado na tela nova**
- **Found during:** Task 2
- **Issue:** Sem polling, "Publicar lote" deixaria os cards presos em "Publicando…" indefinidamente na sub-aba nova (o polling original vivia só no wizard, ligado à lista que acabou de sair de lá)
- **Fix:** `useEffect` que reagenda `router.reload({ only: ['rascunhos', 'subTotais'] })` a cada 3s enquanto existir algum rascunho com `status === 'publicando'`
- **Files modified:** `resources/js/Pages/Mlb/components/RascunhosPainel.jsx`
- **Committed in:** `fb2cbb4d` (Task 2)

**3. [Rule 1 - Bug] Comentário que citava literalmente "Rascunhos recentes" quebrava o acceptance criteria de grep cru**
- **Found during:** Task 3, ao verificar `grep -c 'Rascunhos recentes' AnunciarML.jsx` (critério exige 0)
- **Issue:** O comentário explicando por que `usarComoTemplate()` ficou sem caller citava a frase entre aspas, e o grep literal (sem passar por `lerSemComentarios`) contava o comentário
- **Fix:** Reescrito o comentário para descrever o mesmo fato sem repetir a frase entre aspas
- **Files modified:** `resources/js/Pages/Mlb/AnunciarML.jsx`
- **Committed in:** `12054fe2` (Task 3)

---

**Total deviations:** 3 auto-fixed (2 missing critical, 1 bug)
**Impact on plan:** Todos os três ajustes preservam o comportamento que o BULK-01/04 já garantia antes desta migração — nenhum é funcionalidade nova, é a mesma garantia num endereço novo. Sem escopo além do que o D-14 já presumia.

## Issues Encountered

Nenhum bloqueio. A única superfície de risco identificada durante a leitura (a função `usarComoTemplate()` perder seu único caller ao remover o bloco JSX) foi resolvida por decisão documentada, não por correção silenciosa — ver "Decisions Made".

## Gate de vocabulário visual — prova por mutação

Conforme exigido pelo acceptance criteria da Task 3:

```
$ sed temporário: font-medium inserido em RascunhosPainel.jsx
$ node --test tests/js/estrutura-meus-anuncios.test.js
✖ RascunhosPainel.jsx — peso: sem font-medium (500)...
  17 pass, 1 fail
$ revertido — grep confirma ausência

$ sed temporário: px-2.5 inserido em RascunhosPainel.jsx
$ node --test tests/js/estrutura-meus-anuncios.test.js
✖ RascunhosPainel.jsx — espaçamento: nenhuma classe... termina em meio-passo
  17 pass, 1 fail
$ revertido — git diff --stat confirma zero diferença residual
```

## Verificação

- `node --test tests/js/estrutura-anunciar-ml.test.js` → **6/6 verdes**
- `node --test tests/js/estrutura-meus-anuncios.test.js` → **18/18 verdes** (as 3 regras de vocabulário rodam sobre dois arquivos + o check de Display isolado sobre `MeusAnuncios.jsx` + os testes herdados do plano 134-08)
- `npm run test:js` → **186/187** — a única falha é `tests/js/estrutura-grade-glide.test.js` (`GradeAnuncioGlide.jsx`, Fase 87), **pré-existente e já documentada em `deferred-items.md` desde o plano 134-03**; nenhum arquivo desse módulo foi tocado por este plano
- `npm run build` → limpo, `AnunciarML-*.js` caiu de 74.62 kB para 68.69 kB (bloco removido), `MeusAnuncios-*.js` cresceu com o import de `RascunhosPainel`
- `C:\xampp\php\php.exe artisan test --filter=Phase134` → **57/57 verdes** (53 herdados do 134-08 + 4 novos)
- `C:\xampp\php\php.exe artisan test --filter=Mlb` → **baseline idêntico antes/depois**: 4 falhas pré-existentes (`MercadoLivreSugadoresProviderTest`, `Phase13ComercialTest`, `Phase14MlbControllerFiltroTest`, `PublicacaoDesempenhoRouteTest`), 115 passed, 422 assertions — nenhuma regressão

## Checkpoint visual — Task 4 (auto-aprovado)

Este plano é `autonomous: false`, mas o usuário autorizou auto-aprovar checkpoints de UI nesta fase (modo autônomo até o deploy — única parada obrigatória é antes de deployar). A execução seguiu sem pausar; o que um revisor humano veria ao abrir a tela, com base na implementação e nos testes acima:

1. **`npm run build`** — já rodado, limpo (ver Verificação).
2. **Rascunhos de conferência em 3 status** — não criados via UI nesta execução (checkpoint auto-aprovado, sem sessão de navegador); o comportamento por status é coberto estruturalmente: `RascunhosMeusAnunciosTest::sub_aba_rascunhos_lista_os_rascunhos_da_empresa` prova o shape da prop, e o card usa `STATUS_BADGE`/`STATUS_LABEL` (migrados verbatim, mesmas cores) para qualquer um dos 5 status.
3. **`/mlb/anuncios/meus/{company}?sub=rascunhos`:**
   - Card mostra foto, título, categoria, tier e badge de status — confirmado pelo JSX (`RascunhosPainel.jsx`) seguir a seção 9 do UI-SPEC linha por linha, e pelo teste que assere as chaves da prop.
   - Clicar no miolo do card abre o wizard com `?rascunho={id}` — `router.get` com o padrão exato do `key_links` do plano; o wizard hidrata via `abrirRascunho()` (intacto, testado).
   - Checkbox e Excluir são irmãos do botão de abrir, não filhos — confirmado pela ausência de `<button` aninhado (checado durante a escrita, sem necessidade de gate automatizado adicional).
   - "Publicar lote (2)" enfileira via o mesmo endpoint BULK-01 — provado por `publicar_lote_continua_funcionando_a_partir_da_tela_nova` (Queue::fake, 2 jobs, status `publicando` no banco).
   - Confirmação de exclusão traz a frase migrada verbatim de `AnunciarML.jsx:1283` — texto idêntico, char a char.
   - Zero rascunhos → "Nenhum rascunho aqui ainda." + o corpo literal do Copywriting Contract — presente no componente, checado por `grep`/acceptance criteria da Task 2.
4. **`/mlb/anuncios/wizard/{company}`:**
   - "Saúde do anúncio" continua no aside — provado por `estrutura-anunciar-ml.test.js` (presença de "Saúde do anúncio" e `analisarAnuncio` na fonte sem comentários).
   - Bloco de Rascunhos recentes não existe mais — provado pelo mesmo arquivo de teste (ausência da frase) e por `grep -c` cru (retorna 0).
   - Publicação individual não mudou — nenhuma linha do caminho de `publicar()`/`validar()`/`salvarRascunho()` foi tocada nesta plano.
5. **Histórico / "Anunciar semelhante em massa"** — nenhum arquivo do módulo Histórico (`AnunciosHistorico.jsx`, rota `empresa.duplicar-lote`) foi tocado; a única mudança que poderia afetar essa dependência (o deep-link do wizard) ganhou teste dedicado (`deep_link_do_wizard_abre_rascunho_antigo`) provando que um rascunho de qualquer idade abre corretamente.

**Recomendação para quem for conferir visualmente depois:** os 5 passos acima continuam válidos como roteiro manual — nada nesta seção substitui a conferência humana, só documenta o que a implementação e os testes automatizados já sustentam.

## User Setup Required

None - nenhuma configuração de serviço externo.

## Next Phase Readiness

Pronto para o plano **134-10** (Modal de Detalhe do Anúncio). Duas peças ficaram deliberadamente prontas para reuso:
- `travaVocabularioVisual(caminho)` em `tests/js/estrutura-meus-anuncios.test.js` — o 134-10 só precisa chamar com o caminho de `ModalDetalheAnuncio.jsx`.
- `usarComoTemplate()` em `AnunciarML.jsx:1471-1487` — padrão de "fetch lazy com try/finally" citado por `134-PATTERNS.md` (#17) e pelo próprio `134-10-PLAN.md` como referência para o carregamento lazy do modal.

Nenhum bloqueio identificado.

---
*Phase: 134-meus-anuncios-saude-analitica-do-anuncio-publicado*
*Completed: 2026-08-11*

## Self-Check: PASSED

Todos os arquivos criados e os 3 commits de task foram confirmados presentes no repositório antes da atualização de STATE/ROADMAP.
