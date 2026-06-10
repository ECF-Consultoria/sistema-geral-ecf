---
phase: 31-nps-mensal-automatizado
plan: 02
subsystem: nps
tags: [mail, command, schedule, controller, nps]
requires:
  - 31-01 (nps_responses 1-5, nps_surveys.month_reference/auto_generated, companies.email_cliente)
provides:
  - App\Mail\NpsMonthlyMail (Mailable Markdown SMTP Gmail)
  - app/Console/Commands/NpsDispararMensal.php (comando idempotente aniversário cadastro)
  - Schedule diário 09:00 BRT para nps:disparar-mensal
  - NpsController::submitResponse (escala 1-5, 3 dimensões)
  - NpsController::respond (payload com tem_analista/estrategista_name/analista_name)
  - NpsController::generate (auto_generated=false em surveys manuais — REQ-31-08)
  - nps_surveys.generated_by agora NULLABLE
affects:
  - Frontend Nps/Respond.jsx (precisa Plan 31-03 — chaves novas no payload, sliders 1-5)
  - NpsController::index() ainda lê colunas legacy — quebra em prod até Plan 31-05
  - Dashboard / Performance / CompanyController (downstream) ainda usam score_consultant/mentor/overall
tech_stack:
  added: []
  patterns:
    - "Mailable Markdown SMTP Gmail reusado da Phase 28 (RelatorioMensalMail)"
    - "Schedule::command()->dailyAt()->timezone()->withoutOverlapping() (padrão Laravel 11+ em routes/console.php)"
    - "Idempotência via whereExists(company_id, month_reference) no comando — re-runs no mesmo dia são seguros"
    - "Clamp de dia via min(day, daysInMonth) para edge case D-03 (dia 29/30/31 em fevereiro etc)"
    - "try/catch por iteração dentro do loop chunkById — uma empresa com falha não derruba o comando inteiro"
key_files:
  created:
    - app/Mail/NpsMonthlyMail.php
    - resources/views/emails/nps/mensal.blade.php
    - app/Console/Commands/NpsDispararMensal.php
    - database/migrations/2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php
    - tests/Feature/Phase31NpsMonthlyMailTest.php
    - tests/Feature/Phase31NpsDispararMensalTest.php
    - tests/Feature/Phase31NpsSubmitTest.php
  modified:
    - routes/console.php
    - app/Http/Controllers/NpsController.php
decisions:
  - "nps_surveys.generated_by virou NULLABLE (migration 2026_06_10_100004) em vez do fallback 'primeiro admin' sugerido pelo plan: arquitetonicamente mais limpo, audit log limpo, zero acoplamento a um usuário específico"
  - "Schedule 09:00 BRT escolhido (igual ao plan) por estar fora do cluster 11:00-12:45 dos jobs existentes (adman:sync 11:00, sync-faturamento 11:30, calculate-goals 11:45, sugadores 12:00, gross billing 12:45)"
  - "Empresa sem estrategista atribuído: comando loga Log::warning e pula. NÃO é silencioso como D-04 porque indica configuração faltante — admin precisa saber"
  - "NpsController::index() permanece lendo colunas legacy com TODO marker explícito pro Plan 31-05 — escopo limpo (Plan 02 entrega backend do disparo; Plan 05 reescreve UI admin)"
metrics:
  duration_minutes: 9
  tasks_completed: 3
  files_created: 7
  files_modified: 2
  commits: 3
  completed_at: "2026-06-10T21:46:48Z"
---

# Phase 31 Plan 02: Backend NPS Mensal — Mailable + Comando + Schedule + Controller (Summary)

**One-liner:** Entrega o backend completo da automação NPS: Mailable Markdown reusando SMTP Gmail Phase 28, comando Artisan idempotente `nps:disparar-mensal` com clamp dia 31 → último dia do mês, schedule diário 09:00 BRT e refactor do `NpsController` para escala 1-5 com 3 dimensões preservando o fluxo manual (REQ-31-08).

## O que foi feito

3 tasks executadas, 7 arquivos criados, 2 modificados, 19 testes Phase 31 verdes. Cliente passa a receber email NPS automático no aniversário do cadastro toda vez que rodar o cron; admin consegue submeter resposta na escala 1-5 (3 dimensões); fluxo manual de gerar link continua funcionando e marcado como `auto_generated=false`.

### Task 1 — `NpsMonthlyMail` + template Blade

