---
phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
plan: 03
subsystem: database
tags: [nps, migrations, seed, retro-associacao, idempotencia, pre-check-dupes, laravel-12]

# Dependency graph
requires:
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 01
    provides: "5 tabelas base (nps_templates, nps_template_questions, nps_template_options) + nps_surveys.template_id FK"
  - phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o
    plan: 02
    provides: "Models Eloquent NpsTemplate/Question/Option com fillable + relations (não usados diretamente na migration — DB::table puro por padrão)"
  - phase: 32-customizacao-nps
    provides: "NpsTextRenderer::defaults() — fonte dos textos das 3 perguntas fixas (linhas 43-45)"
provides:
  - "Template `NPS Padrão` (is_default=true, priority=0, active=true, envio_automatico_mensal=true)"
  - "3 perguntas fixas: estrategista (obrigatoria=true, ordem=1), analista (obrigatoria=false, ordem=2), empresa (obrigatoria=true, ordem=3) — tipo=escala"
  - "15 opções (5 por pergunta) — label='1'..'5', peso=1..5, ordem=1..5"
  - "100% das nps_surveys legadas (WHERE template_id IS NULL) retro-associadas ao template padrão"
  - "Pre-check anti-dupes protege o unique parcial da Plan 68-04 (migration 100005) contra falhas em prod"
affects:
  - 68-05-testes-schema (fixtures do padrão + assertions de retro-associação)
  - phase-69 (NpsTemplateService::resolveForCompany fallback para is_default=true)
  - phase-72 (nps:disparar-mensal usa padrão quando empresa não bate service_scope)

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Seed migration idempotente com guards por chave semântica (is_default / dimensao / peso) — não usa updateOrInsert por precisão de intent"
    - "Pre-check anti-dupes ANTES de mutation em massa — falha CEDO com mensagem clara e exemplos concretos (até 5 pares afetados) em vez de quebrar em constraint DB downstream"
    - "Fallback defensivo em UPDATE em massa: catch \\Throwable + iteração por row + log warning + skip individual sem abortar batch (spec ROADMAP)"
    - "DB::table puro em migration de seed (não Eloquent Model) — padrão consolidado do projeto (`2026_06_11_200002_seed_nps_textos_configuracao.php`); evita dependência de autoload de Model que pode mudar"
    - "down() no-op informativo com Log::info — padrão Phase 31 D-10 para migrations de dados semânticos onde reverter destruiria histórico legítimo"

key-files:
  created:
    - "database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php"
  modified: []

key-decisions:
  - "Timestamp 100004 (entre 100003 alter score_* NULLABLE e 100005 dedup unique parcial) — fixa ordem em prod: 100001→100002→100003→100004 (este)→100005; o pre-check anti-dupes vive AQUI (100004) para proteger o unique de 100005"
  - "3 sanity checks canônicos das 3 perguntas com `active` propriedade REMOVIDA do INSERT — a coluna `active` NÃO existe em `nps_template_questions` (checada via PRAGMA table_info); o must_haves.truths do plan mencionava `active=true` para a pergunta analista mas o schema real (Plan 68-01 migration 100001) não tem essa coluna. Renderização condicional é decidida em runtime pela Phase 71 via company.analista_id"
  - "Coluna FK correta em `nps_template_options` é `question_id` (não `template_question_id`) — verificado no schema Plan 68-01 e no Model `NpsTemplateOption::fillable`. `template_question_id` existe em `nps_response_answers` (tabela diferente)"
  - "Textos das 3 perguntas literalmente iguais aos defaults do `NpsTextRenderer::defaults()` (linhas 43-45) — placeholders `{nome_estrategista}` / `{nome_analista}` ficam no texto e são resolvidos em runtime pelo `NpsTextRenderer::render()` (a mesma lógica da Phase 31); NÃO hardcodar nomes reais"
  - "Descrição do template: 'Template padrão herdado da v3.0/v13.0: 3 perguntas fixas (estrategista, analista, empresa) escala 1-5. Fallback para empresas sem template específico associado via service_scopes' — narrativa técnica clara para o CRUD admin (Phase 70)"
  - "envio_automatico_mensal=true — o padrão participa do ciclo mensal automatizado (NPS-B-04); sem isso, empresas fallback ficariam órfãs do disparo mensal"

