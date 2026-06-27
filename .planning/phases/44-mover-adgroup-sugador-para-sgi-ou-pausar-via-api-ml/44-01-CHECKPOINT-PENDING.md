---
plan: 44-01
status: pending_human_checkpoint
gate: blocking
paused_at: "2026-06-27T02:30:00.000Z"
paused_reason: "Operador sem acesso à app ECF no DevCenter ML — não pode ativar permissão Advertising nem reconectar Bymobille com novo scope"
blocks_plans: [44-02, 44-03, 44-04]
---

# Plan 44-01 — Checkpoint humano PENDENTE

## Estado atual

| Tarefa | Status | Commit |
|--------|--------|--------|
| 1. Scope OAuth `read write offline_access` em `MercadoLivreService.php:53` | ✅ DONE | `e40fce3` |
| 2. Comando `sugadores:ml-write-smoke` + 6 testes (TDD) | ✅ DONE | `9981f84` (RED), `eceeb26` (GREEN) |
| 3. Smoke real contra Bymobille (#298) | ⏸ PENDENTE | — |

**Validação dos testes Phase44:** 6/6 verdes (`php artisan test --filter=Phase44/MlWriteSmokeCommandTest`)
**Validação anti-regressão Phase38:** 4/4 verdes (`php artisan test --filter=Phase38/MlSmokeCommandTest`)

## Por que está pausado

Operador respondeu em 2026-06-27 que **não tem acesso à app ECF no DevCenter ML agora** (`developers.mercadolivre.com.br` → app ECF). Sem isso:
- Não pode ativar a permissão funcional "Advertising — access, create and manage campaigns"
- Sem essa permissão ativada na app, mesmo reconectando Bymobille com scope expandido, qualquer PUT/POST em `product_ads` retorna 403
- Sem o smoke 5/5 verde, plans 44-02/03/04 estão codando sobre suposição — `depends_on=[44-01]` em cadeia bloqueia toda a Phase 44

## Como retomar

Quando o acesso à app ECF no DevCenter ML estiver disponível:

### Passo 1 — Ativar permissão na app (uma vez só)
1. Login em https://developers.mercadolivre.com.br
2. Selecionar app **ECF**
3. Aba "**Permissões funcionais**"
4. Confirmar/ativar "**Advertising — access, create and manage campaigns**"
5. Salvar

### Passo 2 — Re-autorizar Bymobille (#298) com novo scope
1. Painel ECF → `/sistema/ml-oauth`
2. Localizar Bymobille → clicar "**Reconectar**"
3. Completar consent no popup ML (login com conta dono da Bymobille)
4. Validar via Tinker:
   ```
   php artisan tinker
   > App\Models\MlToken::where('company_id', 298)->value('scope')
   ```
   Esperado: string contendo literalmente `write` (ex: `"read write offline_access"`)

### Passo 3 — Rodar o smoke contra prod
```
cd c:/xampp/htdocs/ecf_admin/ecf_admin
php artisan sugadores:ml-write-smoke --company=298 --days=30
```

### Passo 4 — Validar fixture
Arquivo gerado em `storage/app/sugadores/ml-write-smoke/298-{YYYY-MM-DD-HHmmss}.json`. Validar:
- `summary.endpoints_ok == 5`
- `summary.endpoints_failed == 0`
- `summary.api_version_used == "2"`
- `summary.new_campaign_id` é integer não-nulo
- `summary.post_campaign_variant_used` é `"A"` (preferida) ou `"B"` (fallback)
- `summary.move_target_item_id` e `summary.original_campaign_id` preenchidos

### Passo 5 — Sanity no painel Mercado Ads
- Painel Mercado Ads do Bymobille → confirmar campanha `SGI-SMOKE-TEST-{ts}` criada e PAUSADA
- (opcional) renomear/limpar manualmente — não há endpoint DELETE documentado

### Passo 6 — Anti-leak (opcional mas recomendado)
```
grep -i "access_token\|refresh_token" storage/logs/laravel.log | tail -20
```
Esperado: zero linhas com token literal de Bymobille.

## Resume signal

Quando smoke validado, retomar a Phase 44 com:

```
/gsd-execute-phase 44
```

E responder ao prompt do orchestrator com UMA das seguintes:

| Resposta | Significado |
|----------|-------------|
| `approved smoke=5/5 variant=A campaign_id={N}` | Fixture verde Variante A → libera Waves 2-4 |
| `approved smoke=5/5 variant=B campaign_id={N}` | Idem, mas plans 44-02 usa Variante B no `createCampaign` |
| `failed step={N} status={HTTP} reason="..."` | Smoke falhou → re-planejar 44-01 ou 44-02 |
| `blocked permission=advertising-missing` | Permissão Advertising NÃO estava ativa na app DevCenter |

## Plans bloqueados

- **44-02** (backend `moverSgi`/`criarSgiEMover`/`desfazerMove`) — Wave 2
- **44-03** (UI `MoveToSgiModal` + `UndoToast` em `Show.jsx`) — Wave 3, checkpoint humano UI
- **44-04** (banner re-auth global para outras empresas em prod com scope antigo) — Wave 4

Wave 2 só destrava após o smoke 5/5 verde + variante POST campaign confirmada.
