---
slug: audit-margem-luiz-ana
status: resolved
trigger: Maioria das empresas da carteira do Luiz Henrique e Ana Julia estão sem margem de contribuição em julho/2026 (mês em curso), mas TÊM em junho e a Adman mostra os valores diretamente. Padrão concentrado nesses 2 profissionais — outros analistas têm no máximo 4-5 empresas sem margem.
created: 2026-07-10
updated: 2026-07-10
criticality: alta
---

# Auditoria: margem de contribuição sumida na carteira do Luiz e Ana Julia

## Symptoms

**Expected behavior:**
Toda empresa que tem venda deveria ter `AdmanMetric.contribution_margin` populado nos últimos dias do mês em curso (com o lag documentado de 1-3 dias no fim da janela). Distribuição de "empresas sem margem" deveria ser aproximadamente uniforme entre os analistas/estrategistas.

**Actual behavior:**
- Carteira do **Luiz Henrique** (user_id 3): MAIORIA das empresas mostra "—" ou null no campo margem
- Carteira da **Ana Julia** (user_id 17): mesmo padrão — MAIORIA sem margem
- Outros analistas/estrategistas (Gabriela, Nathalia, Débora, Gustavo etc): máximo 4-5 empresas sem margem
- Filtrando `?mes=2026-06` para as mesmas empresas do Luiz/Ana → margem PRESENTE em junho
- Dashboard Adman externa mostra margem populada para essas empresas em julho

**Error messages:**
Nenhum erro no frontend — só campo null / "—" no display. Confirmado: não há erro/exception no sync — é um gap estrutural de dados (ver Root Cause).

**Timeline:**
Detectado 2026-07-10. Investigação determinou que o padrão real começou exatamente em **2026-06-30** (ver Evidence).

**Reproduction:**
1. Login como admin
2. Acessar `/admin/users/{id_luiz}/portfolio` — coluna margem majoritariamente vazia
3. Acessar `/admin/users/{id_ana_julia}/portfolio` — mesmo padrão
4. Comparar com `/admin/users/{id_gabriela}/portfolio` — margem em quase todas
5. Trocar filtro pro mês passado (`?mes=2026-06`) — Luiz/Ana voltam a ter margem

## Contexto técnico

- ECF Admin — Laravel 12 + Inertia + React
- Fonte canônica de margem: `AdmanMetric.contribution_margin` (populado por sync diário)
- ML API não expõe margem por design (custo unitário indisponível — memória `project_adman_data_sources`)
- Sync Adman roda 11h BRT via `adman:sync` (rotas/console.php)
- Existem 2 fontes Adman: sync agendado (grava em `adman_metrics`) + MCP (só drilldown Sugadores)
- Empresas ML-only podem retornar 422 em endpoints Adman MCP (memória `project_ml_only_companies_adman_endpoints`)
- Ajuste 2026-07-10 (Tomelin): computeVarMargem já usa recorte simétrico de dias-fim pra lag da Adman — não corrige gap no meio

## Hipóteses iniciais (a serem testadas)

1. **Fonte-cruzada de sync não populando `contribution_margin`** — se sync Adman parou de trazer margem PRA CONTAS ESPECÍFICAS a partir de alguma data, empresas dos Luiz/Ana (que compartilham alguma característica com essas contas) ficam sem
2. **Luiz e Ana têm maior proporção de empresas ML-OAuth** — se sync Adman para dessas contas quando OAuth ML tá ativa (bug de exclusão errada), explicaria o padrão — **CONFIRMADA, com ressalva: não é bug de exclusão errada, é comportamento intencional do cutover que deixou um gap de dado não coberto**
3. **Sync AdmanSyncCompany falhando silenciosamente pra essas empresas** — token Adman inválido, rate limit, sync log com erros específicos — **ELIMINADA**
4. **Adman API mudou schema pra algumas contas em julho** — o campo pode ter mudado de nome ou local no payload — **ELIMINADA**
5. **Empresas do Luiz/Ana foram reatribuídas recentemente** (pivot rebind) e novo sync ainda não rodou pra elas — **ELIMINADA**
6. **Filtro na query de sync excluindo essas empresas** — bug no `AdmanService::syncCompany` que pula quando alguma condição bate — **PARCIALMENTE CONFIRMADA** (o filtro existe e é intencional — ver Root Cause)

## Current Focus

