---
phase: 72-dashboards-pendencias-dia-cobranca
milestone: v15.0
plan: 72-01
subsystem: nps
tags: [nps, backend, service, pending, dia-cobranca, config, admin, contract-first, phase72]
requirements: [NPS-E-01, NPS-E-04]
requires:
  - Configuracao (key-value store)
  - NpsTemplateService::resolveForCompany (Phase 69-01)
  - NpsSurvey (template_id + month_reference + status)
  - User::isAdmin + User::companies (pivot company_users)
provides:
  - NpsPendingService::diaCobranca (int 1..31 com clamp)
  - NpsPendingService::isPendente (guard temporal + template resolve)
  - NpsPendingService::forCarteira (array shape documentado — carteira scoped)
  - PATCH /nps/configuracao/dia-cobranca (admin only)
  - Widget DiaCobrancaWidget em Nps/Configuracao.jsx
affects:
  - app/Services/Nps/NpsPendingService.php  (novo, 199 LOC)
  - app/Http/Controllers/NpsController.php  (+30 LOC — atualizarDiaCobranca)
  - app/Http/Controllers/NpsTemplateController.php  (+5 LOC — inject dia_cobranca prop)
  - routes/web.php  (+7 LOC — 1 rota PATCH)
  - resources/js/Pages/Nps/Configuracao.jsx  (+78 LOC — DiaCobrancaWidget)
tech-stack:
  added: []
  patterns:
    - "Constructor DI de service em service (NpsTemplateService injetado em NpsPendingService)"
    - "Clamp defensivo max(1, min(31, ...)) na leitura de config int"
    - "Try/catch RuntimeException com Log::warning estruturado (padrão Phase 69/70)"
    - "PATCH em rota admin-only via role:admin middleware group"
    - "useForm hook do Inertia com preserveScroll no widget de config"
key-files:
  created:
    - app/Services/Nps/NpsPendingService.php
  modified:
    - app/Http/Controllers/NpsController.php
    - app/Http/Controllers/NpsTemplateController.php
    - routes/web.php
    - resources/js/Pages/Nps/Configuracao.jsx
decisions:
  - "Config em Configuracao (key-value) e NÃO em .env — admin edita sem redeploy VPS"
  - "Default = 25 (próximo do fim do mês; aumenta janela de resposta antes da cobrança)"
  - "diaCobranca() aplica clamp 1..31 na LEITURA além da validação de ESCRITA (defesa em profundidade contra edição direta no banco)"
  - "isPendente() é public e O(1) por empresa — badges em Portfolio/Show e Companies/Index precisam de check por 1 empresa; forCarteira seria overkill"
  - "Escopo de carteira reusa $user->companies() pattern do projeto (mesmo padrão de SugadorController e NpsController::index)"
  - "Guard temporal só aplica ao MÊS CORRENTE — meses passados sempre são 'elegíveis' (permite relatórios históricos via 2º arg opcional)"
  - "Empresa sem template resolvível (RuntimeException do NpsTemplateService) é silenciosamente ignorada com Log::warning — nunca crashar a lista"
metrics:
  duration: "26min"
  completed_at: "2026-07-08T14:56:00Z"
  tasks_completed: 4
  files_modified: 5
  files_created: 1
---

# Phase 72 Plan 01: Fundação Backend Pendências NPS + Config Dia de Cobrança

Entrega o **NpsPendingService** (fonte única para "esta empresa está pendente no mês?") + config global `nps_dia_cobranca` editável por admin via widget na página `/nps/configuracao`. Cobre 100% dos REQs NPS-E-01 e NPS-E-04. Zero mudança em NpsTemplateService, NpsScoreCalculator ou controllers de templates. Serve de base para os Plans 72-02 (dashboards backend) e 72-03 (frontend badge + widget).

## Artefatos entregues

### 1. `app/Services/Nps/NpsPendingService.php` (novo, 199 LOC)

Namespace `App\Services\Nps`. Constructor DI de `NpsTemplateService`. 3 métodos públicos:

- **`diaCobranca(): int`** — lê `Configuracao::get('nps_dia_cobranca', 25)` + clamp `max(1, min(31, $dia))` (defesa em profundidade).
- **`isPendente(Company, ?Carbon = null): bool`** — guard temporal (só valida mês corrente contra `diaCobranca()`); resolve template via NpsTemplateService (try/catch RuntimeException + Log::warning); checa `NpsSurvey::where(company_id, template_id)->whereDate(month_reference)->where('status', 'completed')->exists()`.
- **`forCarteira(User, ?Carbon = null): array`** — shape documentado `[{company_id, name, template_id, template_nome, month_reference, dias_atraso}]` ordenado por `name` ASC. Admin=todas Company; não-admin=`$user->companies()`. Empresas com RuntimeException do template silenciosamente skipped.

### 2. `NpsController::atualizarDiaCobranca` (+30 LOC)

Método `atualizarDiaCobranca(Request)` com:
- Validação `dia: required|integer|min:1|max:31` + 4 mensagens pt-BR (required, integer, min, max)
- `Configuracao::set('nps_dia_cobranca', (string) $validated['dia'])`
- `back()->with('success', "Dia de cobrança do NPS atualizado para {N}.")`

### 3. `routes/web.php` (+7 LOC)

1 rota PATCH dentro do grupo `role:admin` existente (linhas 115-243), logo após as rotas do Plan 70-04:

```php
Route::patch('/nps/configuracao/dia-cobranca',
    [NpsController::class, 'atualizarDiaCobranca'])
    ->name('nps.configuracao.dia-cobranca.update');
```

### 4. `NpsTemplateController::index` (+5 LOC) + `Configuracao.jsx` (+78 LOC)

