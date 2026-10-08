---
phase: 170-identidade-visual-por-conta-de-marketplace-logo-opcional
plan: 02
subsystem: ui
tags: [laravel, react, inertia, publicador, creative-engine, render-test]

requires:
  - phase: 170-identidade-visual-por-conta-de-marketplace-logo-opcional (plano 01)
    provides: "CreativeIdentidade::paraAncora()/CreativeIdentidadeService — o motor que já lê a identidade no prompt"
provides:
  - "MlbPublicadorIdentidadeController::mostrar()/salvar() — leitura/gravação do texto de identidade da CONTA, endereçado por {produto}"
  - "Rotas mlb.anuncios.publicador.identidade.{mostrar,salvar} dentro do grupo role:admin existente"
  - "IdentidadeDaConta.jsx (CampoIdentidade + hook useIdentidadeDaConta) — campo único de texto livre, montado uma vez no topo da etapa Imagens"
affects: []

tech-stack:
  added: []
  patterns:
    - "Componente de apresentação pura (CampoIdentidade) separado do hook que chama a rota, dentro do mesmo arquivo — permite teste de render com JSON controlado sem mockar axios/efeitos"
    - "textoSeguro() como porta de entrada única para qualquer texto vindo do servidor que caia em atributo controlado de um campo (mesma defesa de PainelCriativos.jsx, REND-02)"

key-files:
  created:
    - app/Http/Controllers/MlbPublicadorIdentidadeController.php
    - resources/js/Components/Publicador/useIdentidadeDaConta.js
    - resources/js/Components/Publicador/Mesa/IdentidadeDaConta.jsx
    - tests/Feature/Phase170/MlbPublicadorIdentidadeControllerTest.php
    - tests/js/publicador-identidade-render.test.js
  modified:
    - routes/mlb_anuncios.php
    - resources/js/Components/Publicador/Mesa/EtapaImagens.jsx
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - tests/js/publicador-editor.test.js

key-decisions:
  - "CampoIdentidade (presentational, named export) separado do default IdentidadeDaConta (que chama o hook) — não estava explicitado no plano, mas foi necessário para permitir o teste de render com os 3 formatos de `texto` exigidos pelo REND-01/02 sem mockar axios/efeitos (ver Deviations)"
  - "Nenhum CreativePermissao::exigir() no controller — a classe é só para ações de custo de IA (planejar/gerar/regenerar/aprovar); salvar texto não gasta cota, e o PLAN.md não a menciona. Isolamento fica só no throttle nomeado (30,1/60,1), igual ao especificado"

requirements-completed: [IDENT-01, IDENT-04]

duration: ~70min
completed: 2026-10-07
---

# Phase 170 Plano 02: O cadastro da identidade visual na tela Summary

**Endpoint `MlbPublicadorIdentidadeController` + campo único de texto livre `IdentidadeDaConta.jsx` no topo da etapa Imagens — identidade persistida pela CONTA (company_id/mlb_empresa_id), endereçada por produto só para resolver qual conta.**

## Performance

- **Duration:** ~70 min
- **Tasks:** 2 de 3 (`auto` + `auto tdd="true"`) executados pelo subagente; Task 3 (`checkpoint:human-verify`, `gate="blocking"`) é do usuário — ver seção dedicada abaixo, NÃO conduzida por mim.
- **Files modified:** 9 (3 criados de produção, 2 testes novos, 4 modificados)

## Accomplishments

- `MlbPublicadorIdentidadeController::mostrar()/salvar()` — lê/grava só o texto da identidade da CONTA do produto autorizado, mesma disciplina de `rascunhoAutorizado()` (chave ligada + produto autorizado), sem buscar `PubRascunho`
- Rotas `mlb.anuncios.publicador.identidade.{mostrar,salvar}` dentro do grupo `role:admin` já existente, com throttle nomeado (60,1 leitura / 30,1 escrita)
- 9 testes PHP cobrindo: sem identidade → `null`; PUT→GET no mesmo produto; PUT num produto → GET em OUTRO produto da MESMA conta (prova da âncora por conta); isolamento entre contas diferentes (nunca cruza); produto inexistente → 404; chave desligada → 404; `role` não-admin → 403; texto vazio/null → grava `null`; texto > 4000 → 422
- `IdentidadeDaConta.jsx` montado UMA VEZ no topo de `EtapaImagens.jsx`, acima de "Fotos e variações" — um `<textarea>` só, sem seletor de cor, sem dropdown, sem logo
- `useIdentidadeDaConta.js` — hook com `{texto, carregando, salvando, erro, salvar, limparErro}`, GET ao montar/trocar de produto, PUT ao salvar
- 9 testes JS de render real (esbuild + `react-dom/server`) cobrindo os formatos de `texto` que o servidor pode mandar: string, `null`, objeto, array, número, booleano — nenhum lança, nenhum aparece cru na tela (REND-01/02)
- `npm run build` concluído sem erro; `Editor.jsx` confirmado no manifest do Vite

## Task Commits

