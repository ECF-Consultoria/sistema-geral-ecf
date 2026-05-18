# Codebase Concerns

**Analysis Date:** 2026-05-18

---

## Tech Debt

**MlbController god-class (2066 lines):**
- Issue: `MlbController` handles dashboard, KPIs, publicacoes, vendas, empresas, treinamentos, revisao, historico, sync de vendas, implementacao, e configuração — tudo em uma única classe.
- Files: `app/Http/Controllers/MlbController.php`
- Impact: Extremamente difícil de testar isoladamente; qualquer mudança de regra de negócio arrasta efeitos colaterais imprevistos; tempo de carregamento e revisão de PR alto.
- Fix approach: Extrair grupos de métodos em controllers menores: `MlbDashboardController`, `MlbVendasController`, `MlbEmpresasController`, etc.

**Duplicação de `metaParaMes()` e `diasUteis()` em dois controllers:**
- Issue: Os métodos privados `metaParaMes(int, string): int` e `diasUteis(Carbon, Carbon): int` estão copiados identicamente em `MlbController` e `PerformanceController`. A lógica de contagem de dias úteis também reaparece em `ImportarPlanilhaMLB` com assinatura diferente.
- Files: `app/Http/Controllers/MlbController.php:153,71`, `app/Http/Controllers/PerformanceController.php:219,232`, `app/Console/Commands/ImportarPlanilhaMLB.php:181`
- Impact: Divergência futura garantida — se a regra de dias úteis mudar (ex: feriados), precisará atualizar três locais.
- Fix approach: Extrair para `app/Services/MlbKpiService.php` ou `app/Support/DiasUteis.php` e injetar nos controllers.

**Autorização do módulo MLB via métodos privados, não via Gate/Policy:**
- Issue: O acesso ao módulo MLB usa dois métodos privados no controller (`checkPubAccess()`, `checkPubRole()`) em vez de usar `Gate` ou uma `Policy`. Enquanto o módulo Sugadores usa `Gate::authorize()` corretamente, MLB não tem política registrada.
- Files: `app/Http/Controllers/MlbController.php:25-43`, `app/Http/Controllers/MlbImplementacaoController.php:16-28`
- Impact: Regras de acesso não são testáveis via `$user->can()`. Adicionar uma nova role exige alterar todos os `checkPubRole(['gestor', 'lider'])` espalhados pelo controller.
- Fix approach: Criar `app/Policies/PublicacaoPolicy.php` e `MlbEmpresaPolicy.php`; converter os `checkPubAccess/checkPubRole` em `Gate::authorize()`.

**Herança da autorização "publicador só vê suas publicações" aplicada inline:**
- Issue: A regra "publicador não pode editar publicação de outro publicador" é checada inline com `if ($user->publication_role === 'publicador' && $pub->user_id !== $user->id)` em múltiplos métodos do controller.
- Files: `app/Http/Controllers/MlbController.php:1115,1179,1201`
- Impact: Regra triplicada; não cobre todos os endpoints uniformemente.
- Fix approach: Mover para `PublicacaoPolicy::update()`.

**Ausência de FormRequests — validação inline em controller grande:**
- Issue: Todas as validações do `MlbController` usam `$request->validate([...])` inline no controller. Há 16+ blocos de validação no mesmo arquivo.
- Files: `app/Http/Controllers/MlbController.php:719,1046,1151,1205,1314,1337,1466,1501,1562,1608,1667,1724,1785,1912,1941,1955,1972`
- Impact: Controller ainda mais volumoso; validações não reutilizáveis entre endpoints similares.
- Fix approach: Extrair para `app/Http/Requests/Mlb/StorePublicacaoRequest.php`, etc.

**Compatibilidade legada `fase` → `projeto` mantida em dois lugares:**
- Issue: `MlbEmpresa::FASE_PARA_PROJETO` é uma constante de mapeamento legado; o campo `projeto` foi adicionado em migration posterior (`2026_05_07_000010`). Código novo ainda faz fallback via `$e->getAttributes()['projeto'] ?? null` em vez de usar o accessor `projeto()`.
- Files: `app/Models/MlbEmpresa.php:61-71`, `app/Http/Controllers/MlbController.php:808,1399`
- Impact: Dois caminhos de leitura do mesmo dado. O accessor `projeto()` existe mas não é chamado consistentemente.
- Fix approach: Remover `getAttributes()['projeto']` e usar sempre `$e->projeto()`. Documentar prazo de descontinuação do fallback `fase`.

