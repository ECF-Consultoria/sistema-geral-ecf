---
quick_id: 260630-edm
slug: aba-polos-unificada
title: Aba unificada do módulo Polos — visão operacional orientada à decisão
date: 2026-06-30
status: complete
execucao: inline (sem gsd-executor/worktree — Restrição #10)
deploy: NÃO (apenas localhost)
commits:
  - e0aba34 refactor — extrai poloCores + estagioBadge
  - 2245ce5 feat — backend (montarCockpit + painel)
  - 0a00595 feat — page Polos/Painel + item de menu
---

# SUMMARY — Aba unificada do módulo Polos (Painel Polos)

## O que foi entregue
Nova aba **ADITIVA** `Polos/Painel.jsx` (rota `mlb.polos-painel`, item "Painel Polos" no grupo
Polos) que une Onboarding + Empresas + Faturamento Polos numa visão única orientada à decisão:
o analista vê numa linha Fase, Estágio, Polo, Responsável, Onboarding, Problema e status de
envio — e (SÓ admin) Faturamento vs Meta + ADS — e age in-place, com a ficha a um clique óbvio.
As 3 abas antigas seguem **intactas**.

## Arquivos
**Novos**
- `resources/js/Pages/Polos/Painel.jsx` — a page (cockpit admin colapsável + filtros por chips + tabela densa operável + drawer por empresa).
- `resources/js/Pages/Polos/components/poloCores.js` — `POLO_PALETTE` + `montarCorDoPolo()` (extraído de Index.jsx).
- `resources/js/Pages/Polos/components/estagioBadge.js` — `ESTAGIO_COLORS` + `corEstagio()` (extraído de Implementacao.jsx).

**Modificados (sem regressão)**
- `app/Http/Controllers/PolosController.php` — `index()` virou wrapper; `montarCockpit()` (público) e `cockpitVazio()` extraídos; novo `painel()`.
- `routes/web.php` — rota `mlb.polos-painel` no grupo `mlb.*` (NÃO no `role:admin`).
- `resources/js/Layouts/AppLayout.jsx` — item "Painel Polos" (`permission: 'mlb.projetos'`).
- `resources/js/Pages/Polos/Index.jsx` — usa `montarCorDoPolo` (lógica idêntica).
- `resources/js/Pages/Mlb/Implementacao.jsx` — importa `ESTAGIO_COLORS` (valores idênticos).

## Decisões-chave (justificadas)
- **Rota/gate (RF-1, RF-8)**: rota no grupo `mlb.*`; gate operacional `isAdmin() || hasPermission('mlb.projetos')` (espelha `/mlb/polos-empresas`). Camada financeira montada **só** dentro de `if ($isAdmin)` → não-admin nem recebe os campos no payload (anti-vazamento server-side). NÃO usa `isGestor/isLiderPub/isPublicador` (bug `whereHas('cargos')`).
- **Reuso sem regressão (RF-6)**: `montarCockpit()` extraído do `index()` → `/polos` byte-idêntico e cockpit do Painel idêntico; componentes de `Polos/components/` reusados direto. `PoloDrawer` (acoplado) NÃO reusado — drill-down por empresa via `polos.empresa.semanal`. `EmpresasPorM.jsx` intacto (pill binária mantida).
- **Fase M (RF-4a)**: com ficha → `PATCH bloco.identificacao` (parcial); sem ficha → `PUT empresas.update` com `payload_empresa` completo (evita zerar campos/SKUs). Confirmado: nem todo Polo tem ficha (28 de 171).
- **Mês (RF-7)**: `?mes` só afeta o financeiro (admin); fase/estágio/responsável/onboarding/problema são estado ao vivo.
- **Filtros (RF-5)**: client-side (Fase/Polo/Estágio/busca/Fora-do-prazo/Pendente-de-envio); Status só admin; só o mês é server-side.

## Melhorias propostas (implementadas, justificadas)
- Badge **"Pronto p/ M2"** quando M1 já fatura (decisão de promoção visível).
- **Realce de linha** (borda âmbar) p/ itens que exigem ação (problema/fora do prazo/pendente de envio).
- **Cross-link**: clicar num polo no Ranking/AdsCard filtra a lista operacional abaixo.
- **"sem sync"** distinto de "não fatura" (R$0 por falta de aquecimento).
- Cockpit financeiro **colapsável** (macro no topo, lista abaixo, drill-down sob demanda).

## Sugestões NÃO implementadas (registradas)
- Unificar a pill de estágio do `EmpresasPorM.jsx` (binária) com o mapa canônico — adiado p/ não regredir o visual atual dessa aba.

## Validação manual (localhost, dados reais — SEM deploy)
Banco já populado (187 MlbEmpresa; 171 POLOS; 143 com ficha; 28 sem). `npm run build` exit 0
(page `Painel-*.js` no bundle). Testes via tinker pelo pipeline controller→Inertia:
- **Montagem operacional**: 171/171 rows sem erro (28 sem-ficha incluídas).
- **`montarCockpit()`**: retorna as 10 props idênticas ao `index()` antigo (5 polos, m1=58, erro=NULL); join financeiro mapeou 150 cust_ids.
- **`painel()` ADMIN**: 200, `Polos/Painel`, `isAdmin=true`, 171 empresas, `cockpit` presente, `fin` por linha (ex.: status "Problema", meta 4000), 1 `sem_sync`.
- **`painel()` não-admin COM perm (mock)**: `cockpit` OMITIDO, nenhum row com `fin`; operacional intacto + `payload_empresa` p/ sem-ficha → **sem vazamento financeiro**.
- **`painel()` não-admin SEM perm**: `abort 403`.
- **Regressão**: `/polos`→`Polos/Index` (10 props), `/mlb/polos-empresas`→`Polos/EmpresasPorM` (totalPolos=171), `/mlb/implementacao`→`Mlb/Implementacao` (143) — todas intactas.

## Revisão 2 — modelo "planilha" (após feedback do usuário)
Feedback: a aba parecia cópia das antigas e ainda obrigava abrir a ficha + navegar blocos
para editar. Referência dada: aba "Dash Gerencial Polos" da planilha do time (plana, edita
inline, decide e segue). Li a planilha (Google Drive expirado → via export CSV público) e
mapeei suas colunas = exatamente os campos de MlbImplementacao (acessos/produtos/logística).

Reformulação (commit 9dad371):
- `Polos/Painel.jsx` reescrito como **grade de LENTES** (Geral · Acessos & Setup · Produtos
  & Publicação · Logística · Financeiro-admin), coluna Empresa fixa. TODOS os campos do
  onboarding **editáveis INLINE** (select/toggle/text/date) — cada célula salva sozinha via a
  rota do bloco (`bloco.{identificacao|acessos|produtos|logistica}`, PATCH parcial). **Sem
  abrir ficha.** Cockpit financeiro recolhido no topo. Zebra + sticky + look de planilha.
- `painel()` agora monta SÓ o operacional (**109ms**, sem ECF Drive) + valores do onboarding
  + opcoes + cust_norm → edição inline instantânea.
- Novo `painelFinanceiro()` (rota `mlb.polos-painel.financeiro`, JSON admin-only): cockpit +
  mapa cust_norm→fin, carregado async pelo front. Reforça anti-vazamento (financeiro nem
  entra no payload) e mantém o inline rápido.
- Validado: PATCH parcial muda só 1 campo (vizinhos intactos); financeiro 403 p/ não-admin;
  painel() 109ms; build exit 0; /polos intacto.

## Pendências / notas
- Verificação foi pelo pipeline server→Inertia (não houve click-through de browser). A page compila
  e o acesso a props é guardado; risco de runtime residual baixo.
- Trabalho na branch `deploy/painel-publicador` (init GSD `branch_name=null`). **Nenhum deploy executado.**
