# Phase 13: Reestruturação do Cadastro de Empresas — Fluxo Centralizado via Comercial - Context

**Gathered:** 2026-05-25
**Status:** Ready for planning

<domain>
## Phase Boundary

Criar um fluxo centralizado onde o setor Comercial é a única porta de entrada para cadastro
de novas empresas no sistema. O cadastro do Comercial cria **sempre** um registro em `companies`
e roteia automaticamente para o setor correto com base no `service_type`:

- **Publicação POLOS** → cria `companies` + `mlb_empresas` (tipo=POLO, fase=M0, projeto=POLOS) + `mlb_implementacoes` automaticamente
- **Publicação Assessoria** → cria `companies` + `mlb_empresas` (tipo=ASSESSORIA)
- **Publicidade** → cria `companies` (service_type='publicidade', adman_account_id vazio)
- **Gestão** → cria `companies` (service_type='gestao', sem consultor ainda)

Toda empresa aparece em `AdminController@fechamento` (financeiro) desde o primeiro cadastro.
Empresas existentes em `mlb_empresas` são retroativamente migradas para `companies`.

**RESTRIÇÃO CRÍTICA DE DEPLOY:** Toda implementação desta fase é testada e validada
**exclusivamente em localhost**. Nenhum deploy para VPS (177.7.53.164) sem autorização
explícita do usuário. Isso inclui migrations, build de assets e qualquer alteração de código.

</domain>

<decisions>
## Implementation Decisions

### Acesso do Comercial
- **D-01:** Criar setor "Comercial" na tabela `setores` (is_system=false) + permission_key `comercial.cadastrar_empresa` no catálogo `app/Support/Permissions.php`. Membros do setor Comercial ganham a permissão automaticamente via `setor_permissoes`.
- **D-02:** Admin sempre tem acesso à tela do Comercial via `isAdmin()` short-circuit — padrão existente do sistema (não criar exceção).
- **D-03:** Item "Comercial" aparece no sidebar do `AppLayout.jsx` para usuários com `comercial.cadastrar_empresa`, com sub-item "Cadastro de Empresas". Segue o padrão de gating por permissão já usado em outros setores.

### Dados no Cadastro Inicial
- **D-04:** Cadastro mínimo — Comercial preenche apenas: **Nome** (obrigatório), **CNPJ** (opcional, único), **service_type** (obrigatório), e **subtipo** quando service_type='publicacao'.
- **D-05:** Dados contratuais (`contract_type`, `contract_start`, `contract_end`, `additional_service`, `additional_service_price`) **NÃO são obrigatórios** no cadastro inicial — preenchidos depois pelo Financeiro/Admin via `/administrativo/financeiro`.
- **D-06:** Quando service_type = Publicação, o formulário exibe dinamicamente um segundo campo de subtipo: **POLOS** ou **Assessoria**. Esse subtipo determina se cria `mlb_implementacoes` automaticamente (POLOS) ou só `mlb_empresas` (Assessoria).
- **D-07:** Duplicatas bloqueadas — validação case-insensitive contra `companies.name` e `mlb_empresas.nome` (guard já existe em `MlbImplementacaoController`). Exibe mensagem de erro com opção de confirmar vínculo se empresa já existir.
- **D-08:** Comercial **não atribui** consultor/estrategista no cadastro. Atribuição via `company_users` fica para o setor responsável ao completar os dados.

### Status e Visibilidade de Pendentes
- **D-09:** Adicionar coluna `companies.status` (enum/string: `'pendente'`, `'ativo'`). Cadastros novos do Comercial iniciam como `'pendente'`. O setor responsável marca como `'ativo'` ao completar os dados obrigatórios da área.
- **D-10:** Duplo aviso ao cadastrar empresa nova: (a) **notificação automática** para os líderes do setor de destino usando o sistema de notificações (Phases 8-12); (b) **seção "Pendentes"** destacada no topo das páginas existentes de cada setor (não criar novas páginas).
- **D-11:** Seções "Pendentes" nas páginas existentes:
  - Implementação: empresa já aparece em `/implementacao` automaticamente (POLOS)
  - Publicação: `/mlb/empresas` exibe seção de pendentes no topo
  - Publicidade: `/companies` exibe pendentes com badge visual
  - Gestão: `/companies` idem
  - Financeiro: todas as companies aparecem em `/administrativo/financeiro` independente do status