---

## Known Bugs

**N+1 em `publicacoes()`: query por empresa dentro de `->map()`:**
- Symptoms: Para cada empresa na lista do usuário, uma query `COUNT(*)` separada é disparada — `Publicacao::where('mlb_empresa_id', $e->id)->count()`.
- Files: `app/Http/Controllers/MlbController.php:801`
- Trigger: Acessar `/mlb/publicacoes` com muitas empresas; visível no log de queries com `DB_LOG_QUERIES=true`.
- Workaround: Nenhum. Impacto é proporcional ao número de empresas do publicador (10+ empresas = 10+ queries extras por request).

**N+1 em `projetos()`: query de problemas dentro de `->map()`:**
- Symptoms: Para cada empresa retornada, fazem-se 1-2 queries extras: `COUNT(problema=true)` e, se > 0, outro `GET` das publicações com problema.
- Files: `app/Http/Controllers/MlbController.php:1377-1388`
- Trigger: Acessar `/mlb/projetos` com muitas empresas.
- Workaround: Nenhum.

**`User::find()` dentro de `->map()` gera N+1 ao calcular ticket médio:**
- Symptoms: Para cada publicador retornado no `ticketPorPub`, faz-se `User::find($r->user_id)` separado.
- Files: `app/Http/Controllers/MlbController.php:649`
- Trigger: Visualizar `/mlb/vendas` no modo admin/gestor com múltiplos publicadores.
- Workaround: Substituir por `->with('user')` ou keyBy com mapa de usuários pré-carregado.

**Cálculo acumulado de métricas no `DashboardController` carrega todos os dados em memória:**
- Symptoms: `AdmanMetric::whereIn(...)->get()` traz todos os registros do período para a coleção PHP, onde `->groupBy()`, `->avg()`, `->sum()` etc. são feitos in-memory. Com 180 dias e 50+ empresas, isso pode ser centenas de milhares de linhas.
- Files: `app/Http/Controllers/DashboardController.php:75-115`
- Trigger: Filtro "180 dias" no dashboard admin.
- Workaround: Nenhum.

**`google/callback` aceita requests sem verificar `state` (CSRF do OAuth):**
- Symptoms: O fluxo Google OAuth não gera nem valida o parâmetro `state`, expondo o endpoint a ataques de CSRF no OAuth flow.
- Files: `app/Http/Controllers/GoogleCalendarController.php:22-51`, `app/Services/GoogleCalendarService.php:17-29`
- Trigger: Atacante pode forjar um redirect para o callback com `code` próprio, vinculando sua conta Google à sessão da vítima.
- Workaround: Nenhum implementado.

---

## Security Considerations

**Credenciais VPS hardcoded em scripts de deploy rastreados:**
- Risk: Senha do servidor VPS (`ECF-100376vps`) e IP (`177.7.53.164`) estão hardcoded em `deploy.sh` e `deploy_parcial.sh`.
- Files: `deploy.sh:5-9`, `deploy_parcial.sh:5-9`
- Current mitigation: Scripts estão no `.gitignore` (`deploy*.sh`), então NÃO são commitados no repositório. Porém os arquivos existem no disco de desenvolvimento.
- Recommendations: Migrar para variáveis de ambiente ou SSH key-auth sem senha. Revisar se esses arquivos foram versionados em algum commit histórico.

**SSL verification desabilitada no curl interno de disparo de grants sync:**
- Risk: `CURLOPT_SSL_VERIFYPEER => false` abre brechas para MITM no curl interno que dispara `/internal/grants/sync/run`.
- Files: `app/Http/Controllers/GrantController.php:164`
- Current mitigation: O endpoint requer header `X-Sync-Secret` assinado com `APP_KEY`; o tráfego é localhost-to-localhost em produção.
- Recommendations: Remover `CURLOPT_SSL_VERIFYPEER => false`; ou usar HTTP plain text para chamadas localhost onde SSL não é necessário.

