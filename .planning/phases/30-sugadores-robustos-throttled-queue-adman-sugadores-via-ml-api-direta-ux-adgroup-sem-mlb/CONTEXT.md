# Phase 30: Sugadores Robustos (W1 shipped • W2/W3/W4 SUPERSEDED)

**Status:** W1 shipped 2026-06-08 • W2/W3/W4 **SUPERSEDED por Milestone v11.0** (decisão 2026-06-25)
**Mode:** mvp
**Iniciada:** 2026-06-08
**Depende de:** Phase 4 (`SugadorAnalysisService` Adman), Phase 18 (cache híbrido Adman + cust_id), Phase 19 (UI `/sugadores` consolidada), Phase 20 (`ml_token` integração + grants)
**Milestone:** v9.5 — Sugadores Robustos

> ## ⚠️ Decisão arquitetural 2026-06-25 — W2/W3/W4 SUPERSEDED
>
> Importação do `plano-migracao-sugadores-ml-direto.md` via `/gsd-import` revelou conflito com as decisões D-05 (mirror service `SugadorAnalysisServiceMl`) e D-06 (branching `is_ml_driven` no controller). Após AskUserQuestion, decidido:
>
> - **Arquitetura:** **provider pattern** (`SugadoresAdsProvider` contract + `AdmanSugadoresProvider` + `MercadoLivreSugadoresProvider`), NÃO mirror service. Um único `SugadorAnalysisService` recebe provider por DI, controlado por env `SUGADORES_PROVIDER_MODE` (`adman | ml_shadow | ml_primary`).
> - **Slicing:** Phase 30 W2/W3/W4 (plans 30-02/30-03/30-04) **não são mais para executar**. Substituídas pelas Phases 38-43 da nova **Milestone v11.0 — Migração Sugadores Adman → ML (Fontes Unificadas Fase 1)**.
> - **Plan 30-01** (W1 throttled queue Adman) **permanece em prod** — rate limiter global `adman-api` continua válido e é base do path Adman dentro do novo provider.
> - **Decisão D-08 do plano:** rate limit ML é separado (`ml-api:{seller_id}`), não conflita com `adman-api` shippado.
>
> Razão da troca: provider pattern habilita shadow mode + cut-over por empresa + comparação de paridade (Fase 2/3/4 do plano), que mirror service não suporta sem retrabalho. Veja `plano-migracao-sugadores-ml-direto.md` (raiz) para o plano técnico completo e `.planning/research/sugadores-ml-direto/` para a referência canônica importada.

## Goal

Eliminar as 3 dores em prod do módulo Sugadores:

1. **Rate limit 429 Adman** — contas grandes batem o limite oficial de 10 req/min, queue marca falha com "Tentativa 1/5 falhou. Próxima retry em ~10min"
2. **Paginação truncada** — "Apenas as primeiras 8 de 189 páginas foram lidas" porque o Job estoura timeout antes de varrer tudo
3. **Empresas ML-only não funcionam** — Bymobile teste e futuras (maioria) sem `adman_account_id` mostram "Empresa sem adman_account_id" ao clicar em Carregar MLBs

Resolve dor de hoje (prod estável pra todo tipo de empresa) + valida pattern **Sugadores via ML direta** que vira base de aprendizado pro Milestone v10.0 (Fontes Unificadas).

## Causa raiz (diagnosticada)

### 1. 429 mesmo com throttle