### Nomenclatura dos Service Types
- **D-12:** Renomear `'polo'` → `'polos'` no banco e validações (alinha com `mlb_empresas.projeto='POLOS'`). Migration atualiza registros existentes com `service_type='polo'` para `'polos'`.
- **D-13:** Valores finais de `companies.service_type`: `'polos'`, `'assessoria'`, `'incubadora'`, `'publicidade'`, `'gestao'`.

### Migração de Dados Existentes
- **D-14:** Migration de dados cria um registro em `companies` para **cada `mlb_empresa` sem `company_id`**. Dados MLB (fase, estagio, skus, responsavel_id, etc.) permanecem 100% intactos na tabela `mlb_empresas`.
- **D-15:** `service_type` das Companies retroativas derivado automaticamente:
  - `mlb_empresa.tipo = 'POLO'` OU `mlb_empresa.projeto = 'POLOS'` → `service_type = 'polos'`
  - `mlb_empresa.tipo = 'ASSESSORIA'` → `service_type = 'assessoria'`
  - `mlb_empresa.tipo = 'Incubadora'` → `service_type = 'incubadora'`
- **D-16:** Companies criadas retroativamente recebem `status = 'ativo'` (já em operação — não devem aparecer como pendentes nem gerar notificações).
- **D-17:** Todos os registros já existentes em `companies` também recebem `status = 'ativo'` via migration (valor default para dados pré-existentes).

### Link entre companies e mlb_empresas
- **D-18:** Adicionar coluna `mlb_empresas.company_id` (FK nullable, nullOnDelete → companies). A migration de dados preenche esta coluna para todos os registros existentes e novos.
- **D-19:** `companies` e `mlb_empresas` **NÃO são fundidas** — continuam tabelas separadas. O `company_id` é apenas o elo de rastreabilidade de origem.

### Criação Automática de Implementação para POLOS
- **D-20:** Ao cadastrar empresa POLO, reutilizar exatamente a lógica de `MlbImplementacaoController@criar` (linhas 176-213): `MlbImplementacao::dadosPadrao()` + merge com `MlbConfiguracao::implementacaoPadroes()` + `Str::random(48)` para token. Não duplicar a lógica — extrair para método privado compartilhado ou chamar diretamente.
- **D-21:** Guard de duplicata por nome de empresa reaplicado via `MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)')` — comportamento idêntico ao existente.

### Claude's Discretion
- Estrutura interna do `ComercialController` (service layer ou lógica inline no controller — seguir o padrão do CompanyController existente)
- URL da tela de cadastro: `/comercial/empresas/novo` ou `/comercial/cadastro` — escolher o que for mais claro
- Texto exato das notificações disparadas para líderes de setor
- Estilo visual da seção "Pendentes" (badge de contagem no título vs. seção separada destacada)

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Models e entidades centrais
- `app/Models/Company.php` — model central; fillable, casts, service_type, relacionamentos; adicionar status e company_id
- `app/Models/MlbEmpresa.php` — FASE_PARA_PROJETO mapping, fillable; adicionar company_id FK
- `app/Models/MlbImplementacao.php` — dadosPadrao(), CHECKLIST, ERP_OPCOES, INTEGRADOR_OPCOES
- `app/Models/Setor.php` — setor model, permissoes(), lideres()
- `app/Support/Permissions.php` — catálogo de permissões, AUTO_LIDERANCA; adicionar comercial.cadastrar_empresa

### Controllers de referência
- `app/Http/Controllers/MlbImplementacaoController.php` linhas 176-213 — lógica canônica de criação de POLO + Implementação (reutilizar, não duplicar)
- `app/Http/Controllers/AdminController.php` — fechamento() que lê todas companies; updateFechamento()
- `app/Http/Controllers/CompanyController.php` — padrão de CRUD de Company, validação de duplicata por CNPJ

