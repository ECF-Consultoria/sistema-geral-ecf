# Phase 94: NPS Anti-Burlamento — auditoria técnica + serviço de suspeita (backend) - Research

**Researched:** 2026-07-16
**Domain:** Laravel backend — rastreamento de eventos HTTP + serviço de regras de negócio (anti-fraude leve)
**Confidence:** HIGH

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Rastro de abertura (AB-94-1)**
- GET `/nps/{token}` (`NpsController::respond`) registra no survey: `first_opened_at` (só na primeira), `last_opened_at` (sempre), `open_count` (incrementa), IP e user-agent da abertura
- Aberturas múltiplas preservam o primeiro registro — nunca sobrescrever `first_opened_at`

**Rastro de resposta (AB-94-2)**
- Submit (`NpsController::submitResponse`) registra na resposta: `response_ip_address`, `response_user_agent`, `response_duration_seconds` (delta entre criação/geração do survey e o submit)
- Registrar SEMPRE, para todo submit — a coleta é silenciosa e universal

**Trilha de eventos (AB-94-3)**
- Tabela `nps_survey_events`: `id`, `survey_id` (FK), `event_type` (generated | opened | submitted | expired | sent_email | sent_digisac), `ip_address` nullable, `user_agent` nullable, `user_id` nullable, `metadata` json nullable, `created_at`
- Fluxos existentes passam a emitir eventos: geração manual de link, disparo mensal (email → `sent_email`, Digisac → `sent_digisac`), abertura, submit, expiração
- A trilha é auditoria viva — preferida no plano original justamente para facilitar investigações

**NpsSuspicionService (AB-94-4)**
- Serviço central em `app/Services/Nps/` avalia no submit e persiste `is_suspicious` (bool) + `suspicion_reasons` (json, textos em pt-BR) na resposta
- Regra 1 — IP interno ECF: IP da resposta ∈ `ECF_INTERNAL_IPS` (lista) ou `ECF_INTERNAL_CIDRS` (redes) → suspeita. Motivo: "Resposta enviada a partir da rede interna da ECF."
- Regra 2 — resposta rápida: `generated_at → responded_at` ≤ janela configurável (default 60s) → suspeita. Motivo: "Resposta enviada em menos de 1 minuto após geração do link." (texto deve refletir a janela configurada)
- Regra 3 — combinação IP interno + rápida → severidade maior. Motivo: "Link gerado e respondido rapidamente a partir da rede interna."
- Regra 4 — sessão autenticada: abertura/resposta com usuário interno logado → **marca como suspeita** (motivo: "Resposta realizada em sessão autenticada de usuário interno."). NÃO bloquear nesta fase — bloqueio é Fase 96
- Config em `.env`: `ECF_INTERNAL_IPS`, `ECF_INTERNAL_CIDRS`, janela em segundos — expostos via arquivo de config (ex.: `config/nps.php` ou seção em config existente do NPS)

**Retrocompatibilidade (AB-94-5)**
- Todos os campos novos nullable; nenhum backfill obrigatório
- Surveys/respostas legadas sem rastro continuam funcionando em todas as telas e agregações
- Migration deve seguir as armadilhas conhecidas do projeto: branch SQLite para enum/CHECK se aplicável, `->nullable()` antes de `nullOnDelete`, idempotência

### Claude's Discretion
- Onde exatamente guardar os campos de abertura (colunas no `nps_surveys` vs. derivar de `nps_survey_events`) — decidir no planejamento considerando custo de query; o plano original sugere colunas agregadas (`first_opened_at`, `last_opened_at`, `open_count`) + tabela de eventos
- Nome/estrutura exata do config (novo `config/nps.php` vs. chave em config existente)
- Detecção de severidade: representação de "severidade maior" dentro de `suspicion_reasons` (ex.: campo `severity` no json)
- Como capturar IP atrás de proxy (X-Forwarded-For / trusted proxies) — seguir o setup real do VPS

### Deferred Ideas (OUT OF SCOPE)
- UI de confiança admin-only (badge/filtros/auditoria) → Fase 95
- Bloqueio de resposta em sessão interna, IPs pela UI, invalidação manual → Fase 96
- Generalização do módulo Digisac (tabela polimórfica `digisac_messages`) → backlog, quando houver segundo consumidor
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Descrição | Suporte da pesquisa |
|----|-----------|----------------------|
| AB-94-1 | Rastro de abertura: `first_opened_at`/`last_opened_at`/`open_count`/IP/UA no survey, GET `/nps/{token}` | §Architecture Patterns "Ponto de inserção 1"; código exato de `NpsController::respond()` mapeado; snippet de captura fornecido |
| AB-94-2 | Rastro de resposta: `response_ip_address`/`response_user_agent`/`response_duration_seconds` no submit | §Architecture Patterns "Ponto de inserção 2"; dual-path v15/legacy mapeado com helper compartilhado sugerido |
| AB-94-3 | Trilha `nps_survey_events` com 6 tipos de evento em todos os emissores | §Architecture Patterns "Emissores de eventos"; todos os call-sites de `NpsSurvey::create()` e envio localizados (2 no total) |
| AB-94-4 | `NpsSuspicionService` com 4 regras + config `.env` | §Don't Hand-Roll (Symfony `IpUtils`); §Code Examples (service completo); §Common Pitfalls (proxy confiável) |
| AB-94-5 | Retrocompatibilidade — campos nullable, sem backfill | §Schema real hoje; §Common Pitfalls (enum+SQLite, nullable antes de nullOnDelete, idempotência) — todos os padrões já usados no módulo NPS |
</phase_requirements>

## Summary

Esta fase é 100% backend, sem dependências novas — tudo que ela precisa (captura de IP/UA, matching CIDR, JSON columns, transações) já está disponível no stack atual (Laravel 12 + Symfony HTTP Foundation + MySQL/MariaDB em prod + SQLite em testes). O trabalho é de **instrumentação cirúrgica** de 4 pontos de código já existentes e conhecidos: `NpsController::respond()` (GET), `NpsController::submitResponse()` → `submitResponseV15()`/`submitResponseLegacy()` (dual-path, ambos precisam do mesmo tratamento), `NpsController::generate()` (link manual) e `NpsDispararMensal::handle()` (disparo mensal, único loop que cria surveys automáticas e dispara email/Digisac).

A descoberta mais importante da pesquisa é uma lacuna de infraestrutura: **o projeto não tem `TrustProxies` configurado** (`bootstrap/app.php` não registra trusted proxies/hosts). Isso significa que `$request->ip()` retorna o IP que o PHP-FPM enxerga na camada de rede — se o VPS tiver qualquer proxy reverso na frente (Nginx→Apache, ou CDN), o IP capturado pode não ser o IP real do cliente, quebrando silenciosamente a Regra 1 (IP interno ECF) do `NpsSuspicionService`. Isso está documentado como pitfall crítico e como pergunta em aberto que precisa de verificação manual no VPS antes do deploy.

