# Requirements: ECF Admin

**Última atualização:** 2026-05-21

---

## Milestone v4.0 — Fluxo Comercial

**Definido:** 2026-05-25
**Core Value:** Comercial é a única porta de entrada para novas empresas; cada cadastro cria os registros corretos automaticamente por service_type; setores de destino recebem alerta e veem pendentes nas suas páginas existentes sem precisar de intervenção admin.

### Acesso e Interface (COM)

- [ ] **COM-01**: Usuário do setor Comercial vê item "Comercial" no sidebar com sub-item "Cadastro de Empresas" e consegue acessar o formulário de cadastro; usuário sem permissão `comercial.cadastrar_empresa` recebe 403 ao tentar acessar
- [ ] **COM-02**: Formulário de cadastro exibe campos Nome (obrigatório), CNPJ (opcional, único), service_type (obrigatório) e subtipo dinâmico POLOS/Assessoria quando service_type='publicacao'
- [ ] **COM-03**: Guard de duplicatas bloqueia cadastro com nome igual (case-insensitive) a qualquer `companies.name` ou `mlb_empresas.nome` existente, exibindo mensagem de erro clara

### Criação Automática por Tipo (COM)

- [ ] **COM-04**: Ao cadastrar empresa tipo POLOS, o sistema cria atomicamente em DB::transaction: `companies` (status='pendente') + `mlb_empresas` (tipo=POLO, projeto=POLOS) + `mlb_implementacao` com token Str::random(48) e dados de `dadosPadrao()` + `implementacaoPadroes()`
- [ ] **COM-05**: Ao cadastrar empresa tipo Assessoria, o sistema cria atomicamente: `companies` (status='pendente') + `mlb_empresas` (tipo=ASSESSORIA)
- [ ] **COM-06**: Ao cadastrar empresa tipo Publicidade ou Gestão, o sistema cria apenas `companies` (status='pendente', sem mlb_empresas)
- [ ] **COM-07**: Empresa recém-cadastrada (qualquer tipo) aparece em `/administrativo/financeiro` sem ação adicional do admin

### Visibilidade de Pendentes (COM)

- [ ] **COM-08**: Ao cadastrar nova empresa, líderes do setor de destino recebem notificação automática via sistema de notificações (Phases 8-12) com informações da empresa cadastrada
- [ ] **COM-09**: Setor de destino vê seção "Pendentes" na sua página existente (`/mlb/empresas` para Publicação, `/companies` para Publicidade/Gestão) com badge visual das empresas status='pendente'

### Migração Retroativa (COM)

- [ ] **COM-10**: Migration de dados idempotente cria registro em `companies` para cada `mlb_empresa` sem `company_id`, derivando service_type automaticamente (POLO/POLOS→'polos', ASSESSORIA→'assessoria', Incubadora→'incubadora') e gravando status='ativo'; todos os companies existentes também recebem status='ativo'
- [ ] **COM-11**: Coluna `mlb_empresas.company_id` (FK nullable, nullOnDelete → companies) é preenchida para todos os registros existentes e novos após migração

## Traceability v4.0

| Requisito | Fase | Status |
|-----------|------|--------|
| COM-01 | Phase 13 | Pending |
| COM-02 | Phase 13 | Pending |
| COM-03 | Phase 13 | Pending |
| COM-04 | Phase 13 | Pending |
| COM-05 | Phase 13 | Pending |
| COM-06 | Phase 13 | Pending |
| COM-07 | Phase 13 | Pending |
| COM-08 | Phase 13 | Pending |
| COM-09 | Phase 13 | Pending |
| COM-10 | Phase 13 | Pending |
| COM-11 | Phase 13 | Pending |

**Cobertura v4.0:**
- Requirements: 11 total (3 acesso/interface + 4 criação automática + 2 visibilidade + 2 migração)
- Mapeados para fases: 11 (100%)
- Não mapeados: 0

---

## Milestone v3.0 — Sistema de Notificações

**Definido:** 2026-05-21
**Core Value:** Usuários recebem notificações relevantes (metas atribuídas/atingidas e mensagens manuais) em tempo quase real via sino no header, com targeting por usuário/setor/líderes/todos e disparo automático a partir de eventos de metas.

### Sino no Header (SINO)

