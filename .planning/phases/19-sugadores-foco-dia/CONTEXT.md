# Phase 19: Sugadores — Foco no dia + Atalhos + Fix MCP

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-06-03
**Depende de:** Phase 15 (Sugadores cards/auto-resolução/copy MLBs), Phase 16 (cache D-1), Phase 18 + 18.5 (marketplace dinâmico)

## Goal

Reforçar as duas regras-mestras do projeto (acertividade + praticidade) no módulo Sugadores, eliminando 1 bug crônico (429 do MCP) e 3 problemas de UX reportados pelo usuário em 2026-06-03.

## Origem da fase

Citação do usuário em 2026-06-03:

**Bug:**
> "Ao acessar um adgroup sugador e clicar para carregar os MLBs: Falha ao consultar a API MCP da Adman: Adman MCP tool getMarketplaceadsCustIdproductAdsmetrics erro: Request to adman-api failed with status 429"

**Melhorias:**
> "Até eu que estou no comando do sistema achei e sei que os sugadores só aparecem após o async diário rodar, arredondando, após as 13 horas, havia esquecido disso e estranhei quando acessei o sistema de manhã e vi que todas empresas tiveram zero sugadores flagrados, se eu estranhei o pessoal que vai usar vai estranhar também."

> "Se for possível no card de sugadores já ter o botão de copiar os MLBs (separados por vírgula) para poderem tomar a ação em outro lugar."

> "Os sugadores que foram flagrados em dias anteriores devem sumir se não forem flagrados mais nas analises de outro dias, pois o analista pode ter resolvido e apenas não ter marcado no sistema, isso evita ficar acumulando milhares de sugadores, vou frisar: **o mais importante é o sugador do dia atual**."

## Estado atual verificado em prod (2026-06-03 14:00 BRT)

### Acúmulo confirmado
- **478 sugadores `pendente` HOJE** (detectados pelo cron `sugadores:analyze` 12:00)
- **1407 sugadores `pendente` ANTERIORES** (acumulados) ← problema reportado
- **2981 `auto_resolvido`** total (Phase 15 funcionou desde 2026-05-27)

### Auto-resolução (Phase 15) funciona mas não cobre tudo
- Sugadores que não são re-detectados na rodada do dia viram `auto_resolvido` ✓
- MAS sugadores cuja empresa NÃO sincronizou (sync falho, p.ex. as 32 Shopee até Phase 18.5) ficam `pendente` eternamente porque sem dados não há como "redetectar e não bater critério"
- Por isso há 1407 acumulados

### MCP rate limit
- [app/Services/AdmanMcpService.php](app/Services/AdmanMcpService.php) já tem retry para 429/5xx, mas:
  - Limite documentado **50 req/min** (não 10 como REST)
  - Endpoint `getMarketplaceadsCustIdproductAdsmetrics` é chamado por sugador no drilldown
  - Sem throttle por `custId` — múltiplas chamadas simultâneas em conta grande estouram
- Erro reportado pelo usuário hoje: 429 ao carregar MLBs de um sugador (provavelmente conta grande + concorrência interna)

### Cadência D-1 não-óbvia ao usuário
- `sugadores:analyze` cron `0 12 * * *` (após `adman:sync` 11:00)
- UI atual: contagem no card e timestamp da última análise por empresa — mas operador acessa de manhã, vê tudo zerado, e não entende que é só esperar o cron
- Phase 15 cards já mostram "Pendentes HOJE" mas sem disclaimer explícito da hora

## Decisões já tomadas no scoping (2026-06-03)

1. **Vista default**: lista filtra automaticamente `reference_date=hoje + status=pendente`. Botões explícitos para "Incluir dias anteriores" e "Incluir resolvidos" — mas não é o default.
2. **Botão "Copiar MLBs" em DOIS lugares**: na linha do sugador (lista) + no card da empresa (modo cards). Ambos disparam fetch on-demand mesma rota `/sugadores/{id}/mlbs` + copia direto.
3. **Limpeza dos 1407 acumulados**: comando one-shot `sugadores:limpar-orfaos` que marca como `auto_resolvido` (com `resolvido_por=null`, `acao_tomada='limpeza_orfaos'`, audit log) todos os `pendente` com `reference_date < hoje`. Roda 1× via SSH pós-deploy.

## Success Criteria

1. **Banner D-1 explícito** na página `/sugadores` (topo): badge/bloco pt-BR informando "Análise diária roda às 12h BRT — última execução: HH:MM"; estado por empresa no card mostra "Análise OK hoje" / "Sem análise hoje" / "Sem sync hoje".

2. **Vista default mostra só HOJE + pendente**: lista filtra automaticamente sem o usuário precisar selecionar. Header da lista exibe contador grande "478 sugadores HOJE"; toggle "Incluir dias anteriores" exibe os antigos com badge "Antigo · DD/MM".

3. **Botão "Copiar MLBs" inline no card de sugador** (linha da lista): clique disparou fetch via `/sugadores/{id}/mlbs` + copia MLBs para clipboard (mesma lógica do Show.jsx, sem precisar abrir drilldown). Feedback "Copiado N MLBs" 2s.

4. **Botão "Copiar MLBs da empresa" no card de empresa** (modo cards): clique consolida MLBs de TODOS os sugadores `tipo=adgroup status=pendente reference_date=hoje` da empresa em UMA chamada por sugador (sequencial com throttle), retorna lista única consolidada. Loading state visível.

5. **Mitigação 429 MCP**: adicionar `Cache::lock("adman_mcp:custid:{custId}", 6)` em `AdmanMcpService::fetchMlbsByCampaign` para serializar chamadas por conta + manter retry existente. Documentar que limite é 50 req/min (não 10 como REST).