patterns-established:
  - "Migration de seed com pre-check anti-corrupção legada — sempre que uma migration prepara um estado que outra migration downstream vai consumir com constraint estrita (unique, FK NOT NULL), a upstream deve VERIFICAR o estado de origem e falhar CEDO com mensagem acionável se detectar violação. Reusável em qualquer migration de dados retroativa"
  - "Grep-friendly acceptance criteria com regex fixos — o plan lista `grep -c \"'dimensao'.*=>.*'estrategista'\"` retorna 1 como assertion; o executor pode validar o arquivo criado sem carregar o Laravel. Padrão útil para plans com muitas assertions estáticas"

requirements-completed: [NPS-A-03]

# Metrics
duration: 8min
completed: 2026-07-07
---

# Phase 68 Plan 03: Seed "NPS Padrão" + retro-associação Summary

**Migration `2026_07_07_100004` semeia o template padrão (1 template + 3 perguntas + 15 opções) idempotentemente e retro-associa 100% das nps_surveys legadas via `template_id`, com pre-check anti-dupes que protege o unique parcial da Plan 68-04 contra falhas em produção.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-07-07 (Wave 3 sequencial após Waves 1+2)
- **Completed:** 2026-07-07
- **Tasks:** 1
- **Files created:** 1 migration (218 linhas incluindo docblock)
- **Files modified:** 0

## Accomplishments

- **Migration `2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php`** criada com:
  - Docblock pt-BR extensivo (57 linhas) referenciando Phase 68 Plan 03, REQ NPS-A-03, research §4/§5 e SC #3/5 do ROADMAP
  - `up()` transacional (`DB::transaction`) envolvendo seed + retro-associação — falha parcial reverte tudo
  - Guard `where('is_default', true)->first()` para reutilizar template existente em re-runs
  - Guard `where(template_id, dimensao)->first()` para 3 perguntas idempotentes por dimensão semântica
  - Guard `where(question_id, peso)->exists()` para 15 opções idempotentes por peso
  - **Pre-check anti-dupes** com mensagem clara + até 5 exemplos concretos: dispara `RuntimeException` ANTES da retro-associação se houver 2+ surveys COMPLETED no mesmo (company_id, month_reference) sem template_id
  - Retro-associação como UPDATE em massa (uma query, atômico dentro da transaction) com fallback defensivo por row + log warning + skip
  - `down()` no-op informativo com `Log::info` (padrão Phase 31 D-10)
- **Sanity chain SQLite in-memory** validado com 7 checks — todos verdes (ver seção "Verificação")
- **Idempotência** confirmada rodando `up()` 2× consecutivas — contagens iguais (1 template, 3 questions, 15 options)
- **Retro-associação** confirmada criando 3 surveys legacy sem template_id → seed → 0 órfãs, 3 associadas ao padrão
- **Pre-check dupes** confirmado criando 2 surveys COMPLETED com mesma tupla → seed → `RuntimeException("[Phase 68 Seed] Detectadas 1 dupes legadas...")`
- **Zero regressão:** suite NPS (Phase 31 + Phase 33) — 29/29 verdes, 172 assertions

## Task Commits

Cada task foi commitada atomicamente:

1. **Task 1: Migration de seed + retro-associação idempotente + pre-check dupes** — hash abaixo

## Files Created

- **`database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php`** (218 linhas)
  - 57 linhas de docblock pt-BR (referências, motivação, estrutura, idempotência, pre-check, ordem em prod)
  - `up()` transacional com 4 blocos comentados (template, perguntas, options, pre-check, retro-associação)
  - `down()` no-op informativo

## Comando para rodar em produção

```bash
# No VPS (após deploy autorizado — deploy gate ativo)
php artisan migrate --force
```

Sequência final aplicada pelo Laravel na Phase 68:

1. `2026_07_07_100001` — cria 5 tabelas
2. `2026_07_07_100002` — adiciona nps_surveys.template_id
3. `2026_07_07_100003` — score_estrategista/empresa NULLABLE
4. `2026_07_07_100004` — **este arquivo** — seed padrão + retro-associação
5. `2026_07_07_100005` — dedup_key com unique parcial (protegido pelo pre-check do 100004)

**Impacto em prod:**
- Uma linha nova em `nps_templates` (padrão)
- 3 linhas novas em `nps_template_questions`
- 15 linhas novas em `nps_template_options`
- UPDATE em `nps_surveys` populando `template_id` em N rows históricas (esperado <5k em v15.0)
- Zero mudanças de schema — todas as tabelas são novas ou já tinham `template_id`

