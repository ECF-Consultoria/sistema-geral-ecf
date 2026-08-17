---
quick_id: 260814-cro
type: quick
mode: fix
phase_ref: 130-rede-de-seguranca
status: completo
---

# 260814-cro: Corrigir o relógio do alerta de contrato preso — Summary

**Uma frase:** `ContratosPresosService::dataBase()` usava `updated_at` nos estados
`recusado`/`expirado`/`cancelado`/`erro`/`assinado` (fallback), e o próprio alerta
zerava esse relógio ao gravar `ultimo_alerta_em` — destruindo a D-04. Corrigido para
`enviado_em ?? created_at` em todos os estados sem coluna própria, sem migration.

## O que mudou

### `app/Services/Contratos/ContratosPresosService.php`
- `dataBase()`:
  - `STATUS_ASSINADO`: era `assinado_em ?? updated_at` → agora `assinado_em ?? enviado_em ?? created_at`.
  - `default` (`recusado`/`expirado`/`cancelado`/`erro`): era `updated_at` → agora `enviado_em ?? created_at`.
  - Docblock reescrito em pt-BR explicando por que `updated_at` está proibido no match
    (qualquer escrita no contrato — cooldown do alerta, retry de PDF, sync de
    signatário — bumpa `updated_at` e zeraria o contador).
- Nada mudou em `limiarDias()`, `causa()`, `estaPreso()` ou `listar()`.

### `app/Console/Commands/ClicksignAlertarPresos.php`
- Antes de `$contrato->update(['ultimo_alerta_em' => now()])`, agora seta
  `$contrato->timestamps = false;` — a gravação do cooldown deixa de bumpar
  `updated_at`. Defesa em profundidade: mesmo com `dataBase()` já corrigida, o
  alerta não tem motivo para sujar o timestamp.
- Deliberadamente **não** usa `updateQuietly()` — o docblock de
  `ContratoAssinatura::booted()` avisa que isso desliga o hook `saving` em
  silêncio e pode dessincronizar `company_id_em_andamento`/`servico_id_em_andamento`,
  travando a empresa para gerar contrato novo. `timestamps = false` preserva os
  eventos do model.

### Testes
- `tests/Feature/Phase130/ContratosPresosServiceTest.php`: 2 testes novos de
  regressão — `test_bump_de_updated_at_nao_zera_o_relogio_de_contrato_recusado`
  (escrita no contrato não zera o relógio) e
  `test_estado_sem_enviado_em_conta_desde_created_at` (fallback `created_at`
  quando não há `enviado_em`). Confirmado RED antes da correção (falharam:
  `estaPreso()` `false` e `diasParado()` `0` em vez de `≥ 10`).
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php`: as duas fixtures que
  envelheciam `updated_at` via `forceFill()` foram trocadas — `recusado` agora usa
  `enviado_em = now()->subDays(10)` no `create()` (caso realista: enviado e
  recusado); `erro` mantém `enviado_em` nulo e envelhece `created_at` (caso
  realista: falhou antes do envio) — cobrindo os dois ramos da nova `dataBase()`.
  Comentários que ensinavam o bug ("dataBase() de um default é updated_at")
  reescritos.
- `tests/Feature/Phase130/AlertaContratoPresoTest.php`: docblock e implementação
  do helper `contratoPresoAntigo()` simplificados — não precisa mais envelhecer
  `updated_at` à mão; `enviado_em` já é a data base para todos os estados usados
  no arquivo. Asserções não tocadas.
- `tests/Feature/Phase130/AlertaCooldownTest.php`: novo teste end-to-end
  `test_contrato_preso_continua_preso_depois_de_alertar_e_repete_apos_o_intervalo`
  — roda o comando, reconsulta o contrato fresco do banco e prova as 3 asserções
  do coração da correção: (1) `estaPreso()` continua `true` e `diasParado()`
  continua `≥ 10` depois do alerta; (2) `updated_at` não foi bumpado pela gravação
  do carimbo; (3) recuado o carimbo além do intervalo, o alerta dispara de novo.

## Decisão de schema

**Sem migration**, conforme já decidido no PLAN.md: a tabela `contrato_assinaturas`
não tem `recusado_em`/`expirado_em`/`cancelado_em`. A data estável para os estados
sem coluna própria é `enviado_em ?? created_at` — semanticamente correto para a
D-03/D-05 ("empresa sem liberação há tempo demais", contada desde que o processo
começou, não desde a última mexida no registro). Uma coluna nova só mudaria o marco
para "estado mudou" (pergunta errada) e ainda exigiria backfill dos contratos já em
produção. Para quem for mexer em `ContratosPresosService::dataBase()` depois: **não
reintroduzir `updated_at` no match** — o docblock do método deixa o aviso.

## Suíte `--filter=Phase130`

- **Antes** (baseline, medido nesta execução): 79 passed (302 assertions).
- **Depois da Task 1**: 81 passed (309 assertions).
- **Depois da Task 2**: 82 passed (317 assertions) — bate com o `≥ 82` exigido.

## Suíte `--filter=Phase129` (regressão)

80 passed (235 assertions) — igual ao baseline, sem regressão.

## Deviations from Plan

Nenhuma. O plano foi executado como escrito: as duas tasks, a ordem RED→GREEN em
cada uma, e as duas fixtures indicadas foram corrigidas exatamente como descrito.
Confirmado durante a Task 2 que o teste end-to-end de fato passava mesmo antes de
aplicar `timestamps = false` (a igualdade de `updated_at` coincidia por causa da
precisão de segundo do cast `datetime` do MySQL/SQLite) — o próprio PLAN.md previu
esse cenário e instruiu manter o teste como defesa em profundidade, o que foi
seguido.

## Commits

- `098f6e34` — fix(quick-260814-cro): dataBase() para de usar updated_at (relógio do alerta)
- `3aa7d504` — fix(quick-260814-cro): gravação do cooldown não suja mais updated_at + prova a repetição da D-04

## Self-Check: PASSED

- `app/Services/Contratos/ContratosPresosService.php` — FOUND, `updated_at` só em comentário (conferido por `grep`).
- `app/Console/Commands/ClicksignAlertarPresos.php` — FOUND, `timestamps = false` presente antes do `update()`.
- `tests/Feature/Phase130/ContratosPresosServiceTest.php` — FOUND, 2 testes novos.
- `tests/Feature/Phase130/LiberacaoManualEstadoRealTest.php` — FOUND, fixtures atualizadas.
- `tests/Feature/Phase130/AlertaContratoPresoTest.php` — FOUND, helper simplificado.
- `tests/Feature/Phase130/AlertaCooldownTest.php` — FOUND, teste end-to-end novo.
- Commit `098f6e34` — FOUND em `git log`.
- Commit `3aa7d504` — FOUND em `git log`.
