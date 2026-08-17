---
phase: 125-estrutura-de-dados-administrativa-v22-0
plan: 02
subsystem: database
tags: [laravel, eloquent, migration, clicksign, lgpd]

# Dependency graph
requires:
  - phase: 125-01
    provides: Tabela contrato_assinaturas + model ContratoAssinatura (FK do signatário aponta para ela)
provides:
  - Tabela contrato_assinatura_signatarios com papel, contato copiado, situação individual e evidência de autenticação do Gate #9
  - Model ContratoAssinaturaSignatario com constantes de papel (D-08) e situação (D-09)
  - ContratoAssinaturaSignatarioFactory (states assinou()/recusou()/daEcf())
  - ContratoAssinatura::signatarios()
affects: [126-integracao-clicksign, 129-webhook-clicksign, 130-liberacao-manual, 131-tela-administrativa-contratos]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Bloco de evidência de terceiro (data.signer da Clicksign) gravado inteiro em JSON + 3 colunas promovidas (assinado_em/ip_address/auths) para consulta por SQL sem abrir o JSON — mesmo princípio de servicos_snapshot congelado do 125-01, aplicado a evidência jurídica de terceiro"
    - "FK nullOnDelete + cópia deliberada dos dados (D-07): evidência jurídica não pode depender de FK viva — nome/e-mail/CPF sobrevivem à exclusão do usuário"
    - "user forceDelete() é o hard-delete real deste projeto (User usa SoftDeletes) — testes de nullOnDelete sobre users precisam de forceDelete(), não delete()"

key-files:
  created:
    - database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php
    - app/Models/ContratoAssinaturaSignatario.php
    - database/factories/ContratoAssinaturaSignatarioFactory.php
    - tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php
    - tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php
  modified:
    - app/Models/ContratoAssinatura.php

key-decisions:
  - "Índices e FKs nomeados à mão (cas_contrato_fk, cas_user_fk, cas_contrato_situacao_idx, cas_clicksign_signer_idx) — a FK autogerada para contrato_assinatura_id chegaria a 62 caracteres, 2 de margem para o limite 64 do MariaDB (armadilha 1059)"
  - "user_id nullable() explícito ANTES da FK nullOnDelete() — armadilha 1830, conferida junto na mesma leitura"
  - "papel e situacao são string + constantes, nunca enum de banco (D-04)"
  - "ContratoAssinaturaSignatario NÃO usa LogsActivity (T-125-10) — dado pessoal (nome/e-mail/CPF/IP/evidência) não duplica no activity_log; a auditoria de processo já vive em ContratoAssinatura"
  - "cascadeOnDelete do contrato para os signatários é deliberado (T-125-16) — não existe fluxo de exclusão de contrato nesta milestone"

patterns-established:
  - "Evidência de terceiro (webhook/API externa) sempre gravada inteira em JSON + colunas promovidas para leitura por SQL — replicável em contrato_assinatura_eventos (Fase 129, DADOS-03)"

requirements-completed: [DADOS-02]

# Metrics
duration: ~15min
completed: 2026-08-10
---

# Phase 125 Plan 02: Estrutura de dados administrativa — tabela contrato_assinatura_signatarios Summary