- [ ] **SINO-01**: Usuário autenticado vê ícone de sino no canto superior direito do `AppLayout` em todas as páginas
- [ ] **SINO-02**: Usuário vê badge numérico no sino com a contagem de notificações não lidas; badge oculto quando contagem é zero
- [ ] **SINO-03**: Usuário clica no sino e vê dropdown com as últimas 10 notificações (não lidas + recentes)
- [ ] **SINO-04**: Cada item do dropdown exibe título, prévia da mensagem, autor (quando manual), tempo relativo ("há 5min") e indicador visual de não lida
- [ ] **SINO-05**: Usuário clica em uma notificação não lida no dropdown para marcá-la como lida; badge decrementa imediatamente sem reload
- [ ] **SINO-06**: Dropdown tem link "Ver todas" que navega para a página `/notificacoes`

### Página de Histórico (HIST)

- [ ] **HIST-01**: Usuário acessa página `/notificacoes` (rota nomeada `notificacoes.index`) e vê lista paginada das próprias notificações
- [ ] **HIST-02**: Página tem abas "Não lidas" (default) e "Todas" — a aba "Todas" inclui lidas com até 30 dias
- [ ] **HIST-03**: Usuário pode marcar qualquer notificação individual como lida diretamente na lista
- [ ] **HIST-04**: Usuário pode marcar todas as não lidas como lidas com um botão "Marcar todas como lidas"
- [ ] **HIST-05**: Cada item exibe título, mensagem completa, origem (manual com nome do autor, ou sistema), categoria visual (cor/ícone por tipo de evento) e data/hora absoluta

### Criação Manual e Targeting (ENVIO)

- [ ] **ENVIO-01**: Usuário com permissão `notificacoes.criar` vê item de menu "Enviar notificação" no sidebar e acessa `/notificacoes/nova`
- [ ] **ENVIO-02**: Usuário pode escolher o público da notificação: (a) usuário individual via busca, (b) um setor inteiro, (c) todos os líderes (qualquer user em `setor_lideres`), ou (d) todos os usuários ativos
- [ ] **ENVIO-03**: Usuário preenche título obrigatório (máx 100 caracteres) e mensagem obrigatória (máx 1000 caracteres) antes de enviar
- [ ] **ENVIO-04**: Após envio, o criador vê confirmação com a quantidade efetiva de destinatários; as notificações ficam visíveis para os destinatários imediatamente (sem necessidade de reload do lado deles além do polling)
- [ ] **ENVIO-05**: Usuário sem permissão `notificacoes.criar` não vê o menu nem consegue acessar a rota `/notificacoes/nova` (retorna 403)

### Disparos Automáticos de Metas (AUTO)

- [ ] **AUTO-01**: Quando uma `SetorGoal` é criada, todos os membros do setor recebem notificação "Nova meta do setor: [descrição]"
- [ ] **AUTO-02**: Quando uma `Goal` (meta de empresa) é criada, o consultor e o mentor da empresa recebem notificação "Nova meta para [empresa]: [descrição]"
- [ ] **AUTO-03**: Quando uma `PortfolioGoal` (meta de carteira) é criada, o dono da carteira (user_id) recebe notificação "Nova meta de carteira: [descrição]"
- [ ] **AUTO-04**: Quando o resultado de uma `SetorGoal` atinge ou ultrapassa o `target_value`, todos os admins e os líderes do setor recebem notificação "Meta atingida: [setor] alcançou [métrica]" (disparo idempotente — uma notificação por período)
- [ ] **AUTO-05**: Quando o resultado de uma `Goal` (empresa) atinge `target_value`, o consultor e o mentor da empresa + todos os admins recebem notificação "Meta atingida: [empresa] alcançou [métrica]"
- [ ] **AUTO-06**: Quando o resultado de uma `PortfolioGoal` atinge `target_value`, o dono da carteira + todos os admins recebem notificação "Meta atingida: sua carteira alcançou [métrica]"

### Permissões (PERM)

- [ ] **PERM-01**: Sistema registra nova permission_key `notificacoes.criar` no catálogo `App\Support\Permissions` (grupo "Sistema" ou novo grupo "Notificações") com label e descrição em pt-BR
- [ ] **PERM-02**: Admin (`User::isAdmin()`) tem `notificacoes.criar` automaticamente via short-circuit já existente em `hasPermission`
- [ ] **PERM-03**: Qualquer usuário em `setor_lideres` recebe `notificacoes.criar` automaticamente via inclusão da chave em `Permissions::AUTO_LIDERANCA`
- [ ] **PERM-04**: A permissão `notificacoes.criar` aparece na UI de configuração de setores (`/sistema/setores`) e pode ser concedida ao setor "Administrativo" ou a qualquer outro setor

