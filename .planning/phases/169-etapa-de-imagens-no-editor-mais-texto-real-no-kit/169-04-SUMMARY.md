---
phase: 169-etapa-de-imagens-no-editor-mais-texto-real-no-kit
plan: 04
subsystem: Publicador (editor do anúncio MLB)
tags: [publicador, editor, etapas, fotos, ux]
dependency-graph:
  requires: ["169-03"]
  provides: ["quarta-etapa-imagens-no-editor"]
  affects: ["170", "171"]
tech-stack:
  added: []
  patterns: ["ETAPAS como fonte única derivada por índice (proximaEtapa/etapaAnterior/tituloDaEtapa)"]
key-files:
  created:
    - resources/js/Components/Publicador/Mesa/EtapaImagens.jsx
  modified:
    - resources/js/Components/Publicador/apoio.js
    - resources/js/Pages/Mlb/Publicador/Editor.jsx
    - resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx
    - tests/js/publicador-mesa.test.js
    - tests/js/publicador-editor.test.js
decisions:
  - "4ª etapa Imagens criada movendo FotosEVariacoes de Detalhes, sem duplicar o componente"
  - "etapaDoProblema: todo problema de foto (E6, com ou sem alvo.grupo/alvo.imagem) passa a apontar para 'imagens', nunca mais para 'detalhes'"
  - "usePublicador.js não foi tocado — validação por etapa mora só em apoio.js + Editor.jsx"
metrics:
  duration: "~1h"
  completed: "2026-10-07"
---

# Fase 169 Plano 04: Quarta etapa "Imagens" no editor do Publicador Summary

Editor do Publicador passa de 3 para 4 etapas (Produto → Detalhes → Imagens → Condições de
venda), movendo "Fotos e variações" de Detalhes para uma etapa própria nova — para que o
operador preencha o fato do produto (ficha técnica/descrição) ANTES de gerar imagens.

## O que foi feito

**Task 1 — `apoio.js` + `EtapaImagens.jsx` novo** (commit `54813bb6`):
- `ETAPAS` ganhou a entrada `{ chave: 'imagens', titulo: 'Imagens' }` entre `'detalhes'` e
  `'condicoes'`. `proximaEtapa`/`etapaAnterior`/`etapaValida`/`tituloDaEtapa` são todas derivadas
  do array por índice — não precisaram de nenhuma mudança própria.
- `etapaDoProblema()`: a condição de fotos passou de `if (alvo.grupo || alvo.imagem) return
  'detalhes'` para `if (alvo.grupo || alvo.imagem || e === 'E6') return 'imagens'`, e `'E6'` saiu
  da lista `['E3','E4','E5','E6','E8','E9']` que cai em `'detalhes'`. Resultado: TODA pendência de
  foto do servidor — com ou sem `alvo.grupo`/`alvo.imagem` — aponta para Imagens, nunca mais para
  Detalhes.
- `EtapaImagens.jsx` criado em `Mesa/`, no mesmo molde de `EtapaDetalhes.jsx`: recebe só `{ m }`,
  renderiza `<FotosEVariacoes m={m} />` (mesmo componente de antes, import direto, zero
  duplicação), `data-etapa-conteudo="imagens"`.
- `SECOES`/`estadoDasSecoes`/`secaoDoProblema`/`contarBloqueios` (taxonomia paralela de 8 seções,
  usada só pelo resumo de progresso em `usePublicador.js`) **não foram tocados**.

**Task 2 — `Editor.jsx` e `EtapaDetalhes.jsx`** (commit `b785c42b`):
- `Editor.jsx`: import de `EtapaImagens`; novo branch `{etapa === 'imagens' && <EtapaImagens
  m={m} />}` entre o de `'detalhes'` e o de `'condicoes'`, dentro do mesmo `<div
  id="conteudo-etapa">`. Não recebe/repassa `criativos` (o `FotosPorGrupo.jsx` já lê o contexto
  `CriativosDoPublicador` sozinho desde a Fase 165-07).
