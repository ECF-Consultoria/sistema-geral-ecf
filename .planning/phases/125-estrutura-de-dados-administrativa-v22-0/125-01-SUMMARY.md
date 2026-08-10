---
phase: 125-estrutura-de-dados-administrativa-v22-0
plan: 01
subsystem: database
tags: [laravel, eloquent, migration, spatie-activitylog, clicksign]

# Dependency graph
requires:
  - phase: 124-refatoracao-roteador-empresa-operacional
    provides: EmpresaOperacionalRouter e PendenciasComerciaisService como ponto de roteamento pós-comercial
provides:
  - Tabela contrato_assinaturas com estado do contrato, datas do ciclo de vida e valores congelados
  - Model ContratoAssinatura com os 7 estados como constantes públicas (D-04/D-06)
  - Garantia dupla de unicidade de contrato em andamento por empresa (banco + código, D-01)
  - ContratoAssinaturaFactory (states emAndamento()/assinado())
  - Company::contratoAssinaturas()
affects: [126-integracao-clicksign, 129-webhook-clicksign, 130-liberacao-manual, 131-tela-administrativa-contratos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Coluna auxiliar nullable + índice único para D-01 (unicidade de estado 'em andamento' sobrevivendo a duplo clique/retry)"
    - "Hook Eloquent booted()/saving() sincroniza coluna auxiliar a partir de status, mas o índice único do banco é a garantia real, não o hook"
    - "servicos_snapshot em JSON congelado no instante da geração (D-10) — nunca relido ao vivo da tabela viva"

key-files:
  created:
    - database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php
    - app/Models/ContratoAssinatura.php
    - database/factories/ContratoAssinaturaFactory.php
    - tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php
    - tests/Feature/Phase125/ContratoAssinaturaModelTest.php
  modified:
    - app/Models/Company.php

key-decisions:
  - "Índices nomeados à mão (ca_company_andamento_uniq, ca_clicksign_envelope_uniq, ca_company_status_idx) — armadilha 1059 do MariaDB não é pega pelo SQLite dos testes"
  - "status é string(40) + constantes no model, nunca enum de banco (D-04)"
  - "company_id_em_andamento sem FK própria — é espelho da D-01, não relacionamento"
  - "servicos_snapshot excluído do logOnly do activity_log (T-125-03) para não duplicar dado sensível de valor contratado numa tabela de retenção diferente"

patterns-established:
  - "Garantia dupla D-01: índice único (garantia real) + guard de código emAndamentoDaEmpresa() (mensagem amigável) — replicável em qualquer fase futura com regra de 'no máximo um X ativo por Y'"

requirements-completed: [DADOS-01, DADOS-04]

# Metrics
duration: ~20min
completed: 2026-08-10
---

# Phase 125 Plan 01: Estrutura de dados administrativa — tabela contrato_assinaturas Summary

**Tabela `contrato_assinaturas` (status string + 7 estados, datas de envio/assinatura/liberação, valores congelados em JSON) com garantia dupla banco+código de no máximo um contrato em andamento por empresa.**

## Performance

- **Duration:** ~20 min
- **Tasks:** 2/2 concluídas
- **Files modified:** 6 (5 criados, 1 modificado)

## Accomplishments
- Migration `create_contrato_assinaturas_table` com os três índices nomeados à mão (armadilha 1059 evitada) e nenhuma coluna de prazo/`expira_em` (D-03 respeitada)
- Model `ContratoAssinatura` com os 7 estados (D-04/D-06) como constantes públicas, cast `array` em `servicos_snapshot` (D-10), hook `saving` sincronizando a coluna auxiliar da D-01 e o guard de código `emAndamentoDaEmpresa()`
- `ContratoAssinaturaFactory` cria contrato válido sem argumento nenhum, com states `emAndamento()`/`assinado()`
- `Company::contratoAssinaturas()` — alteração puramente aditiva (confirmado por `git diff`)
- 13 testes verdes provando schema (5) e model (8): unicidade de contrato em andamento, convivência de encerrados, round-trip do cast array, os 7 estados nunca colapsando entre si

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration contrato_assinaturas + prova de schema** - `15fb2beb` (feat)
2. **Task 2: Model ContratoAssinatura + factory + relação em Company** - `62732b7f` (feat)

**Plan metadata:** _(este commit)_ docs: complete plan

## Files Created/Modified
- `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` - tabela com status string, coluna auxiliar de andamento, índices nomeados à mão
- `app/Models/ContratoAssinatura.php` - os 7 estados como const, hook de sincronia, guard `emAndamentoDaEmpresa()`
- `database/factories/ContratoAssinaturaFactory.php` - factory + states `emAndamento()`/`assinado()`
- `app/Models/Company.php` - `contratoAssinaturas()` (única mudança)
- `tests/Feature/Phase125/ContratoAssinaturaSchemaTest.php` - 5 testes de schema
- `tests/Feature/Phase125/ContratoAssinaturaModelTest.php` - 8 testes de model

## Decisions Made
Nenhuma decisão nova além das já travadas em `125-CONTEXT.md` (D-01 a D-10). Único ponto de discrição do executor: nome dos dois testes de round-trip precisaram de ajuste fino para não colidir com a própria D-01 (ver Deviations).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] `HasFactory` faltando no model**
- **Found during:** Task 2, primeira rodada de testes
- **Issue:** `ContratoAssinatura::factory()` falhava com `BadMethodCallException` — o plano não mencionou explicitamente o trait `HasFactory`, mas todo model com factory no projeto o usa (`NpsSurvey`, molde consultado)
- **Fix:** Adicionado `use HasFactory, LogsActivity;` e o `use Illuminate\Database\Eloquent\Factories\HasFactory;`
- **Files modified:** `app/Models/ContratoAssinatura.php`
- **Verification:** Suíte voltou a rodar
- **Committed in:** `62732b7f` (parte do commit da Task 2)

