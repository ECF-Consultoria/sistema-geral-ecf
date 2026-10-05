---
phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro
plan: 07
subsystem: ui
tags: [react, publicador, creative-engine, kit-criativos, inertia, laravel]

requires:
  - phase: 165-06
    provides: "useCriativosDoPublicador (hook + contexto CriativosDoPublicador) e Mesa/PainelCriativos.jsx (apresentação pura do kit)"
  - phase: 165-04
    provides: "endpoints criativos.* e PublicadorCriativoKitPresenter"
provides:
  - "Prop Inertia criativos_ia na página Mlb/Publicador/Editor (chave creative_engine_ativo && CreativePermissao::podeGerar, sem exigir Company)"
  - "BlocoDeFotos (FotosPorGrupo.jsx) ligado ao contexto: botão 'Gerar com IA' no cabeçalho e PainelCriativos montado abaixo do bloco certo"
  - "Editor.jsx cria o hook e envolve a página inteira com CriativosDoPublicador.Provider"
affects: []

tech-stack:
  added: []
  patterns:
    - "useId() + reivindicar(): quando duas variações dividem o mesmo grupo de fotos (ex.: Preto/P e Preto/M), cada BlocoDeFotos tem sua própria instância; só a instância que chamou abrir() (ou que reivindicou depois de um F5) mostra o painel"
    - "aberto não depende de disabled: o painel continua montado durante a releitura do rascunho (pub.recarregar() deixa m.disabled=true por um instante) — só PainelCriativos desabilita as próprias ações via a prop disabled"
    - "sugeridas cai em cascata: fotos do próprio bloco com arquivo guardado; se nenhuma, fotos da galeria geral (GERAL) com arquivo; limite 14"

key-files:
  created:
    - tests/Feature/Phase165/EditorCriativosIaPropTest.php
  modified:
    - app/Http/Controllers/MlbPublicadorEntradaController.php
    - resources/js/Components/Publicador/FotosPorGrupo.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - tests/js/publicador-mesa.test.js
    - tests/js/publicador-editor.test.js

key-decisions:
  - "Nenhuma divergência do plano nesta onda: o 165-07-PLAN.md já foi escrito depois da Fase 162 incorporada nas ondas 4-6 (presenter com validacao_status/pode_aprovar), e o hook/painel do 165-06 já expõem o contrato exato (abrir/reivindicar/fechar/alvo) que este plano pedia — Task 1 e Task 2 executadas à risca."
  - "CartaoVariante.jsx e Mesa/FotosEVariacoes.jsx não foram tocados, como o plano previu: o BlocoDeFotos lê o contexto da página sozinho (useContext(CriativosDoPublicador)), nulo fora do editor ou com a chave desligada."

requirements-completed: [CE165-10]

duration: ~50min
completed: 2026-10-05
---

# Fase 165 Plano 07: Ligar o kit à tela pelo bloco de fotos Summary

**O bloco de fotos de cada variação e "Fotos para todas as variações" ganham "Gerar com IA" (sem tocar nos dois arquivos que os usam), com o painel nativo do 165-06 abrindo abaixo do bloco certo — um por vez, mesmo quando duas variações dividem o mesmo grupo — e sobrevivendo à releitura do rascunho depois de usar uma imagem no anúncio.**

Executado seguindo o desenho do ECF Dev (dono do Publicador) — ver aviso de coordenação em CLAUDE.md; a trava de não tocar em `Publicador/` não vale nesta fase por decisão do usuário.

## Performance

- **Duration:** ~50 min
- **Tasks:** 2/2
- **Files modified:** 6 (1 criado, 5 modificados)

## Accomplishments

