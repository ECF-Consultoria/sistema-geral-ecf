---
phase: 168-geracao-de-ofertas-a-partir-dos-produtos-combo-kit-e-combit-sugeridos
plan: 20
subsystem: frontend-portal-estrutura
tags: [conferencia-visual, puppeteer, fidelidade, gap-closure]
requires: [168-19]
provides: [roteiro-T1-T21-verde, passe-de-fidelidade]
affects: [168-21]
key-files:
  modified:
    - resources/js/Pages/Portal/EstruturaSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/BarraDeFiltros.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/AbasDasSugestoes.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/LinhaSugestao.jsx
    - resources/js/Components/Portal/Estrutura/Sugestoes/PecasDaSugestao.jsx
    - tests/js/estrutura-sugestoes-redesenho.test.js
metrics:
  tasks: 2
  completed: 2026-10-07
---

# Phase 168 Plan 20: Conferência no navegador e fidelidade à referência Summary

O roteiro T1..T21 passa inteiro na tela redesenhada (SQLite isolado, dados fictícios) e o passe de fidelidade levou a barra de filtros, a linha e os botões à geometria da referência em 3 rodadas de captura (r1, r2, r3), com a tabela das diferenças que restaram.

`BASE_FIDELIDADE` = `75522f6bf76d1ca915256c1f1459e7a0150758a7` (HEAD depois dos dois `fix` do roteiro e antes dos ajustes de geometria).

## Commits

| Commit | Conteúdo |
|---|---|
| 41c59a7d | `fix(168)`: a tela ficava PRETA: `comFiltro` usado sem ser declarado (o 168-19 trocou a constante local pela função `filtroAtivo`). Gate novo no teste do redesenho |
| 75522f6b | `fix(168)`: a família escolhida na aba Pendentes sumia do seletor na aba Sem tipo (mostrava "Todas" com o filtro ativo). Opção "Família escolhida (0)" quando ela não existe na aba. Gate novo |
| fd439f2f | `fix(168)`: ajustes de geometria da fidelidade (só apresentação, 4 componentes) |

## Defeitos achados pelo roteiro (e só por ele)

1. **Tela preta** (`ReferenceError: comFiltro is not defined`): `npm run test:js` e o esbuild passavam; só o navegador mostrou. Rodada 1 do roteiro: T1..T12 todos FALHOU. Corrigido no 41c59a7d; rodada seguinte passou.
2. **Filtro de família escondido na aba Sem tipo** (T20): corrigido no 75522f6b.
3. **Rolagem horizontal a 1280 px** (scrollWidth 1314): a barra de filtros tinha larguras fixas. Corrigido no fd439f2f (flex-wrap a partir de xl); a 1280 a barra quebra em 2 linhas.

Ajustes no próprio roteiro (não são defeito do código): a frase "Nada é criado sozinho" agora vive na janela "Como funciona" (T1 abre a janela); T17 desfaz a edição e limpa a seleção no fim, porque a guarda de saída (beforeunload) aborta o `goto` seguinte quando sobra edição.

## Roteiro final (`redesenho/roteiro-redesenho.txt`)

