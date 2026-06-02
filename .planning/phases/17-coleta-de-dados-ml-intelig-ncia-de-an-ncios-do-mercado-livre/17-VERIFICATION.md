---
phase: 17
status: passed
verified_at: 2026-06-02
verifier: inline (orchestrator) — verificação goal-backward; gsd-verifier não usado por instabilidade de subagente nesta sessão
must_haves_total: 7
must_haves_verified: 7
requirements: [D-01, D-02, D-03, D-04, D-05, D-06, D-07]
human_verification: done (checkpoint 17-05 aprovado pelo usuário — "funcionou")
---

# Phase 17 — Verificação de Objetivo

**Objetivo da fase:** Tornar a inteligência de anúncios do Mercado Livre coletável, observável e controlável — pesquisar uma keyword, coletar dados públicos da 1ª página de concorrentes (sem token de empresa), minerar keywords/dúvidas e apresentar um relatório, tudo via uma área restrita do módulo Publicação.

## Veredito: PASSED (7/7 must-haves)

Análise goal-backward: o que a fase prometeu existe e funciona no codebase, não apenas "tasks concluídas".

## Rastreabilidade de Requisitos (D-01..D-07)

| Req | Descrição | Evidência | Status |
|-----|-----------|-----------|--------|
| D-01 | App token client_credentials cacheado | `MlColetaService::getAppToken` (cache `ml_app_token_coleta`, TTL expires_in-300) — `test_app_token_cacheado` verde; run real obteve token | ✓ |
| D-02 | Degradação graciosa questions 401/403 | `fetchPerguntas` captura 401 → `questions_disponivel=false` sem abortar — `test_pipeline_sem_questions` verde | ✓ |
| D-03 | Rate limit 429 com backoff | `mlGet` trata 429 (Retry-After cap 30s) + loop top-5 best-effort — `test_429_degradacao_graciosa` verde | ✓ |
| D-04 | Mineração estatística (ranking n-gramas) | `MlKeywordMinerService::rankingKeywords` — 4 testes verdes; run real gerou 30 keywords | ✓ |
| D-05 | Recomendação heurística (sem IA) | `recomendacaoHeuristica` + aviso "Fase 2" na UI — sem dependência de IA (grep limpo) | ✓ |
| D-06 | Persistência + Job assíncrono + status + failed() | `mlb_coletas` migrada; `MlbColetaJob` (status pendente→rodando→concluido/erro, failed) — `MlbColetaJobTest` + `Phase17ColetaTest` verdes | ✓ |
| D-07 | Acesso restrito (publication_role) + sem state global | `checkPubAccess('coleta')` nas 4 actions; nav gated; UI só com useState/useForm — `test_acesso_403_sem_pub_role` verde | ✓ |

## Verificação E2E (run real contra a API ML)

Coleta "Cadeira Escritório" (id=1) processada via `dispatchSync`:
- `status=concluido`, sem erro
- `ranking_keywords`: 30 termos
- `questions_disponivel=true` (perguntas funcionaram com o app token — valida premissa A1/A2 do RESEARCH)
- `total_produtos_analisados=10`, `categoria_id=MLB193945` (domain_discovery resolveu categoria real)

## Artefatos
- SUMMARYs: 17-01, 17-02, 17-03, 17-04, 17-05 ✓ (5/5)
- `npm run build`: verde ✓
- Rotas `mlb.coleta.index|store|show|status` registradas ✓
- Permission `mlb.coleta` no catálogo ✓
- Item de nav 'Int. Anúncios' gated ✓

## Regressão
Suíte `--group phase17`: 11/11 verde. Suíte completa: 44 falhas **pré-existentes** (Phase 13/14 cobrança/coexistência + timezone DB do ambiente), **nenhuma** relacionada à Fase 17. Schema drift: não detectado.

## Itens diferidos (não são gaps da Fase 17)
- **Recomendação ciente do produto (IA / MAG T8)** — explicitamente diferida à Fase 2 por D-05. Capturada em `.planning/todos/pending/260602-recomendacao-ia-mag-t8-fase2.md`.

## Verificação humana
Checkpoint visual 17-05 **aprovado** pelo usuário ("funcionou"), com 2 ajustes de UX já aplicados (preserveScroll/State no "Ver relatório"; relatório em cards colapsáveis).
