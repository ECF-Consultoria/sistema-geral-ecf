---
phase: 56
name: Menu lateral multi-marketplace + stubs "em desenvolvimento"
discussed: 2026-07-03
mode: discuss (lean batch — 1 question with 4 sub-decisions)
---

# Phase 56 — DISCUSSION LOG

## Contexto pré-discussão

Milestone v13.0 recém-iniciada (auditoria v12.0 fecha o momento anterior — [v12.0-MILESTONE-AUDIT.md](../../v12.0-MILESTONE-AUDIT.md)). Phase 56 é a primeira phase da milestone e é puramente visual: refactor do sidebar em `AppLayout.jsx`.

Requirements NAV-01..04 já locked em [REQUIREMENTS.md](../../REQUIREMENTS.md#milestone-v130--reorganizacao-multi-marketplace) durante `/gsd-new-milestone`. Discuss não re-abre o QUE, foca em COMO.

## Scout do código pré-discussão

- `AppLayout.jsx` — 568 linhas; `NAV_TREE` constant (linhas 22-173) é o alvo.
- `NAV_TREE` suporta 2 tipos de entry: item de topo `{label, routeName, page, icon, permission, ...}` OU grupo `{group, icon, children: [items]}`.
- **Sub-grupos aninhados NÃO existem.** Grep por `sub_group|subgroup|subChildren`: 0 hits.
- Grupo "Publicações" atual tem 10 sub-items todos `mlb.*` (linhas 128-144).
- Rota `/em-desenvolvimento` **não existe** em `routes/web.php`.
- Padrão `showBadge` existente (linhas 366-370): renderiza contador dinâmico via `badgeCounters[entry.showBadge]`.

## Gray areas identificadas (4)

Considerando lean planning preference do usuário ([[feedback_lean_planning]]), consolidei as 4 gray areas em uma AskUserQuestion multi-decisão com recomendações do Claude e o usuário aprovou tudo.

### Q1: Estrutura NAV_TREE para "Mercado Livre > Performance > (8 items) + Polos"

**Opções apresentadas:**

- **A. Achatar em 1 grupo com separator visual** *(recomendada)* — Grupo 'Mercado Livre' com todos items misturados
- **B. Nível: ML e Polos como grupos irmãos** — Polos sai da pasta ML
- **C. Implementar sub-grupos aninhados** — Mudança estrutural em `renderSidebar`

**Selecionado:** A — Achatar

**Racional:**
- Sistema atual só suporta 1 nível — implementar sub-grupos seria escopo desproporcional.
- Achatar preserva a intenção do briefing (tudo de ML numa pasta só) sem tocar renderização.
- Separator visual entre Performance e Polos comunica a distinção sem estrutura nova.

### Q2: Item Dashboard aponta pra qual rota?

**Opções apresentadas:**

- **A. Continua 'dashboard' (rota existente)** *(recomendada)* — Phase 58 renomeia depois
- **B. Já aponta pra 'mercadolivre.dashboard' (rota nova)** — Preparação antecipada

**Selecionado:** A

**Racional:**
- Lean, zero risco de link quebrado.
- Phase 58 é dona da decisão de rota canônica ML vs alias.

### Q3: Grupo 'Publicações' atual (10 sub-items MLB.*)

**Opções apresentadas:**

- **A. Renomear 'Publicações' → 'Publicação'** *(recomendada)* — Mantém sub-items intactos
- **B. Mover 'Publicações' pra DENTRO da pasta ML temporariamente** — Reflete realidade
- **C. Deixar como está** — Zero mudança

**Selecionado:** A

**Racional:**
- Alinha com briefing (Publicação = setor transversal, fora do ML).
- Não antecipa Phase 59 (auditoria + generalização dos sub-items MLB.*).
- Rename é mudança de 1 caractere no NAV_TREE.

### Q4: Visual dos stubs Shopee/Amazon

**Opções apresentadas:**

- **A. Badge 'Em breve' na sidebar + placeholder na página** *(recomendada)*
- **B. Sem badge, só placeholder** — Descobre "em desenvolvimento" só ao acessar
- **C. Item cinza/opaco (disabled visual) + placeholder** — Pode confundir com falta de permissão

**Selecionado:** A

**Racional:**
- Comunica claramente antes do clique.
- Badge é campo `badgeText` novo (estático), distinto de `showBadge` (contador dinâmico existente).

## Deferred ideas

Nenhuma scope creep apareceu durante a discussão — os requirements NAV-01..04 estavam bem locked antes.

Ideas noted para futuro:
- Sidebar reordering das áreas transversais (ordem de grupos secundários) — Phase 59 se relevante
- Ícones distintos oficiais dos marketplaces — phase futura de branding
- Search/filter na sidebar — se app crescer
- Multi-marketplace toggle no header — poderia ser feature complementar futura

## Downstream awareness

**Para gsd-planner:** CONTEXT.md tem esboço de código do NAV_TREE novo + descrição de 6 pontos concretos de refactor. Plan pode ir direto pra 2-3 waves:
- Wave 1: refactor NAV_TREE + extensão renderSidebar (divider + badgeText + defaultOpen)
- Wave 2: rota + página `EmDesenvolvimento.jsx`
- Wave 3: smoke/UAT

**Sem necessidade de research subagent** — o escopo é conhecido, sem incógnitas técnicas.