- `EtapaDetalhes.jsx`: removido o import e o JSX de `FotosEVariacoes` — a etapa agora só tem Ficha
  técnica e Descrição.
- Comentário de topo do `Editor.jsx` atualizado: registra explicitamente que a volta a 4 etapas
  (07/10/2026, D1/Fase 169) é deliberada e não um esquecimento da decisão de 3 etapas de
  04/10/2026 — explica a causa raiz medida (ordem de renderização em `EtapaDetalhes`) e cita que o
  usuário recebeu a alternativa mais barata e escolheu a quarta etapa mesmo assim.
- Comentário de topo do `EtapaDetalhes.jsx` ajustado, removendo a menção a Fotos e apontando para
  `EtapaImagens.jsx`.
- Gates de fonte em `tests/js/publicador-mesa.test.js` e `tests/js/publicador-editor.test.js`
  atualizados: `ETAPAS` deepEqual com as 4 entradas, `proximaEtapa('detalhes')==='imagens'`,
  `etapaAnterior('imagens')==='detalhes'`/`etapaAnterior('condicoes')==='imagens'`,
  `etapaDoProblema` com os casos de grupo/imagem/E6 apontando para `'imagens'`, nova asserção
  "EtapaDetalhes NÃO contém FotosEVariacoes" e novo teste de `EtapaImagens.jsx`. `EtapaImagens.jsx`
  foi adicionado à lista `CARDS` (gates de tipografia/peso/acento/select nativo).

## Não regredido (checado)

- `Publicar.jsx` (resumo "o que falta por etapa" / "Corrigir em…") usa `ETAPAS.map(...)` e
  `etapaDoProblema` genericamente — nenhuma mudança própria foi necessária; funciona com 4 etapas
  automaticamente.
- `usePublicador.js` **não foi tocado** — confirmado por grep: só usa `SECOES`/`estadoDasSecoes`/
  `secaoDoProblema`, nunca `ETAPAS`/`etapaDoProblema`.
- Navegação por `?etapa=` na URL (`history.replaceState`) + `sessionStorage` por produto: lógica
  inalterada em `Editor.jsx` (`etapaLembrada`, `guardarEtapa`), funciona com qualquer `chave`
  validada por `etapaValida` — nenhuma mudança necessária para as 4 etapas.
- `FotosPorGrupo.jsx`/`BlocoDeFotos` (painel de criativos por IA) não foram tocados — só mudaram
  de componente-pai (`EtapaImagens` em vez de `EtapaDetalhes`), o contexto `CriativosDoPublicador`
  continua alcançando-os do mesmo jeito.

## Diff nos arquivos do outro dev (dono do Publicador)

| Arquivo | Linhas adicionadas | Linhas removidas |
|---|---|---|
| `resources/js/Components/Publicador/apoio.js` | 6 | 3 (ver commit `54813bb6`) |
| `resources/js/Pages/Mlb/Publicador/Editor.jsx` | 19 | 7 |
| `resources/js/Components/Publicador/Mesa/EtapaDetalhes.jsx` | 5 | 3 |
| `resources/js/Components/Publicador/Mesa/EtapaImagens.jsx` | arquivo novo (23 linhas) | — |

Diffs pequenos e localizados: em `Editor.jsx` só o import, o branch de render e o comentário de
topo; em `EtapaDetalhes.jsx` só a remoção de `FotosEVariacoes` e o comentário. Nenhuma lógica de
`usePublicador.js`, `useIaDoPublicador.js` ou `useCriativosDoPublicador.js` foi tocada.

## Verificação

- `node --test tests/js/publicador-ferramentas.test.js tests/js/publicador-editor.test.js
  tests/js/publicador-mesa.test.js` — 189/189 passaram.
- `npm run test:js` (suíte JS completa) — 1136/1138 passaram; as 2 falhas são as PRÉ-EXISTENTES
  documentadas (`estrutura-grade-glide.test.js` "Características secundárias nasce recolhido",
  `polosEntrantes.test.js` "FASES_TERMINAIS cobre as três fases de saída") — confirmadas como
  preexistentes (não regressão), zero falha nova.
