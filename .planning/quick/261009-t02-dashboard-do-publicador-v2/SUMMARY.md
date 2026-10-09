---
tipo: quick
slug: t02-dashboard-do-publicador-v2
data: 2026-10-09
status: complete
origem: stitch_ecf_marketplace_publisher_redesign/02_dashboard_do_publicador_modern_minimalist/
commits: [778c58a0, a9587910, 70916f52]
---

# Quick 261009-t02 — Dashboard do Publicador (tela 02)

A Visão geral da conta no layout do mockup, com **4 KPIs de dado verdadeiro**, o quinto vazio e
honesto, e os "Alertas" saindo da triagem que já existia — sem inventar número nenhum.

## Gates — reconferidos pelo orquestrador

| gate | antes | depois |
|---|---|---|
| PHP `tests/{Feature,Unit}/Publicador` | 1383 passed | **1392 passed**, 0 falhas |
| JS `npm run test:js` | 1813 / 1811 pass / 2 fail | **1851 / 1849 pass / 2 fail** |
| `npm run build` | — | verde; `assets/VisaoGeral-DzGS-y9O.js` |
| `grep -c "bg-ecf-yellow[^/]"` (painel e cartão) | — | **0** e **0** |
| `git diff --stat -- database` | — | **vazio** |

⚠️ O hash saiu **com hífen** (`DzGS-y9O`) de novo — resolvido pelo JSON do manifest.
⚠️ A baseline PHP medida foi **1383**, não os 1382 que eu havia informado no plano.

## O achado mais importante: o rótulo do mockup seria mentira

O mockup chama o KPI de **"Tração (30D)"**. Mas o número vem de `sold_quantity`, que é venda
**acumulada desde a publicação** — não uma janela de 30 dias. O número está certo; a afirmação
sobre a janela, não. Ficou **"Com venda — 83% do que está no ar já vendeu"**.

Isso já estava registrado na spec da Etapa 2 ("`sold_quantity` é acumulado; 30 dias exigiria
diferença na série"), e o mockup reintroduziu a afirmação errada.

## Comparação com o `screen.png` — o que mudou e por quê

> ⚠️ Conferência contra o **PNG do mockup** e o HTML do render real. **Ninguém viu a tela
> renderizada** — a conferência visual é do usuário.

**Dado que não existe, desenhado vazio e honesto:** Revisão humana ("Não existe no sistema —
nada passa por revisão manual hoje"), estoque do ERP na fila, reputação "Conta Líder Platinum",
"ERP sincronizado há 8 min", "API Mercado Livre 99.9%", "32 packs reaproveitados" e
"320 imagens" (só contamos packs).

**Rótulos corrigidos:**

| Mockup | Ficou | Motivo |
|---|---|---|
| "Tração (30D)" | "Com venda" | a janela não existe no dado |
| "124 Fase 2" | "124 produtos em kit" | `quantidade_kit >= 2` inclui o kit de 3, que é Fase 3 |
| "Alertas Meli & ERP" | "Alertas do acervo" + rodapé "Nada aqui vem do ERP" | nada vem do ERP |
| "Atividade da Equipe · tempo real" | "Quem publicou" | "gerou 5 imagens IA" e "revisão aprovada" não existem como registro |
| "ALAVANCA RECOMENDADA · Reotimizar com IA" | "Ver alavancas desta conta" | o número 58 é real; a reotimização por IA não existe |

**Estrutura:** 6 KPIs em vez de 5 (o "Publicados nos últimos 30 dias" já existia e não podia
sumir); cards de atalho empilhados, não lado a lado (a coluna tem 340px); sparkline e seletor de
período fora, por decisão.

## Guardas de honestidade embutidas

- `no_ar_por_fase` devolve **nulo** quando a lista não é ciente de fase (shape antigo) — ali
  zero seria mentira. Lista **vazia** é outra coisa: a conta não tem produto, e `{0,0}` é medido.
- `alertas.disponivel = false` sem Company: "não há acervo para triar" ≠ "zero alertas".
- `tracao_pct` **nulo** com `no_ar = 0`, acervo nunca coletado ou conta sem Company — nunca 0%.
- O cartão **não carimba** o selo de urgência ("Pendentes") em cima de um "—".
- `alertas.total` conta anúncios **distintos** com motivo (3), não a soma dos chips (4).

## Custo de consulta

`no_ar_por_fase` não consulta nada (lê a lista já carregada), `alertas` não consulta nada
(reformata a triagem já carregada) e `criativos_packs` é **uma** consulta. Gate contando
consultas com 11 produtos, 11 kits e 11 itens de acervo.

## Mudança em teste existente — mais estrito, não menos

`publicador-visao-geral-render.test.js`, "item sem fase mostra '—'": o recorte ia do título do
item **até o fim do documento** e contava os "—". Passava por acidente; bastou a coluna lateral
ganhar um estado vazio para virar 2. O recorte passou a terminar no fim da **seção** de
Publicações, que é o que a frase do teste afirma.

## Pendências

1. **Sparkline de conversão diária + seletor Hoje/7 dias/Este mês** — factível com dado real
   (`ml_acervo_metricas_diarias` tem a série diária por anúncio). Tarefa própria.
2. **Revisão humana**, **estoque do ERP** e **reputação** — lugar reservado, nada afirmado.
3. **Contagem de imagens de criativo** — hoje só packs.
4. Conferência visual no navegador — do usuário.