Para o matching IP/CIDR (Regra 1), o projeto **não precisa de nenhuma lib nova**: `Symfony\Component\HttpFoundation\IpUtils::checkIp()` já vem transitivamente via `laravel/framework` (confirmado em `composer.lock`) e resolve IPv4 unicast + CIDR + IPv6 numa única chamada — não hand-rolar `ip2long`/bitmask.

Todas as migrations seguem os 3 padrões já estabelecidos no módulo NPS (Phase 68/79): `Schema::hasTable`/`Schema::hasColumn` para idempotência, `nullable()` sempre antes de `nullOnDelete()` (evita erro 1830 do MariaDB em prod, que o SQLite dos testes não pega), e `enum` simples é seguro em `Schema::create` (o pitfall de enum+SQLite só se manifesta em **ALTER** de coluna enum existente — não é o caso aqui, pois `nps_survey_events.event_type` nasce junto com a tabela nova).

**Primary recommendation:** Adicionar 5 colunas nullable em `nps_surveys`, 5 colunas nullable em `nps_responses`, criar a tabela `nps_survey_events`, criar `NpsSuspicionService` usando `Symfony\Component\HttpFoundation\IpUtils`, e instrumentar os 4 pontos de código mapeados abaixo — nesta ordem de wave: (1) schema, (2) captura de abertura, (3) `NpsSuspicionService` + captura de resposta (dual-path), (4) emissão de eventos nos 4 emissores + testes.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Captura de IP/UA na abertura do link | API / Backend (`NpsController::respond`) | Database (`nps_surveys` colunas) | Rota pública sem UI própria nesta fase; dado nasce e persiste no controller |
| Captura de IP/UA/duração na resposta | API / Backend (`NpsController::submitResponse*`) | Database (`nps_responses` colunas) | Mesma rota pública; dual-path v15/legacy precisa do mesmo tratamento |
| Trilha de eventos | API / Backend (múltiplos call-sites) + Console Command | Database (`nps_survey_events`) | Console Command (`NpsDispararMensal`) e Controller são os únicos emissores — sem camada de serviço dedicada necessária para o insert simples |
| Avaliação de suspeita | API / Backend — novo `NpsSuspicionService` | Config (`config/nps.php` + `.env`) | Regra de negócio pura, sem I/O externo — service isolado testável sem HTTP |
| Config de IPs/janela internos | Config (`.env` + `config/nps.php`) | — | Fase 94 é hardcoded `.env`; UI de configuração é explicitamente Fase 96 (fora de escopo) |

## Standard Stack

### Core

Nenhuma dependência nova. O trabalho usa exclusivamente o que já está no `composer.json`/`composer.lock`.

| Componente | Já disponível | Papel nesta fase |
|---|---|---|
| `laravel/framework ^12.0` | Sim | `Illuminate\Http\Request::ip()`/`userAgent()`, migrations, Eloquent casts (`array`/`boolean`/`datetime`) |
| `symfony/http-foundation` (transitivo, `^7.2` via `laravel/framework`) [VERIFIED: composer.lock] | Sim | `Symfony\Component\HttpFoundation\IpUtils::checkIp()` — matching IP/CIDR IPv4+IPv6 sem lib nova |
| `spatie/laravel-activitylog ^4.9` | Sim | Não usado nesta fase (eventos técnicos vivem em `nps_survey_events`, não em `activity_log` — trilha de alto volume não deve poluir o log de auditoria humana) |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| `Symfony\Component\HttpFoundation\IpUtils::checkIp()` | `ip2long()` manual + bitmask de máscara | Reinventa parsing de CIDR, não cobre IPv6, mais superfície de bug para um problema já resolvido pelo framework — não recomendado |
| Colunas agregadas em `nps_surveys`/`nps_responses` | Derivar tudo de `nps_survey_events` via query | CONTEXT já resolveu: manter as duas coisas — colunas agregadas para leitura O(1) nos dashboards da Fase 95, tabela de eventos para auditoria granular |

**Installation:** Nenhuma — zero pacotes novos.

**Version verification:** `symfony/http-foundation` confirmado em `composer.lock` (`^7.2.0` via `laravel/framework`, entradas adicionais `^6.4\|^7.0\|^8.0` de outras deps) — [VERIFIED: composer.lock local].

## Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote Composer ou npm novo. Toda a funcionalidade usa classes já presentes no vendor/ atual (`Illuminate\Http\Request`, `Symfony\Component\HttpFoundation\IpUtils`). Nenhuma linha do `composer.json`/`package.json` precisa mudar.

## Architecture Patterns

### System Architecture Diagram

```text
Cliente (navegador, sem login)
   │
   ├─ GET /nps/{token} ─────────────────────────► NpsController::respond()
   │                                                  │
   │                                                  ├─ [NOVO] captura open-trail
   │                                                  │   (first_opened_at/last_opened_at/
   │                                                  │    open_count/open_ip/open_ua)
   │                                                  │   → UPDATE nps_surveys
   │                                                  │
   │                                                  ├─ [NOVO] emite evento 'opened'
   │                                                  │   → INSERT nps_survey_events
   │                                                  │
   │                                                  ├─ if completed  → AlreadyCompleted
   │                                                  ├─ if expired    → marca status=expired
   │                                                  │                   [NOVO] emite 'expired'
   │                                                  │                   → Expired
   │                                                  └─ else           → Respond.jsx (form)
   │
   └─ POST /nps/{token} ────────────────────────► NpsController::submitResponse()
                                                       │
                                                       ├─ [NOVO] captura response-trail
                                                       │   (ip, user-agent, duração =
                                                       │    now() - survey.created_at)
                                                       │
                                                       ├─ [NOVO] NpsSuspicionService::evaluate()
                                                       │   ├─ Regra 1: IP em ECF_INTERNAL_IPS/CIDRS?
                                                       │   ├─ Regra 2: duração <= janela config?
                                                       │   ├─ Regra 3: 1+2 juntas → severidade maior
                                                       │   └─ Regra 4: Auth::check() (sessão logada)?
                                                       │
                                                       ├─ template_id != null → submitResponseV15()
                                                       │     (grava NpsResponse + is_suspicious/
                                                       │      suspicion_reasons + nps_response_answers
                                                       │      + NpsSnapshotService — DENTRO da mesma
                                                       │      transação já existente)
                                                       │
                                                       ├─ template_id == null → submitResponseLegacy()
                                                       │     (mesmo tratamento, fluxo legado)
                                                       │
                                                       └─ [NOVO] emite evento 'submitted'
                                                           → INSERT nps_survey_events

Admin (autenticado) ─ POST /nps/generate ───────► NpsController::generate()
                                                       │
                                                       ├─ NpsSurvey::create([...])   (já existe)
                                                       └─ [NOVO] emite evento 'generated'
                                                           → INSERT nps_survey_events

Scheduler (09:00 BRT) ─ nps:disparar-mensal ────► NpsDispararMensal::handle()
                                                       │  (loop por empresa × modelo aplicável)
                                                       ├─ NpsSurvey::create([...])   (já existe)
                                                       ├─ [NOVO] emite evento 'generated'
                                                       ├─ Mail::send() sucesso
                                                       │   └─ [NOVO] emite evento 'sent_email'
                                                       └─ NpsDigisacDispatchService::send()
                                                           status=='enviado'
                                                           └─ [NOVO] emite evento 'sent_digisac'
```

