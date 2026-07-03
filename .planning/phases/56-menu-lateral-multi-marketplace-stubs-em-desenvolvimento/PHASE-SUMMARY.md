---
phase: 56
name: Menu lateral multi-marketplace + stubs "em desenvolvimento"
milestone: v13.0
status: complete
completed_at: 2026-07-03
requirements_completed: [NAV-01, NAV-02, NAV-03, NAV-04]
plans_delivered: [56-01, 56-02, 56-03]
uat_status: aprovado
deployed_to_prod: true
---

# Phase 56 — PHASE-SUMMARY

**Milestone:** v13.0 Reorganização Multi-Marketplace
**Escopo:** Reorganizar sidebar da app ECF Admin para refletir estrutura multi-marketplace da ECF Consultoria. Mudança visual imediata, zero risco de dados.

## O que foi entregue

### Wave 1 — Refactor NAV_TREE + extensão renderSidebar (commit `5859abe`)

**Extensões novas em `AppLayout.jsx`:**
- Entry tipo `{ divider: 'label' }` renderiza label de seção dentro de grupos (sem link/hover)
- Campo `badgeText: 'string'` renderiza badge cinza estático em items topo (distinto de `showBadge` dinâmico)
- Flag `defaultOpen: true` faz grupo abrir automaticamente na primeira visita (preferência salva vence depois)
- Campo `iconSrc: '/path/to/icon.svg'` renderiza `<img>` no lugar do ícone lucide (aplicado nos 3 marketplaces)
- Fix defensivo: `itemVisivel` retorna true pra dividers; `filteredTree` esconde grupo se sobrar só divider; `groupActive` filtra child sem `page`

**Grupo Mercado Livre** (aberto por default, com logo oficial) — estrutura final:
- **Performance:** Dashboard, Desempenho, Empresas, Carteira, Sugadores, Metas, PPA
- **Dados Estratégicos** (divider): Painel Executivo, Concentração e Previsão, Alertas Estratégicos, Grants
- **Polos** (divider): Painel Polos, Onboarding, Empresas Polos, Faturamento Polos

**Items topo Shopee e Amazon** com badge cinza "Em breve" + logos oficiais das marcas.

**Grupo Publicação** (renomeado do plural "Publicações", sub-items MLB.* mantidos — Phase 59 audita).

**Áreas transversais no top-level:** Usuários, Setores, Reuniões, Enviar notificação, NPS (grupo), Meu Setor, Dev (grupo), Comercial (grupo), Publicação (grupo), Administrativo (grupo), Manual do Sistema.

### Wave 2 — Rota + página placeholder (commit `78f0d55`)

- Rota GET `/em-desenvolvimento` (nome `em-desenvolvimento`) em `routes/web.php` com middleware `[web, auth, verified]`
- Componente `resources/js/Pages/EmDesenvolvimento.jsx`:
  - Wraps `AppLayout` (sidebar preservada)
  - Icone `Construction` em círculo `bg-ecf-yellow/[0.12]`
  - Sub-título dinâmico via query param `?marketplace=<slug>` — `MARKETPLACE_LABEL` mapeia shopee/amazon/magalu + fallback
  - Botão CTA amarelo "Voltar ao Dashboard"

### Wave 3 — UAT humano em prod (commits `551e7b0`, hotfixes acumulados)

UAT feito diretamente em prod (usuário optou por pular local). Aprovado após 3 hotfixes visuais durante o processo:

| Hotfix | Commit | Racional |
|--------|--------|----------|
| Dados Estratégicos movido pra dentro do grupo ML | `31c78ee` | Dados vem do ECF Drive, fonte ML-only |
| Reuniões movido pra top-level acima de Enviar notificação | `b24c6e2` | Reuniões é transversal, não ML-específico |
| Logos SVG oficiais das marcas via novo campo `iconSrc` | `bba291a`, `5f914cf`, `7013a28`, `e00adaa` | Identidade visual das marcas; ícone ML iterado 3× até aprovação |