- **hypothesis:** CONFIRMADA — ver Resolution
- **next_action:** nenhuma (goal = find_root_cause_only; fix não aplicado nesta rodada)
- **test:** concluído
- **expecting:** concluído

## Escopo da auditoria

- Identificar empresas específicas do Luiz e Ana sem margem em julho — feito
- Verificar quantas têm mlToken ativo — feito
- Comparar com carteira de Gabriela/Nathalia (mesmo tipo de sync, mesmas condições) — feito
- Investigar `AdmanService`/`MercadoLivreService` — como popula `contribution_margin`? Existe filtro que exclui empresas OAuth? — feito
- Ver logs de sync recente — não necessário (sem erro/exception, é gap estrutural)
- **NÃO aplicar fix** nesta rodada — só diagnóstico (respeitado)

## Arquivos suspeitos

- `app/Services/AdmanService.php` (sync + populate contribution_margin)
- `app/Services/MercadoLivreService.php` — **confirmado como fonte do gap** (`syncCompany()`, linhas 745-815, comentário linha 787)
- `app/Jobs/SyncAdmanCompanyJob.php` (job async por empresa)
- `app/Console/Commands/SyncAdmanData.php` — **confirmado**: linha 83, `whereDoesntHave('mlToken', fn($q) => $q->where('status', 'active'))`
- `app/Models/AdmanMetric.php` (schema + casts)
- `app/Models/Company.php` — `is_ml_driven` accessor (linha 98)
- Commit `c85b86f` ("feat(ml): cutover Adman -> ML por empresa", 2026-06-01) — introduz o roteamento

## Evidence

- timestamp: 2026-07-10T00:00:00-03:00
  ação: Identificação de user_ids via SQL (`SELECT id, name FROM users WHERE name LIKE...`)
  resultado: Luiz Henrique = id 3, Ana Julia = id 17, controles: Gabriela=10, Nathalia=11, Débora=12, Gustavo=16

- timestamp: 2026-07-10T00:05:00-03:00
  ação: Distribuição de empresas com `ml_tokens.status='active'` por analista (join `company_users`+`companies`+`ml_tokens`)
  resultado:
  ```
  user_id | total_empresas | com_ml_oauth_ativo | %
  3 (Luiz)     | 24 | 23 | 96%
  17 (Ana)     | 23 | 19 | 83%
  11 (Nathalia)| 36 | 27 | 75%
  10 (Gabriela)| 18 | 12 | 67%
  16 (Gustavo) | 13 |  6 | 46%
  12 (Débora)  |  5 |  1 | 20%
  ```
  Luiz e Ana têm a MAIOR proporção de empresas ML-OAuth ativo da carteira, mas Nathalia/Gabriela também são altas sem apresentar o sintoma reportado no mesmo grau — indício de que a proporção sozinha não basta, precisa correlacionar com quando o token foi ativado (ver evidência seguinte).

- timestamp: 2026-07-10T00:10:00-03:00
  ação: Contagem de dias com `contribution_margin IS NULL` em julho (01-09) vs junho (completo), por empresa, carteira Luiz/Ana
  resultado: TODAS as empresas com `ml_oauth_ativo=1` mostram `jul_null = jul_total` (100% NULL em julho), enquanto as poucas empresas SEM mlToken (`ml_oauth_ativo=NULL`) mostram `jul_null=0`. Em junho, a mesma empresa tinha `jun_null` baixo (0-1 de ~25 dias — lag normal documentado).

- timestamp: 2026-07-10T00:15:00-03:00
  ação: Query system-wide (todas as 127 empresas ativas), agrupando por `ml_oauth_ativo`
  resultado:
  ```
  ml_oauth_ativo | n_empresas | jul_dias_null/jul_dias_total | jun_dias_null/jun_dias_total
  NULL (sem token)| 68 | 0/454 (0%)     | 0/1189 (0%)
  1 (ativo)       | 59 | 397/518 (76.6%)| 50/1355 (3.7%, lag normal)
  ```
  Confirma que o NULL está 100% correlacionado com `ml_tokens.status='active'`, não com o analista em si. Luiz/Ana só aparecem "piores" porque têm mais empresas nessa condição.

