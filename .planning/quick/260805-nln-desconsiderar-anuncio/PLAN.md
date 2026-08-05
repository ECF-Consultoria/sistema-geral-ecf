---
id: 260805-nln
slug: desconsiderar-anuncio
date: 2026-08-05
mode: quick
---

# Desconsiderar anúncio fora da metodologia

## Problema

Anúncio publicado fora da metodologia hoje entra em tudo: conta como produção
na meta do publicador, entra no denominador da conversão, soma no faturamento
e puxa o score. Não existe forma de tirá-lo da apuração sem apagar o registro —
e apagar destrói o histórico de que ele existiu.

## Decisão

Um flag `desconsiderado` por publicação, alternado por botão em `/mlb/revisao`
(mesma permissão de quem dá veredicto: líder, gestor ou admin). O anúncio
continua existindo, visível e auditável — apenas para de contar.

Escopo do "para de contar": **toda apuração**. Meta, produtividade,
pontualidade, conversão, qualidade, vendas, unidades, faturamento e ticket
médio — nas 3 telas (Dashboard MLB, Vendas, Meu Painel), no Desempenho de
publicadores, no `PlanoMetasPublicacaoService`, no `PublicadorScoreService` e
nas metas de setor.

Fica de fora do escopo: Publicações (cadastro) e Histórico continuam mostrando
tudo — são as telas de registro, não de apuração.

## Tarefas

1. **Migration** — `desconsiderado` (bool, default false, indexado),
   `desconsiderado_por` (FK users nullOnDelete), `desconsiderado_em` (timestamp).
2. **Model `Publicacao`** — fillable, casts, relação `desconsideradoPor()`,
   scope `scopeConsiderado()` (o que entra em apuração).
3. **`RevisaoService::definirDesconsiderado()`** — grava autoria + data e
   registra no activity log. Não mexe na máquina de estados da revisão:
   desconsiderar é ortogonal a revisar.
4. **Rota + controller** — `PATCH /mlb/pub/{pub}/desconsiderar`, guardado por
   `checkPubAccess('revisao')` + `checkPodeRevisar()`.
5. **Aplicar `considerado()`** em todos os pontos de apuração (os 19 sites que
   hoje fazem `tipo != variacao`, mais os que somam `net_billing`).
6. **Fila da revisão** — desconsiderados saem das colunas e dos KPIs (não há o
   que revisar num anúncio que não conta) e ganham filtro próprio
   `Lista · desconsiderados`, que é por onde se desfaz.
7. **UI** — botão com ícone `Ban` na linha e no card, `title` explicando o que
   faz; selo "Fora da metodologia" quando marcado.
8. `npm run build`.

## Riscos

- **Regressão silenciosa de apuração**: esquecer um site de contagem faz o
  número divergir entre telas. Mitigação: varredura por `tipo', '!=', 'variacao'`
  e por `net_billing`, conferindo cada ocorrência.
- **SQLite dos testes × MariaDB**: a migration usa `nullOnDelete`, que exige
  `nullable()` (erro 1830 em produção).