**Endpoint `/internal/grants/sync/run` sem CSRF e protegido apenas por header secret:**
- Risk: O endpoint está aberto sem autenticação de sessão; depende exclusivamente de um secret derivado de `APP_KEY`. Se `APP_KEY` vazar, qualquer requisição externa pode disparar importações SFTP.
- Files: `routes/web.php:29-31`, `app/Http/Controllers/GrantController.php:210-220`
- Current mitigation: Header `X-Sync-Secret` validado com hash SHA-256 de `APP_KEY`.
- Recommendations: Considerar mover para `routes/console.php` como comando; ou restringir o endpoint apenas a IP `127.0.0.1` via middleware.

**OAuth Google sem `state` parameter:**
- Risk: CSRF no OAuth flow — um atacante pode iniciar o flow OAuth e redirecionar a vítima para o callback com o próprio `code`, vinculando a conta Google do atacante à sessão da vítima.
- Files: `app/Http/Controllers/GoogleCalendarController.php:15-51`
- Current mitigation: Nenhuma.
- Recommendations: Gerar e salvar `state` aleatório na sessão em `connect()`; validar no `callback()` antes de trocar o `code`.

**Rotas públicas sem autenticação expõem dados de empresas via token:**
- Risk: As rotas de workspace do PPA (`/ppa/workspace/{token}`), implementação MLB (`/implementacao/{token}`), e publicador (`/implementacao/{token}/publicador`) são completamente públicas. Um token exposto ou guessable permite acesso a dados das empresas.
- Files: `routes/web.php:34-42`
- Current mitigation: Tokens gerados com `Str::uuid()` (alta entropia). Sem expiração.
- Recommendations: Adicionar expiração de token; logar acessos públicos; considerar proteção por senha opcional.

**DB::raw com `$intQty` interpolado (risco teórico, mitigado por cast):**
- Risk: `DB::raw("GREATEST(COALESCE(vendas_qty, 0), {$intQty})")` usa interpolação direta de variável PHP no SQL.
- Files: `app/Http/Controllers/MlbController.php:759,1699,1751`, `app/Console/Commands/SyncVendasAdman.php:54`
- Current mitigation: `$intQty = (int) $data['qty']` — o cast para `int` efetivamente previne injeção SQL aqui.
- Recommendations: Substituir por `DB::raw('GREATEST(COALESCE(vendas_qty, 0), ?)', [$intQty])` para clareza semântica e eliminar dependência do cast manual.

---

## Performance Bottlenecks

**Sync de vendas executado dentro do request HTTP com `set_time_limit(0)`:**
- Problem: `syncVendasPublicador()` e `syncTodasVendasAdman()` fazem múltiplas chamadas à API Adman com sleeps de 600ms por empresa dentro do ciclo request-response HTTP. Um publicador com 10 empresas = mínimo 6 segundos de bloqueio de thread.
- Files: `app/Http/Controllers/MlbController.php:713-778,1715-1764`
- Cause: Sem queue para operações de IO síncronas no controller.
- Improvement path: Mover para `Job` despachado na fila (como `AnalyzeCompanySugadoresJob`); retornar resposta imediata; usar polling de status similar ao grants sync.

**Loop de busca de dias úteis sem cache:**
- Problem: `diasUteis(Carbon $start, Carbon $end)` percorre dia-a-dia em PHP, é chamado 3x por publicador no `dashboard()` e mais vezes no `meuPainel()`, sem nenhum caching.
- Files: `app/Http/Controllers/MlbController.php:71-82`, `app/Http/Controllers/PerformanceController.php:232-243`
- Cause: Loop O(n) onde n = dias no período. Para um mês (31 dias) é trivial, mas o código não escala se o período crescer.
- Improvement path: Usar `CarbonPeriod` com filtro `isWeekday()` ou cache em memória (`static`).

**`metaParaMes()` dispara 1 query por publicador no ranking do dashboard:**
- Problem: No `dashboard()`, `metaGeral` é calculado somando `$this->metaParaMes($p->id, $mesRef)` para cada publicador via `->sum()` em collection PHP. Cada call dispara 1 query.
- Files: `app/Http/Controllers/MlbController.php:201`
- Cause: N+1 para metas — 5 publicadores = 5 queries a `mlb_meta_historico`.
- Improvement path: Fazer uma única query `whereIn('user_id', [...])` e mapear em PHP.