### Real-time e Cleanup (POLL)

- [ ] **POLL-01**: Contador de notificações não lidas do usuário autenticado é exposto via shared prop Inertia `notificacoes_nao_lidas` (injetada em `HandleInertiaRequests`) em todas as páginas
- [ ] **POLL-02**: Frontend faz polling do endpoint `/api/notificacoes/contador` a cada ~60 segundos para atualizar o badge sem reload (intervalo configurável no client)
- [ ] **POLL-03**: Toda navegação Inertia revalida automaticamente o contador via shared prop, sem requisição extra
- [ ] **POLL-04**: Scheduled command `notifications:cleanup` roda diariamente (declarado em `routes/console.php`) e remove notificações lidas com mais de 30 dias
- [ ] **POLL-05**: `spatie/laravel-activitylog` registra criação manual de notificação (autor, público-alvo, contagem de destinatários); disparos automáticos NÃO são logados para evitar inundação

## Out of Scope (v3.0)

| Feature | Motivo |
|---------|--------|
| WebSocket / broadcast em tempo real (Laravel Reverb/Pusher) | Polling 60s + revalidação Inertia atende UX; broadcast adiciona infra de worker e config — fica para v4.0+ se demanda surgir |
| Notificação por email | In-app é suficiente; email pode ser adicionado depois reutilizando o sistema de Notification do Laravel |
| Push notification (browser/mobile) | Fora do escopo de painel interno acessado em desktop |
| Categorias customizáveis pelo usuário | Categorias são fixas no MVP (meta_atribuida, meta_atingida, manual) |
| Preferências por usuário (silenciar tipos, frequência) | Adição valiosa mas fora do MVP; deixar para v4.0+ |
| Anexos em notificações (imagem, arquivo) | Texto puro é suficiente para os casos do MVP |
| Resposta/comentário em notificações | Notificação é one-way no MVP |
| Templates de notificação reutilizáveis | Mensagens são livres ou geradas a partir do evento; templates são complexidade extra |
| Notificação de outros eventos do sistema (sync falhado, sugador detectado, NPS recebido) | Restringir o MVP a metas + manuais; outros eventos podem ser plugados nas iterações seguintes |
| Painel admin de auditoria/uso de notificações (quantas enviadas por user, taxa de leitura) | Métricas operacionais ficam para milestone futuro |

## Traceability v3.0

| Requisito | Fase | Status |
|-----------|------|--------|
| SINO-01 | Phase 10 | Pending |
| SINO-02 | Phase 10 | Pending |
| SINO-03 | Phase 10 | Pending |
| SINO-04 | Phase 10 | Pending |
| SINO-05 | Phase 10 | Pending |
| SINO-06 | Phase 10 | Pending |
| HIST-01 | Phase 9 | Pending |
| HIST-02 | Phase 10 | Pending |
| HIST-03 | Phase 9 | Pending |
| HIST-04 | Phase 9 | Pending |
| HIST-05 | Phase 10 | Pending |
| ENVIO-01 | Phase 12 | Pending |
| ENVIO-02 | Phase 12 | Pending |
| ENVIO-03 | Phase 12 | Pending |
| ENVIO-04 | Phase 12 | Pending |
| ENVIO-05 | Phase 12 | Pending |
| AUTO-01 | Phase 11 | Pending |
| AUTO-02 | Phase 11 | Pending |
| AUTO-03 | Phase 11 | Pending |
| AUTO-04 | Phase 11 | Pending |
| AUTO-05 | Phase 11 | Pending |
| AUTO-06 | Phase 11 | Pending |
| PERM-01 | Phase 8 | Pending |
| PERM-02 | Phase 8 | Pending |
| PERM-03 | Phase 8 | Pending |
| PERM-04 | Phase 12 | Pending |
| POLL-01 | Phase 9 | Pending |
| POLL-02 | Phase 9 | Pending |
| POLL-03 | Phase 9 | Pending |
| POLL-04 | Phase 12 | Pending |
| POLL-05 | Phase 12 | Pending |