1. **Task 1: Endpoints de leitura/gravação da identidade por conta** - `cb3fac7b` (feat)
2. **Task 2: Campo único na etapa Imagens, com teste de render real** - `5c6b73b5` (feat)

Nota TDD (Task 2, `tdd="true"`): segui RED→GREEN como commit único (mesmo padrão da 170-01 Task 2 e das Fases 168/169 para blocos desta natureza) — rodei `tests/js/publicador-identidade-render.test.js` ANTES de criar `useIdentidadeDaConta.js`/`IdentidadeDaConta.jsx` e confirmei falha por `Could not resolve` (RED real, não só teoria), só então implementei. **Gate de conformidade TDD:** não há commit `test(...)` isolado antes do `feat(...)` — mesma transparência documentada na 170-01.

**Plan metadata:** a ser commitado pelo orquestrador (SUMMARY/STATE/ROADMAP fora do escopo deste subagente, conforme instrução).

## Files Created/Modified

- `app/Http/Controllers/MlbPublicadorIdentidadeController.php` - `produtoAutorizado()`, `mostrar()`, `salvar()`
- `routes/mlb_anuncios.php` - grupo `identidade` irmão de `criativos`, dentro de `publicador/produtos/{produto}`
- `resources/js/Components/Publicador/useIdentidadeDaConta.js` - hook: GET ao montar/trocar produto, PUT ao salvar
- `resources/js/Components/Publicador/Mesa/IdentidadeDaConta.jsx` - `CampoIdentidade` (apresentação pura, named export) + `IdentidadeDaConta` (default, chama o hook)
- `resources/js/Components/Publicador/Mesa/EtapaImagens.jsx` - ganhou prop `produtoId`; monta `<IdentidadeDaConta produtoId={produtoId} />` antes de `<FotosEVariacoes m={m} />`
- `resources/js/Pages/Mlb/Publicador/Editor.jsx` - 1 linha: `<EtapaImagens m={m} produtoId={produto.id} />`
- `tests/Feature/Phase170/MlbPublicadorIdentidadeControllerTest.php` - 9 testes
- `tests/js/publicador-identidade-render.test.js` - 9 testes (render real)
- `tests/js/publicador-editor.test.js` - 1 assert ajustado (ver Deviations)

## Decisions Made

- `CampoIdentidade` como named export separado do default `IdentidadeDaConta` — decisão de implementação não detalhada no PLAN.md, necessária para cumprir o REND-01/02 do `<behavior>` (3 formatos de `texto` renderizados com props controladas, sem precisar mockar axios/efeitos de `useEffect`, que `react-dom/server` nunca executa). O `default export` continua sendo `IdentidadeDaConta({ produtoId })` como o artifact exige.
- Nenhum uso de `CreativePermissao::exigir()` no controller: essa classe é dedicada a ações que gastam cota de IA (planejar/gerar/regenerar/aprovar); salvar um texto não gasta cota e o PLAN.md (fonte de verdade desta execução) não a menciona para este controller — só throttle nomeado, como escrito.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 3 - Blocking] `tests/js/publicador-editor.test.js` tinha um gate de fonte com o padrão ANTIGO de `<EtapaImagens m={m} />`**
- **Found during:** Task 2, ao rodar o verify (`publicador-mesa.test.js`/`publicador-editor.test.js`)
- **Issue:** Um `assert.match` pré-existente exigia a string exata `<EtapaImagens m={m} />` na fonte de `Editor.jsx` — a MUDANÇA DE 1 LINHA que o próprio PLAN.md determina (acrescentar `produtoId={produto.id}`) quebra esse regex literal.
- **Fix:** Atualizei o regex para `<EtapaImagens m={m} produtoId={produto.id} />`, com comentário citando a Fase 170/170-02. Nenhuma outra asserção do arquivo foi tocada.
- **Files modified:** tests/js/publicador-editor.test.js
- **Verification:** `node --test tests/js/publicador-identidade-render.test.js tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` → 186/186 verde
- **Committed in:** `5c6b73b5` (mesmo commit da Task 2)

---

**Total deviations:** 1 auto-fixed (Rule 3 - blocking)
**Impact on plan:** Necessário para não quebrar a baseline; mudança mecânica de 1 regex, nenhuma lógica de teste alterada. Nenhum scope creep.

### Discrepâncias nos greps literais do `<acceptance_criteria>` (documentadas, não corrigidas)

- `grep -n "logo" app/Http/Controllers/MlbPublicadorIdentidadeController.php` e o mesmo grep em `IdentidadeDaConta.jsx` **encontram 1 linha cada** — mas é o PRÓPRIO comentário de docblock dizendo "Sem nenhum endpoint de logo (D3 fora do planejamento desta fase)" / "Sem logo (D3 fora desta fase)". Não há nenhum endpoint, upload, campo ou lógica de logo em nenhum dos dois arquivos — confirmei lendo o arquivo inteiro. O grep literal do plano não previa que a PRÓPRIA negação textual do logo bateria no padrão.

