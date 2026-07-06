---
phase: 59
name: Desacoplamento de áreas transversais
milestone: v13.0
captured: 2026-07-06
requirements: [CROSS-01, CROSS-02, CROSS-03]
---

# Phase 59 — CONTEXT

## Domain

Auditar acoplamento ML-only nas áreas transversais do sistema e generalizar onde a assumção implícita de Mercado Livre for INCORRETA (naming, filtros hardcoded, defaults de UI). Confirmar que Publicação (`pub.*`) já é transversal. Zero regressão em toda a suite.

**Reality check pós-scout (2026-07-06):** as 6 áreas listadas nominalmente no ROADMAP (Usuários, Setores, NPS, Notificações, Meetings, PPA, Goals) têm **zero refs** a `marketplace|meli|mlb|Mlb|ml_store` nos controllers principais — já são transversais na prática. O acoplamento real está concentrado em 3 controllers:

| Controller | Refs a ML | Tratamento |
|---|---|---|
| `ComercialController` | 29 | Auditar + fix acoplamentos incorretos |
| `CompanyController` | 17 | Auditar + fix (cadastro, defaults UI) |
| `AdminController` | 10 | Auditar + fix acoplamentos incorretos |

Controllers com refs porém legítimos (módulo ML dedicado) — **NÃO SÃO ALVO**: `MlbController` (184), `MlbImplementacaoController` (88), `SugadorController` (81), `PolosController` (51), `DashboardController` (38 — já tratado na Phase 58), `MercadoLivreOAuthController` (8), `GrantController` (14 — Phase 51 dedicated).

## Canonical refs

- [.planning/phases/57-modelo-de-dados-multi-marketplace/57-CONTEXT.md](../57-modelo-de-dados-multi-marketplace/57-CONTEXT.md) — modelo N:N `company_marketplaces` (base v13.0)
- [.planning/phases/58-dashboard-ecf-agregado-shells-por-marketplace/58-CONTEXT.md](../58-dashboard-ecf-agregado-shells-por-marketplace/58-CONTEXT.md) — Dashboard multi-marketplace (irmã Phase 59)
- [app/Http/Controllers/ComercialController.php](../../../app/Http/Controllers/ComercialController.php) — 29 refs a ML
- [app/Http/Controllers/CompanyController.php](../../../app/Http/Controllers/CompanyController.php) — 17 refs a ML
- [app/Http/Controllers/AdminController.php](../../../app/Http/Controllers/AdminController.php) — 10 refs a ML
- [app/Models/User.php](../../../app/Models/User.php) — helpers `hasPubPermission()`, `publication_role_legacy` (Phase 7 renomeou colunas)
- [app/Http/Middleware/EnsurePermission.php](../../../app/Http/Middleware/EnsurePermission.php) — gate de permissões
- [app/Models/Company.php](../../../app/Models/Company.php) — coluna flat `marketplace` + relação `marketplaces()` (Phase 57)
- [app/Models/CompanyMarketplace.php](../../../app/Models/CompanyMarketplace.php) — pivot N:N (Phase 57)

## Locked decisions

### 1. Alvo do fix — 3 controllers (Comercial, Admin, Company), NÃO as 7 áreas nominais

**Decisão:** A Phase 59 NÃO ataca Usuários/Setores/NPS/Notificações/Meetings/PPA/Goals — todas essas já são transversais (0 refs no scout). Foca em ComercialController + AdminController + CompanyController, os 3 hotspots reais identificados no scout.

**Racional:**
- Scout com `grep -E "marketplace|meli|mlb|Mlb|ml_store"` nos 6 controllers nominais retornou 0/0/0/0/0/0
- Scope original do ROADMAP era baseado em suspeita; scout revelou a realidade concreta
- Concentrar esforço nos 56 refs reais dos 3 controllers hotspot é mais efetivo que auditar áreas já limpas

### 2. Publicação transversal (CROSS-02) — validação via grep + doc no AUDIT.md

