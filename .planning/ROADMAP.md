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

- [x] **Phase 13: Reestruturação do Cadastro de Empresas** - Setor Comercial é a única porta de entrada para novas empresas; cadastro roteia automaticamente por service_type; migração retroativa vincula mlb_empresas a companies (completed 2026-05-25)
- [ ] **Phase 14: Consolidação do Modelo de Serviços (Frente B)** - Modelo unificado: campos legacy de companies (service_type/contract_start/contract_end/additional_service/additional_service_price) substituídos por contratos_servico N:N introduzido na quick task 260526-jgj; Fechamento, Comercial e demais consumidores migrados sem alterar resultados financeiros

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
**Plans**: TBD (definido em `/gsd-plan-phase 14`)

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
| 14. Consolidação do Modelo de Serviços | 0/? | Planned | - |