## Issues Encountered

Nenhum bloqueio. `mysqld` confirmado ativo antes dos testes PHP (convenção do ambiente). Build e as três suítes (PHP Phase170, JS Publicador, baseline ampliada) rodaram sem necessidade de retry.

## Resultado das suítes (rodado de verdade, não só o comando do plano)

- `tests/Feature/Phase170/MlbPublicadorIdentidadeControllerTest.php` → **9/9 OK**
- `node --test tests/js/publicador-identidade-render.test.js tests/js/publicador-mesa.test.js tests/js/publicador-editor.test.js` → **186/186 OK**
- Baseline `tests/Feature/Publicador` + `tests/Unit/Publicador` (inviolável) → **819/819 OK**, sem regressão
- Baseline ampliada do Creative Engine (Phase160/161/162/165/169/170 + Quick261003L8o/261005/261006/261006J44/261007/261007Amb/261007Rmv, Feature+Unit) → **481/481 OK**, sem regressão (nenhum dos 2 testes flaky pré-existentes documentados na 170-01 — `CriativoRetencaoTest`/`ColocarFotoNoGrupoSobTravaTest` — apareceu nesta rodada)
- `node --test "tests/js/**/*.test.js"` (suíte JS completa) → **1288/1290 OK**; as 2 falhas são EXATAMENTE as pré-existentes e documentadas (`estrutura-grade-glide.test.js` — `RECOLHIDOS_INICIAIS`; `polosEntrantes.test.js` — `FASES_TERMINAIS`), confirmadas não relacionadas a este plano (nenhum dos dois arquivos toca Publicador/Creative Engine/identidade)
- `npm run build` → **sucesso** (51.99s); `public/build/manifest.json` confirma `resources/js/Pages/Mlb/Publicador/Editor.jsx` mapeado

## Texto exibido na tela (para o usuário revisar/ajustar)

**Título:** `Identidade visual da conta`

**Texto de ajuda (abaixo do título, 11px):**
> Cadastrada uma vez por conta — vale para todos os produtos dela, não só este. Opcional: sem isto, a geração de imagens continua normal. Pode ser só um texto, por exemplo as cores de preferência em hexadecimal e a fonte usada pela empresa.

**Placeholder do campo (textarea vazio):**
> Ex.: cor principal #0A2342, cor secundária #FFC107, fonte Montserrat, acabamento fosco.

**Botão:** `Salvar` (vira `Salvando…` enquanto a chamada está em voo, desabilitado nesse estado)

**Mensagem de erro (quando a leitura/gravação falha):** vem de `mensagemDe(e)` (`resources/js/Components/Publicador/apoio.js`) — mesma função usada em todo o Publicador; tipicamente "Não foi possível concluir. Tente de novo." ou a mensagem específica do servidor.

## User Setup Required

None - nenhuma configuração de serviço externo necessária. A migration da 170-01 (`creative_identidades_conta`) já foi aplicada no ambiente de dev local nesta sessão anterior; precisa ser aplicada em qualquer outro ambiente (`php artisan migrate`) antes do uso em produção.

## Próximo passo — Task 3 (checkpoint do usuário, NÃO conduzido por mim)

Este plano tem uma Task 3 (`checkpoint:human-verify`, `gate="blocking"`) que é, por instrução explícita, **do usuário com o orquestrador — não minha**. Eu NÃO a conduzi e NÃO registro aqui nenhuma aprovação. O que falta, para quem for rodar com o usuário:

1. Abrir `/mlb/anuncios/publicador/produtos/{produto}/editor` de um produto qualquer → etapa **Imagens**.
2. Confirmar que o cartão "Identidade visual da conta" aparece ACIMA de "Fotos e variações", uma única vez, mesmo com várias variações/grupos de fotos — e que é um campo de texto único (sem seletor de cor/dropdown).
3. Digitar um texto (ex.: o do placeholder) e clicar "Salvar"; recarregar (F5); confirmar que persiste.
4. Trocar para OUTRO produto da MESMA conta → confirmar que mostra o MESMO texto.
5. Trocar para um produto de conta DIFERENTE → confirmar campo vazio (ou texto daquela outra conta, se já tiver) — nunca o texto do passo 3.
6. (Opcional) Repetir o passo do checkpoint da 170-01 (prompt via tinker) com o texto cadastrado por esta tela, para confirmar o caminho ponta a ponta tela→banco→prompt.

**Migration da 170-01 ainda não rodou em produção** — isto precisa acontecer antes de qualquer verificação em ambiente que não seja o dev local.

---
*Phase: 170-identidade-visual-por-conta-de-marketplace-logo-opcional*
*Completed: 2026-10-07 (Tasks 1-2; Task 3 pendente, é checkpoint do usuário)*

## Self-Check: PASSED

Todos os 9 arquivos citados neste SUMMARY foram confirmados existentes em disco; os 2 commits (`cb3fac7b`, `5c6b73b5`) foram confirmados presentes em `git log --oneline`.