**`adman_campaign_metrics` sem índice em `reference_date` isolado:**
- Problem: O `MetricsCleanup` e o `DashboardController` filtram `adman_campaign_metrics` por `reference_date` sem índice dedicado nessa coluna (o unique é composto com `company_id` e `campaign_id`).
- Files: `database/migrations/2026_04_27_100002_create_adman_campaign_metrics_table.php`
- Cause: Unique constraint em `(company_id, reference_date, campaign_id)` — scans por `reference_date` isolado fazem full-index-scan.
- Improvement path: Adicionar `$table->index('reference_date')` em nova migration.

**`mlb_publicacoes` sem índice em `mlb_empresa_id` nem `vendido`:**
- Problem: Queries frequentes como `Publicacao::where('mlb_empresa_id', $e->id)->count()` e `->where('vendido', true)` não têm índices dedicados.
- Files: `database/migrations/2026_04_30_000001_create_mlb_publicacoes_table.php`
- Cause: Migration original criou só `(user_id, data)` e `data`. As colunas `mlb_empresa_id` e `vendido` não têm índices.
- Improvement path: `$table->index('mlb_empresa_id')` e `$table->index(['vendido', 'data'])` em nova migration.

---

## Fragile Areas

**`fetchAdsMetrics()` na AdmanService — paginação ilimitada sem teto:**
- Files: `app/Services/AdmanService.php:390-467`
- Why fragile: O loop `do { ... } while ($page <= $totalPages)` não tem limite de páginas. Uma conta grande na Adman com centenas de campanhas/adgroups pode resultar em centenas de chamadas HTTP sequenciais. O timeout de 30s por chamada não cobre o total da operação.
- Safe modification: Testar sempre com contas de menos de 10 campanhas antes de expandir a produção; adicionar `$maxPages = 200` como guard.
- Test coverage: Nenhum teste automatizado para este fluxo.

**Processo de grants sync com mecanismo triplo (exec + curl + fastcgi):**
- Files: `app/Http/Controllers/GrantController.php:146-180`
- Why fragile: Três fallbacks encadeados (exec nohup → curl fire-and-forget → fastcgi terminating). Se todos falharem silenciosamente, o status fica em `running` e trava por 10 minutos. Logs e status dependem de arquivo JSON em `storage/app/grants_sync_status.json`.
- Safe modification: Não alterar a lógica sem testar em ambiente com mesmas restrições de exec() do servidor de produção (Hostinger shared/VPS com PHP-FPM).
- Test coverage: Nenhum.

**`MlbEmpresa::progresso()` chamado para cada empresa em `->map()` sem lazy-loading:**
- Files: `app/Models/MlbEmpresa.php:127-158`, `app/Http/Controllers/MlbController.php:819,1411`
- Why fragile: `progresso()` lê `skus_estagio1/2/3` (casts JSON). Se a estrutura do JSON SKU mudar, o método falha silenciosamente retornando contagens erradas porque os campos são `?? []`.
- Safe modification: Qualquer mudança no schema de SKUs requer atualizar a lógica de `progresso()` e `avancaEstagio()`.
- Test coverage: Nenhum teste unitário para `progresso()` ou `avancaEstagio()`.

**Workspace público de implementação MLB aceita PATCH sem autenticação:**
- Files: `routes/web.php:39`, `app/Http/Controllers/MlbImplementacaoController.php`
- Why fragile: `PATCH /implementacao/{token}` é público — qualquer um com o token pode salvar dados no checklist da empresa. O token é uuid (alta entropia), mas não há expiração nem revogação.
- Safe modification: Qualquer adição de campos ao checklist deve validar que os IDs de item são do `MlbImplementacao::CHECKLIST` hardcoded no model.
- Test coverage: Nenhum.

