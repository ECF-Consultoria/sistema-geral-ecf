# Phase 1: Diagnóstico Adman - Context

**Gathered:** 2026-05-18
**Status:** Ready for planning

<domain>
## Phase Boundary

Adicionar uma seção de diagnóstico do sync Adman diretamente na página `/dev/desenvolvimento` existente. A seção exibe a lista de empresas com data/hora do último sync, diff (criados/atualizados/ignorados) e payload bruto em accordion inline. Um botão por empresa dispara o sync manual. Escopo: apenas o sync Adman (DEV-01 a DEV-04). Jobs, logs e configurações ficam para fases posteriores.

</domain>

<decisions>
## Implementation Decisions

### Layout
- **D-01:** O diagnóstico Adman deve ser uma **seção inline** na página `/dev/desenvolvimento` existente — não criar sub-página `/dev/adman`. Adicionar abaixo do card da extensão Chrome usando o `DevCard` já existente.
- **D-02:** Detalhes de cada empresa (payload bruto, diff) devem ser exibidos via **accordion inline** — clicar na empresa expande uma linha com os detalhes abaixo, sem mudança de página ou modal.

### Claude's Discretion
- **Armazenamento do log de sync:** Criar nova tabela `adman_sync_logs` (estruturada) ou aproveitar o `activity_log` do Spatie ou buscar da API sob demanda. Claude decide com base no que for mais simples de implementar e manter.
- **Campos do payload exibido:** Exibir campos-chave resumidos (grossBilling, netBilling, TACOS, soldQty, profitMargin) com JSON bruto expansível, ou mostrar apenas o JSON bruto. Claude decide o que for mais útil para debug.
- **Disparo do sync manual:** Enfileirar `AnalyzeCompanySugadoresJob` existente ou criar novo job específico; feedback ao usuário (toast/polling). Claude decide a abordagem mais robusta.
- **Formato de timestamp:** Exibir como "2h atrás" (relativo) ou data/hora absoluta (dd/mm HH:mm). Claude decide.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Código de sync Adman existente
- `app/Services/AdmanService.php` — serviço principal de sync; métodos `syncCompany()`, `syncAll()`, `fetchPerformance()`; campos retornados pela API (grossBilling, netBilling, TACOS, soldQty, profitMargin, investment, etc.)
- `app/Jobs/AnalyzeCompanySugadoresJob.php` — job assíncrono de análise; padrão para disparar jobs por empresa
- `app/Models/Company.php` — model de empresa; campo `adman_account_id` usado para identificar empresas com sync Adman
- `app/Models/AdmanMetric.php` — model de métrica Adman já existente; pode ser reutilizado ou complementado

### Diagnóstico CLI existente (referência de campos)
- `app/Console/Commands/InspecionarAdman.php` — inspeciona campos brutos do Adman para uma empresa; referência do que mostrar no painel
- `app/Console/Commands/DiagnosticSyncVendas.php` — diagnóstico de sync de vendas; referência de campos de diff

### Frontend existente
- `resources/js/Pages/Dev/Desenvolvimento.jsx` — página Dev atual com `DevCard` e `LinkRow` components reutilizáveis; design system ECF (dark theme, ecf-* tokens, cn())
- `resources/js/Layouts/AppLayout.jsx` — layout autenticado; props globais via `HandleInertiaRequests`

### Rotas e controle de acesso
- `routes/web.php` — rota existente `/dev/desenvolvimento` com middleware `role:admin`; padrão para adicionar novas rotas Dev

### Requisitos da fase
- `.planning/REQUIREMENTS.md` — DEV-01, DEV-02, DEV-03, DEV-04

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- `DevCard` component (`Desenvolvimento.jsx`): container card com ícone, título e subtitle — reutilizar para a seção de Sync Adman
- `AdmanService::syncCompany()`: método pronto para executar sync de uma empresa específica — expor via controller/job
- `AdmanMetric` model: já armazena métricas Adman por empresa/data — consultar para exibir último sync
- Middleware `role:admin`: já configurado nas rotas Dev — todas as novas rotas herdam

### Established Patterns
- Controllers retornam `Inertia::render()` com props PHP — não há camada de API REST separada
- Jobs assíncronos com `dispatch()` para operações longas (padrão `AnalyzeCompanySugadoresJob`)
- `spatie/laravel-activitylog` para auditoria — já disponível se precisar registrar disparo manual
- Comentários em pt-BR em todos os arquivos do projeto
- `catch (\Throwable $e)` + `Log::error("[Módulo] mensagem")` para tratamento de erros

### Integration Points
- Novo `DevController` (ou rota closure) para retornar dados de sync Adman via Inertia
- `AdmanMetric` ou nova tabela `adman_sync_logs` como fonte de dados para o painel
- Rota POST para disparar sync manual → job → feedback via Inertia redirect/flash

</code_context>

<specifics>
## Specific Ideas

- A seção deve aparecer **abaixo do card da extensão Chrome** na página `/dev/desenvolvimento`
- Cada empresa na lista: nome, timestamp do último sync, status (OK / Erro), botão "Disparar sync"
- Ao clicar na empresa: accordion expande mostrando diff (criados/atualizados/ignorados) e payload/erro do último sync

</specifics>

<deferred>
## Deferred Ideas

- Sub-página `/dev/adman` com histórico completo e paginação — pode ser v2 se a seção inline ficar lotada
- Filtro de empresas por status (só com erro, só com sync atrasado) — Fase 2 ou v2
- Alertas automáticos quando sync falha — v2 (DEV-ALERT-01 no backlog)

</deferred>

---

*Phase: 1-Diagnóstico Adman*
*Context gathered: 2026-05-18*