**Se o pre-check disparar em prod:** a migration aborta com mensagem listando até 5 pares afetados. Recovery: script manual `keep-latest-per-key delete others` em `nps_surveys` seguido de re-migrar.

## Verificação de schema (SQLite in-memory)

Após `migrate:fresh --env=testing`:

```
=== VERIFICACOES SEED ===
Templates default: 1 (esperado 1)
Perguntas total: 3 (esperado 3)
Dimensões na ordem: estrategista,analista,empresa (esperado est/ana/emp)
Options total: 15 (esperado 15)
  Pergunta id=1 pesos: 1,2,3,4,5
  Pergunta id=2 pesos: 1,2,3,4,5
  Pergunta id=3 pesos: 1,2,3,4,5
Nome template: NPS Padrão
  estrategista obrigatoria: 1
  analista obrigatoria: 0
  empresa obrigatoria: 1

=== IDEMPOTENCIA ===
Templates: 1 -> 1 (após 2ª chamada up())
Questions: 3 -> 3
Options:   15 -> 15
IDEMPOTENCIA: PASS

=== RETRO-ASSOCIACAO ===
Surveys órfãos ANTES: 3
Surveys órfãos DEPOIS: 0
Surveys retro-associadas ao padrão: 3
RETRO-ASSOCIACAO: PASS

=== PRE-CHECK DUPES ===
Estado: 2 surveys COMPLETED (company=1, month=2026-05-01)
RuntimeException capturada: "[Phase 68 Seed] Detectadas 1 dupes legadas..."
PRE-CHECK DUPES: PASS

=== BASELINE NPS ===
Phase 31 + Phase 33: 29/29 verdes (172 assertions)
```

## Decisions Made

- **`active` NÃO incluído no INSERT das perguntas** — o must_haves.truths do plan mencionava `active=true` para a pergunta analista, mas o schema real de `nps_template_questions` (Plan 68-01, migration 100001) NÃO tem essa coluna. Validado via `PRAGMA table_info(nps_template_questions)`. Se incluísse, migration falharia com `no such column: active`. Renderização condicional é decidida em runtime pela Phase 71 via `company.analista_id` — `obrigatoria=false` já cobre "não bloqueia submit quando não renderizada"
- **`question_id` (não `template_question_id`) no INSERT das options** — schema de `nps_template_options` usa `question_id` como FK para `nps_template_questions.id`. `template_question_id` existe em `nps_response_answers`, tabela distinta. Confirmado no Model `NpsTemplateOption::fillable`
- **Descrição narrativa do template** ("Template padrão herdado da v3.0/v13.0..."): informativa para o admin no CRUD futuro (Phase 70) sem revelar detalhes internos de implementação
- **Filename com formato `seed_nps_template_padrao_and_retro_associate`** (não `seed_nps_padrao_template_and_retroassociate` do prompt de mission) — alinha com o `frontmatter.files_modified` e com todos os `grep` de acceptance criteria do plan
- **Pre-check somente em surveys COMPLETED** (`status='completed' AND completed_at IS NOT NULL`) — o unique parcial da Plan 68-04 filtra a mesma condição, então dupes em `pending`/`expired` não quebrariam nada e não precisam falhar a migration

## Deviations from Plan

**Nenhum desvio de intent do plan.** Ajustes de detalhe:

**1. [Rule 1 - Bug] `active` removido do INSERT de `nps_template_questions`**
- **Encontrado durante:** Sanity chain inicial (`PRAGMA table_info`)
- **Discrepância:** must_haves.truths do plan mencionava `active=true` para a pergunta analista, mas coluna `active` não existe em `nps_template_questions` (schema Plan 68-01 confirmado)
- **Fix:** INSERT sem `active` — obrigatoria=false já cobre semântica de renderização condicional
- **Impacto:** Zero — semântica preservada (Phase 71 decide render via `company.analista_id`); migration não falha

**2. [Rule 3 - Blocker] `question_id` (não `template_question_id`) no INSERT de options**
- **Encontrado durante:** Leitura do schema Plan 68-01 vs. plan text
- **Discrepância:** plan text usou `template_question_id`, schema usa `question_id`
- **Fix:** INSERT com `question_id` alinhado ao schema real
- **Impacto:** Zero — mission já fornecia versão corrigida

