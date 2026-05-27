# Phase 15: Sugadores — UI por Empresa + Auto-resolução + Atalhos Operacionais

**Status:** Planning
**Mode:** mvp
**Iniciada:** 2026-05-27
**Depende de:** Phase 14 (concluída 2026-05-26), módulo Sugadores existente

## Goal

A aba `/sugadores` muda do paradigma "lista global paginada" para "cards por empresa com drilldown filtrado"; a análise diária auto-resolve sugadores `pendente` que não foram re-detectados na nova rodada (combate acúmulo histórico); operadores ganham botão de copy em massa dos MLBs no drilldown do AdGroup e reanálise direto do card da empresa.

## Origem da fase

Pedido direto do usuário após teste pela equipe em 2026-05-27. Citação:

> "Nos da equipe testamos a tool sugadores e pensando em agregar eficiência e agilidade que é a principal intenção do sistema, chegamos ao consenso que seria melhor na aba sugadores na UI ser mostrada por cards cada card seria uma empresa ao clicar mostra a lista de sugadores apenas da empresa clicada. No card da empresa deve mostrar a quantidade de sugadores identificados hoje. Além na view do Adgroup quando apertar em carregar MLBs que estão dentro do adgroup deve ter opção de copiar todos os MLBs de uma vez (separados por vírgula). E por fim pensando em aumentar eficiência e agilidade dos operadores que vão usar dou a liberdade para implementar o que achar funcional."

> "O sistema identifica um sugador, o analista resolve o sugador no mercado livre mas não marca no sistema, no outro dia quando o sugadores rodar de novo o sistema não deve mais aparecer no sistema por esse sugador que foi identificado ontem não atende mais os endpoints usados como critério para identificar um sugador. Isso fará não acumular um monte de sugadores."

## Success Criteria (do ROADMAP.md)

1. `/sugadores` exibe **grid de cards de empresa** como visão padrão — nome, contagem `pendente` HOJE em destaque, total pendentes acumulados, timestamp da última análise.
2. Cards ordenados por `count_hoje DESC, total_pendentes DESC, nome ASC`; clicar abre drilldown com `company_id` pré-aplicado; toggle "lista global" mantido (compat).
3. Drilldown de MLBs do AdGroup: **botão "Copiar MLBs"** (lista completa separada por vírgula). Quando há `matches_adgroup=true`, botão extra "Copiar prováveis".
4. Card tem **botão "Reanalisar"** (rota existente `sugadores.analyze-company`) com feedback ("Enfileirado às HH:mm"); respeita Policy `manage`.
5. Após análise diária por empresa, sugadores com `status=pendente` e `reference_date < hoje` daquela empresa cujo `(tipo, campaign_id, adgroup_id)` NÃO consta no upsert atual são marcados como `auto_resolvido` (novo status), com `resolvido_em=now()`, `resolvido_por=null`, audit log `SugadorAcao::ACAO_AUTO_RESOLVIDO`. STATUS_TRAVADOS NÃO são tocados.
6. Status `auto_resolvido` aparece com badge diferente (tooltip "Resolvido automaticamente pelo sistema"), excluído do count "Pendentes", contado no histórico (semelhante a `resolvido`).
7. LocalStorage `sugadores:last_company_id` ao abrir drilldown; ao reabrir `/sugadores`, chip "Continuar com [Empresa X]" — sem auto-redirect.

## Estado atual da feature (verificado em 2026-05-27)

### Arquivos-chave