- `npm run build` — build OK (32.11s); `resources/js/Pages/Mlb/Publicador/Editor.jsx` continua no
  manifest do Vite (`assets/Editor-hLgcY1_H.js`); confirmado por grep no bundle gerado
  (`assets/apoio-CzqvMB6U.js`) que a string `titulo:"Imagens"` está presente no array `ETAPAS`
  compilado.
- `php artisan test tests/Feature/Publicador tests/Unit/Publicador` — **816 passed** (4093
  assertions), 0 falhas — bate exatamente com a baseline do plano.
- Rodei também `--filter=Publicador` (mais amplo, pega qualquer teste com "Publicador" no nome em
  todo o projeto): 887 passed, 3 failed, 1 incomplete. As 3 falhas são em
  `tests/Feature/Phase75/PublicarEmpresaNaoAtribuidaTest.php` (ex.:
  `test_publicador_dono_nao_recebe_403`), por erro HTTP 400 ao obter "app token" do MLB —
  totalmente alheio a este plano (é uma chamada de rede/mock de token, não a navegação por etapa).
  Fora de escopo (Rule de escopo: falha pré-existente em arquivo não tocado por este plano); não
  investigado nem corrigido aqui.

## Deviations from Plan

Nenhuma — plano executado como escrito. As únicas adições em relação ao `<interfaces>` do plano
foram: (1) o comentário de topo do `Editor.jsx` atualizado além do que o plano pedia para
`EtapaDetalhes.jsx` — pedido explícito do usuário na mensagem de execução, para que quem ler depois
não confunda a 4ª etapa com um esquecimento da decisão de 3 etapas de 04/10; (2) `EtapaImagens.jsx`
adicionado à lista `CARDS` dos gates de tipografia/vocabulário em `publicador-mesa.test.js`, para
que o arquivo novo fique sob as mesmas regras de design system que os demais cards da etapa.

## Known Stubs

Nenhum.

## Threat Flags

Nenhum novo. A navegação por `?etapa=imagens` segue a mesma superfície já registrada no
`<threat_model>` do plano (T-169-13/T-169-14) — nenhuma rota, escrita ou leitura nova.

## Checkpoint (Task 3) — pendente, é do usuário

A Task 3 (`checkpoint:human-verify`, gate `blocking`) **não foi executada por mim** — é
explicitamente do usuário, com o outro dev. O que falta verificar na tela, antes de considerar
este plano concluído:

1. Abrir um produto em `/mlb/anuncios/publicador/produtos/{produto}/editor`.
2. Confirmar 4 nomes na barra de etapas: Produto, Detalhes, Imagens, Condições de venda.
3. Em Detalhes: confirmar que NÃO há mais seção de fotos (só Ficha técnica e Descrição).
4. Em Imagens: confirmar que "Fotos e variações" está lá, com "Gerar com IA" e o bloco de pontos
   fortes/medidas (169-03) funcionando.
5. "Continuar" em Detalhes sem foto aprovada (se o servidor cobrar): confirmar que leva a Imagens,
   não mais a Detalhes.
6. F5 na etapa Imagens: confirmar `?etapa=imagens` na URL e a tela voltar na mesma etapa.
7. Trocar de produto pela barra lateral: confirmar que a etapa lembrada por produto continua
   funcionando (sessionStorage).

**Resposta do usuário:** ainda não coletada — aguardando.

## Self-Check: PASSED

- `resources/js/Components/Publicador/Mesa/EtapaImagens.jsx` — FOUND
- `resources/js/Components/Publicador/apoio.js` contém `chave: 'imagens'` — FOUND
- `resources/js/Pages/Mlb/Publicador/Editor.jsx` contém `EtapaImagens` — FOUND
- commit `54813bb6` — FOUND em `git log --oneline`
- commit `b785c42b` — FOUND em `git log --oneline`
