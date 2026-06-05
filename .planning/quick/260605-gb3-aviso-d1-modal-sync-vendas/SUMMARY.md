---
quick_id: 260605-gb3
slug: aviso-d1-modal-sync-vendas
date: 2026-06-05
status: complete
---

# Summary: Aviso D-1 da Adman no modal Sync Vendas + Preços

## O que foi feito

Adicionado um aviso de defasagem **D-1 da Adman** dentro do modal
`SyncVendasModal` em `resources/js/Pages/Mlb/Empresas.jsx`, entre a descrição
existente e o formulário. Reaproveita o ícone `Clock` (já importado) e os tokens
de estilo do projeto (`border-white/[0.08]`, `bg-white/[0.03]`,
`text-white/40 text-[11px]`).

**Texto exibido:**
> Dados defasados em 1 dia — a API Adman publica D-1 ao redor das 10h BRT. A
> sincronização aqui é manual, por mês.

## Decisão de redação (importante)

O disclaimer do Dashboard principal diz "Sincronização automática diária às 11h"
porque lá o `adman:sync` roda no scheduler. O Sync Vendas + Preços das
publicações é **manual** (botão → modal), sem job agendado equivalente. Por isso
a 2ª frase foi **adaptada** para "A sincronização aqui é manual, por mês" — copiar
o texto literal do dashboard seria enganoso. Fonte de dados confirmada como Adman
(`Empresas.jsx:99` e tooltip "Sincronizar vendas via Adman").

Como o modal é compartilhado, o aviso aparece tanto no sync por empresa quanto no
"Todas as empresas com Cust ID".

## Verificação

- `npm run build` ✓ (10.24s) — chunk `Empresas-*.js` regerado sem erros.

## Arquivos

- `resources/js/Pages/Mlb/Empresas.jsx` — bloco de aviso D-1 no `SyncVendasModal`.
