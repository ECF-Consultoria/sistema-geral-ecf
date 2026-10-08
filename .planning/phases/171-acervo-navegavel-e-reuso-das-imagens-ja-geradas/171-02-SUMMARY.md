---
phase: 171-acervo-navegavel-e-reuso-das-imagens-ja-geradas
plan: 02
subsystem: frontend
tags: [publicador, creative-engine, react, inertia, render-real]

# Dependency graph
requires:
  - phase: 171-01
    provides: "GET mlb.anuncios.publicador.acervo.listar/imagem, POST .../usar — contrato de paraItem() e de reaproveitar()"
  - phase: 170
    provides: "molde de hook simples (useIdentidadeDaConta.js) e de separação apresentação/hook (IdentidadeDaConta.jsx)"
provides:
  - "useAcervoDaConta.js — hook com GET/alternar toda-conta/POST de reaproveitamento"
  - "AcervoDaConta.jsx — CartaoAcervo (apresentação pura) + AcervoDaConta (default), montado na etapa Imagens"
affects: []

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Grupo de fotos do seletor de reaproveitamento vem do estado já carregado pela Mesa (m.estado.grupos_imagem, cada item já com rotulo amigável do servidor) — nenhuma chamada nova ao backend"
    - "Flags por item (temImagem, deOutroProduto, nomeProduto) computadas DENTRO do componente filho (CartaoAcervo), nunca como variável de escopo do componente pai usada só dentro do .map() — mesma precaução de feedback_rollup_map_scope_bug.md"

key-files:
  created:
    - resources/js/Components/Publicador/useAcervoDaConta.js
    - resources/js/Components/Publicador/Mesa/AcervoDaConta.jsx
    - tests/js/publicador-acervo-render.test.js
  modified:
    - resources/js/Components/Publicador/Mesa/EtapaImagens.jsx

key-decisions:
  - "Botão de reaproveitar mostra 'Copiando…' (não 'Usando…') enquanto em voo — reforça que é uma cópia, nunca referência compartilhada, pedido explícito do usuário sobre a linguagem da tela"
  - "Imagem de outro produto sem produto de origem resolvível mostra 'de um anúncio que não existe mais' — nunca 'órfão' (jargão) nem nome vazio/errado"

requirements-completed: [ACERVO-01, ACERVO-02, ACERVO-03, ACERVO-05]

# Metrics
duration: ~50min
completed: 2026-10-08
---

# Fase 171 Plano 02: A lista do acervo na etapa Imagens — Summary

**Bloco "Acervo de imagens já geradas" na etapa Imagens do Publicador: lista as fotos já geradas para o produto aberto (e, marcando "ver de toda a conta", de qualquer outro produto da mesma conta) e permite copiar qualquer uma delas para o anúncio atual com um clique, sem gastar nenhuma chamada nova à IA.**

## Performance

- **Duration:** ~50 min
- **Tasks:** 2/2 completos (Task 3 é checkpoint de conferência do usuário — ver seção própria abaixo, NÃO resolvido por este agente)

## Accomplishments

- `useAcervoDaConta.js` — hook novo (molde de `useIdentidadeDaConta.js`, Fase 170): `carregar(toda)` faz GET em `acervo.listar` com `toda_conta`; `alternarTodaConta()` inverte e relê; `reaproveitar(criativoId, grupo)` faz POST em `acervo.usar` e extrai erro pela mesma convenção de `useCriativosDoPublicador.js` (`erros[0].mensagem`); trocar de `produtoId` sempre reseta `todaConta` para `false` e relê do zero (nunca herda o estado visual da conta anterior — T-171-02-02).
- `AcervoDaConta.jsx` — `CartaoAcervo` (apresentação pura, exportado nomeado, testável por render real) + `AcervoDaConta` (default, chama o hook). Grade responsiva de miniaturas, checkbox "Ver de toda a conta", seletor de grupo por item (derivado de `m.estado.grupos_imagem`, que já traz `rotulo` amigável do servidor — nenhuma lógica nova de resolução de grupo), botão "Usar esta foto"/"Copiando…", estado vazio e esqueleto de carregamento.
- `tests/js/publicador-acervo-render.test.js` — 13 casos de render REAL (esbuild + `react-dom/server`, mesmo harness de `publicador-identidade-render.test.js`): item válido, `produto_nome` como objeto, `rotulo`/`criado_em` em array/número/booleano, 1 vs. vários grupos, imagem de outro produto (com e sem nome resolvível), botão ocupado, `imagem_url` em formato inesperado, e o componente default montando sem crashar (sem chamada ao servidor no render).
- `EtapaImagens.jsx` — uma linha nova (`<AcervoDaConta m={m} produtoId={produtoId} />`), depois de `<FotosEVariacoes m={m} />`, dentro do mesmo `<div>`. Nenhum outro arquivo do Publicador foi tocado.

