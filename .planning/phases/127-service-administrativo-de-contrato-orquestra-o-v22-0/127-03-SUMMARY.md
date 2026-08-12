---
phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0
plan: 03
subsystem: contratos
tags: [laravel, eloquent, tdd, validation, clicksign]

# Dependency graph
requires:
  - phase: 127-01
    provides: coluna servico_id_em_andamento e trava de unicidade (empresa+servico) em contrato_assinaturas
  - phase: 127-02
    provides: caminho ClicksignClient que para no rascunho (D-02)
provides:
  - "ContratoDadosMinimosService::faltantes(Company) — lista os campos que bloqueiam geração de contrato"
  - "ContratoDadosMinimosService::estaPronta(Company) — bool de conveniência"
  - "Contrato de retorno estável [campo, rotulo, motivo, servico_id] para a Fase 131 consumir"
affects: [127-04, 127-05, 127-06, 127-07, 131]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Service puro sem I/O, sem construtor com dependência externa — regra de negócio pura consumível de qualquer contexto"
    - "Checagem de dado legado via getRawOriginal() em vez do acessor com cast, quando o cast do Eloquent mascararia o caso inválido (Carbon::parse('') vira 'agora')"

key-files:
  created:
    - app/Services/Contratos/ContratoDadosMinimosService.php
    - tests/Feature/Phase127/ContratoDadosMinimosTest.php

key-decisions:
  - "Não reusa PendenciasComerciaisService::calcular() — gated por is_origem_hubspot e não checa email/cnpj (Q5 da pesquisa, confirmado lendo o código)"
  - "5 bloqueantes, não 3: email_cliente, cnpj, nome_contato (do CONTEXT) + contrato de serviço ativo e data_contratacao (extensão do planejamento, lastro no texto literal do REDE-05)"
  - "data_vencimento vazia NÃO bloqueia — prazo indeterminado é contrato legítimo"
  - "Os 3 campos A DEFINIR (endereco, dia_vencimento, data_primeira_parcela) ficam fora da checagem por decisão do usuário (checkpoint 126-06)"
  - "CNPJ: presença + 14 dígitos após remover pontuação — sem dígito verificador (é o que o REDE-05 pede, projeto não tem helper de validação de CNPJ)"

patterns-established:
  - "Checagem de coluna NOT NULL potencialmente 'vazia por dado legado' deve ler getRawOriginal(), nunca o acessor com cast — evita que o cast do Eloquent mascare o valor inválido"

requirements-completed: [REDE-05]

# Metrics
duration: ~8min
completed: 2026-08-12
---

# Phase 127 Plan 03: ContratoDadosMinimosService Summary

**Service puro (sem I/O) que decide se uma empresa está pronta para gerar contrato — 5 bloqueantes (e-mail, CNPJ, nome do contato, serviço contratado, data de início) checados independente da origem da empresa, provado por `Http::assertNothingSent()`.**

## Performance

- **Duration:** ~8 min
- **Started:** 2026-08-12T09:53Z (commit anterior do plano 02)
- **Completed:** 2026-08-12T10:01Z
- **Tasks:** 2
- **Files modified:** 2

## Accomplishments
- `ContratoDadosMinimosService` criado em `app/Services/Contratos/`, sem construtor, sem dependência externa, sem I/O
- 13 testes cobrindo os 5 bloqueantes, os 2 não-bloqueantes (`data_vencimento` vazia, campos `A DEFINIR`) e o caso empresa não-HubSpot
- `Http::fake()` + `Http::assertNothingSent()` em `tearDown()` de todo teste — prova estrutural de que a checagem nunca toca rede
- Gate de código confirmado: `grep -v '^\s*[/*]' | grep -c is_origem_hubspot` retorna 0 — a checagem não depende de origem da empresa

## Task Commits

Cada task foi commitada atomicamente (TDD: RED → GREEN):

1. **Task 1: Testes da checagem de dados mínimos** - `c00cd9d1` (test) — RED, 13 testes falhando por classe ausente
2. **Task 2: ContratoDadosMinimosService** - `73a70647` (feat) — GREEN, 13/13 verdes

