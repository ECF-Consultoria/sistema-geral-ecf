---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 06
subsystem: clicksign / orquestrador
tags: [laravel, service, tdd, clicksign, d-05, d-06, d-10, idempotencia]

# Dependency graph
requires:
  - phase: 127-01
    provides: "schema D-06 (servico_id, trava composta empresa+serviço), ContratoAssinatura::emAndamentoDoServico()"
  - phase: 127-03
    provides: "ContratoDadosMinimosService::faltantes()/estaPronta() — bloqueio ANTES de I/O"
  - phase: 127-05
    provides: "GerarContratoAssinaturaJob — worker de fila que monta o envelope e para no rascunho"
provides:
  - "App\\Services\\Clicksign\\ContratoClicksignService::iniciarParaEmpresa() — ponto único de entrada do módulo, o que o ROADMAP pede como Success Criteria 1 e 5"
  - "servicos_snapshot congelado no INSERT — a partir desta fase, esta é a ÚNICA classe que grava esse campo (a 126 só lê)"
affects: [131]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Captura de violação de constraint composta via QueryException::getCode() === '23000' (SQLSTATE, não errorInfo[1] do MySQL) — mesmo precedente de NpsController.php:1835/NpsGrupoController.php:301, agora com um terceiro consumidor no projeto"
    - "Guard de leitura (emAndamentoDoServico) como UX + constraint do banco como garantia real — dois níveis, o segundo é quem de fato impede a corrida entre workers"

key-files:
  created:
    - app/Services/Clicksign/ContratoClicksignService.php
    - tests/Feature/Phase127/ContratoClicksignServiceTest.php
    - tests/Feature/Phase127/IdempotenciaContratoTest.php

key-decisions:
  - "Ordem da sequência é o requisito, não detalhe de implementação: bloqueio → leitura de serviços ativos → criação+congelamento+catch → dispatch, nesta ordem, por contrato"
  - "delay(now()->addSeconds($i * 5)) é camada ADICIONAL ao bucket global 'clicksign-envelope' (127-05), não substituto — duas empresas diferentes disparando ao mesmo tempo ainda compartilham o mesmo rate limit de conta"
  - "Guard de UX (emAndamentoDoServico) roda ANTES do INSERT só para reportar 'ja_em_andamento' de forma amigável; a garantia real contra corrida é o catch da constraint composta"

requirements-completed: [REDE-05, CLICK-02, DADOS-06]

# Metrics
duration: ~25min
completed: 2026-08-12
---

# Phase 127 Plan 06: ContratoClicksignService::iniciarParaEmpresa() Summary

**O ponto único de entrada do módulo — bloqueia antes de qualquer I/O, congela o `servicos_snapshot` por serviço (não mais por empresa) e despacha um `GerarContratoAssinaturaJob` por contrato, com idempotência garantida pela constraint composta do banco (SQLSTATE 23000), não por checagem de código.**

## Performance

- **Duration:** ~25 min
- **Started:** 2026-08-12T10:35Z (commit anterior do plano 05)
- **Completed:** 2026-08-12T10:42Z
- **Tasks:** 2
- **Files modified:** 3 (1 criado em produção, 2 de teste)

## Accomplishments

- `ContratoClicksignService::iniciarParaEmpresa()` criado em `app/Services/Clicksign/`, integrando `ContratoDadosMinimosService` (127-03) ANTES de qualquer I/O — Success Criteria 1, provado por `Http::assertNothingSent()` + `Queue::assertNothingPushed()`.
- Empresa com N serviços `ContratoServico` ativos gera N `ContratoAssinatura` (um por serviço, D-06) e despacha N `GerarContratoAssinaturaJob` (127-05), com delay escalonado (`$i * 5` segundos) como camada adicional ao rate limit global.
- `servicos_snapshot` congelado no INSERT, com **exatamente um item** por contrato (D-06 + D-10) — prova executável de que mudar `valor_contratado` na origem DEPOIS do congelamento não afeta o snapshot já gravado (o precedente do `hs_mrr = 0` do HubSpot que já zerou 3 contratos deste projeto).
- Idempotência real (Success Criteria 5): chamar `iniciarParaEmpresa()` duas vezes para a mesma empresa não cria segundo contrato nem segundo job — nem quando um `ContratoAssinatura` em rascunho é criado manualmente por fora do guard de leitura, forçando a constraint do banco a agir. Captura por `QueryException` com `(string) $e->getCode() === '23000'` (SQLSTATE, portável entre SQLite dos testes e MariaDB de produção), copiada literalmente do precedente `NpsController.php:1835`.
- `enviado_em` nunca é tocado (D-02) — gate de código confirmado por `grep`, zero ocorrências fora de comentário.
- `DADOS-06` (prazo/lembrete efetivos por contrato) integrado: `iniciarParaEmpresa($company, prazoDias:, lembreteDias:)` grava as duas colunas quando informadas; ficam `null` (padrão da config) quando omitidas.

## Task Commits

Ciclo TDD (RED → GREEN):

1. **Task 1: Testes RED do ponto único e da idempotência** - `36109799` (test) — 11 testes falhando por `App\Services\Clicksign\ContratoClicksignService` inexistente.
2. **Task 2: ContratoClicksignService (GREEN)** - `6a9c226b` (feat) — 11/11 verdes.

## TDD Gate Compliance

- RED gate: `36109799` (test) ✓
- GREEN gate: `6a9c226b` (feat) ✓
- Nenhum commit `refactor` necessário — implementação passou de primeira contra o RED, sem ciclo de correção estrutural.