**Decisão:** Não criar teste dedicado. Validar via grep que:
- Permissões `pub.*` (`hasPubPermission()`, `DEFAULT_PUB_PERMISSIONS`, `ALL_PUB_PERMISSIONS`) não têm amarração implícita a ML
- Middleware `EnsurePermission` não filtra por marketplace
- Rotas `Route::middleware('pub.*')` não pressupõem ML

Documentar evidência (arquivo:linha + comentário) em seção dedicada do AUDIT.md.

**Racional:**
- 0 empresas Shopee/Amazon hoje — teste automatizado dedicado seria mockup, não valida cenário real
- Phase 7 já renomeou `publication_role → publication_role_legacy` (histórico de decoupling)
- Grep-based validation é rápida, verificável, sem overhead de manutenção

### 3. AUDIT.md — arquivo único com uma tabela por área (deliverable CROSS-01)

**Decisão:** Um único arquivo `59-AUDIT.md` com estrutura:

```markdown
# Phase 59 — AUDIT.md

## Metodologia
[grep patterns usados, critérios de classificação]

## Comercial (ComercialController.php)
| Linha | Trecho | Tipo | Severidade | Plano |
|---|---|---|---|---|
| 42 | where('marketplace', 'meli') | filtro hardcoded | HIGH | fix Phase 59 |
| 78 | 'default_mp' => 'meli' | default UI ML | MED | fix Phase 59 |
| 120 | var $mlbCount | naming | LOW | fix Phase 59 |

## Admin (AdminController.php)
[idem]

## Company (CompanyController.php)
[idem]

## Publicação — CONFIRMED transversal
[evidências grep-based]

## Sumário
- HIGH: N
- MEDIUM: N
- LOW: N
- Deferred pra v14+: N
```

**Racional:**
- Um arquivo = fácil de comparar/reviewar cross-área
- Tabela = machine-readable + human-scannable
- Coluna `Plano` explicita o que fica pra v14+ (migração N:N) vs. Phase 59

### 4. Profundidade dos fixes — só acoplamentos INCORRETOS

**Decisão:** Fix APENAS onde:
- Naming assume ML fora de contexto ML (ex: método `contarMlb()` no ComercialController quando deveria ser `contarEmpresasAtivas()`)
- Filtro hardcoded `where('marketplace', 'meli')` em query transversal (não específica de ML)
- Default UI pressupõe ML em cadastro/tela transversal (ex: dropdown pré-selecionando meli sem justificativa)

**NÃO fixar:**
- Lógica legítima de módulo ML (código em `MlbController`, `MlbImplementacaoController`, etc — é feature ML, não acoplamento)
- Refs a `marketplace='meli'` em contexto claramente ML-specific (relatório de vendedores ML, sync ML, etc)
- Migração para pivot N:N `whereHas('marketplaces', ...)` — fica pra v14+ (Deferred)

**Racional:**
- Escopo cirúrgico evita retrabalho quando v14+ vier
- Diferença entre "código ML" (feature) e "código que assume ML" (bug) é o divisor
- Aceita override do DASH-01 (Phase 58): agregação real cross-marketplace fica pra v14+ com dados reais

### 5. Regressão zero (CROSS-03) — suite completa verde

**Decisão:** Rodar `php artisan test` completo antes e depois dos fixes. Contagem de verdes tem que bater — se algum teste que estava verde ficar vermelho, fix bloqueia o merge.

**Racional:**
- Fixes em Comercial/Admin/Company podem tocar código usado por outras áreas
- Suite completa é o cinto de segurança realista
- Phase 57 (20 verdes) + Phase 58 (16 verdes) + baseline pré-v13 = universo total a preservar

## Escopo — O que ENTRA

1. **Plano 01 — Audit:** scout completo de Comercial+Admin+Company+Publicação, produz `59-AUDIT.md` com tabelas + severidade + plano por linha
2. **Plano 02 — Fixes:** aplica as ações classificadas como `fix Phase 59` no AUDIT.md
3. **Plano 03 — Regressão:** rodar `php artisan test` completo antes/depois, capturar contagens, corrigir qualquer teste que quebrou por causa dos fixes

