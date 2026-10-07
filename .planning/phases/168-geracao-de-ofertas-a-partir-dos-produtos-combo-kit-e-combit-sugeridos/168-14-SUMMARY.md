---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 14
subsystem: portal-estrutura
tags: [portal, react, inertia, sugestoes, cartoes, guarda-de-saida]
requires: ["168-05", "168-10", "168-13"]
provides:
  - "Pages/Portal/EstruturaSugestoes.jsx (aba Sugestões completa)"
  - "6 componentes em Components/Portal/Estrutura/Sugestoes/ (BarraDeMarcadas já com variante 'descartadas' para o 168-15)"
  - "qualEstadoVazio em sugestoesEstrutura.js"
affects: ["168-15", "168-16"]
key-files:
  created:
    - resources/js/Pages/Portal/EstruturaSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/CartaoSugestao.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/CabecalhoFamilia.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/FiltrosSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/BarraDeMarcadas.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/ExplicacaoDasOfertas.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/AvisoSugestoes.jsx
    - tests/js/estrutura-sugestoes.test.js
    - tests/js/estrutura-sugestoes-componentes.test.js
  modified:
    - resources/js/lib/sugestoesEstrutura.js
decisions:
  - "D-01, D-08, D-18 e D-19 implementados na tela; D-20 respeitado (nenhum submódulo novo)"
metrics:
  tarefas: 3
  commits: [7fe71923, b229e244, 10b01d7e]
  completed: 2026-10-07
---

# Phase 168 Plan 14: página Sugestões de ofertas (aba Sugestões) Summary

Página Inertia com cartões por família, filtros e páginas no servidor, aceitar/descartar por cartão e em lote pelas libs do 168-05, guarda de saída em três portas (aba, link, voltar do navegador), frete da página sob demanda e os quatro estados vazios, tudo sem planilha.

## Commits

| Tarefa | Commit | Conteúdo |
|---|---|---|
| 1 | `7fe71923` | 6 componentes + gate `estrutura-sugestoes-componentes.test.js` (6 testes) |
| 2 | `b229e244` | página (casca, abas, filtros no servidor, marcação/edição, aceite/descarte) + gate da página |
| 3 | `10b01d7e` | guarda de saída, frete da página, estados vazios (`qualEstadoVazio`), gate ampliado |

O plano pedia um commit único junto da Task 3; o orquestrador pediu um commit por tarefa, e foi o que se fez.

## Verificação

- `node --test` dos 3 arquivos (`estrutura-sugestoes`, `-componentes`, `-selecao`): 45 testes, exit 0. RED visto antes dos componentes (exit 1, arquivos ausentes).
- `npm run test:js`: 1178 testes, 1176 passam, 2 falhas = o piso antigo ("Características secundárias nasce recolhido" e "FASES_TERMINAIS cobre as três fases de saída").
- `AcessoAsSugestoesTest`: 16 testes, 174 asserções, exit 0.
- Build: `npm run build` exit 0 em 21 s; mtime do `public/build/manifest.json` (1791383435) posterior ao início (1791383414) e chave `resources/js/Pages/Portal/EstruturaSugestoes.jsx` presente. `public/build` não foi commitado.
- Não foi aberta a tela no navegador neste plano (a prova no navegador, incluindo 390 px e a guarda do voltar, é do 168-16, como diz a ameaça T-168-45).

## Decisões de implementação

- A página não monta corpo de aceite: `aceitarMarcadas` envia só `{chave, nome, sku}` (T-168-44); o gate exige `{ sugestoes: pedidos }` e a rota `.aceitar`.
- Guarda: `definirGuardaDoVoltar` + `router.on('before')` com `deveSegurarVisita` + `beforeunload`; o gate proíbe `addEventListener('popstate'`. "Sair sem aceitar" libera e refaz a visita (ou `history.back()` no voltar).
- Filtros: `filtrosRef` guarda os filtros "de agora" para duas mudanças seguidas (Limpar filtros + busca esvaziando) não pisarem uma na outra com props antigas.
- O corpo do cartão mostra "Mercado Livre" só quando o frete cotado vem com `origem === 'api'` e sem `falhou`; fora disso, "estimado".
- As pílulas de tipo do cartão só viram botão quando a página passa `onTipo`; sem ele são rótulos (o `JanelaTipo` é do 168-15).

## Deviations from Plan

**1. [Rule 3 - Bloqueio] Heredoc do Bash falhou duas vezes**
- Arquivos e scripts foram gravados com a ferramenta Write (o script de edição ficou no scratchpad). Sem efeito no código.

**2. [Rule 1 - Ajuste] Gate de `AvisoSugestoes` exige `role="status"` literal**
- `role={erro ? 'alert' : 'status'}` não passava no gate; o componente renderiza duas `div` com o papel literal. Mesmo comportamento.

**3. [Ajuste] `qualEstadoVazio` ganhou o parâmetro `qtdItens`** (além dos quatro do plano) para devolver `null` quando a página tem itens.

## Known Stubs

- Abas "Sem tipo" e "Descartadas": a página mostra só o título, as abas e a barra; o corpo (`PainelSemTipo`, `ListaDescartadas`, `JanelaTipo`) é do 168-15, como o plano determina. Em `Sem tipo`/`Descartadas` a barra de marcadas não é renderizada.
- `onTipo` do cartão não é passado ainda (ver acima).
- As entradas por Produtos e Lista SKUs também são do 168-15.

## Threat Flags

Nenhum. T-168-44 e T-168-46 cobertos por gate; T-168-45 depende da prova no navegador do 168-16; T-168-47 aceito (botão `disabled` durante a consulta).

## Self-Check: PASSED

- Os 9 arquivos criados existem; commits 7fe71923, b229e244 e 10b01d7e conferidos com `git show --stat`.
- STATE.md e ROADMAP.md intocados.