- `app/Mail/NpsMonthlyMail.php`: Mailable com 5 props (companyName, estrategistaName, analistaName nullable, linkPublico, mesLabel), reusa SMTP Gmail validado em Phase 28. Subject pt-BR `"[ECF Admin] Pesquisa de satisfação — Junho/2026"`. Sem anexo.
- `resources/views/emails/nps/mensal.blade.php`: HTML no padrão visual da Phase 28 (header amarelo `#ffe600`), saudação, "Quem te atende" (estrategista sempre, analista condicional via `@if($analistaName)` — D-07), CTA destacado pro `/nps/{token}`, disclaimer pt-BR + footer ECF.
- 5 testes cobrindo instânciação, envelope/subject, content/view payload, render do Blade e omissão da label "Analista:" quando o nome é null.

### Task 2 — Comando `nps:disparar-mensal` + migration auxiliar

- Migration `2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php`: torna `nps_surveys.generated_by` NULLABLE com `nullOnDelete`. **Decisão técnica fora do plan** — ver "Deviations" abaixo.
- `app/Console/Commands/NpsDispararMensal.php`:
  - Signature `nps:disparar-mensal {--dry-run}`
  - `handle()` itera `Company::where('active', true)->whereNotNull('email_cliente')->where('email_cliente', '!=', '')->chunkById(50, ...)`
  - Calcula `$diaAlvo = min($empresa->created_at->day, $hoje->daysInMonth)` — clamp D-03
  - Idempotência: `NpsSurvey::where(company_id, month_reference=$mesAtual)->exists()` → pula
  - Resolve estrategista (`$empresa->estrategista()->first()`) — null = Log::warning + pula
  - Analista (`$empresa->consultor()->first()`) — pode ser null (mentoria pura)
  - Cria survey com `auto_generated=true`, `month_reference=YYYY-MM-01`, `expires_at=+30d`, `generated_by=null`, `status='pending'`, token UUID
  - `Mail::to($empresa->email_cliente)->send(new NpsMonthlyMail(...))`
  - `Log::info("[NPS Mensal] enviado para empresa {$id} ({$nome}) email={$email} survey_id={$id}")`
  - try/catch por empresa: falha pontual não derruba o comando
  - Sumário final no stdout + Log::info: counts criados/enviados/elegíveis/idempotentes/sem estrategista
- 7 testes (happy, sem email, dia não bate, idempotência, edge dia 31 → fev, empresa inativa, analista no payload).

### Task 3 — Schedule + NpsController refactor

- `routes/console.php`: bloco adicionado antes do schedule de envio de relatório mensal:
  ```php
  Schedule::command('nps:disparar-mensal')
      ->dailyAt('09:00')
      ->timezone('America/Sao_Paulo')
      ->name('nps-disparar-mensal')
      ->withoutOverlapping();
  ```
- `NpsController::generate()`: validação preservada, adicionado `'auto_generated' => false` explícito (REQ-31-08). `month_reference` fica null por default.
- `NpsController::respond()`: payload Inertia agora expõe:
  ```php
  'survey' => [
      'token'              => ...,
      'company_name'       => ...,
      'estrategista_name'  => $estrategista?->name,
      'analista_name'      => $analista?->name,
      'tem_analista'       => $analista !== null,
  ]
  ```
  Chaves legacy `mentor_name`/`consultant_name` REMOVIDAS. Variável local renomeada de `$consultant` para `$analista` por clareza pt-BR.
- `NpsController::submitResponse()`: validação nova:
  ```php
  $data = $request->validate([
      'respondent_name'    => 'nullable|string|max:255',
      'score_estrategista' => 'required|integer|min:1|max:5',
      'score_analista'     => 'nullable|integer|min:1|max:5',
      'score_empresa'      => 'required|integer|min:1|max:5',
      'comment'            => 'nullable|string|max:2000',
  ]);
  ```
- `NpsController::index()`: deixado **inalterado** com TODO marker explícito pro Plan 31-05. Continua lendo `score_consultant/mentor/overall` — vai retornar SQL error em prod até o Plan 31-05 ser deployado junto. Mantido inalterado para escopo limpo deste plan.
- 7 testes (3 scores válidos, analista nullable, score fora 1-5 → 422, respondent_name ausente, payload com chaves novas, mentoria pura, generate manual).

## Arquivos afetados