**Enum de `publication_role` e `role` gerenciados manualmente (sem Enum PHP):**
- Files: `app/Models/User.php`, `app/Http/Controllers/UserController.php:14`, múltiplas migrations
- Why fragile: Os valores válidos de `publication_role` (`gestor`, `lider`, `publicador`, `analista`) e `role` (`admin`, `consultor`, `mentor`, `analista`) são strings verificadas por `in_array()` espalhadas pelo código. Adicionar um novo role requer busca manual de todos os locais.
- Safe modification: Usar `BackedEnum` PHP 8.1+ e cast no model.
- Test coverage: Nenhum.

---

## Scaling Limits

**Adman sync via `adman:sync` a cada 5 minutos — sequencial por empresa:**
- Current capacity: `AdmanService::syncAll()` percorre todas as empresas sequencialmente com 700ms de sleep entre cada uma.
- Limit: 50 empresas × 700ms = 35s mínimo por execução. Próxima execução começa em 5min. Com 100+ empresas e falhas de API, pode ultrapassar o intervalo.
- Scaling path: Usar filas com `SyncAdmanCompanyJob` por empresa (o Job existe em `app/Jobs/SyncAdmanCompanyJob.php` mas não é usado pelo command `adman:sync`).

**`mlb_publicacoes` crescimento ilimitado sem archiving:**
- Current capacity: Sem particionamento ou archiving. A tabela cresce com cada publicação registrada por todos os publicadores indefinidamente.
- Limit: Queries como `Publicacao::where('mlb_empresa_id', $e->id)->count()` em `->map()` ficarão cada vez mais lentas.
- Scaling path: Adicionar índice em `mlb_empresa_id`; considerar archiving de registros com mais de 1 ano.

**`adman_campaign_metrics` com cleanup apenas em 12 meses:**
- Current capacity: `MetricsCleanup` (não agendado automaticamente) remove registros com mais de 12 meses. A tabela tem registros diários por campanha por empresa.
- Limit: 50 empresas × 20 campanhas × 365 dias = ~365.000 registros/ano.
- Scaling path: Agendar `metrics:cleanup` no `console.php`; adicionar índice em `reference_date`.

---

## Dependencies at Risk

**Sem testes para código de negócio crítico — nenhuma rede de segurança:**
- Risk: Os 10 arquivos de teste existentes cobrem exclusivamente os scaffolding padrão do Laravel (Auth, Profile). Zero testes para: `MlbController`, `AdmanService`, `SugadorAnalysisService`, `MlbEmpresa::progresso()`, `CalculateGoalResults`, `SugadorPolicy`.
- Impact: Qualquer refactoring do código de negócio pode quebrar sem detecção automatizada. Deploys são cegos.
- Migration plan: Começar com testes de feature para os endpoints críticos; testes unitários para `SugadorAnalysisService::evaluateMetrics()` e `MlbEmpresa::progresso()`.

**`ImportarPlanilhaMaycon` e `ImportarPlanilhaMLB` — comandos ad-hoc não documentados:**
- Risk: Dois comandos de importação de planilha (`mlb:importar-maycon`, `mlb:importar`) que dependem de formato específico de arquivo XLSX. Se o formato da planilha mudar, falham silenciosamente com dados errados.
- Impact: Importações incorretas são difíceis de detectar sem verificação manual.
- Migration plan: Adicionar validação de headers da planilha no início do comando; documentar o formato esperado.

---

## Missing Critical Features

**Sem monitoramento/alertas de falha do adman:sync:**
- Problem: Se `adman:sync` falhar para todas as empresas (API down, chave expirada), o sistema continua funcionando sem dados novos. Nenhum alerta é emitido.
- Blocks: Gestores podem tomar decisões com dados desatualizados sem saber.

**Sem expiração de tokens de workspace público:**
- Problem: Tokens de `/ppa/workspace/{token}`, `/implementacao/{token}`, e `/nps/{token}` não têm data de expiração (exceto NPS que tem 2 dias via scheduled cleanup). Um link de implementação enviado ao cliente permanece válido indefinidamente.
- Blocks: Impossível revogar acesso de ex-clientes sem deletar o registro.

**Módulos Admin (Financeiro, Relatório, Inventário) são stubs:**
- Problem: Três páginas JSX (`Admin/Financeiro.jsx`, `Admin/Relatorio.jsx`, `Admin/Inventario.jsx`) renderizam apenas "Em Desenvolvimento" sem funcionalidade.
- Blocks: Funcionalidades prometidas pela UI não existem; não há rotas definidas para esses módulos em `routes/web.php`.