## Files Created/Modified
- `app/Services/Contratos/ContratoDadosMinimosService.php` - service puro com `faltantes()` e `estaPronta()`
- `tests/Feature/Phase127/ContratoDadosMinimosTest.php` - 13 testes, `Http::fake()` em todo teste

## Decisions Made
- Ver `key-decisions` no frontmatter. Resumo: 5 bloqueantes (não 3), `data_vencimento` não bloqueia, os 3 campos `A DEFINIR` ficam fora, CNPJ é presença+formato sem dígito verificador.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Teste 9 do plano usava `data_contratacao => null`, mas a coluna é NOT NULL no schema**
- **Found during:** Task 1/2 (RED→GREEN da checagem de `data_contratacao`)
- **Issue:** A migration `2026_05_26_120002_create_contratos_servico_table.php` declara `$table->date('data_contratacao')` **sem** `->nullable()`. Criar um `ContratoServico` com `data_contratacao => null` (como o texto do plano descrevia) estoura `QueryException` (NOT NULL constraint) antes mesmo do service rodar — o cenário "sem `data_contratacao`" descrito no plano não é criável do jeito literal.
- **Fix:** o teste passou a usar `data_contratacao => ''` (string vazia), que satisfaz a constraint NOT NULL do SQLite (não é `NULL`, é um valor válido porém vazio) e representa o caso real de dado legado sem data preenchida. No service, a checagem de `data_contratacao` passou a ler `$contrato->getRawOriginal('data_contratacao')` em vez do acessor com cast `date:Y-m-d` — o cast do Eloquent, via `Carbon::parse('')`, interpretaria string vazia como "agora" (`Carbon::now()`), mascarando exatamente o caso que a regra existe para pegar. Confirmado em tinker antes da mudança.
- **Files modified:** `app/Services/Contratos/ContratoDadosMinimosService.php`, `tests/Feature/Phase127/ContratoDadosMinimosTest.php`
- **Verification:** 13/13 testes verdes; teste específico (`contrato_ativo_sem_data_contratacao_reprova_com_servico_id`) confirma `motivo = 'ausente'` e `servico_id` preenchido.
- **Committed in:** `73a70647` (Task 2 commit)

---

**Total deviations:** 1 auto-fixed (1 bug de teste/design de dado legado)
**Impact on plan:** O bloqueante `data_contratacao ausente` continua funcionando exatamente como pedido pelo plano e pelo REDE-05 — só a forma técnica de representar "ausente" num schema NOT NULL mudou (string vazia + leitura raw em vez de null + acessor com cast). Nenhuma mudança de comportamento do ponto de vista da Fase 131 (que consome o contrato de retorno, não a forma interna de armazenamento).

## Issues Encountered
None além do deviation acima.

## User Setup Required
None - nenhuma configuração de serviço externo.

## Next Phase Readiness
- `ContratoDadosMinimosService::estaPronta()` / `faltantes()` prontos para o orquestrador (127-04+) chamar ANTES de qualquer `ClicksignClient` (Success Criteria 1).
- Contrato de retorno (`campo`, `rotulo`, `motivo`, `servico_id`) documentado no docblock da classe — é o formato que a Fase 131 vai consumir; qualquer mudança de chave ali quebra aquela tela futura.
- Nenhum bloqueio para os próximos planos desta fase.
- **REDE-05 NÃO marcado como concluído em `REQUIREMENTS-v22.md`** — o plano 127-06 também declara REDE-05 no frontmatter (é ele que integra a checagem no orquestrador, o que efetivamente cumpre "valida ANTES de gerar o PDF e criar o envelope"). Marcar aqui seria prematuro; deixar para o plano que fecha a integração. `requirements.mark-complete` também retornou `not_found` para a árvore raiz (gap conhecido, documentado em `project_requirements_raiz_desatualizado_v17`).

---
*Phase: 127-service-administrativo-de-contrato-orquestra-o-v22-0*
*Completed: 2026-08-12*

## Self-Check: PASSED

- FOUND: app/Services/Contratos/ContratoDadosMinimosService.php
- FOUND: tests/Feature/Phase127/ContratoDadosMinimosTest.php
- FOUND: c00cd9d1 (test RED)
- FOUND: 73a70647 (feat GREEN)
