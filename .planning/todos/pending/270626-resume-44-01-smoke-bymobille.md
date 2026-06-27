---
id: 270626-resume-44-01-smoke-bymobille
created: 2026-06-27
priority: high
effort_estimate: 30min (5min DevCenter + 5min reconectar + 5min rodar smoke + 15min validar fixture e responder)
category: blocker-resume
resolves_phase: 44
references:
  - .planning/phases/44-mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml/44-01-PLAN.md (Tarefa 3 checkpoint)
  - .planning/phases/44-mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml/44-01-CHECKPOINT-PENDING.md (instruções completas de resume)
  - .planning/STATE.md (Blockers: 44-01-T3)
status: pending
---

# Destravar Plan 44-01 — rodar smoke real contra Bymobille (#298)

## Contexto

Phase 44 está bloqueada na Tarefa 3 do Plan 44-01 desde 2026-06-27. Tarefas 1+2 já entregues:

- `e40fce3` — scope OAuth `read write offline_access` em `MercadoLivreService.php:53`
- `9981f84` — 6 testes Phase44 RED
- `eceeb26` — `SugadoresMlWriteSmoke` command GREEN (6/6 testes verdes, zero regressão Phase 38)

Falta apenas a prova empírica em prod. Operador respondeu que **não tinha acesso à app ECF no DevCenter ML** no momento da execução.

## Pré-requisito

Acesso à app **ECF** em https://developers.mercadolivre.com.br (login com conta que está como owner/dev da app).

## Passos para destravar

Detalhes completos em `.planning/phases/44-mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml/44-01-CHECKPOINT-PENDING.md`. Resumo:

1. **Ativar permissão** "Advertising — access, create and manage campaigns" na app ECF (DevCenter ML)
2. **Reconectar Bymobille (#298)** via painel ECF `/sistema/ml-oauth` — confirmar via Tinker que `MlToken.scope` contém `write`
3. **Rodar smoke**: `php artisan sugadores:ml-write-smoke --company=298 --days=30`
4. **Validar fixture** em `storage/app/sugadores/ml-write-smoke/298-*.json`: `endpoints_ok=5`, `api_version_used="2"`, `new_campaign_id` integer não-nulo, `post_campaign_variant_used` `"A"` ou `"B"`
5. **Sanity** painel Mercado Ads do Bymobille — campanha `SGI-SMOKE-TEST-{ts}` PAUSADA criada
6. **Anti-leak** (opcional): `grep -i "access_token\|refresh_token" storage/logs/laravel.log | tail -20` → 0 matches

## Destravamento

Após smoke 5/5 verde, rodar `/gsd-execute-phase 44` e responder ao prompt com:

```
approved smoke=5/5 variant={A|B} campaign_id={N}
```

Isso libera Waves 2-4:
- **Wave 2** (44-02): backend `moverSgi`/`criarSgiEMover`/`desfazerMove`
- **Wave 3** (44-03): UI `MoveToSgiModal` + `UndoToast` em `Sugadores/Show.jsx`
- **Wave 4** (44-04): banner re-auth global para empresas com scope antigo