## Task Commits

1. **Task 1 (RED) — teste de render real** - `ea2ac850` (test)
2. **Task 1 (GREEN) — hook + componente** - `4993484c` (feat)
3. **Task 2 — wiring em EtapaImagens.jsx** - `a445977c` (feat)

## TDD Gate Compliance

- RED: `ea2ac850` (`test(171-02): teste de render real...`) — compilação falhou porque `AcervoDaConta.jsx` ainda não existia (confirmado pela execução real do `node --test`, não só por leitura).
- GREEN: `4993484c` (`feat(171-02): hook e componente...`) — 13/13 testes passam.
- Gate sequence OK (test → feat, nesta ordem no git log).

## Texto final que aparece na tela (para o usuário revisar/ajustar)

**Cabeçalho do bloco:**
- Título: `Acervo de imagens já geradas`
- Descrição: `As fotos que já foram geradas para este produto e, marcando abaixo, também as de outros produtos desta mesma conta. Usar uma delas aqui COPIA a foto para este anúncio — sem gerar nenhuma imagem nova e sem nenhum custo.`
- Checkbox: `Ver de toda a conta`

**Estados:**
- Vazio (produto): `Nenhuma imagem gerada ainda neste produto.`
- Vazio (toda a conta): `Nenhuma imagem gerada ainda nesta conta.`
- Limite: `Mostrando as {limite} mais recentes de {total}.`
- Erro: mensagem vinda do servidor (`erros[0].mensagem`) ou a mensagem genérica de `mensagemDe()`.

**Por item (`CartaoAcervo`):**
- Sem imagem (defensivo, nunca deve ocorrer com dado real): `Sem imagem`
- Rótulo: o `rotulo` do item (nome do tipo de foto, ex. "Imagem principal"), ou `Imagem` se vier em formato inesperado
- Linha de data/origem: `{criado_em}` sozinho (quando é do produto atual); `{criado_em} — de "{produto_nome}"` (quando é de outro produto COM nome resolvível); `{criado_em} — de um anúncio que não existe mais` (quando é de outro produto SEM nome resolvível, o caso antes chamado de "órfão" nas notas técnicas — a tela nunca usa essa palavra)
- Seletor de grupo (só aparece com mais de 1 grupo válido): rótulo de cada `<option>` = `grupo.rotulo` (ex. "Fotos gerais", "Preto")
- Botão: `Usar esta foto` / `Copiando…` (enquanto em voo, desabilitado)

## Decisions Made

- "Copiando…" em vez de "Usando…" no botão em voo — a palavra reforça que é cópia, não movimento nem compartilhamento (regra explícita do briefing do usuário).
- Grupo do seletor já vem com `rotulo` amigável do servidor (`ResolvedorGruposImagem::rotuloDoGrupo()`) — não foi preciso reimplementar a resolução de rótulo no cliente, só ler `m.estado.grupos_imagem` direto (mais simples do que o plano antecipava, que previa a possibilidade de fallback para a chave crua).
- "Usar esta foto" renderizado com `BotaoAcao` (secundário, não o amarelo primário) — a tela já tem um botão primário por etapa ("Continuar"); múltiplos itens no grid não podem competir com ele.

## Deviations from Plan

