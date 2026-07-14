# Phase 77: Setor Shopee organizacional — cargos e usuários (Felipe/Gustavo) (v16.0) - Context

**Gathered:** 2026-07-14
**Status:** Ready for planning
**Source:** `.planning/milestones/v16.0-brief.md` + mapeamento de setores/cargos + decisão do usuário (AskUserQuestion).

<domain>
## Phase Boundary

Criar o **Setor organizacional "Shopee"** (RBAC — tabela `setores`, eixo diferente do `servico.setor='shopee'` já existente) com seus cargos, vincular a permission `shopee.empresas`, e configurar os usuários reais Felipe e Gustavo com papéis por setor — tudo via **migration idempotente** (decisão travada), espelhando `2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php`.

**IN SCOPE:**
1. Migration idempotente: setor "Shopee" (slug `shopee`) + cargos `analista`/`estrategista` + `setor_permissoes: shopee.empresas`.
2. Vincular por email (idempotente, pula+loga se ausente): **Felipe** (`consultor.02@ecfconsultoria.com.br`) = cargo estrategista no setor Shopee + líder do setor Shopee (`setor_lideres`); **Gustavo** (`suporte.11@ecfconsultoria.com.br`) = cargo analista no setor Shopee + cargo analista no setor Performance (se existir).
3. Testes provando: setor/cargos/permissão criados; wiring por email funciona (fixture users); usuário ausente → skip sem erro; mesmo user com papéis em setores diferentes.

**OUT:** atribuição de responsáveis a EMPRESAS (isso é `company_users`, Fases 76/78); UI de gestão (Comercial/aba Shopee = Fase 78); NPS/bônus (79/80).
</domain>

<decisions>
## Implementation Decisions

### DEC-77-1 — Migration idempotente espelhando `seed_setor_comercial`
- `up()`: `firstOrCreate`-style por `slug='shopee'` em `setores` (nome "Shopee", `active=true`, `is_system=false`, `created_by=null`). Guardas `->where(...)->value('id')` / `->exists()` como no molde.
- Cross-driver (SQLite dos testes) + idempotente (re-rodar não duplica).
- `down()`: remover só o que foi inserido (setor, cargos, setor_permissoes, user_setores desses users, setor_lideres) — sem apagar a membership Performance pré-existente de Gustavo além do que a migration adicionou.

### DEC-77-2 — Cargos do setor Shopee: `analista` e `estrategista`
- Inserir em `cargos` com `setor_id`=Shopee, `slug` `analista`/`estrategista` (unique é `setor_id+slug` → duplicar os slugs do Performance é OK e esperado — ver `CompanyController::usersPorCargo` que faz `pluck` por haver slugs repetidos). `ordem`/`active` padrão.
- **Líder NÃO é cargo** — é membership em `setor_lideres` (dá `AUTO_LIDERANCA` automático via `User::effectivePermissions`).

### DEC-77-3 — Permissão do setor Shopee
- `setor_permissoes`: vincular `shopee.empresas` ao setor Shopee (a aba da Phase 75). Suficiente para esta fase; líder (Felipe) recebe `AUTO_LIDERANCA` (dashboard_setor etc.) automaticamente.

### DEC-77-4 — Wiring de Felipe e Gustavo por email (idempotente, skip se ausente)
- Resolver `user_id` por email; se não encontrar, `Log::warning` e pular (o banco de teste não tem esses emails — os testes usam fixture users com esses emails para provar a LÓGICA).
- **Felipe** (`consultor.02@ecfconsultoria.com.br`): `user_setores` (setor Shopee, `cargo_id`=estrategista, `is_principal=true`) + `setor_lideres` (setor Shopee). Unique `user_setores(user_id,setor_id)` → idempotente por par.
- **Gustavo** (`suporte.11@ecfconsultoria.com.br`): `user_setores` (setor Shopee, cargo analista, `is_principal=false`) + se o setor Performance (slug `performance`) e um cargo `analista` nele existirem, `user_setores` (setor Performance, cargo analista) — pular se já houver a linha ou se não existir o setor/cargo.

### Claude's Discretion
- `is_principal` de Gustavo (Performance vs Shopee) — default: manter o principal existente; se novo, Performance principal.
- Nome exato do arquivo de migration (`2026_07_14_*_seed_setor_shopee_e_usuarios.php`).
- Se busca Performance/analista por slug ou também valida `active`.
</decisions>

<constraints>
## Constraints
- **Testes em `tests/Feature/V16/`** (namespace `Tests\Feature\V16`).
- Migration idempotente + cross-driver + `down()` reversível.
- Não vincular responsáveis a empresas aqui (é `company_users`, Fase 78).
- Dev em paralelo — reconciliar antes de deploy.
- pt-BR nos comentários.
</constraints>

<canonical_refs>
## Canonical References
- **Molde:** `database/migrations/2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php` (DB::table idempotente: setor + setor_permissoes).
- Schema: `2026_05_20_200001_create_setores_table.php`, `_200003_create_cargos_table.php` (setor_id, slug, ordem, active, meta_publicacoes), `_200004_create_user_setores_table.php` (unique user_id+setor_id; cargo_id, is_principal, assigned_at), `_200005_create_setor_lideres_table.php` (PK setor_id+user_id).
- `app/Models/Setor.php` (membros/lideres/cargos relations), `app/Models/Cargo.php` (setor_id, slug), `app/Models/User.php:103-132` (setores/setoresLiderados/isLider) + `:140-191` (effectivePermissions, AUTO_LIDERANCA).
- `app/Support/Permissions.php` (`SHOPEE_EMPRESAS='shopee.empresas'` :66; AUTO_LIDERANCA :98-103; setor slug 'performance' :108).
- `CompanyController::usersPorCargo :206-222` (lê cargo por slug via user_setores — confirma que slugs de cargo repetem entre setores).
</canonical_refs>

<validation>
## Validation Architecture (Nyquist)
Testes Feature em `tests/Feature/V16/SetorShopeeSeedTest.php` (RefreshDatabase; rodar a migration):
1. Setor `shopee` existe (active, is_system=false); cargos `analista`/`estrategista` existem com `setor_id` do Shopee.
2. `setor_permissoes` tem `shopee.empresas` vinculada ao setor Shopee.
3. Wiring: criar fixture users com emails `consultor.02@...`/`suporte.11@...` ANTES de rodar a migration → após: Felipe em `user_setores` (Shopee, cargo estrategista) + `setor_lideres` (Shopee, `isLider` true); Gustavo em `user_setores` (Shopee, cargo analista) e (se Performance+analista existirem no cenário) em Performance.
4. Idempotência: rodar a migration 2× não duplica setor/cargos/permissão/vínculos.
5. Skip gracioso: sem os emails no banco, a migration completa sem erro (só loga).
6. `down()` remove o que inseriu sem quebrar.
</validation>

<deferred>
## Deferred
- Vincular Gustavo/Felipe como responsáveis de EMPRESAS específicas (company_users por-serviço) → Comercial/aba Shopee, Fase 78.
- Permissões adicionais do setor Shopee (relatórios etc.) → conforme Fases 79/80.
</deferred>

---
*Phase: 77 — v16.0 (v2)*