- timestamp: 2026-07-10T00:20:00-03:00
  ação: Breakdown diário (2026-06-20 a 2026-07-09) de nulls entre empresas com ml_oauth ativo
  resultado: Inflexão exata em **2026-06-30**: nulls saltam de 1 (lag normal) para 23, depois sobem para ~57-59 (quase 100%) até 07-09. Antes de 06-30, praticamente nenhuma null mesmo entre empresas com token ativo.

- timestamp: 2026-07-10T00:25:00-03:00
  ação: Inspeção de `ml_tokens.created_at` para as empresas afetadas do Luiz/Ana
  resultado: Maioria dos tokens foi criada entre **2026-06-30 e 2026-07-08** (lote de conexões OAuth ML recente/onboarding em massa nessas duas carteiras) — coincide com a data de inflexão.

- timestamp: 2026-07-10T00:30:00-03:00
  ação: Teste de controle — empresa com o token ML mais ANTIGO do sistema (company_id 298, "ByMobille - Teste", token criado 2026-05-28) — verificar se idade do token importa
  resultado: `contribution_margin`/`product_cost` NULL desde **2026-06-01** (data do deploy do commit de cutover) mesmo para esse token antigo — confirma que o gap é estrutural (qualquer empresa ML-driven, independente de quando conectou) e não uma falha transitória ou específica de onboarding recente.

- timestamp: 2026-07-10T00:35:00-03:00
  ação: Comparação direta de `raw_data` (payload bruto salvo) para a empresa 217 (DROSSI INTERIORES) entre 2026-06-27 e 2026-07-01
  resultado: Até 06-29, `raw_data` tem schema nativo da Adman (`{"grossBilling":...}`) e `product_cost`/`contribution_margin` populados normalmente. A partir de 06-30, `raw_data` passa a ser `{"source":"ml_direct","orders":{...},"ads":{...}}` — schema completamente diferente, sem nenhum campo de custo — e `product_cost`, `contribution_margin`, `contribution_margin_pct`, `products_without_cost` ficam NULL a partir dessa data, enquanto `revenue` e `ad_spend` continuam populados normalmente.

- timestamp: 2026-07-10T00:40:00-03:00
  ação: Leitura de `app/Console/Commands/SyncAdmanData.php` (linha 73-84)
  resultado: Query de fan-out do `adman:sync` (11h BRT) tem `->whereDoesntHave('mlToken', fn($q) => $q->where('status', 'active'))` — comentário no código confirma: "Cutover Adman → ML (Opção A): empresas com token ML ativo são sincronizadas só pelo ml:sync. Sem isso, adman:sync (11:00) e ml:sync (11:05) gravavam a MESMA linha adman_metrics e o ML sobrescrevia — gerando linhas mistas". Ou seja: **por design, a partir do momento em que uma empresa tem mlToken ativo, o `adman:sync` (única fonte que tem `contribution_margin`) PARA de rodar pra ela, permanentemente.**

- timestamp: 2026-07-10T00:45:00-03:00
  ação: Leitura de `app/Services/MercadoLivreService.php::syncCompany()` (linhas 745-815)
  resultado: Este é o método que roda via `ml:sync` para empresas ML-driven. Ele busca `fetchOrdersSummary()` (revenue, sold_quantity, sales_fee) e `fetchAdsSummary()` (ad_spend) da API oficial do Mercado Livre, e grava via `AdmanMetric::updateOrCreate(...)` **sem nunca incluir** `contribution_margin`, `contribution_margin_pct`, `product_cost` ou `products_without_cost` no array de campos. Comentário inline na linha 787: `// Campos não disponíveis diretamente via ML API (exigiriam CMV do seller)`. Como o registro do dia é **criado pela primeira vez** por esse método (não é um update de linha pré-existente da Adman), os campos de custo ficam NULL por default do schema — não há nenhum valor prévio pra preservar.

- timestamp: 2026-07-10T00:50:00-03:00
  ação: Confirmação de origem via `git log`/`git show` do commit que introduziu o roteamento
  resultado: Commit `c85b86f` — "feat(ml): cutover Adman -> ML por empresa (token ML ativo assume)" — 2026-06-01. Mensagem do commit já documenta a decisão: "empresa com token Mercado Livre ATIVO passa a ser 'ML-driven' — o sistema usa o caminho ML e para de chamar a Adman para ela, mesmo que ainda tenha adman_account_id." Resolvia um problema de dados híbridos/zerados quando ML era vinculado numa empresa Adman, mas não cobriu a lacuna de que a Adman é a ÚNICA fonte com dado de custo/margem.