None — plano executado como escrito. Dois ajustes pequenos, ambos dentro do próprio plano (sem mudar comportamento fora dele):
1. O comentário de docstring de `CartaoAcervo` originalmente citava a sequência de caracteres `` route( `` entre crases — isso fazia o grep de aceite da Task 1 (`grep -n "route(" AcervoDaConta.jsx`) encontrar 1 ocorrência (um comentário, não código). Reescrevi o comentário sem essa sequência literal; o grep agora não encontra nada, como o plano exige.
2. O teste que eu mesmo escrevi (RED) usava o texto "Usando…" para o botão ocupado; decidi (ver "Decisions Made") usar "Copiando…" na implementação — ajustei o teste para o texto real antes de declarar GREEN, não o contrário.

## Issues Encountered

Nenhum.

## Resultado das suítes (saída real)

- `tests/js/publicador-acervo-render.test.js` — **13 testes, 13 passam, 0 falham**.
- `tests/js/publicador-acervo-render.test.js` + `publicador-mesa.test.js` + `publicador-editor.test.js` juntos — **190 testes, 190 passam, 0 falham**.
- Suíte JS completa (`tests/js/**/*.test.js`) — **1303 testes, 1301 passam, 2 falham** — as 2 falhas são as PRÉ-EXISTENTES já documentadas (`estrutura-grade-glide.test.js` "Características secundárias nasce recolhido" e `polosEntrantes.test.js` "FASES_TERMINAIS cobre as três fases de saída"), confirmadas por leitura da saída (mesma asserção, mesmo diff, nenhuma relação com este plano). Nenhuma terceira falha introduzida.
- `npm run build` — concluído em 54.10s, sem erro. `grep "Mlb/Publicador/Editor.jsx" public/build/manifest.json` confirma que `Editor.jsx` continua mapeado no manifest.
- `git diff --stat` em `FotosEVariacoes.jsx`, `PainelCriativos.jsx`, `Editor.jsx` e `apoio.js` — **vazio**, nenhum dos 4 foi tocado.
- Suíte PHP (`tests/Feature/Publicador`, `tests/Feature/Phase171`, Creative Engine): **não executada neste plano** — nenhum arquivo PHP foi criado ou modificado (plano 100% frontend), então não há risco de regressão no backend a verificar.

## Checkpoint — Task 3 (NÃO resolvido por este agente)

Conforme instrução do ambiente de execução, este checkpoint de conferência na tela é do
usuário em conversa com o orquestrador — não é deste agente. Este agente NÃO conduziu a
preparação local (tinker/MariaDB) nem a navegação na tela, e NÃO registra aqui nenhuma
aprovação do usuário.

O que falta para fechar o plano (a ser conduzido fora deste agente, com o usuário):

1. **Preparação local (SEM custo de IA, no MariaDB local, nunca no VPS):**
   - Escolher 2 `PubProduto`/`PubRascunho` da MESMA conta (mesmo `company_id`/`mlb_empresa_id`).
   - Criar um `MlAnuncioCriativo` (`slot_indice=1`, `status='aprovado'`, `imagem_path` apontando
     para um JPEG real ≥ 500px no disco local) com `pub_rascunho_id` = rascunho do PRODUTO 1.
   - Criar um segundo `MlAnuncioCriativo` igual, mas com `pub_rascunho_id = null` (simula o caso
     de uma imagem sem anúncio associado), mesma conta.
   - Rodar `php artisan migrate` se a Task 1 da 171-01 ainda não tiver sido aplicada neste ambiente.

2. **Verificação na tela:**
   - Abrir a etapa Imagens do PRODUTO 1: o bloco "Acervo de imagens já geradas" deve aparecer
     DEPOIS de "Fotos e variações", mostrando a imagem criada — sem o checkbox marcado, a imagem
     sem anúncio associado NÃO aparece.
   - Marcar "Ver de toda a conta": a imagem sem anúncio associado deve aparecer agora, com o texto
     "de um anúncio que não existe mais" (nunca um nome errado, nunca `[object Object]`).
   - Escolher um grupo (se houver mais de um) e clicar "Usar esta foto": deve terminar com sucesso,
     a foto deve aparecer em "Fotos e variações" do grupo escolhido, SEM spinner de geração por IA
     e SEM cobrança.
   - Reconsultar o `MlAnuncioCriativo` de ORIGEM no banco: `status`/`pub_imagem_id`/`aprovado_em`
     devem estar EXATAMENTE como antes do clique (a cópia não deve alterar a origem).
   - Abrir a etapa Imagens de um produto de OUTRA conta: o acervo deve aparecer vazio, mesmo com
     "toda a conta" marcado.

Até que isto seja conduzido e aprovado pelo usuário, o plano 171-02 (e a milestone v25.0) não
está formalmente encerrado — Tasks 1 e 2 estão prontas e comprovadas por teste/build acima.

## Self-Check: PASSED

Todos os 4 arquivos de `key-files` confirmados em disco; os 3 commits (`ea2ac850`, `4993484c`, `a445977c`) confirmados em `git log`.

---
*Phase: 171-acervo-navegavel-e-reuso-das-imagens-ja-geradas*
*Completed (Tasks 1-2): 2026-10-08*
