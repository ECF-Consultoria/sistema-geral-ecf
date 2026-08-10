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

**DEPLOYADO 260810** (`159f6f98..63461dde`, push FF + `deploy.sh` exit 0, ~4 min).

Saiu **isolado**: a VPS estava 1 commit atrás de `origin/main`, mas esse commit
(`159f6f98`) toca só `.planning/` — o único código que mudou em produção foi o desta task.
Conferido `HEAD..origin/main` **e** o HEAD da VPS antes do push, como manda o playbook.
Árvore da VPS sem nenhum arquivo *rastreado* sujo (só `.bak`/`.env.bak` não rastreados, que
o `reset --hard` não toca e o script não limpa) — sem repetir o incidente de 260731.

Verificado em produção por reconsulta, não por stdout do script:

- HEAD da VPS = `63461dde`;
- migration aplicada (147.87ms) e `Schema::hasColumn('mlb_implementacoes','link_whatsapp')`
  → **existe** no banco de prod;
- bundle **buildado na VPS**: o manifest resolve `Pages/Polos/Painel.jsx` →
  `assets/Painel-BCphPUpl.js` (resolvido pelo manifest de propósito — havia **dois**
  `Painel-*.js`, e assertar o errado daria falso positivo). Nele: `Link do Whats` presente,
  `link_whatsapp` 7×, e **zero** ocorrências de `hrefWhats`/`encurtaLink`/`EditLink`.
  Essa última é a asserção que importa: pela lição de 260807, identificador livre que
  **sobrevive literal** à minificação é sinal de que o esbuild não o resolveu (escopo
  vazado). Minificados = resolvidos;
- workers `_00`/`_01` RUNNING com uptime de 28s — prova de que a última linha do script
  rodou, já que o `supervisorctl restart` é o fim dele;
- smoke: `/mlb/polos-painel` → 302 (sem sessão), `/login` → 200.

O `ERROR The [public/storage] link already exists` no log é idempotente e esperado
(`storage:link || true`).