Além disso, integrei via rebase o commit `cbb9bb7 feat(polos): Painel de Polos unificado` de outro dev (novo sub-item "Painel Polos" no grupo ML) sem conflito.

## Requirements cobertos

| REQ-ID | Descrição | Como validado |
|--------|-----------|---------------|
| NAV-01 | Sidebar mostra pasta "Mercado Livre" aberta com Performance + Polos + Dados Estratégicos | UAT prod C1 |
| NAV-02 | Sidebar mostra "Publicação" transversal fora do ML | UAT prod C2 |
| NAV-03 | Sidebar mostra "Shopee" e "Amazon" com badge "Em breve" | UAT prod C3 |
| NAV-04 | Rota `/em-desenvolvimento` renderiza placeholder consistente com DS | UAT prod C4 |

## Arquivos modificados

- `resources/js/Layouts/AppLayout.jsx` — refactor NAV_TREE + extensão renderSidebar (5 features novas: divider, badgeText, defaultOpen, iconSrc, defensive filters)
- `resources/js/Pages/EmDesenvolvimento.jsx` — NOVO componente placeholder
- `routes/web.php` — NOVA rota `em-desenvolvimento` + `use Inertia\Inertia`
- `public/images/mercado-livre-87.svg` — NOVO asset (logo ML, iterado 3×)
- `public/images/shopee-icon.svg` — NOVO asset (logo Shopee)
- `public/images/icons8-amazon.svg` — NOVO asset (logo Amazon)

## Decisões implementadas + overrides do UAT

Locked em CONTEXT.md:
1. ✓ Achatar em 1 grupo Mercado Livre com separator visual (não implementar sub-grupos aninhados)
2. ✓ Dashboard aponta pra rota `dashboard` atual (Phase 58 renomeia)
3. ✓ Rename `Publicações → Publicação` mantendo sub-items mlb.* intactos (Phase 59 audita)
4. ✓ Stubs com badge "Em breve" + placeholder na página

**Overrides do UAT** (documentado em 56-UAT.md):
- Dados Estratégicos ANTECIPADO pra Phase 56 (estava marcado Phase 59)
- Logos SVG das marcas ANTECIPADO (não estava explicitamente escopado)

## Habilita phases

- **Phase 57** (Modelo de dados multi-marketplace): estrutura visual estabelecida — decisões de dados agora podem seguir o contrato visual do menu
- **Phase 58** (Dashboard ECF agregado): shell `/dashboard/{marketplace}` fica trivial de encaixar
- **Phase 59** (Desacoplamento áreas transversais): Publicação transversal já sinalizada visualmente; Dados Estratégicos + Reuniões já reclassificados (menos trabalho pra 59)

## Deploy prod

- Deploy inicial: primeiro `bash deploy.sh` (commit `551e7b0` → `b24c6e2`)
- Sucessivos hotfixes deployados individualmente (5 execuções de deploy.sh no total)
- Cada deploy passou por: `stash` outros devs → `push` → `deploy.sh` (git pull + build + composer + migrate + caches + supervisor restart) → `stash pop`
- Zero downtime observado; zero rollback necessário
- Smoke prod OK: `curl -sI /em-desenvolvimento` retorna 302 → login (rota registrada + middleware auth funcionando)

## Notas para consulta futura

- **Convenção NAV_TREE evolveu:** entries agora podem ter `divider`, `badgeText` (estático) OU `showBadge` (dinâmico), `iconSrc` (SVG externo), `defaultOpen` (grupo). Comentários inline em `AppLayout.jsx` documentam.
- **Extensibilidade multi-marketplace:** adicionar novo marketplace (ex: Magazine Luiza) é 2 mudanças — 1 entry no `MARKETPLACE_LABEL` do `EmDesenvolvimento.jsx` + 1 entry topo no NAV_TREE com `routeParams: { marketplace: 'magalu' }`. Sem tocar rota ou backend.
- **Logos das marcas:** mantidos com cores originais (não recolorimos). Trade-off consciente — dá identidade visual mas quebra a uniformidade dark theme dos ícones lucide. Aprovado no UAT.
