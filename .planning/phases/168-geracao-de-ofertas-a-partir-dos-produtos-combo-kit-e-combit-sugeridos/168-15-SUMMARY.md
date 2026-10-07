---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 15
subsystem: portal-estrutura-sugestoes
tags: [react, inertia, portal, sugestoes, sem-tipo, descartadas]
requires: ["168-05", "168-10", "168-13", "168-14"]
provides:
  - aba Sem tipo (PainelSemTipo) e JanelaTipo (tipo e quantidades do produto)
  - aba Descartadas (ListaDescartadas) com restaurar uma ou várias
  - entradas por Produtos (6ª ação) e pela Lista SKUs (link no cabeçalho)
key-files:
  created:
    - resources/js/Components/Portal/Estrutura/Sugestoes/PainelSemTipo.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/JanelaTipo.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/ListaDescartadas.jsx
    - tests/js/estrutura-sugestoes-abas.test.js
  modified:
    - resources/js/Pages/Portal/EstruturaSugestoes.jsx
    - resources/js/lib/sugestoesEstrutura.js
    - resources/js/Components/Portal/Estrutura/Produtos/BarraAcoesProdutos.jsx
    - resources/js/Pages/Portal/EstruturaLista.jsx
    - tests/js/estrutura-produtos-topo.test.js
decisions:
  - "Quantidade vazia vira null (herda o padrão do tipo) e '0' passa como '0' (não gera), com teste comportamental (T-168-49)"
  - "Aba Descartadas tem seleção própria (2ª instância de estadoInicial); a barra usa variante descartadas, sem amarelo"
  - "Definir tipo no painel grava só o tipo e preserva as quantidades que o produto já tinha"
metrics:
  tasks: 3
  commits: 1 (as 3 tarefas num commit só, por determinação do plano)
  completed: 2026-10-07
---

# Phase 168 Plan 15: abas Sem tipo e Descartadas, janela de tipo e entradas Summary

Fecha a tela Sugestões de ofertas: escolha de tipo sem tocar a ficha da 167, restauração de descartadas só por decisão da pessoa e as duas entradas (Produtos e Lista SKUs).

## O que foi feito

- **Lib (`sugestoesEstrutura.js`)**: `opcoesDeTipo` (candidatos primeiro, sem repetir, ignora candidato que não é tipo da empresa), `corpoDaGeracao`, `textoTipoDefinido`, `textoPodeSer`.
- **PainelSemTipo**: introdução literal, cartões em grade, "Pode ser X ou Y", select "Escolha o tipo…", `Definir tipo` desabilitado sem escolha e gravando só no clique, `Ajustar quantidades`, paginação no servidor e estado vazio "Todos os produtos têm tipo".
- **JanelaTipo**: PUT em `portal.auth.estrutura.sugestoes.geracao`; erro 422 do servidor sob o campo só depois de tentar salvar; sucesso fecha, avisa e recarrega `sugestoes`. Aberta pelo painel e pela pílula de tipo do cartão (`onTipo`).
- **ListaDescartadas**: cartões compactos com "Descartada em dd/mm", `Restaurar` (Undo2), marcação própria, `BarraDeMarcadas variante="descartadas"`, POST `.restaurar` com `{ chaves }` e `textoRestauracao` (o que já virou oferta não volta e a pessoa é avisada).
- **Entradas**: `BarraAcoesProdutos` ganhou a 6ª ação (secundária, só com produtos); `EstruturaLista` ganhou o link no cabeçalho.

## Verificação

- `node --test` dos 7 arquivos relacionados: 83 testes, 0 falhas.
- `npm run test:js`: 1190 testes, 1188 passam, 2 falhas (as 2 antigas, o piso: "Características secundárias nasce recolhido" e "FASES_TERMINAIS cobre as três fases de saída").
- Ficha da 167: `git diff 9d54db33 --stat -- EstruturaProdutoFicha.jsx FichaDadosGerais.jsx CartaoVariacao.jsx` vazio.
- Build: `npm run build` saiu 0; `public/build/manifest.json` com mtime 11:36:36 de 07/10/2026 e as chaves `EstruturaSugestoes.jsx`, `EstruturaProdutos.jsx` e `EstruturaLista.jsx`.
- Commit: `0804f453` (`git show --stat` lista só os 9 caminhos do plano).

## Deviations from Plan

Nenhuma de regra. Observações:

- As 3 tarefas saíram num commit só, como o próprio plano determina.
- A busca e o filtro de família da aba Sem tipo (citados na UI-SPEC como "iguais aos da aba Sugestões") NÃO foram acrescentados: o plano não os lista nesta tarefa. Fica como possível melhoria.
- Não foi feita conferência visual no navegador (fora de alcance; só gates, testes e build).

## Known Stubs

Nenhum.

## Threat Flags

Nenhum. T-168-48 (janela só abre produtos da prop da empresa), T-168-49 (teste comportamental de vazio versus 0) e T-168-50 (diff da ficha vazio) mitigados.

## Self-Check: PASSED

Arquivos criados presentes, commit `0804f453` existe.