**Cobertura v3.0:**
- Requirements: 31 total (6 SINO + 5 HIST + 5 ENVIO + 6 AUTO + 4 PERM + 5 POLL)
- Mapeados para fases: 31 (100%)
- Não mapeados: 0
- Distribuição: Phase 8 (3 reqs) · Phase 9 (6 reqs) · Phase 10 (8 reqs) · Phase 11 (6 reqs) · Phase 12 (8 reqs)

---

## Milestone v2.0 — Administrativo Fechamento (entregue 2026-05-19)

**Definido:** 2026-05-19
**Core Value:** Admin pode ver o faturamento de cada empresa, sua faixa de investimento e o total a cobrar no mês — sem acessar o servidor ou planilhas externas.

### Fechamento (FCH)

- [x] **FCH-01**: Admin pode ver todas as empresas cadastradas na tela Fechamento, incluindo empresas sem integração Adman (exibidas com badge "Sem integração")
- [x] **FCH-02**: Admin pode configurar o tipo de serviço de cada empresa (POLO / Assessoria / Incubadora)
- [x] **FCH-03**: Admin pode registrar e ver as datas de início e encerramento do contrato de cada empresa
- [x] **FCH-04**: Admin pode ver o faturamento mensal de cada empresa calculado dos dados diários sincronizados (adman_metrics.revenue), com indicação do período coberto
- [x] **FCH-05**: Admin pode ver a faixa de investimento e o valor mensal a cobrar de cada empresa, calculados automaticamente pela tabela de progressão
- [x] **FCH-06**: Admin pode ver barra de progresso com posição na faixa atual e quanto falta para a próxima; faixa máxima (>R$5M) exibe "Faixa máxima" sem barra
- [x] **FCH-07**: Admin pode ver o total consolidado a cobrar no mês (soma apenas de empresas com dados válidos)
- [x] **FCH-08**: Admin pode ver campo de serviço adicional por empresa (visível, sem lógica de valor neste milestone)

### Configuração (CFG)

- [x] **CFG-01**: O label "Financeiro" no sidebar e na página foi renomeado para "Fechamento"

## Out of Scope (v2.0)

| Feature | Motivo |
|---------|--------|
| Emissão de NF / boleto | Não é sistema de cobrança — é painel de consulta |
| Edição da tabela de faixas via UI | Regra de negócio estável; mudança via deploy |
| Pro-rata de dias no mês | Complexidade sem valor no MVP |
| Régua de dunning / cobrança automática | Fora do escopo do painel administrativo |
| Histórico mensal de fechamentos | v2.1+ |
| Exportação para CSV/Excel | v2.1+ |
| Lógica de valor para serviço adicional | Campo reservado; lógica definida em milestone futuro |

## Traceability v2.0

| Requisito | Fase | Status |
|-----------|------|--------|
| FCH-01 | Phase 5 | Complete |
| FCH-02 | Phase 5 | Complete |
| FCH-03 | Phase 5 | Complete |
| FCH-04 | Phase 6 | Complete |
| FCH-05 | Phase 6 | Complete |
| FCH-06 | Phase 7 | Complete |
| FCH-07 | Phase 7 | Complete |
| FCH-08 | Phase 7 | Complete |
| CFG-01 | Phase 7 | Complete |

---

## Milestone v1.0 — Setor Dev (referência histórica)

**Definido:** 2026-05-18 | **Status:** Phase 1 completa; DEV-05..08 pausados para v4.0

### Entregues (Phase 1 ✓)

- [x] **DEV-01**: Admin pode ver data/hora do último sync Adman por empresa
- [x] **DEV-02**: Admin pode ver o payload bruto retornado pela API Adman (ou erro HTTP) de cada sync
- [x] **DEV-03**: Admin pode ver o diff do sync: quantos registros foram criados, atualizados e ignorados
- [x] **DEV-04**: Admin pode disparar o sync Adman de uma empresa específica manualmente via botão

### Pausados (retomar em v4.0)

- [ ] **DEV-05**: Admin pode ver status da fila de jobs (pendentes, em execução, falhados com detalhe do erro)
- [ ] **DEV-06**: Admin pode ver logs recentes do sistema (errors e warnings) sem acessar o servidor
- [ ] **DEV-07**: Admin pode ver informações do ambiente (versão PHP, driver de fila, driver de cache, uptime)
- [ ] **DEV-08**: Admin pode visualizar e editar configurações/flags do sistema

---

*Requirements v3.0 definidos: 2026-05-21 · Mapeamento de fases concluído: 2026-05-21*
