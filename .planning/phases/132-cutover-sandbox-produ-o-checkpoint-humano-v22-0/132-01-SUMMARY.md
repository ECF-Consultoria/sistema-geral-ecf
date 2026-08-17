---
phase: 132-cutover-sandbox-produ-o-checkpoint-humano-v22-0
plan: 01
subsystem: infra
tags: [clicksign, config, feature-flag, laravel, env, checklist]

requires:
  - phase: 131-tela-administrativa-completar-cadastro-contratos-badge-comer
    provides: "ContratoAdminController::show()/gerarContrato() e ContratoDetalhe.jsx (o ponto único de geração e a tela que este plano estende)"
  - phase: 130-rede-de-seguran-a-reconcilia-o-alerta-e-libera-o-manual-v22-
    provides: "clicksign:reconciliar e a rede de segurança que o gate empírico #10 retoma"
provides:
  - "ClicksignAmbiente — normalização de grafia de CLICKSIGN_ENV (D-01), fecha a armadilha código-português vs. roadmap-inglês"
  - "CongelamentoEmissaoService — interruptor próprio que fecha a janela entre a troca de credenciais e a aprovação final (D-07)"
  - "132-GATE.md — roteiro versionado da virada, para os planos 132-02/03/04 preencherem"
affects: [132-02, 132-03, 132-04]

tech-stack:
  added: []
  patterns:
    - "Classe estática pura sem I/O para regra normalizável (molde ClicksignHmacVarredura), evitando testar config() em cache"
    - "Interruptor via Configuracao (chave própria, string '1'/'0', ausência = desligado) — mesmo molde de EmpresaOperacionalRouter::CHAVE_BLOQUEIO, mas propósito distinto"

key-files:
  created:
    - app/Support/Clicksign/ClicksignAmbiente.php
    - app/Services/Clicksign/CongelamentoEmissaoService.php
    - tests/Feature/Phase132/ClicksignAmbienteTest.php
    - tests/Feature/Phase132/EmissaoCongeladaTest.php
    - .planning/phases/132-cutover-sandbox-produ-o-checkpoint-humano-v22-0/132-GATE.md
  modified:
    - config/services.php
    - .env.example
    - app/Http/Controllers/ContratoAdminController.php
    - resources/js/Pages/Admin/ContratoDetalhe.jsx

key-decisions:
  - "D-01: aceitar as 4 grafias producao/produção/production/prod para CLICKSIGN_ENV, com grafia desconhecida caindo sempre no painel de teste (default seguro), em vez de padronizar numa única string"
  - "D-07: interruptor PRÓPRIO (contratos_emissao_congelada), não reaproveitar administrativo_bloqueio_ativo da Fase 128 — propósitos diferentes (geração de contrato vs. liberação ao operacional)"

patterns-established:
  - "Regra de normalização de env var mora em classe estática testável, não inline no config() (que fica em cache e não é testável sem reload)"
  - "Chave de interruptor de configuração sempre nasce ausente/desligada; nunca migration com seed"

requirements-completed: [SC-132-1, SC-132-4, SC-132-5, GATE-EMP-03, D-01, D-02, D-06, D-07]

duration: 7min
completed: 2026-08-17
---

# Fase 132 Plano 01: Fecha os dois buracos de código antes da virada Summary

**Normalizador de grafia de CLICKSIGN_ENV (D-01) + interruptor de emissão de contratos
(D-07) fecham as duas falhas silenciosas que a virada sandbox→produção abriria, mais o
roteiro versionado `132-GATE.md` que os próximos 3 planos vão preencher.**

## Performance

- **Duration:** 7 min (commits entre 11:37 e 11:44 de 2026-08-17)
- **Tasks:** 3/3 completas
- **Files modified:** 7 (4 criados, 3 modificados) + `132-GATE.md`

## Accomplishments

- `ClicksignAmbiente::ehProducao()`/`painelUrl()` fecham a armadilha de grafia: o código
  antigo comparava só `=== 'producao'` (português), mas o ROADMAP manda escrever
  `production` (inglês) — quem seguisse o roadmap à risca caía sempre no painel de TESTE
  mesmo já em produção, em silêncio. Agora as 4 grafias valem; grafia desconhecida continua
  caindo no painel de teste, de propósito.
- `CongelamentoEmissaoService` fecha a janela entre a troca de credenciais (plano 132-02) e
  a aprovação final (plano 132-04): checado como primeira coisa de
  `ContratoAdminController::gerarContrato()`, antes de qualquer I/O; e `show()` força
  `motivo_bloqueio = 'emissao_congelada'` com precedência sobre o `match` de motivos
  existente. A tela explica o motivo sem jargão.
- `132-GATE.md` — roteiro de 207 linhas, 7 seções, com os campos de resultado de SC1-SC5 +
  gate empírico #3 + gate #10 + as quatro linhas do interruptor de emissão, o procedimento
  numerado de voltar atrás (começando por "o interruptor FICA LIGADO") e o critério único
  de parar (D-06).

## Task Commits

1. **Task 1: `ClicksignAmbiente` — grafia normalizada + `.env.example`** - `d15d6049` (feat)
2. **Task 2: interruptor de emissão de contratos (D-07)** - `ebd74d65` (feat)
3. **Task 3: roteiro `132-GATE.md`** - `41a82153` (docs)

_TDD: Tasks 1 e 2 seguiram RED→GREEN dentro do mesmo commit por task (testes + implementação
juntos, conforme convenção do projeto de "teste nasce na mesma task do código que prova")._

## Files Created/Modified