- `MlbPublicadorEntradaController::editor()` ganhou a prop `criativos_ia` (injeção de `CreativeEngineAtivo`/`CreativePermissao` no método, igual a `produtos()`), avaliando `$creativeAtivo->ativa() && $creativePermissao->podeGerar($request->user())` — sem exigir Company, diferente da ponte `criativos_ia.url` da tela de produtos (Fase 164), que exige. A ponte antiga ficou intacta.
- `FotosPorGrupo.jsx` (`BlocoDeFotos`): `useContext(CriativosDoPublicador)` lê o contexto da página; `useId()` identifica a instância; o botão "Gerar com IA" (`LINK`, nunca amarelo sólido) aparece no cabeçalho quando `disponivel`; clicar abre/fecha o painel daquele grupo+instância; um `useEffect` reivindica a instância depois de um F5 (quando `alvo.instancia === null`); `aberto` nunca depende de `disabled`, então o `PainelCriativos` continua montado durante a releitura do rascunho (só as ações dele ficam desabilitadas via a prop `disabled`). As "sugeridas" caem em cascata: fotos do próprio bloco com arquivo guardado, senão as da galeria geral, limitadas a 14.
- `Editor.jsx`: recebe `criativos_ia = false` como prop; cria `criativos = useCriativosDoPublicador({ produtoId, disponivel: criativos_ia === true, onAprovou: () => pub.recarregar() })`; envolve todo o conteúdo da página (dentro do `AppLayout`) com `<CriativosDoPublicador.Provider value={criativos}>`. Nenhuma mudança em `CartaoVariante.jsx` nem em `Mesa/FotosEVariacoes.jsx` — eles continuam chamando `<BlocoDeFotos grupo={grupo} …>` e `<BlocoDeFotos grupo={GERAL} …>` sem saber que o contexto existe.
- Gates de fonte novos: `tests/js/publicador-mesa.test.js` (BlocoDeFotos: contexto, `useId`, atributos `data-*`, ausência de `route(`/`bg-ecf-yellow`/`uppercase`, `aberto` sem `disabled`) e `tests/js/publicador-editor.test.js` (Editor: hook, `criativos_ia === true`, `onAprovou`, Provider).

## Deviations from Plan

Nenhuma. O `165-07-PLAN.md` já foi escrito depois de incorporar a divergência da Fase 162 nas ondas 4-6 — o hook e o painel do 165-06 já expunham exatamente o contrato (`abrir`, `reivindicar`, `fechar`, `alvo.{grupo,titulo,instancia}`, `disponivel`) que este plano pedia para ligar. As duas tasks foram executadas literalmente conforme escritas, sem necessidade de ajuste de Rule 1/2/3/4.

## Issues Encountered

Nenhum bloqueio. `git status --short` antes de começar a Task 2 confirmou que nenhuma outra sessão estava editando `FotosPorGrupo.jsx` ou `Editor.jsx` ao mesmo tempo.

## Task Commits

1. **Task 1: Flag `criativos_ia` na página do editor** — `a2c9f3f6` (feat)
2. **Task 2: `BlocoDeFotos` com "Gerar com IA" + Provider na página + gates + build** — `86d18cf4` (feat)

Nenhum commit de metadados (SUMMARY/STATE/ROADMAP não commitados, por instrução do prompt de execução).

## Files Created/Modified

- `app/Http/Controllers/MlbPublicadorEntradaController.php` — `editor()` ganhou a prop `criativos_ia`
- `tests/Feature/Phase165/EditorCriativosIaPropTest.php` — 4 testes (chave ligada/desligada, lista `creative_engine_usuarios`, loja de `MlbEmpresa` sem Company)
- `resources/js/Components/Publicador/FotosPorGrupo.jsx` — `BlocoDeFotos` ligado ao contexto, botão "Gerar com IA", `PainelCriativos` montado condicionalmente
- `resources/js/Pages/Mlb/Publicador/Editor.jsx` — cria o hook, `Provider` em volta da página
- `tests/js/publicador-mesa.test.js` — gate de `BlocoDeFotos`
- `tests/js/publicador-editor.test.js` — gate do `Editor.jsx`

## Decisions Made

Ver seção "Deviations" — não houve decisão fora do plano nesta onda.

## Known Stubs

Nenhum. A funcionalidade está ponta a ponta: servidor manda a flag, o bloco de fotos mostra o botão, o painel nativo do 165-06 (com o gate de validação da Fase 162 já incorporado) abre e funciona.

## Threat Flags

Nenhuma superfície nova além do `threat_model` do próprio plano (T-165-35 a T-165-38, todos `mitigate`/`accept` já cobertos): a flag só esconde o botão (o servidor confere tudo de novo em cada rota do 165-04/05); a prop `criativos_ia` é um booleano sem informação sensível; a escrita concorrente é resolvida por `pub.recarregar()` (salva o pendente, espera, relê) sob a trava do rascunho; `BlocoDeFotos` não introduz `dangerouslySetInnerHTML`.

## User Setup Required

Nenhum. A chave `creative_engine_ativo` já estava ligada em produção desde 03/10 (ver `165-CONTEXT.md` §specifics); a onda não precisa de nenhuma configuração nova.

## Resultado dos testes (medido nesta execução)

