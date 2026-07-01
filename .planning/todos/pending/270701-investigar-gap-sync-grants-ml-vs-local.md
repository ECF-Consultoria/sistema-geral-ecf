# TODO — Investigar gap Grants ML (396) vs Local (61)

**Capturado:** 2026-07-01 (durante UAT da Phase 51)
**Prioridade:** média
**Contexto:** débito técnico registrado no fechamento da Phase 51

## Situação

Após deploy da Phase 51, `/grants` mostra:
- **Total no ML (remoto, fonte de verdade):** 396 grants
- **Grants ativos (local, `CompanyGrant::where('status', 'active')->count()`):** 61

Gap de ~335 grants. A tabela `company_grants` está muito atrás do universo ML.

## Investigação sugerida

1. Rodar `php artisan grants:sync-ecf` manualmente no VPS e comparar antes/depois
2. Analisar log de erros do sync: `storage/logs/grants_sync.log`
3. Verificar se o sync incremental filtra por algum critério que exclui grants (ex: só sync empresas com `company_id` matchável)
4. Verificar retorno do `EcfDriveService::clientesGrants()` — quantos grants a API retorna vs quantos são persistidos

## Possíveis causas

- **Sync só processa Company matcháveis por cust_id** — grants para cust_ids que não têm Company correspondente ficariam órfãos. Mas `company_grants.company_id` é NOT NULL — se não achar Company, provavelmente pula (log warning) em vez de persistir.
- **Sync tem filtro de status** — talvez só sincroniza grants "ativos no ML" e o ML retorna 61 nessa categoria (mas o `/grants/resumo` retornou 345 vigentes...).
- **Sync tem paginação/timeout** — se `clientesGrants()` só retorna 61 por página e o loop para na 1ª página, explica.
- **Fonte diferente** — sync via SFTP legacy vs API ECF Drive Phase 20 — pode ter regressão silenciosa.

## Não bloqueia Phase 51

Phase 51 já mostra os 396 na UI como fonte de verdade. Este TODO é sobre **reconciliação** do banco local com o remoto — importante para reports, filtros locais e o card "Sem grant" (que hoje ignora grants não-sincronizados).

## Próxima ação

Aguardar cron `grants:sync-ecf` de 2026-07-02 03:00 BRT. Se o gap persistir, abrir investigação.