- **Backend:** injeta prop `'dia_cobranca' => (int) Configuracao::get('nps_dia_cobranca', 25)` no `Inertia::render`.
- **Frontend:** componente inline `DiaCobrancaWidget` (56 LOC) renderizado ACIMA do grid principal:
  - `useForm({ dia: diaAtual ?? 25 })` + input `type=number min=1 max=31`
  - Botão Salvar via PATCH `route('nps.configuracao.dia-cobranca.update')` com `preserveScroll: true`
  - Design tokens ecf-*: `bg-ecf-card`, `border-white/[0.08]`, `bg-ecf-yellow`, `hover:bg-ecf-yellow/90`
  - Ícone `CalendarClock` do lucide-react
  - Estado de erro visual (`aria-invalid`, borda vermelha, mensagem inline)

## Verificações executadas

| Verificação | Resultado |
|-------------|-----------|
| `php -l app/Services/Nps/NpsPendingService.php` | No syntax errors detected |
| `php -l app/Http/Controllers/NpsController.php` | No syntax errors detected |
| `php -l app/Http/Controllers/NpsTemplateController.php` | No syntax errors detected |
| `php -l routes/web.php` | No syntax errors detected |
| `php artisan route:list --path=nps/configuracao/dia-cobranca -v` | 1 rota PATCH com middleware `role:admin` |
| `php artisan tinker` — DI NpsPendingService | Class resolve: `App\Services\Nps\NpsPendingService` |
| `npm run build` | verde (25.05s) — `Configuracao-DjDd-XXA.js` presente |
| **Baseline suite** (Phase 31 + 33 + 68 + 69 + 70 + 71) | **118 passed** (764 assertions, 72.11s) — zero regressão |

## Contratos honrados

- **REQ NPS-E-01 (config global dia_cobranca):** persistido em `Configuracao::nps_dia_cobranca`, validado 1..31, editável via widget admin em `/nps/configuracao`. Cast + clamp na leitura (`NpsPendingService::diaCobranca`) garantem robustez.
- **REQ NPS-E-04 (NpsPendingService::forCarteira):** contrato documentado (shape do array + escopo admin/não-admin + ordenação por name). Consumível por Plans 72-02 (dashboards backend) e 72-03 (frontend badge).

## Guards implementados

1. **Clamp `diaCobranca()`** — valor corrompido no banco não crasha service; normalizado para range 1..31.
2. **RuntimeException do NpsTemplateService** — capturado em `isPendente()` + `forCarteira()` com `Log::warning` estruturado; empresa silenciosamente ignorada, lista nunca crasha.
3. **Guard temporal em `isPendente()`** — só marca pendente no MÊS CORRENTE após `diaCobranca()`; meses passados ignoram guard (relatórios históricos).
4. **Validação de escrita** — PATCH rejeita 422 com mensagens pt-BR para valores fora de 1..31.
5. **Role admin no PATCH** — rota dentro do grupo `auth+verified+role:admin` — não-admin recebe 403.

## Deviations from Plan

None — plan executado exatamente como escrito. Ajustes menores estéticos:
- Widget usa flex-wrap (`flex-wrap items-center gap-4`) em vez de flex simples — melhora responsividade em telas estreitas.
- Adicionado ícone `CalendarClock` (lucide-react) para consistência visual com outros widgets NPS.
- Border vermelha condicional + `aria-invalid` no input para feedback de erro visual acessível.
- Mensagens de validação incluem 4 chaves (`required`, `integer`, `min`, `max`) em pt-BR em vez de apenas `min`/`max`.

Nenhuma dessas mudanças altera contrato, comportamento ou API — são refinamentos UX/acessibilidade dentro do escopo do widget.

## Fix attempts / Deferred Issues

Nenhum — todos os 4 tasks executaram limpo na primeira tentativa. Sem regressões.

## Ambiente

- **MariaDB local corrompido** (documentado no MEMORY.md 2026-06-25): tinker checks que exigem DB não puderam ser executados (`diaCobranca()` sem stub). DI validado via `dump(get_class(...))` (não hits o banco). **Suite completa de 118 testes rodou verde via SQLite in-memory** — cobertura preservada.

## Notas para consumidores futuros (Plans 72-02 / 72-03)

- **Performance de `forCarteira`:** faz N chamadas de `resolveForCompany` (1-2 queries cada). Para carteiras >200 empresas, otimizar em v15.1 com batch fetch. Para v15.0 aceitável — widgets mostram top N e endpoints admin usam LIMIT.
- **Shape estável:** o array `forCarteira` está documentado no docblock — consumers podem depender das 6 chaves. Adições futuras (ex.: `campanha_id` para segmentação) devem ser aditivas.
- **Reuso em NPS-FUTURE-03 (notificações):** o serviço já tem escopo de carteira built-in; jobs de notificação chamam `->forCarteira($user)` e enviam email para cada `template_nome` retornado. Sem duplicação de regra.

## Self-Check: PASSED

- File `app/Services/Nps/NpsPendingService.php` exists — FOUND (199 LOC)
- File `app/Http/Controllers/NpsController.php` modified (contains `atualizarDiaCobranca`) — FOUND
- File `app/Http/Controllers/NpsTemplateController.php` modified (contains `'dia_cobranca'` prop) — FOUND
- File `routes/web.php` modified (contains `nps.configuracao.dia-cobranca.update`) — FOUND
- File `resources/js/Pages/Nps/Configuracao.jsx` modified (contains `DiaCobrancaWidget`) — FOUND
- All 4 acceptance criteria sections (T1, T2, T3, T4) validated via `grep` + `route:list` + `php -l` + `npm run build` + baseline test suite
- Zero regressão em 118 testes das Phases 31/33/68/69/70/71
