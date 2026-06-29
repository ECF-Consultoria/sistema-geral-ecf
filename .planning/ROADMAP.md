# Roadmap: ECF Admin

## Overview

Evolução do painel de administração interno do ECF Admin em milestones incrementais.
**v1.0 — Setor Dev** (diagnóstico Adman) e **v2.0 — Administrativo Fechamento** já entregues.
**v3.0 — Sistema de Notificações** entregue (phases 8-12): sino no header, histórico paginado,
criação manual com targeting (usuário / setor / líderes / todos), disparos automáticos a partir de
eventos das três famílias de metas (`Goal`, `PortfolioGoal`, `SetorGoal`) e atualização real-time
via polling + revalidação Inertia.
**v4.0 — Fluxo Comercial** (milestone ativo) cria o fluxo centralizado de cadastro de empresas
pelo setor Comercial, com roteamento automático por service_type, migração retroativa de mlb_empresas
e visibilidade de pendentes nos setores de destino. Cada fase entrega uma capacidade observável de ponta a ponta.

## Phases

**Phase Numbering:**
- Integer phases (1, 2, 3): Planned milestone work
- Decimal phases (2.1, 2.2): Urgent insertions (marked with INSERTED)

Decimal phases appear between their surrounding integers in numeric order.

### Milestone v1.0 — Setor Dev

- [x] **Phase 1: Diagnóstico Adman** - Admin pode inspecionar e controlar o sync Adman por empresa sem acessar o servidor
- [ ] **Phase 2: Monitoramento de Jobs** - Admin pode ver o estado da fila de jobs, incluindo falhas com detalhes completos *(pausado — retomar em v4.0)*
- [ ] **Phase 3: Observabilidade** - Admin pode ver logs de erro do sistema e informações do ambiente de execução *(pausado — retomar em v4.0)*
- [ ] **Phase 4: Configurações** - Admin pode visualizar e editar flags de configuração do sistema via painel *(pausado — retomar em v4.0)*

### Milestone v2.0 — Administrativo Fechamento

- [x] **Phase 5: Fundação Fechamento** - Banco de dados e campos Company que suportam tipo de serviço, datas de contrato e renomeação de sidebar
- [x] **Phase 6: Backend Fechamento** - Aggregation query sobre adman_metrics, cálculo de faixa e props Inertia entregues ao frontend (completed 2026-05-19)
- [x] **Phase 7: UI Fechamento** - Reescrita de Financeiro.jsx como Fechamento com lista de empresas, barras de progresso e total consolidado

### Milestone v3.0 — Sistema de Notificações

- [x] **Phase 8: Fundação de Notificações** - Tabela `notifications`, classe base e permission `notificacoes.criar` disponíveis para o resto do sistema usar (completed 2026-05-21)
- [x] **Phase 9: Backend de Leitura, Contador e Polling** - Endpoints de listagem/marcação, shared prop do contador e polling funcionando ponta a ponta sem UI (completed 2026-05-21)
- [x] **Phase 10: UI do Sino e Página de Histórico** - Usuário vê e interage com suas notificações via sino no header e página `/notificacoes` (completed 2026-05-21)
- [x] **Phase 11: Disparos Automáticos de Metas** - Atribuição e atingimento de qualquer tipo de meta gera notificações automáticas para o público correto (completed 2026-05-21)
- [x] **Phase 12: Criação Manual, Permissão na UI de Setores e Cleanup** - Usuário autorizado envia notificações com targeting; permissão aparece em `/sistema/setores`; cleanup diário descarta lidas antigas (completed 2026-05-21)

### Milestone v4.0 — Fluxo Comercial

- [x] **Phase 13: Reestruturação do Cadastro de Empresas** - Setor Comercial é a única porta de entrada para novas empresas; cadastro roteia automaticamente por service_type; migração retroativa vincula mlb_empresas a companies (completed 2026-05-25)
- [x] **Phase 14: Consolidação do Modelo de Serviços (Frente B)** - Modelo unificado: campos legacy de companies (service_type/contract_start/contract_end/additional_service/additional_service_price) substituídos por contratos_servico N:N introduzido na quick task 260526-jgj; Fechamento, Comercial e demais consumidores migrados sem alterar resultados financeiros (completed 2026-05-26)

### Milestone v4.1 — Eficiência Operacional Sugadores

- [x] **Phase 15: Sugadores — UI por Empresa + Auto-resolução + Atalhos Operacionais** - Aba Sugadores migra do paradigma "lista global" para "cards por empresa com drilldown"; análise diária auto-resolve sugadores pendentes que não atendem mais critérios (combate acúmulo); botão de copiar MLBs em massa no drilldown do AdGroup; reanálise direto do card (completed 2026-05-27, deployed to prod)

### Milestone v4.2 — Adequação à Cadência D-1 da Adman

- [x] **Phase 16: Adequação à cadência D-1 da API Adman** - Reduz chamadas de ~2k/h para ~168/dia alinhando schedule, caches e UX ao fato de que a API é D-1 (atualiza 1× às 10h BRT). Cascata de jobs reorganizada para 11h-12h30. Botão "Sincronizar agora" removido. Botão "Reanalisar" do card Sugadores bloqueia quando já houve sync no dia. Throttle ≥6s para respeitar limite de 10 req/min documentado pela Adman. (completed 2026-05-27, deployed; **redução de 429 confirmada em 98%**: ~7.500/dia → ~140/dia em 6 dias de produção)

### Milestone v5.0 — Inteligência de Anúncios ML

- [x] **Phase 17: Coleta de Dados ML (Fase 1 — sem IA)** - Dada uma keyword, minera keywords de concorrentes via API oficial do ML (app token), agrupa dúvidas das perguntas dos clientes e gera recomendação heurística de título/descrição — assíncrono, com feedback de progresso e persistência para histórico. (completed 2026-06-02)

### Milestone v6.0 — Precisão e Praticidade na Dashboard

- [x] **Phase 18: Dashboard precisa e com filtros empilháveis** - Corrige 3 bugs reportados pelo usuário em 2026-06-02: (1) trocar o filtro de tempo não muda os dados dos cards principais (range 30d hardcoded ignora `$period`); (2) selecionar empresa + período perde a empresa (inconsistência camelCase/snake_case entre `filters` retornado pelo controller e query params lidos pelo controller); (3) soma de faturamento da Dashboard não bate com a Adman para o mesmo período. Aplica diretamente as duas regras-mestras: **acertividade** (números batem com a fonte) e **praticidade** (filtros combinam de verdade). (completed 2026-06-03, deployed; 25 testes verdes; auditoria revelou causa raiz da divergência → Phase 18.5)
- [x] **Phase 18.5: Marketplace dinâmico no AdmanService + import CSV oficial** - Investigação da Phase 18 revelou que o `AdmanService::$marketplace` estava hardcoded em `'meli'` e quebrava para 34 contas Shopee/Amazon. Implementação: coluna `companies.marketplace`, comando `dashboard:import-marketplace-from-csv` (33 atualizadas: 32 Shopee + 1 Amazon), refator do `AdmanService` (8 endpoints aceitam `$marketplace`), 6 callers críticos atualizados. **Resultado validado em prod**: `cust_id_status='invalido'` caiu de 32 para 2 (94% de redução); soma DB cresceu de R$ 15,4M → R$ 16,1M com 1 dia de sync das Shopee. Gap histórico de 29 dias se preenche naturalmente com cache D-1 da Phase 16. (completed 2026-06-03, deployed; 7 testes verdes)

### Milestone v7.0 — Eficiência Operacional Sugadores (Foco no dia)

- [x] **Phase 19: Sugadores — Foco no dia + Atalhos + Fix MCP** - Reforça as duas regras-mestras (acertividade + praticidade) no módulo Sugadores. (1) Banner explícito de cadência D-1 ("Análise diária às 12h BRT") + estado por empresa. (2) Vista default filtra `reference_date=hoje + status=pendente` para focar o operador no que importa hoje (esconde 1407 acumulados que confundem). (3) Botão "Copiar MLBs" inline em cada linha de sugador + no card de empresa (modo cards) — ação em 1 clique sem abrir drilldown. (4) Mitigação do 429 do MCP no drilldown (throttle por custId + Cache::lock). (5) Comando one-shot `sugadores:limpar-orfaos` para marcar como `auto_resolvido` os 1407 pendentes antigos acumulados. (completed 2026-06-03)
- [x] **Phase 20: Integração ECF Drive (substitui sync SFTP por API HTTP)** - Troca a fonte de dados do módulo `/grants`: pipeline `grants:sync-sftp` substituído por wrapper `EcfDriveService` que consome a API do sistema externo ECF Drive (`files.ecfconsultoria.com.br/api/v1`). Mantém model `CompanyGrant`, página `/grants`, permissão `core.grants`. Adiciona migration `segmento`, comando `grants:sync-ecf`, schedule diário. **Plano 02 (mesmo dia)**: match ESTRITO por cust_id (`adman_account_id` ou `ml_store_id`) — removido fallback CNPJ por risco de associar grants de alunos de cursos a empresas com mesmo CNPJ. **Validado em prod**: 475 grants recebidos, 80 matched, 395 órfãos (alunos/contas pessoais ML — comportamento esperado). API foi ajustada pelo usuário pra corrigir inversão `granted_at > expires_at` (13 falsos "Pending" → Active). Webhook real-time fica para fase futura. (completed 2026-06-05, deployed; 20 testes Phase 20 verdes)
- [x] **Phase 21: Manual do Sistema (artigos para usuários não-técnicos)** - Cria aba "Manual do Sistema" no rodapé da sidebar, acessível a TODOS os usuários autenticados. Artigos hardcoded em JSX, sem CMS no banco. Primeiro artigo: "Cronograma de horários" — tabela ordenada explicando em linguagem simples (sem termos técnicos) o que cada rotina automática do sistema faz e quando roda (sync diário da Adman, análise de Sugadores, sincronização do ECF Drive, etc). Arquitetura extensível para artigos futuros via componentes JSX em `resources/js/Pages/Manual/Artigos/`. (completed 2026-06-05)

### Milestone v8.0 — Integração Estratégica ECF Drive

Expansão profunda da API ECF Drive (já integrada em Phase 20 apenas pra /grants) para destravar **decisão estratégica** + **detecção precoce de churn** + **automação executiva**. ECF Drive abstrai o SFTP do ML e expõe REST com endpoints de Sellers (métricas operacionais), Carteira (agregados), Signals (alertas automáticos), Relatórios e Webhooks. Cada phase é independente após Phase 22 (base wrapper) — pode ser executada e deployada isolada.

- [x] **Phase 22: Wrapper expandido EcfDriveService** - Expande `EcfDriveService` da Phase 20 com 18 métodos novos cobrindo `/clientes/*`, `/sellers/*`, `/carteira/*`, `/signals/*`, `/relatorios/*`. Cache estratégico por endpoint (5min listas, 1h ranking, 24h relatórios). Validações defensivas (`MET_VALIDAS` no ranking, ≤20 cust_ids em comparar, `dimensoes` não-vazio). **47 testes verdes** (27 Phase 22 + 20 Phase 20 regressão). Smoke W4 validou 4/5 chamadas em prod: `carteiraResumo` R$ 42,8M GMV maio/2026, `ranking` top1 RELOJOARIA WENUS R$ 2,7M, `listSignals` 778 alertas, `relatorioMensal` 202605. `ping()` retorna false (cosmético — `/auth/me` exige user session; follow-up: substituir endpoint). Sem UI por design — habilita Phases 23-28. (completed 2026-06-05, deployed)
- [x] **Phase 23: Alertas Estratégicos (signals)** - Aba `/alertas-estrategicos` consumindo `/signals` em polling diário (MVP — webhook em Phase 26). Caixa de entrada do comercial com filtros por severidade e tipo (queda GMV, queda visitas, medalha rebaixada, score crítico, oportunidade PADS). Ação "marcar como visto" via `POST /signals/:id/ack`. **778 signals já detectados em prod** (61 críticos), destravando ação comercial imediata. Convive com Sugadores (operacional, adgroup-level) sem substituí-lo.
 (completed 2026-06-05)
- [x] **Phase 24: Painel Executivo Carteira ECF** - Aba `/painel-executivo` com visão estratégica consolidada da carteira inteira ECF (~1238 sellers, R$ 42,8M GMV maio/26). 8 KPI cards com delta MoM colorido (TrendingUp/Down/Minus), gráfico histórico 12 meses com duplo eixo Y (GMV + Sellers), 4 tabs de breakdown (Programa/Frete/Cluster/Localidade) com PieChart + tabela. Try/catch global (ECF Drive offline não quebra pageload). Item "Painel Executivo" no topo da sidebar (só admin). 8 testes Feature verdes (71 assertions). (W1+W2+W3 completed 2026-06-05 — aguardando smoke visual W4)
- [x] **Phase 25: Análise por Empresa (Sellers)** - Página `/empresas/{custId}/analise` com ficha 360° do seller usando `/sellers/{custId}`, métricas mensal/diário, histórico de medalhas, signals daquele seller, ranking. Destrava a substituição parcial do drilldown Adman MCP (frágil — Phase 19 lutou contra 429) para campos que ECF Drive cobre.
 (completed 2026-06-06)
- [x] **Phase 26: Webhooks completos ECF Drive** - Receiver `POST /api/webhooks/ecf` validando HMAC SHA256 timing-safe (`X-ECF-Signature`) para 6 eventos: `sync.completed`, `sync.failed`, `etl.completed`, `grant.expirando`, `signal.detected`, `relatorio.gerado`. Idempotência via tabela `webhook_deliveries` (UNIQUE event_id), dispatch async via 6 Jobs em `app/Jobs/EcfWebhook/`, rate limit 600/min/IP, canal log dedicado `ecf-webhooks`. **Smoke real em prod**: 6,56ms latência (alvo <100ms), HMAC válido aceito, inválido rejeitado, idempotência confirmada. Webhook configurado no painel ECF Drive com todos os 6 eventos. (completed 2026-06-05, deployed; 6 testes Phase 26 verdes)
- [x] **Phase 27: Concentração de Receita e Forecast** - Aba `/concentracao` (só admin) com 3 seções: (1) matriz heatmap programa × cluster via `/carteira/segmentacao`; (2) forecast 90 dias com regressão linear sobre `/carteira/historico` (3 cenários otimista/base/pessimista, R² na UI); (3) top 20 "vacas leiteiras silenciosas" (top 50 do ranking cruzado com coeficiente de variação das métricas mensais). `ForecastService` com 3 funções puras. 17 testes verdes (10 Unit + 7 Feature). **Insights reais em prod**: top vaca IMPERIALECOMMERCEOFICIAL (CV 4,3%, R$ 394k médio); top grant em risco RELOJOARIA WENUS (R$ 5,5M, 4d). 4 Planos de fix no mesmo dia (camelCase API, labels pt-BR sem expansão, lookup company nos grants, revert workaround após parceiro corrigir BrasilAPI — diversidade de razões sociais subiu de 1 para 235). (completed 2026-06-06, deployed)
- [x] **Phase 28: Relatório Mensal Executivo automatizado** - Job mensal (dia 6 às 10h BRT, após webhook `relatorio.gerado` dia 5 09:00 UTC) consome `/relatorios/mensal/{timMonthId}` e gera PDF executivo via Dompdf, enviado por email à liderança. Onboarding rápido (mostra estado completo da carteira para novos gestores). Depende de Phase 26 para receber o webhook.
 (completed 2026-06-06)

### Milestone v9.0 — Sistema de Notificações 2.0

A v8.0 entregou a infraestrutura de webhooks (Phase 26) que recebe 6 eventos do ECF Drive em tempo real, mas os handlers só fazem `Log::channel('ecf-webhooks')` — o sino do header (Phase 10) NÃO acende quando algo chega. A v9.0 costura essa lacuna: webhooks viram notificações reais no banco usando a infra `BaseNotification` da Phase 8/12 que já entrega via `database` channel + polling do sino.

- [x] **Phase 29: signal.detected vira notificação no sino** (completed 2026-06-08) - Integra `HandleSignalDetectedJob` (Phase 26) com `BaseNotification` (Phase 8). Quando ECF Drive envia push de `signal.detected` severity=critical para empresas da NOSSA carteira (lookup local `Company` por cust_id), cria notificação na tabela `notifications` da Phase 8 destinada a admin + consultor + mentor. Sino do header automaticamente acende via polling do shared prop existente. Categoria nova `ALERTA_ECF` na enum, título descritivo pt-BR (ex: "Queda crítica de faturamento em RELOJOARIA WENUS"), link direto para `/alertas-estrategicos`. Filtros: apenas carteira local + apenas critical para evitar ruído. Outros eventos ficam para fases futuras. **Smoke prod validado**: 13 notifications criadas (1 admin + 2 mentors + 10 consultores) para signal cust_id 570267839 (RELOJOARIA WENUS), idempotência confirmada (2º webhook com mesmo signal_id 9101 não duplicou). 21 testes verdes.
- [ ] **Phase 31: grant.expirando vira notificação pra time comercial** - Integra `HandleGrantExpirandoJob` (Phase 26) com `BaseNotification`. Quando ECF Drive envia push de grant vencendo em 30/15/7 dias para empresa da carteira, cria notificação destinada ao consultor + admin pra renovação preventiva. Reusa pattern Phase 29 (filtro carteira, idempotência por grant_id, link direto pra `/grants` ou `/companies/{id}`). Diferencial: 3 disparos por grant (30d/15d/7d antes do vencimento) sem duplicar.

### Milestone v9.5 — Sugadores Robustos

Módulo de Sugadores (detecção de adgroups que consomem orçamento sem retorno) tem 3 problemas em prod que precisam de solução conjunta:

1. **Rate limit 429 Adman** (10 req/min hard limit) — contas grandes batem o limite, queue marca falha, retry só em ~10min. Mensagem "Tentativa 1/5 falhou" é frequente.
2. **Paginação truncada** — "8 de 189 páginas lidas" porque o job estoura timeout antes de varrer tudo. Adgroups dos finais da paginação somem do resultado.
3. **Empresas ML-only não funcionam** — Bymobile teste (e futura maioria) sem `adman_account_id` vê "Empresa sem adman_account_id" ao clicar em "Carregar MLBs". Sugadores não consegue rodar.

v9.5 entrega só W1 (throttled queue Adman, já em prod). W2 (`SugadorAnalysisServiceMl` espelho) e W3/W4 foram **superseded pela Milestone v11.0** em 2026-06-25 após decisão arquitetural de adotar **provider pattern** ao invés de mirror service (ver `plano-migracao-sugadores-ml-direto.md`).

- [~] **Phase 30: Sugadores Robustos** — W1 throttled queue Adman shipped (30-01, commit `faf1a9a` 2026-06-08). W2/W3/W4 (plans 30-02/03/04) **superseded** pela v11.0 — destrava Bymobile via provider pattern com shadow + cut-over, não via mirror service.

### Milestone v11.0 — Migração Sugadores Adman → ML (Fontes Unificadas Fase 1)

Migra o módulo Sugadores da Adman API para a API oficial do Mercado Livre via **provider pattern** (`SugadoresAdsProvider` contract + `AdmanSugadoresProvider` + `MercadoLivreSugadoresProvider`). Adman vira fallback até cut-over por empresa estar validado. Preserva 100% da UI, FSM de status, schema de `sugadores`/`sugador_configs`/`sugador_acoes` e contrato normalizado de `evaluateMetrics`. Bymobile teste é piloto da Fase 0 (smoke). Origem técnica: `plano-migracao-sugadores-ml-direto.md` (importado via /gsd-import 2026-06-25, decisão "provider pattern" + slicing "nova milestone substituindo Phase 30 W2/W3").

- [~] **Phase 38: Smoke ML (piloto Bymobile)** *(partially_complete — código pronto + tests verdes; smoke real deferido por MariaDB local corrompido)* - Comando `sugadores:ml-smoke --company={id} --days=30` resolve `mlToken` (com refresh), descobre `advertiser_id`, lista campanhas e ads, tenta métricas no período, grava fixture JSON anonimizável em `storage/app/sugadores/ml-smoke/`. Critério de aceite: relatório curto com endpoints que funcionaram, campos disponíveis vs ausentes, equivalência com contrato normalizado, blockers de permissão/token. NÃO grava em `sugadores`. Bloqueia avanço pra Phase 39 se não estiver verde (BLOQUEIO ATIVO).
- [x] **Phase 39: Provider pattern + MercadoLivreSugadoresProvider (sem gravar)** *(completed 2026-06-25 — 5/5 plans: 39-01 ✓ + 39-02 ✓ + 39-03 ✓ + 39-04 ✓ + 39-05 ✓; todas as 4 waves verdes; 48/48 Phase 39 tests; 65/65 Sugador acumulada — zero regressão)* - `SugadoresAdsProvider` contract + `AdmanSugadoresProvider` encapsulando lógica atual + `MercadoLivreSugadoresProvider` + `MercadoLivreAdsService` (retries, paginação, refresh token). Repositório `AdgroupMlbMapRepository` para esconder `adman_adgroup_mlbs`. `SugadorAnalysisService` refatorado pra resolver provider via DI (zero regressão validada por baseline). Comando `sugadores:analyze --provider={adman|ml} --company={id} --dry-run` retornando motivos sem upsert; guard ml_primary aborta `--provider=ml` sem `--dry-run` com exit 1 (proteção pré-Phase 42). Testes unitários de normalização com fixtures especulativas + Feature tests cobrindo command. Critério: `evaluateMetrics()` não sabe a origem.
- [x] **Phase 40: Shadow mode + tabelas de comparação** - Tabelas auxiliares `sugador_provider_runs` + `sugador_provider_items` (sem alterar `sugadores`). Comandos `sugadores:shadow-ml --company={id|all}` e `sugadores:compare-providers --company={id} --from --to`. Match por chave normalizada `tipo|campaign_id|adgroup_id` + alternativo por `mlb_id`. Classifica divergências (só-Adman / só-ML / métricas / motivo / quarentena). Scheduler shadow separado, não toca scheduler Adman. Alvo de paridade: >= 95% de motivos. Conectar 1+ empresa Adman+ML para validar paridade (Bymobile não basta sozinha).
 (completed 2026-06-25)
- [x] **Phase 41: Onboarding ML por empresa** - Tela admin: empresas ativas com `mlToken` válido/expirado/ausente/erro. Checklist por empresa (OAuth, seller_id, advertiser_id, scopes Ads, smoke, shadow). Política temporária: sem token → Adman; com token mas smoke falha → Adman + alerta; com shadow aprovado 7d → candidata a `ml_primary`. Tabela opcional `ml_advertisers` para cache de `advertiser_id`/`seller_id`/`site_id`. Rate limiter `ml-api:{seller_id}` por seller (não global) com backoff 429/5xx/401/403.
 (completed 2026-06-25)