- `app/Support/Clicksign/ClicksignAmbiente.php` - normaliza `CLICKSIGN_ENV` (trim + `Str::ascii` + lowercase) e resolve a URL do painel
- `app/Services/Clicksign/CongelamentoEmissaoService.php` - interruptor `contratos_emissao_congelada` (ativo/ligar/desligar)
- `tests/Feature/Phase132/ClicksignAmbienteTest.php` - 14 testes, incluindo o caso "grafia desconhecida cai no painel de teste"
- `tests/Feature/Phase132/EmissaoCongeladaTest.php` - 9 testes, incluindo a não-regressão explícita da Fase 131
- `config/services.php` - `clicksign.painel_url` passa a chamar `ClicksignAmbiente::painelUrl()`
- `.env.example` - documenta as 4 grafias aceitas + estacionamento comentado `CLICKSIGN_SANDBOX_*`
- `app/Http/Controllers/ContratoAdminController.php` - `gerarContrato()` recusa com o interruptor ligado (log + flash sem jargão); `show()` força o motivo de bloqueio
- `resources/js/Pages/Admin/ContratoDetalhe.jsx` - bloco próprio para `motivo_bloqueio === 'emissao_congelada'`
- `.planning/phases/132-.../132-GATE.md` - roteiro da virada

## Decisions Made

- D-01: normalizar 4 grafias em vez de padronizar uma string única — recusa deliberada de "consertar" só um lado, porque o ponto único de falha continuaria existindo.
- D-07: chave própria `contratos_emissao_congelada`, distinta de `administrativo_bloqueio_ativo` (Fase 128) — documentado explicitamente no docblock do service e verificado por `grep` no `<acceptance_criteria>` (0 ocorrências da chave da Fase 128 no arquivo novo).

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] Comentário no `config/services.php` batia acidentalmente no grep de regressão**
- **Found during:** Task 1, ao rodar os `acceptance_criteria`
- **Issue:** O comentário explicativo citava literalmente `=== 'producao'` para descrever a comparação antiga removida — o próprio grep de regressão (`grep -c "=== 'producao'" config/services.php` deve retornar 0) acusava a citação como se a comparação frágil ainda existisse.
- **Fix:** Reescrita do comentário para descrever a comparação sem reproduzir a string literal.
- **Files modified:** `config/services.php`
- **Verification:** `grep -c "=== 'producao'" config/services.php` → 0
- **Committed in:** `d15d6049` (parte do commit da Task 1)

**2. [Rule 1 - Bug] Docblock do `CongelamentoEmissaoService` citava literalmente a chave da Fase 128**
- **Found during:** Task 2, ao rodar os `acceptance_criteria`
- **Issue:** O docblock explicando por que este interruptor não é o da Fase 128 citava `administrativo_bloqueio_ativo` entre crases — o grep de isolamento (`grep -c "administrativo_bloqueio_ativo" ... ` deve retornar 0) acusava a citação.
- **Fix:** Reescrito para descrever a chave sem reproduzir o literal, mantendo a explicação (aponta para `EmpresaOperacionalRouter::CHAVE_BLOQUEIO`).
- **Files modified:** `app/Services/Clicksign/CongelamentoEmissaoService.php`
- **Verification:** `grep -c "administrativo_bloqueio_ativo" app/Services/Clicksign/CongelamentoEmissaoService.php` → 0
- **Committed in:** `ebd74d65` (parte do commit da Task 2)

---

**Total deviations:** 2 auto-fixed (ambos Rule 1, ajuste de texto para satisfazer os próprios acceptance_criteria do plano)
**Impact on plan:** Nenhum impacto de escopo — os dois ajustes são de redação de comentário, sem mudança de comportamento.

## Issues Encountered

- Ao rodar `grep -icE "cutover|sandbox|deploy|webhook|credencial" resources/js/Pages/Admin/ContratoDetalhe.jsx` (checagem de jargão da Task 2), o grep retorna 2 — mas ambas as ocorrências são referências ao **nome do arquivo** `CLICKSIGN-SANDBOX-EMPIRICO.md` dentro de docblocks/comentários já existentes ANTES desta task (confirmado via `git show HEAD:...` antes do primeiro commit deste plano). Nenhum texto renderizado para o usuário (título, parágrafos do bloco novo, mensagem de flash) contém qualquer uma das palavras proibidas. Fora de escopo desta task — não alterado (Rule 1-3 scope boundary: só se corrige o que a própria task introduziu).

## User Setup Required

None - nenhuma configuração de serviço externo neste plano. Nenhum deploy foi executado (proibido pelo `<aviso_de_ambiente>` do plano; é a Task 1 do plano 132-02, sob autorização explícita do usuário).

## Next Phase Readiness

- O plano 132-02 pode publicar as duas correções (grafia + interruptor) e seguir o roteiro `132-GATE.md` a partir da seção 3 ("Ordem dos passos"), preenchendo os campos de resultado de SC1 e SC4/gate empírico #3.
- O interruptor de emissão nasce **desligado** (confirmado por reconsulta via tinker) — nenhum comportamento de hoje mudou.
- Nenhum bloqueio conhecido para os planos seguintes.

---
*Phase: 132-cutover-sandbox-produ-o-checkpoint-humano-v22-0*
*Completed: 2026-08-17*

## Self-Check: PASSED

Todos os 6 arquivos declarados (criados/roteiro/SUMMARY) confirmados no disco; todos os 4
commits (`d15d6049`, `ebd74d65`, `41a82153`, `3f1d8cd7`) confirmados em `git log --all`.