## Files Created/Modified

- `app/Services/Clicksign/ContratoClicksignService.php` - `iniciarParaEmpresa()`: bloqueio (passo 1), leitura de `ContratoServico` ativos (passo 2), criação por serviço com `servicos_snapshot` congelado e `catch (QueryException)` do SQLSTATE 23000 (passo 3), dispatch escalonado do job (passo 3d), retorno estável `{ok, faltando, criados, pulados}` (passo 4). Docblock de classe documenta as 3 fronteiras (não ativa, não expõe rota, não reusa `PendenciasComerciaisService`).
- `tests/Feature/Phase127/ContratoClicksignServiceTest.php` - 8 testes: bloqueio sem I/O (email/CNPJ/nome_contato), 2 serviços → 2 contratos/jobs, snapshot congelado por serviço (com prova de que mudança posterior na origem não afeta), serviço inativo ignorado, prazo/lembrete gravados vs. `null` por padrão, empresa sem serviço ativo recusada, forma estável do retorno.
- `tests/Feature/Phase127/IdempotenciaContratoTest.php` - 3 testes (Success Criteria 5): dupla chamada não duplica, a garantia real é a constraint (não o guard de leitura, provado criando manualmente um contrato "por fora"), teste estático confirmando que o `catch` usa SQLSTATE `'23000'` e não `errorInfo` (armadilha MySQL-specific que se comportaria diferente no SQLite dos testes).

## Decisions Made

Ver `key-decisions` no frontmatter. Resumo: a ordem da sequência (bloqueio → leitura → criação+congelamento+catch → dispatch) é requisito, não implementação livre; o `delay()` escalonado é camada adicional ao rate limit global (127-05), nunca substituto; o guard de leitura é só UX, a garantia real é a constraint composta do banco.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug de teste] CNPJ duplicado entre duas empresas no mesmo método de teste**
- **Found during:** Task 2, ao rodar a suíte GREEN pela primeira vez.
- **Issue:** Os testes `prazo_e_lembrete_sao_gravados_quando_informados_e_ficam_null_por_padrao` e `retorno_tem_forma_estavel_com_chaves_fixas` criavam uma segunda `Company` usando o CNPJ padrão do helper `companyCompleta()` (`12.345.678/0001-95`), que já tinha sido usado pela primeira empresa do mesmo teste — a coluna `companies.cnpj` tem índice único, e a segunda inserção estourava `UniqueConstraintViolationException` antes mesmo do service rodar.
- **Fix:** a segunda empresa de cada um desses dois testes passou a informar um CNPJ diferente (`98.765.432/0001-10`) explicitamente via `$overrides`.
- **Files modified:** `tests/Feature/Phase127/ContratoClicksignServiceTest.php`.
- **Verification:** 210/210 testes verdes (`Phase125 + Phase126 + Phase127`) na execução seguinte.
- **Committed in:** `6a9c226b` (Task 2 commit — a correção foi feita antes do primeiro commit GREEN, então não gerou commit próprio).

---

**Total deviations:** 1 auto-fixed (1 bug no próprio arquivo de teste, não no service).
**Impact on plan:** Nenhum — o bug estava na fixture do teste (dois `Company` com o mesmo CNPJ), não na lógica do `ContratoClicksignService`. O comportamento provado pelos testes é exatamente o descrito no plano.

## Known Stubs

Nenhum. Este plano é puramente backend (service), sem UI nem dado exibido ao usuário final — a tela consumidora é a Fase 131.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. Os 4 threats registrados (T-127-18 a T-127-21) foram todos endereçados:
- T-127-18 (duplo clique) — mitigado, provado pelo Teste 10.
- T-127-19 (valor mudando após emissão) — mitigado, provado pelo Teste 4.
- T-127-20 (DoS por dado incompleto) — mitigado, provado pelo Teste 1/7.
- T-127-21 (chamada sem autorização) — transferido para a Fase 131, registrado no docblock da classe.

## Issues Encountered

Nenhum além do deviation acima.

## User Setup Required

None - nenhuma configuração de serviço externo. Não foi feita nenhuma chamada real à Clicksign; nenhum deploy.

## Next Phase Readiness

- `ContratoClicksignService::iniciarParaEmpresa()` está pronto para a **Fase 131** (tela do Administrativo) chamar — contrato de retorno estável `{ok, faltando, criados, pulados}` documentado no docblock.
- A trilha completa do módulo agora fecha ponta a ponta em testes: `ContratoDadosMinimosService` (127-03) → `ContratoClicksignService` (este plano) → `GerarContratoAssinaturaJob` (127-05) → `ClicksignClient` (127-02). Nenhum passo restante nesta fase depende de I/O real.
- Nenhum bloqueio para o plano 127-07 (gate de envelope completo contra o sandbox real).

Suíte combinada `Phase125 + Phase126 + Phase127` = **210 testes verdes** (baseline 199 + 11 novos deste plano). Zero regressão. Nenhuma chamada real à Clicksign; nenhum deploy.

---
*Phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: app/Services/Clicksign/ContratoClicksignService.php
- FOUND: tests/Feature/Phase127/ContratoClicksignServiceTest.php
- FOUND: tests/Feature/Phase127/IdempotenciaContratoTest.php
- FOUND commit 36109799 (test RED)
- FOUND commit 6a9c226b (feat GREEN)
