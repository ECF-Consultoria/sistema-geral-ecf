---
phase: 59
name: Desacoplamento de áreas transversais
milestone: v13.0
captured: 2026-07-06
---

# Phase 59 — DISCUSSION-LOG.md

## Scout inicial (pre-discussion)

**Método:** `grep -cE "marketplace|meli|mlb|Mlb|ml_store" <controller>`

**Achados críticos:**
- **6 áreas nominais do ROADMAP têm zero acoplamento:** UserController (0), NpsController (0), NotificacaoController (0), MeetingController (0), PpaController (0), GoalController (0)
- **3 hotspots reais concentram todo o acoplamento:** ComercialController (29), CompanyController (17), AdminController (10)
- **Controllers ML-dedicados (NÃO alvo):** MlbController (184), MlbImplementacaoController (88), SugadorController (81), PolosController (51), DashboardController (38 — já tratado Phase 58), MercadoLivreOAuthController (8), GrantController (14)

**Insight:** o escopo original do ROADMAP era baseado em suspeita ("as 6 áreas transversais podem ter acoplamento"). Scout revelou que a suspeita estava errada — o acoplamento real está em 3 controllers específicos (Comercial + Admin + Company), não nos 6 nominais. Isso muda drasticamente a estrutura da phase.

## Áreas discutidas

### 1. Escopo real da Phase 59

**Pergunta:** Quais áreas são alvo de FIX (não só documentação)?

**Opções apresentadas:**
- Comercial (29 refs) — principal ofensor
- Admin (10 refs)
- Company/CompanyController (17 refs)
- Só documentar em AUDIT.md, sem fixar código

**Escolha do usuário:** Comercial + Admin + Company (multi-select — os 3 hotspots).

**Notas:** usuário optou por fixar os 3 hotspots ao invés de deferir. Alinhado com "acertividade + praticidade" — resolver o problema real agora.

### 2. Validação de Publicação transversal (CROSS-02)

**Pergunta:** Como validar?

**Opções apresentadas:**
- Grep + doc no AUDIT.md (recomendado)
- Teste automatizado dedicado

**Escolha do usuário:** Grep + doc no AUDIT.md.

**Notas:** 0 empresas Shopee/Amazon hoje → teste dedicado seria mockup. Grep-based validation é rápida e suficiente. Phase 7 já fez decoupling (renomeou colunas legacy).

### 3. Formato do AUDIT.md (CROSS-01)

**Pergunta:** Como estruturar?

**Opções apresentadas:**
- Uma tabela por área em arquivo único (recomendado)
- Um AUDIT.md por área

**Escolha do usuário:** Arquivo único com tabela por área.

**Notas:** facilita comparação cross-área. Colunas: arquivo:linha, trecho, tipo, severidade, plano.

### 4. Profundidade dos fixes

**Pergunta:** Fixar tudo ou só acoplamentos incorretos?

**Opções apresentadas:**
- Só acoplamentos INCORRETOS (recomendado)
- Generalizar tudo pra pivot N:N

**Escolha do usuário:** Só acoplamentos incorretos.

**Notas:** migração N:N fica pra v14+ (Deferred). Divisor: código ML legítimo vs. código que assume ML implicitamente em contexto transversal.

### 5. Critério de regressão zero (CROSS-03)

**Pergunta:** Escopo dos testes de regressão?

**Opções apresentadas:**
- Suite completa verde (recomendado)
- Só Phase 57 + 58 baseline

**Escolha do usuário:** Suite completa verde.

**Notas:** fixes em controllers principais podem afetar outras áreas → suite completa é o cinto de segurança.

## Deferred ideas (registradas em CONTEXT.md)

- Migração completa pra pivot N:N `company_marketplaces` (v14+)
- Fix em componentes JSX que default ML (avaliar quando AUDIT flag HIGH)
- Refactor de MlbController pra separar transversal vs. específico (184 refs, mas todas legítimas)
- Coluna `marketplace_context` em atividades/logs

## Claude's discretion (não perguntado ao user)

- Estrutura interna do AUDIT.md (colunas exatas da tabela)
- Grep patterns exatos a usar no scout (`marketplace|meli|mlb|Mlb|ml_store`)
- Divisão em 3 plans (audit / fixes / regressão) — planner decide se compacta pra 2 ou 3
- Nomenclatura sugerida para renames (ex: `contarMlb` → `contarEmpresasAtivas`)

## Escopo REJEITADO da discussão

- Discutir cada uma das 7 áreas nominais originais separadamente — scout provou que não é necessário
- Discutir criação de novos modelos/tabelas — Phase 57 já entregou o modelo, Phase 59 é só cirurgia
- Discutir UI redesign — ROADMAP explicita "Sem redesign de tela"
