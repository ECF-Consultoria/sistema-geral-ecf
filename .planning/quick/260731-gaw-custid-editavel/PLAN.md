---
quick_id: 260731-gaw
slug: custid-editavel
date: 2026-07-31
status: in-progress
---

# Quick 260731-gaw — Cust ID editável no Painel Polos (corrigir id errado sem trocar de tela)

## Problema

O `＋ cust_id` inline (deployado hoje em `d88cabc4`) só resolve o caso **vazio**. Assim que o
valor é salvo, `CustIdCell` cai no ramo `if (e.cust_id && !editando) return <CustIdChip …/>` e o
chip só copia — **não existe caminho para voltar ao modo de edição**. Se o id for digitado errado
(ou vier errado da planilha), a correção exige ir até a tela Empresas/Publicações.

Isso não é raro: o sync de 31/07 gravou `cust_id` vindo da planilha em 37 empresas, e **7 delas a
Adman não reconhece** (Magistral Brasil, QSolucoes, Diamond Decor, Mais de Casa, Mesa H, Casa H,
GILLJESTOFADOS) — id errado na planilha é uma das hipóteses, e a correção é exatamente esta.

## Escopo

Mudança **só de frontend** — o endpoint já suporta tudo:
`PATCH mlb.empresas.cust-id` → `MlbController@updateCustIdEmpresa` valida `nullable|string|max:50`
e grava `null` quando vem string vazia.

1. Com `cust_id` preenchido: chip de copiar (inalterado) **+ botão de lápis** que abre o mesmo
   input inline **já preenchido com o valor atual** (corrigir um dígito, não redigitar).
2. Lápis aparece no hover do chip — a grade tem ~390 linhas e um ícone fixo por linha vira ruído.
   `focus:opacity-100` mantém acessível por teclado.
3. Esvaziar um id existente = **remover** (o backend grava `null`). Explícito na UI: o botão de
   confirmar vira lixeira vermelha com título "Remover Cust ID", e o placeholder passa a "vazio remove".
4. Guardas: não dispara request se o valor não mudou, nem se o cadastro foi abandonado em branco.

**Fora de escopo:** validar formato do cust_id (o backend aceita string livre por desenho) e
conferir contra a Adman no ato do salvamento.

## Verificação

- `npm run build` verde.
- Chip continua copiando ao clicar (comportamento antigo intacto).
- Lápis abre o input preenchido; Enter salva, Esc cancela.
- Salvar valor igual ao atual não gera request.
- Esvaziar + confirmar remove o cust_id (volta ao estado "＋ cust_id").