```
T1 PASSOU - a ação "Sugestões de ofertas" abre a tela (h1, frase de regra na janela Como funciona)
T2 PASSOU - Lista SKUs: o link abre a tela
T3 PASSOU - filtros/aba/página preservam marcação e edição, sem pergunta (6 checagens, marcadas=2, "2 selecionadas")
T4 PASSOU - link com edição: janela da guarda; continuar editando fica; sair sai
T5 PASSOU - voltar do navegador com edição não salva: fica; sair sai
T6 PASSOU - aceitar 1 linha com nome editado (Lista SKUs ok; custo 420 = soma dos componentes)
T7 PASSOU - marcar em 2 páginas e aceitar em lote: "2 ofertas criadas", Pendentes 180 -> 178
T8 PASSOU - descartar/desfazer, descartar 2 com janela, restaurar (descartadas 3 -> 2)
T9 PASSOU - Sem tipo: Pendentes 177 -> 182, Sem tipo 2 -> 1; "1" em Combo dá erro só ao salvar
T10 PASSOU - código com 121 caracteres: Aceitar e marcação desabilitados com o aviso
T12 PASSOU - sair e voltar pelo histórico: a tela recarrega (linha descartada fora sumiu)
T11 PASSOU - celular 390: scrollWidth 390/390 nas 3 abas; barra em duas linhas
T13 PASSOU - admin cria par; pares 18 -> 19, Kits na tela 36 -> 54
T15 PASSOU - Status: Com aviso=7 (7 linhas, todas com aviso); Prontas=192 (192 linhas, 0 com aviso); 7+192=199=Pendentes
T16 PASSOU - Grupo: Selecionar todas marca as 20 aceitáveis; chevron recolhe/expande mantendo 20
T17 PASSOU - Atualizar: 1 requisição parcial (sugestoes), "Atualizado agora", edição e marcada ficam, sem pergunta
T18 PASSOU - Edição em linha: Enter, Esc, sair do campo e Desfazer edição
T19 PASSOU - Resumo: total 199 = Pendentes; 75+54+70=199; % do total confere; cartões não mudam com o Status
T20 PASSOU - Filtros seguem para Sem tipo (URL com familia), status/tipo/fases desabilitados, dica visível, família mantida ao voltar
T21 PASSOU - celular: sem rolagem nas 3 abas, linha empilhada com Aceitar/Descartar, aceitar-selecionadas invisível, barra fixa em 2 linhas, resumo 2x2
T14 PASSOU - 0 erros de console/página; 1 resposta 422 esperada (T9); 0 diálogos nativos
```

Decisões do CONTEXT conferidas na tela: D-24 (resumo, filtros, abas, linha), D-25 (quadro com iniciais), D-26 (dados do servidor), D-27 (Tipo de produto), D-28 (Status, T15), D-29 (trilho do Portal), D-30 (grupo recolhível, T16), D-31 (Atualizar, T17). D-01, D-19 e D-20 preservados (guarda T4/T5/T12, aceite e descarte T6..T8).

## Fidelidade

3 rodadas de captura antes da final (r1 diagnóstico, r2 ajuste, r3 ajuste do foco da caixa) mais a `final-` do estado commitado. Rodada 1 (`redesenho/diferencas-r1.md`) achou: rolagem a 1280, texto dos selects cortado, "Atualizar sugestões" quebrando em 2 linhas, container de filtros com 78 (ref. 70), linha com 118 (ref. 85), nome/SKU 32 px à direita da referência, caixa marcada com anel azul, CTA 214×40 (ref. 188×37). Rodadas 2 e 3 trataram todas. Medidas finais a 1576 (px absolutos, ref. entre parênteses): filtros 70 (70), selects 174×30 (173×30), Atualizar 176×44 (175×43), CTA 200×38 (188×37), cabeçalho do grupo 46 (46), cartões de resumo 346×84 com vão de 15 (347×84, 15), nome/SKU em x 355 relativo à linha (347).

### Tabela final de diferenças (`redesenho/diferencas-final.md`)