- [x] **Phase 42: Sugadores via API ML (troca de motor + esconder UI Dev paralela)** - Reorientação 2026-06-26 baseada em briefing do usuário (`fix-melhorias-sugadores-api-mercado-livre.md`). Migração ML deixa de ser feature visual paralela e vira troca silenciosa de motor: API ML alimenta o mesmo contrato normalizado (adgroup_id/campaign_id/investment/revenue/sold_quantity/clicks/impressions/cpc/ctr/acos/roas) que a Adman alimentava, mesmo `SugadorAnalysisService`, mesma tabela `sugadores`, mesma `/sugadores`, mesma `/sugadores/config/{company}`. Janela 30d fechados (ontem-29d → ontem). Sidebar item "Onboarding ML" da Phase 41 escondido (rota permanece como ferramenta técnica admin). Adiciona `cpc_minimo_cliques` em `sugador_configs` (Opção B do briefing §8). Preserva quarentena SGI, idempotência por chave estável e status travados (em_acao/resolvido/ignorado/movido/auto_resolvido). Piloto: ByMobille - Teste (#298). Detalhes locked em `.planning/phases/42-sugadores-api-ml/42-CONTEXT.md`.
 (completed 2026-06-26)
- [ ] **Phase 43: Remoção da Adman (Sugadores)** - Só iniciar quando 100% das empresas ativas MLB tiverem `mlToken` válido + scheduler ML estável + 429 ML < 1% por 7d + contas grandes < 900s + suporte aceitar Adman não ser mais fallback. Remove env obrigatório `ADMAN_API_KEY` do path Sugadores (mantém pra Dashboard se ainda dependente). Renomeia `adman_adgroup_mlbs` → `sugador_adgroup_mlbs` via migration simples. Mantém compatibilidade de leitura no histórico.
- [ ] **Phase 44: Mover adgroup-sugador para campanha SGI via API ML** *(adicionada 2026-06-26 a partir do quick `260626-qgf`; escopo reduzido na discuss-phase 2026-06-26 — só "Mover SGI"; "Pausar in-place" virou Phase 44b)* — Expõe ação destrutiva no `Show.jsx`: mover adgroup pra campanha SGI (quarentena pausada). Combobox com SGIs da conta (reusa `QUARANTINE_NAME_REGEX`) + botão "Criar nova SGI" (pausada, nome sugerido `SGI [YYYY-MM]` editável). Toast "Desfazer" por 10s sem persistência no DB. Depende de Phase 43 estabilizar. Plan 44-01 obrigatório: smoke do `PATCH` na API ML Product Ads antes de qualquer planejamento backend. Context: `.planning/phases/44-mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml/44-CONTEXT.md`.
- [ ] **Phase 44b (deferred): Pausar adgroup-sugador in-place via API ML** — `PATCH status=paused` sem mudar campanha. Originalmente parte do escopo Phase 44; reduzido na discuss-phase 2026-06-26 ("Mover SGI" é a ação organizacional canônica). Roda depois da Phase 44 estabilizar.

### Milestone v12.0 — Fontes Unificadas Fase 2 + Desempenho longitudinal + Redesign Carteira + Gamificação ML

Generaliza o pattern de provider/precedência da v11.0 (Sugadores) para Dashboard + Carteira + Desempenho, garantindo que empresas ML somem nas métricas igual às Adman. Adiciona histórico longitudinal de scores, redesigna a carteira individual, separa rankings por função, e gamifica a migração Adman→ML. Pode rodar em paralelo com a finalização da v11.0 — Phases 45/46/48/50 são independentes; 47 e 49 dependem de phases anteriores.

Captura inicial: `.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md` (Itens 1-5). Extensão 2026-06-29: `.planning/todos/pending/270629-melhorias-carteira-desempenho-gamificacao-ml.md` (briefing detalhado dos 6 itens novos). Briefings auxiliares ainda untracked no root: `briefing-carteira-analistas-ui.md` (carteira UI — ingerido na Phase 48) e `metodologia-desempenho-carteira.md` (metodologia score justa — ingerido na Phase 46).

- [x] **Phase 45: Compatibilidade ML em métricas — foco em /desempenho + widget unify** — Caminho B confirmado (smoke 2026-06-29: Bymobille tem dados em adman_metrics). Plans 45-02/03 DEFERRED. Fix do filtro de users em /performance entregue. UAT 45-04 pendente.
- [ ] **Phase 46: Histórico longitudinal de scores na página de desempenho** — snapshot diário (`desempenho_score_snapshots`: user_id, ref_date, score, ranking_pos, breakdown_json) via job no scheduler. UI mostra delta vs dia anterior / semana anterior + gráfico de evolução individual. Resolve "classificação muda todo dia, não dá pra decidir quem é realmente o melhor". Ingerir `metodologia-desempenho-carteira.md` no discuss-phase. Independente.
- [ ] **Phase 47: Scoring por função com balanceamento por volume** — adiciona pontuação `sugador-resolvido-via-sistema` (analistas, **scoring negativo por não-resolvido**) + `PPA-concluído` (estrategistas). **Balanceamento crítico:** sugador é diário (recompensa pequena ou penalização) vs PPA mensal (recompensa proporcionalmente maior). Pesos por volume esperado. Depends on Phase 44 + Phase 46.
- [ ] **Phase 48: Redesign da carteira individual (analista + estrategista)** — modernizar UI da carteira seguindo `briefing-carteira-analistas-ui.md` (hero card com gradiente, KPIs simplificados, gráfico com tooltip, tabela orientada a ação). Adicionar: mini-gráfico de crescimento por empresa na listagem (verde subindo/vermelho descendo/cinza regular), histórico de NPS do dono da carteira, bloco diferenciado por função (sugadores para analista / PPAs para estrategista). **REMOVER meta agregada da carteira** — meta é por empresa (modelo correto). Independente, pode rodar em paralelo.
- [ ] **Phase 49: Rankings de /desempenho por função + ranking separado de publicação** — adicionar 3 tabs em `/performance` (Geral / Analistas / Estrategistas) + nova rota `/publicacao/desempenho` (ou similar) dentro do dropdown Publicação do menu lateral. Reusa `PortfolioScoreService` com filtro de função. Depends on Phase 47 (score diferenciado).
- [ ] **Phase 50: Gamificação OAuth ML para Líder Performance + Estrategistas** — nova aba/rota incentivando conexão ML de empresas pendentes. Estrategista vê apenas empresas atribuídas; Líder vê todas. Ranking/badge/score por conexão concluída + status "Em conversa com cliente". Acelera migração Adman→ML. Independente; sinergia com Phases 41/42.

## Phase Details

### Phase 1: Diagnóstico Adman
**Goal**: Admin pode inspecionar e controlar o sync Adman de cada empresa sem precisar de acesso ao servidor ou Artisan
**Mode:** mvp
**Depends on**: Nothing (first phase)
**Requirements**: DEV-01, DEV-02, DEV-03, DEV-04
**Success Criteria** (what must be TRUE):
  1. Admin vê uma lista de empresas com a data/hora exata do último sync Adman de cada uma
  2. Admin clica em uma empresa e vê o payload bruto retornado pela API Adman (ou a mensagem de erro HTTP) daquele sync
  3. Admin vê o diff do sync: quantos registros foram criados, atualizados e ignorados
  4. Admin clica em "Disparar sync" de uma empresa específica e o sync é enfileirado e executado em background
**Plans**: 3 plans

Plans:
- [x] 01-01-PLAN.md — Fundação de dados e contrato de testes (migration + AdmanSyncLog + Company rels + 5 testes RED)
- [x] 01-02-PLAN.md — Backend end-to-end: DevController + AdmanService logging + rotas (testes GREEN)
- [x] 01-03-PLAN.md — UI inline SyncAdmanSection + npm run build + checkpoint humano

**UI hint**: yes

### Phase 2: Monitoramento de Jobs
**Goal**: Admin pode acompanhar o estado da fila de jobs em tempo real e investigar falhas sem abrir o servidor
**Mode:** mvp
**Depends on**: Phase 1
**Requirements**: DEV-05
**Success Criteria** (what must be TRUE):
  1. Admin vê contadores de jobs pendentes, em execução e falhados
  2. Admin expande um job falhado e lê o payload completo e a stack trace da exceção
  3. Admin pode retentar ou descartar um job falhado via botão na interface
**Plans**: TBD
**UI hint**: yes

### Phase 3: Observabilidade
**Goal**: Admin pode ler logs de erro e inspecionar o ambiente de execução diretamente no painel
**Mode:** mvp
**Depends on**: Phase 2
**Requirements**: DEV-06, DEV-07
**Success Criteria** (what must be TRUE):
  1. Admin vê as entradas mais recentes de erro e warning do log do Laravel sem acesso ao servidor
  2. Admin vê informações do ambiente: versão PHP, driver de fila, driver de cache e uptime do processo
  3. Admin pode filtrar os logs por nível (error / warning) e ver a mensagem completa de cada entrada
**Plans**: TBD
**UI hint**: yes

### Phase 4: Configurações
**Goal**: Admin pode visualizar e editar flags de configuração do sistema diretamente no painel, sem editar arquivos .env no servidor
**Mode:** mvp
**Depends on**: Phase 3
**Requirements**: DEV-08
**Success Criteria** (what must be TRUE):
  1. Admin vê uma lista de configurações/flags do sistema com seus valores atuais
  2. Admin edita o valor de uma flag e salva — a mudança persiste entre sessões
  3. Alterações de configuração ficam registradas no activity log com o usuário que as fez
**Plans**: TBD
**UI hint**: yes

### Phase 5: Fundação Fechamento
**Goal**: Banco de dados e model Company preparados para suportar tipo de serviço e datas de contrato; sidebar renomeado para "Fechamento"
**Mode:** mvp
**Depends on**: Phase 1 (infra admin existente)
**Requirements**: FCH-01, FCH-02, FCH-03, CFG-01
**Success Criteria** (what must be TRUE):
  1. A rota `/administrativo/financeiro` continua acessível e o label no sidebar exibe "Fechamento" (não "Financeiro")
  2. A migration `add_service_fields_to_companies` existe e foi executada; as colunas `service_type` (enum: polo/assessoria/incubadora), `contract_start`, `contract_end` e `additional_service` existem na tabela `companies`
  3. Todas as empresas cadastradas aparecem na tela Fechamento, incluindo aquelas sem integração Adman — estas exibem badge "Sem integração"
  4. Admin consegue salvar o tipo de serviço de uma empresa (POLO / Assessoria / Incubadora) via formulário inline; o valor persiste após recarregar a página
  5. Admin consegue salvar as datas de início e encerramento de contrato de uma empresa; os valores persistem após recarregar a página
**Plans**: 3 plans

Plans:

**Wave 1**
- [x] 05-01-PLAN.md — Migration + Company model + stubs de teste Wave 0 (FCH-02, FCH-03)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 05-02-PLAN.md — AdminController fechamento()/updateFechamento() + rota PATCH (FCH-01, FCH-02, FCH-03)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 05-03-PLAN.md — Financeiro.jsx reescrito + AppLayout label + npm run build + checkpoint humano (FCH-01, CFG-01)

Cross-cutting constraints:
- `EnsureUserHasRole` middleware obrigatório em todas as rotas admin (Plans 02, 03)
- Carbon `?->toDateString()` para serialização de datas em props Inertia (Plans 02, 03)
- `npm run build` obrigatório após qualquer edição JSX (Plan 03)

**UI hint**: yes

### Phase 6: Backend Fechamento
**Goal**: Query de aggregation sobre adman_metrics entrega faturamento mensal por empresa com período coberto; calcularFaixa() determina valor a cobrar; props chegam ao frontend via Inertia
**Mode:** mvp
**Depends on**: Phase 5
**Requirements**: FCH-04, FCH-05
**Success Criteria** (what must be TRUE):
  1. A query `SUM(adman_metrics.revenue) GROUP BY company_id` retorna o faturamento acumulado no mês corrente sem nenhuma chamada HTTP à API Adman
  2. O período coberto ("01/05 a 18/05") é calculado dinamicamente com base nos registros presentes em `adman_metrics` e sempre aparece associado ao faturamento da empresa
  3. Empresas sem nenhum registro em `adman_metrics` no mês corrente recebem estado `sem_dados` e não entram no total consolidado
  4. `calcularFaixa()` aplica a tabela de progressão corretamente: dado um faturamento de entrada, retorna a faixa correspondente e o valor mensal a cobrar
  5. O controller Inertia entrega todos os campos necessários (faturamento, faixa, valor, período, estado) para cada empresa no array de props
**Plans**: 2 plans

Plans:

**Wave 1**
- [x] 06-01-PLAN.md — calcularFaixa() + const FAIXAS + CalcularFaixaTest GREEN + 8 stubs de feature RED (FCH-04, FCH-05)

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 06-02-PLAN.md — fechamento() expandido com aggregation query + todos os 16 testes GREEN (FCH-04, FCH-05)

Cross-cutting constraints:
- `whereNotNull('revenue')` obrigatório na aggregation para evitar distorção por revenue null (Plans 02)
- `Carbon::parse()` obrigatório antes de `->format('d/m')` em campos retornados por selectRaw (Plan 02)
- `(float)` cast obrigatório antes de passar faturamento para calcularFaixa() (Plan 02)
- Nenhuma mudança de rota — GET /administrativo/financeiro já existe (Plans 01, 02)
- `updateFechamento()` não deve ser alterado em nenhum plano desta fase (Plans 01, 02)

### Phase 7: UI Fechamento
**Goal**: Financeiro.jsx reescrita como tela Fechamento completa: lista de empresas com estado, barra de progresso por faixa, campo de serviço adicional e total consolidado visível
**Mode:** mvp
**Depends on**: Phase 6
**Requirements**: FCH-06, FCH-07, FCH-08
**Success Criteria** (what must be TRUE):
  1. Empresas com faturamento válido exibem barra de progresso mostrando posição na faixa atual e o valor que falta para atingir a próxima faixa
  2. Empresas na faixa máxima (faturamento > R$5M) exibem o texto "Faixa máxima" sem nenhuma barra de progresso
  3. O total consolidado exibido no topo da página soma apenas as empresas com estado `ok` (excluindo `sem_integracao` e `sem_dados`)
  4. O campo de serviço adicional aparece por empresa (valor visível ou placeholder "—"); não há lógica de cálculo associada nesta fase
  5. O período coberto ("01/05 a 18/05") está sempre visível na UI, associado ao bloco de faturamento de cada empresa
**Plans**: 1 plan

Plans:

**Wave 1**
- [x] 07-01-PLAN.md — Financeiro.jsx expandido (TotalConsolidado + FaixaProgresso + FechamentoRow + FechamentoAccordion) + npm run build + checkpoint humano (FCH-06, FCH-07, FCH-08, CFG-01)

Cross-cutting constraints:
- `autonomous: false` — requer checkpoint humano de verificação visual (D-15)
- `npm run build` obrigatório após edições JSX
- Nenhuma mudança de backend, rota ou teste PHP — apenas Financeiro.jsx

**UI hint**: yes

### Phase 8: Fundação de Notificações
**Goal**: A infraestrutura mínima de notificações (tabela `notifications` do Laravel, classe `Notification` base e a permission `notificacoes.criar` no catálogo) existe e está disponível para que qualquer outra fase possa criar e ler notificações
**Mode:** mvp
**Depends on**: Phase 7 (último estado estável do sistema antes do v3.0)
**Requirements**: PERM-01, PERM-02, PERM-03
**Success Criteria** (what must be TRUE):
  1. A tabela `notifications` (schema nativo do Laravel: `id` uuid, `type`, `notifiable_id`, `notifiable_type`, `data` json, `read_at`, `created_at`, `updated_at`) existe e foi migrada
  2. A permission_key `notificacoes.criar` aparece no catálogo retornado por `App\Support\Permissions::all()` com label e descrição em pt-BR, sob o grupo "Notificações"
  3. Qualquer usuário admin retorna `true` em `$user->hasPermission('notificacoes.criar')` sem precisar de atribuição manual (short-circuit já existente)
  4. Qualquer usuário cadastrado em `setor_lideres` (líder de qualquer setor) retorna `true` em `$user->hasPermission('notificacoes.criar')` automaticamente, sem precisar de atribuição manual ao setor
  5. A permission_key `notificacoes.criar` consta no array `Permissions::AUTO_LIDERANCA`
**Plans**: 4 plans

Plans:

**Wave 1**
- [x] 08-01-PLAN.md — Slice 1 Storage: migration `notifications` (schema canônico Laravel 12) + esqueleto da suíte Phase8FoundationTest com Test 1 GREEN

**Wave 2** *(blocked on Wave 1 completion)*
- [x] 08-02-PLAN.md — Slice 2 Domain types: enum `Categoria` (backed string, 3 cases) + classe abstrata `BaseNotification` (construtor 6 params, via=database, toArray 6 chaves) + Test 7 smoke E2E GREEN
- [x] 08-03-PLAN.md — Slice 3 Permission catalog: 3 edições cirúrgicas em `Permissions.php` (constante + grupo `Notificações` + AUTO_LIDERANCA) + Tests 2/3/4 GREEN (PERM-01, PERM-03 registro)

**Wave 3** *(blocked on Wave 2 completion)*
- [x] 08-04-PLAN.md — Slice 4 Authorization resolution E2E: Tests 5/6 GREEN via `User::hasPermission` (admin short-circuit, líder via AUTO_LIDERANCA merge) — suíte 7/7 verde (PERM-02, PERM-03 E2E)

Cross-cutting constraints:
- Não modificar `app/Models/User.php` (D-10: short-circuit + AUTO_LIDERANCA merge existentes cobrem PERM-02/03)
- Não criar subclasses concretas de `BaseNotification` (D-04: ficam para Phases 11/12)
- Migration timestamp estritamente posterior a `2026_05_20_200008` (Pitfall 6 do RESEARCH)
- Smoke test usa `Notification::send` REAL (Pitfall 1 — NÃO `Notification::fake()`)
- `php artisan migrate` e `php artisan test` rodam no host XAMPP (CLI PHP não disponível no agente)

### Phase 9: Backend de Leitura, Contador e Polling
**Goal**: O backend expõe o contador de não lidas como shared prop Inertia, oferece endpoint JSON de polling, lista as notificações do usuário autenticado e permite marcar uma ou todas como lidas — tudo testável via HTTP/Tinker antes da UI existir
**Mode:** mvp
**Depends on**: Phase 8
**Requirements**: POLL-01, POLL-02, POLL-03, HIST-01, HIST-03, HIST-04
**Success Criteria** (what must be TRUE):
  1. Em toda página Inertia, a prop compartilhada `notificacoes_nao_lidas` está presente e reflete a contagem real de notificações `read_at IS NULL` do usuário autenticado (zero quando não houver)
  2. Após qualquer navegação Inertia (visita a outra rota), a shared prop é recalculada automaticamente sem precisar de requisição manual adicional
  3. O endpoint `GET /api/notificacoes/contador` (autenticado) responde JSON `{ "count": N }` com a contagem atual de não lidas do usuário e pode ser chamado repetidamente para alimentar o polling do frontend
  4. A rota nomeada `notificacoes.index` (`GET /notificacoes`) responde Inertia com a lista paginada das notificações do usuário autenticado (ordenadas por `created_at DESC`)
  5. Existe endpoint para marcar uma notificação individual como lida (preenche `read_at = now()`) e ele rejeita marcar notificação que não pertença ao usuário autenticado (403)
  6. Existe endpoint para marcar todas as notificações não lidas do usuário como lidas em uma única requisição

### Phase 10: UI do Sino e Página de Histórico
**Goal**: Usuário autenticado interage com suas notificações pela primeira vez: vê o sino no header com badge de não lidas, abre o dropdown com as 10 mais recentes, clica para marcar como lida e acessa a página `/notificacoes` com abas e marcação em massa
**Mode:** mvp
**Depends on**: Phase 9
**Requirements**: SINO-01, SINO-02, SINO-03, SINO-04, SINO-05, SINO-06, HIST-02, HIST-05
**Success Criteria** (what must be TRUE):
  1. Em qualquer página autenticada, o ícone de sino aparece no canto superior direito do `AppLayout` com badge numérico quando há notificações não lidas, e o badge some quando a contagem é zero
  2. Ao clicar no sino, o dropdown abre exibindo as 10 notificações mais recentes (não lidas + recentes) com título, prévia, autor (quando manual), tempo relativo ("há 5min") e indicador visual de não lida
  3. Ao clicar em uma notificação não lida dentro do dropdown, ela é marcada como lida no backend e o badge do sino decrementa imediatamente, sem reload da página
  4. O dropdown contém o link "Ver todas" que navega via Inertia para `/notificacoes`
  5. A página `/notificacoes` exibe abas "Não lidas" (default) e "Todas" — a aba "Todas" inclui lidas com até 30 dias; alternar abas troca a lista exibida
  6. Cada item da lista mostra título, mensagem completa, origem (nome do autor para manual, ou rótulo "Sistema"), ícone/cor por categoria do evento e data/hora absoluta
  7. O botão "Marcar todas como lidas" zera o badge e move todas as não lidas da aba para o estado lido em uma única ação
**UI hint**: yes

### Phase 11: Disparos Automáticos de Metas
**Goal**: Sempre que uma meta de qualquer tipo (`SetorGoal`, `Goal` de empresa, `PortfolioGoal`) é atribuída ou atinge seu `target_value`, o público correto recebe a notificação automaticamente sem ação manual do admin
**Mode:** mvp
**Depends on**: Phase 9 (dispatch + leitura já funcionam) e Phase 10 (UI para o usuário ver o efeito)
**Requirements**: AUTO-01, AUTO-02, AUTO-03, AUTO-04, AUTO-05, AUTO-06
**Success Criteria** (what must be TRUE):
  1. Quando uma `SetorGoal` é criada, cada membro daquele setor (relação `user_setores`) recebe a notificação "Nova meta do setor: [descrição]" visível no sino e na página de histórico
  2. Quando uma `Goal` (meta de empresa) é criada, o consultor e o mentor vinculados àquela empresa (relação `company_users`) recebem a notificação "Nova meta para [empresa]: [descrição]"
  3. Quando uma `PortfolioGoal` é criada, o dono da carteira (`user_id`) recebe a notificação "Nova meta de carteira: [descrição]"
  4. Quando o resultado de uma `SetorGoal` atinge ou ultrapassa o `target_value`, todos os usuários com role admin e o(s) líder(es) do setor recebem a notificação "Meta atingida: [setor] alcançou [métrica]" — sem disparar duplicata no mesmo período de avaliação
  5. Quando o resultado de uma `Goal` atinge o `target_value`, o consultor + mentor da empresa + todos os admins recebem a notificação "Meta atingida: [empresa] alcançou [métrica]"
  6. Quando o resultado de uma `PortfolioGoal` atinge o `target_value`, o dono da carteira + todos os admins recebem a notificação "Meta atingida: sua carteira alcançou [métrica]"

### Phase 12: Criação Manual, Permissão na UI de Setores e Cleanup
**Goal**: Usuário com permissão `notificacoes.criar` envia notificações manuais com targeting (usuário / setor / líderes / todos); a permissão aparece e pode ser atribuída em `/sistema/setores`; o sistema mantém a tabela enxuta via cleanup diário e registra envios manuais no activity log
**Mode:** mvp
**Depends on**: Phase 9 (endpoints prontos), Phase 10 (UI base para destinatários verem), Phase 11 (dispatch maduro)
**Requirements**: ENVIO-01, ENVIO-02, ENVIO-03, ENVIO-04, ENVIO-05, PERM-04, POLL-04, POLL-05
**Success Criteria** (what must be TRUE):
  1. Usuário com a permissão `notificacoes.criar` vê o item "Enviar notificação" no sidebar do `AppLayout` e consegue abrir a página `/notificacoes/nova`
  2. Na tela de envio, o usuário consegue escolher o público entre: (a) usuário individual via busca, (b) um setor inteiro, (c) todos os líderes (qualquer usuário em `setor_lideres`), ou (d) todos os usuários ativos
  3. O envio só é aceito quando título (máx 100 caracteres) e mensagem (máx 1000 caracteres) estão preenchidos — formulário bloqueia submissão e exibe mensagem de erro caso contrário
  4. Após o envio bem-sucedido, o autor vê uma confirmação informando a quantidade efetiva de destinatários, e essas notificações ficam imediatamente visíveis para os destinatários no próximo ciclo de polling (sem ação adicional do destinatário)
  5. Usuário sem `notificacoes.criar` não vê o item no sidebar e, ao tentar acessar `/notificacoes/nova` diretamente, recebe HTTP 403
  6. Na página `/sistema/setores`, a permissão `notificacoes.criar` aparece na lista de chaves atribuíveis e pode ser concedida ao setor "Administrativo" ou a qualquer outro setor — usuários do setor passam a tê-la imediatamente após salvar
  7. O scheduled command `notifications:cleanup` está declarado em `routes/console.php` rodando diariamente e, ao executar, remove notificações com `read_at` mais antigo do que 30 dias (não toca em não lidas)
  8. Cada envio manual gera entrada no `activity_log` registrando o autor, o público-alvo escolhido e a contagem de destinatários efetivos; disparos automáticos das fases anteriores não são logados
**UI hint**: yes

### Phase 13: Reestruturação do Cadastro de Empresas
**Goal**: Setor Comercial é a única porta de entrada para cadastro de novas empresas; o cadastro roteia automaticamente por service_type criando os registros corretos em cada tabela; mlb_empresas existentes sem company_id são migradas retroativamente; setores de destino veem empresas pendentes nas suas páginas existentes
**Mode:** mvp
**Depends on**: Phase 12 (sistema de notificações para alertar líderes de setor)
**Requirements**: COM-01, COM-02, COM-03, COM-04, COM-05, COM-06, COM-07, COM-08, COM-09, COM-10, COM-11
**Success Criteria** (what must be TRUE):
  1. Usuário do Comercial (ou admin) acessa `/comercial/empresas/novo` e vê formulário com campos Nome, CNPJ, service_type e subtipo condicional; usuário sem permissão `comercial.cadastrar_empresa` recebe 403
  2. Ao cadastrar empresa tipo POLOS: `companies` (status='pendente') + `mlb_empresas` (tipo=POLO, projeto=POLOS) + `mlb_implementacao` (token + dadosPadrao() + implementacaoPadroes()) criados atomicamente em DB::transaction
  3. Ao cadastrar empresa tipo Assessoria: `companies` (status='pendente') + `mlb_empresas` (tipo=ASSESSORIA); tipo Publicidade/Gestão: apenas `companies` (status='pendente')
  4. Empresa recém-cadastrada aparece em `/administrativo/financeiro` e na seção "Pendentes" da página do setor de destino sem ação adicional
  5. Líderes do setor de destino recebem notificação automática via sistema de notificações (Phases 8-12) ao novo cadastro
  6. Migration de dados cria companies para todos os mlb_empresas sem company_id (idempotente), derivando service_type automaticamente, e preenche mlb_empresas.company_id; todos os registros existentes recebem status='ativo'
  7. Guard de duplicatas bloqueia cadastro com nome igual (case-insensitive) a companies.name ou mlb_empresas.nome existente
**Plans**: 4 plans

Plans:
- [x] 13-01-PLAN.md -- Migrations de schema (companies.status + mlb_empresas.company_id) + rename polo->polos + fix AdminController + Financeiro.jsx labels
- [x] 13-02-PLAN.md -- Permission comercial.cadastrar_empresa + setor Comercial + migration retroativa idempotente mlb_empresas
- [x] 13-03-PLAN.md -- ComercialController + EmpresaCadastradaNotification + rotas /comercial/* + suíte Phase13ComercialTest
- [x] 13-04-PLAN.md -- UI: sidebar item Comercial + NovaEmpresa.jsx + secoes Pendentes + checkpoint humano

**UI hint**: yes

### Phase 14: Consolidação do Modelo de Serviços (Frente B)
**Goal**: Modelo unificado em `contratos_servico` — os 5 campos legacy de `companies` (`service_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`) substituídos pelo modelo N:N introduzido na quick task 260526-jgj (Frente A); Fechamento, Comercial e demais consumidores migrados sem alterar resultados financeiros
**Mode:** mvp
**Depends on**: Phase 13 (cadastro Comercial usa service_type), quick task 260526-jgj (Frente A — catálogo + tabela N:N já criados)
**Requirements**: SVC-01, SVC-02, SVC-03, SVC-04, SVC-05, SVC-06, SVC-07
**Success Criteria** (what must be TRUE):
  1. Migration de dados popula `servicos` com os 6 tipos canônicos legacy (`publicacao`, `polos`, `assessoria`, `incubadora`, `publicidade`, `gestao`) — `valor_padrao=0`, `tipo_cobranca='mensal'`, `ativo=true` — e cria `contratos_servico` correspondentes para cada empresa, preservando `contract_start`/`contract_end` como `data_contratacao`/`data_vencimento`
  2. Para empresas com `additional_service` não-vazio, migration cria um `servicos` adicional (find-or-create por nome) + `contratos_servico` com `valor_contratado=additional_service_price`, mantendo as datas do contrato principal
  3. `AdminController::fechamento` calcula `cobranca_mensal` como `faixaData['valor']` + SUM(`contratos_servico.valor_contratado` WHERE `ativo=true` AND `tipo_cobranca='mensal'`); valor confere com o cálculo pré-refatoração para toda empresa que tinha `additional_service_price` preenchido
  4. `Admin/Financeiro.jsx` substitui o editor de "Serviço adicional" (texto livre + preço único) pela mesma UI de gestão de contratos do `Companies/Show.jsx` — modal de "Adicionar contrato", lista de ativos, ações editar/desativar
  5. Filtros e badges que hoje usam `whereJsonContains('service_type', ...)` apontam para `contratos_servico` via JOIN em `servicos.nome`; ServiceBadge no Fechamento mostra nomes dos contratos ativos
  6. `Comercial/NovaEmpresa.jsx` substitui o input `service_type` por seletor multi do catálogo de serviços; cria empresa + contratos atomicamente em uma `DB::transaction`, mantendo o roteamento por tipo (POLOS/Assessoria/Publicidade/Gestão) intacto
  7. Migration de schema descarta as 5 colunas legacy de `companies`; `down()` recria estrutura (sem rollback de dados pós-drop, documentado na migration)
  8. `EmpresaCadastradaNotification` e `EnviarRelatorioFechamentoJob` adaptados para consumir `contratos_servico`; conteúdo de notificações e email do relatório de fechamento permanece equivalente ao pré-refatoração
  9. Após a refatoração, `grep -rE 'service_type|contract_start|contract_end|additional_service|additional_service_price' app/ resources/js/` retorna zero matches em código aplicativo (migrations históricas excluídas)
**Plans**: 7 plans

Plans:

**Wave 0** *(prep work — nenhum impacto em produção)*
- [x] 14-01-PLAN.md — CobrancaCalculator helper + comando phase14:verificar-cobranca + 2 suítes de testes (SVC-02)

**Wave 1** *(blocked on Wave 0)*
- [x] 14-02-PLAN.md — Migrations 1 (seed_servicos_catalog) + 2 (migrate_legacy_service_data) idempotentes + testes (SVC-01)

**Wave 2** *(blocked on Wave 1 — paralelizável internamente entre 14-03 e 14-04)*
- [x] 14-03-PLAN.md — Refator de 6 consumers PHP (Company, AdminController×3 sites, MlbController, CompanyController, EmpresaCadastradaNotification, EnviarRelatorioFechamentoJob) + 2 suítes (golden cobranca + filtro JOIN) (SVC-02, SVC-04, SVC-07)
- [x] 14-04-PLAN.md — ComercialController.store reescrito + NovaEmpresa.jsx seletor multi + helper servicoDisparaImplementacao + 2 suítes (helper + roteamento Phase 13 preservado) (SVC-05)

**Wave 3** *(blocked on Wave 2)*
- [x] 14-05-PLAN.md — Admin/Financeiro.jsx substitui editor por seção de contratos (reusa rotas Frente A) + 3 Blade views usam service_type_label accessor + UAT humano deferido (SVC-03, SVC-04)

**Wave 4** *(blocked on Wave 3; 14-06 e 14-07 sequenciais entre si)*
- [x] 14-06-PLAN.md — Pre-flight `phase14:verificar-cobranca --abort-on-divergence` + Migration 3 aplicada localmente (drop 6 colunas, down recria TEXT) + cleanup backend (Company.php $fillable/$casts/logOnly, controllers/job/notification/comando) (SVC-06, SVC-04)
- [x] 14-07-PLAN.md — Cleanup dos 5 JSX consumers (Admin/Empresas, Comercial/Empresas, Mlb/Empresas, Companies/Index, Admin/Financeiro fragmentos) + smoke test humano fim-a-fim deferido (SVC-04, SVC-06)

Cross-cutting constraints:
- `phase14:verificar-cobranca --abort-on-divergence` é gate obrigatório antes de Plan 14-06 (drop irreversível)
- Comentários e mensagens em pt-BR (CLAUDE.md mandate)
- `npm run build` obrigatório após cada edição JSX (Pitfall 6 do RESEARCH)
- Eager loading `contratosServico.servico` em queries do AdminController evita N+1 (Pitfall 2)
- Down() da migration 3 recria `service_type` como TEXT (não string — Pitfall 7)

**UI hint**: yes

### Phase 15: Sugadores — UI por Empresa + Auto-resolução + Atalhos Operacionais
**Goal**: A aba `/sugadores` muda do paradigma "lista global paginada" para "cards por empresa com drilldown filtrado"; a análise diária auto-resolve sugadores `pendente` que não foram re-detectados na nova rodada (combate acúmulo); operadores ganham botão de copy em massa dos MLBs no drilldown do AdGroup e reanálise direto do card da empresa.
**Mode:** mvp
**Depends on**: Phase 14 (sistema estável após cleanup de serviços) + módulo Sugadores existente (já entregue em milestone anterior)
**Requirements**: SUG-01, SUG-02, SUG-03, SUG-04, SUG-05, SUG-06, SUG-07 *(novos — registrar em REQUIREMENTS.md durante o discuss/plan)*
**Success Criteria** (what must be TRUE):
  1. `/sugadores` exibe grid de **cards de empresa** como visão padrão — cada card mostra nome da empresa, contagem de sugadores `pendente` identificados HOJE em destaque, total de pendentes acumulados, e timestamp da última análise daquela empresa
  2. Cards estão ordenados por `count_hoje DESC, total_pendentes DESC, nome ASC`; clicar no card abre o drilldown da empresa (lista filtrada com `company_id` pré-aplicado) mantendo todos os filtros existentes (tipo/status/data/include_resolved); modo "lista global" continua acessível via toggle (compat com bookmarks)
  3. No drilldown de MLBs do AdGroup (`Sugadores/Show.jsx` → `mlbs`), **botão "Copiar MLBs"** copia a lista completa (ex: `MLB1234,MLB5678,...`) para o clipboard via `navigator.clipboard.writeText`; quando há MLBs com `matches_adgroup=true`, aparece botão extra "Copiar prováveis" com a sub-lista
  4. Cada card tem **botão "Reanalisar"** que enfileira `AnalyzeCompanySugadoresJob` via rota existente `sugadores.analyze-company` e mostra feedback visual ("Enfileirado às HH:mm"); botão respeita Policy `manage` (não aparece para quem não tem permissão)
  5. Após análise diária de uma empresa, sugadores com `status=pendente` e `reference_date < hoje` daquela empresa cujo `(tipo, campaign_id, adgroup_id)` NÃO consta no upsert atual são marcados como `auto_resolvido` (novo status), com `resolvido_em=now()`, `resolvido_por=null` e entrada de `SugadorAcao` com `acao=auto_resolvido`; STATUS_TRAVADOS (`em_acao`, `resolvido`, `ignorado`, `movido`) NÃO são tocados
  6. Sugadores com `status=auto_resolvido` aparecem visualmente diferentes na listagem (badge cinza/verde claro com tooltip "Resolvido automaticamente pelo sistema"), são excluídos do count "Pendentes" mas contam no histórico filtrável (mesmo tratamento de `resolvido`)
  7. Filtro persistente leve: ao abrir o drilldown de uma empresa, a UI grava em `localStorage` (`sugadores:last_company_id`); ao reabrir `/sugadores` na mesma sessão, oferece chip "Continuar com [Empresa X]" — clicar restaura o drilldown. Sem auto-redirect (analista pode querer ver os cards novamente)
**Plans**: TBD (a definir pelo planner — provavelmente 3-4 plans: 1 backend auto-resolução + 2 frontend cards/copy/restore + 1 testes)

Cross-cutting constraints:
- pt-BR em comentários, mensagens e activity log (CLAUDE.md mandate)
- `npm run build` obrigatório após cada edição JSX
- Reusar rotas existentes (`sugadores.analyze-company`, `sugadores.index?company_id=`) — não criar endpoints duplicados
- Novo status `auto_resolvido` exige migration de schema (enum) + atualização de `Sugador::STATUS_TRAVADOS` (adicionar para evitar regressão futura) + UI labels
- Auto-resolução roda dentro de `SugadorAnalysisService::analyzeCompany` APÓS o upsert (consome `$toUpsert` keys); cuidado com `dryRun=true` (não deve auto-resolver em dry run)
- Botão "Copiar MLBs" precisa de fallback para browsers sem `navigator.clipboard` (textarea + execCommand)
- LocalStorage não pode quebrar SSR/Inertia: usar `useEffect`

**UI hint**: yes

### Phase 16: Adequação à cadência D-1 da API Adman
**Goal**: Reduzir chamadas à API Adman de ~2k/hora para ~168/dia alinhando schedule, caches e UX ao fato de que a Adman é D-1 (atualiza 1× ao dia, às 10h BRT, com limite de 10 req/min por API key). Eliminar o 429 crônico em produção sem perder funcionalidade.
**Mode:** mvp
**Depends on**: Phase 15 (módulo Sugadores estável) + confirmação Adman: "API D-1 atualiza às 10h" + doc Adman "10 req/min por API key"
**Requirements**: ADM-01 a ADM-08 *(novos — registrar em REQUIREMENTS.md durante o discuss/plan)*
**Success Criteria** (what must be TRUE):
  1. Schedule `adman:sync` roda **1× por dia às 11h BRT** (cron `0 11 * * *`) — não mais `everyFiveMinutes()`
  2. Cascata reorganizada para depois das 11h: `adman:sync` (11:00) → `adman:sync-faturamento` (11:30) → `calculate-goal-results` (11:45) → `calculate-setor-goal-results` (11:55) → `sugadores:analyze` (12:00) → `sugadores:cleanup-quarentena` (12:30) → `RefreshGrossBillingCacheJob` (12:45, 1×/dia)
  3. `AdmanService` documenta a constante `ADMAN_RATE_LIMIT_RPM = 10`; throttle entre chamadas sequenciais ajustado para **7 segundos** (folga sobre o limite de 6s teórico) — implementado em `SyncAdmanData` command e `RefreshGrossBillingCacheJob`
  4. Cache TTLs runtime sobem para **24h**: `fetchGrossBilling` (era 60min), `fetchAccountMetricsCached` (era 60min), `fetchGrossBillingsBatch` (era 30min); chaves de cache incluem `YYYY-MM-DD` para invalidar automaticamente ao virar o dia
  5. Botão **"Sincronizar agora"** removido: rota `POST /adman/sync` deletada de `routes/web.php`; `AdmanController::syncNow()` removido; botões na UI substituídos por badge `"Atualizado em DD/MM HH:mm · D-1 da Adman"` (lê de `MAX(adman_sync_logs.created_at)` ou `MAX(adman_metrics.updated_at)` por empresa visível)
  6. Botão **"Reanalisar"** no card de Sugadores (Phase 15) só fica ativo se `NÃO houve sync no dia atual`; quando bloqueado mostra `"Análise diária já rodou hoje · próxima amanhã às 12h"`; lookup via `AdmanSyncLog::whereDate('created_at', today())->where('company_id', $id)->exists()`
  7. UI mostra disclaimer **"Dados D-1 da Adman"** com tooltip explicativo em Dashboard, Fechamento e cards de Sugadores
  8. Logs do servidor durante 24h pós-deploy mostram **zero 429** em uso normal; `php artisan tinker` rodando `(new AdmanService)->fetchPerformance(...)` 10× seguidas sem dormir mostra throttling automático e sem 429
**Plans**: TBD (a definir pelo planner — sugestão: 3 plans — backend schedule+cache+throttle / UI remoção botão sync + disclaimers / UI bloqueio reanalisar + testes)

Cross-cutting constraints:
- pt-BR em comentários, mensagens flash e activity log
- `npm run build` obrigatório após cada edição JSX
- **NÃO quebrar** dados existentes — caches antigos expiram naturalmente (TTL atual ≤ 60min); novos TTLs aplicam-se a partir do próximo refresh
- **NÃO remover** `AdmanController::syncNow` antes de remover/migrar TODOS os callers no JSX (grep prévio obrigatório)
- Constante `ADMAN_RATE_LIMIT_RPM` deve ser referenciada em comentários onde throttle for aplicado (rastreabilidade)
- Migration de schema NÃO é necessária — apenas mudança de código
- Decisão de remover botão "Sincronizar agora" é irreversível em UX; ao executar Plan, confirmar que badge de "última atualização" foi mostrado nas mesmas posições

**UI hint**: yes

## Progress

**Execution Order:**
v1.0 phases execute in order: 1 → 2 → 3 → 4 (phases 2–4 pausadas para v5.0)
v2.0 phases execute in order: 5 → 6 → 7
v3.0 phases execute in order: 8 → 9 → 10 → 11 → 12
v4.0 phases execute in order: 13 → 14

| Phase | Plans Complete | Status | Completed |
|-------|----------------|--------|-----------|
| 1. Diagnóstico Adman | 3/3 | Complete | 2026-05-18 |
| 2. Monitoramento de Jobs | 0/? | Paused (v5.0) | - |
| 3. Observabilidade | 0/? | Paused (v5.0) | - |
| 4. Configurações | 0/? | Paused (v5.0) | - |
| 5. Fundação Fechamento | 3/3 | Complete | 2026-05-19 |
| 6. Backend Fechamento | 2/2 | Complete | 2026-05-19 |
| 7. UI Fechamento | 1/1 | Complete | 2026-05-19 |
| 8. Fundação de Notificações | 4/4 | Complete   | 2026-05-21 |
| 9. Backend de Leitura, Contador e Polling | 1/1 | Complete   | 2026-05-21 |
| 10. UI do Sino e Página de Histórico | 1/1 | Complete   | 2026-05-21 |
| 11. Disparos Automáticos de Metas | 1/1 | Complete   | 2026-05-21 |
| 12. Criação Manual, Permissão na UI de Setores e Cleanup | 1/1 | Complete   | 2026-05-21 |
| 13. Reestruturação do Cadastro de Empresas | 4/4 | Complete   | 2026-05-25 |
| 14. Consolidação do Modelo de Serviços | 7/7 | Complete    | 2026-05-26 |
| 15. Sugadores — UI por Empresa + Auto-resolução + Atalhos | 4/4 (waves) | Complete | 2026-05-27 |
| 16. Adequação à cadência D-1 da Adman | 4/4 (waves) | Complete | 2026-05-27 |
| 17. Coleta de Dados ML (Fase 1 — sem IA) | 5/5 | Complete    | 2026-06-02 |
| 18. Dashboard precisa e com filtros empilháveis | 5/5 (waves) | Complete | 2026-06-03 |
| 18.5. Marketplace dinâmico no AdmanService | 3/3 (waves) | Complete | 2026-06-03 |
| 19. Sugadores — Foco no dia + Atalhos + Fix MCP | 1/1 | Complete   | 2026-06-03 |

### Phase 18: Dashboard precisa e com filtros empilháveis
**Goal**: Aplicar diretamente as duas regras-mestras do projeto (**acertividade** + **praticidade**) na Dashboard, eliminando 3 bugs reportados pelo usuário em 2026-06-02. Os dados mostrados ao admin precisam (a) refletir o período selecionado, (b) preservar todos os filtros simultaneamente, e (c) bater com a Adman para o mesmo range.
**Mode:** mvp (slice por bug)
**Depends on**: Phase 16 (cache D-1 + `RefreshGrossBillingCacheJob` em produção). Phase 18 NÃO requer mudança de schema.
**Requirements**: DASH-01 a DASH-06 *(novos — registrar durante o plan)*
**Success Criteria** (what must be TRUE):
  1. **Filtros empilháveis**: o usuário pode selecionar empresa + período + analista + estrategista em qualquer ordem; nenhum se perde ao alterar outro. Fix da inconsistência camelCase (`companyFilter`) ↔ snake_case (`company_id`) em [DashboardController:386](app/Http/Controllers/DashboardController.php) ↔ [Admin.jsx:95](resources/js/Pages/Dashboard/Admin.jsx). Frontend e backend usam exclusivamente snake_case (`company_id`, `consultor_id`, `estrategista_id`, `period`).
  2. **Período afeta TODOS os cards**: trocar o seletor de período (1d/7d/30d/180d) recalcula `total_revenue`, `total_ad_investment`, `avg_tacos`, `avg_margin`, `total_net_billing` e demais cards. Não há mais range `$dateFrom30d` hardcoded; tudo deriva de `$period` via helper `getPeriodRange(string $period): array{from: string, to: string}`.
  3. **Auditoria de divergência executada**: comando Artisan `dashboard:audit-billing-divergence [--period=N]` que, para cada empresa ativa com `cust_id`, compara `AdmanService::fetchPerformance` (fonte autoritativa) com `SUM(adman_metrics.revenue)` no mesmo range e imprime tabela de discrepâncias. Output: empresas sem `cust_id` mapeado, empresas com sync faltando dias, magnitude do gap por empresa, soma total da divergência absoluta e em %. Roda em produção via SSH sem efeito colateral (read-only).
  4. **Fix do Bug 3 baseado nos achados de SC-3**: depois da auditoria, aplicar a estratégia adequada ao tipo de gap encontrado. Pode incluir: (a) preencher `cust_id` faltante via `php artisan adman:identify-missing-cust`, (b) backfill de dias com sync falhado via `php artisan adman:backfill-missing-days --since=YYYY-MM-DD`, (c) revisar a política tudo-ou-nada de cache (linhas 117-133 do controller), (d) criar snapshot diário `dashboard_daily_totals` se gap for sistemático. **Estratégia escolhida em deviation explícito após W3**, não pré-decidida agora.
  5. **UI sinaliza incerteza**: quando o controller cai no fallback DB (cache Adman incompleto), os cards mostram um indicador sutil "≈ valor aproximado" ou tooltip "Cache parcial — recarregue em alguns minutos". Quando cache está completo, sem indicador (modo padrão). Critério baseado em regra de acertividade: o usuário NUNCA vê dado errado mascarado.
  6. **Testes** cobrem: (a) `applyFilter('period', '7')` preserva `company_id` na URL, (b) `applyFilter('company_id', '5')` preserva `period` na URL, (c) range derivado de `$period` é aplicado em TODAS as queries do controller (não só série temporal), (d) auditoria detecta empresa propositalmente sem `cust_id` e sem `adman_metrics` no range.

**Plans**: TBD (sugestão pelo planner — 4 a 5 plans):
  - W1: alinhamento naming (SC-1) — backend `compact` em snake_case + frontend `applyFilter` consistente
  - W2: helper `getPeriodRange` + propagação em todas as queries (SC-2)
  - W3: comando de auditoria + execução em produção via SSH (SC-3)
  - W4: estratégia de fix do Bug 3 (SC-4) — escopo definido após W3
  - W5: UI feedback (SC-5) + testes consolidados (SC-6)

Cross-cutting constraints:
- pt-BR em comentários, mensagens flash e activity log
- `npm run build` obrigatório após cada edição JSX
- **NÃO mexer** em `RefreshGrossBillingCacheJob` (Phase 16) salvo se a auditoria identificar gap originado lá
- **NÃO criar** multi-select de empresas (decisão do usuário: 1 por vez basta)
- **NÃO criar** range custom de datas nesta fase (1d/7d/30d/180d bastam)
- Comando de auditoria é read-only — NUNCA executa UPDATE/INSERT
- Fix de Bug 3 (W4) é definido por deviation explícito; planner registra como "TBD a partir de SC-3"
- Cache key do `RefreshGrossBillingCacheJob` segue range 30d (não mudar) — mas o controller pode ter caches secundários por período se SC-2 exigir

**UI hint**: yes

### Phase 17: Coleta de Dados ML (Fase 1 — sem IA)

**Goal:** Dada uma palavra-chave de produto, o sistema coleta dados via API oficial do Mercado Livre (app token client_credentials), minera estatisticamente as keywords mais usadas pelos concorrentes top, agrupa as principais dúvidas das perguntas dos clientes e gera uma recomendação heurística de título/descrição para o nosso anúncio — assíncrono, com feedback de progresso e persistência para histórico/reuso. Módulo Publicação/MLB; acesso por `publication_role`.
**Requirements**: D-01..D-07 (decisões travadas em 17-CONTEXT.md — Phase 17 não possui REQ-IDs mapeados)
**Depends on:** Módulo Publicação/MLB existente (`MlbController`) + integração ML OAuth/`MercadoLivreService` (NÃO depende da Phase 16)
**Plans:** 5/5 plans complete

**Success Criteria:**
- Dada uma keyword, retorna ranking de keywords dos concorrentes + top dúvidas/objeções das perguntas + recomendação heurística de título/descrição
- Coleta roda assíncrona (Job, queue `database`) com feedback de progresso (pendente/rodando/concluído/erro) e logging tag `[MLB Coleta]` com id+nome
- Falha de 1 item não interrompe o lote; erros logados e visíveis na UI
- Resultado persistido (migration + models) para histórico e reuso
- Página React em `resources/js/Pages/Mlb/` com design system `ecf-*` (dark, `DevCard`, `cn()`, shadcn/Radix), sem state global

**Decisões travadas (probe 2026-06-01 — ver memória `project_ml_api_search_restriction`):**
- Fonte via APP TOKEN (`client_credentials`), não user token. `/sites/MLB/search` = 403 (bloqueada). Endpoints OK: `/products/search?q=`, `/highlights/MLB/category/{cat}`, `/trends/MLB[/{cat}]`, `/sites/MLB/domain_discovery/search?q=`, `/items/{id}`, `/items/{id}/description`, `/questions/search?item=`. Reviews best-effort, cai p/ só perguntas.
- Volume: top 10 produtos; análise a fundo nos 5 melhores.
- IA FORA da Fase 1 (mineração estatística; recomendação heurística). Recomendação por IA = Fase 2.
- Restrições: só API oficial (sem scraping/ToS); não persistir dados pessoais além do necessário; `sold_quantity` pode vir oculto — tratar graciosamente.

Plans:

**Wave 1** *(paralelo — sem overlap de arquivos)*
- [x] 17-01-PLAN.md — MlKeywordMinerService (mineração estatística pt-BR + recomendação heurística, PHP puro) + testes unitários (D-04, D-05)
- [x] 17-02-PLAN.md — Migration + model MlbColeta + MlbColetaJob (ciclo de status/failed) + teste do failed() (D-06)

**Wave 2** *(bloqueado na Wave 1; 17-03 e 17-04 paralelos entre si)*
- [x] 17-03-PLAN.md — MlColetaService (app token cacheado + pipeline ML + 429/fallback questions) + testes (D-01, D-02, D-03, D-04, D-05)
- [x] 17-04-PLAN.md — Permission mlb.coleta + actions MlbController + rotas mlb.coleta.* + suíte Feature 403/store/status (D-06, D-07)

**Wave 3** *(bloqueado na Wave 2)*
- [x] 17-05-PLAN.md — Página Mlb/Coleta.jsx (formulário + polling + relatório) + nav AppLayout + npm run build + checkpoint humano (D-06, D-07)

### Phase 20: Integração ECF Drive (substitui sync SFTP por API HTTP)

**Goal:** Trocar a fonte de dados do módulo `/grants` do XLSX-via-SFTP para a API HTTP do ECF Drive, mantendo model/UI/permissões existentes. Adicionar coluna `segmento`, novo comando `grants:sync-ecf`, schedule diário, match `company_id` por `cust_id` com fallback CNPJ.
**Mode:** mvp
**Requirements:** GRT-API-01 (wrapper EcfDriveService), GRT-SYNC-02 (comando substitui SFTP), GRT-MATCH-03 (match cust_id→cnpj), GRT-SEGMENTO-04 (migration coluna segmento), GRT-UI-05 (UI mostra status sync ECF), GRT-SECRETS-06 (.env + config/services.php)
**Depends on:** Phase 19
**Plans:** 1/1 plans complete

Plans:
- [x] 20-01-PLAN.md — Backend EcfDriveService + comando grants:sync-ecf + migration segmento + schedule + UI coluna+label + 18 testes Feature + checkpoint humano deploy/smoke (todas as 6 REQ-IDs)

### Phase 21: Manual do Sistema (artigos explicativos para usuários não-técnicos)

**Goal:** Criar aba "Manual do Sistema" no rodapé da sidebar, acessível a TODOS os usuários autenticados, contendo artigos hardcoded em JSX que explicam aspectos do sistema em linguagem simples. Primeiro artigo: "Cronograma de horários" — tabela ordenada explicando o que acontece em cada horário do dia (Adman D-1 11h, Sugadores 12h BRT, ECF Drive grants diário, etc) sem nomes técnicos.
**Mode:** mvp
**Requirements:** MAN-NAV-01 (item no rodapé da sidebar), MAN-INDEX-02 (página `/manual` lista artigos), MAN-SHOW-03 (página `/manual/{slug}` renderiza artigo JSX), MAN-CRONO-04 (artigo Cronograma como tabela ordenada por horário), MAN-ACESSO-05 (acesso a todos autenticados, sem permission check), MAN-PT-BR-06 (linguagem simples, sem termos técnicos)
**Depends on:** Phase 20
**Plans:** 1/1 plans complete

Plans:
- [ ] TBD (run /gsd-plan-phase 21 to break down)

### Phase 22: Wrapper expandido EcfDriveService (todos os endpoints + cache estratégico)

**Goal:** Expandir o EcfDriveService da Phase 20 para cobrir todos os domínios estratégicos da API ECF Drive (/clientes, /sellers, /carteira, /signals, /relatorios) com 18 métodos públicos novos, helpers privados de HTTP e cache, validações defensivas e cache TTL por endpoint conforme tabela do API-GUIDE.md §12. Infra reutilizável pelas Phases 23-28 — sem UI, sem migration, sem command novo nesta fase.
**Mode:** mvp
**Requirements**: ECF-CORE-01, ECF-CLIENTES-02, ECF-SELLERS-03, ECF-CARTEIRA-04, ECF-SIGNALS-05, ECF-RELATORIOS-06, ECF-CACHE-07, ECF-VALIDA-08
**Depends on:** Phase 20 (EcfDriveService base)
**Plans:** 1 plan

Plans:
- [ ] 22-01-PLAN.md — Helpers get/cacheKey + refactor Phase 20 + 18 metodos novos (/clientes, /sellers, /carteira, /signals, /relatorios) + 5 suites de testes Http::fake + smoke prod via tinker

### Phase 23: Alertas Estratégicos (signals — caixa de entrada do comercial)

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 22
**Plans:** 1/1 plans complete

Plans:
- [ ] TBD (run /gsd-plan-phase 23 to break down)

### Phase 24: Painel Executivo Carteira ECF (resumo + histórico + breakdowns)

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 23
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd-plan-phase 24 to break down)

### Phase 25: Análise por Empresa (ficha 360° via Sellers)

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 24
**Plans:** 1/1 plans complete

Plans:
- [ ] TBD (run /gsd-plan-phase 25 to break down)

### Phase 26: Webhooks completos ECF Drive (receiver HMAC, 6 eventos)

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 25
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd-plan-phase 26 to break down)

### Phase 27: Concentração de Receita e Forecast 90d

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 26
**Plans:** 0 plans

Plans:
- [ ] TBD (run /gsd-plan-phase 27 to break down)

### Phase 28: Relatório Mensal Executivo automatizado (PDF + email)

**Goal:** [To be planned]
**Requirements**: TBD
**Depends on:** Phase 27
**Plans:** 1/1 plans complete

Plans:
- [ ] TBD (run /gsd-plan-phase 28 to break down)

### Phase 29: signal.detected vira notificação no sino

**Status:** Completed (2026-06-08)
**Goal:** Quando webhook `signal.detected` severity=critical chega no receiver da Phase 26 para empresa da NOSSA carteira, criar notificação na tabela `notifications` (Phase 8) para admin + consultor + mentor — sino do header acende via polling existente.
**Depends on:** Phase 8 (BaseNotification), Phase 10 (sino+polling), Phase 12 (dispatch pattern), Phase 23 (lookup company por cust_id), Phase 26 (HandleSignalDetectedJob recebendo push)
**Plans:** 1/1 plans complete

Plans:
- [x] **29-01** — `Categoria::ALERTA_ECF` + `AlertaEcfNotification extends BaseNotification` + reescrita `HandleSignalDetectedJob` (severity guard → carteira guard → idempotência via `data->meta->signal_id` → `Notification::send` admin+consultor+mentor active) + 21 testes Feature

**Resultado:**
- 1 Notification subclass nova (`AlertaEcfNotification`)
- 1 case enum nova (`Categoria::ALERTA_ECF`)
- 1 Job reescrito (`HandleSignalDetectedJob.handle()`)
- 21 testes Feature verdes (8 Job + 13 Notification)
- **Smoke prod**: 13 notifications criadas para signal `seller.gmv_queda_mom` cust_id 570267839 (RELOJOARIA WENUS), idempotência confirmada (2º webhook com mesmo `signal_id=9101` retornou 13 — sem dup)
- **Zero mudança no frontend** — sino do header (Phase 10) lê automaticamente via polling do shared prop
- Webhook payload da Phase 26 agora dispara notif real (antes só `Log::channel('ecf-webhooks')`)

### Phase 30: Sugadores Robustos — throttled queue Adman (W1 shipped, W2/W3/W4 superseded)

**Milestone:** v9.5 — Sugadores Robustos
**Status:** Partially Shipped (W1 em prod) + W2/W3/W4 **SUPERSEDED by Milestone v11.0** (2026-06-25)
**Goal original:** Eliminar 4 dores em prod do módulo Sugadores. **Realizado:** apenas dor 1 (rate limit 429 Adman) resolvida via W1.
**Decisão arquitetural 2026-06-25:** W2 (mirror service `SugadorAnalysisServiceMl`) foi superseded pelo **provider pattern** decidido na importação do `plano-migracao-sugadores-ml-direto.md` via /gsd-import. ML provider, shadow mode, cut-over por empresa e remoção da Adman passaram a ser o escopo da Milestone v11.0 (Phases 38-43). Plans 30-02/30-03/30-04 NÃO devem ser executados como estavam.
**Depends on:** Phase 4/19 (`SugadorAnalysisService` Adman + UI `/sugadores`), Phase 18 (cache híbrido Adman), Phase 20 (ml_token integração)
**Plans:** 1/4 — 30-01 shipped; 30-02/03/04 superseded

Plans:
- [x] 30-01 — W1 throttled queue Adman + checkpoint paginação (RateLimiter global 8/min + Fix D catch 429 upstream) **deployed 2026-06-08**
- [~] 30-02 — **SUPERSEDED por Phase 39 (v11.0)**: arquitetura mudou para provider pattern; mirror service `SugadorAnalysisServiceMl` foi descartada antes de implementar
- [~] 30-03 — **SUPERSEDED por Phase 39/41 (v11.0)**: UX adgroup sem MLB integrada ao novo provider pattern (decisão de quando criar Sugador manual fica no provider)
- [~] 30-04 — **SUPERSEDED por Phase 39 (v11.0)**: tabela local `adman_adgroup_mlbs` permanece (decisão §5 do plano), mas acessada via `AdgroupMlbMapRepository` que esconde o nome legado; sync agendado e botão "Forçar atualização" reaproveitados pelo provider ML quando aplicável

### Milestone v10.0 — Pesquisa de Satisfação 2.0

NPS automatizado mensal por email. Substitui o fluxo manual "por reunião" por uma cadência regular sem depender de analista/estrategista gerar o link a cada ciclo. Notas separadas (Estrategista, Analista, Empresa) em escala 1-5 + feedback livre. Admin acompanha por mês com cards + gráfico de variação. Geração manual de link preservada como back-compat.

### Phase 31: NPS Mensal Automatizado

**Milestone:** v10.0 — Pesquisa de Satisfação 2.0
**Status:** ✅ Completed (2026-06-10) — pronta para deploy agrupado em prod
**Goal:** Cliente recebe email NPS automaticamente no dia do mês em que a empresa foi cadastrada (`DAY(companies.created_at) == DAY(today)`), responde 3 notas 1-5 (Estrategista, Analista quando houver, Empresa "está atendendo sua expectativa?") + comentário livre. Admin acompanha por mês em `/nps` com cards de média, gráfico de variação 12 meses (3 séries) e lista de respostas do mês. Geração manual de link preservada como back-compat.
**Requirements:**
- REQ-31-01 — Coluna `companies.email_cliente` nullable + UI de edição em `Companies/Show.jsx` / `Comercial/Empresas.jsx`
- REQ-31-02 — Migration drop+recreate `nps_responses` com escala 1-5 (`score_estrategista`, `score_analista` nullable, `score_empresa`, `comment`, `respondent_name` nullable) + apagar surveys/responses existentes (são testes)
- REQ-31-03 — Migration add `nps_surveys.month_reference` (date YYYY-MM-01) + `nps_surveys.auto_generated` (boolean)
- REQ-31-04 — Comando `nps:disparar-mensal` rodando 1×/dia via `routes/console.php`. Por empresa active com `email_cliente`, se `DAY(created_at)=DAY(today)` AND não existe survey do mês AND não há contrato encerrado → cria survey `auto_generated=true` + envia `NpsMonthlyMail`
- REQ-31-05 — Mailable `NpsMonthlyMail` (Markdown) usando SMTP Gmail (já validado em Phase 28) com link nominal (nome do Estrategista + nome do Analista) e CTA pro link público `/nps/{token}`
- REQ-31-06 — Reescrita `Nps/Respond.jsx`: 3 sliders 1-5 (Estrategista, Analista condicional, Empresa) + textarea livre + nome opcional
- REQ-31-07 — Admin section dentro de `/nps` com filtro por mês, 3 cards de média (Estrategista/Analista/Empresa), gráfico Recharts de linha (3 séries × 12 meses), lista paginada de respostas do mês
- REQ-31-08 — Endpoint `nps.generate` (link manual) preservado, cria survey `auto_generated=false` (zero break do fluxo atual)
**Depends on:** Phase 28 (SMTP Gmail validado), Phase 8 (BaseNotification — opcional pra notificar admin sobre respostas recebidas), fix accessor `Company::cust_id` (quick 260609-mom — não é dependência mas evita confusão de match)
**Plans:** 5/5 plans executed ✅

Plans:
- [x] 31-01-PLAN.md — Schema NPS 1-5: migrations companies.email_cliente + drop+recreate nps_responses + month_reference/auto_generated em nps_surveys (completed 2026-06-10)
- [x] 31-02-PLAN.md — Backend automação: NpsMonthlyMail + comando nps:disparar-mensal (idempotente, edge dia 31) + schedule 09:00 BRT + NpsController submitResponse 1-5 + generate auto_generated=false (completed 2026-06-10)
- [x] 31-03-PLAN.md — UI cliente: Nps/Respond.jsx reescrita com 3 sliders 1-5 (analista condicional) + textarea livre + nome opcional (completed 2026-06-10)
- [x] 31-04-PLAN.md — UI empresa: campo email_cliente em Companies/Index.jsx (admin) e Comercial/Empresas.jsx + validação backend nullable|email (completed 2026-06-10)
- [x] 31-05-PLAN.md — Admin /nps expandido (filtro mês + 3 cards média + LineChart 12m + lista) + Dashboard widget NPS ajustado escala 1-5 + cleanup legacy refs em PerformanceController/Companies/Show.jsx (completed 2026-06-10)

### Phase 34: Cadastro Comercial Otimizado + Integração HubSpot

**Milestone:** v10.0 — Pesquisa de Satisfação 2.0 (item add-on)
**Status:** ✅ Codigo completo (Wave 2 completa 2026-06-12 — 4/4 plans entregues; falta verify+deploy agrupado)
**Goal:** Capturar mais info no close comercial (nicho/dor/faturamento/marketplaces/vende_ml), corrigir bug semântico do `email_colaborador`, expor tag "Empresa nova" como pendência na aba `/companies`, máscaras CNPJ/telefone nos forms, e webhook HubSpot para cadastro automático quando deal vira "Fechado Ganho".
**Requirements:**
- REQ-34-01 — Schema: 9 novas colunas em `companies` (nicho/dor/vende_ml/faturamento_mensal/marketplaces_extras/email_colaborador/empresa_nova/visto_em/visto_por)
- REQ-34-02 — Tabela `hubspot_eventos` para auditoria/replay do webhook
- REQ-34-03 — Pendência `empresa_nova` na aba Pendências + botão "Marcar como visto" (admin-only)
- REQ-34-04 — Fix D-07: `sem_email_colaborador` checa `email_colaborador` (era `email_cliente` por bug semântico)

Plans:
- [x] 34-01-PLAN.md — Fundação: schema (2 migrations defensivas) + Company fillable+casts + HubspotEvento model + CompanyController.index pendencia+fix+payload + endpoint marcar-visto role:admin + 8 testes (completed 2026-06-12)
- [x] 34-02-PLAN.md — Wizard Comercial estendido: NovaEmpresa.jsx + ComercialController::store validation dos 9 campos + máscaras CNPJ/telefone
- [x] 34-03-PLAN.md — Admin UI: Companies/Index.jsx (badge "Empresa nova", botão "Marcar visto", modal admin com novos campos + máscaras IMaskInput) + Companies/Show.jsx (seção "Informações do Close") + Comercial/Empresas.jsx edit form com 6 campos + máscaras + CompanyController validation update + payload show (completed 2026-06-12)
- [x] 34-04-PLAN.md — Webhook HubSpot: rota `/api/webhooks/hubspot` + HMAC v3 validation + grava `hubspot_eventos` + processamento inline (fetch deal+company HubSpot → cria Company) + idempotência por `object_id` (completed 2026-06-12)

### Phase 37: Onboarding Comercial Unificado via HubSpot Line Items

**Milestone:** v10.0 — Pesquisa de Satisfação 2.0 (item add-on — continuação 34→35→36)
**Status:** ✅ Concluída (2026-06-18) — 7/7 plans em 3 waves; pronta para deploy AGRUPADO
**Goal:** Quando deal vira "Fechado Ganho" no HubSpot, empresa entra no sistema com **serviço + valor + setor preenchidos automaticamente** via line items do HubSpot, restando apenas pendências operacionais (cust_id, responsável, grant ativo, ativação). Nova listagem em `/comercial/empresas/listagem` cobre TODOS os setores com filtros (serviço/setor/ordem/pendência), pendências comerciais isoladas e CRUD de grupos. `/companies` passa a focar APENAS em Performance (Gestão + Mentoria); aba Grupos e pendência `sem_servico` migram para Comercial. Menu "Serviços" vai pra dentro do grupo "Comercial" na sidebar.
**Mode:** mvp
**Depends on:** Phase 34 (webhook HubSpot base, schema close), Phase 35 (correções v2 do cadastro HubSpot), Phase 36 (Comercial UX + Atribuir Serviço migrado pro Comercial), Phase 14 (modelo unificado `contratos_servico` + catálogo `servicos`)

**Decisões travadas (2026-06-18):**
- **Phase 37 ÚNICA** (mega) com 5-7 plans em waves — mantém contexto único e deploy agrupado (sem fragmentar webhook + UI)
- **Filtro de Performance via catálogo de serviços**: `contratos_servico` JOIN `servicos` WHERE `servicos.setor = 'performance'` (Gestão + Mentoria). Reusa modelo unificado Phase 14 + nova coluna `servicos.setor` (enum). Sem nova coluna em `companies` — setor sempre derivado do catálogo
- **Empresas legacy** (created_at < deploy desta phase) entram na nova listagem Comercial SEM pendências comerciais. Pendência comercial só dispara para empresas com origem HubSpot (`hubspot_eventos.company_id_criada` apontando pra elas) que vieram sem line items ou com serviço não reconhecido
- **Mapeamento line_item.name → servico** via tabela `hubspot_line_item_mapping` editável no painel admin (sem deploy para novos mapeamentos)
- **Backwards compat MLB**: empresas de Publicação criadas via HubSpot continuam aparecendo em `/mlb/empresas` e widget "Empresas Pendentes" — zero regressão do fluxo Publicação atual

**Requirements:**
- REQ-37-01 — `HubspotApiClient::fetchDealLineItems(dealId)` consome `GET /crm/v3/objects/deals/{dealId}/associations/line_items` + `GET /crm/v3/objects/line_items/{id}?properties=name,price,quantity,hs_product_id,recurringbillingfrequency`
- REQ-37-02 — Tabela `hubspot_line_item_mapping` (`line_item_name` → `servico_id`) gerenciável via UI admin; cobre famílias MAP/Polo/Brigada/Gestão/Mentoria/Publicação
- REQ-37-03 — Coluna `servicos.setor` (enum: `performance`, `publicacao`, `outros`) com seed atualizando Gestão+Mentoria→`performance`, Publicação→`publicacao`, demais→`outros`; default `outros`; migration idempotente
- REQ-37-04 — Webhook HubSpot (Phase 34-04) estendido: após `criarEmpresa`, busca line items, mapeia via `hubspot_line_item_mapping`, cria `contratos_servico` com `valor_contratado=item.price`, `tipo_cobranca` derivado de `recurringbillingfrequency` (`monthly`→`mensal`, ausente→`unica`), `data_contratacao=now()` — atomicamente em `DB::transaction`
- REQ-37-05 — Nova rota `/comercial/empresas/listagem` (controller `ComercialController` ou dedicado), exibe TODAS as empresas com filtros: `?servico=`, `?setor=`, `?ordem=recentes|antigas`, `?pendencia=...`, busca por nome — filtros empilháveis (lição Phase 18)
- REQ-37-06 — Pendências comerciais isoladas no Comercial: `sem_servico`, `sem_valor`, `servico_nao_reconhecido`, `sem_setor`, `dados_close_incompletos`. Cards/badges/contadores no header da listagem Comercial
- REQ-37-07 — `/companies` filtra apenas empresas com pelo menos 1 contrato ATIVO em serviço cujo `Servico::setor='performance'`; remove tag/pendência `sem_servico` (movida pro Comercial); remove aba Grupos do Companies/Index
- REQ-37-08 — CRUD de grupos migra pra `/comercial/empresas/listagem` (aba ou seção dedicada); rota `groups.*` reaproveitada; permissions ajustadas
- REQ-37-09 — `AppLayout` sidebar reorganiza: grupo "Comercial" expansível contendo (Empresas, Grupos, Serviços); item "Serviços" some do nível raiz
- REQ-37-10 — Empresas legacy não geram pendência comercial: query de pendência checa `EXISTS(hubspot_eventos WHERE company_id_criada = companies.id)` para considerar empresa "de origem HubSpot"

**Success Criteria (what must be TRUE):**
  1. Quando deal vira `closedwon` no HubSpot, webhook `/api/webhooks/hubspot` busca line items associados, mapeia cada `item.name` para um servico do catálogo via `hubspot_line_item_mapping`, e cria `contratos_servico` na empresa criada com `valor_contratado=item.price`, `tipo_cobranca` derivado de `recurringbillingfrequency` (mensal/única), `data_contratacao=now()` — atomicamente em `DB::transaction`
  2. Quando `line_item.name` não tem mapeamento no `hubspot_line_item_mapping`, a empresa é criada SEM contrato + pendência comercial `servico_nao_reconhecido` marcada — webhook não falha (retorna 200, registra HubspotEvento.processado com warning)
  3. Página `/comercial/empresas/listagem` exibe TODAS as empresas (todos os setores) com filtros funcionais: `?servico=`, `?setor=`, `?ordem=recentes|antigas`, `?pendencia=sem_servico|sem_valor|servico_nao_reconhecido` — filtros empilháveis sem perder seleções (padrão Phase 18 snake_case)
  4. Listagem mostra grupos e permite CRUD de grupos diretamente; admin `Companies/Index` NÃO mostra mais a aba Grupos
  5. `/companies` passa a listar APENAS empresas que têm pelo menos 1 contrato ATIVO em serviço cujo `Servico::setor='performance'` — query reusa scope existente do `CompanyController` com novo `whereHas('contratosServico.servico', fn($q) => $q->where('ativo', true)->where('setor', 'performance'))`
  6. `/companies` não mostra mais a tag/pendência `sem_servico` (movida pro Comercial); aba Pendências em `/companies` lista apenas pendências OPERACIONAIS (sem_cust_id, sem_responsavel, sem_grant_ativo, etc)
  7. `AppLayout` exibe menu "Comercial" expansível contendo: Empresas (listagem nova), Grupos, Serviços (catálogo `/servicos` hoje no `/sistema`); menu "Serviços" some do nível raiz
  8. Empresas pré-existentes (created_at < deploy desta phase) que não têm contratos válidos NÃO geram pendência comercial — pendência só dispara para empresas com `hubspot_eventos.company_id_criada` apontando pra elas (origem HubSpot)
  9. Empresas de Publicação criadas via HubSpot continuam aparecendo em `/mlb/empresas` e no widget "Empresas Pendentes" do MLB (zero regressão do fluxo Publicação atual — `MlbController::empresas` continua usando `service_type_label` / `contratos_servico` com serviço Publicação)
  10. Tabela `hubspot_line_item_mapping` editável via UI admin (rota `/sistema/hubspot-line-items` ou similar) para que comercial cadastre novos mapeamentos sem precisar de deploy

**Plans:** 7 plans em 3 waves

Plans:

**Wave 1** *(paralelo — fundação schema, sem overlap de arquivos)*
- [x] 37-01-PLAN.md — Migration `servicos.setor` (enum performance/publicacao/outros) + seed Gestão+Mentoria→performance, Publicação→publicacao + Servico::SETORES const + helpers isPerformance/isPublicacao + scope porSetor + 6 testes (REQ-37-03) (completed 2026-06-18)
- [x] 37-02-PLAN.md — Migration `create_hubspot_line_item_mapping_table` + seed inicial (MAP/Polo/Brigada/Gestão/Mentoria/Publicação) + model HubspotLineItemMapping (scope ativo + relação servico + helper paraNome case-insensitive) + 9 testes (REQ-37-02 schema/model) (completed 2026-06-18)

**Wave 2** *(bloqueado na Wave 1)*
- [x] 37-03-PLAN.md — HubspotApiClient::fetchDealLineItems (2-call pattern: associations + batch loop de detalhes) + tratamento resiliente 4xx/5xx (associations → []; line_item individual → log warning + skip) + cast defensivo price/quantity + 9 testes Http::fake / 28 assertions (REQ-37-01) (completed 2026-06-18)
- [x] 37-04-PLAN.md — HubspotWebhookController estendido: processarLineItems via HubspotLineItemMapping::paraNome + cria ContratoServico atomicamente em DB::transaction + tipo_cobranca derivado de recurringbillingfrequency (anotado em observacoes, coluna nao existe em contratos_servico) + fallback fluxo legado quando deal sem line items + idempotência preservada + 10 testes Feature ponta-a-ponta / 57 assertions (REQ-37-04) (completed 2026-06-18)

**Wave 3** *(bloqueado na Wave 2 — Plans 37-05/37-06/37-07 paralelizáveis entre si, sem overlap)*
- [x] 37-05-PLAN.md — Nova rota /comercial/empresas/listagem (ComercialController::listagem) com filtros snake_case empilháveis (servico, setor, ordem, pendencia, q) + 5 cards de pendência comercial calculados APENAS para empresas com EXISTS(hubspot_eventos.company_id_criada) + página Comercial/EmpresasListagem.jsx com aba Grupos integrada (GruposManager reaproveitado) + sub-item sidebar "Empresas (todos os setores)" + 17 testes Feature / 62 assertions (REQ-37-05, REQ-37-06, REQ-37-08, REQ-37-10) (completed 2026-06-18)
- [x] 37-06-PLAN.md — CompanyController::index refoca em Performance via whereHas('contratosServico.servico', $q -> setor performance + ativo) + remove pendência sem_servico do payload + remove aba Grupos do Companies/Index.jsx + 12 testes Phase37CompaniesPerformanceFilterTest verdes (17 assertions) + 4 testes Phase34 ajustados via Rule 1 (helper attachPerformanceContract — fixture obsoleta) + zero regressão Phase 34/35/37 (105/105 — 537 assertions) (REQ-37-07) (completed 2026-06-18)
- [x] 37-07-PLAN.md — `autonomous: false` — AppLayout sidebar reorganizada (Comercial expansível: Empresas/Cadastrar empresa/Grupos/Serviços/HubSpot Line Items; Serviços removido do raiz) + CRUD admin de HubspotLineItemMapping em /sistema/hubspot-line-items (controller no namespace Sistema\ + 4 rotas role:admin + página React com modal Dialog) + 13 testes Phase37HubspotLineItemMappingAdminTest verdes (45 assertions) + zero regressao Phase 34/35/36/37 (124/124 — 607 assertions) + checkpoint humano pré-aprovado (REQ-37-09, REQ-37-02 UI) (completed 2026-06-18)

**Cross-cutting constraints:**
- pt-BR em comentários, mensagens flash, activity log (CLAUDE.md mandate)
- `npm run build` obrigatório após cada edição JSX
- **Deploy agrupado** — não fragmentar: schema + webhook + UI saem juntos (lição Phase 34/35)
- **NÃO quebrar** webhook HubSpot atual (Phase 34-04) durante migração — testes de regressão obrigatórios
- **Reusar** `Servico` model + `contratos_servico` (Phase 14) — NÃO criar tabela paralela de contratos
- Migration `servicos.setor` é idempotente (default=`outros`); seed re-rodável atualiza Gestão/Mentoria→`performance`, Publicação→`publicacao`
- Empresas legacy continuam funcionando em `/companies` se já têm contrato de Gestão/Mentoria no catálogo (cobertura natural via JOIN)
- `hubspot_line_item_mapping`: case-insensitive match no `line_item_name` (LOWER comparison) para tolerar variações de capitalização do HubSpot

**UI hint**: yes
**Autonomous**: false (checkpoint humano após plan do webhook + após reorg de menu)

### Phase 38: Smoke ML (piloto Bymobile)

**Milestone:** v11.0 — Migração Sugadores Adman → ML (Fontes Unificadas Fase 1)
**Status:** Partially Complete (Plan 38-01 ✅; Plan 38-02 código pronto + tests verdes, smoke real bloqueado por MariaDB local corrompido — recovery via quick task `dev:reparar-mariadb-local`)
**Mode:** mvp
**Goal:** Descobrir o shape real da API oficial do Mercado Livre Mercado Ads / Product Ads para empresa `ByMobille - Teste` (única empresa com OAuth ML direto hoje) **sem tocar no fluxo de produção do módulo Sugadores**. Entrega: comando Artisan `sugadores:ml-smoke --company={id} --days=30` que resolve `mlToken` (com refresh se necessário), descobre `advertiser_id`, lista campanhas e ads, tenta métricas no período, grava fixture JSON anonimizável em `storage/app/sugadores/ml-smoke/{company_id}-{date}.json` e imprime relatório curto. Gate obrigatório antes de Phase 39 (provider pattern) — se smoke não estiver verde, plano técnico bloqueia avanço ("Não avance para substituir a Adman antes desse smoke estar verde").
**Depends on:** Phase 20 (MlToken + MercadoLivreService + refresh token), Phase 30 W1 (RateLimiter global `adman-api` já em prod — referência de pattern). Não depende de Phase 39+ (esta é o gate de entrada da v11.0).
**Requirements:**
- REQ-38-01 — Comando `sugadores:ml-smoke --company={id} --days=30` resolve `mlToken` ativo da empresa, faz refresh se expirado, falha cedo com mensagem clara se ausente/inválido
- REQ-38-02 — Comando descobre `advertiser_id` via `GET /advertising/advertisers` (ou endpoint atualizado da doc oficial), persiste em cache de sessão (não tabela ainda — Phase 41)
- REQ-38-03 — Comando lista campanhas Product Ads do advertiser e imprime status HTTP, contagem e shape do primeiro payload (campos disponíveis)
- REQ-38-04 — Comando lista ads/anúncios e tenta obter métricas (custo, receita, unidades, cliques, impressões, CPC, CTR, ACOS, ROAS, item/MLB, thumbnail, status) no período de `--days`
- REQ-38-05 — Comando grava JSON de amostra (anonimizável — sem PII de cliente final) em `storage/app/sugadores/ml-smoke/{company_id}-{YYYY-MM-DD}.json`
- REQ-38-06 — Comando imprime relatório curto: endpoints que funcionaram, campos disponíveis vs ausentes, equivalência com contrato normalizado §2.3 do plano, blockers de permissão/scope/token
- REQ-38-07 — Comando NÃO grava em `sugadores`, `sugador_configs`, `sugador_acoes` nem em qualquer tabela de produção; é puramente diagnóstico

**Success Criteria** (what must be TRUE):
  1. `php artisan sugadores:ml-smoke --company={id_bymobille} --days=30` roda no host de dev sem precisar de mock — chama API ML real com token real
  2. Comando lista pelo menos uma campanha Product Ads OU retorna erro claro de permissão/scope/token (não silencia falhas)
  3. Comando lista pelo menos um anúncio (Product Ad/item) OU explica por quê não conseguiu (advertiser sem ads, scope ausente, etc)
  4. Comando tenta endpoint de métricas e imprime quais campos do contrato normalizado §2.3 do plano estão presentes/ausentes no payload real (custo, receita, unidades, cliques, impressões, CPC, CTR, ACOS, ROAS, item/MLB, thumbnail, status)
  5. Arquivo `storage/app/sugadores/ml-smoke/{company_id}-{date}.json` existe após execução com amostras dos payloads (capacidade de revisar offline)
  6. Relatório final do comando lista explicitamente: (a) endpoints que retornaram 2xx; (b) endpoints que retornaram 4xx/5xx + razão; (c) campos do contrato normalizado presentes; (d) campos ausentes (precisam de fallback ou nullable); (e) blockers para Phase 39
  7. `grep -r "sugadores"` no fluxo de Sugadores prod confirma que nenhuma tabela/job/controller de prod foi alterado — comando é stand-alone
  8. Próxima Phase 39 só pode começar depois do operador (usuário) revisar o relatório e aprovar — `autonomous: false` no plan principal

**Plans:** 1/2 complete + 1/2 partially_complete (smoke real deferido)
- [x] 38-01-PLAN.md — MercadoLivreAdsService (wrapper ML Mercado Ads) + diretorio storage versionado + 4 tests Http::fake (Wave 1, autonomous)
- [~] 38-02-PLAN.md — Comando Artisan `sugadores:ml-smoke` + 4 tests Feature Http::fake (✅ 4/4 verde, commits `984f3bc` RED + `45e986c` GREEN) + smoke real Bymobille (❌ DEFERIDO — bloqueado por corrupção do MariaDB local, recovery via quick task `dev:reparar-mariadb-local`). Wave 2, autonomous=false.

**Cross-cutting constraints:**
- pt-BR em comentários e mensagens (CLAUDE.md mandate)
- Não modificar `SugadorAnalysisService`, `SugadorController`, `AnalyzeCompanySugadoresJob`, `FetchAdmanMlbsByCampaignJob` (Adman path) — gate é "smoke não toca prod"
- Reusar `MercadoLivreService` (Phase 20) para autenticação/refresh; se faltar método específico (advertisers, product ads), adicionar via novo `MercadoLivreAdsService` em namespace separado (`App\Services\MercadoLivre\AdsService` ou `App\Services\Sugadores\MercadoLivreAdsService`)
- Endpoints ML são **candidatos a validar** contra a doc oficial (`https://developers.mercadolivre.com.br/`) — comando deve imprimir URL chamada + status para facilitar correção se nome do endpoint mudou
- Rate limit ML é separado da Adman (plano §3): não usar RateLimiter `adman-api`; ML não exige throttle agressivo (~8k req/dia por app)
- Fixture JSON pode ser usada depois em testes da Phase 39 — formato deve ser estável o suficiente

**UI hint**: no (comando Artisan, sem UI)
**Autonomous**: false (após smoke, gate humano para revisar relatório e autorizar Phase 39)

### Phase 39: Provider pattern + MercadoLivreSugadoresProvider (sem gravar)

**Milestone:** v11.0
**Status:** Completed (2026-06-25)
**Mode:** mvp
**Goal:** Implementar `SugadoresAdsProvider` contract + `AdmanSugadoresProvider` (encapsula `AdmanService` atual) + `MercadoLivreSugadoresProvider` + `MercadoLivreAdsService` (com retries, paginação, refresh token). Refatorar `SugadorAnalysisService` para resolver provider via DI. Criar `AdgroupMlbMapRepository` para esconder `adman_adgroup_mlbs` (decisão §5 do plano). Comando `sugadores:analyze --provider=ml --company={id} --dry-run` retorna motivos sem upsert. NÃO grava em `sugadores`.
**Depends on:** Phase 38 (smoke verde com fixtures reais), Phase 20 (MlToken + MercadoLivreService)
**Requirements:**
  - REQ-39-01 — Contract `App\Contracts\SugadoresAdsProvider` com 6 métodos (supports/name/fetchCampaigns/fetchCampaignsMetrics/fetchAdgroupsMetrics/fetchAdgroupMlbs) + PHPDoc descrevendo cada chave do contrato normalizado §2.3
  - REQ-39-02 — `App\Services\Sugadores\AdmanSugadoresProvider` implementando o contract via composição de `AdmanService` (sem modificá-lo)
  - REQ-39-03 — `App\Services\Sugadores\MercadoLivreSugadoresProvider` implementando o contract via composição de `MercadoLivreAdsService` (Phase 38); normalização ML→§2.3; comentários `// CANDIDATO — revalidar após smoke real` em campos especulativos
  - REQ-39-04 — `App\Repositories\AdgroupMlbMapRepository` (3 métodos: getMlbsForAdgroup, setMlbsForAdgroup, bulkSetFromProvider) escondendo nome legado `adman_adgroup_mlbs`; legacy `AdmanAdgroupMlbsRepository` preservado para compat com SugadorController + SyncCompanyAdgroupMlbsJob
  - REQ-39-05 — `App\Services\Sugadores\SugadoresAdsProviderFactory` resolvendo provider por (forceName ou capability detection); regra default "prefere Adman até Phase 42"
  - REQ-39-06 — `SugadorAnalysisService` refatorado para receber factory via DI; analyzeCompany aceita `?string $forceProvider`; lógica de detecção (evaluateMetrics, buildRow, STATUS_TRAVADOS, auto-resolve, quarentena) IDÊNTICA — zero regressão
  - REQ-39-07 — Comando `php artisan sugadores:analyze --company={id} --provider={adman|ml} --dry-run` retorna motivos sem upsert; `--provider=ml` sem `--dry-run` aborta com exit 1 (proteção pré-Phase 42)
  - REQ-39-08 — Suite de testes (Unit + Feature) cobrindo: AdmanProvider (Mockery), MlProvider (Http::fake speculative), Repository (SQLite), Factory (resolução), SugadorAnalysisService refactor (regressão), command (dry-run + guard); zero regressão na suite Sugador existente
**Success Criteria**: provider ML entrega exatamente o contrato §2.3 do plano; `evaluateMetrics()` não sabe a origem; comando dry-run retorna mesma estrutura de motivos do path Adman para Bymobile.
**Plans:** 5/5 plans executed
  - 39-01-PLAN.md — Wave 1: Contract + AdmanSugadoresProvider + Factory minimal + Unit tests ✓ (122451c RED + b69030d GREEN)
  - 39-02-PLAN.md — Wave 2: MercadoLivreSugadoresProvider + factory branch ml + Http::fake speculative tests ✓ (43208d1 RED + 6da011c GREEN)
  - 39-03-PLAN.md — Wave 2: AdgroupMlbMapRepository neutro + legacy compat preserva call-sites + Unit tests ✓ (a3c0bf9 RED + 20e6cd3 GREEN)
  - 39-04-PLAN.md — Wave 3: Refactor SugadorAnalysisService para usar factory; lógica detecção INALTERADA; baseline + refactor tests ✓ (09cd274 BASELINE + 14eb676 RED + f23ba31 GREEN)
  - 39-05-PLAN.md — Wave 4: Estende command sugadores:analyze com --provider + guard ml_primary + Feature tests ✓ (4318d9a RED + e605397 GREEN)
**UI hint**: no

### Phase 40: Shadow mode + tabelas de comparação

**Milestone:** v11.0
**Status:** Pending
**Mode:** mvp
**Goal:** Tabelas auxiliares `sugador_provider_runs` + `sugador_provider_items` (sem alterar `sugadores`). Comandos `sugadores:shadow-ml --company={id|all}` e `sugadores:compare-providers --company={id} --from --to`. Match por chave normalizada `tipo|campaign_id|adgroup_id` + alternativo por `mlb_id`. Classifica divergências (só-Adman / só-ML / métricas / motivo / quarentena). Scheduler shadow separado. Alvo paridade ≥95% de motivos. Exige 1+ empresa Adman+ML para validar paridade (Bymobile sozinha não basta).
**Depends on:** Phase 39 (provider pattern operando dry-run)
**Requirements:**
- REQ-40-01 — Migration cria `sugador_provider_runs` (10 colunas) + `sugador_provider_items` (8 colunas) + índices compostos (`idx_company_ref_provider`, `idx_run_tipo`); FKs com cascadeOnDelete; idempotente; Models Eloquent `SugadorProviderRun` + `SugadorProviderItem` com casts e relações
- REQ-40-02 — `App\Services\Sugadores\ShadowRunService` orquestra 2 runs por (empresa+data) — uma com `forceProvider='adman'` e outra com `forceProvider='ml'`, ambas via `SugadorAnalysisService::analyzeCompany($company, $ref, dryRun=true, $provider)`; persiste runs+items; **GATE CRÍTICO:** ZERO gravação em `sugadores`; falha de um provider não interrompe o outro
- REQ-40-03 — `App\Services\Sugadores\ProviderComparisonService` classifica items em 6 buckets (matched, metrics_diff, motivo_diff, apenas_adman, apenas_ml, quarentena_diff) + calcula `paridade_motivos_pct`; tolerâncias §7 do plano-migracao (dinheiro ≤1% OU ≤R$0,10; percentuais ≤0,5pp; inteiros igualdade); 2 métodos públicos `compareRuns` + `compareWindow`
- REQ-40-04 — Comando `php artisan sugadores:shadow-ml --company={id|all} [--days=N]` dispara `ShadowRunService` inline; respeita `config('sugadores.ml_shadow_companies')` quando `--company=all`; clamp `--days` em [1,90]
- REQ-40-05 — Comando `php artisan sugadores:compare-providers --company={id} --from=YYYY-MM-DD --to=YYYY-MM-DD [--format=table|json]` imprime relatório; exit 0 se paridade ≥95%, exit 1 caso contrário (automatável em CI)
- REQ-40-06 — Scheduler em `routes/console.php` adiciona entrada `sugadores:shadow-ml --company=all --days=1` rodando `->dailyAt('13:00')->timezone('America/Sao_Paulo')->onOneServer()->withoutOverlapping()`; entradas existentes (Adman 12h, cleanup 12:30) inalteradas
- REQ-40-07 — Env `SUGADORES_ML_SHADOW_COMPANIES` documentada em `.env.example`; arquivo NOVO `config/sugadores.php` expõe `ml_shadow_companies` lendo CSV; comando aborta com mensagem clara em pt-BR ("Nenhuma empresa elegível — defina SUGADORES_ML_SHADOW_COMPANIES") quando env vazia + `--company=all`
- REQ-40-08 — Suite de testes cobrindo: schema migration (8 tests), ShadowRunService Mockery com gate zero gravação (9 tests), ProviderComparisonService com 15 cenários de divergência, ambos comandos CLI (17 tests — exit codes + format json/table + abort cases); zero regressão na suite Sugador acumulada (>= 65 verdes baseline Phase 39)

**Success Criteria** (what must be TRUE):
  1. `php artisan migrate` cria as 2 tabelas com índices compostos e FKs cascade
  2. `ShadowRunService` grava em `sugador_provider_runs`+`sugador_provider_items` mas NUNCA em `sugadores` (validado por `assertDatabaseCount('sugadores', $initial)` antes==depois)
  3. `ProviderComparisonService::compareWindow` retorna paridade % calculável; tolerâncias §7 aplicadas como constantes
  4. `php artisan sugadores:shadow-ml --company={id}` e `php artisan sugadores:compare-providers ...` rodam sem fatal e retornam exit code apropriado
  5. Scheduler agendado para 13h BRT visível em `php artisan schedule:list` com nome `sugadores-shadow-ml-daily`
  6. Suite Phase 40 acumulada >= 49 tests verdes; suite Sugador continua sem regressão (>= 65 verdes legados)
  7. Smoke real (rodar contra MariaDB com 1+ empresa que tenha tanto Adman quanto ML) DEFERIDO até MariaDB local voltar (quick task `dev:reparar-mariadb-local`)
  8. Phase 41 (onboarding ML) destravada — Phase 40 entrega a infra de medição que Phase 41 vai expor visualmente

**Plans:** 4/4 plans complete
- [x] 40-01-PLAN.md — Wave 1: Migration 2 tabelas + 2 Models Eloquent + 1 test schema (REQ-40-01)
- [x] 40-02-PLAN.md — Wave 2: ShadowRunService + tests Feature com Mockery (REQ-40-02, gate zero gravação)
- [x] 40-03-PLAN.md — Wave 2: ProviderComparisonService + tests Unit com 15 cenários (REQ-40-03)
- [x] 40-04-PLAN.md — Wave 3: 2 comandos Artisan + config/sugadores.php + .env.example + scheduler 13h BRT + tests Feature (REQ-40-04, REQ-40-05, REQ-40-06, REQ-40-07, REQ-40-08 parte)

**UI hint**: no (relatório CLI; UI admin opcional fica para Phase 41)

### Phase 41: Onboarding ML por empresa

**Milestone:** v11.0
**Status:** Pending
**Mode:** mvp
**Goal:** Tela admin: empresas ativas com `mlToken` válido / expirado / ausente / erro. Checklist por empresa (OAuth, seller_id, advertiser_id, scopes Ads, smoke, shadow). Política temporária: sem token → Adman; com token mas smoke falha → Adman + alerta; shadow aprovado 7d → candidata a `ml_primary`. Tabela opcional `ml_advertisers` para cache de `advertiser_id`/`seller_id`/`site_id`. Rate limiter `ml-api:{seller_id}` por seller (não global). Backoff 429/5xx/401/403 conforme plano §3.
**Depends on:** Phase 40 (shadow funcional, paridade medida)
**Requirements:** REQ-41-01, REQ-41-02, REQ-41-03, REQ-41-04, REQ-41-05, REQ-41-06, REQ-41-07, REQ-41-08, REQ-41-09, REQ-41-10
**Plans:** 5/5 plans complete

Plans:

**Wave 1**
- [x] 41-01-PLAN.md — 2 migrations (`ml_advertisers` + `sugador_ml_company_config`) + 2 Models + relações Company (`mlAdvertiser`, `sugadorMlConfig`) + tests schema/FK cascade (REQ-41-01, REQ-41-02)

**Wave 2** *(blocked on Wave 1; 41-02 e 41-03 paralelizáveis — sem overlap de arquivos)*
- [x] 41-02-PLAN.md — Refactor `MercadoLivreAdsService`: `callWithBackoff` (429/5xx/401/403) + cache advertiser 7d + rate limiter `ml-api:{seller_id}` (60/min) registrado em `AppServiceProvider` + métricas operacionais via `getLastRunMetrics` + tests Http::fake (REQ-41-03, REQ-41-04, REQ-41-05, REQ-41-06)
- [x] 41-03-PLAN.md — Refactor comando `sugadores:shadow-ml --company=all` priorizando `SugadorMlCompanyConfig::where('shadow_enabled', true)` + fallback env CSV preservado + tests config table vs env (REQ-41-07)

**Wave 3** *(blocked on Wave 2)*
- [x] 41-04-PLAN.md — Estende `ShadowRunService` (Plan 40-02) para mesclar `ml_metrics` no `summary` JSON da run quando provider=ml + tests (REQ-41-06 finalização)
- [x] 41-05-PLAN.md — UI admin `/dev/sugadores-ml-onboarding`: Controller (4 actions) + 4 rotas role:admin + página React (tabela 6 colunas + filtros + ações inline) + item de sidebar Sistema + `npm run build` + tests Feature (REQ-41-08, REQ-41-09, REQ-41-10)

Cross-cutting constraints:
- pt-BR em todos os artefatos e comentários (CLAUDE.md mandate)
- `npm run build` obrigatório após edição JSX (Plan 41-05)
- Phase NÃO grava em `sugadores` (gate REQ-40-02 preservado pelos services Plan 40-02/41-04)
- Tests rodam SQLite em-memory + Http::fake + Mockery (bloqueio MariaDB local não impacta)
- Smoke real (clicar "Rodar smoke agora" no painel) deferido até MariaDB voltar + smoke Phase 38-02 destravar

**UI hint**: yes

### Phase 42: Sugadores via API ML (troca de motor + esconder UI Dev paralela)

**Milestone:** v11.0
**Status:** Pending
**Mode:** mvp
**Reorientada em:** 2026-06-26 (briefing do usuário `fix-melhorias-sugadores-api-mercado-livre.md`, salvo em `.planning/phases/42-sugadores-api-ml/42-CONTEXT.md`)
**Goal:** Trocar a fonte de dados dos sugadores do Adman para a API oficial do Mercado Livre SEM criar novas telas, menus ou fluxos paralelos. A `/sugadores`, `/sugadores/{id}` e `/sugadores/config/{company}` continuam sendo as únicas telas operacionais. A API ML alimenta o mesmo contrato normalizado, o mesmo `SugadorAnalysisService` e a mesma tabela `sugadores`. Janela 30d fechados (ontem-29d → ontem). Item sidebar "Onboarding ML" (Phase 41) escondido — rota permanece como ferramenta técnica admin acessível por URL direta.

**Depends on:** Phase 41 (mlToken + advertiser cache + rate limiter + backoff + métricas operacionais já entregues)

**Requirements:**
- REQ-42-01 — `MercadoLivreSugadoresProvider` (ou equivalente) normaliza payload da API ML para o contrato canônico (§3 do briefing): `adgroup_id`, `campaign_id`, `investment`, `revenue`, `sold_quantity`, `clicks`, `impressions`, `cpc`, `ctr`, `acos`, `roas`, `organic_amount`, `organic_units`, `raw`
- REQ-42-02 — `SugadorAnalysisService::analyzeCompany($company, $referenceDate, $dryRun=false, $forceProvider='ml')` grava em `sugadores` via ML com mesma idempotência (chave: `company_id|reference_date|tipo|campaign_id|adgroup_id`)
- REQ-42-03 — Janela default: `reference_date=hoje`, `periodo_fim=ontem`, `periodo_inicio=ontem-29d` (30 dias fechados). Vale para cron e análise manual sem override
- REQ-42-04 — Adiciona `cpc_minimo_cliques` (nullable int) em `sugador_configs` + UI em `/sugadores/config/{company}` + lógica composta em `cpc_alto`: `sold_quantity==0 && cpc > cpc_maximo && (cpc_minimo_cliques === null || clicks >= cpc_minimo_cliques)`
- REQ-42-05 — Quarentena SGI preservada: pular campanhas com nome contendo `SGI`/`Sugadores`/`Sugador`/pausadas/encerradas (mesma regra hoje aplicada ao Adman)
- REQ-42-06 — Status travados (`em_acao`/`resolvido`/`ignorado`/`movido`/`auto_resolvido`) NÃO podem voltar para `pendente` em re-análise via ML. Métricas e `raw_data` atualizam, status persiste
- REQ-42-07 — Item sidebar "Onboarding ML" REMOVIDO de `AppLayout.jsx`. Rota `/dev/sugadores-ml-onboarding` permanece (acesso via URL direta, role:admin). Phase 41 fica preservada como ferramenta técnica
- REQ-42-08 — ByMobille - Teste (#298) é o piloto: roda análise ML, gera sugadores em `/sugadores`, valida que aparecem com os mesmos cards de métricas (investimento, vendas, faturamento, ACOS, cliques, impressões, CPC médio, ROAS)
- REQ-42-09 — Botão "Painel de Ads" em `/sugadores/{id}` abre o painel correto do Mercado Livre usando `campaign_id`/`ad_id`/`item_id` (não Adman) para sugadores de origem ML
- REQ-42-10 — Testes Feature existentes em `tests/Feature/Sugadores*` continuam passando sem alteração (analista não percebe a troca de motor)

**Success Criteria** (must-haves verificáveis):
  1. `/sugadores` continua sendo a única tela operacional do analista; nenhum item novo na sidebar
  2. `/sugadores/config/{company}` mostra o novo campo `cpc_minimo_cliques` ao lado de `cpc_maximo` + `cpc_maximo_logic`
  3. Roda análise de ByMobille - Teste via comando manual; sugadores aparecem em `/sugadores` com origem ML transparente
  4. Janela exibida no detalhe do sugador (`/sugadores/{id}`): `26/05/2026 → 24/06/2026` quando rodado em 25/06/2026 (exemplo do briefing §4)
  5. Configurar `gasto_minimo_sem_venda=20` modo OU e rodar análise: sugador com gasto >= R$20 e zero vendas vira `pendente`
  6. Configurar `cpc_maximo=4` modo OU + `cpc_minimo_cliques=5` e rodar análise: sugador só flaga quando CPC > 4 E cliques >= 5 E zero vendas
  7. Campanha com nome `SGI - Lentes` é pulada da análise ML (quarentena §12)
  8. Sugador em `em_acao`/`resolvido` permanece nesse status após re-análise ML do mesmo dia
  9. Item sidebar "Onboarding ML" não aparece para nenhum usuário; rota `/dev/sugadores-ml-onboarding` continua respondendo via URL direta (admin)
 10. Todos os testes Feature de Sugadores existentes passam

**Plans:** 6/6 plans complete

Plans:

**Wave 1**
- [x] 42-01-PLAN.md — Migration cpc_minimo_cliques + logica composta no evaluator (REQ-42-04 backend, TDD)

**Wave 2** *(blocked on Wave 1; 42-02 e 42-03 paralelos)*
- [x] 42-02-PLAN.md — UI campo cpc_minimo_cliques em /sugadores/configs/{company} (REQ-42-04 frontend)
- [x] 42-03-PLAN.md — Normalizer ML contrato §3 completo + comentario janela 30d + quarentena SGI por nome+status (REQ-42-01, REQ-42-03, REQ-42-05)

**Wave 3** *(blocked on Wave 2)*
- [x] 42-04-PLAN.md — Cut-over factory (ML preferido) + remove guard ml_primary + controller aceita empresas ML-only (REQ-42-02, REQ-42-06, REQ-42-08)

**Wave 4** *(blocked on Wave 3; 42-05 e 42-06 paralelos)*
- [x] 42-05-PLAN.md — Sidebar esconde Onboarding ML + linkAdsML deep link Mercado Ads (REQ-42-07, REQ-42-09)
- [x] 42-06-PLAN.md — Suite aceite E2E ByMobille + guard de regressao Sugadores legados (REQ-42-08, REQ-42-10)
**UI hint**: yes (campo novo em /sugadores/config/{company} + esconder item sidebar)

### Phase 43: Remoção da Adman (Sugadores)

**Milestone:** v11.0
**Status:** Pending
**Mode:** mvp
**Goal:** Só iniciar quando 100% das empresas ativas MLB tiverem `mlToken` válido + scheduler ML estável + 429 ML < 1% por 7d + contas grandes < 900s + suporte aceitar Adman não ser mais fallback. Remove env obrigatório `ADMAN_API_KEY` do path Sugadores (mantém pra Dashboard se ainda dependente). Renomeia `adman_adgroup_mlbs` → `sugador_adgroup_mlbs` via migration simples. Mantém compatibilidade de leitura no histórico.
**Depends on:** Phase 42 (primary estável em todas empresas relevantes)
**Plans:** TBD
**UI hint**: no

### Phase 44: Mover adgroup-sugador para campanha SGI via API ML

**Goal:** Expor 1 ação destrutiva no `Show.jsx` do sugador — mover adgroup-sugador para campanha SGI (quarentena pausada) via `PATCH` na API ML Product Ads. Eliminar os ~5 cliques redundantes no painel do Mercado Ads e dar rastreabilidade no histórico do sugador (`activity_log` + `Sugador.status='movido'`).

**Mode:** standard

**Requirements**: Detalhados em [44-CONTEXT.md](.planning/phases/44-mover-adgroup-sugador-para-sgi-ou-pausar-via-api-ml/44-CONTEXT.md):
- Combobox com SGIs da conta (reusa `QUARANTINE_NAME_REGEX`) + opção "Criar nova SGI" (pausada, nome sugerido `SGI [YYYY-MM]` editável)
- Modal de confirmação dupla com nome literal do adgroup + nome da SGI destino
- Toast "Desfazer" por 10s (sem persistência DB)
- Aviso não-bloqueante se SGI escolhida está ativa
- Plan 44-01 obrigatório: smoke do PATCH antes de qualquer planejamento

**Depends on:** Phase 43 (path Adman removido — não faz sentido editar adgroup ML enquanto há fallback Adman ativo)

**Plans:** 4 plans

Plans:
- [ ] 44-01-PLAN.md — Smoke + scope OAuth (BLOQUEIO Wave 1: ml-write-smoke command + scope read write offline_access + checkpoint humano Bymobille)
- [ ] 44-02-PLAN.md — Backend (MercadoLivreAdsService::createCampaign + moveAdgroupToCampaign + 3 actions controller + feature flag + 3 rotas; 14 tests Feature)
- [ ] 44-03-PLAN.md — Frontend (MoveToSgiModal evoluido mode=api_call + UndoToast novo + Show.jsx orquestracao; preserva bulk Index audit_only)
- [ ] 44-04-PLAN.md — UX re-auth global (banner amarelo Show.jsx quando scope sem write + runbook operacional STATE.md)

**UI hint:** sim — modal de confirmação dupla com combobox de SGI no `Show.jsx`

**Deferred (originalmente parte da Phase 44, reduzido na discuss):**
- Pausar adgroup in-place → Phase 44b
- Ações em lote (selecionar N sugadores no Index) → phase futura
- Botão "Reverter" permanente (persistir `campaign_id_anterior`) → phase futura

### Phase 45: Compatibilidade ML em métricas — foco em /desempenho + widget unify

**Milestone:** v12.0
**Status:** ✅ Complete (Caminho B — Plans 45-02/03 DEFERRED, UAT 45-04 APROVADO em 2026-06-29)
**Mode:** standard

**Goal:** Eliminar 2 bugs concretos em `/desempenho` e widgets relacionados, ambos causados pela mistura Adman/ML hoje: (1) a página `/performance` e o widget "Desempenho da equipe" da dashboard mostram classificações **DIFERENTES** pra mesma equipe — preciso ser exatamente igual (widget é preview da página); (2) empresas ML-only (hoje Bymobille #298, futuramente maioria) aparecem zeradas ou ausentes nos scores porque a leitura ainda vai majoritariamente em `adman_metrics`. Phase entrega `CompanyMetricsProvider` (factory por empresa Adman vs ML — pattern v11.0 Sugadores) e unifica a fonte de verdade do scoring/ranking. Itens 1a/1b (compat ML em dashboard métricas e carteira admin/líder) ficam fora — entram via aproveitamento natural do provider novo, mas o ESCOPO crítico é /desempenho.

**Requirements** (capturados em [.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md](.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md) — foco em Itens 1c e 3b):

- Widget "Desempenho da equipe" da dashboard mostra EXATAMENTE a mesma classificação/ranking da página `/performance` (preview resumida, mesma fonte de verdade — hoje divergem)
- Empresas com fonte ML (sem `adman_account_id` ou só com `ml_store_id`) aparecem corretamente nos scores de quem as gerencia em `/performance` — não mais zeradas
- Abstração via `CompanyMetricsProvider` (factory por empresa, escolhe Adman vs ML baseado em `mlToken` ativo) reusando pattern v11.0 Sugadores — 2 implementações concretas (`AdmanMetricsProvider`, `MlMetricsProvider`)
- Service único compartilhado entre widget e página `/performance` (provavelmente `PerformanceScoreService` ou similar)

**Out of scope** (fica pra phases separadas):
- Item 1a — compat ML em dashboard admin métricas (faturamento total, TACOS médio, gráfico evolução)
- Item 1b — compat ML em carteira individual/geral (admin, líder)
- Redesign visual da carteira (briefing-carteira-analistas-ui.md) — vira phase separada quando o usuário decidir
- Histórico de scores — Phase 46
- Novos parâmetros de score — Phase 47

**Depends on:** Nenhuma (independente da Phase 44 destravar — pode rodar em paralelo)

**Plans:** TBD

**UI hint:** sim — widget "Desempenho da equipe" em `Dashboard/Admin.jsx` + página `/performance`. Mudanças funcionais (classificação correta) sem redesign visual.

### Phase 46: Histórico longitudinal de scores na página de desempenho

**Milestone:** v12.0
**Status:** Pending
**Mode:** standard

**Goal:** Resolver "classificação muda todo dia, não consigo decidir quem é realmente o melhor/pior". Adicionar snapshot diário dos scores via job no scheduler (após sync Adman/ML) + UI que mostra delta vs dia anterior / semana anterior + gráfico de evolução individual ao longo do tempo. Permite premiar/bonificar com base em dados longitudinais reais, não em ranking volátil.

**Requirements** (capturados em [.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md](.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md) — Item 4):

- Migration nova: `desempenho_score_snapshots` (user_id, ref_date, score, ranking_pos, breakdown_json) + Model Eloquent
- Job no scheduler que persiste snapshot diário (executar depois do sync Adman/ML do dia)
- UI da página /performance mostra: delta vs dia anterior (`↑ +0.5`), delta vs semana anterior, gráfico individual de evolução
- Briefing auxiliar `metodologia-desempenho-carteira.md` (untracked no root) **deve ser ingerido no discuss-phase** — tem a metodologia de scoring justa pensada pelo usuário (princípios de comparação, ajuste por porte/segmento/maturidade)

**Depends on:** Nenhuma (independente da Phase 44 e 45 — pode rodar em paralelo)

**Plans:** TBD

**UI hint:** sim — página /performance ganha indicadores de delta + gráfico de evolução por profissional

### Phase 47: Scoring por função com balanceamento por volume (sugador + PPA)

**Milestone:** v12.0
**Status:** Pending
**Mode:** standard

**Goal:** Ampliar o scoring com parâmetros que diferenciam funções (analista vs estrategista) RESPEITANDO o volume esperado da atividade. Sugador é diário (alto volume) → recompensa pequena OU penalização por não-resolução. PPA é mensal (baixo volume) → recompensa proporcionalmente maior. Garante incentivo justo em vez de só analista ganhar pontos novos.

**Requirements** (capturados em [.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md](.planning/todos/pending/270627-melhorias-dashboard-desempenho-ml-compat.md) Item 5 + briefing 2026-06-29 Item 5):

- **Para analistas — scoring sugador com BALANCEAMENTO POR VOLUME:**
  - Sugador é atividade DIÁRIA → recompensa por unidade resolvida deve ser pequena
  - **DECISÃO LOCKED:** scoring NEGATIVO — subtrair pontos por sugador NÃO resolvido na fila do analista (incentiva "limpar" o backlog em vez de só recompensar atividade)
  - Métrica entra automaticamente quando Phase 44 estiver em prod (resolver sugador via API ML = `Sugador.status='movido'`)
- **Para estrategistas — scoring PPA com peso PROPORCIONAL:**
  - PPA é atividade MENSAL ou mais espaçada → recompensa por unidade deve ser maior que a do sugador resolvido
  - Métrica: contagem de PPAs concluídos por estrategista no período
- **Princípio geral lockado:** o peso de cada parâmetro novo deve refletir o volume esperado — atividade frequente = recompensa pequena por unidade; atividade rara = recompensa maior por unidade. Evita inflar score de quem faz a função "fácil" (sugador diário) vs penalizar quem tem ciclo longo (PPA).
- Pesos exatos: definir em discuss-phase com base em volume médio observado em prod
- Funciona em cima do histórico longitudinal da Phase 46 — snapshots mostram impacto do novo scoring antes/depois

**Depends on:** Phase 44 (sugador-resolvido via ML — destrava contabilização automática), Phase 46 (snapshot diário precisa estar persistindo o breakdown). Sinergia com Phase 49 (rankings por função vão usar esse score diferenciado).

**Plans:** TBD

**UI hint:** parcial — indicadores no perfil/card de cada profissional mostrando: para analista (sugadores resolvidos / pendentes / não-resolvidos no período); para estrategista (PPAs concluídos no período). Componentes podem ser reaproveitados na carteira individual (Phase 48 Item 5).

### Phase 48: Redesign da carteira individual (analista + estrategista)

**Milestone:** v12.0
**Status:** Pending
**Mode:** standard

**Goal:** Modernizar a tela de carteira individual (`/portfolio/{id}` ou equivalente) seguindo o briefing UI do usuário + adicionar visibilidade granular por empresa (crescimento) + histórico NPS do profissional + bloco diferenciado por função (sugadores p/ analista, PPAs p/ estrategista) + REMOVER conceito de meta agregada da carteira (modelo errado — meta é por empresa).

**Requirements** (capturados em [briefing-carteira-analistas-ui.md](briefing-carteira-analistas-ui.md) + briefing 2026-06-29 Items 2/3/4/5):

- **UI modernizada** (briefing-carteira-analistas-ui.md, 237 linhas): hero card com gradiente + faturamento em destaque `R$ X.XXM`, KPIs simplificados, gráfico com tooltip, tabela orientada a ação, painel lateral estratégico, responsividade mobile
- **Indicador de crescimento por empresa na listagem** (Item 2): mini-gráfico inline em cada linha — verde subindo / vermelho descendo / cinza regular. Sparkline 7-14d via Recharts. Critério: revenue periodo atual vs anterior
- **Histórico de NPS do dono da carteira** (Item 3): bloco/widget mostrando notas NPS ao longo do tempo recebidas pelo profissional. Reusa tabelas/services Phase 31-33 (NPS mensal automatizado). Visual: gráfico de evolução + média + última nota
- **REMOVER meta da carteira** (Item 4 — DECISÃO LOCKED): meta agregada da carteira não existe mais. Tirar cards "Meta da carteira", "% atingido", "R$ restante pra meta". Manter meta por empresa (já existe). PortfolioScoreService: categoria "atingimento de meta" vira média ponderada de % de cada empresa individual (não soma agregada vs meta agregada)
- **Bloco diferenciado por função** (Item 5 UI — backend é Phase 47):
  - Carteira ANALISTA: bloco de métricas de Sugadores (resolvidos / pendentes / não-resolvidos)
  - Carteira ESTRATEGISTA: bloco de métricas de PPAs (concluídos no período)
  - Esses blocos consomem os mesmos counters que a Phase 47 usa no score (consistência)

**Depends on:** Nenhuma (pode rodar antes ou em paralelo). Sinergia: Phase 47 entrega os counters para o bloco diferenciado; se 47 não estiver pronta, 48 mostra placeholder ou usa contagem direta dos models.

**Plans:** TBD

**UI hint:** SIM — redesign completo de página. Reusa tokens `ecf-*`, dark theme, Recharts (já no projeto). Briefing tem mockup HTML estático (`carteira-analistas-ui-proposta.html`) como referência.

### Phase 49: Rankings de /desempenho por função + ranking separado de publicação

**Milestone:** v12.0
**Status:** Pending
**Mode:** standard

**Goal:** Página `/performance` hoje tem ranking ÚNICO (Geral). Adicionar 4 visualizações: Geral (atual), Analistas (filtro por função), Estrategistas (filtro por função), e Publicação **em rota separada** dentro do dropdown "Publicação" do menu lateral (não em /performance — funções distintas, públicos distintos).

**Requirements** (briefing 2026-06-29 Item 1):

- 3 visualizações em `/performance` (tabs ou seletor): Geral / Analistas / Estrategistas
- Cada ranking usa o mesmo `PortfolioScoreService` mas com filtro de função no input — DRY
- Filtro de função reusa o pattern do filtro canônico de users (`user_setores → cargos.slug`) — alinhado com a Phase 45 fix
- Nova rota `/publicacao/desempenho` (nome a confirmar — pode ser `/publicacao/ranking`) dentro do dropdown Publicação do menu lateral
- Ranking de publicação usa scoring específico de publicação (provavelmente parâmetros próprios — pode reusar service base ou ter service dedicado se métricas divergirem muito)
- Sidebar: aba "Desempenho" dentro do dropdown Publicação (excludeRoles=analista/estrategista — só quem é publicação acessa)

**Depends on:** Phase 47 (precisa do filtro de função no score pra Analistas vs Estrategistas terem dados diferenciados). Phase 45 (fix do filtro canônico de users — base).

**Plans:** TBD

**UI hint:** SIM — tabs em `/performance` + nova página em `/publicacao/desempenho` + ajuste no sidebar AppLayout.jsx (nova entry no dropdown Publicação).

### Phase 50: Gamificação OAuth ML para Líder Performance + Estrategistas

**Milestone:** v12.0
**Status:** Pending
**Mode:** standard

**Goal:** Acelerar migração de empresas de Adman → ML criando incentivo gamificado pra quem tem relação direta com cliente. Conectar empresa ao OAuth ML exige conversa humana (reunião semanal ou mensagem). Líder de Performance + Estrategistas são quem faz essa conversa. Nova aba/rota dedicada mostra empresas pendentes de OAuth com ranking/score por conexão concluída.

**Requirements** (briefing 2026-06-29 Item 6):

- Nova rota no menu lateral (provavelmente em "Dados Estratégicos" ou seção própria — decidir em discuss-phase)
- Tela mostra empresas com status de OAuth ML: "Pendente" (sem mlToken active) / "Em conversa com cliente" (status manual) / "ML conectado" (mlToken.status='active')
- **Visão diferenciada por role:**
  - **Estrategista:** apenas empresas atribuídas a ele via `company_users` (pivot já existe)
  - **Líder Performance:** TODAS as empresas (visão global)
- Ranking/badge/score por conexão concluída — incentiva ação proativa. Pode integrar com Phase 47 (parâmetro de score adicional) ou ser ranking standalone
- Status "Em conversa com cliente" é estado intermediário manual (usuário marca quando inicia conversa) — evita "esquecer" empresa parada
- Possíveis ganhos secundários: visibilidade pro time saber qual empresa está em qual etapa, accountability

**Depends on:** Nenhuma direta. Sinergia com Phase 41 (Onboarding ML por empresa) — pode reusar models/views se aproximação for similar. Sinergia com Phase 47 (se ranking entrar no score geral).

**Plans:** TBD

**UI hint:** SIM — nova rota + nova entry no sidebar + lista de empresas com filtros por status + indicadores gamificados (badges, ranking).
