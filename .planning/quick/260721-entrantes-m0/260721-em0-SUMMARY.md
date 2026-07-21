---
quick_id: 260721-em0
slug: entrantes-m0
date: 2026-07-21
status: complete
commit: 60b0a750
branch: feat/polos-entrantes-m0 (a partir de origin/main — isolado do WIP de Shopee)
---

# Quick 260721-em0 — SUMMARY

## O que foi feito
Nova sub-aba **"Entrantes (M0)"** na lente **Metas** do Painel Polos (`/mlb/polos-painel`),
espelhando o layout do PDF "MAPEAMENTO POLOS - Entrantes". Fonte = dados do sistema
(prop `empresas`), escopo **Fase M0**. 100% frontend.

## Arquivos
- **NOVO** `resources/js/Pages/Polos/components/EntrantesM0Panel.jsx`
  - Bloco 1: **Meta M0** (hero `cust+acesso / total M0` com gauge) + **por polo**
    (cards `cust+acesso / total` · "Com cust" · %).
  - Bloco 2: **Checklist M0** — barras Cust ID e Acesso Colaborador (Grupo omitido: ~sempre true).
  - Bloco 3: **Aceites no Projeto** (contador dos pendentes + "Feito") + funil de barras
    **Status da Entrada** (em contato · Entrada próx. mês · Não tem CNPJ · Não tem conta · Não responde).
- **EDIT** `resources/js/Pages/Polos/Painel.jsx`
  - import de `EntrantesM0Panel`; estado `metaView` ('entrantes' | 'torre', default 'entrantes');
    sub-toggle na lente Metas ("Entrantes (M0)" | "Torre de Comando"). MetasPanel intacto.

## Definições (confirmadas com dados de prod, read-only)
- **Meta M0** = Cust ID + Acesso Colaborador. Grupo não discrimina (true em 315/316).
- **Aceite** = M0 com `status_entrada` pendente; **Feito** = já entrou.
- Escopo M0: exclui M1+/Churn/Encerrado (que inflavam a contagem antiga de "entrante").

## Gates
- `npm run build` ✓ (verde, 23,6s; bundle `Painel` recompilado sem erros).
- Verificação visual em localhost: **PENDENTE** (banco local não representa prod).

## NÃO feito (proposital)
- Sem backend/migration/sync. Sem deploy. Sem bater número-a-número com a planilha
  (fonte = sistema).

## Commit
- Código: `60b0a750` (feat/polos-entrantes-m0, a partir de origin/main).
- `anauncios.tct` e `tests/Feature/ShopeeAdsMetricsTest.php` (untracked, do WIP Shopee) NÃO entraram.
- WIP de Shopee ficou intacto na feat/shopee-2apps (stash "shopee-wip-entrantes-detour").

## Próximos passos
1. Review visual da aba renderizada.
2. Se aprovado: `git push -u origin feat/polos-entrantes-m0` (quando você pedir) — sem deploy.