## Escopo — O que NÃO ENTRA

- **Auditoria das 6 áreas nominais do ROADMAP** (Users, Setores, NPS, Notif, Meetings, PPA, Goals) — scout confirmou 0 refs, já são transversais
- **Migração pra pivot N:N `whereHas('marketplaces', ...)`** — v14+
- **Fix em controllers ML-dedicados** (MlbController, MlbImplementacao, Sugador, Polos, Grant) — código legítimo de módulo, não acoplamento
- **Fix em Frontend/JSX** de forma abrangente — se AUDIT achar acoplamento crítico em componente transversal (ex: `<CompanyForm>` default ML), fix pontual; sem varredura JSX completa
- **Teste automatizado dedicado pra Publicação transversal** — grep + doc no AUDIT.md é suficiente

## Deferred ideas

- **Migração completa pra pivot N:N `company_marketplaces`** — todas as queries `where('marketplace', 'meli')` viram `whereHas('marketplaces', fn($q) => $q->where('marketplace', 'meli'))`. Fica pra v14+ quando Shopee/Amazon integrarem e o custo/benefício justificar
- **Fix em componentes JSX que default ML** — se o AUDIT.md flag como HIGH, considerar fase futura de UI polish
- **Refactor de MlbController pra separar transversal vs. específico** — 184 refs, mas todas legítimas em módulo ML dedicado
- **Adicionar coluna `marketplace_context` em atividades/logs** — atualmente logs não têm contexto de marketplace explícito

## Code context

**Arquivos que serão modificados:**
- `app/Http/Controllers/ComercialController.php`
- `app/Http/Controllers/AdminController.php`
- `app/Http/Controllers/CompanyController.php`
- Possivelmente views/JSX de cadastro empresa (se AUDIT achar default ML)

**Arquivos que serão criados:**
- `.planning/phases/59-desacoplamento-de-reas-transversais/59-AUDIT.md` (deliverable CROSS-01)

**Padrões a preservar:**
- Comentários em pt-BR (CLAUDE.md)
- Design tokens ECF (se tocar UI)
- Métodos `Company::marketplaces()` (pivot N:N Phase 57) preferido sobre coluna flat quando fizer sentido
- Convenções de nomenclatura existentes (`isMlb()` é OK em contexto ML; `contarEmpresasAtivas()` melhor que `contarMlbCadastradas()` em contexto transversal)

## Risk summary

| Risco | Severidade | Mitigação |
|---|---|---|
| Fix em Comercial quebra fluxos usados por outra área | Média | Suite completa verde é gate obrigatório |
| AUDIT classifica errado (fix vs. defer) | Média | Uma linha específica de "Plano: fix Phase 59" por item; user reviewa antes do Plano 02 |
| Naming rename introduz merge conflict com outra frente | Baixa | Stash + commit atômico + comunicar no PR |
| Fix generaliza demais e vira o refactor N:N (v14+) | Média | Regra clara CONTEXT §4: só onde há assumção INCORRETA; migração N:N é deferred |

## Success criteria

1. `59-AUDIT.md` existe com tabelas para Comercial + Admin + Company + seção Publicação transversal com evidências grep
2. Cada linha da tabela AUDIT tem: arquivo:linha, trecho, tipo, severidade, plano (fix agora vs. defer v14+)
3. Todos os itens marcados `Plano: fix Phase 59` no AUDIT.md estão resolvidos no código
4. `php artisan test` completo passa com mesma contagem de verdes que antes dos fixes (ou mais)
5. Publicação (`pub.*`) confirmada transversal em seção dedicada do AUDIT.md com refs `arquivo:linha` como evidência
6. Zero regressão em testes Phase 57 (20) + Phase 58 (16) = 36 tests baseline
7. Requisitos CROSS-01/02/03 rastreáveis via `requirements` frontmatter dos plans