### Criados
- `app/Mail/NpsMonthlyMail.php`
- `resources/views/emails/nps/mensal.blade.php`
- `app/Console/Commands/NpsDispararMensal.php`
- `database/migrations/2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php`
- `tests/Feature/Phase31NpsMonthlyMailTest.php` (5 testes)
- `tests/Feature/Phase31NpsDispararMensalTest.php` (7 testes)
- `tests/Feature/Phase31NpsSubmitTest.php` (7 testes)

### Modificados
- `routes/console.php` — bloco Schedule do `nps:disparar-mensal`
- `app/Http/Controllers/NpsController.php` — `submitResponse`/`respond`/`generate` na nova taxonomia

## Commits

| Hash      | Mensagem                                                                              |
| --------- | ------------------------------------------------------------------------------------- |
| `a8d3572` | `feat(31-02): adiciona NpsMonthlyMail (Markdown SMTP Gmail) + template Blade`         |
| `e5af674` | `feat(31-02): adiciona comando nps:disparar-mensal idempotente + clamp dia 31`        |
| `a661438` | `feat(31-02): schedule diario 09:00 BRT + NpsController na escala 1-5`                |

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 2 - Critical functionality] Migration auxiliar tornando `generated_by` NULLABLE em vez do fallback "primeiro admin"**
- **Found during:** Task 2, ao revisar a action item g do plan
- **Issue:** Plan sugeria fallback `User::where('role','admin')->orderBy('id')->first()->id` para satisfazer a FK NOT NULL. Isso (a) acopla o comando a um admin específico que pode ser deletado/desativado, (b) polui o audit log de qualquer dashboard de "Quem gerou" com o admin escolhido a cada disparo mensal, (c) é semanticamente errado — não há humano por trás de surveys automáticas.
- **Fix:** Criada migration `2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php` que torna a coluna NULLABLE com `nullOnDelete`. Comando agora cria surveys com `generated_by=null`. Surveys MANUAIS continuam recebendo `$user->id` normalmente.
- **Files modified:** `database/migrations/2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php` (criado)
- **Commit:** `e5af674`

**2. [Rule 2 - Critical functionality] Comando trata empresa sem estrategista atribuído com Log::warning**
- **Found during:** Task 2 implementação
- **Issue:** Plan não especifica o que fazer se `$empresa->estrategista()->first()` for null. O Mailable exige `estrategistaName` (string não-nullable) — sem ele, o comando crashava.
- **Fix:** Empresa sem estrategista → `Log::warning("[NPS Mensal] empresa {$id} ({$nome}) sem estrategista atribuido, pulando disparo")` + continue. Diferente de D-04 (sem email_cliente é silencioso porque é estado esperado de empresa nova), aqui é estado anômalo que admin precisa saber — empresa ativa+email+aniversário mas sem estrategista é um bug de configuração.
- **Files modified:** `app/Console/Commands/NpsDispararMensal.php`
- **Commit:** `e5af674`

### Out-of-scope deferrals (logged here, not fixed)

**Pre-existing legacy column references em CompanyController e PerformanceController** — confirmado via `grep -rn "score_consultant|score_mentor|score_overall" app/`:

| Arquivo | Linhas | Sites | Plan downstream |
|---------|--------|-------|-----------------|
| `app/Http/Controllers/CompanyController.php` | 309-311 | Ficha 360 da empresa monta payload com colunas dropadas | **Plan 31-04** (já está mexendo em Companies/Index.jsx) |
| `app/Http/Controllers/PerformanceController.php` | 58-59, 264 | Ranking por papel (mentor/consultor) | **Plan 31-04 ou Plan 31-05** |
| `app/Http/Controllers/DashboardController.php` | 363, 395-397, 605, 727 | Widget NPS + Performance card | **Plan 31-05** (D-09) |
| `app/Http/Controllers/NpsController.php::index()` | 36-38 | Listagem admin de surveys + responses | **Plan 31-05** |
| `resources/js/Pages/Companies/Show.jsx` | 377, 848 | Calcula media de NPS na ficha empresa | **Plan 31-04 ou Plan 31-05** |
| `resources/js/Pages/Nps/Index.jsx` | 82-84 | Coluna NpsScore na tabela admin | **Plan 31-05** |
| `resources/js/Pages/Nps/Respond.jsx` | 42-44, 54-56, 88-105 | Form público com sliders 0-10 (escala antiga) | **Plan 31-03** (escopo principal) |