### Ponto de inserção 1 — Abertura (`NpsController::respond()`, atualmente linha ~519)

**Estado atual mapeado (arquivo lido linha a linha):**
- Assinatura atual: `public function respond(string $token)` — **sem `Request $request` injetado**. Precisa adicionar o parâmetro (Laravel resolve por type-hint, independente de ordem com o `{token}` de rota).
- Fluxo: `NpsSurvey::with(['company','generatedBy','template.questions.options'])->where('token',$token)->firstOrFail()` → `if (status === 'completed') return AlreadyCompleted` → `if (isExpired()) { update(status=expired); return Expired }` → monta payload e `Inertia::render('Nps/Respond', ...)`.
- **Onde inserir a captura:** logo após o `firstOrFail()`, **antes** dos dois early-returns (`completed`/`expired`). Justificativa: AB-94-1 diz "todo GET" registra abertura — inclusive re-aberturas de link já respondido/expirado são sinal técnico relevante (útil para a Fase 95 mostrar "aberto 5x, respondido 1x, 2 aberturas depois de expirar").
- Não quebra os ramos existentes: a captura é um `update()`/insert isolado antes dos `if`s, os `return`s continuam intocados.

**Snippet de referência (padrão para o plano, não é código final):**
```php
public function respond(Request $request, string $token)
{
    $survey = NpsSurvey::with([...])->where('token', $token)->firstOrFail();

    // AB-94-1 — rastro de abertura, roda em TODO GET (mesmo status completed/expired)
    $survey->update([
        'first_opened_at' => $survey->first_opened_at ?? now(),
        'last_opened_at'  => now(),
        'open_count'      => $survey->open_count + 1,
        'open_ip_address' => $request->ip(),
        'open_user_agent' => $request->userAgent(),
    ]);

    NpsSurveyEvent::create([
        'survey_id'  => $survey->id,
        'event_type' => NpsSurveyEvent::TYPE_OPENED,
        'ip_address' => $request->ip(),
        'user_agent' => $request->userAgent(),
        'user_id'    => auth()->id(), // nullable — captura sessão interna se houver (útil p/ Regra 4 e Fase 95)
        'metadata'   => ['first_open' => $survey->open_count === 1],
    ]);

    if ($survey->status === 'completed') { ... } // inalterado
    if ($survey->isExpired()) { ...; /* [NOVO] emite evento 'expired' aqui */ }
    // ... resto inalterado
}
```

### Ponto de inserção 2 — Resposta (`NpsController::submitResponse()`, ~669, dual-path)

**Estado atual mapeado:**
- `submitResponse(Request $request, string $token)` já recebe `Request` — não precisa mudar assinatura.
- Discrimina por `$survey->template_id !== null` → `submitResponseV15()` ou `submitResponseLegacy()`. **Ambos** precisam do mesmo tratamento de captura + suspeita — a lógica deve ser extraída para um helper privado compartilhado, chamado nos dois métodos, para não duplicar a lógica de negócio 2x (regra do projeto de reuso, e risco real de "corrigiu um path e esqueceu o outro").
- `submitResponseV15()`: já roda dentro de `DB::transaction()` (linha ~747-821) e já chama `app(NpsSnapshotService::class)->registrar($response)` **depois** de gravar as answers e **antes** do `$survey->update(['status'=>'completed', ...])`. `NpsSuspicionService` deve seguir o mesmo padrão: **stateless, resolvido via `app()`, chamado dentro da mesma transação**, sem abrir transação própria (mesmo motivo do `NpsSnapshotService`: se o guard `QueryException 23000` do dedup estourar, tudo deve reverter junto).
- `submitResponseLegacy()`: roda dentro de `DB::transaction()` (linha ~892-927), sem guard de duplicata (o legado não tem dedup).
- **Onde calcular a duração:** `response_duration_seconds = now()->diffInSeconds($survey->created_at)` — CONTEXT já resolveu usar `created_at` do survey como "generated_at" (survey manual e automática ambas têm `created_at` populado no `NpsSurvey::create()`; não é necessário adicionar coluna `generated_at` separada).
- **Onde persistir `is_suspicious`/`suspicion_reasons`:** incluir diretamente no array do `NpsResponse::create([...])` em AMBOS os métodos (v15 e legacy) — evita um `UPDATE` extra pós-insert.

**Snippet de referência (helper privado compartilhado):**
```php
/**
 * AB-94-2 + AB-94-4 — captura o rastro técnico da resposta e avalia suspeita.
 * Chamado por submitResponseV15() e submitResponseLegacy() — NÃO duplicar a
 * lógica nos dois métodos.
 */
private function capturarRastroEAvaliarSuspeita(Request $request, NpsSurvey $survey): array
{
    $duracao = now()->diffInSeconds($survey->created_at);

    $veredito = app(\App\Services\Nps\NpsSuspicionService::class)->evaluate(
        ip: $request->ip(),
        durationSeconds: $duracao,
        isAuthenticatedSession: auth()->check(),
    );

    return [
        'response_ip_address'       => $request->ip(),
        'response_user_agent'       => $request->userAgent(),
        'response_duration_seconds' => $duracao,
        'is_suspicious'             => $veredito['is_suspicious'],
        'suspicion_reasons'         => $veredito['reasons'], // array — cast 'array' no model serializa pra JSON
    ];
}
```
Uso: `NpsResponse::create([...campos existentes..., ...$this->capturarRastroEAvaliarSuspeita($request, $survey)])` em `submitResponseV15()` e `submitResponseLegacy()`.

### Emissores de eventos — mapeamento completo (AB-94-3)

Busca confirmou **exatamente 2 call-sites** de `NpsSurvey::create()` no codebase (fora de testes):

