---
phase: 160-publicador-no-sistema-interno-mlb-anuncios-para-polos-e-incu
plan: 11
subsystem: frontend
tags: [mlb, publicador, inertia, react]
requires: [160-06, 160-10]
provides:
  - Tela B (produtos da empresa) em Mlb/Publicador/Produtos.jsx
  - SeloStatusProduto e ModalNovoProduto (reusados pelo editor, 160-13)
  - ModoAnuncioTabs com Individual -> Publicador e abas desabilitadas sem Company
key-files:
  created:
    - resources/js/Pages/Mlb/Publicador/Produtos.jsx
    - resources/js/Components/Mlb/Publicador/SeloStatusProduto.jsx
    - resources/js/Components/Mlb/Publicador/ModalNovoProduto.jsx
  modified:
    - resources/js/Pages/Mlb/ModoAnuncioTabs.jsx
    - tests/js/estrutura-meus-anuncios.test.js
    - tests/js/publicador-entrada.test.js
decisions:
  - Filtros da tela B espelham os baldes de contagem do servidor (MlbPublicadorEntradaController): rascunho inclui conferir/publicando; publicados inclui parcial; com_problema = erro
  - Pílula de origem decidida só por oferta_id (D27)
metrics:
  tasks: 2
  completed: 2026-10-02
---

# Fase 160 Plano 11: Tela B e abas do Publicador Summary

Tela de produtos da empresa no Publicador interno, com a aba "Individual" trocada para ele e as abas dependentes de Company desabilitadas (não escondidas) quando a empresa não tem Company.

## Commits

- Tarefa 1: aba Individual, selo e modal de produto (`git log` do branch: commit "feat(160): aba Individual leva ao Publicador...")
- Tarefa 2: "feat(160): tela de produtos da empresa no Publicador interno"

## Entregue

- `ModoAnuncioTabs`: Individual aponta para `mlb.anuncios.publicador.produtos` (`conta` = `contaPublicador` ou `company-{id}`); Meus/Massa/Histórico com `aria-disabled`, `opacity-40`, `cursor-not-allowed` e title literal sem Company; sem `mlb.anuncios.wizard` no código. Páginas irmãs e o wizard não foram tocados.
- `SeloStatusProduto` (sete situações, variante compacta) e `ModalNovoProduto` (SKU/Nome obrigatórios, aviso de SKU repetido não bloqueante, erros 422, `router.get(data.url)` no 201).
- Tela B: trilha, selos, Sincronizar do Portal (mensagem, realce 2 s, status 6 s), "+ Produto", chips com contagens do servidor, busca no navegador, tabela 56px clicável/Enter, estados vazios, rodapé D22, polling de 5 s só com produto "publicando".
- Testes JS: gates de fonte para os arquivos novos e para a tela B.

## Verificação

- `npm run test:js`: 596 testes, 594 passam, 2 falham (as 2 da baseline: "Características secundárias nasce recolhido..." e "FASES_TERMINAIS cobre as três fases de saída...").
- `npm run build`: verde; manifest novo (mtime 02/10 14:48) com `resources/js/Pages/Mlb/Publicador/Produtos.jsx` -> `assets/Produtos-SBg9m43i.js` existente.

## Deviations from Plan

Nenhuma de regra 1-4. Observações: o filtro "Rascunho" inclui produtos "Sem rascunho" e "Publicando" porque o servidor os conta nesse balde; nenhum outro teste de `tests/js` esperava o wizard nas abas.

## Known Stubs

Nenhum.

## Self-Check: PASSED
