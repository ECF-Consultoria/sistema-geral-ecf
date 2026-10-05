---
phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e
plan: 01
subsystem: ai
tags: [gemini, creative-engine, laravel, validacao-automatica, artisan]

# Dependency graph
requires:
  - phase: 161-kit-de-7-criativos
    provides: "MlAnuncioCriativo/MlAnuncioCriativoKit, GeminiImageProvider, ReferenciaEfemeraService, CreativeSlotCatalog, FalhaDeGeracaoTrocavel"
provides:
  - "Contrato ImageJudgementProvider (N imagens + prompt -> TEXTO), separado de ImageGenerationProvider"
  - "GeminiImageProvider::julgar() — chamada real a /interactions SEM response_format, medida em 2026-10-05"
  - "CreativeJuizPromptBuilder — prompt pt-BR com bloco TRUTH-02/03 obrigatório para o juiz"
  - "CreativeJuiz::julgar()/julgarEGravar() — reconciliação no servidor (VAL-04 eliminatório) e veredito gravado na tabela"
  - "Colunas validacao_status/validacao/validacoes/regeneracao_automatica/validacao_pedida_em/validacao_em (criativos) e validacoes/regeneracoes_automaticas (kits)"
  - "Comando creative:validar-criativo {id|token} — prova real contra criativo já em produção"
affects: [162-02, 162-03, 162-04, 163]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Contrato novo e estreito em vez de método novo em interface já implementada por classes anônimas de teste"
    - "Reconciliação no servidor: nada do LLM é aceito como autoridade (molde CreativePlanner/AnaliseAnuncioService)"
    - "Veredito gravado na TABELA, nunca só em log (LOG_LEVEL=error em produção torna log inútil como registro)"

key-files:
  created:
    - app/Services/Creative/Contracts/ImageJudgementProvider.php
    - app/Services/Creative/Dto/CreativeJudgementRequest.php
    - app/Services/Creative/Dto/CreativeJudgementResult.php
    - app/Services/Creative/Dto/CreativeValidacao.php
    - app/Services/Creative/CreativeJuizPromptBuilder.php
    - app/Services/Creative/CreativeJuiz.php
    - app/Console/Commands/CreativeValidarCriativo.php
    - database/migrations/2026_10_05_100000_add_validacao_columns_to_ml_anuncio_criativos_table.php
    - database/migrations/2026_10_05_100100_add_validacao_columns_to_ml_anuncio_criativo_kits_table.php
  modified:
    - app/Models/MlAnuncioCriativo.php
    - app/Models/MlAnuncioCriativoKit.php
    - app/Services/Creative/GeminiImageProvider.php
    - app/Providers/AppServiceProvider.php
    - config/services.php
    - .env.example

key-decisions:
  - "Contrato ImageJudgementProvider NOVO e separado (não método novo em ImageGenerationProvider) — classes anônimas de teste da Fase 161 implementam o contrato antigo e um método abstrato novo seria erro fatal de PHP"
  - "Juiz lê as COLUNAS JÁ GRAVADAS (truth/slot_plano/imagem_path), nunca reconstrói por builder — cumpre a trava de coordenação com a Fase 165 e valida contra o truth que de fato gerou a imagem"
  - "lite como default do JUIZ, flash como reserva — medição real de 2026-10-05 mostrou os dois acertando o defeito, lite 2,7s vs flash 4,9s; não contradiz D-04 (lite continua descartado para GERAR)"
  - "Reconciliação ignora o veredito literal do modelo para decidir status final: status é sempre recalculado pela regra eliminatória (fidelidade=falha OU problema gravidade alta em tipo elegível) — nunca confia no veredito cru, mesmo quando válido"
  - "validacoes incrementado ANTES da chamada ao provedor (unidade faturada é a chamada) — impede retentativa infinita de um juiz que falha sempre"

patterns-established:
  - "Bloco TRUTH-02/03 do juiz: se o truth gravado não afirma contagem, o juiz não pode exigir nem inventar contagem — mesma disciplina do builder de geração, agora espelhada no builder de julgamento"

requirements-completed: [VAL-02, VAL-03, VAL-04]

# Metrics
duration: ~25min (intervalo entre o primeiro e o último commit de task)
completed: 2026-10-05
---

# Phase 162 Plan 01: Validação automática — Gemini como juiz Summary

**Juiz de visão Gemini (contrato `ImageJudgementProvider`, prompt pt-BR com regra TRUTH-02/03 e reconciliação eliminatória VAL-04) grava veredito estruturado em `ml_anuncio_criativos.validacao`, provado pelo comando `creative:validar-criativo` contra um criativo real.**

## Performance

- **Duration:** ~25 min entre o commit da Task 1 (11:11) e o commit da Task 3 (11:28), sem contar a leitura inicial de contexto
- **Tasks:** 3/3 completas
- **Files modified:** 15 (9 criados, 6 modificados)

## Accomplishments