**Tabela `contrato_assinatura_signatarios` (papel/contato copiado/situação individual em vocabulário próprio + evidência de autenticação do Gate #9 gravada íntegra em JSON com 3 colunas promovidas) vinculada a `contrato_assinaturas` via FK nomeada à mão.**

## Performance

- **Duration:** ~15 min
- **Tasks:** 2/2 concluídas
- **Files modified:** 6 (5 criados, 1 modificado)

## Accomplishments
- Migration `create_contrato_assinatura_signatarios_table` com as 4 FKs/índices nomeados à mão (`cas_contrato_fk`, `cas_user_fk`, `cas_contrato_situacao_idx`, `cas_clicksign_signer_idx`) — todos ≤ 25 caracteres, evitando por larga margem a armadilha 1059
- `user_id` declarado `->nullable()` explícito, par obrigatório da FK `nullOnDelete` (armadilha 1830) — as duas conferidas na mesma leitura
- Colunas de evidência do Gate #9: `evidencia_signer` (bloco `data.signer` íntegro em JSON) + `assinado_em`/`ip_address`/`auths` promovidas para consulta por SQL sem abrir o JSON
- Model `ContratoAssinaturaSignatario` com `PAPEL_TODOS` (D-08) e `SITUACAO_TODAS` (D-09) como vocabulário próprio, sem interseção com `ContratoAssinatura::STATUS_TODOS`; deliberadamente **sem** `LogsActivity` (T-125-10)
- `ContratoAssinaturaSignatarioFactory` com states `assinou()` (fixture canônica do Gate #9, payload real do sandbox), `recusou()` e `daEcf(User $user)` (copia dados mesmo com vínculo, D-07)
- `ContratoAssinatura::signatarios()` — alteração puramente aditiva (confirmado por `git diff`)
- 11 testes verdes provando schema (5) e model (6): colunas, índices ≤ 64 chars, `user_id` nulo, ausência de tipo restrito, cascade do contrato, vínculo nas duas pontas, round-trip byte-a-byte da evidência, preservação dos dados copiados após apagar o usuário, vocabulário próprio de papel e situação

## Task Commits

Each task was committed atomically:

1. **Task 1: Migration contrato_assinatura_signatarios + prova de schema** - `0afc8622` (feat)
2. **Task 2: Model ContratoAssinaturaSignatario + factory + relação no contrato** - `61028647` (feat)

**Plan metadata:** _(este commit)_ docs: complete plan

## Files Created/Modified
- `database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php` - tabela com papel/contato/situação, evidência do Gate #9, FKs e índices nomeados à mão
- `app/Models/ContratoAssinaturaSignatario.php` - constantes de papel/situação, casts, relações, sem `LogsActivity`
- `database/factories/ContratoAssinaturaSignatarioFactory.php` - factory + states `assinou()`/`recusou()`/`daEcf()`
- `app/Models/ContratoAssinatura.php` - `signatarios()` (única mudança)
- `tests/Feature/Phase125/ContratoAssinaturaSignatarioSchemaTest.php` - 5 testes de schema
- `tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php` - 6 testes de model

## Decisions Made
Nenhuma decisão nova além das já travadas em `125-CONTEXT.md` (D-04, D-07, D-08, D-09). Único ponto de discrição do executor: forma exata do payload de `evidencia_signer` no state `assinou()`, copiado literalmente do `CLICKSIGN-SANDBOX-EMPIRICO.md` §3.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Teste `apagar_o_usuario_preserva_a_evidencia_de_quem_assinou` usava `delete()` em vez de `forceDelete()`**
- **Found during:** Task 2, primeira rodada de testes
- **Issue:** `User` usa `SoftDeletes` (`app/Models/User.php`). Um `$user->delete()` comum só grava `deleted_at` — não dispara o `DELETE` físico que a FK `nullOnDelete` (`cas_user_fk`) observa. O teste falhava afirmando `user_id` nulo quando na verdade continuava com o valor antigo, porque o registro do `User` nunca foi fisicamente apagado.
- **Fix:** Trocado para `$user->forceDelete()`, com comentário explicando o motivo. É o mesmo padrão de remoção definitiva já usado em `UserController` (`forceDelete()`) neste projeto.
- **Files modified:** `tests/Feature/Phase125/ContratoAssinaturaSignatarioModelTest.php`
- **Verification:** Teste passa; `user_id` volta `null` e `nome`/`email`/`cpf`/`evidencia_signer` continuam intactos (D-07 provada)
- **Committed in:** `61028647` (parte do commit da Task 2)

---

**Total deviations:** 1 auto-fixed (Rule 1 — bug de teste descoberto durante a própria execução, não do plano ou do schema)
**Impact on plan:** Nenhum. O ajuste foi interno ao arquivo de teste; nenhuma decisão travada (D-04 a D-09) foi reaberta ou contradita — o achado, na verdade, é um ponto de atenção útil para qualquer teste futuro de `nullOnDelete` sobre `User` neste projeto (registrável como aprendizado, já que `User::delete()` silenciosamente não exercita a FK).

## Issues Encountered
None além do item acima (já documentado como deviation).

## User Setup Required
None - nenhuma configuração de serviço externo. `mysqld` local não foi necessário (todos os testes rodaram em SQLite, conforme o plano previu).

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. As 7 threats registradas (T-125-10 a T-125-16) foram todas mitigadas ou aceitas exatamente como planejado:
- T-125-10 (dado pessoal duplicado no `activity_log`) — mitigado: `ContratoAssinaturaSignatario` não usa `LogsActivity`
- T-125-11 (perda de evidência ao apagar usuário) — mitigado e provado pelo teste (após correção do Rule 1 acima)
- T-125-12 (mass assignment) — mitigado: `$fillable` explícito
- T-125-13 (índice/FK acima de 64 chars) — mitigado: todos nomeados à mão, provado pelo teste
- T-125-14 (erro 1830 no deploy) — mitigado no schema; prova final em MariaDB fica para o plano 125-03
- T-125-15 (CPF em texto puro) — aceito, conforme o plano
- T-125-16 (cascade ao excluir contrato) — aceito, conforme o plano

## Next Phase Readiness
- `contrato_assinatura_signatarios` está pronta para a Fase 126 preencher `clicksign_signer_key` e mapear `papel`/`sign_as`
- `ContratoAssinatura::signatarios()` disponível para a tela da Fase 131
- A fixture canônica do Gate #9 (`ContratoAssinaturaSignatarioFactory::assinou()`) está pronta para as Fases 126 e 129 reutilizarem em testes
- ⚠️ Teste verde no SQLite **não prova o deploy** — a prova em MariaDB real (as duas armadilhas 1830/1059 sobrevivendo à criação da tabela) é escopo do plano 125-03, com checkpoint humano
- Plano 125-03 (prova em MariaDB) depende das duas tabelas desta fase existirem — estão prontas

---
*Phase: 125-estrutura-de-dados-administrativa-v22-0*
*Completed: 2026-08-10*

## Self-Check: PASSED

Todos os 6 arquivos criados/modificados encontrados no disco; os 2 commits de task (`0afc8622`, `61028647`) confirmados em `git log`.