| Evento | Call-site exato | Momento de emissão |
|---|---|---|
| `generated` | `NpsController::generate()` linha ~488 (link manual) | Logo após `NpsSurvey::create([...])` |
| `generated` | `NpsDispararMensal::handle()` linha ~250 (loop `foreach ($modelosAplicaveis as $modelo)`) | Logo após `NpsSurvey::create([...])`, **dentro do loop de modelo** — 1 evento por survey criada (empresa com 2 modelos aplicáveis gera 2 surveys = 2 eventos `generated`) |
| `sent_email` | `NpsDispararMensal::handle()` linha ~308-324, dentro do `try` de sucesso do `Mail::to(...)->send(...)` | Só no branch de sucesso (o branch de falha, linha ~325-336, já grava `NpsEmailEnvio.status=falha` separadamente — não precisa de evento `sent_email` duplicando isso) |
| `sent_digisac` | `NpsDispararMensal::handle()` linha ~342-365, após `$this->digisacDispatch->send(...)` retornar `$envio` | Só quando `$envio->status === NpsDigisacEnvio::STATUS_ENVIADO` (não emitir em `falha`/`skipped` — não há tipo de evento para isso no enum travado por CONTEXT) |
| `opened` | `NpsController::respond()` (ver Ponto de inserção 1) | Todo GET, mesmo em survey já completed/expired |
| `submitted` | `NpsController::submitResponse()` — melhor local: **depois** que `submitResponseV15()`/`submitResponseLegacy()` retornam com sucesso (ou dentro da própria transação, ver Open Question abaixo) | Todo POST bem-sucedido (não emitir em 422 de validação nem no guard 23000 de duplicata) |
| `expired` | `NpsController::respond()`, dentro do `if ($survey->isExpired())`, junto do `$survey->update(['status' => 'expired'])` | **Único lugar do código onde o status realmente transiciona para `expired`** — confirmado por grep: não existe job/command de expiração agendado, a expiração é 100% lazy (calculada em `NpsSurvey::isExpired()` e persistida só quando alguém abre o link depois do vencimento). `submitResponse()` também chama `isExpired()` (linha ~673) mas **não** atualiza o status nesse branch — só retorna 422 — então não deve emitir `expired` ali (o status pode já ter sido marcado antes, ou pode nunca ser marcado se ninguém jamais abrir o link vencido) |

### Recommended Project Structure

Nenhuma pasta nova — segue exatamente a convenção já usada por `NpsPendingService`/`NpsSnapshotService`/`NpsTemplateService` e `NpsScoreCalculator`:

```
app/
├── Models/
│   ├── NpsSurvey.php              # + fillable/casts para colunas novas
│   ├── NpsResponse.php            # + fillable/casts para colunas novas
│   └── NpsSurveyEvent.php         # NOVO — model simples, sem trait especial
├── Services/Nps/
│   └── NpsSuspicionService.php    # NOVO — mesmo padrão stateless dos outros NpsXService
├── Http/Controllers/
│   └── NpsController.php          # instrumentado (respond/submitResponse/generate)
├── Console/Commands/
│   └── NpsDispararMensal.php      # instrumentado (2 pontos: generated + sent_email/sent_digisac)
config/
└── nps.php                        # NOVO — ECF_INTERNAL_IPS/CIDRS + janela de suspeita
database/
├── migrations/
│   ├── 2026_07_16_XXXXXX_add_open_trail_to_nps_surveys_table.php
│   ├── 2026_07_16_XXXXXX_add_response_trail_and_suspicion_to_nps_responses_table.php
│   └── 2026_07_16_XXXXXX_create_nps_survey_events_table.php
└── factories/
    └── NpsSurveyEventFactory.php  # NOVO
tests/Feature/Phase94/
├── NpsOpenTrailTest.php
├── NpsResponseTrailAndSuspicionTest.php
├── NpsSurveyEventsTest.php
├── NpsSuspicionServiceTest.php    # unit-like, mas Feature por conveniência (usa config real)
└── NpsAntiBurlamentoBackwardCompatTest.php
```

### Pattern 1: Service stateless resolvido via container

**O que:** Todos os `App\Services\Nps\*` existentes (`NpsPendingService`, `NpsSnapshotService`, `NpsScoreCalculator`, `NpsTemplateService`) são classes sem estado, instanciadas via `app(Classe::class)` ou injeção de construtor — nunca `new Classe()` direto em código de produção.

**Quando usar:** `NpsSuspicionService` deve seguir o mesmo padrão — sem propriedades de instância além de dependências injetadas (nenhuma, na verdade: config é lido via `config()` global, não precisa de injeção).

**Exemplo:**
```php
// Fonte: app/Services/Nps/NpsSnapshotService.php (padrão já em produção)
app(\App\Services\Nps\NpsSnapshotService::class)->registrar($response);

// Mesmo padrão para o novo service:
app(\App\Services\Nps\NpsSuspicionService::class)->evaluate(...);
```

### Pattern 2: Migration idempotente cross-driver (MySQL prod + SQLite testes)

**O que:** Toda migration do módulo NPS desde a Phase 68 usa `Schema::hasTable()`/`Schema::hasColumn()` como guard de idempotência, e distingue comportamento MySQL vs. SQLite apenas quando estritamente necessário (índices parciais, colunas geradas). Para colunas simples nullable, `Schema::table()` funciona igual nos dois drivers — não precisa de branch.

**Exemplo (fonte: `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php`):**
```php
public function up(): void
{
    if (! Schema::hasTable('nps_survey_events')) {
        Schema::create('nps_survey_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('survey_id')->constrained('nps_surveys')->cascadeOnDelete();
            $table->enum('event_type', [
                'generated', 'opened', 'submitted', 'expired', 'sent_email', 'sent_digisac',
            ]);
            $table->string('ip_address', 45)->nullable();   // 45 = tamanho max de literal IPv6
            $table->text('user_agent')->nullable();          // text evita truncation em strict mode
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['survey_id', 'event_type'], 'idx_nps_survey_events_survey_type');
            $table->index('created_at', 'idx_nps_survey_events_created');
        });
    }
}

public function down(): void
{
    Schema::dropIfExists('nps_survey_events');
}
```

**Colunas novas em `nps_surveys` (nullable, sem afetar o enum `status` existente):**
```php
Schema::table('nps_surveys', function (Blueprint $table) {
    if (! Schema::hasColumn('nps_surveys', 'first_opened_at')) {
        $table->timestamp('first_opened_at')->nullable()->after('completed_at');
        $table->timestamp('last_opened_at')->nullable()->after('first_opened_at');
        $table->unsignedInteger('open_count')->default(0)->after('last_opened_at');
        $table->string('open_ip_address', 45)->nullable()->after('open_count');
        $table->text('open_user_agent')->nullable()->after('open_ip_address');
    }
});
```

**Colunas novas em `nps_responses` (nullable):**
```php
Schema::table('nps_responses', function (Blueprint $table) {
    if (! Schema::hasColumn('nps_responses', 'response_ip_address')) {
        $table->string('response_ip_address', 45)->nullable()->after('comment');
        $table->text('response_user_agent')->nullable()->after('response_ip_address');
        $table->unsignedInteger('response_duration_seconds')->nullable()->after('response_user_agent');
        $table->boolean('is_suspicious')->default(false)->after('response_duration_seconds');
        $table->json('suspicion_reasons')->nullable()->after('is_suspicious');
    }
});
```