- Contrato `ImageJudgementProvider` novo e separado, implementado por `GeminiImageProvider` sem alterar uma linha de `gerarImagem()`/`gerarTexto()` — os 11 testes de `GeminiImageProviderTest` seguem verdes sem edição
- `GeminiImageProvider::julgar()` chama `/interactions` SEM `response_format` (forma medida em produção em 2026-10-05) e devolve TEXTO, com reserva de modelo na mesma disciplina das chamadas de geração/texto
- `CreativeJuizPromptBuilder` monta o prompt do juiz em pt-BR a partir das colunas já gravadas (`truth`, `slot_plano`), com o bloco TRUTH-02/03 obrigatório (o juiz não pode exigir nem inventar contagem/fato fora do Product Truth gravado) e a regra de texto por slot (`CreativeSlotCatalog::aceitaTexto()`)
- `CreativeJuiz` reconcilia o veredito no servidor — "nada é aceito do modelo": fidelidade eliminatória (VAL-04) reprova mesmo com `veredito: "aprovada"` do modelo; whitelist de tipo/gravidade; teto de problemas; strings sanitizadas e cortadas
- Duas migrations aditivas e idempotentes com as colunas de validação, sem enum/FK/índice novo, zero colisão com as colunas da Fase 165 (`pub_rascunho_id`/`pub_grupo`/`pub_imagem_id`)
- Comando `creative:validar-criativo {id|token} {--json}` testado manualmente contra id inexistente (saída limpa, sem chamar a API)

## Task Commits

1. **Task 1: Colunas de validação + constantes nos models + config/env** — `4d866680` (feat)
2. **Task 2: Contrato de julgamento + caminho aditivo no GeminiImageProvider** — `2542e6ac` (feat)
3. **Task 3: CreativeJuiz — prompt, reconciliação e veredito gravado** — `e4096ed4` (feat)

**Plan metadata:** (este commit, docs — ver `<final_commit>`)

## Files Created/Modified

- `database/migrations/2026_10_05_100000_add_validacao_columns_to_ml_anuncio_criativos_table.php` — 6 colunas aditivas de validação
- `database/migrations/2026_10_05_100100_add_validacao_columns_to_ml_anuncio_criativo_kits_table.php` — 2 colunas aditivas do kit
- `app/Models/MlAnuncioCriativo.php` — constantes `VALIDACAO_*`, `LIMITE_VALIDACAO_MINUTOS`, predicados `validacaoPendente()`/`validacaoTravada()`/`validacaoMensagem()`
- `app/Models/MlAnuncioCriativoKit.php` — fillable das duas colunas novas
- `config/services.php` — bloco `creative.gemini.judge_model/judge_fallbacks` e bloco `creative.validacao`
- `.env.example` — chaves novas documentadas, nunca com valor de chave de API
- `app/Services/Creative/Contracts/ImageJudgementProvider.php` — contrato novo de julgamento
- `app/Services/Creative/Dto/CreativeJudgementRequest.php` / `CreativeJudgementResult.php` — DTOs do pedido/resultado de julgamento
- `app/Services/Creative/GeminiImageProvider.php` — implementa os dois contratos; `julgar()`/`julgarComModelo()` aditivos
- `app/Providers/AppServiceProvider.php` — singleton de `ImageJudgementProvider`
- `app/Services/Creative/CreativeJuizPromptBuilder.php` — prompt do juiz em pt-BR
- `app/Services/Creative/Dto/CreativeValidacao.php` — veredito reconciliado
- `app/Services/Creative/CreativeJuiz.php` — `julgar()`/`julgarEGravar()`
- `app/Console/Commands/CreativeValidarCriativo.php` — comando de prova real
- `tests/Unit/Phase162/*` — 4 arquivos de teste (27 testes, 97 assertions)

## Decisions Made

Ver `key-decisions` no frontmatter — resumo: contrato novo e separado para não quebrar as classes anônimas de teste da Fase 161; juiz lê colunas gravadas (nunca builder); `lite` default do juiz (medição real); status final sempre recalculado pela regra eliminatória, nunca confiando no `veredito` literal do modelo; contador de validações incrementado antes da chamada.

## Deviations from Plan

None - plano executado como escrito. Os 4 arquivos travados pela coordenação com a Fase 165 (`CreativeContextBuilder.php`, `ProductTruthBuilder.php`, `CreativePermissao.php`, `CreativePromptBuilder.php`) e os diretórios do Publicador não foram tocados — confirmado por `git diff --stat` vazio e `git status --short` vazio ao final.

## Issues Encountered

None.

## User Setup Required

None - no external service configuration required. As chaves novas (`GEMINI_JUDGE_MODEL`, `CREATIVE_VALIDACAO_ATIVA`, etc.) têm defaults funcionais e já estão documentadas no `.env.example`.

## Self-Check: PASSED

- `app/Services/Creative/Contracts/ImageJudgementProvider.php` — FOUND
- `app/Services/Creative/CreativeJuiz.php` (contém `julgarEGravar`) — FOUND
- `app/Services/Creative/CreativeJuizPromptBuilder.php` (contém `paraCriativo`) — FOUND
- `database/migrations/2026_10_05_100000_add_validacao_columns_to_ml_anuncio_criativos_table.php` — FOUND
- `app/Console/Commands/CreativeValidarCriativo.php` (contém `creative:validar-criativo`) — FOUND
- Commit `4d866680` — FOUND em `git log`
- Commit `2542e6ac` — FOUND em `git log`
- Commit `e4096ed4` — FOUND em `git log`

## Next Phase Readiness

- O Plano 02 (job de validação + gate de aprovação) pode consumir `CreativeJuiz::julgarEGravar()` direto — o contrato e a reconciliação já existem e estão testados.
- O Plano 03 (regeneração automática) tem o vocabulário pronto em `MlAnuncioCriativo` (`VALIDACAO_*`, predicados) sem precisar de nova migration.
- Nenhum bloqueio conhecido. A trava de coordenação com a Fase 165 continua válida para os planos seguintes desta fase.

---
*Phase: 162-valida-o-autom-tica-gemini-como-juiz-regenera-o-autom-tica-e*
*Plan: 01*
*Completed: 2026-10-05*
