---
task_id: 260810-dv6
slug: link-whats-polos
date: 2026-08-10
status: complete
commits:
  - 434646f6
---

# Quick 260810-dv6 — Coluna "Link do Whats" no Painel Polos

## O que foi entregue

Coluna **"Link do Whats"** no Painel Polos (`/mlb/polos-painel`), editável inline como as
demais células da planilha, presente na lente **Acessos** e na **Geral**. Com link salvo,
aparece ao lado um ícone que abre o grupo em nova aba.

## Decisão que importa: onde o campo mora

`link_whatsapp` foi para **`mlb_implementacoes`** (bloco **Acessos**), colado no
`grupo_whatsapp` que já existia — o boolean diz SE o grupo foi criado, o campo novo diz
ONDE ele está. Consequências:

- Reusa o PATCH parcial `mlb.implementacao.bloco.acessos` que a edição inline já usa —
  **nenhuma rota nova**, nenhum controller novo. Bastou `BLOCO_DE.link_whatsapp = 'acessos'`.
- **Empresa sem ficha mostra "criar ficha"** na célula, como em todas as outras colunas de
  onboarding. Medido antes de decidir: **269 de 284** empresas POLOS ativas têm ficha (95%),
  então isso atinge 15 linhas que já convivem com a mesma limitação em `gmail_colaborador`,
  `me1`, `erp` etc. A alternativa (guardar em `mlb_empresas`) cobriria essas 15 ao custo de um
  endpoint dedicado — `updateEmpresa` zera campos omitidos, por isso o painel só o usa com
  payload completo.

`grupo_whatsapp` **não foi tocado nem reaproveitado**: `MetasPanel` e `EntrantesM0Panel`
derivam o realizado da aba Metas de `e.grupo_whatsapp === true`. Trocar o tipo do boolean
para acomodar o link teria quebrado a contagem de entrantes silenciosamente.

## Validação: guarda o que foi digitado

`nullable|string|max:255`, **não** `url`. O time cola o que tem na mão — convite
`chat.whatsapp.com/XXXX`, `wa.me/55…` e às vezes sem o `https://`. Validar como URL
rejeitaria uso legítimo. A normalização acontece só no `href` (`hrefWhats()` prefixa
`https://` quando falta), preservando o texto original no campo.

## Verificação

- **Round-trip real, não só unitário**: gravado um link numa ficha local → o payload de
  `PolosController::painel` devolveu `link_whatsapp` na linha da empresa → dado de teste
  removido do banco em seguida.
- Teste novo `test_link_whatsapp_persiste_como_texto` (Phase33OnboardingFichaTest): persiste
  com e sem protocolo, aceita `null` para limpar, rejeita 256 chars. **Passa.**
- `npm run build` verde; bundle da página (`Painel-BA_w-Fn-.js`) contém `Link do Whats` e
  `link_whatsapp`.
- Regressão: `Phase33OnboardingFichaTest` 15/16 (a falha é o grant do polo "Serra Gaúcha",
  pendência aberta desde 260803); suítes de Polos 22 passando / **10 falhas pré-existentes**
  já documentadas em `.planning/learnings/painel-polos-status-e-meta.md` (CSV × Adman +
  assinatura do `SyncPolosFaturamentoJob`) — mesma contagem do baseline de 260805.

## Ajuste de tabela junto

O `colSpan` do drawer de detalhe estava em 30 com o comentário "Geral chega a ~27"; a lente
Geral já passava disso e agora chega a ~33 com a coluna nova. Subiu para 40 para o drawer
voltar a cobrir a linha inteira.

## Fora de escopo (consciente)

- **Ficha de Onboarding** (`OnboardingFicha.jsx`) não ganhou o campo — o pedido era a coluna
  do painel. O dado já está no mesmo bloco Acessos, então incluir depois é um campo no JSX.
- **Edição em massa** (`painelBulk`) não aceita `link_whatsapp`: link é único por empresa,
  aplicar o mesmo a N empresas seria sempre errado.

## Status de deploy

**NÃO deployado** — aguardando autorização. Sai com migration
(`2026_08_10_100000_add_link_whatsapp_to_mlb_implementacoes`).