| Suíte | Comando | Resultado |
|---|---|---|
| `tests/Feature/Phase165/EditorCriativosIaPropTest.php` + `tests/Feature/Publicador/MlbPublicadorEntradaTest.php` | `phpunit` | **15 testes / 101 assertions / 0 falhas** |
| `tests/Feature/Phase165` + `tests/Unit/Phase165` | `phpunit` | **152 testes / 847 assertions / 1 incomplete (esperado) / 0 falhas** |
| `tests/Feature/Publicador` | `phpunit` | **560 testes / 3224 assertions / 0 falhas** |
| `tests/Unit/Publicador` | `phpunit` | **243 testes / 815 assertions / 0 falhas** (560+243 = **803**, igual ao baseline pedido) |
| `tests/js/publicador-mesa.test.js` + `tests/js/publicador-editor.test.js` | `node --test` | **158 testes / 0 falhas** (eram 156 em 165-06; +2 gates novos) |
| `tests/js/**/*.test.js` (suíte JS inteira) | `npm run test:js` | **967 testes / 965 passam / 2 falhas pré-existentes** (`estrutura-grade-glide.test.js` e `polosEntrantes.test.js` — nenhuma em `Publicador/`, as mesmas já documentadas antes desta sessão; não cresceu) |
| Build de produção | `npm run build` | **✓ built in 40.64s**, sem erros |

Confirmações pedidas pelo prompt de execução (com evidência):
- **(a) "Gerar com IA" no bloco de fotos geral e no de uma variação:** `CartaoVariante.jsx` (grupo da variação) e `Mesa/FotosEVariacoes.jsx::FotosParaTodas` (grupo `GERAL`) chamam `<BlocoDeFotos grupo={…}>` sem nenhuma mudança (`git show --stat 86d18cf4` não lista nenhum dos dois); o botão vive dentro do `BlocoDeFotos` compartilhado — todo lugar onde ele aparece ganha o botão quando `criativos.disponivel` é `true`. Confirmado por `grep -l "Gerar com IA" public/build/assets/*.js` → `Editor-BfKuUuZY.js` (o único bundle de página que importa `FotosPorGrupo.jsx` por este caminho).
- **(b) o painel abre abaixo do bloco certo, um por vez:** `aberto = visivel && criativos.alvo?.grupo === grupo && criativos.alvo.instancia === instancia` (instância por `useId()`) — gate `assert.match(f, /criativos\.alvo\.instancia === instancia/)` verde; `reivindicar()` (hook do 165-06) garante que só a primeira instância que montar reivindica o alvo guardado no sessionStorage depois de um F5.
- **(c) `AnunciarML.jsx` e os dois painéis antigos intocados:** `git log --oneline a2c9f3f6~1..HEAD -- resources/js/Pages/Mlb/components resources/js/Pages/Mlb/AnunciarML.jsx` não lista nenhum commit desta sessão.
- **(d) o build passou:** `npm run build` imprimiu `✓ built in 40.64s`; `grep -c "Pages/Mlb/Publicador/Editor.jsx" public/build/manifest.json` → `3`; `grep -l "data-painel-criativos" public/build/assets/*.js` → `Editor-BfKuUuZY.js`.

## Next Phase Readiness

A fatia ponta a ponta da Fase 165 está completa: flag no servidor → botão no bloco de fotos → painel nativo (165-06) → aprovação grava `pub_imagens` (165-02/03) → o bloco relê e mostra a foto. Não há mais planos pendentes conhecidos nesta fase (165-01 a 165-07 todos executados). Próximo passo natural, se o usuário quiser, é a verificação visual manual no navegador (não executada nesta sessão — fora do escopo de "executar o plano").

## Self-Check: PASSED

- `app/Http/Controllers/MlbPublicadorEntradaController.php` — FOUND, contém `'criativos_ia' =>` dentro de `editor(`
- `tests/Feature/Phase165/EditorCriativosIaPropTest.php` — FOUND
- `resources/js/Components/Publicador/FotosPorGrupo.jsx` — FOUND, contém `useContext(CriativosDoPublicador)`, `useId()`, `data-gerar-com-ia={grupo}`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx` — FOUND, contém `useCriativosDoPublicador({`, `<CriativosDoPublicador.Provider value={criativos}>`
- Commit `a2c9f3f6` — FOUND em `git log --oneline`
- Commit `86d18cf4` — FOUND em `git log --oneline`
- `node --test tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js`: 158/158 verde
- `npm run test:js`: 967 testes, 965 passam, 2 falhas pré-existentes (mesmas de antes desta sessão)
- `npm run build`: sucesso, `Editor-BfKuUuZY.js` contém "Gerar com IA" e `data-painel-criativos`

---
*Phase: 165-creative-engine-no-publicador-novo-gerador-de-imagens-dentro*
*Completed: 2026-10-05*