| Elemento | Referência | Sistema | Motivo |
|---|---|---|---|
| Menu lateral de 180 px e barra do topo | presentes | trilho de 76 px; trilha acima do título | D-29 |
| Cores navy / fotos reais | navy, fotos | tokens ecf-*, quadro com iniciais | D-29, D-25 |
| Nomes, SKUs, números, fretes, famílias | os da imagem | os do servidor | a imagem não define dados (D-26) |
| Filtro Ambiente | presente | Tipo de produto | D-27 |
| Chevron à esquerda do grupo | duplicado | só o da direita | aceita |
| Contador nos botões de tipo de sugestão | sem | com contagem | faceta que já existia; busca ~65 px mais estreita |
| Texto de exemplo da busca | "família, produto, SKU" | "produto, nome ou SKU" | a busca não procura família |
| Linha da sugestão | 84-86 | 97 a 110 (motivo em 2 linhas); ~92 com 1 linha | dado real: motivo do servidor é mais longo |
| Início do nome/SKU | x 347 | 355 | dentro de ±8 px |
| Aceitar selecionadas | 188×37 | 200×38 | dentro de ±10% |
| Seleção à direita das abas | "N selecionadas" + CTA | mais "Descartar N" e "Limpar" quando há marcadas | função preservada |
| "Como funciona" e linha de frete/ML | ausentes | presentes | função preservada |
| Filtros a 1280 | n/d | barra em 2 linhas | limite medido (larguras fixas somam mais que 1160 px) |
| Faixa amarela de sessão de equipe | ausente | presente no ambiente; removida só nas capturas de comparação (há `*-com-faixa-1672.png`) | ambiente de conferência |

Nenhuma diferença de estrutura ficou sem motivo. Sem rolagem horizontal a 1672, 1576, 1440, 1280 e 390 (`final-resumo-scrollwidth.json`: 1672, 1576, 1440, 1280, 390; Sem tipo e Descartadas a 1672 também), sem erro de console ou de página.

## Capturas finais

Pasta `C:/Users/User/AppData/Local/Temp/claude/c--xampp-htdocs-ecf-admin/46f098c2-e84c-4b60-80fc-174e1c55b68f/scratchpad/sugestoes-visual/redesenho/capturas/`: `final-pendentes-1672.png` (1672×941), `final-pendentes-1576.png`, `final-pendentes-1440.png`, `final-pendentes-1280.png`, `final-pendentes-390.png` (página inteira), `final-pendentes-1672-cheia.png`, `final-sem-tipo-1672.png`, `final-descartadas-1672.png`, `final-linha-editando-1672.png`, `final-com-faixa-1672.png`, `medidas-final-{1672,1576,1440,1280}.json`, `final-resumo-scrollwidth.json`. Ao lado: `diferencas-r1.md`, `diferencas-final.md`, `roteiro-redesenho.txt`, `roteiro-redesenho.mjs`, `capturar-redesenho.mjs`.

## Verificação

- `node --test tests/js/estrutura-sugestoes*.test.js`: exit 0.
- `npm run test:js`: 1208 testes, 1206 passam, 2 falham (as 2 antigas: "Características secundárias nasce recolhido" e "FASES_TERMINAIS cobre as três fases de saída").
- Build real: mtime do manifest 13:00:49 (168-19) -> 13:24:28 (último build, com a página no manifest, 5 ocorrências). `public/build` não commitado.
- `git diff --stat 75522f6b..HEAD -- app routes database config tests/Feature resources/js/lib/sugestoesSelecao.js resources/js/lib/guardaDoVoltar.js`: vazio.
- Artefatos do 168-16 (`roteiro.mjs`, `roteiro.txt`, `capturas/`) intactos. Servidor de conferência segue de pé (`/login` = 200).

## Deviations from Plan

- [Rule 1 - Bug] tela preta (`comFiltro`), filtro de família escondido e rolagem horizontal a 1280: ver acima.
- A frase de regra "Nada é criado sozinho" deixou de aparecer na tela e só está na janela "Como funciona" (decisão do 168-19, que removeu a explicação fixa). Registrado para o 168-21 decidir se quer a frase visível.
- A captura mobile de página inteira tem 22678 px de altura (lista de 181 linhas empilhadas); a leitura foi feita pelo `t21-celular.png` e pelas medidas.

## Known Stubs

Nenhum.

## Self-Check: PASSED

Commits 41c59a7d, 75522f6b e fd439f2f existem; capturas `final-*` e tabelas existem; STATE.md e ROADMAP.md intocados.
