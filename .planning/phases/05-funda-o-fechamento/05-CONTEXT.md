# Phase 5: Fundação Fechamento - Context

**Gathered:** 2026-05-19
**Status:** Ready for planning

<domain>
## Phase Boundary

Preparar a fundação técnica do módulo Fechamento: (1) migration que adiciona `service_type`, `contract_start`, `contract_end` e `additional_service` à tabela `companies`; (2) atualização do model `Company` com fillable, casts e logOnly; (3) renomeação do label sidebar de "Financeiro" para "Fechamento"; (4) reescrita do `Financeiro.jsx` como tela inicial de Fechamento mostrando todas as empresas ativas com badge "Sem integração" para as sem `adman_account_id`, e formulário accordion inline para editar tipo de serviço e datas de contrato por empresa.

Escopo estrito: apenas os dados de fundação (FCH-01, FCH-02, FCH-03, CFG-01). Faturamento, faixas de investimento e total consolidado ficam para as Phases 6 e 7.

</domain>

<decisions>
## Implementation Decisions

### Sidebar e Rota
- **D-01:** Apenas o label do sidebar muda — de "Financeiro" para "Fechamento". A rota (`/administrativo/financeiro`), o `routeName` (`admin.financeiro`) e o arquivo de página (`Admin/Financeiro.jsx`) permanecem sem renomear neste milestone. Nenhuma migration de rota, apenas string em `AppLayout.jsx`.

### Estrutura de Colunas na Migration
- **D-02:** `service_type` implementado como `string` nullable (varchar) com validação PHP via `in:['polo','assessoria','incubadora']` — não usar MySQL ENUM para manter flexibilidade de evolução sem migration de schema.
- **D-03:** `contract_start` e `contract_end` como colunas `date` nullable.
- **D-04:** `additional_service` como `string` nullable (campo reservado, sem lógica neste milestone).

### Model Company
- **D-05:** Adicionar `service_type`, `contract_start`, `contract_end`, `additional_service` ao `$fillable` do model.
- **D-06:** `contract_start` e `contract_end` adicionados ao `$casts` como `'date'` para retornar Carbon automaticamente.
- **D-07:** Adicionar `service_type`, `contract_start`, `contract_end` ao `logOnly` do `getActivitylogOptions()` — auditoria de quem mudou tipo de serviço e datas de contrato.

### Controller
- **D-08:** Adicionar método `fechamento()` ao `AdminController` existente (já thin, 4 renders). Suficiente para esta fase; se a lógica crescer nas Phases 6/7, extrair para `AdminFinanceiroController` naquele momento.
- **D-09:** O método `fechamento()` retorna `Inertia::render('Admin/Financeiro', [...])` com props: `companies` (array com campos de fechamento + `has_adman` flag).

### Lista de Empresas
- **D-10:** Listar apenas empresas com `active = true`. FCH-01 refere-se a empresas cadastradas ativas; inativas não participam do fechamento mensal.
- **D-11:** Ordenação: alfabética por `name`.
- **D-12:** Badge "Sem integração" para empresas onde `adman_account_id` é null — flag `has_adman = (bool)$company->adman_account_id` passada via props.

### Edição Inline
- **D-13:** Formulário de edição via **accordion inline** — clicar no nome da empresa expande uma seção com campos de tipo de serviço (select) e datas de contrato (date inputs). Mesmo padrão estabelecido na Phase 1 (sync Adman accordion). Não usar modal.
- **D-14:** Submissão via `useForm()` do Inertia com `PATCH /administrativo/financeiro/{company}`. Controller valida e salva. Flash de sucesso/erro via `back()->with()`.
- **D-15:** Apenas um accordion aberto por vez (ao abrir uma empresa, fecha a anteriormente aberta).

### Rota de Atualização
- **D-16:** Adicionar rota `PATCH /administrativo/financeiro/{company}` no grupo admin de `routes/web.php`, apontando para `AdminController@updateFechamento`. Validação: `service_type in:polo,assessoria,incubadora|nullable`, `contract_start date|nullable`, `contract_end date|after_or_equal:contract_start|nullable`.