**Estado de prod pós-deploy desta migration + Plan 31-01:** acessos a `/dashboard`, `/companies/{id}`, `/performance`, `/nps` retornarão SQL error `Unknown column 'score_consultant'`. **NÃO FAZER DEPLOY** dos Plans 31-01/31-02 sozinhos — agrupar com Plan 31-03/31-04/31-05.

## Gotchas / Próximos passos

### Para Plan 31-03 (form público Respond.jsx)

- O payload Inertia retornado por `nps.respond` AGORA EXPÕE:
  - `survey.estrategista_name` (string)
  - `survey.analista_name` (string|null)
  - `survey.tem_analista` (bool)
- Frontend deve substituir os sliders 0-10 atuais por sliders/botões 1-5 (3 campos).
- Campo `score_analista` deve aparecer/desaparecer baseado em `tem_analista`.
- Validação da resposta no backend: `score_estrategista|score_empresa` required 1-5, `score_analista` nullable 1-5, `respondent_name` agora nullable, `comment` max 2000.

### Para Plan 31-04 (UI admin /nps mensal)

- A view do admin precisa parar de ler `score_consultant/mentor/overall` (foram dropados no Plan 31-01).
- Médias mensais: query `NpsResponse` joining `nps_surveys` filtrando por `month_reference`.
- Distinguir surveys manuais vs. automáticas via `auto_generated` (bool já está em fillable + casts do model NpsSurvey).
- Cuidar do `CompanyController` (linhas 309-311) e `PerformanceController` (linhas 58-59, 264) — ambos referenciam colunas legacy. Se o escopo de 31-04 não cobrir, declarar dependência explícita do Plan 31-05.

### Para Plan 31-05 (cleanup widget Dashboard + NpsController index)

- Auditar os 7 sites listados na tabela "Out-of-scope deferrals" acima.
- D-09 do CONTEXT sugere mapeamento promotor=5, neutro=4, detrator=1-3 baseado em `score_empresa` — ou simplesmente mostrar "media de score_empresa" + "respostas no mês".

### Deploy

- **Não fazer deploy isolado** desta migration. Aguardar Plans 31-03/31-04/31-05 prontos para subir tudo junto.
- Schedule 09:00 BRT já está registrado — vai começar a rodar no primeiro `schedule:run` após o deploy.
- Primeira semana de prod, conferir `storage/logs/laravel.log` por mensagens `[NPS Mensal]` + revisar se empresas ativas têm `email_cliente` preenchido. Sem o preenchimento, comando passa em silêncio (D-04).

## Threat Flags

Nenhuma. O Mailable usa SMTP Gmail já validado em Phase 28, o comando não expõe endpoint de rede, o controller não muda auth boundaries, e o token UUID dos surveys segue o padrão pré-existente. `respond` continua sendo público (sem auth) — comportamento intencional pré-existente para o cliente final responder.

## Self-Check: PASSED

- ✓ `app/Mail/NpsMonthlyMail.php` FOUND
- ✓ `resources/views/emails/nps/mensal.blade.php` FOUND
- ✓ `app/Console/Commands/NpsDispararMensal.php` FOUND
- ✓ `database/migrations/2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php` FOUND
- ✓ `tests/Feature/Phase31NpsMonthlyMailTest.php` FOUND (5 testes)
- ✓ `tests/Feature/Phase31NpsDispararMensalTest.php` FOUND (7 testes)
- ✓ `tests/Feature/Phase31NpsSubmitTest.php` FOUND (7 testes)
- ✓ `routes/console.php` modificado (Schedule nps-disparar-mensal)
- ✓ `app/Http/Controllers/NpsController.php` modificado (escala 1-5, payload tem_analista, generate auto_generated=false)
- ✓ Commits `a8d3572`, `e5af674`, `a661438` existem em `git log`
- ✓ `php artisan list | grep nps:disparar-mensal` mostra comando registrado
- ✓ `php artisan schedule:list | grep nps:disparar-mensal` mostra `0 9 * * *` (09:00 BRT)
- ✓ `php artisan nps:disparar-mensal --dry-run` executa sem mutar banco (0 elegíveis hoje no DB local — esperado)
- ✓ `php artisan migrate` aplicou a migration nullable sem erro
- ✓ Suite Phase 31 completa: **19/19 testes verdes**
