---
phase: 140-extrair-tabelas-progressivas-do-clicksign
plan: 04
subsystem: database
tags: [laravel, eloquent, migration, activitylog, clicksign, artisan-command]

requires:
  - phase: 140-03
    provides: comando `clicksign:extrair-tabelas` (só relatório) e a rodada real que mediu 85 contratos fechados, 49 tabelas, 29 valor fixo, zero casamentos com segurança
provides:
  - tabela `contrato_tabela_propostas` (proposta ≠ cobrança confirmada)
  - model `App\Models\ContratoTabelaProposta` (auditável via LogsActivity)
  - factory `ContratoTabelaPropostaFactory` com states `valorFixo()`, `comEmpresaCasada()`, `confirmada()`
  - opção `--gravar` em `ClicksignExtrairTabelas` que persiste cada linha lida como proposta `pendente`, sem sobrescrever conferência humana
affects: [140-05]

tech-stack:
  added: []
  patterns:
    - "Proposta pendente de confirmação humana (situacao pendente/confirmada/descartada) com updateOrCreate pela chave única do envelope — nunca sobrescreve confirmada/descartada"
    - "FK nullable() antes de constrained() com tabela explícita (constrained('companies')/constrained('users')) — convenção já usada em mlb_empresas/sugadores/onboarding_mapeamentos"

key-files:
  created:
    - database/migrations/2026_09_08_100000_create_contrato_tabela_propostas_table.php
    - app/Models/ContratoTabelaProposta.php
    - database/factories/ContratoTabelaPropostaFactory.php
    - tests/Feature/Phase140/Phase140PropostaSchemaTest.php
    - tests/Feature/Phase140/Phase140GravarPropostasTest.php
  modified:
    - app/Console/Commands/ClicksignExtrairTabelas.php

key-decisions:
  - "tipo_cobranca (string(16)) normaliza 'numeros_ilegiveis' (17 chars, não caberia) e 'arquivo ilegível' para o mesmo valor 'ilegivel' — do ponto de vista de quem confere depois, a ação é idêntica (abrir o contrato à mão)"
  - "Coluna motivo virou o campo genérico de aviso para conferência: guarda tanto o motivo de não-leitura quanto os avisos do parser (valor implausível ≥R$1bi, pagamento escalonado com múltiplos valores de parcela) — nunca perdidos, nunca uma média inventada"
  - "confianca nasce 'incerto' quando não há palpite (envelope ilegível) — nenhuma proposta fica sem confiança"
  - "Reescrito o comentário da migration para não conter a substring literal 'enum(' (o texto explicativo sobre a armadilha de coluna enum triggerava falso positivo no grep de verificação #3 do próprio plano)"

requirements-completed: [TAB-07]

duration: ~20min
completed: 2026-09-09
---

# Fase 140 Plano 04: Propostas de tabela de cobrança lidas do Clicksign Summary

**Tabela `contrato_tabela_propostas` + opção `--gravar` no comando de leitura: cada contrato lido vira uma proposta `pendente`, auditável, sem tocar em nenhuma tabela de cobrança.**

## Performance

- **Duration:** ~20 min (commits entre 09:06:46 e 09:14:53 BRT de 2026-09-09)
- **Tasks:** 2/2 completas
- **Files modified:** 6 (2 novos + 1 migration + 1 model + 1 factory + 1 comando alterado)

## Accomplishments
- `contrato_tabela_propostas` criada com as três armadilhas de MariaDB respeitadas (índices curtos nomeados, `nullable()` antes de `nullOnDelete()`, nenhuma coluna de tipo enumerado fechado)
- Model `ContratoTabelaProposta` auditável (`LogsActivity`, log `tabela_proposta`), com constantes de situação/confiança/tipo e scope `pendentes()`
- `--gravar` grava/atualiza proposta por `clicksign_envelope_id` (chave única) e NUNCA sobrescreve uma proposta já `confirmada`/`descartada`
- Schema comporta os dois formatos reais medidos na varredura completa: 29 de valor fixo (`valor_fixo` preenchido, `faixas` nulo) e 49 de tabela (`faixas` preenchido, `valor_fixo` nulo) — e o caso de pagamento escalonado (`valor_fixo` nulo, valores no `motivo`)
- Nenhuma linha de `empresa_faixas_faturamento`, `grupo_faixas_faturamento` ou `companies` é tocada — coberto por teste dedicado

## Task Commits

Cada tarefa seguiu o ciclo RED → GREEN (test → feat):

1. **Tarefa 1: tabela e model da proposta**
   - `a1c398d5` test(140-04): cobre schema de contrato_tabela_propostas (RED confirmado — migration temporariamente removida, teste falhou com "no such table")
   - `76c7f417` feat(140-04): tabela e model de proposta de tabela de cobranca (GREEN)
2. **Tarefa 2: opção `--gravar`**
   - `263bb07f` test(140-04): cobre opcao --gravar do comando de leitura (RED confirmado — `InvalidOptionException: --gravar option does not exist`)
   - `0da662c4` feat(140-04): opcao --gravar guarda a leitura como proposta pendente (GREEN)

## Files Created/Modified
- `database/migrations/2026_09_08_100000_create_contrato_tabela_propostas_table.php` - schema da proposta, três armadilhas de MariaDB documentadas e respeitadas
- `app/Models/ContratoTabelaProposta.php` - model auditável, casts de `faixas`/`candidatos` para array, constantes de situação/confiança/tipo, relações `company()`/`confirmadoPor()`, scope `pendentes()`
- `database/factories/ContratoTabelaPropostaFactory.php` - factory com states `valorFixo()`, `comEmpresaCasada()`, `confirmada()`
- `app/Console/Commands/ClicksignExtrairTabelas.php` - opção `{--gravar}`, métodos `gravarProposta()`/`tipoCobrancaProposta()`/`motivoProposta()`, resumo impresso ganha 2 linhas com `--gravar`
- `tests/Feature/Phase140/Phase140PropostaSchemaTest.php` - 8 testes de schema (colunas, unique, FK nula, casts, situação inicial, activity_log, relações, scope)
- `tests/Feature/Phase140/Phase140GravarPropostasTest.php` - 6 testes da opção `--gravar` (nada sem a flag, criação, idempotência, proteção de confirmada, cobrança intocada, relatório continua saindo)