**Por que `enum` simples é seguro aqui (sem branch SQLite):** o pitfall documentado no projeto (`project_enum_setor_sqlite_check.md`) é sobre **alterar** um enum já existente (`->change()` em coluna com CHECK constraint no SQLite quebra). `nps_survey_events.event_type` nasce **dentro de `Schema::create`** — SQLite aceita `enum` (via CHECK) normalmente em criação de tabela nova, o mesmo padrão já usado em `nps_digisac_envios.status` e `nps_response_scores.dimensao` (ambos criados sem branch, funcionando nos 2 drivers hoje). Só haveria risco se uma migration FUTURA precisasse adicionar um 7º valor ao enum via `->change()`.

**Por que `nullable()` antes de `nullOnDelete()` no `user_id`:** confirma o padrão já documentado no projeto (`project_mysql_nullondelete_nullable.md`) — sem `->nullable()`, o MariaDB de produção rejeita com erro 1830 ao tentar `ON DELETE SET NULL` numa coluna NOT NULL; o SQLite dos testes não pega esse erro, então é fácil passar batido localmente e quebrar só no VPS.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Match de IP contra lista de IPs + CIDRs (IPv4/IPv6) | `ip2long()` + cálculo de máscara manual | `Symfony\Component\HttpFoundation\IpUtils::checkIp($ip, $ranges)` | Já resolvido pelo framework (transitivo via `laravel/framework`), cobre IPv4 unicast, CIDR (`10.0.0.0/8`) e IPv6 numa única chamada; hand-rolar reintroduz bugs de edge-case (ex.: `/0`, IPv6 comprimido) sem ganho nenhum |
| Captura de IP "real" atrás de proxy | Parsear `X-Forwarded-For` manualmente no controller | `bootstrap/app.php` → `$middleware->trustProxies(...)` (Laravel 12 fluent API) — **mas só se o VPS realmente tiver proxy reverso na frente do PHP** | Ver Common Pitfalls — este projeto NÃO tem trusted proxies configurado hoje; decisão de adicionar (ou não) depende de confirmar a topologia real do VPS antes do deploy |

**Key insight:** Esta fase não introduz nenhum problema "clássico" que justifique lib externa — é 100% orquestração de dados já disponíveis no `Request` do Laravel e regras de negócio simples. O único risco de "reinventar a roda" seria no parsing de IP/CIDR, já coberto pelo Symfony.

## Common Pitfalls

### Pitfall 1: `$request->ip()` pode não ser o IP real do cliente (proxy não confiável)

**What goes wrong:** Se o VPS roteia requisições através de um proxy reverso (Nginx na frente de Apache, CDN, load balancer) e o Laravel não tem `trustProxies` configurado, `$request->ip()` retorna o IP da última "hop" de rede que o PHP-FPM enxerga — que pode ser sempre `127.0.0.1` ou o IP interno do proxy, **nunca** o IP público do cliente. Isso quebraria silenciosamente a Regra 1 (nenhuma resposta jamais seria detectada como vinda de dentro da ECF, mesmo quando de fato está) OU, pior, faria TODAS as respostas parecerem vir do mesmo IP "interno" (falsos positivos em massa).

**Why it happens:** `bootstrap/app.php` deste projeto **não chama** `$middleware->trustProxies(...)` nem existe qualquer `TrustProxies` customizado — confirmado por grep (`trustedproxy|TrustProxies|trustHosts|trustProxies` → zero resultados em `app/`, `bootstrap/`, `config/`). Sem essa config, o Symfony `Request` (base do Laravel) ignora `X-Forwarded-For`/`X-Forwarded-Proto` por padrão e usa `REMOTE_ADDR` bruto.

**How to avoid:** ANTES de codar a Regra 1 do `NpsSuspicionService`, confirmar no VPS real (`https://admin.ecfconsultoria.com.br`) qual é a topologia: Apache recebe conexão direta da internet (então `$request->ip()` já é confiável, nenhuma mudança necessária) OU existe proxy/CDN na frente (então é preciso configurar `trustProxies` com o IP do proxy antes que a Regra 1 funcione). Esta fase (94) é "backend only" e não expõe nada na UI — é seguro implementar a Regra 1 mesmo com essa incerteza (ela simplesmente não vai gerar suspeitas corretas até a topologia ser confirmada), mas o plano deve incluir uma tarefa de verificação manual (`checkpoint:human-verify` ou teste manual em prod) antes de considerar a Regra 1 "confiável" — não é um bloqueador para codar a fase, mas é um risco real de "feature que parece funcionar nos testes locais (SQLite/php artisan serve, onde `$request->ip()` é sempre `127.0.0.1`) e não funciona em prod".

**Warning signs:** Em testes Feature locais, `$request->ip()` retorna `127.0.0.1` sempre — então o teste da Regra 1 precisa fazer `$this->withServerVariables(['REMOTE_ADDR' => '201.x.x.x'])` (ou equivalente) para simular um IP real, não pode confiar no ambiente de teste puro.

### Pitfall 2: Duplicar a lógica de suspeita entre `submitResponseV15()` e `submitResponseLegacy()`

**What goes wrong:** `NpsController::submitResponse()` já bifurca em 2 métodos privados que reimplementam fluxos parecidos, mas com nuances diferentes (v15 tem guard de dedup 23000, legacy não). Se a captura de rastro/suspeita for colada em cada método separadamente, é fácil corrigir um bug num path e esquecer o outro — já aconteceu no projeto antes (comentário na linha 437 do `NpsController` menciona um bug real de produção causado por lógica divergente entre paths).

**Why it happens:** O controller tem 930+ linhas e os dois métodos privados estão fisicamente distantes um do outro no arquivo.

**How to avoid:** Extrair a captura de rastro + chamada ao `NpsSuspicionService` para 1 único método privado (`capturarRastroEAvaliarSuspeita`, ver snippet acima) chamado nos dois lugares — nunca duplicar o corpo da lógica.

**Warning signs:** Se o diff do plano mostrar o mesmo bloco de código (mais que 3 linhas) colado duas vezes no controller, é sinal de que o helper não foi extraído.

### Pitfall 3: Emitir evento `submitted` fora da transação, causando inconsistência em caso de rollback

**What goes wrong:** `submitResponseV15()` roda dentro de `DB::transaction()` e pode reverter tudo se o guard `QueryException 23000` (dedup mensal) disparar. Se o evento `submitted` for inserido **fora** dessa transação (ex.: depois que `submitResponse()` recebe o retorno de `submitResponseV15()`), e o retorno for `Inertia::render('Nps/AlreadyCompleted')` por causa do 23000, o evento `submitted` teria sido emitido para uma resposta que na verdade **não foi persistida** (a transação reverteu).

