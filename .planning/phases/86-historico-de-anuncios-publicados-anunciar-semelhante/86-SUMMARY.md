---
phase: 86-historico-de-anuncios-publicados-anunciar-semelhante
date: 2026-07-15
status: pending-checkpoint
requirements: [HIST-86-1, HIST-86-2, HIST-86-3]
one-liner: "3ª aba Histórico com os publicados + Anunciar Semelhante abrindo o clone no wizard"
commits:
  - 22de805 feat(86) historico de anuncios publicados + Anunciar Semelhante
deployed: 2026-07-15 (deploy parcial, com route:cache)
---

# Fase 86 — Histórico + Anunciar Semelhante — Summary

## O que foi feito

3ª aba (**Individual | Em massa | Histórico**) com os anúncios publicados da empresa: capa, título,
preço, tipo, data, link para o ML e busca por título/SKU. Botão **"Anunciar semelhante"** clona e
abre o rascunho novo no wizard — as duas decisões do usuário.

## A maior parte já existia

`duplicarComoTemplate` → `criarTemplateInterno` **já rodava em produção**: clona `category_id`,
`sku_origem`, `listing_tier` e o **payload inteiro** (título, preço, atributos e, desde a Fase 85,
as fotos), zerando `ml_item_id`/`_classico`/`_premium`. A fase **reusou** — zero alteração nele, e
os 6 testes de `Phase81/DuplicarComoTemplateTest` seguem verdes. O consumidor atual
(`AnunciarML.jsx:1355`, que espera JSON `{ok, rascunho}`) não foi tocado.

**O que faltava era só o histórico:** `massa()`/`index()` filtram
`whereIn([rascunho, validado, erro, publicando])` — `publicado` fica de fora **de propósito** (a
grade edita o que ainda não foi), então o anúncio some da tela justamente quando dá certo.
`historico()` é o inverso.

## Riscos endereçados

- **Vazamento entre empresas:** o `orWhere` da busca fica **agrupado** dentro de
  `where(function ($s) {...})`. Solto, o `or` sobe ao topo do `WHERE` e anula o escopo por
  `company_id`/`status` — a busca por "Camiseta" traria anúncio de **outros clientes**. Teste
  dedicado com 3 "Camiseta" (uma válida, uma de outra empresa, uma em rascunho) prova que só a certa
  volta.
- **`Mlb/Historico.jsx` já existe** e é de **outro módulo** (`MlbController`) — renderizar aquele
  nome sequestraria a página alheia. A página nova é `AnunciosHistorico.jsx`.
- **TDZ no wizard:** o efeito de auto-abertura fica **depois** da definição de `abrirRascunho`
  (`const` não sofre hoisting) — referenciar antes daria o mesmo erro que já deixou este wizard com
  tela preta (`83d4a70`). E usa `abrirRascunho` (a função **completa**), não `hidratarDoRascunho`,
  que perderia os atributos.
- **Link do ML** extraído para `anuncioHistoricoUtils.linkAnuncioMl` — fonte única; o formato vem do
  `9e5a640` (que corrigiu o link errado), agora sem risco de wizard e histórico divergirem.

## Gotcha do deploy (registrado)

Esta fase adiciona **rota nova** e a VPS roda com `route:cache`: copiar `routes/mlb_anuncios.php`
**não basta** — a rota não existiria até `php artisan route:cache` rodar. Feito e confirmado
(`route:list | grep -c mlb.anuncios.historico` = 1).

## Verificação

Phase86 **6/6** (publicados-só, escopo, busca-não-fura-escopo, ordenação, 404 sem token, 403
consultor), Phase82 10/10, Phase81 duplicar-template 6/6, 63/63 JS, build verde.

**Checkpoint visual pendente** — não verificável em localhost (0 empresas com `ml_token`, 0
publicados). Verificar em produção: a aba aparece, lista os publicados, o botão clona e abre o
wizard preenchido.
