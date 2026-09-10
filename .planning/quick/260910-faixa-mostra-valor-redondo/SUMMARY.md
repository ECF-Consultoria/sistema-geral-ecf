---
quick_id: 260910-j1u
slug: faixa-mostra-valor-redondo
date: 2026-09-10
type: quick
status: done
---

# A faixa mostra o valor redondo, como no contrato — Summary

**Uma linha:** a tabela progressiva (leitura E edicao) passa a mostrar `500.000,00`/`1.000.000,00`
com a logica "a partir de", em vez do teto cru gravado `499.999,99`/`999.999,99` — sem tocar em
nada do motor de cobranca.

## O que foi feito

Criado `resources/js/lib/faixasFaturamento.js` — modulo de funcoes puras isolando toda a
conversao entre o teto **gravado** (`limite_superior`, sempre `,99`, o que
`FechamentoFaixaResolver::classificar()` usa) e o valor **redondo** mostrado ao usuario:

- `tetoRedondo(teto)` / `tetoGravado(valorRedondo)` — a ida e a volta (`+0,01` / `-0,01`).
- `indiceDeGravacao(idx)` — a primeira linha aponta pra ela mesma; todas as outras (inclusive a
  ultima, sem teto) apontam pra linha **anterior** — porque `piso(n) = teto(n-1) + 0,01` por
  definicao.
- `faturamentoDaLinha` / `rotuloFaturamento` — a leitura ("ate R$ X" na primeira, "a partir de
  R$ X" em todas as outras).
- `valorExibidoNoCampo` / `aplicarValorDigitado` — o caminho digitacao -> valor gravado, para o
  formulario de edicao.

Tres telas passaram a usar o modulo:

1. **`resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx`** (grade de leitura
   compartilhada, usada pelo Fechamento E pela ficha da empresa) — a coluna "Faturamento" de cada
   linha usa `rotuloFaturamento`. O cabecalho continua literal `Faturamento ate` (trava do
   `Phase139TabelaProgressivaFielTest`, que mede o cabecalho inteiro) — a mesma ambiguidade que ja
   existe no contrato, decisao do usuario.
2. **`resources/js/Pages/Admin/TabelasContrato.jsx`** (conferencia das propostas do Clicksign,
   painel "Lida deste contrato" vs. "Em uso hoje") — mesma convencao, para as duas telas nao
   divergirem.
3. **`resources/js/Pages/Admin/TabelaEmpresa.jsx`** (ficha de edicao) — o campo de Faturamento de
   cada linha mostra/recebe o valor redondo. A primeira linha ("Faturamento ate") grava nela
   mesma; todas as outras ("Faturamento a partir de") gravam o valor digitado na linha **anterior**
   (`indiceDeGravacao`) — e a unica que funciona ao contrario das outras. Reaproveita
   `atualizarLinha` (mantem a regra existente de limpar "valor e piso" quando a linha-alvo deixa
   de estar sem teto) e `CampoDinheiro`/`dinheiro.js` (mascara ja existente, nao recriada).

`TabelaFaixasSection.jsx` (Fechamento, so leitura) nao precisou de mudanca direta — herda a
correcao por importar `TabelaProgressivaFaixas`.

**`limite_superior` continua gravado com `,99` em todo lugar.** A conversao acontece so na borda
(exibicao e digitacao); `FechamentoFaixaResolver` e `SalvarFaixasFaturamentoRequest` nao foram
tocados.

## Achado durante a implementacao (nao estava no plano, resolvido antes de commitar)

Ao escrever os testes do caminho digitacao -> valor gravado, o primeiro desenho ingenuo (cada
linha editando ela mesma, com um "shift" so na leitura) nao batia com o texto do plano ("grava
piso - 0,01 como teto da **linha anterior**"). A implementacao literal do texto do plano faz a
**linha 0** e a **linha 1** apontarem para o **mesmo** teto gravado (a mesma fronteira, vista do
lado de quem termina ali — "ate" — e do lado de quem comeca ali — "a partir de"). Isso e
intencional, nao bug: as duas ficam sempre sincronizadas (mesmo valor, editavel dos dois lados), e
e consequencia direta e inevitavel de `piso(n) = teto(n-1) + 0,01`. Documentado com um teste
dedicado (`linha 1 (a segunda) espelha a linha 0`) para ninguem "consertar" achando duplicata.

## Testes

`tests/js/faixasFaturamento.test.js` — 17 casos novos (`node --test`), cobrindo a primeira linha,
uma intermediaria e a ultima (sem teto), a ida-e-volta sem perda de centavo, o fallback de tabela
de uma faixa so, e o caso do espelhamento intencional entre as duas primeiras linhas.

```
node --test tests/js/faixasFaturamento.test.js
tests 17 · pass 17 · fail 0
```

Suite JS completa (`npm run test:js`): **396/398 passam** — as 2 falhas restam em
`polosEntrantes.test.js`, arquivo nao tocado por este quick, PRE-EXISTENTE.

**Gate do plano** (`--filter="Phase122|Phase136|Phase137|Phase138|Phase139|Phase140|Phase141|Phase142|Quick260909"`):

```
Tests: 605 passed (2833 assertions)
```

Identico a baseline informada no plano (605/2833/0) — zero regressao.

## Build

`npm run build` — verde, `public/build` regenerado (gitignored, nao commitado). Nenhuma classe
Tailwind fora da escala real (`px-4.5` etc.) foi introduzida — os tres arquivos de UI tocados so
mudaram logica JS e texto, nao classes novas.

## Deviations from Plan

Nenhuma — plano executado como escrito. O unico ponto de deliberacao (o desenho exato do "shift"
na edicao, linha 0 vs. linha 1) esta documentado na secao "Achado durante a implementacao" acima
e resolvido dentro do escopo do proprio plano (Regra 1 — o comportamento observavel bate
exatamente com o texto do plano; a duvida era de implementacao, nao de requisito).

## Known Stubs

Nenhum.

## Arquivos

- Criado: `resources/js/lib/faixasFaturamento.js`
- Criado: `tests/js/faixasFaturamento.test.js`
- Modificado: `resources/js/Components/Fechamento/TabelaProgressivaFaixas.jsx`
- Modificado: `resources/js/Pages/Admin/TabelasContrato.jsx`
- Modificado: `resources/js/Pages/Admin/TabelaEmpresa.jsx`

## Commits

- `ee6fbb23` — feat(quick260910-01): funcoes puras de conversao teto gravado <-> valor redondo
- `6e60ade7` — feat(quick260910-02): grade de leitura mostra valor redondo com a partir de
- `667c58fa` — feat(quick260910-03): conferencia de contratos usa a mesma convencao de faturamento
- `e045ba32` — feat(quick260910-04): formulario de edicao digita e grava valor redondo

## Self-Check: PASSED

- FOUND: `resources/js/lib/faixasFaturamento.js`
- FOUND: `tests/js/faixasFaturamento.test.js`
- FOUND: commit `ee6fbb23`
- FOUND: commit `6e60ade7`
- FOUND: commit `667c58fa`
- FOUND: commit `e045ba32`
- CONFIRMED: gate `605 passed (2833 assertions)` reproduzido nesta sessao, arvore limpa.