- **Controller:** [app/Http/Controllers/SugadorController.php](app/Http/Controllers/SugadorController.php) (541 linhas) — `index`, `show`, `mlbs`, `analyzeCompany`, `analyzeAll`, `bulkMove`, `move`, `updateStatus`, `sgiCampaigns`.
- **Service:** [app/Services/SugadorAnalysisService.php](app/Services/SugadorAnalysisService.php) (503 linhas) — `analyzeAll`, `analyzeCompany`, `evaluateMetrics`, `buildRow`, `loadCampaignsInfo`, `shouldSkipCampaign`.
- **Job:** [app/Jobs/AnalyzeCompanySugadoresJob.php](app/Jobs/AnalyzeCompanySugadoresJob.php) — `ShouldBeUnique` por `company_id`, 4 tries, timeout 900s, backoff 180→3600.
- **Model:** [app/Models/Sugador.php](app/Models/Sugador.php) (191 linhas) — constants `STATUS_*`, `STATUS_TRAVADOS = [EM_ACAO, RESOLVIDO, IGNORADO, MOVIDO]`, scopes `pendentes`, `daCarteira`, `noPeriodo`.
- **Audit model:** [app/Models/SugadorAcao.php](app/Models/SugadorAcao.php) — ACAO_* constants.
- **UI:** [resources/js/Pages/Sugadores/Index.jsx](resources/js/Pages/Sugadores/Index.jsx) (741 linhas), [Show.jsx](resources/js/Pages/Sugadores/Show.jsx) (774 linhas), [Config.jsx](resources/js/Pages/Sugadores/Config.jsx) (380 linhas).
- **Policy:** [app/Policies/SugadorPolicy.php](app/Policies/SugadorPolicy.php).
- **Modal compartilhado:** [resources/js/Components/MoveToSgiModal.jsx](resources/js/Components/MoveToSgiModal.jsx).

### Rotas existentes (web.php:157-177)

```
GET    /sugadores                                  sugadores.index
GET    /sugadores/{sugador}                        sugadores.show
GET    /sugadores/{sugador}/mlbs                   sugadores.mlbs           (JSON)
PATCH  /sugadores/{sugador}/status                 sugadores.update-status
POST   /sugadores/{sugador}/move                   sugadores.move
POST   /sugadores/bulk-move                        sugadores.bulk-move
POST   /sugadores/analyze                          sugadores.analyze-all
POST   /sugadores/companies/{company}/analyze      sugadores.analyze-company
GET    /sugadores/companies/{company}/sgi-campaigns sugadores.sgi-campaigns (JSON)
GET    /sugadores/configs/{company}                sugadores.config.show
```

### Schema relevante

Tabela `sugadores`:
- Chave única lógica de upsert: `(company_id, reference_date, tipo, campaign_id, adgroup_id)`
- Status atuais (enum/string): `pendente`, `em_acao`, `resolvido`, `ignorado`, `movido`
- Campos relevantes: `reference_date`, `status`, `resolvido_em`, `resolvido_por`, `acao_tomada`, `motivos` (json), `raw_data` (json)

Tabela `sugador_acoes`:
- Ações conhecidas: `ACAO_MARCOU_EM_ACAO`, `ACAO_MARCOU_RESOLVIDO`, `ACAO_MARCOU_IGNORADO`, `ACAO_VOLTOU_PENDENTE`, `ACAO_MOVEU`.

### Mecânica do upsert atual (insight crítico)

[SugadorAnalysisService::analyzeCompany](app/Services/SugadorAnalysisService.php) constrói `$existingMap` filtrando APENAS por `reference_date = $refDateStr` (hoje):

```php
$existingMap = Sugador::where('company_id', $company->id)
    ->where('reference_date', $refDateStr)   // <-- só hoje
    ->get()
    ->keyBy(fn($s) => "{$s->tipo}|{$s->campaign_id}|{$s->adgroup_id}");
```

Daí o acúmulo: sugadores de `reference_date < hoje` NUNCA são re-avaliados. A análise de hoje cria registros novos para `reference_date = hoje` mas não toca nos de ontem. O critério 5 da fase **precisa**:

1. Calcular `$toUpsert` (lista de detecções de hoje) normalmente.
2. Após o `Sugador::upsert(...)`, varrer sugadores com `status=pendente` e `reference_date < hoje` da mesma empresa.
3. Identificar quais chaves `(tipo, campaign_id, adgroup_id)` desses NÃO estão em `$toUpsert`.
4. Marcar esses como `auto_resolvido` (novo status) + criar `SugadorAcao` com nova `ACAO_AUTO_RESOLVIDO`.

### Dependências externas

- `App\Services\AdmanMcpService` — MCP para drilldown de MLBs (já tem `fetchMlbsByCampaign`, `cachedFullScanIfReady`).
- `App\Jobs\FetchAdmanMlbsByCampaignJob` — varredura completa em background para contas grandes.
- `App\Services\AdmanService::fetchSugadorCampaigns` — listagem de campanhas SGI.

## Decisões já tomadas no scoping