**2. [Rule 1 - Bug] Teste de round-trip do JSON usava valor float "redondo"**
- **Found during:** Task 2, rodada de testes
- **Issue:** `servicos_snapshot` com `valor_mensal => 1500.00` falhava o `assertSame` — PHP/JSON perdem a marca decimal de um float "inteiro" no ciclo `json_encode`/`json_decode`, voltando como `int`. Não é bug do cast `array` do Eloquent, é limitação conhecida de serialização JSON de float.
- **Fix:** Trocado para `1500.75` no teste, com comentário explicando o motivo
- **Files modified:** `tests/Feature/Phase125/ContratoAssinaturaModelTest.php`
- **Verification:** Teste passa
- **Committed in:** `62732b7f`

**3. [Rule 1 - Bug] Teste `company_expoe_os_contratos_de_assinatura` violava a própria D-01**
- **Found during:** Task 2, rodada de testes
- **Issue:** O teste original criava dois contratos com status default (`rascunho`) para a mesma empresa — `rascunho` está em `STATUS_EM_ANDAMENTO`, então o segundo insert colidia com `ca_company_andamento_uniq` (comportamento CORRETO da D-01, não bug de produção). O teste estava mal desenhado.
- **Fix:** Segundo contrato criado com `status => STATUS_CANCELADO` (fora de `STATUS_EM_ANDAMENTO`), com comentário explicando por quê
- **Files modified:** `tests/Feature/Phase125/ContratoAssinaturaModelTest.php`
- **Verification:** Teste passa; a garantia D-01 continua provada por `empresa_nao_pode_ter_dois_contratos_em_andamento`
- **Committed in:** `62732b7f`

---

**Total deviations:** 3 auto-fixed (todos Rule 1 — bugs de teste/model descobertos durante a própria execução, não do plano)
**Impact on plan:** Nenhum. Os três ajustes foram internos aos arquivos de teste e ao trait faltante do model; nenhuma decisão travada (D-01 a D-10) foi reaberta ou contradita — o terceiro item, na verdade, confirma que a D-01 funciona como desenhado.

## Issues Encountered
None além dos três itens acima (já documentados como deviations).

## User Setup Required
None - nenhuma configuração de serviço externo. `mysqld` local não foi necessário (todos os testes rodaram em SQLite, conforme o plano previu).

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. Confirmado: nenhuma rota, controller ou input HTTP foi tocado nesta fase — a única fronteira é código PHP interno → banco, exatamente como registrado em `125-01-PLAN.md`.

## Next Phase Readiness
- `contrato_assinaturas` está pronta para a Fase 126 preencher `clicksign_envelope_id`/`clicksign_document_id` e para a Fase 129 preencher `assinado_em`
- `Company::contratoAssinaturas()` disponível para a tela da Fase 131
- ⚠️ Teste verde no SQLite **não prova o deploy** — a prova em MariaDB real (índices sobrevivendo à criação da tabela, sem erro 1059/1830) é escopo do plano 125-03, com checkpoint humano
- Plano 125-02 (tabela `contrato_assinatura_signatarios`) depende desta tabela existir — está pronta

---
*Phase: 125-estrutura-de-dados-administrativa-v22-0*
*Completed: 2026-08-10*
