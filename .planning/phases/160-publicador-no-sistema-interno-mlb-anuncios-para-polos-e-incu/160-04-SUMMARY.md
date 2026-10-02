---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 04
subsystem: frontend-publicador
tags: [react, publicador, mesa-de-anuncio, ui]
requires: [160-01]
provides:
  - Mesa/comum.jsx (CardMesa, ChipSecao, Tile, estadoDasSecoes)
  - CardProduto, CardFichaTecnica, CardFotos sobre o contrato m
  - SECOES, secaoDoProblema, CARD_DA_SECAO, estadoDasSecoes em apoio.js
affects: [160-05, 160-13, 160-15]
key-files:
  created:
    - resources/js/Components/Publicador/Mesa/comum.jsx
    - resources/js/Components/Publicador/Mesa/CardProduto.jsx
    - resources/js/Components/Publicador/Mesa/CardFichaTecnica.jsx
    - resources/js/Components/Publicador/Mesa/CardFotos.jsx
    - tests/js/publicador-mesa.test.js
  modified:
    - resources/js/Components/Publicador/apoio.js
    - resources/js/Components/Publicador/EditorPublicador.jsx
    - resources/js/Components/Publicador/CampoAtributo.jsx
    - resources/js/Components/Publicador/FotosPorGrupo.jsx
    - resources/js/Components/Publicador/Problemas.jsx
    - resources/js/Components/Portal/Estrutura/FotosDoPar.jsx
decisions:
  - "Selo do card Produto deriva de produto.oferta_id (D27), nunca de origem"
  - "Regra de foto lê min_picture_width/height de schema.limites; como o schema atual não os expõe, o apoio mostra só 'Fundo branco'"
  - "FotosDoPar ganhou a prop opcional mesa (72px, CAPA); sem a prop o Portal é idêntico"
metrics:
  tasks: 2
  commits: [87c173ef, 5535cc20]
---

# Fase 160 Plano 04: Mesa de anúncio, cards 1, 2 e 4 — Resumo

Base dos cards da mesa (CardMesa, ChipSecao, Tile) e os cards Produto e categoria, Ficha técnica e Fotos, como apresentação pura sobre o contrato `m`, com os componentes de campo reaproveitados normalizados para 24/15/13/11px e pesos 400/700.

## Commits

- Tarefa 1: base, cards Produto e Ficha técnica, CampoAtributo normalizado, seções em apoio.js `87c173ef`
- Tarefa 2: `5535cc20` — card de Fotos, FotosPorGrupo (envioAoMl, D26), Problemas, FotosDoPar (variante mesa)

## Verificação

- `node --test tests/js/publicador-mesa.test.js tests/js/fotos-do-par.test.js`: 33/33.
- `npm run test:js`: 504 testes, 502 passam, 2 falham — as duas pré-existentes ("Características secundárias nasce recolhido..." e "FASES_TERMINAIS cobre as três fases de saída..."). Nenhuma falha nova.
- esbuild arquivo a arquivo: exit 0. `npm run build`: exit 0, manifest com mtime novo (os cards só entram no bundle quando 160-13 compuser a página).

## Desvios do Plano

**1. [Rule 3 - Bloqueio] FotosDoPar.jsx (Portal) tocado**
- O arquivo não estava em files_modified. Miniatura de 72px com selo "CAPA" e "+ Foto" exige estilo que só existe dentro do `FotosDoPar`. Adicionada a prop opcional `mesa = false`; sem ela o Portal renderiza exatamente como antes. Lógica de reordenar intocada (`fotos-do-par.test.js` verde).

**2. Regra de foto sem números**
- `schema.limites` (ClassificadorAtributos) não traz largura/altura mínimas. O CardFotos lê `min_picture_width/height` se existirem e, senão, mostra só "Fundo branco" (previsto no plano).

**3. Normalização extra**
- `CampoAtributo` força `text-[13px]` sobre o `CLASSE_INPUT` (13.5px) para entrar no vocabulário; efeito de meio pixel no editor do Portal.
- Em `FotosPorGrupo`, falhas reais agora aparecem sob o bloco do grupo da foto (as sem grupo ficam no bloco âmbar do topo).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. T-160-16 mitigado (sem `dangerouslySetInnerHTML`, afirmado no teste); T-160-77 mitigado (nota neutra, sem "enviar de novo" para foto guardada).

## Self-Check: PASSED (hashes conferidos no git log)
