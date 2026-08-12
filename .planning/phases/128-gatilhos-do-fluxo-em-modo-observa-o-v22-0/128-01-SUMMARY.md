---
phase: 128-gatilhos-do-fluxo-em-modo-observa-o-v22-0
plan: 01
subsystem: catálogo de serviços / gate administrativo
tags: [servicos, exige-contrato, D-03, FLUXO-08, migration]
dependency-graph:
  requires: []
  provides:
    - "servicos.exige_contrato (coluna boolean, default true, Polos isento)"
    - "Servico::exigeContrato()"
    - "Servico::scopeExigeContrato()"
  affects:
    - "app/Services/Clicksign/ContratoClicksignService.php (leitura futura nos planos 03/04)"
tech-stack:
  added: []
  patterns:
    - "dado configurável no catálogo em vez de `if` por nome de serviço espalhado no código"
    - "isenção gravada na própria migration, sem depender de UI/ação humana posterior"
key-files:
  created:
    - database/migrations/2026_08_13_100001_add_exige_contrato_to_servicos_table.php
    - tests/Feature/Phase128/ExigeContratoTest.php
  modified:
    - app/Models/Servico.php
decisions:
  - "Isenção de Polos gravada por `where('nome', 'Polos')->update(...)` na própria migration — nenhum `whereIn` com a lista dos 8 serviços que exigem contrato, porque `Gestão de ADS Shopee` não tem seed versionado e a grafia real em produção não foi medida (MariaDB local indisponível durante a pesquisa)."
  - "`default(true)` no schema é a rede de segurança dupla: cobre a exigência do SQLite (ADD COLUMN NOT NULL sem default falha) e garante que serviço novo nasce exigindo contrato sem precisar ser enumerado por nome."
  - "`exige_contrato` adicionado a `logOnly()` do activity log — mudar essa coluna decide se o serviço entra no gate administrativo, é auditável no mesmo espírito de `clicksign_template_id` (T-127-10)."
metrics:
  duration: "~25min"
  completed: "2026-08-12"
---

# Phase 128 Plan 01: Coluna `exige_contrato` no catálogo de serviços Summary

Coluna `servicos.exige_contrato` (boolean, default `true`) criada com `Polos` isentado direto na
migration, e exposta via `Servico::exigeContrato()`/`scopeExigeContrato()` como único ponto de
leitura autorizado — sem `if` por nome de serviço em nenhum outro lugar do código.

## O que foi construído

### Task 1 — Migration `2026_08_13_100001_add_exige_contrato_to_servicos_table`

- `up()` adiciona `exige_contrato` boolean `default(true)` após `clicksign_template_id`, e em
  seguida faz `DB::table('servicos')->where('nome', 'Polos')->update(['exige_contrato' => false])`.
- `down()` remove a coluna.
- Nenhum `whereIn` com a lista dos 8 serviços que exigem contrato — decisão deliberada da pesquisa
  da fase: `Gestão de ADS Shopee` não tem migration de seed versionada (cadastro manual do admin) e
  sua grafia real em produção não foi confirmada (MariaDB local fora do ar). Com `default(true)` +
  UPDATE só de `Polos`, uma eventual divergência de grafia é inofensiva — o serviço continua
  exigindo contrato, nunca fica isento por engano.
- Nenhuma armadilha de MariaDB do projeto se aplica: não é `enum`, não cria índice, não cria FK
  (mesma conclusão da migration-molde da Fase 127).

### Task 2 — `Servico::exigeContrato()` + cast + scope + teste (TDD)

Em `app/Models/Servico.php`:
- `exige_contrato` adicionado a `$fillable` e a `$casts` (`'boolean'`).
- `exigeContrato(): bool` — leitura pura da coluna, com docblock explícito dizendo que é o ÚNICO
  ponto de leitura autorizado (nenhum `if ($servico->nome === 'Polos')` em lugar nenhum).
- `scopeExigeContrato($query)` — segue o mesmo estilo de `scopeActive()`/`scopePorSetor()`.
- `getActivitylogOptions()->logOnly([...])` passou a incluir `exige_contrato` (Regra 2 — é
  superfície editável a partir da Fase 131 e decide se o serviço entra no gate administrativo).

`tests/Feature/Phase128/ExigeContratoTest.php` (5 testes, TDD RED→GREEN direto na primeira
tentativa, sem correção):
1. `polos_semeado_pela_migration_ja_nasce_isento_de_contrato` — prova o Success Criteria 0 no
   catálogo: o `Polos` semeado pela migration de 2026-05-27 já vem `exigeContrato() === false`
   depois da migration desta fase rodar.
2. `servico_novo_sem_informar_exige_contrato_nasce_exigindo` — default seguro.
3. `servico_com_exige_contrato_false_gravado_a_mao_nao_exige` — leitura pura da coluna.
4. `scope_exige_contrato_nunca_inclui_polos` — prova o scope no nível de query.
5. `exige_contrato_e_bool_php_nunca_inteiro_cru` — prova do cast.

## Deviations from Plan

Nenhum desvio de Rule 1-4. Único acréscimo por Regra 2 (funcionalidade crítica ausente): incluir
`exige_contrato` em `logOnly()` do activity log — não estava pedido explicitamente na `<action>` da
Task 2, mas é consistente com o padrão já aplicado a `clicksign_template_id` na mesma tabela
(T-127-10) e o próprio `<threat_model>` desta fase (T-128-01) trata a coluna como superfície de
tampering a mitigar.

## Verification

- `artisan test --filter=ExigeContratoTest` — 5 passed (6 assertions).
- `artisan test --filter=Phase127` — 66 passed (221 assertions), zero regressão na suíte da fase
  anterior que também mexe na tabela `servicos`.
- `artisan migrate --pretend` não pôde ser executado contra a conexão default (MariaDB local fora
  do ar nesta máquina — `SQLSTATE[HY000] [2002]`, mesmo sintoma já registrado em
  `.planning/learnings` para incidentes anteriores). A migration foi validada via `RefreshDatabase`
  em SQLite (mesma engine usada pelo `phpunit.xml` do projeto), rodando de fato em todos os 5 testes
  acima — cobertura equivalente ao `--pretend`, com a vantagem de provar o comportamento real (linha
  `Polos` isenta) em vez de só o SQL gerado.

## Known Stubs

Nenhum. `exigeContrato()`/`scopeExigeContrato()` são funcionais desde o commit — não há dado mockado
nem placeholder de UI.

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. `exige_contrato` entrou em `logOnly()`
como mitigação adicional de T-128-01 (já coberta pelo registro de ameaças do próprio plano).

## Self-Check: PASSED

- `database/migrations/2026_08_13_100001_add_exige_contrato_to_servicos_table.php` — FOUND
- `app/Models/Servico.php` — FOUND (modificado)
- `tests/Feature/Phase128/ExigeContratoTest.php` — FOUND
- Commit `adb76add` (migration) — FOUND em `git log`
- Commit `6c89392b` (model + teste) — FOUND em `git log`