### Infraestrutura de acesso e permissões
- `app/Http/Middleware/EnsureUserHasRole.php` — RBAC via role; padrão para novas rotas admin
- `app/Http/Middleware/HandleInertiaRequests.php` — shared props; avaliar exposição de contagem de pendentes
- `resources/js/Layouts/AppLayout.jsx` — sidebar com gating por permissão; onde adicionar item "Comercial"

### Migrations de referência
- `database/migrations/2026_05_19_100001_add_service_fields_to_companies.php` — campos de contrato em companies
- `database/migrations/2026_05_20_100003_add_parent_company_id_to_companies.php` — padrão de FK nullable nullOnDelete (seguir para mlb_empresas.company_id)
- `database/migrations/2026_05_04_000004_add_tipo_to_mlb_empresas_table.php` — padrão de migration em mlb_empresas

### Sistema de Notificações (reutilizar)
- `.planning/phases/08-funda-o-de-notifica-es/08-CONTEXT.md` — decisões de arquitetura do sistema de notificações
- `app/Http/Controllers` — buscar NotificationController para padrão de dispatch

### Rotas
- `routes/web.php` — estrutura de grupos de rotas com middleware; onde adicionar rotas /comercial/*

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `MlbImplementacaoController@criar` (linhas 176-213): lógica completa de criação de MlbEmpresa POLO + MlbImplementacao + activity log — extrair para método privado ou serviço e reutilizar no fluxo do Comercial
- `MlbEmpresa::whereRaw('LOWER(nome) = LOWER(?)')`: guard case-insensitive de duplicata por nome — reutilizar na validação do Comercial
- `Permissions::all()` + `setor_permissoes`: catálogo de permissões existente — adicionar `comercial.cadastrar_empresa` com label e grupo "Comercial"
- `MlbImplementacao::dadosPadrao()` + `MlbConfiguracao::implementacaoPadroes()`: inicialização padrão de dados de implementação — já funciona, não alterar

### Established Patterns
- **Controllers retornam `Inertia::render()`** — sem API separada; props montados no PHP
- **Permissões verificadas via `abort_unless($user->hasPermission('key'))`** ou `checkAccess()` inline — seguir o padrão de MlbImplementacaoController
- **Criação em `DB::transaction()`** — usar para garantir atomicidade de Company + MlbEmpresa + MlbImplementacao
- **Activity log com `activity()->causedBy()->withProperties()->log()`** — obrigatório em toda criação de entidade do Comercial
- **`back()->with('success', '...')`** para retorno de formulários

### Integration Points
- `AdminController@fechamento` já lista TODAS as `companies` ativas — basta companies existir para aparecer no financeiro
- `AppLayout.jsx`: nav items filtrados por `auth.user` permissions; nova entrada "Comercial" segue o mesmo padrão
- `HandleInertiaRequests`: considerar expor `empresas_pendentes` como shared prop (similar a `sugadores_pendentes`) para badge global
- Sistema de notificações (Phase 8-12): usar `Notification::send()` com `BaseNotification` para notificar líderes de setor

</code_context>

<specifics>
## Specific Ideas

- Comercial deve ser o fluxo **exclusivo** de entrada de novas empresas daqui para frente — setores não devem mais criar empresas diretamente nas suas próprias telas
- Para POLOS: empresa aparece em `/implementacao` **sem nenhuma ação adicional** do time de implementação — o clique em "Nova Implementação" é eliminado para novas empresas
- A migração de dados existentes deve ser **idempotente** (re-rodar não gera duplicatas) — seguir o padrão das migrations `2026_05_07_000011` que têm comentário "Idempotente"
- `companies.status` não substitui `companies.active` — `active` controla se a empresa está ativa no negócio; `status` controla o estágio de onboarding

</specifics>

<deferred>
## Deferred Ideas

- **Tela de progresso de onboarding por empresa** — painel mostrando qual % dos dados de cada setor foi preenchido para uma empresa pendente. Escopo de outra fase.
- **Notificação de rememoração** — reenviar notificação para setores que ainda não completaram dados após X dias. Escopo de outra fase.
- **Histórico de cadastros do Comercial** — log de todas as empresas cadastradas pelo Comercial com data, usuário e setor de destino. Pode ser derivado do `activity_log` existente.

</deferred>

---

*Phase: 13-reestruturacao-cadastro-empresas*
*Context gathered: 2026-05-25*