**Why it happens:** O guard 23000 é capturado num `try/catch` que envolve a transação inteira — é fácil pensar "emito o evento depois que a função retorna" sem perceber que o retorno pode ser de uma falha silenciosa (sucesso HTTP 200, mas nenhum dado gravado).

**How to avoid:** Emitir o evento `submitted` **dentro** da mesma `DB::transaction()`, logo antes do `$survey->update(['status' => 'completed', ...])` — mesmo padrão de posicionamento do `NpsSnapshotService::registrar()` (que já roda ali por este exato motivo, documentado no comentário da Phase 79). Se o 23000 disparar, o evento reverte junto com o resto.

**Warning signs:** Teste que força o guard de duplicata (2º submit no mesmo mês) e verifica que **nenhum** evento `submitted` extra foi criado para a tentativa que falhou.

### Pitfall 4: `nps_surveys.status` é `enum('pending','completed','expired')` — não confundir com o novo `event_type`

**What goes wrong:** `nps_surveys.status` já é um enum MySQL existente desde a migration original (`2026_04_26_152218`). A Fase 94 não precisa (e não deve) alterar esse enum — `event_type` é uma coluna **nova**, numa tabela **nova** (`nps_survey_events`), com seu próprio enum independente. Confundir os dois e tentar fazer `ALTER` no `status` existente reintroduziria o pitfall real de enum+SQLite documentado no projeto.

**How to avoid:** Nenhuma migration desta fase deve tocar em `nps_surveys.status`. Confirmar isso no code review do plano.

## Code Examples

### `NpsSuspicionService` completo (padrão de referência para o plano)

```php
<?php

namespace App\Services\Nps;

use Symfony\Component\HttpFoundation\IpUtils;

/**
 * NpsSuspicionService — Phase 94 (AB-94-4).
 *
 * Avalia se uma resposta NPS é suspeita com base em 4 regras. Stateless,
 * resolvido via container (mesmo padrão de NpsSnapshotService/NpsPendingService).
 * NÃO bloqueia nada — apenas retorna o veredito para o controller persistir.
 * Bloqueio de sessão interna é Fase 96 (fora de escopo aqui).
 */
class NpsSuspicionService
{
    /**
     * @return array{is_suspicious: bool, reasons: array<int, string>, severity: string}
     */
    public function evaluate(?string $ip, int $durationSeconds, bool $isAuthenticatedSession): array
    {
        $reasons = [];

        $ehIpInterno = $ip !== null && $this->isInternalIp($ip);
        $ehRapida    = $durationSeconds <= $this->windowSeconds();

        if ($ehIpInterno) {
            $reasons[] = 'Resposta enviada a partir da rede interna da ECF.';
        }

        if ($ehRapida) {
            $reasons[] = sprintf(
                'Resposta enviada em menos de %d segundos após geração do link.',
                $this->windowSeconds()
            );
        }

        if ($ehIpInterno && $ehRapida) {
            $reasons[] = 'Link gerado e respondido rapidamente a partir da rede interna.';
        }

        if ($isAuthenticatedSession) {
            $reasons[] = 'Resposta realizada em sessão autenticada de usuário interno.';
        }

        $severidade = ($ehIpInterno && $ehRapida) ? 'alta' : (empty($reasons) ? 'nenhuma' : 'media');

        return [
            'is_suspicious' => ! empty($reasons),
            'reasons'       => $reasons,
            'severity'      => $severidade,
        ];
    }

    private function isInternalIp(string $ip): bool
    {
        $ranges = array_merge(
            config('nps.anti_burlamento.internal_ips', []),
            config('nps.anti_burlamento.internal_cidrs', []),
        );

        if (empty($ranges)) {
            return false;
        }

        return IpUtils::checkIp($ip, $ranges);
    }

    private function windowSeconds(): int
    {
        return (int) config('nps.anti_burlamento.fast_response_window_seconds', 60);
    }
}
```

**Nota sobre `suspicion_reasons` e severidade:** CONTEXT deixa em aberto ("Claude's Discretion") se a severidade entra como campo dedicado no JSON. O exemplo acima retorna `severity` separado do array `reasons` — recomendação: persistir `suspicion_reasons` como `{"reasons": [...], "severity": "alta"}` (objeto, não array simples) para já nascer pronto pro consumo da Fase 95 sem precisar de migration nova depois. Isso é uma decisão de shape que o plano deve travar explicitamente.

### `config/nps.php` (novo arquivo — segue o padrão de `config/digisac.php`)

```php
<?php

/*
 * Configuração do módulo Anti-Burlamento NPS — Phase 94.
 *
 * IPs/CIDRs internos da ECF e a janela de "resposta rápida" ficam em .env
 * nesta fase — configuráveis pela UI é Fase 96 (fora de escopo aqui).
 */

return [

    'anti_burlamento' => [

        // Lista de IPs exatos da rede interna ECF, separados por vírgula.
        // Ex.: ECF_INTERNAL_IPS=201.10.20.30,189.40.50.60
        'internal_ips' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ECF_INTERNAL_IPS', ''))
        ))),

        // Lista de redes CIDR internas, separadas por vírgula.
        // Ex.: ECF_INTERNAL_CIDRS=10.0.0.0/8,192.168.0.0/16
        'internal_cidrs' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('ECF_INTERNAL_CIDRS', ''))
        ))),

        // Janela (segundos) para considerar uma resposta "rápida demais" após
        // a geração do link. Default 60s (Regra 2 do NpsSuspicionService).
        'fast_response_window_seconds' => (int) env('NPS_SUSPICION_WINDOW_SECONDS', 60),

    ],

];
```

**Adicionar em `.env.example`:**
```
ECF_INTERNAL_IPS=
ECF_INTERNAL_CIDRS=
NPS_SUSPICION_WINDOW_SECONDS=60
```