---

## Test Coverage Gaps

**Módulo MLB inteiro:**
- What's not tested: Toda lógica de KPI (`calcularKpis`), cálculo de dias úteis, projeção de meta, fluxo de publicação, regras de acesso por `publication_role`, sync de vendas.
- Files: `app/Http/Controllers/MlbController.php`, `app/Http/Controllers/PerformanceController.php`
- Risk: Regressões em cálculos de meta/projeção passam despercebidas.
- Priority: High

**SugadorAnalysisService e SugadorPolicy:**
- What's not tested: Critérios de detecção (`evaluateMetrics`), regras de acesso por carteira, lógica de upsert com status travado.
- Files: `app/Services/SugadorAnalysisService.php`, `app/Policies/SugadorPolicy.php`
- Risk: Mudanças nos thresholds de detecção podem criar falsos positivos/negativos sem detecção.
- Priority: High

**AdmanService — integração com API externa:**
- What's not tested: Paginação de adgroups, retry em 429, parsing de resposta, campos opcionais como `thumbnail` e `adgroup_type`.
- Files: `app/Services/AdmanService.php`
- Risk: Mudanças na API Adman quebram silenciosamente campos que o sistema depende.
- Priority: High

**CalculateGoalResults e CalculatePortfolioGoalResults:**
- What's not tested: Cálculo de `achieved` para cada métrica, comportamento quando `realized_value` é null, match de métricas não suportadas.
- Files: `app/Jobs/CalculateGoalResults.php`, `app/Jobs/CalculatePortfolioGoalResults.php`
- Risk: Metas reportadas incorretamente para clientes.
- Priority: Medium

**Endpoints públicos (workspace, NPS, implementacao):**
- What's not tested: Acesso com token inválido, expirado, ou de outra empresa; validação de campos do checklist.
- Files: `routes/web.php:34-42`, `app/Http/Controllers/MlbImplementacaoController.php`, `app/Http/Controllers/PpaController.php`
- Risk: Acesso a dados de empresa errada via token de outra empresa (IDOR).
- Priority: High

---

## Structural Concerns

**Projeto aninhado `ecf_admin/ecf_admin/` no disco de desenvolvimento:**
- Issue: Existe um diretório `/c/xampp/htdocs/ecf_admin/ecf_admin/` que é uma cópia antiga do projeto completo (com `artisan`, `composer.json`, `node_modules`, banco SQL exportado `ecf_admin_export.sql`).
- Files: `/c/xampp/htdocs/ecf_admin/ecf_admin/` (inteiro)
- Impact: Risco de editar arquivos no diretório errado; banco exportado pode conter dados de produção.
- Fix approach: Remover do disco; garantir que `.gitignore` cobre `ecf_admin/`.

**`routes/storage/` commitado por engano:**
- Issue: O diretório `routes/storage/` contém arquivos de framework (sessions, cache) que pertencem ao `storage/` da raiz — provavelmente gerado por link simbólico mal-configurado ou bug de deploy.
- Files: `routes/storage/framework/sessions/`, `routes/storage/framework/cache/`
- Impact: Sessões de usuário armazenadas em local incorreto podem causar logout inesperado ou conflito com o storage real.
- Fix approach: Deletar `routes/storage/`; verificar configuração de `FILESYSTEM_DISK` e links simbólicos; adicionar ao `.gitignore`.

**Comandos diagnósticos/debug versionados como código de produção:**
- Issue: `DiagnosticSyncVendas.php` e `InspecionarAdman.php` são comandos de debug temporários presentes no código-base (aparecem no `git status` como novos arquivos). Não têm testes, mas estão no namespace de produção `App\Console\Commands`.
- Files: `app/Console/Commands/DiagnosticSyncVendas.php`, `app/Console/Commands/InspecionarAdman.php`
- Impact: Comandos de debug ficam disponíveis em produção via `php artisan`.
- Fix approach: Se temporários, adicionar ao `.gitignore` e não commitar; se permanentes, documentar claramente e adicionar flag `--production-safe`.

---

*Concerns audit: 2026-05-18*