6. **Comando one-shot `sugadores:limpar-orfaos`** (read-only por default com `--apply`):
   - Lista candidatos: `Sugador::where('status', 'pendente')->where('reference_date', '<', today())`
   - Sumário: total candidato + breakdown por empresa
   - `--apply` faz UPDATE em massa (DB::transaction): status='auto_resolvido', resolvido_em=now(), resolvido_por=null
   - Cria `SugadorAcao` em massa com `acao='limpeza_orfaos'`, observação "Limpeza one-shot Phase 19 — sugadores antigos não-redetectados"
   - Audit log + log file
   - Sem `--apply`: dry-run apenas

7. **Testes** cobrem: vista default filtra hoje+pendente, copiar MLBs por linha e por empresa, comando limpar-orfaos (dry-run e apply), `Cache::lock` no fetchMlbsByCampaign.

## Mapa de arquivos relevantes

### Backend
- [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php) — `index` (filtros default + `companies_summary` com estado análise/sync), `mlbs` (endpoint do drilldown), possível novo endpoint `mlbs-by-company`
- [app/Services/SugadorAnalysisService.php](app/Services/SugadorAnalysisService.php) — auto-resolução já implementada (Phase 15)
- [app/Services/AdmanMcpService.php](app/Services/AdmanMcpService.php) — `fetchMlbsByCampaign` + retry; adicionar `Cache::lock`
- [app/Models/Sugador.php](app/Models/Sugador.php) — scopes; possível scope `apenasHoje()` para clarity
- `app/Models/SugadorAcao.php` — nova constante `ACAO_LIMPEZA_ORFAOS`
- `app/Console/Commands/LimparOrfaosSugadores.php` (novo)

### Frontend
- [resources/js/Pages/Sugadores/Index.jsx](resources/js/Pages/Sugadores/Index.jsx) — banner D-1 + filtro default + botão Copiar inline na linha + Copiar agregado no card de empresa
- [resources/js/Pages/Sugadores/Show.jsx](resources/js/Pages/Sugadores/Show.jsx) — sem mudança no drilldown atual (apenas se SC-5 do MCP exigir signaling)

### Testes
- `tests/Feature/Phase19/SugadoresVistaDefaultTest.php` (novo)
- `tests/Feature/Phase19/CopiarMlbsTest.php` (novo)
- `tests/Feature/Phase19/LimparOrfaosSugadoresTest.php` (novo)

## Pitfalls antecipados

1. **Filtro default mudando comportamento atual** — usuários acostumados podem estranhar. Mitigação: header com texto óbvio "Mostrando: HOJE · 478 pendentes" + botão visível "Ver dias anteriores".

2. **Copiar MLBs no card de empresa pode ser caro** — empresa com 30 sugadores AdGroup × MCP chamada = 30 chamadas com throttle 6s = 3 min. Mitigação: indicar loading + permitir cancelar + considerar limite (e.g. max 10 sugadores por copy).

3. **Cache::lock no MCP** — se MCP demora muito (15s TLS handshake), lock TTL 6s pode ser curto. Calibrar: lock por 30s? Documentar trade-off.

4. **Comando limpar-orfaos pode marcar sugadores que NÃO deveriam** — ex: empresa que tem sync OK mas sugador continua pendente porque batendo critério. Mitigação:
   - SOMENTE sugadores `reference_date < HOJE` (não toca os de hoje)
   - Sumário no dry-run mostra por empresa pra usuário inspecionar antes de `--apply`

5. **SugadorAcao::create bulk** — pode estourar memória com 1407 registros. Usar `insert()` em chunks de 500 com `created_at` manual.

6. **Banner D-1** — precisa ler `last_sync_log` ou `MAX(created_at)` dos sugadores para mostrar "última análise". Adicionar uma prop no controller.

7. **Botão "Copiar MLBs" inline** — diferente do Show.jsx, na lista o sugador pode não ter sido carregado ainda. Lazy-fetch com loading + tooltip.

## Não-objetivos (out of scope)

- Refator completo da UI de Sugadores (manter cards + lista da Phase 15)
- Mudar critérios de detecção em `evaluateMetrics`
- Refator do `AdmanMcpService` além do `Cache::lock`
- Backfill histórico de adman_metrics (deferred non-priority Phase 18)
- Auto-resolução agressiva mensal (Phase 15 já cobre redetecção; comando one-shot é suficiente pra limpeza)
- Mudar Policy ou regras de visibilidade

## Cross-cutting constraints

- pt-BR em comentários, mensagens, commits
- `npm run build` obrigatório após cada JSX
- snake_case consistente
- Comando one-shot é **read-only por default** — `--apply` é explícito
- Não tocar em sugadores `reference_date = hoje` no comando
- Manter STATUS_TRAVADOS intocados (em_acao/resolvido/ignorado/movido/auto_resolvido)
- Reusar rota existente `/sugadores/{id}/mlbs` (não criar nova)
- Sem deploy automático

## Referências adicionais

- Phase 15 PLAN.md — auto-resolução + cards + copy MLBs no drilldown
- Phase 16 Cache D-1 — pattern de cache key e throttle
- Memory: [feedback_project_priorities.md](MEMORY.md) — regras acertividade + praticidade aplicadas aqui
- Memory: [project_sugadores_pagination_limit.md](MEMORY.md) — limitação histórica do drilldown

## Memory persistente relevante

- **Auto-resolução já existe (Phase 15)** mas não cobre sugadores de empresas sem sync — daí o acúmulo
- **MCP da Adman tem rate limit 50 req/min** (documentado no código)
- **Lean planning** — pular discuss/research/plan-check
- **GSD output em pt-BR**