- **Novo status `auto_resolvido`** (não reaproveitar `resolvido` com `acao_tomada` especial) — analistas conseguem filtrar/auditar separadamente o que foi auto vs manual.
- **Cards são a view DEFAULT** — modo "lista global" continua acessível via toggle/query param para não quebrar bookmarks/links antigos.
- **Reusar rotas existentes** — sem novos endpoints duplicados; `sugadores.index?company_id=` já filtra por empresa.
- **localStorage chip "Continuar com X"** em vez de auto-redirect — analista pode querer ver cards.
- **`navigator.clipboard.writeText` com fallback** (textarea + `document.execCommand('copy')`) para compat.
- **`auto_resolvido` adicionado a `STATUS_TRAVADOS`** — evita uma re-análise futura voltar para `pendente` por engano.

## Não-objetivos (out of scope)

- Mover `SugadorAnalysisService::analyzeCompany` para 100% async além das 16 páginas do drilldown MCP — refactor pesado, fica para Phase 16+.
- Mudar heurística/critérios de detecção (`evaluateMetrics`).
- Mudar Policy ou regras de visibilidade (admin/gestor/lider veem global; demais só carteira — mantido).
- Notificações de auto-resolução para analistas.
- Bulk-actions na visão de cards (selecionar várias empresas) — fica para futuro.

## Cross-cutting constraints

- pt-BR em comentários, mensagens e activity log (CLAUDE.md mandate).
- `npm run build` obrigatório após cada edição JSX.
- Reusar rotas existentes — sem endpoints duplicados.
- Novo status `auto_resolvido` exige migration de schema (enum/string) + atualização de `Sugador::STATUS_TRAVADOS` + UI labels (`STATUS_LABELS`, `STATUS_BADGE`).
- Auto-resolução roda dentro de `SugadorAnalysisService::analyzeCompany` APÓS o upsert; respeitar `dryRun=true` (não auto-resolver em dry run).
- Botão "Copiar MLBs" precisa fallback browser sem `navigator.clipboard`.
- LocalStorage NUNCA acessado em SSR — sempre dentro de `useEffect`.

## Pitfalls antecipados

1. **`reference_date` cast 'date'** — SQLite (testes) retorna ISO datetime; comparações em SQL devem usar `toDateString()` no PHP, não objetos Carbon.
2. **`bulk upsert` bypassa Eloquent casts** — `motivos`/`raw_data` precisam de `json_encode` manual (já feito).
3. **`SugadorAcao::insert` (massa) bypassa timestamps** — precisa `created_at` manual.
4. **Auto-resolução em rerun manual no mesmo dia** — se analista clicar "Reanalisar" duas vezes seguidas, a segunda rodada pode re-marcar pendentes que ela mesma criou. Solução: filtrar por `reference_date < hoje` (não `<=`).
5. **Concorrência `AnalyzeCompanySugadoresJob`** — `ShouldBeUnique` por `company_id` já previne duplicata mas auto-resolução precisa ser atômica com upsert (mesma transação ou ordem garantida).
6. **Cards eficiência da query** — calcular `count_hoje` + `total_pendentes` + `ultima_analise` por empresa: usar SUBQUERY/GROUP BY único, não N+1 (provavelmente leftJoin agregado).
7. **`AdmanSyncLog` ou outra fonte para "última análise"** — verificar onde rastreamos timestamp da última run por empresa (pode ser `MAX(sugadores.created_at)` por company_id).
8. **`navigator.clipboard` em HTTP localhost** — funciona; em IPs intranet sem HTTPS pode falhar — fallback necessário.

## Referências adicionais

- [.planning/codebase/ARCHITECTURE.md](.planning/codebase/ARCHITECTURE.md) — arquitetura geral (atualizada 2026-05-27).
- [.planning/codebase/STRUCTURE.md](.planning/codebase/STRUCTURE.md) — layout de diretórios.
- `STATUS_TRAVADOS` — `app/Models/Sugador.php:105`.
- `STATUS_LABELS`, `STATUS_BADGE` no front — `resources/js/Pages/Sugadores/Index.jsx:13-27`.
- Mecânica de upsert — `app/Services/SugadorAnalysisService.php:117-120, 277-288`.

## Memory persistente relevante

- **Sugador drilldown limita 16 páginas** — contas grandes mostram "limite de tempo". Fix definitivo (mover analisador completo pra queue) é tarefa separada — Phase 16+. NÃO bloqueia esta fase.
- **Lean planning** — pular discuss/research/plan-check; ir direto pro planner com CONTEXT.md detalhado.
- **GSD output em pt-BR** — todos artefatos da fase em português.
