---
phase: 202-renova-o-contratual-vencimento-de-contratos-e-oportunidade-d
plan: 01
status: complete
requirements: [REN-01, REN-02, REN-08]
commits:
  - 220ff651b
  - ffbcf9857
  - 7ca07044b
key-files:
  created:
    - database/migrations/2026_11_06_100000_add_data_vencimento_origem_to_contratos_servico.php
    - database/migrations/2026_11_06_100001_create_contrato_renovacoes_table.php
    - app/Models/ContratoRenovacao.php
    - app/Models/ContratoRenovacaoEvento.php
    - app/Services/Contratos/ContratoGrupos.php
    - app/Services/Contratos/VigenciaContrato.php
    - app/Services/Contratos/TerminoContratoService.php
    - app/Console/Commands/CalcularTerminoContratos.php
    - tests/Feature/Phase202/SchemaRenovacaoTest.php
    - tests/Feature/Phase202/TerminoContratoTest.php
  modified:
    - app/Models/ContratoServico.php
    - app/Services/ContratoPdfService.php
    - app/Http/Controllers/ComercialController.php
deviations:
  - Testes do comando (Task 3) escritos junto com TerminoContratoTest na Task 2 (commit ffbcf9857).
  - Sem teste novo específico para o término calculado na entrada do Comercial; só regressão.
---

# Fase 202 Plano 01: Fundação de dados da renovação — Summary

Término de contrato com origem rastreável (`manual|hubspot|calculada|renovacao`), tabelas de ciclo de renovação e de eventos, serviços de domínio (grupos, vigência, término) e o comando de backfill `contratos:calcular-termino` em dry-run por padrão.

## Commits

- `220ff651b` — origem do término e tabelas de ciclo/eventos de renovação
- `ffbcf9857` — término calculado por grupo de contrato (regra D-03)
- `7ca07044b` — comando `contratos:calcular-termino` e término na entrada do Comercial

## O que foi feito

- **Migrations:** `data_vencimento_origem` em `contratos_servico`; `contrato_renovacoes` e `contrato_renovacao_eventos`, com índices nomeados à mão (`contrato_renov_ciclo_unico` etc.), sem enum e `nullOnDelete` só em coluna nullable.
- **`ContratoServico`:** constantes `ORIGEM_TERMINO_*`; hook `saving` faz edição humana de `data_vencimento` virar `manual` (apagar zera a origem); `gravarTermino($data, $origem)` com flag transitória limpa no `finally`, então origem repetida (renovacao→renovacao, calculada→calculada, hubspot→hubspot) se mantém.
- **Models:** `ContratoRenovacao` (constantes de status, relações, `registrarEvento`) e `ContratoRenovacaoEvento`.
- **Serviços:** `ContratoGrupos` (agrupamento pelo serviço dono, extraído do closure do index), `VigenciaContrato` (regra única de meses; `ContratoPdfService::vigenciaEmMeses` agora delega), `TerminoContratoService` (início por assinatura do dono ou menor `data_contratacao`; data expressa no HubSpot vence o cálculo; manual nunca sobrescrito; grava sempre por `gravarTermino`).
- **Comando:** `contratos:calcular-termino {--aplicar} {--empresa=}`; sem `--aplicar` imprime "SIMULAÇÃO — nada foi gravado" e nada muda.
- **Comercial:** `preencherEmpresa` chamado após criar os `ContratoServico`, dentro da transaction.
- `ContratoAdminController` NÃO foi alterado (migração para `ContratoGrupos` fica no 202-06).

## Testes

- `SchemaRenovacaoTest`: 6 passed (18 assertions).
- `TerminoContratoTest`: 11 comportamentos de término + 3 do comando, todos verdes.
- `Phase126`: 165 passed (574 assertions).
- `Phase202 + Phase124RegressaoComercialTest + Phase135/OnboardingObserverCallSitesTest`: 30 passed.
- Rodada final ampla (Phase124KillSwitch, Phase128, Phase151/ComercEtapaNascimentoCadastroManual, Phase75/Phase75CadastroShopee, Phase126, Phase202): 240 passed (790 assertions), 0 falhas.

## Deviations from Plan

- Os testes do comando (Task 3) foram escritos junto com o `TerminoContratoTest` na Task 2 e entraram no commit `ffbcf9857`; o commit da Task 3 contém só o comando e o controller.
- Não há teste novo cobrindo especificamente o término calculado na entrada do Comercial; apenas as suítes de regressão do Comercial (verdes).
- Nenhum desvio de regra (1-4); nada contra produção, sem deploy, backfill não rodado com `--aplicar` fora de testes.

## Ressalvas

- Linha legada com data e origem null recebe `marcar_manual`; com `--aplicar` é regravada via `gravarTermino($data, 'manual')` sem mudar a data.
- Relatório sinaliza `data_contratacao_futura`; o cálculo segue normalmente.
- Falha por empresa em `preencherTodos` é logada em `ecf-webhooks` com `[Renovacao]` e o comando sai com FAILURE.
- O humano roda o backfill depois (plano 202-07); conferir por reconsulta: `SELECT data_vencimento_origem, COUNT(*) FROM contratos_servico WHERE ativo=1 GROUP BY 1`.

## Self-Check: PASSED

Arquivos e commits listados existem (verificado por execução dos testes e `git log`).
