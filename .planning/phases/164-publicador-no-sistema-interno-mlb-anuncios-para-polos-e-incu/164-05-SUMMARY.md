---
phase: 164-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 05
subsystem: frontend-publicador
tags: [react, publicador, mesa-de-anuncio, ui]
requires: [164-04]
provides:
  - CardVariacoes e CartaoVariante (card 3)
  - CardTiposEPrecos (card 5), CardLogistica (card 6), CardDescricao (card 7)
  - GradeVariantes exporta CampoEstoque, CampoSku, CampoGtin, AtributosExtrasDaVariante
affects: [164-13, 164-15]
key-files:
  created:
    - resources/js/Components/Publicador/Mesa/CardVariacoes.jsx
    - resources/js/Components/Publicador/Mesa/CartaoVariante.jsx
    - resources/js/Components/Publicador/Mesa/CardTiposEPrecos.jsx
    - resources/js/Components/Publicador/Mesa/CardLogistica.jsx
    - resources/js/Components/Publicador/Mesa/CardDescricao.jsx
  modified:
    - resources/js/Components/Publicador/GradeVariantes.jsx
    - resources/js/Components/Publicador/EditorDeEixos.jsx
    - tests/js/publicador-mesa.test.js
decisions:
  - "Chip de fotos da variante lê min_pictures/recommended_pictures de schema.limites; como o servidor não os expõe, mostra 'Fotos OK (n)' ou 'Sem fotos'"
  - "Bolinha de cor só aparece se o valor do eixo COLOR trouxer hex/rgb; o servidor hoje não envia, então nada é desenhado"
  - "Cards usam m.alvos (estado mesclado com a cópia local), não estado.alvos"
metrics:
  tasks: 2
  commits: [370214c2, edaf656b]
---

# Fase 164 Plano 05: Mesa de anúncio, cards 3, 5, 6 e 7 — Resumo

Os 7 cards da mesa existem sobre o contrato `m`: Variações e estoque (um cartão por combinação, reaproveitando os campos de GradeVariantes), Clássico e Premium (título por tipo com limite do schema e simulador), Logística (modalidade do servidor, tiles de medidas, garantia, envio em select nativo) e Descrição em texto simples (RN-72). EditorDeEixos e GradeVariantes ficaram no vocabulário 13/11px e pesos 400/700.

## Commits

- Tarefa 1: `370214c2` — card de variações, CartaoVariante, normalização e exportação dos campos
- Tarefa 2: `edaf656b` — cards Clássico e Premium, Logística e Descrição

## Verificação

- `node --test tests/js/publicador-mesa.test.js`: 61/61.
- `npm run test:js`: 537 testes, 535 passam, 2 falham, ambas pré-existentes ("Características secundárias nasce recolhido (é o grupo que mais infla)" e "FASES_TERMINAIS cobre as três fases de saída..."). Nenhuma falha nova.
- esbuild arquivo a arquivo: exit 0. `npm run build`: exit 0, mtime do `public/build/manifest.json` novo (13:56). Os cards só entram no bundle quando 164-13 compuser a página.

## Desvios do Plano

**1. Chip de fotos sem números da categoria**
- `schema.limites` não traz mínimo/recomendado de fotos. O chip lê `min_pictures`/`recommended_pictures` se existirem; senão distingue só "Fotos OK (n)" de "Sem fotos". Nada fixo no código.

**2. Bolinha de cor condicional**
- Os valores do eixo não carregam hex hoje; a bolinha só aparece quando `hex`/`rgb` existir (previsto no plano: "senão nada").

**3. Limite de eixos**
- `maxEixos` lê `schema.limites.max_variation_axes` (inexistente hoje) e cai em 3, como o plano permitia.

**4. Portal intocado em comportamento**
- `Linha` de GradeVariantes passou a usar os campos exportados; a tabela do piloto renderiza igual (apenas meio pixel de tipografia: 12.5 para 13, 11.5 para 11).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. T-164-19 mitigado (só interpolação JSX; gate proíbe `dangerouslySetInnerHTML` em Mesa/*); T-164-21 mitigado (preço e "Ativa" travados com `v.publicada`).

## Self-Check: PASSED (arquivos e hashes conferidos)