## Eliminated

- **Hipótese 3** (sync falhando silenciosamente / token Adman inválido / rate limit): eliminada — não há tentativa de sync Adman para essas empresas; elas são explicitamente excluídas da query do `adman:sync` (não é falha, é exclusão intencional).
- **Hipótese 4** (Adman mudou schema do payload pra algumas contas em julho): eliminada — o payload Adman nunca é mais buscado pra essas empresas; o `raw_data` muda de schema porque a FONTE muda (Adman → ML direct), não porque a Adman alterou seu formato.
- **Hipótese 5** (empresas reatribuídas recentemente / pivot rebind sem novo sync): eliminada — o padrão está 100% correlacionado com `ml_tokens.status='active'`, não com mudança de responsável/analista. Testado companhia com token desde maio (298) e mesmo padrão se repete.

## Resolution

**root_cause:**
Desde o commit `c85b86f` (2026-06-01, "cutover Adman → ML por empresa"), toda empresa com `ml_tokens.status='active'` é considerada "ML-driven" e o comando `adman:sync` (11h BRT) a EXCLUI permanentemente da sincronização com a API Adman (`SyncAdmanData.php` linha 83) — que é a ÚNICA fonte de dados que contém custo de produto / margem de contribuição. Em vez disso, essas empresas passam a ser sincronizadas apenas via `MercadoLivreService::syncCompany()` (rota `ml:sync`), que busca pedidos e publicidade direto da API oficial do Mercado Livre — mas essa API **não expõe CMV/custo do vendedor**, então o método nunca popula `contribution_margin`, `contribution_margin_pct`, `product_cost` nem `products_without_cost` (comentário explícito no código confirma que essa é uma limitação conhecida e aceita no momento do cutover).

O sintoma "concentrado no Luiz e na Ana Julia" não é causado por nada específico desses dois profissionais — é resultado de dois fatores que se somam nas carteiras deles:
1. Eles têm a MAIOR proporção de empresas com ML OAuth ativo entre todos os analistas (96% e 83%, vs. 20-75% dos demais);
2. A maioria dessas conexões ML OAuth nas carteiras deles foi feita/ativada num lote concentrado entre **2026-06-30 e 2026-07-08** — exatamente quando o "mês em curso" (julho) começou a ser sincronizado exclusivamente pelo caminho ML, sem margem.

Junho aparece "normal" porque, para a maior parte do mês, essas empresas ainda eram sincronizadas pela Adman (token ainda não estava ativo ou tinha acabado de ativar perto do fim do mês). A dashboard Adman externa mostra margem populada porque o problema é de ROTEAMENTO interno do ECF Admin (deixamos de perguntar à Adman) — a conta Adman em si continua existindo e sendo alimentada normalmente do lado deles.

Evidência confirmatória (não apenas plausível):
- Correlação 100% entre `ml_tokens.status='active'` e `contribution_margin IS NULL` em julho, testada em toda a base (127 empresas), não só na carteira do Luiz/Ana.
- Inflexão exata em 2026-06-30 no volume de NULLs, coincidindo com o lote de ativações de token ML nessas carteiras.
- `raw_data` muda literalmente de schema Adman-nativo para `{"source":"ml_direct",...}` no exato dia em que os campos de custo somem.
- Código-fonte confirma ambas as pontas do mecanismo: exclusão em `SyncAdmanData.php:83` + ausência estrutural de custo em `MercadoLivreService.php:779-792` (com comentário explícito do autor original reconhecendo a limitação).

**fix:** não aplicado (goal = find_root_cause_only, conforme solicitado).

**Observação para follow-up (fora de escopo desta auditoria):** qualquer fix precisa decidir entre (a) buscar CMV/custo do vendedor por outra via quando a empresa é ML-driven, (b) continuar consultando a Adman só para o campo de margem mesmo com ML ativo (reintroduzindo parcialmente o "conflito de linha mista" que o cutover original queria evitar — precisaria merge seletivo de campos em vez de exclusão total), ou (c) tornar explícito na UI que margem não está disponível para empresas ML-driven (em vez de mostrar "—" sem explicação, que hoje aparenta ser um bug/dado perdido, indo contra a diretriz do projeto de "evitar jargão/estado não explicado na UI").