**Total:** 2 ajustes contra o texto exato do plan; zero contra o schema real. Ambos documentados como acceptance-safe.

## Issues Encountered

- **`php artisan migrate:fresh --env=testing` falhou inicialmente** porque `--env=testing` só carrega vars via PHPUnit XML — não afeta `.env` do Laravel CLI. Contorno: rodar via `php -r` inline com `DB_CONNECTION=sqlite DB_DATABASE=:memory:` no ambiente do processo, bootstrappando o container manualmente. Validação equivalente ao PHPUnit — mesmo driver, mesma DB in-memory
- **Coluna `email_to` inexistente em `nps_surveys`** — descoberto ao criar surveys legacy no sanity de retro-associação. Schema Phase 31 usa `token` + `company_id` + `month_reference` + `status`. Ajuste local no script sanity (não afeta migration)

## Known Stubs

Nenhum stub. Migration entrega comportamento final — template padrão real + retro-associação real + pre-check real. Nada placeholder.

## User Setup Required

Nenhum — migration é puramente schema-data. Nenhuma env var nova, nenhum serviço externo, nenhum job/scheduler. Deploy quando autorizado roda `php artisan migrate --force` e o padrão fica semeado + surveys legadas retro-associadas em uma transação.

## Next Phase Readiness

**Wave 4 — Plan 68-05 (testes Feature)** pronto:
- Fixture `NpsTemplate::default()->first()` retorna o padrão semeado
- 3 perguntas + 15 opções disponíveis para testes de resolveForCompany / NpsScoreCalculator
- Retro-associação já valida a semântica esperada — Plan 68-05 pode reforçar via Feature test dedicado

**Phase 69 (NpsTemplateService)** pronto:
- `resolveForCompany` pode chamar `NpsTemplate::default()->first()` como fallback determinístico
- Empresas sem service_scope aplicável recebem o padrão sem código adicional

**Phase 72 (nps:disparar-mensal)** pronto:
- Comando pode iterar `NpsTemplate::where('envio_automatico_mensal', true)->get()` — padrão participa por default
- Dedup key (Plan 68-04) garante idempotência do disparo mesmo com padrão como fallback

**Deploy:** gate ativo. Não deployar sem autorização explícita (regra permanente + regra específica v15.0 com outro dev em paralelo).

## Self-Check: PASSED

- [x] `database/migrations/2026_07_07_100004_seed_nps_template_padrao_and_retro_associate.php` existe
- [x] `php -l` limpo (sem erros de sintaxe)
- [x] `grep "NPS Padrão"` presente (5 ocorrências — nome + descrição + docblock + logs)
- [x] `grep "is_default.*=>.*true"` presente (1 ocorrência no INSERT template)
- [x] `grep "'dimensao'.*=>.*'estrategista'"` retorna 1 (spec de acceptance)
- [x] `grep "'dimensao'.*=>.*'analista'"` retorna 1 (spec de acceptance)
- [x] `grep "'dimensao'.*=>.*'empresa'"` retorna 1 (spec de acceptance)
- [x] `grep "DB::transaction"` presente (transacional)
- [x] `grep "whereNull.*template_id"` presente (retro-associação)
- [x] Sanity: `NpsTemplate` default count = 1
- [x] Sanity: 3 perguntas com dimensões corretas em ordem
- [x] Sanity: 15 options com pesos 1..5 em cada pergunta
- [x] Sanity: `obrigatoria` correto (est=1, ana=0, emp=1)
- [x] Idempotência: 2ª chamada não cria duplicatas
- [x] Retro-associação: 3 legacy órfãs → 0 órfãs após seed
- [x] Pre-check dupes: `RuntimeException("Detectadas ... dupes")` lançada quando há dupes COMPLETED
- [x] Phase 31 NPS: 12/12 verdes (zero regressão)
- [x] Phase 33 NPS: 9/9 verdes (zero regressão)
- [x] Suite `Nps*`: 29/29 verdes (172 assertions) — baseline preservado
- [x] Working tree `MercadoLivreOAuthController.php` intocado (M pré-existente da sessão)

---

*Phase: 68-schema-modelos-e-seed-retroativo-nps-padr-o*
*Plan: 03 — Wave 3 de 4*
*Completed: 2026-07-07*