### Claude's Discretion
- Estrutura interna do JSX (sub-componentes locais como `ServiceBadge`, `FechamentoRow`) — Claude decide o que faz sentido isolar.
- Formato de exibição das datas no acordeão (se populadas: "dd/mm/yyyy"; se nulas: "—").
- Ícone do item de sidebar (manter `Banknote` de Lucide ou trocar) — Claude mantém `Banknote` para não criar ruído.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Modelo e banco de dados
- `app/Models/Company.php` — model Company existente; fillable, casts, logOnly, relacionamentos
- `database/migrations/` — padrão de migrations existentes (nome, estrutura, up/down)

### Controller e rotas
- `app/Http/Controllers/AdminController.php` — controller thin alvo; adicionar `fechamento()` e `updateFechamento()`
- `routes/web.php` — grupo de rotas admin (linha ~236: rota existente `/financeiro`); adicionar PATCH aqui

### Frontend existente
- `resources/js/Pages/Admin/Financeiro.jsx` — arquivo-alvo da reescrita (hoje: placeholder "Em desenvolvimento")
- `resources/js/Layouts/AppLayout.jsx` — linha 51: item de nav "Financeiro" → mudar label para "Fechamento"
- `resources/js/Pages/Dev/Desenvolvimento.jsx` — referência do padrão accordion inline e `DevCard` da Phase 1

### Requisitos da fase
- `.planning/REQUIREMENTS.md` — FCH-01, FCH-02, FCH-03, CFG-01 (escopo desta fase)
- `.planning/ROADMAP.md` — Phase 5 Success Criteria (5 critérios verificáveis)

### Design system
- `tailwind.config.js` — tokens `ecf-*` (ecf-bg, ecf-card, ecf-yellow); dark theme
- `resources/js/lib/utils.js` — função `cn()` (clsx + tailwind-merge)

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `AppLayout` (`resources/js/Layouts/AppLayout.jsx`): layout autenticado com sidebar; mudar string "Financeiro" → "Fechamento" na linha 51
- `DevCard` (`Desenvolvimento.jsx`): container card reutilizável com ícone/título/subtitle; referenciar visualmente para consistência
- `AdmanService` / `AdmanMetric`: não usados nesta fase — apenas Company e seus campos novos
- `Company` model: já tem `LogsActivity`, fillable e casts estruturados; extensão direta

### Established Patterns
- Accordion inline: padrão de Phase 1 para expandir detalhes por empresa sem modal ou mudança de página
- `useForm()` do Inertia: padrão de submissão de formulário com estado, erros e flash em todos os módulos
- `back()->with('success', '...')` no controller após salvar: flash message via `HandleInertiaRequests`
- `$request->validate([...])` inline no controller: sem FormRequest separado para validações simples
- `cn()` + Tailwind tokens ECF: toda composição de classes usa esta função

### Integration Points
- `AdminController::fechamento()` — novo método que substitui o render vazio atual
- `AdminController::updateFechamento()` — nova action PATCH por empresa
- `routes/web.php` grupo admin (~linha 236) — adicionar rota PATCH `/financeiro/{company}`
- `AppLayout.jsx` linha 51 — mudar label "Financeiro" → "Fechamento"
- `Company` model — `$fillable`, `$casts`, `logOnly` e `getActivitylogOptions()` precisam de atualização

</code_context>

<specifics>
## Specific Ideas

- Badge "Sem integração" deve ser visualmente distinto (ex: badge amarelo/amber com `ecf-yellow` ou cinza), indicando que a empresa não tem `adman_account_id`.
- O select de tipo de serviço deve exibir: "POLO", "Assessoria", "Incubadora" (labels visíveis) mapeando para `polo`, `assessoria`, `incubadora` (valores no banco).
- Accordion: ao expandir uma empresa, exibir campos já preenchidos (se existirem) carregados diretamente dos props Inertia — sem fetch adicional.
- Phase 5 entrega a tela de Fechamento funcional para FCH-01/02/03 — Phase 6 acrescenta faturamento, Phase 7 acrescenta barras de progresso.

</specifics>

<deferred>
## Deferred Ideas

- Lógica de valor para `additional_service` — campo reservado; Phase 7 ou milestone futuro
- Historico de fechamentos por empresa — v2.1+
- Exportação CSV da lista — v2.1+
- Agrupamento da lista por tipo de serviço — pode ser adicionado na Phase 7 (UI) se conveniente

</deferred>

---

*Phase: 5-Fundação Fechamento*
*Context gathered: 2026-05-19*