## Decisions Made
- **`tipo_cobranca` normaliza dois casos do parser em um só valor `ilegivel`**: `numeros_ilegiveis` (17 caracteres — não cabe no `string(16)`) e "arquivo não legível" (download/extração falhou). Do ponto de vista da conferência humana a ação é a mesma: abrir o contrato manualmente.
- **`motivo` virou o campo geral de aviso**, não só "por que não deu para ler". Guarda o(s) valor(es) do pagamento escalonado (item 5 do prompt do usuário) e o aviso de valor implausível (item 4) — nunca perdidos, nunca inventados.
- **`confianca` nasce `incerto` quando não há palpite** (envelope cujo arquivo nem abriu) — nenhuma linha fica sem esse campo, já que a coluna não é nullable.
- **Reescrevi o comentário da migration** para não conter a substring literal `enum(` — o texto original explicando a armadilha #3 ("nunca `enum()`") disparava falso positivo na verificação #3 do próprio plano (`grep -n "enum(" ... — zero ocorrências`). O comentário agora descreve a armadilha sem usar essa sintaxe literal.

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário da migration continha a substring "enum(" e quebrava a verificação #3 do próprio plano**
- **Found during:** Tarefa 1, ao rodar a verificação do plano após o commit
- **Issue:** O comentário explicativo da armadilha #3 ("nunca `enum()`") continha literalmente a substring que a verificação `grep -n "enum(" ... — zero ocorrências` procura, gerando falso positivo mesmo sem nenhuma coluna `enum()` de fato na migration
- **Fix:** Reescrito o comentário para descrever a armadilha ("coluna de tipo enumerado do MySQL/MariaDB") sem usar a sintaxe literal `enum(`
- **Files modified:** `database/migrations/2026_09_08_100000_create_contrato_tabela_propostas_table.php`
- **Verification:** `grep -n "enum(" database/migrations/2026_09_08_100000_create_contrato_tabela_propostas_table.php` — zero ocorrências
- **Committed in:** `0da662c4` (junto do commit da Tarefa 2, feito antes do commit para já sair correto)

### Documentação — verificação #4 do plano não bate literalmente, mas a regra está satisfeita

A verificação #4 do plano espera `grep -n "nullable()->constrained()"` (sem argumento) presente nas duas FKs. A migration usa `->nullable()->constrained('companies')->nullOnDelete()` e `->nullable()->constrained('users')->nullOnDelete()` — com o nome da tabela explícito, seguindo a convenção já estabelecida no projeto para colunas de FK que não terminam em `_id` puro ou cujo nome não permite inferência segura (`confirmado_por`, mesmo padrão de `mlb_empresas.criado_por`, `sugadores.resolvido_por` e `onboarding_mapeamentos.confirmado_por`, todas com `constrained('users')` explícito). A **regra real** que a verificação protege — `nullable()` chamado ANTES de `constrained()`/`nullOnDelete()` (armadilha MariaDB 1830) — está presente e confirmada nas duas linhas (`grep -n "nullable()->constrained("` bate nas duas). Não alterei o código para forçar `constrained()` sem argumento porque isso quebraria a convenção do projeto e arriscaria mapear `confirmado_por` para uma tabela errada por inferência automática de nome.

---

**Total deviations:** 1 auto-fixado (Rule 1) + 1 nota de documentação (verificação #4 do plano imprecisa vs. convenção real do projeto)
**Impact on plan:** Nenhum impacto funcional. A tabela, o model e o comando fazem exatamente o que o plano pediu; só o texto de um comentário e a leitura literal de uma verificação precisaram de ajuste.

## Issues Encountered
Nenhum bloqueio. Checkpoint do 140-03 (que bloqueava este plano) foi respondido explicitamente pelo usuário nesta conversa, com os números medidos da rodada real completa (85 contratos, 49 tabelas, 29 valor fixo, 4 indefinidos, 3 ilegíveis, zero casamentos com segurança) substituindo os números do CONTEXT.md original — ver o próprio prompt do usuário para o registro dessa autorização.

## User Setup Required
None - nenhuma configuração de serviço externo necessária. Nenhum acesso a produção foi usado (Http::fake() em todos os testes).

## Next Phase Readiness
- `contrato_tabela_propostas` e o model estão prontos para o plano 140-05 (tela de conferência + escrita auditada em `empresa_faixas_faturamento`/`companies` após confirmação humana)
- O comando de leitura já pode rodar com `--gravar` numa nova varredura real (fora do escopo deste executor — envolve `plink`/API real) para popular a tabela de propostas antes da tela existir
- Nenhuma tabela de cobrança foi tocada; o gate de testes cresceu de 430→444 testes (2104→2165 asserções), 0 falhas

---
*Phase: 140-extrair-tabelas-progressivas-do-clicksign*
*Completed: 2026-09-09*

## Self-Check: PASSED

Todos os 7 arquivos declarados (migration, model, factory, 2 testes, comando, este SUMMARY) e os 4 commits de tarefa (`a1c398d5`, `76c7f417`, `263bb07f`, `0da662c4`) foram confirmados no disco/git. Nenhum item faltando.
