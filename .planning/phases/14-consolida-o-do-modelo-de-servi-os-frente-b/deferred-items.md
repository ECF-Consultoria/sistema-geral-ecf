# Deferred Items — Phase 14

Itens descobertos durante a execução mas fora de escopo dos plans atuais.

## Plan 14-03 (descobertos em 2026-05-26)

### Falhas pré-existentes em AdminFechamentoControllerTest (5 testes)

Detectadas ao rodar `php artisan test --filter=AdminFechamentoControllerTest` para validar não-regressão pós refator do Plan 14-03. Verificadas via `git stash` — os testes JÁ FALHAVAM antes deste plan.

Testes falhando:
- `test_update_persiste_service_type`
- `test_update_rejeita_service_type_invalido`
- `test_update_rejeita_contract_end_anterior`
- `test_empresa_ok_recebe_periodo_coberto`
- `test_metrica_fora_do_mes_nao_conta`

Causa provável: testes escritos antes do refator do `service_type` para JSON array (Phase 2 quick task `260525`) ou da janela 30d rolling no `fechamento()` (Phase 5).

**Decisão:** Não fazer fix neste plan (fora de escopo — SCOPE BOUNDARY do executor). Deve ser atacado em quick task específica.
