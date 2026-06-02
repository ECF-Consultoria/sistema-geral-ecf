---
phase: 17
status: clean
reviewed_at: 2026-06-02
reviewer: inline (orchestrator) — gsd-code-review multi-agente não usado por instabilidade de subagente nesta sessão
files_reviewed: 9
findings_high: 0
findings_medium: 0
findings_low: 3
---

# Phase 17 — Code Review (inline)

> Revisão inline das mudanças da fase. Para uma revisão multi-agente mais profunda, rode `/gsd:code-review 17`.

## Arquivos revisados
- `app/Services/MlKeywordMinerService.php`
- `app/Services/MlColetaService.php`
- `app/Models/MlbColeta.php`
- `app/Jobs/MlbColetaJob.php`
- `app/Http/Controllers/MlbController.php` (4 actions + 2 helpers)
- `app/Support/Permissions.php`
- `routes/web.php`
- `resources/js/Pages/Mlb/Coleta.jsx`
- `resources/js/Layouts/AppLayout.jsx`

## Veredito: CLEAN (sem findings HIGH/MEDIUM)

Segurança e correção verificadas:
- App token vive só no cache server-side (`ml_app_token_coleta`); **nunca logado** (T-17-08). ✓
- Sem SSRF: `API_BASE` e paths são constantes; keyword só entra como query param `q=` (T-17-06/07). ✓
- Endpoint banido `/sites/MLB/search` (403) não é usado. ✓
- Validação de entrada no `coletaStore` (`keyword required|max:255`, `condicao in:new,used`). ✓
- Gating `checkPubAccess('coleta')` em todas as 4 actions; 403 coberto por teste (D-07). ✓
- React escapa texto por padrão; sem `dangerouslySetInnerHTML` (T-17-14). ✓
- Polling com deadline 10min + `clearInterval` no cleanup (T-17-16). ✓
- Privacidade: `fetchPerguntas` persiste só o texto, nunca `from_id` (T-17-09). ✓

## Findings LOW (informativos — não bloqueantes)

1. **[LOW · Design aceito] Visibilidade compartilhada do histórico.** `coletaIndex/Show/Status` não filtram por dono — qualquer usuário com `mlb.coleta` vê/consulta qualquer coleta por id. Disposição: **accept** (decisão RESEARCH Q2 / T-17-13 — colaborativo dentro do módulo). Nenhum dado pessoal de comprador é persistido.

2. **[LOW] `sleep()` bloqueante em 429.** `mlGet` chama `sleep(max(1,min(retryAfter,30)))` no thread do worker. Aceitável (Job `timeout=300`, fila database single-worker), mas em cenário multi-tenant de alto volume conviria mover o backoff para reenfileiramento com `release()`. Disposição: accept (alinha com D-03/RESEARCH).

3. **[LOW] Recomendação heurística genérica.** Confirmado no checkpoint humano: a recomendação não conhece o produto do usuário. É o comportamento contratado da Fase 1 (D-05, com aviso "Fase 2"). Remediação registrada como Fase 2 (IA/MAG T8) em `.planning/todos/pending/260602-recomendacao-ia-mag-t8-fase2.md`.

## Testes
- `--group phase17`: 11/11 verdes (23 asserts).
- Suíte completa: 44 falhas **pré-existentes** (Phase 13/14 cobrança/coexistência + timezone DB do ambiente) — nenhuma relacionada à Fase 17.