`AdmanMcpService::call()` ([app/Services/AdmanMcpService.php:64-126](app/Services/AdmanMcpService.php#L64-L126)) já tem retry em 429 com sleep até 65s + throttle de 6.5s entre páginas (~9 req/min). Mas o throttle é **intra-Job**. Quando múltiplos Jobs rodam em paralelo (supervisor `ecf-worker:*` tem 2 workers), cada um respeita ~9 req/min sozinho → total estoura 10 req/min global → 429.

**Fix:** rate limiter GLOBAL (não só intra-Job). Laravel oferece `Illuminate\Queue\Middleware\RateLimited` que serializa Jobs entre workers via `RateLimiter::for('adman-api', ...)`.

### 2. Paginação truncada

`FetchAdmanMlbsByCampaignJob` ([app/Jobs/FetchAdmanMlbsByCampaignJob.php:42-222](app/Jobs/FetchAdmanMlbsByCampaignJob.php#L42-L222)) tem timeout 1800s (30min) e `maxPages=1000`. Mas: cada página exige 6.5s de throttle + ~1-2s API = ~8s/página. Para 189 páginas = ~25min teóricos, próximo do timeout. Com qualquer falha intermitente, estoura.

**Fix:** quando o Job atingir limite de tempo (~80% do timeout), persistir progresso (último cursor de página + array `$mlbsAcumulados`) em cache + re-enfileirar continuação. Não recomeça do zero.

### 3. ML-only sem path implementado

`SugadorAnalysisService::analyzeCompany()` ([app/Services/SugadorAnalysisService.php:97-99](app/Services/SugadorAnalysisService.php#L97-L99)) prefere `adman_account_id`, sem fallback ML. `SugadorController::mlbs()` ([app/Http/Controllers/SugadorController.php:586-591](app/Http/Controllers/SugadorController.php#L586-L591)) retorna 422 quando empresa não tem.

**Fix:** novo `SugadorAnalysisServiceMl` espelhando lógica em cima de `MercadoLivreService` (Phase 20). Branching no controller: `$company->is_ml_driven ? MlService : AdmanService`.

## Decisões já travadas (AskUserQuestion 2026-06-08)

1. **Onde encaixar:** Nova Milestone v9.5 (entre v9.0 e v10.0). Não mistura com Notificações (v9.0) nem espera redesign Fontes Unificadas (v10.0).
2. **Escopo:** 1 phase única com 3 waves (W1+W2+W3) — compartilham contexto (Service, Job, UI Sugadores).
3. **Compatibilidade:** Sugadores Adman existente continua funcionando 100%. Mudança só introduz path adicional ML + throttling global pra Adman.

## Decisões técnicas

### W1 — Throttled queue Adman + paginação completa

#### D-01: Rate limiter global via `RateLimited` middleware

Definir limiter em `app/Providers/AppServiceProvider::boot()`:
```php
RateLimiter::for('adman-api', fn () => Limit::perMinute(8)->by('global'));
// 8/min deixa folga de 2 req pra retries; 10/min hard limit
```

Aplicar middleware no Job:
```php
public function middleware(): array {
    return [new RateLimited('adman-api')];
}
```

Jobs que excedem ficam pausados em `delayed_jobs` até janela liberar. Não falham.

#### D-02: Remover throttle interno (`usleep(6_500_000)`)

Sem o rate limiter global ser dono do throttling, o `usleep` interno vira redundância prejudicial (atrasa Jobs single-shot que poderiam queimar 8 req/min legítimas). Remove + ajusta comentário.

#### D-03: Continuação de paginação com checkpoint

`FetchAdmanMlbsByCampaignJob` ganha campo `int $startPage = 1` e `array $mlbsAcumulados = []`. No final do handle(), se atingiu timeout-imminence (`time() - LARAVEL_START > $this->timeout * 0.80`), re-dispatch com `startPage = ultimaProcessada + 1, mlbsAcumulados = soma`. Cache em `Cache::put("sugadores:fetch:{$companyId}:{$campaignId}", ['progress' => 'continuando_pagina_X'], 3600)`.

#### D-04: Cap maxPages razoável

Aumentar `maxPages` de 1000 (~8000 req!) pra 500. Combinado com rate limiter global de 8/min, varredura completa de 500 páginas leva ~60min worst-case, mas distribui entre Jobs (não em 1 single execution). Aceitável: usuário vê "varredura completa em andamento" + recebe notificação quando termina.

### W2 — Sugadores via ML API direta

#### D-05: Novo `SugadorAnalysisServiceMl`

`app/Services/SugadorAnalysisServiceMl.php` espelha API pública do `SugadorAnalysisService` mas internamente usa `MercadoLivreService` (Phase 20):

| Método | Adman MCP | ML API direta |
|--------|-----------|---------------|
| Listar adgroups | `getProductAdsCampaigns` | `/items/search?seller_id=X` (agrupa por catalog_product_id) |
| Métricas adgroup | `getMarketplaceAdsCustIdProductAdsmetrics` | `/insights/orders/by-product` + `/products/{id}/visits` |
| Detalhar MLB | `getMarketplaceAdsItem` | `/items/{ID}` |

Lógica de detecção de sugador (consumo sem retorno) idêntica — só muda a origem dos números.

#### D-06: Branching no `SugadorController`

Trocar todas as 4 verificações `if (!$company->adman_account_id)` ([linhas 344, 410, 586, 693](app/Http/Controllers/SugadorController.php#L344)) por:
```php
$analyzer = $company->is_ml_driven
    ? app(SugadorAnalysisServiceMl::class)
    : app(SugadorAnalysisService::class);
```

Se empresa **não tem nem `adman_account_id` nem `mlToken` ativo**, mantém o erro 422 (verdadeira "sem fonte").

#### D-07: Bymobile teste é piloto exclusivo

Smoke em prod **apenas com Bymobile teste**. Outras empresas ML-driven (futuras) não recebem essa funcionalidade automaticamente — exige verificação manual antes de habilitar (memory `feedback_acertividade`).

#### D-08: Rate limit ML é diferente

API Mercado Livre direta tem rate limit muito maior (8000 req/dia por app, sem limite forte por minuto). Não precisa de throttle agressivo. **Não aplicar middleware `RateLimited`** no path ML.

### W3 — UX adgroup sem MLB no período

#### D-09: Botão "Marcar como sugador" desacoplado de MLB

Em `Sugadores/Show.jsx` ([linha 720-721](resources/js/Pages/Sugadores/Show.jsx#L720-L721)), quando "Nenhum MLB encontrado para esta campanha no período X→Y", mostrar botão `[Marcar adgroup como sugador (análise manual)]`. Ao clicar, cria registro `Sugador` com `motivo='manual_analista'` + texto observação obrigatório.

#### D-10: Diferenciação visual

Sugadores criados manualmente ganham badge `📌 Manual` (na verdade só texto, sem emoji por convenção projeto). Filtro adicional `?fonte=manual` na lista pra auditoria.

#### D-11: Sem mudança no fluxo de pausa

Pausar adgroup continua funcionando via Adman MCP (`pauseProductAdsCampaign`). Sugador manual só sinaliza "analista decidiu" — pausa segue mesmo caminho.

## Success Criteria

### W1 — Adman throttle + paginação
1. RateLimiter `adman-api` configurado em `AppServiceProvider` (8/min global)
2. `AnalyzeCompanySugadoresJob` + `FetchAdmanMlbsByCampaignJob` declaram middleware `RateLimited('adman-api')`
3. Throttle interno `usleep(6_500_000)` removido do `AdmanMcpService`
4. Checkpoint de paginação: Job persiste `startPage + mlbsAcumulados`, re-dispatch em `(timeout * 0.80)`
5. UI mostra status "Continuando varredura... página X" enquanto há checkpoints pendentes
6. **Smoke prod**: rodar carregar MLBs numa conta grande (~150+ páginas) e ver "X de Y páginas lidas (100%)" sem 429

### W2 — Sugadores ML direta
1. `app/Services/SugadorAnalysisServiceMl.php` criado, API pública idêntica ao `SugadorAnalysisService`
2. `SugadorController` branching `is_ml_driven` em 4 actions: `analyzeCompany`, `sgiCampaigns`, `mlbs`, `mlbsByCompany`
3. Bymobile teste consegue clicar "Carregar MLBs" e ver lista real
4. Testes Feature: 1 cenário Adman path + 1 cenário ML path (mock `MercadoLivreService`)
5. **Smoke prod**: Bymobile teste analisada com sucesso, sugadores aparecem na lista

### W3 — UX manual
1. Botão "Marcar como sugador (análise manual)" aparece quando MLB lista vazia mas período válido
2. Modal exige observação textual mínima 10 chars
3. Sugador criado com `motivo=Sugador::MOTIVO_MANUAL_ANALISTA`
4. Lista `/sugadores` filtra por `?fonte=manual` mostrando só esses
5. Pausa segue funcionando normalmente

## Mapa de arquivos

### Backend novos
- `app/Services/SugadorAnalysisServiceMl.php` (W2)
- `tests/Feature/Phase30/ThrottledAdmanQueueTest.php` (W1)
- `tests/Feature/Phase30/SugadorMlPathTest.php` (W2)
- `tests/Feature/Phase30/ManualSugadorTest.php` (W3)

### Backend modificados
- `app/Providers/AppServiceProvider.php` — registrar `RateLimiter::for('adman-api')` (W1)
- `app/Services/AdmanMcpService.php` — remover `usleep(6_500_000)` (W1)
- `app/Jobs/AnalyzeCompanySugadoresJob.php` — `middleware(): [RateLimited]` (W1)
- `app/Jobs/FetchAdmanMlbsByCampaignJob.php` — middleware + checkpoint paginação (W1)
- `app/Http/Controllers/SugadorController.php` — branching `is_ml_driven` 4 actions + endpoint `markManual` (W2+W3)
- `app/Models/Sugador.php` — constante `MOTIVO_MANUAL_ANALISTA` (W3)
- Migration: `add_motivo_manual_to_sugadores` (W3) — só validation, sem schema change se motivo já é string livre

### Frontend modificados
- `resources/js/Pages/Sugadores/Show.jsx` — botão "Marcar como sugador" no estado vazio (W3)
- `resources/js/Pages/Sugadores/Index.jsx` — filtro `?fonte=manual` (W3)

### Não tocar
- `SugadorAnalysisService` (Adman) — só consumir, não alterar
- `MercadoLivreService` (Phase 20) — só consumir
- Rotas Adman (Phase 4)
- Polling do sino (Phase 10) — sem interação

## Pitfalls antecipados

1. **Rate limiter pode degradar UX em contas pequenas**: contas com 5 páginas vão ter o throttle global aplicado mesmo precisando só 0,7s. Mitigação: 8/min é folgado, não é hard limit.

2. **Checkpoint re-dispatch infinito**: Job que sempre atinge timeout vira loop. Cap em **10 re-dispatches** por carga inicial. Após isso, registra falha definitiva + notifica admin (Phase 8 BaseNotification, se já testado).

3. **ML token expirado** em Bymobile teste: `MlToken::status='active'` mas `expires_at < now()`. `MercadoLivreService` precisa refresh transparente. Verificar se Phase 20 já faz isso; se não, adicionar.

4. **Heurística de detecção difere por fonte**: Adman tem campos próprios (acos, ctr, conversions); ML direta tem `visits + orders + revenue`. Equivalência precisa validar empiricamente. Bymobile teste como cobaia: comparar sugadores que seriam detectados pelos 2 paths.

5. **Backwards compat da queue `database`**: nova middleware `RateLimited` precisa de cache hit-back; em prod o cache é Redis (descoberto na auditoria de hoje). Confirmar que driver `cache.default=redis` suporta o lock atômico do Rate Limiter (sim, suporta).

## Não-objetivos

- Migrar TODO o sistema pra ML API direta — é só Sugadores nesta phase. v10.0 cuida do escopo geral.
- Trocar driver de queue de `database` pra Redis — está fora de escopo, mesmo que ajudaria perf.
- Substituir Adman MCP por chamadas HTTP diretas — manter wrapper MCP que já funciona.
- Throttle agressivo na ML API — não precisa, limite é alto.
- UI bonita pra "sugador manual" — funcional já basta nesta fase.
- Migrar dados existentes (`motivo` antigos) — só novos cadastros são afetados.

## Cross-cutting constraints

- **Comentários em pt-BR** (convenção projeto + CLAUDE.md)
- **`npm run build` após qualquer mudança JSX** (convenção projeto)
- **Acessível só por admin/consultor/mentor** (middleware existente)
- **Compatibilidade Sugadores Adman**: zero regressão em prod
- **PERGUNTAR antes do deploy.sh** (memory `feedback_perguntar_antes_deploy_v9`)
- **Mantém pattern**: Service single-responsibility, Job small-handler, Controller fino, Action no model quando faz sentido
- **Smoke prod só com Bymobile teste no W2** — não ativar em outras empresas ML automaticamente

## Referências

- Phase 4 — `SugadorAnalysisService` Adman + lógica detecção
- Phase 18 — cache híbrido Adman + cust_id válido
- Phase 19 — UI `/sugadores` consolidada
- Phase 20 — `MlToken` + `MercadoLivreService` + grants via ML
- Doc oficial Adman: 10 req/min/key hard limit
- Memory `project_sugadores_pagination_limit` — drilldown limita 16 páginas
- Memory `project_adman_data_sources` — Adman tem 2 fontes
- API Mercado Livre — `/items/search`, `/insights/orders`, `/products/{ITEM_ID}/visits`

## Memory persistente relevante

- Lean planning
- pt-BR
- **PERGUNTAR antes do deploy** (v9.0+ — outro dev ativo)
- Autorização permanente para push/comandos read-only
- Acertividade — Bymobile teste como único piloto no W2
- Praticidade — analista resolve adgroup sem MLB sem precisar suporte