### Model `NpsSurveyEvent` (novo)

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NpsSurveyEvent extends Model
{
    use HasFactory;

    public const TYPE_GENERATED    = 'generated';
    public const TYPE_OPENED       = 'opened';
    public const TYPE_SUBMITTED    = 'submitted';
    public const TYPE_EXPIRED      = 'expired';
    public const TYPE_SENT_EMAIL   = 'sent_email';
    public const TYPE_SENT_DIGISAC = 'sent_digisac';

    public const TYPES = [
        self::TYPE_GENERATED, self::TYPE_OPENED, self::TYPE_SUBMITTED,
        self::TYPE_EXPIRED, self::TYPE_SENT_EMAIL, self::TYPE_SENT_DIGISAC,
    ];

    protected $fillable = ['survey_id', 'event_type', 'ip_address', 'user_agent', 'user_id', 'metadata'];

    protected $casts = ['metadata' => 'array'];

    public function survey(): BelongsTo
    {
        return $this->belongsTo(NpsSurvey::class, 'survey_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

## State of the Art

Não aplicável — não há "abordagem antiga vs. nova" nesta fase; é uma feature nova, não uma migração de padrão existente.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `$request->ip()` retorna um IP utilizável em produção (não sempre `127.0.0.1`/IP de proxy) — sem isso, a Regra 1 do `NpsSuspicionService` nunca detecta corretamente | Common Pitfalls #1 | MÉDIO — a Regra 1 fica sempre "não suspeita" (falso negativo) ou sempre "suspeita" (falso positivo) até a topologia de rede do VPS ser confirmada; não bloqueia a codificação da fase, mas exige verificação manual pós-deploy |
| A2 | Regra 4 (sessão autenticada) avalia **apenas** `Auth::check()` no momento do POST — não correlaciona com uma eventual sessão autenticada capturada durante um GET anterior (não há join com `nps_survey_events` no cálculo de suspeita) | Ponto de inserção 2 / Code Examples | BAIXO — simplificação razoável para o escopo "backend only" desta fase; se o usuário quiser correlação histórica, é ajuste pequeno na Fase 95/96 (dado já fica registrado no `user_id` do evento `opened`) |
| A3 | O evento `generated` deve ser emitido em AMBOS os call-sites de `NpsSurvey::create()` (manual E disparo mensal automático) — CONTEXT.md lista explicitamente "geração manual de link" mas não menciona geração automática separadamente do "disparo mensal (email/Digisac)" | Emissores de eventos | BAIXO — sem isso, a trilha do Success Criteria #4 ("gerado → enviado → aberto → respondido") fica incompleta para surveys automáticas (teria `sent_email` sem `generated` antes); recomendação alinhada ao objetivo declarado da trilha |
| A4 | `nps_survey_events` usa `$table->timestamps()` (created_at + updated_at) por convenção do projeto, mesmo sendo uma tabela append-only onde `updated_at` nunca muda — mesmo padrão usado em `nps_response_scores`/`nps_digisac_envios` (também append-only) | Pattern 2 | MUITO BAIXO — só desperdiça 1 coluna, zero risco funcional |
| A5 | `config/nps.php` (arquivo novo) é o local certo para `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS`/janela — CONTEXT deixou como discretion ("novo `config/nps.php` vs. chave em config existente"); não existe `config/nps.php` hoje (só `config/digisac.php`), então "config existente" não é uma opção NPS-específica real | Code Examples | MUITO BAIXO — é criação de arquivo novo, não há conflito com nada existente |

**Nenhum destes itens bloqueia o planejamento** — todos têm uma recomendação clara acima. A2, A3, A4, A5 podem ser confirmados/ajustados durante o `/gsd:plan-phase` sem nova rodada de pesquisa. A1 precisa de verificação manual no VPS (não é código, é infraestrutura) — recomenda-se incluir uma tarefa `checkpoint:human-verify` no plano para confirmar a topologia de rede antes do primeiro deploy desta fase.

## Open Questions

1. **Topologia de rede do VPS — há proxy reverso na frente do PHP-FPM?**
   - What we know: `bootstrap/app.php` não configura `trustProxies`; CLAUDE.md descreve "Web server: Apache/Nginx com `www-data` ownership" (ambíguo — não deixa claro se é Apache direto na 443 ou Nginx→Apache).
   - What's unclear: se `$request->ip()` retorna o IP público real do cliente ou o IP de um proxy interno.
   - Recommendation: incluir no plano uma tarefa de verificação manual pós-deploy (ex.: gerar um link, abrir de fora da rede ECF, checar no banco se `open_ip_address` bate com o IP público real do dispositivo usado). Se não bater, plano de acompanhamento (fora desta fase) configura `trustProxies`. Não bloqueia a codificação da Regra 1 — apenas sua confiabilidade em produção.

2. **Deve o `submitResponse()` também emitir algum sinal de auditoria quando rejeita por expiração (422), mesmo sem mudar `status`?**
   - What we know: o único lugar que persiste `status=expired` é `respond()` (GET); `submitResponse()` (POST) apenas retorna 422 quando `isExpired()` é true, sem tocar no banco.
   - What's unclear: se vale a pena registrar essa tentativa rejeitada em `nps_survey_events` (ex.: metadata `{'rejected_reason': 'expired'}` num evento futuro, ou reaproveitar `submitted` com metadata indicando falha) — não é coberto por nenhum dos 6 `event_type` travados no CONTEXT.
   - Recommendation: fora de escopo estrito de AB-94-3 (que lista os 6 tipos travados); não criar 7º tipo sem confirmação do usuário. Se quiser esse sinal, é ajuste pequeno de escopo a decidir no `/gsd:plan-phase` ou deferir para Fase 95/96.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.50 (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` (suites `Unit` em `tests/Unit`, `Feature` em `tests/Feature`) |
| Quick run command | `php artisan test --filter=Phase94` |
| Full suite command | `php artisan test` |

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AB-94-1 | GET registra first_opened_at/last_opened_at/open_count/IP/UA; múltiplas aberturas preservam first_opened_at | Feature | `php artisan test --filter=NpsOpenTrailTest` | ❌ Wave 0 (novo `tests/Feature/Phase94/NpsOpenTrailTest.php`) |
| AB-94-2 | POST registra response_ip_address/response_user_agent/response_duration_seconds em ambos os paths (v15 e legacy) | Feature | `php artisan test --filter=NpsResponseTrailAndSuspicionTest` | ❌ Wave 0 (novo `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php`) |
| AB-94-3 | `nps_survey_events` acumula linha do tempo completa (generated → sent_email/sent_digisac → opened → submitted/expired) para os 4 emissores | Feature | `php artisan test --filter=NpsSurveyEventsTest` | ❌ Wave 0 (novo `tests/Feature/Phase94/NpsSurveyEventsTest.php`) |
| AB-94-4 | `NpsSuspicionService` avalia as 4 regras corretamente (IP interno, janela rápida, combinação, sessão autenticada) e persiste `is_suspicious`/`suspicion_reasons` | Feature (chamando o service diretamente + via HTTP) | `php artisan test --filter=NpsSuspicionServiceTest` | ❌ Wave 0 (novo `tests/Feature/Phase94/NpsSuspicionServiceTest.php`) |
| AB-94-5 | Surveys/respostas legadas (sem template_id, sem colunas novas preenchidas) continuam passando pelos fluxos existentes sem quebrar | Feature (regressão) | `php artisan test --filter=Phase31NpsSubmitTest && php artisan test --filter=NpsAntiBurlamentoBackwardCompatTest` | Parcial — `Phase31NpsSubmitTest` já existe e deve continuar verde; `NpsAntiBurlamentoBackwardCompatTest` é novo (Wave 0) |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=Phase94` (roda só a suite nova, rápido)
- **Per wave merge:** `php artisan test --filter=Nps` (roda toda a suite NPS — hoje 207/207 verde; a fase 94 não pode reduzir esse número)
- **Phase gate:** `php artisan test` completo (suite inteira) antes de `/gsd:verify-work` — a memória do projeto registra "Nps 207/207" como baseline de regressão a preservar; após a Fase 94 o número sobe (novos testes) mas nenhum teste Nps pré-existente pode passar a falhar

### Wave 0 Gaps

- [ ] `tests/Feature/Phase94/NpsOpenTrailTest.php` — cobre AB-94-1
- [ ] `tests/Feature/Phase94/NpsResponseTrailAndSuspicionTest.php` — cobre AB-94-2 + parte de AB-94-4 (persistência)
- [ ] `tests/Feature/Phase94/NpsSurveyEventsTest.php` — cobre AB-94-3 (todos os 4 emissores)
- [ ] `tests/Feature/Phase94/NpsSuspicionServiceTest.php` — cobre AB-94-4 (as 4 regras isoladas, incluindo `withServerVariables(['REMOTE_ADDR' => ...])` para simular IP real — ver Common Pitfalls #1)
- [ ] `tests/Feature/Phase94/NpsAntiBurlamentoBackwardCompatTest.php` — cobre AB-94-5 (survey/response sem colunas novas populadas continua funcionando em todas as agregações do `NpsController::index()`)
- [ ] `database/factories/NpsSurveyEventFactory.php` — factory nova para o model novo (padrão de `NpsResponseAnswerFactory.php` como referência de shape mínimo)

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V5 Input Validation | Sim | `ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS` são lidos de `.env` (não input de usuário nesta fase — Fase 96 move para UI, aí sim precisa validar formato CIDR no submit) — nesta fase, validação é só defensiva (`array_filter` remove entradas vazias antes de passar pro `IpUtils::checkIp`, que já lança `InvalidArgumentException` silenciosamente tratável se o formato for inválido) |
| V7 Error Handling and Logging | Sim | `nps_survey_events` É a implementação desta categoria — trilha de auditoria técnica append-only, alinhada com o padrão `Log::info/error` com prefixo `[NPS ...]` já usado no módulo |
| V11 Business Logic | Sim | `NpsSuspicionService` é um controle de anti-automação/anti-abuso de negócio — a fase implementa apenas a **detecção** (marca), não o **enforcement** (bloqueio é V11 mais estrito, na Fase 96) |
| V2 Authentication | Não | Rota pública `/nps/{token}` não usa autenticação própria — o "usuário autenticado" da Regra 4 é sobre a sessão do painel admin coexistindo com a aba pública, não uma auth nova nesta fase |
| V6 Cryptography | Não | Nenhum dado sensível novo precisa de cifragem — IP e user-agent já trafegam em claro no `activity_log` existente (padrão já aceito no projeto para IP de login) |

### Known Threat Patterns for este domínio

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Spoofing de IP via header `X-Forwarded-For` manipulado pelo cliente | Tampering | NÃO confiar em headers `X-Forwarded-*` a menos que `trustProxies` esteja configurado explicitamente para o(s) IP(s) do proxy real — usar sempre `$request->ip()` (que já respeita trustProxies quando configurado) e nunca ler `X-Forwarded-For` manualmente do request |
| Repúdio — usuário interno nega ter respondido a própria pesquisa | Repudiation | `nps_survey_events` com `user_id` + `ip_address` + `created_at` imutáveis cobre isso; é exatamente o propósito da trilha |
| Abuso de automação — script/bot gerando e respondendo NPS em lote para inflar métricas | Elevation of Privilege (indireto — abuso de processo de negócio) | Regras 2/3 do `NpsSuspicionService` (janela de tempo curta) são a mitigação primária nesta fase; enforcement real (bloqueio) é Fase 96 |

## Sources

### Primary (HIGH confidence)
- `app/Http/Controllers/NpsController.php` (leitura completa, 1396 linhas) — fluxo exato de `respond()`/`submitResponse()`/`submitResponseV15()`/`submitResponseLegacy()`/`generate()`
- `app/Console/Commands/NpsDispararMensal.php` (leitura completa) — único emissor de `generated`/`sent_email`/`sent_digisac` automáticos
- `app/Services/Digisac/NpsDigisacDispatchService.php` (leitura completa) — ponto exato de confirmação de envio Digisac
- `app/Models/NpsSurvey.php`, `app/Models/NpsResponse.php`, `database/factories/NpsSurveyFactory.php`, `database/factories/NpsResponseFactory.php` — schema/fillable/casts atuais
- `database/migrations/2026_04_26_152218_create_nps_surveys_table.php`, `2026_06_10_100002_recreate_nps_responses_table.php`, `2026_07_07_100005_add_dedup_key_to_nps_surveys.php`, `2026_06_10_100004_make_generated_by_nullable_on_nps_surveys_table.php`, `2026_07_14_200001_create_nps_snapshot_tables.php`, `2026_07_08_180001_create_nps_digisac_envios_table.php` — padrões de migration idempotente/cross-driver já em produção
- `routes/web.php` (grep completo `nps`) — confirmação de que `/nps/{token}` está fora de qualquer grupo `auth`/`role`
- `bootstrap/app.php` — confirmação de ausência de `trustProxies` [VERIFIED: leitura direta do arquivo]
- `composer.lock` (grep `symfony/http-foundation`) — confirmação de disponibilidade transitiva [VERIFIED: composer.lock]
- `app/Models/User.php` — `isAdmin()/isConsultor()/isMentor()` confirmam que "usuário interno" = qualquer usuário autenticado do sistema (não há usuários-clientes com login)
- `tests/Feature/Phase31NpsSubmitTest.php` — padrão de teste Feature existente para o fluxo de submit (baseline de regressão)
- `.planning/phases/94-.../94-CONTEXT.md`, `.planning/ROADMAP.md` (seção Phase 94), `PLANO_NPS_ANTI_BURLAMENTO_DIGISAC.md` (seções 1-2) — fonte das decisões travadas

### Secondary (MEDIUM confidence)
- Nenhuma — toda a pesquisa foi feita por leitura direta do código-fonte do projeto, sem necessidade de busca externa (fase 100% backend com stack já resolvido)

### Tertiary (LOW confidence)
- Nenhuma

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — zero deps novas, tudo confirmado em `composer.lock` e uso real no codebase
- Architecture: HIGH — todos os 4 pontos de inserção lidos linha a linha no código-fonte atual, com números de linha confirmados
- Pitfalls: HIGH para os pitfalls de schema (confirmados por grep + leitura de migrations existentes); MÉDIO para o pitfall de trusted proxy (a ausência de config está confirmada, mas o comportamento real do VPS não foi verificado nesta sessão — requer teste manual pós-deploy)

**Research date:** 2026-07-16
**Valid until:** 2026-08-15 (30 dias — stack estável, sem dependência de API externa com versionamento rápido)
