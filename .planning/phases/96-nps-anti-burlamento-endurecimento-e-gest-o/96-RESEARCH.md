# Phase 96: NPS Anti-Burlamento — Endurecimento e Gestão - Research

**Researched:** 2026-07-17
**Domain:** Laravel 12 (controllers/migrations/Eloquent) + Inertia/React — endurecimento de fluxo público NPS + persistência de configuração via UI + invalidação de dado agregado (bônus/dashboards)
**Confidence:** HIGH (código-fonte lido diretamente; nenhuma dependência externa nova; nenhum pacote a instalar)

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**AB-96-1 — Bloqueio de sessão interna**
- Resposta (submit) em sessão autenticada de usuário interno é BLOQUEADA — upgrade da Regra 4 da Fase 94 (que hoje só marca)
- Mensagem amigável ao usuário (não um 500/erro cru) — explicar que aquela sessão não pode responder; sem jargão
- O bloqueio é registrado como evento em `nps_survey_events` (novo tipo de evento ou metadata em evento existente — decidir no planejamento; o CONTEXT da Fase 94 travou 6 tipos, então avaliar se cria 7º `blocked` ou usa metadata)
- A ABERTURA (GET) continua permitida e registrada; o bloqueio é no SUBMIT (POST) — não revelar demais ao usuário interno o que dispara

**AB-96-2 — IPs internos pela UI**
- IPs/CIDRs internos da ECF configuráveis pelo painel (tela NPS > Configuração — `resources/js/Pages/Nps/Configuracao.jsx`), apenas admin
- `.env` (`ECF_INTERNAL_IPS`/`ECF_INTERNAL_CIDRS` de `config/nps.php`, criados na Fase 94) permanece como fallback/default
- Persistência: decidir no planejamento (tabela de config, `settings`, ou coluna JSON) seguindo o padrão de config já usado no NPS
- `NpsSuspicionService` passa a ler a lista efetiva (UI ∪/precedência sobre `.env`) — a Fase 94 já lê de `config/nps.php`; estender a fonte sem quebrar a assinatura pública do service (suíte Nps verde)

**AB-96-3 — Invalidação manual**
- Admin pode invalidar uma resposta suspeita (ação na UI da Fase 95 — listagem/modal), com trilha no `spatie/activitylog` (quem invalidou e quando)
- Resposta invalidada SAI das agregações de forma consistente: Dashboards NPS/médias; Snapshots que alimentam o bônus — ATENÇÃO especial a `nps_response_scores` e `nps_score_assignments` (fonte do bônus v16.0, gravados pelo `NpsSnapshotService`)
- Mecanismo de invalidação: decidir no planejamento — flag `invalidated_at`/`invalidated_by` em `nps_responses` + filtro em TODAS as agregações, OU soft-exclusão dos snapshots. Preferir a abordagem que garanta que NENHUMA query de bônus/dashboard conte a resposta invalidada (o risco é esquecer um call-site)
- Idealmente reversível (revalidar) — decidir no planejamento

### Claude's Discretion
- 7º event_type `blocked` vs metadata em evento existente
- Estrutura de persistência dos IPs pela UI
- Flag de invalidação vs remoção de snapshot — priorizar consistência total das agregações
- Se a invalidação recalcula/remove os snapshots do `NpsSnapshotService` na hora ou apenas marca e filtra na leitura

### Deferred Ideas (OUT OF SCOPE)
- Correlação histórica abertura-logada → submit-deslogado (refinamento da detecção) — follow-up
- Generalização do Digisac (tabela polimórfica) — backlog
</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| AB-96-1 | Sessão interna autenticada é bloqueada no SUBMIT (POST), com mensagem amigável e evento auditado | Ver `Architecture Patterns > Padrão 1`, `Code Examples > Bloqueio`, Pitfall "enum+SQLite em ALTER" |
| AB-96-2 | IPs/CIDRs internos configuráveis pelo painel `/nps/configuracao`, `.env` como fallback | Ver `Architecture Patterns > Padrão 2`, `Code Examples > Config IPs pela UI` |
| AB-96-3 | Invalidação manual de resposta suspeita, com efeito consistente em TODAS as agregações (dashboards/bônus) e trilha de quem/quando | Ver `Architecture Patterns > Padrão 3` (mapa exaustivo de call-sites), `Common Pitfalls`, `Code Examples > Invalidação` |
</phase_requirements>

## Summary

Fase 96 é 100% trabalho interno ao codebase Laravel/Inertia já existente — **nenhum pacote novo a instalar** (nem `composer`, nem `npm`). As três frentes se apoiam em padrões que o próprio projeto já usa em outros módulos NPS:

1. **AB-96-1 (bloqueio):** `auth()->check()` já funciona nas rotas públicas `/nps/{token}` (a Fase 94 comprovou isso gravando `user_id` nos eventos `opened`/`submitted`). O ponto de bloqueio é `NpsController::submitResponse()`, ANTES de despachar para `submitResponseV15()`/`submitResponseLegacy()` — nenhuma `NpsResponse` deve ser criada quando bloqueado. A pergunta em aberto no CONTEXT ("7º event_type `blocked` vs metadata") tem uma resposta técnica clara: **nenhum evento existente é semanticamente compatível** (nem `opened`, nem `submitted`, nem `expired` representam "tentativa de submit rejeitada") — a pesquisa recomenda criar o 7º tipo `blocked`, usando o **padrão de migration enum+SQLite já usado 2x neste mesmo módulo** (`add_shopee_to_servicos_setor_enum.php` e `alter_nps_template_questions_tipo_add_texto_livre.php`) para não cair na armadilha do CHECK constraint do SQLite dos testes.

2. **AB-96-2 (IPs pela UI):** o projeto já tem o padrão exato de "config chave/valor persistida + fallback + widget PATCH" rodando em produção: `Configuracao::get/set('nps_dia_cobranca', ...)`, servido por `NpsTemplateController::index()` e consumido pelo `DiaCobrancaWidget` em `Nps/Configuracao.jsx`. A pesquisa recomenda replicar esse MESMO padrão para 2 novas chaves (`nps_internal_ips`/`nps_internal_cidrs`, JSON array de strings) e estender `NpsSuspicionService::isInternalIp()` (método privado, sem tocar na assinatura pública `evaluate()`) para ler a UNIÃO (`.env` ∪ UI).

3. **AB-96-3 (invalidação) — a parte crítica:** o codebase tem um **dual-path documentado e testado** que consome `nps_score_assignments`/`nps_response_scores`/o `response()` de `NpsSurvey` em **10 call-sites distintos**, espalhados por 6 arquivos (`DesempenhoScoreService`, `PerformanceController`, `DashboardController`, `PortfolioController`, `CalculateGoalResults`, `CompanyController` — este último adicionado na revisão pós plan-check). Todos eles, sem exceção, chegam a uma `NpsResponse` — seja via JOIN direto em `nps_score_assignments.nps_response_id`, seja via eager-load `NpsSurvey::with('response')`. Isso significa que uma flag `invalidated_at`/`invalidated_by` em `nps_responses`, combinada com uma constraint aplicada em cada um desses 8 pontos, fecha 100% da superfície — **sem precisar tocar em `NpsSnapshotService`, sem apagar nenhuma linha de `nps_response_scores`/`nps_score_assignments`, e mantendo reversibilidade trivial** (a "revalidação" é só `invalidated_at = null`). A pesquisa também descobriu um 9º ponto não óbvio e de alto risco: `DesempenhoScoreService::computeCached()` cacheia o bônus de um MÊS FECHADO por até **7 dias** — sem um `Cache::forget()` explícito no momento da invalidação, o admin veria o número errado por até uma semana mesmo com a query corrigida.

**Primary recommendation:** implementar as 3 frentes como extensões cirúrgicas do código já lido (nenhum novo pacote), com o mecanismo de invalidação centrado em UMA flag em `nps_responses` + um scope Eloquent reutilizável (`scopeValida()`), aplicado explicitamente nos 8 call-sites mapeados abaixo + `Cache::forget()` das chaves de bônus afetadas.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Bloqueio de submit (sessão interna) | API/Backend (`NpsController::submitResponse`) | — | Decisão de negócio no ponto de entrada do POST público; nenhuma lógica de UI decide isso (a UI de resposta é pública/anônima) |
| Registro de evento `blocked` | API/Backend (`NpsSurveyEvent`) | Database/Storage | Trilha de auditoria append-only, mesmo padrão dos outros 6 tipos já existentes |
| Config de IPs internos (persistência) | Database/Storage (`configuracoes` key/valor) | API/Backend (`NpsTemplateController`/`NpsController`) | Segue o padrão já estabelecido (`nps_dia_cobranca`, `nps_textos`) — key/valor genérico, sem tabela nova |
| Config de IPs internos (UI) | Frontend Server/SSR (Inertia render) | Browser (formulário React) | Tela admin-only já existe (`Nps/Configuracao.jsx`), só precisa de 1 widget novo no molde do `DiaCobrancaWidget` |
| Leitura efetiva de IPs (regra de suspeita) | API/Backend (`NpsSuspicionService`) | Database/Storage | Service já é a fonte única da Regra 1/4 — deve permanecer o único ponto que decide "é IP interno?" |
| Flag de invalidação | Database/Storage (`nps_responses.invalidated_at`) | API/Backend | É dado, não é lógica — a lógica de "quem pode ver o quê" fica nos controllers/services que leem a flag |
| Filtro de agregação (bônus/dashboards) | API/Backend (8 call-sites mapeados) | Database/Storage (índice na coluna) | Cada consumidor de `NpsResponse`/`NpsScoreAssignment` é responsável por filtrar — não existe camada única de "agregação" no codebase atual |
| Cache-busting do bônus | API/Backend (`DesempenhoScoreService`) | — | O cache é per-user-per-mês; só o backend sabe quais `user_id` foram afetados pela resposta invalidada |
| Trilha de invalidação (quem/quando) | API/Backend (`activity()` explícito) | Database/Storage (`activity_log`) | Padrão já usado em `MarkCustIdStatus`/`NotificacaoController` — chamada explícita, sem acoplar `LogsActivity` trait ao model inteiro |

## Standard Stack

### Core

Nenhuma dependência nova. Toda a Fase 96 usa infraestrutura já presente:

| Componente | Já usado desde | Papel na Fase 96 |
|---|---|---|
| `Symfony\Component\HttpFoundation\IpUtils` | Fase 94 (`NpsSuspicionService`) | Continua sendo o único matcher de IP/CIDR — IPs vindos da UI passam pelo MESMO `IpUtils::checkIp()`, nenhuma lib nova |
| `spatie/laravel-activitylog` | Já em `composer.json` (`^4.9`) | Trilha de invalidação via chamada explícita `activity()->...->log()` (ver Code Examples) |
| `App\Models\Configuracao` | Fase 32/72 (`nps_textos`, `nps_dia_cobranca`) | Persistência das chaves de IPs internos pela UI |
| Eloquent local scopes | Uso extensivo no projeto (`scopePrincipal`) | Novo `NpsResponse::scopeValida()` |

**Instalação:** nenhuma. `composer install`/`npm install` não são afetados por esta fase.

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| Flag `invalidated_at` + filtro em cada call-site | Deletar/recalcular os snapshots (`nps_response_scores`/`nps_score_assignments`) no momento da invalidação | Perde reversibilidade trivial (revalidar exigiria re-rodar `NpsSnapshotService`, que pode gerar um snapshot DIFERENTE do original se a carteira mudou nesse meio-tempo — quebra o próprio princípio de "congelamento" da Fase 79); mais código, mais risco |
| Tabela `configuracoes` (chave/valor) para IPs | Tabela dedicada `nps_internal_ranges` (1 linha por IP/CIDR) | Tabela dedicada permite metadados por IP (label, criado_por) mas é over-engineering para uma lista pequena (dezenas de IPs no máximo) editada só por admin; o padrão `Configuracao` já é o "blessed pattern" deste projeto para esse tipo de config |
| 7º `event_type = 'blocked'` | Metadata em evento existente (ex.: `opened.metadata.blocked = true`) | Semanticamente errado — o bloqueio acontece no POST, não no GET onde `opened` é emitido; não existe evento existente que representa uma tentativa de submit rejeitada |

## Package Legitimacy Audit

**Não aplicável** — esta fase não instala nenhum pacote novo (nem Composer, nem npm). Todas as dependências usadas (`symfony/http-foundation` via `laravel/framework`, `spatie/laravel-activitylog`) já estão em `composer.lock` desde fases anteriores.

## Architecture Patterns

### Diagrama — Fluxo de bloqueio (AB-96-1)

```
GET /nps/{token}                          POST /nps/{token}
      │                                          │
      ▼                                          ▼
NpsController::respond()              NpsController::submitResponse()
  - grava rastro de abertura                     │
  - emite evento 'opened'          ┌─────────────┴─────────────┐
  - PERMITIDO mesmo p/ sessão      │  $survey = ...firstOrFail()│
    interna (Fase 94, inalterado)  │  if (isExpired()) → 422    │
                                    └─────────────┬─────────────┘
                                                  ▼
                                    ┌─────────────────────────────┐
                                    │ NOVO (AB-96-1):              │
                                    │ if (auth()->check()) {       │
                                    │   emite evento 'blocked'     │
                                    │   return Inertia::render(    │
                                    │     'Nps/Blocked')           │
                                    │ }                             │
                                    └─────────────┬─────────────┘
                                                  ▼ (não bloqueado)
                                    template_id !== null?
                                      ├─ sim → submitResponseV15()
                                      └─ não → submitResponseLegacy()
```

### Diagrama — Fluxo de config de IPs (AB-96-2)

```
Admin em /nps/configuracao (Nps/Configuracao.jsx, modo 'list')
      │
      ▼
Novo widget "IPs internos" (molde: DiaCobrancaWidget)
      │  PATCH /nps/configuracao/ips-internos
      ▼
NpsController::atualizarIpsInternos()
      │  valida cada entrada (IP válido OU CIDR válido)
      │  Configuracao::set('nps_internal_ips', json_encode($ips))
      │  Configuracao::set('nps_internal_cidrs', json_encode($cidrs))
      ▼
[persistido em `configuracoes` — tabela key/valor já existente]

  ⋯ tempo depois, em qualquer submit NPS ⋯

NpsSuspicionService::isInternalIp($ip)
      │  ranges = config('nps.anti_burlamento.internal_ips')   // .env (fallback)
      │         ∪ config('nps.anti_burlamento.internal_cidrs') // .env (fallback)
      │         ∪ Configuracao::get('nps_internal_ips')  decode // UI
      │         ∪ Configuracao::get('nps_internal_cidrs') decode // UI
      ▼
IpUtils::checkIp($ip, $ranges)   ← inalterado, mesma chamada da Fase 94
```

### Diagrama — Mapa exaustivo de call-sites da invalidação (AB-96-3)

```
Admin clica "Invalidar resposta" no modal (Nps/Index.jsx)
      │  PATCH /nps/{survey}/response/invalidar
      ▼
NpsController::invalidarResposta()
      │  $response->update(['invalidated_at' => now(), 'invalidated_by' => $user->id])
      │  activity()->causedBy($user)->performedOn($response)->log('Resposta NPS invalidada')
      │  Cache::forget() das chaves de bônus dos users em nps_score_assignments desta resposta
      ▼
[nps_responses.invalidated_at preenchido — NADA é deletado, NpsSnapshotService não roda de novo]

  ⋯ toda leitura subsequente precisa filtrar ⋯

┌─────────────────────────────────────────────────────────────────────────┐
│ 10 CALL-SITES QUE PRECISAM DO FILTRO (nenhum pode ser esquecido)         │
├─────────────────────────────────────────────────────────────────────────┤
│ 1. DesempenhoScoreService::notasPorAtribuicao()  (JOIN nps_responses)   │
│ 2. DesempenhoScoreService::notasLegado()          (eager-load response) │
│ 3. PerformanceController::notasNpsDoUsuarioPorResposta() ramo (A) JOIN  │
│ 4. PerformanceController::notasNpsDoUsuarioPorResposta() ramo (B) legado│
│ 5. DashboardController — home() (linha ~540, eager-load ->principal()) │
│ 6. DashboardController — userDashboard() (linha ~1058, idem)           │
│ 7. DashboardController — buildRanking()/$surveys (linha ~938, método ~927, idem) │
│ 8. PortfolioController — "Histórico NPS mensal" (linha ~1600, idem —   │
│    NÃO usa NpsScoreAssignment, é single-path ->principal() + calculator│
│    direto sobre $s->response; CONFIRMADO como implementação PRÓPRIA,   │
│    não compartilhada com PerformanceController)                        │
│ 9. CalculateGoalResults::computeNps()             (metas NPS mensais)  │
│10. CompanyController::show() (linha ~303 eager-load npsSurveys →       │
│    avgNps/lista em Companies/Show.jsx) — visível a QUALQUER usuário    │
│    com acesso à empresa, não só admin; fix backend-only (->valida())   │
├─────────────────────────────────────────────────────────────────────────┤
│ + NpsController::index() cards/série 12m (NpsResponse::query() solto)  │
│   — a LISTAGEM PAGINADA em si NÃO filtra (admin precisa ver p/ gerir)  │
└─────────────────────────────────────────────────────────────────────────┘
```
*(nota: contagem granular por método/linha, útil como checklist de execução — o call-site #10 foi adicionado na revisão pós plan-check)*

### Padrão 1 — Bloqueio no ponto de entrada do submit, sem tocar nos 2 paths

**O quê:** interceptar em `NpsController::submitResponse()` (o método que já discrimina v15/legacy), ANTES do `if ($survey->template_id !== null)`.

**Quando usar:** exatamente aqui — é o único ponto que os dois paths (`submitResponseV15`/`submitResponseLegacy`) compartilham antes de qualquer `NpsResponse::create()`. Replicar o bloqueio dentro de cada path duplicaria a lógica (o mesmo pitfall documentado no `capturarRastroEAvaliarSuspeita` da Fase 94 — "nunca duplicar entre os 2 paths").

**Exemplo (baseado no código real de `app/Http/Controllers/NpsController.php:865-880`):**
```php
public function submitResponse(Request $request, string $token)
{
    $survey = NpsSurvey::where('token', $token)->where('status', 'pending')->firstOrFail();

    if ($survey->isExpired()) {
        return response()->json(['error' => 'Pesquisa expirada.'], 422);
    }

    // NOVO (AB-96-1) — bloqueio de sessão interna, upgrade da Regra 4.
    // Roda ANTES de qualquer NpsResponse::create() nos dois paths.
    if (auth()->check()) {
        NpsSurveyEvent::create([
            'survey_id'  => $survey->id,
            'event_type' => NpsSurveyEvent::TYPE_BLOCKED, // novo 7º tipo
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_id'    => auth()->id(),
            'metadata'   => null, // não precisa detalhar — o próprio user_id já é o sinal
        ]);

        return Inertia::render('Nps/Blocked');
    }

    if ($survey->template_id !== null) {
        return $this->submitResponseV15($request, $survey);
    }

    return $this->submitResponseLegacy($request, $survey);
}
```

**Página `Nps/Blocked.jsx` (novo arquivo, molde EXATO de `Nps/Expired.jsx`/`Nps/AlreadyCompleted.jsx` já lidos):**
```jsx
import { ShieldAlert } from 'lucide-react';

export default function Blocked() {
    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="text-center space-y-4 max-w-sm">
                <ShieldAlert className="h-20 w-20 text-yellow-400 mx-auto" />
                <h1 className="text-2xl font-bold text-foreground">Não foi possível registrar sua resposta</h1>
                <p className="text-muted-foreground">
                    Esta sessão não pode responder a esta pesquisa. Se você é o
                    destinatário, abra o link em uma janela anônima ou em outro
                    navegador.
                </p>
            </div>
        </div>
    );
}
```
*(mensagem redigida para não revelar "detectamos que você está logado como usuário interno" — só orienta a ação corretiva, conforme CONTEXT: "não revelar demais ao usuário interno o que dispara")*

### Padrão 2 — Config UI reaproveitando `Configuracao` (mesmo molde de `nps_dia_cobranca`)

**O quê:** 2 novas chaves key/valor (`nps_internal_ips`, `nps_internal_cidrs`), cada uma um JSON array de strings, salvas/lidas via `App\Models\Configuracao::get()/set()` — a MESMA classe/tabela já usada por `nps_textos` e `nps_dia_cobranca`.

**Quando usar:** sempre que a config é pequena, editável só por admin, e não precisa de relacionamento com outras tabelas — exatamente o perfil de "lista de IPs internos".

**Exemplo — leitura efetiva em `NpsSuspicionService` (sem mudar a assinatura pública `evaluate()`):**
```php
// app/Services/Nps/NpsSuspicionService.php
private function isInternalIp(string $ip): bool
{
    $ranges = array_merge(
        config('nps.anti_burlamento.internal_ips', []),
        config('nps.anti_burlamento.internal_cidrs', []),
        // NOVO (AB-96-2) — soma (∪) da lista editável pela UI. `.env`
        // continua valendo como fallback/default — nunca é substituído,
        // só complementado. Um IP cadastrado em qualquer uma das duas
        // fontes já é suficiente pra disparar a Regra 1.
        json_decode(\App\Models\Configuracao::get('nps_internal_ips', '[]'), true) ?: [],
        json_decode(\App\Models\Configuracao::get('nps_internal_cidrs', '[]'), true) ?: [],
    );

    if (empty($ranges)) {
        return false;
    }

    return IpUtils::checkIp($ip, $ranges);
}
```

**Exemplo — endpoint PATCH (mesmo molde de `atualizarDiaCobranca`, `app/Http/Controllers/NpsController.php:1293-1309`):**
```php
public function atualizarIpsInternos(Request $request)
{
    $validated = $request->validate([
        'ips'        => 'nullable|array',
        'ips.*'      => ['string', function ($attr, $value, $fail) {
            if (!filter_var($value, FILTER_VALIDATE_IP)) {
                $fail("\"$value\" não é um IP válido.");
            }
        }],
        'cidrs'      => 'nullable|array',
        'cidrs.*'    => ['string', 'regex:/^\d{1,3}(\.\d{1,3}){3}\/\d{1,2}$/'],
    ], [
        'cidrs.*.regex' => 'CIDR inválido — use o formato 10.0.0.0/8.',
    ]);

    Configuracao::set('nps_internal_ips', json_encode($validated['ips'] ?? [], JSON_UNESCAPED_UNICODE));
    Configuracao::set('nps_internal_cidrs', json_encode($validated['cidrs'] ?? [], JSON_UNESCAPED_UNICODE));

    return back()->with('success', 'IPs internos atualizados.');
}
```
*Rota: `PATCH /nps/configuracao/ips-internos` → `nps.configuracao.ips-internos.update`, registrada no mesmo grupo `role:admin` de `nps.configuracao.dia-cobranca.update` (`routes/web.php:275-277`).*

**Widget frontend** — copiar `DiaCobrancaWidget` (`resources/js/Pages/Nps/Configuracao.jsx:50-106`) trocando `useForm({dia: ...})` por `useForm({ips: [...], cidrs: [...]})` e o `<input type="number">` por uma lista editável (add/remove chip), posicionado ao lado do `DiaCobrancaWidget` no modo `'list'`.

### Padrão 3 — Invalidação por flag + scope Eloquent aplicado nos 8 call-sites

**O quê:** 2 colunas nullable em `nps_responses` (`invalidated_at`, `invalidated_by`) + 1 scope local `NpsResponse::scopeValida($query)`. NENHUM dado é apagado, `NpsSnapshotService` NÃO roda de novo, `nps_response_scores`/`nps_score_assignments` permanecem intactos — só deixam de ser CONTADOS.

**Por que flag (não deleção/recálculo):** reversibilidade trivial (`invalidated_at = null`); os snapshots da Fase 79 são "congelados por design" — recalculá-los no momento da invalidação poderia gerar valores diferentes dos originais se a carteira (`company_users`) mudou nesse meio-tempo, quebrando a garantia de imutabilidade que a Fase 79 existe para proteger.

**O scope:**
```php
// app/Models/NpsResponse.php
public function scopeValida($query)
{
    return $query->whereNull('invalidated_at');
}
```

**Os 8 call-sites e a mudança exata em cada um** (confirmados por leitura direta do código nesta sessão — `PortfolioController` e `PerformanceController` foram lidos separadamente e são implementações DIFERENTES, não compartilhadas):

| # | Arquivo | Método | Mudança |
|---|---------|--------|---------|
| 1 | `app/Services/DesempenhoScoreService.php` | `notasPorAtribuicao()` (linha ~485) | JOIN já existe (`join('nps_responses as r', ...)`) → adicionar `->whereNull('r.invalidated_at')` |
| 2 | `app/Services/DesempenhoScoreService.php` | `notasLegado()` (linha ~567) | `NpsSurvey::with('response')->principal()...` → trocar para `->with(['response' => fn ($q) => $q->valida()])` (o `foreach` já faz `if ($response === null) continue;` — passa a pular sozinho) |
| 3 | `app/Http/Controllers/PerformanceController.php` | `notasNpsDoUsuarioPorResposta()` ramo (A) (linha ~602) | JOIN já existe → adicionar `->whereNull('r.invalidated_at')` (mesmo padrão do #1). Alimenta os 3 widgets da carteira (coluna NPS, heatmap, últimas 4 respostas) numa única passagem |
| 4 | `app/Http/Controllers/PerformanceController.php` | `notasNpsDoUsuarioPorResposta()` ramo (B) (linha ~637) | `NpsSurvey::with(['response.answers', 'response.survey'])->principal()...` → `->with(['response' => fn ($q) => $q->valida()->with(['answers', 'survey'])])` |
| 5 | `app/Http/Controllers/DashboardController.php` | `home()` — widgets NPS (linha ~540) | `NpsSurvey::with('response')->principal()...` → `with(['response' => fn ($q) => $q->valida()])` |
| 6 | `app/Http/Controllers/DashboardController.php` | `userDashboard()` — widgets NPS (linha ~1058) | mesmo padrão do #5 |
| 7 | `app/Http/Controllers/DashboardController.php` | `buildRanking()` — `$surveys` (query ~938, método a partir da ~927) + `avgNotaDimensao()` (helper compartilhado, linha ~1139) | mesmo padrão do #5 — `avgNotaDimensao()` já faz `->filter(fn($n) => $n !== null)`, então filtrar o eager-load de `response` basta, nenhuma mudança no helper em si |
| 8 | `app/Http/Controllers/PortfolioController.php` | "Histórico NPS mensal do profissional" (linha ~1600) | `NpsSurvey::with(['response.answers', 'response.survey'])->principal()...` → mesmo padrão do #4. **Confirmado nesta pesquisa: esta é uma implementação PRÓPRIA e SIMPLES (single-path, sem `NpsScoreAssignment`), não a mesma função `notasNpsDoUsuarioPorResposta` do `PerformanceController`** — os dois arquivos precisam de fixes SEPARADOS |
| 9 | `app/Jobs/CalculateGoalResults.php` | `computeNps()` (linha ~210) | `NpsResponse::query()->whereHas('survey', ...)` → adicionar `->whereNull('invalidated_at')` direto na query de `NpsResponse` (não precisa de scope aqui, é query direta) |
| 10 | `app/Http/Controllers/CompanyController.php` | `show()` — eager-load `npsSurveys` (linha ~303) + builder do payload `nps_surveys` (linhas ~508-532) | trocar o nested `->with(['response.answers', 'template'])` por `->with(['response' => fn ($q) => $q->valida()->with('answers'), 'template'])` — a resposta invalidada vira `null` no payload `nps_surveys`, saindo do `avgNps` (`resources/js/Pages/Companies/Show.jsx` ~396-399) e da lista "NPS Respondidos" (~995-1014). **Visível a QUALQUER usuário com acesso à empresa (não só admin).** Fix 100% backend — o JSX já filtra por `s.response` no `completedNps`, NÃO tocar no Show.jsx |

**+ 1 call-site de agregação FORA da tabela acima (cards/série do próprio `/nps`):**
`NpsController::index()`, variável `$responsesMes` e o loop `$serieMeses` (linhas ~340 e ~370) — `NpsResponse::query()->with(['survey', 'answers'])->whereHas('survey', $responsesFilter)->get()` → adicionar `->whereNull('invalidated_at')`. **A listagem paginada (`$surveys = $baseQuery->paginate(...)`) NÃO deve filtrar** — o admin precisa continuar vendo a resposta invalidada na lista/modal para poder revalidá-la.

**Cache-busting (achado crítico da pesquisa, não estava em nenhum item explícito do CONTEXT):**
```php
// Dentro de NpsController::invalidarResposta() / revalidarResposta(), APÓS
// persistir o flag — senão o bônus de um mês FECHADO fica errado no
// /performance por até 7 dias (TTL documentado em DesempenhoScoreService::
// computeCached(), linha ~197: `now()->addDays(7)` para mês passado).
$mesCompletado = $response->survey->completed_at?->startOfMonth();
if ($mesCompletado) {
    $userIds = \App\Models\NpsScoreAssignment::where('nps_response_id', $response->id)
        ->pluck('user_id')
        ->unique();
    foreach ($userIds as $userId) {
        \Illuminate\Support\Facades\Cache::forget(
            sprintf('desempenho.compute.v4.%d.%s', $userId, $mesCompletado->format('Y-m'))
        );
    }
}
```
*A versão `v4` do cache key deve ser lida do código em execução no momento do plano (o projeto já fez bump v1→v2→v3→v4 três vezes por motivos parecidos — confirmar o número atual em `DesempenhoScoreService::computeCached()` antes de codar, não hardcode cego). Nota: `NpsScoreAssignment` só existe para respostas v15 com template — para respostas LEGADAS (sem template_id, sem assignment), a nota só afeta o ramo `notasLegado()`/dashboards, que já são recalculados live (sem cache) a cada request — não há bônus cacheado a invalidar nesse caso.*

### Trilha de invalidação — activitylog explícito (sem tocar em `NpsResponse`)

`NpsResponse` **não tem** `LogsActivity` hoje (confirmado lendo o model completo). Duas opções:
1. Adicionar `LogsActivity` + `getActivitylogOptions()->logOnly(['invalidated_at','invalidated_by'])->logOnlyDirty()` — replica o molde de `NpsSurvey`.
2. Chamada explícita `activity()->...->log()` dentro do controller, SEM tocar no model — molde já usado em `MarkCustIdStatus::class` (`app/Console/Commands/MarkCustIdStatus.php:272-280`) e `NotificacaoController` (`app/Http/Controllers/NotificacaoController.php:264-273`).

**Recomendação: opção 2.** Motivo — `LogsActivity` com `logOnlyDirty()` ainda dispara o evento `created` no `NpsResponse::create()` do submit normal (toda resposta, mesmo não-invalidada, geraria uma linha `activity_log` vazia/de criação, poluindo a auditoria com centenas de eventos irrelevantes por mês). A chamada explícita só loga a ação de invalidar/revalidar, exatamente como CONTEXT pede ("sem poluir"):

```php
// app/Http/Controllers/NpsController.php — novo método
public function invalidarResposta(Request $request, NpsSurvey $survey)
{
    abort_unless($request->user()->isAdmin(), 403);

    if (!$survey->response) {
        return back()->with('error', 'Esta pesquisa ainda não foi respondida.');
    }
    if ($survey->response->invalidated_at) {
        return back()->with('error', 'Esta resposta já está invalidada.');
    }

    $survey->response->update([
        'invalidated_at' => now(),
        'invalidated_by' => $request->user()->id,
    ]);

    activity()
        ->causedBy($request->user())
        ->performedOn($survey->response)
        ->withProperties(['survey_id' => $survey->id, 'company_id' => $survey->company_id])
        ->log('Resposta NPS invalidada');

    // ... cache-busting (ver acima) ...

    return back()->with('success', 'Resposta invalidada — não conta mais em dashboards nem no bônus.');
}

public function revalidarResposta(Request $request, NpsSurvey $survey)
{
    abort_unless($request->user()->isAdmin(), 403);

    if (!$survey->response || !$survey->response->invalidated_at) {
        return back()->with('error', 'Esta resposta não está invalidada.');
    }

    $survey->response->update(['invalidated_at' => null, 'invalidated_by' => null]);

    activity()
        ->causedBy($request->user())
        ->performedOn($survey->response)
        ->withProperties(['survey_id' => $survey->id, 'company_id' => $survey->company_id])
        ->log('Resposta NPS revalidada');

    // ... mesmo cache-busting ...

    return back()->with('success', 'Resposta revalidada — volta a contar normalmente.');
}
```

### Recommended Project Structure

Nenhum arquivo/diretório novo de infraestrutura — só extensões dos arquivos já mapeados:
```
app/
├── Http/Controllers/NpsController.php   # + submitResponse() bloqueio, + invalidarResposta()/revalidarResposta(), + atualizarIpsInternos()
├── Models/NpsResponse.php               # + fillable invalidated_at/invalidated_by, + scopeValida()
├── Models/NpsSurveyEvent.php            # + TYPE_BLOCKED + entrada em TYPES
├── Services/Nps/NpsSuspicionService.php # isInternalIp() lê Configuracao também
├── Services/DesempenhoScoreService.php  # notasPorAtribuicao()/notasLegado() filtram invalidated_at
├── Http/Controllers/PerformanceController.php  # notasNpsDoUsuarioPorResposta() filtra
├── Http/Controllers/DashboardController.php    # 3 call-sites filtram (home/userDashboard/buildRanking)
├── Http/Controllers/PortfolioController.php     # "Histórico NPS mensal" filtra (implementação própria, separada do PerformanceController)
└── Jobs/CalculateGoalResults.php        # computeNps() filtra

database/migrations/
├── 2026_07_17_..._add_invalidation_to_nps_responses_table.php
└── 2026_07_17_..._add_blocked_event_type_to_nps_survey_events.php  (enum ALTER com branch SQLite)

resources/js/Pages/Nps/
├── Blocked.jsx           # novo — molde Expired.jsx/AlreadyCompleted.jsx
├── Configuracao.jsx      # + widget IPs internos (molde DiaCobrancaWidget)
└── Index.jsx             # + botão Invalidar/Revalidar no DialogFooter (ao lado de "Excluir resposta")
```

### Anti-Patterns to Avoid

- **Filtrar a listagem paginada (`$surveys`) de `NpsController::index()`:** o admin PRECISA ver a resposta invalidada para poder gerenciá-la (revalidar, ler o motivo). Só as agregações (cards/série/bônus) filtram.
- **Recalcular/apagar `nps_response_scores`/`nps_score_assignments` na invalidação:** quebra a garantia de "congelamento" da Fase 79 e destrói a reversibilidade.
- **Adicionar `LogsActivity` genérico ao `NpsResponse`:** polui `activity_log` com um evento `created` por resposta legítima (centenas/mês) — usar chamada explícita `activity()->log()` só na ação de invalidar/revalidar.
- **Esquecer o `Cache::forget()` do bônus:** a invalidação parecerá "não ter feito nada" para um mês fechado por até 7 dias — achado crítico desta pesquisa, sem ele o REQ AB-96-3 fica quebrado silenciosamente para dados históricos.
- **Reverter `NpsSurvey.status` para `pending` na invalidação:** ver Pitfall "hasOne ambíguo" abaixo — isso reabre uma superfície de bug que `excluirResposta()` (ação DIFERENTE e já existente) já assume conscientemente, mas que a invalidação NÃO deveria herdar.
- **Pular o SQLite no ALTER do enum `event_type`:** o projeto já cometeu esse erro uma vez (migration de `'polos'`) e documentou como "armadilha latente" — sempre seguir o padrão de branch por driver.
- **Assumir que `PortfolioController` e `PerformanceController` compartilham a mesma função de agregação NPS:** confirmado nesta pesquisa que NÃO compartilham — são 2 correções separadas (call-sites #4 e #8).

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Matching de IP/CIDR (v4 e v6) | Regex customizado de IP/CIDR | `Symfony\Component\HttpFoundation\IpUtils::checkIp()` (já em uso na Fase 94) | Já testado, já é dependência transitiva do `laravel/framework`, cobre IPv4 e IPv6 corretamente |
| Trilha de "quem fez o quê" | Coluna `invalidated_by_name`/log manual em tabela própria | `spatie/laravel-activitylog` (já instalado, já é o padrão do projeto) | Reaproveita `activity_log` existente, já tem UI-friendly retrieval (`->causedBy()`, `->properties`) |
| Config chave/valor pequena editável por admin | Tabela dedicada `nps_settings` | `App\Models\Configuracao` (já existe, já é o padrão de `nps_dia_cobranca`/`nps_textos`) | Zero migration nova de schema para a config em si — só as 2 chaves |

**Key insight:** esta fase não introduz NENHUM problema novo de engenharia — é 100% reaproveitamento de padrões já validados em produção neste mesmo módulo NPS. O único trabalho genuinamente novo é o MAPEAMENTO exaustivo de call-sites (Padrão 3 acima), que é trabalho de investigação, não de construção de infraestrutura.

## Common Pitfalls

### Pitfall 1: Cache do bônus mascara a invalidação por até 7 dias
**What goes wrong:** admin invalida uma resposta de um mês fechado, mas `/performance` continua mostrando o bônus antigo.
**Why it happens:** `DesempenhoScoreService::computeCached()` usa `Cache::remember()` com TTL de 7 dias para meses passados (`now()->addDays(7)`, linha ~197) — o comentário do próprio código diz "cache é invalidado naturalmente pelo TTL... não precisa invalidar explicitamente", o que é verdade para os cenários que o código previa (mudança de lógica de cálculo), mas FALSO para invalidação de dado pontual.
**How to avoid:** `Cache::forget()` explícito no momento da invalidação/revalidação, para cada `user_id` afetado (via `NpsScoreAssignment.user_id` da resposta) e o mês de `completed_at` — ver Code Examples acima.
**Warning signs:** teste manual "invalidei, mas o número do bônus não mudou" — sintoma direto deste pitfall.

### Pitfall 2: `NpsSurvey::response()` é `hasOne` sem ordenação — reabrir para nova resposta é ambíguo
**What goes wrong:** se a invalidação reverter `status` para `'pending'` (copiando o comportamento de `excluirResposta()`) SEM apagar a `NpsResponse` antiga, o schema permite (não há `unique(survey_id)` em `nps_responses` — confirmado via grep nas migrations) que uma SEGUNDA resposta seja criada para o mesmo survey. `NpsSurvey::response()` (`hasOne`, sem `->latest()`) passaria a retornar uma linha ambígua entre as duas.
**Why it happens:** a decisão de design de `excluirResposta()` (linha 1397-1409 do controller) assume implicitamente que só existe 1 resposta por vez porque ELA APAGA a antiga antes de reabrir. A invalidação, por design, NÃO apaga — então herdar o "volta pra pending" quebraria essa invariante silenciosa.
**How to avoid:** a invalidação NÃO deve tocar em `NpsSurvey.status`/`completed_at`. Mantém `status='completed'` — o survey continua "tecnicamente respondido", só o CONTEÚDO da resposta é que passa a ser desconsiderado nas agregações. Se o negócio quiser uma resposta NOVA e legítima, o caminho é gerar um novo link (`generate()`), não reabrir o mesmo survey.
**Warning signs:** 2 linhas em `nps_responses` para o mesmo `survey_id`; `$survey->response` retornando ora uma ora outra dependendo da query.

### Pitfall 3: ALTER de enum em `nps_survey_events.event_type` quebra o SQLite dos testes
**What goes wrong:** `SQLSTATE[23000]: CHECK constraint failed: event_type` em qualquer Feature test que tente persistir `event_type='blocked'`, se a migration só fizer `ALTER TABLE ... MODIFY COLUMN` (sintaxe MySQL) sem branch para SQLite.
**Why it happens:** memória documentada do projeto (`project_enum_setor_sqlite_check.md`) — o SQLite dos testes ENFORÇA o CHECK constraint que o Laravel gera para `$table->enum(...)`, ao contrário do que a migration original de `'polos'` assumiu (pulou o SQLite e só não quebrou por sorte, porque nenhum teste exercitava aquele valor).
**How to avoid:** seguir EXATAMENTE o padrão já usado 2x neste projeto (`add_shopee_to_servicos_setor_enum.php` e `alter_nps_template_questions_tipo_add_texto_livre.php`): `if (driver === 'mysql') { DB::statement("ALTER TABLE ... MODIFY COLUMN event_type ENUM(...) ..."); } else { $table->string('event_type', 20)->change(); }`.
**Warning signs:** teste que cria `NpsSurveyEvent::factory()->create(['event_type' => 'blocked'])` falhando só em CI/local (SQLite), passando em staging/produção (MySQL) — sintoma clássico de migration que pulou o branch.

### Pitfall 4: Esquecer 1 dos 8 call-sites deixa a resposta invalidada "meio-contando"
**What goes wrong:** bônus zera a nota mas o widget "últimas 4 respostas" (`PerformanceController`) ou o card de média mensal (`NpsController::index()`) ainda mostra a resposta suspeita — o admin perde confiança na feature ("eu invalidei, por que ainda aparece ali?").
**Why it happens:** não existe uma camada única de "agregação NPS" no codebase — cada tela reimplementa sua própria leitura dual-path (atribuições + legado `->principal()`), historicamente por boas razões de isolamento por serviço (Fase 80, DEC-80-D), mas isso significa que qualquer nova regra de exclusão (como invalidação) precisa ser replicada manualmente em cada um.
**How to avoid:** usar a tabela de 8 call-sites (Padrão 3) como checklist de verificação obrigatória no plano/execução — cada um deve ter um teste de regressão dedicado provando que uma resposta com `invalidated_at` preenchido não aparece no resultado.
**Warning signs:** qualquer PR/plano que adicione a coluna `invalidated_at` sem tocar em pelo menos 5 arquivos (`DesempenhoScoreService`, `PerformanceController`, `DashboardController`, `PortfolioController`, `CalculateGoalResults`) quase certamente deixou algum call-site descoberto.

### Pitfall 5: Respostas LEGADAS (sem template) não têm `NpsScoreAssignment` — cache-busting não se aplica a elas
**What goes wrong:** ao invalidar uma resposta legada (pré-Fase 69, `template_id=null`), o código de cache-busting baseado em `NpsScoreAssignment::where('nps_response_id', ...)` não encontra nada e não busta cache nenhum — mas isso é CORRETO, não um bug, porque respostas legadas só afetam `notasLegado()`/dashboards, que são recalculados live a cada request (sem `Cache::remember`).
**Why it happens:** `NpsSnapshotService::registrar()` retorna cedo (`if (! $survey->template_id) return;`) para surveys sem template — nenhum snapshot é gerado, logo nenhuma atribuição existe para essas respostas.
**How to avoid:** documentar explicitamente esse comportamento no teste de cache-busting (asserir que invalidar resposta legada NÃO precisa de `Cache::forget` para produzir o efeito correto) — evita que um revisor futuro "corrija" isso como se fosse um bug.
**Warning signs:** nenhum — é o comportamento correto; incluído aqui só para não gerar confusão durante a implementação/revisão.

## Code Examples

### Migration — invalidação em `nps_responses` (padrão idempotente + nullable do projeto)
```php
<?php
// database/migrations/2026_07_17_..._add_invalidation_to_nps_responses_table.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('nps_responses', 'invalidated_at')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->timestamp('invalidated_at')->nullable()->after('suspicion_reasons');
                // nullable() ANTES de nullOnDelete() — evita erro 1830 MariaDB
                // (padrão já documentado nas migrations da Fase 94/79).
                $table->foreignId('invalidated_by')
                    ->nullable()
                    ->after('invalidated_at')
                    ->constrained('users')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('nps_responses', 'invalidated_at')) {
            Schema::table('nps_responses', function (Blueprint $table) {
                $table->dropForeign(['invalidated_by']);
                $table->dropColumn(['invalidated_at', 'invalidated_by']);
            });
        }
    }
};
```

### Migration — 7º event_type `blocked` (branch por driver, molde comprovado no projeto)
```php
<?php
// database/migrations/2026_07_17_..._add_blocked_event_type_to_nps_survey_events.php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            // SQLite (testes): CHECK é enforçado — recria como string sem CHECK,
            // mesmo padrão de add_shopee_to_servicos_setor_enum.php.
            Schema::table('nps_survey_events', function (Blueprint $table) {
                $table->string('event_type', 20)->change();
            });
            return;
        }

        DB::statement(
            "ALTER TABLE nps_survey_events MODIFY COLUMN event_type "
            . "ENUM('generated','opened','submitted','expired','sent_email','sent_digisac','blocked') NOT NULL"
        );
    }

    public function down(): void
    {
        $orfaos = DB::table('nps_survey_events')->where('event_type', 'blocked')->count();
        if ($orfaos > 0) {
            throw new \RuntimeException("[Migration rollback] {$orfaos} eventos 'blocked'. Apague antes do rollback.");
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            return; // mantém string sem CHECK
        }

        DB::statement(
            "ALTER TABLE nps_survey_events MODIFY COLUMN event_type "
            . "ENUM('generated','opened','submitted','expired','sent_email','sent_digisac') NOT NULL"
        );
    }
};
```

### `NpsSurveyEvent` model — novo constante
```php
// app/Models/NpsSurveyEvent.php
public const TYPE_BLOCKED = 'blocked';

public const TYPES = [
    self::TYPE_GENERATED,
    self::TYPE_OPENED,
    self::TYPE_SUBMITTED,
    self::TYPE_EXPIRED,
    self::TYPE_SENT_EMAIL,
    self::TYPE_SENT_DIGISAC,
    self::TYPE_BLOCKED, // Fase 96 AB-96-1
];
```

## State of the Art

| Old Approach (Fase 94/95) | Current Approach (Fase 96) | When Changed | Impact |
|--------------|------------------|--------------|--------|
| Regra 4 (`isAuthenticatedSession`) só MARCA a resposta como suspeita | Regra 4 BLOQUEIA o submit inteiro | AB-96-1 | Nenhuma `NpsResponse`/snapshot é criada para sessão interna — mais forte que o "marca e deixa passar" anterior |
| IPs internos só via `.env` (exige deploy) | IPs internos editáveis pela UI, `.env` vira fallback | AB-96-2 | Admin não depende mais de acesso ao servidor para ajustar a lista |
| Nenhuma forma de "descontar" uma resposta suspeita das métricas | Flag `invalidated_at` + filtro em 8 call-sites | AB-96-3 | Primeira vez que o módulo NPS tem um mecanismo de correção pós-fato que respeita o congelamento da Fase 79 |

**Não há nada "deprecated" nesta fase** — tudo é aditivo sobre a fundação da Fase 94/95, sem remover nenhum comportamento existente.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | Recomendação de UNIÃO (∪, não substituição/precedência) entre IPs do `.env` e IPs da UI | Padrão 2 | Se o usuário/CONTEXT realmente quis "UI SOBREPÕE .env" (não soma), a implementação recomendada ficaria mais permissiva do que o esperado — mas como o pior caso é "detectar suspeita a mais" (nunca "detectar a menos"), o risco de segurança é baixo; risco é só UX (falso positivo). Confirmar com o usuário/discuss se a soma é aceitável ou se precisa de um toggle "desativar .env" |
| A2 | `invalidated_by` referencia `users.id` com `nullOnDelete` (mesmo padrão de `generated_by`/outras FKs de auditoria do módulo NPS) | Code Examples (migration) | Baixo — é o padrão universal do projeto para "quem fez X", nenhuma razão para divergir aqui |
| A3 | O cache key do bônus está na versão `v4` no momento desta pesquisa (`desempenho.compute.v4.*`) | Padrão 3 (cache-busting) | Se outra fase já tiver feito bump para v5+ antes da execução da Fase 96, o código de `Cache::forget()` precisa usar o número ATUAL — o planner/executor deve reler `DesempenhoScoreService::computeCached()` no momento da implementação, não confiar cegamente neste documento |

**Nenhuma outra claim deste documento foi tagueada `[ASSUMED]`** — todo o resto (incluindo a distinção `PortfolioController` vs `PerformanceController`, corrigida durante esta própria sessão de pesquisa) foi verificado lendo o código-fonte diretamente (controllers, models, migrations, services, testes, config).

## Open Questions

1. **Reverter `NpsSurvey.status` na invalidação?**
   - What we know: `excluirResposta()` (ação EXISTENTE, diferente) reverte para `'pending'` e permite nova resposta; a invalidação, por design (flag, não delete), NÃO deveria fazer isso (ver Pitfall 2 — `hasOne` sem ordenação vira ambíguo com 2 respostas).
   - What's unclear: se o negócio ESPERA que uma empresa com resposta invalidada volte a aparecer como "pendente" no `NpsPendingService` (que só olha `status='completed'`, não olha `invalidated_at`).
   - Recommendation: manter `status='completed'` intocado na invalidação (documentado como decisão no plano); se o negócio quiser reabrir para nova resposta, isso é um fluxo separado (gerar novo link) — não uma consequência automática de invalidar. Levar essa pergunta explicitamente para o planner/discuss se o CONTEXT não a resolver.

2. **Motivo/comentário livre na invalidação?**
   - What we know: CONTEXT pede só "trilha de quem invalidou e quando" — o `activity()->withProperties()` já registra isso; a Fase 95 já expõe os `motivos` de suspeita automática no modal.
   - What's unclear: se o admin precisa digitar um motivo textual próprio (ex.: "confirmei por telefone que não foi o cliente que respondeu") além dos motivos automáticos já exibidos.
   - Recommendation: campo opcional de texto curto no PATCH de invalidação, gravado em `withProperties(['motivo' => ...])` do activity log (sem coluna nova em `nps_responses` — não é dado de negócio, é metadado de auditoria). Nice-to-have, não bloqueante — decidir no planejamento/discuss se entra no escopo mínimo.

3. **União vs precedência entre `.env` e UI para IPs internos (AB-96-2)?**
   - What we know: CONTEXT usa a notação "UI ∪/precedência sobre `.env`" — ambíguo entre as duas opções.
   - What's unclear: se o admin espera poder DESATIVAR um IP que está fixo no `.env` (precedência/substituição) ou só ADICIONAR novos (união).
   - Recommendation: união (∪) por ser estritamente mais segura (defesa em profundidade) e mais simples de implementar/testar — ver Assumption A1.

## Environment Availability

Não aplicável — esta fase não introduz dependência de ferramenta/serviço externo além do que já roda em produção (PHP 8.2+, MySQL/MariaDB, mesmo stack de sempre).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` |
| Quick run command | `php artisan test --filter=Nps` (baseline atual: 264/264 verde, pós-Fase 95) |
| Full suite command | `php artisan test --filter=Nps` + `php artisan test --filter=V16` (regressão do bônus/atribuições — `BonusAtribuicoesNpsTest`, `BonusDualPathRegressaoTest`, `AtribuicaoPorServicoNpsTest`, `WidgetNpsAtribuicoesTest`) |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| AB-96-1 | Sessão autenticada é bloqueada no POST, evento `blocked` emitido, `NpsResponse` NÃO criada | Feature | `php artisan test --filter=NpsBloqueioSessaoInternaTest` | ❌ Wave 0 |
| AB-96-1 | GET continua permitido para sessão interna (regressão do comportamento Fase 94) | Feature | `php artisan test --filter=NpsOpenTrailTest` | ✅ (já existe, Fase 94) |
| AB-96-2 | PATCH persiste IPs/CIDRs via `Configuracao`, validação rejeita formato inválido | Feature | `php artisan test --filter=NpsConfiguracaoIpsInternosTest` | ❌ Wave 0 |
| AB-96-2 | `NpsSuspicionService` detecta IP cadastrado só pela UI (sem `.env`) | Unit/Feature | `php artisan test --filter=NpsSuspicionServiceTest` (estender o existente) | ✅ existe, precisa de novos cenários |
| AB-96-3 | Invalidar resposta: flag persistida, activity log gravado, resposta some dos 8 call-sites | Feature | `php artisan test --filter=NpsInvalidacaoRespostaTest` | ❌ Wave 0 |
| AB-96-3 | Bônus (`DesempenhoScoreService::compute()`) não conta resposta invalidada | Feature | `php artisan test --filter=BonusDualPathRegressaoTest` (estender) | ✅ existe, precisa de novos cenários |
| AB-96-3 | `PortfolioController`/`PerformanceController`/`DashboardController` widgets não mostram resposta invalidada | Feature | novos cenários dedicados por controller | ❌ Wave 0 |
| AB-96-3 | Cache do bônus é invalidado (`Cache::forget`) ao invalidar resposta de mês fechado | Feature | novo teste dedicado | ❌ Wave 0 |
| AB-96-3 | Revalidação restaura o efeito nas agregações | Feature | `php artisan test --filter=NpsInvalidacaoRespostaTest` (mesmo arquivo) | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Nps`
- **Per wave merge:** `php artisan test --filter=Nps` + `php artisan test --filter=V16`
- **Phase gate:** as duas suítes acima 100% verdes ANTES de `/gsd:verify-work` — a suíte V16 é OBRIGATÓRIA (não opcional) porque a invalidação mexe na fonte direta do bônus.

### Wave 0 Gaps
- [ ] `tests/Feature/Phase96/NpsBloqueioSessaoInternaTest.php` — cobre AB-96-1
- [ ] `tests/Feature/Phase96/NpsConfiguracaoIpsInternosTest.php` — cobre AB-96-2
- [ ] `tests/Feature/Phase96/NpsInvalidacaoRespostaTest.php` — cobre AB-96-3 (flag, activity log, os 8 call-sites, cache-busting, revalidação)
- [ ] Estender `tests/Feature/Phase94/NpsSuspicionServiceTest.php` com cenários de IP vindo só da `Configuracao` (UI)
- [ ] Estender `tests/Feature/V16/BonusDualPathRegressaoTest.php` com cenário "resposta invalidada não conta em nenhum dos dois ramos (A/B)"

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não (rota pública, sem login) | — |
| V3 Session Management | sim | `auth()->check()` reflete a sessão de cookie já validada pelo middleware `web`/`StartSession` — nenhuma lógica de sessão nova é criada, só uma LEITURA de estado já existente |
| V4 Access Control | sim | Ações de invalidação/config de IPs exclusivas de admin — `abort_unless($request->user()->isAdmin(), 403)` (padrão já usado em `excluirResposta`/`destroy`/`bulkDestroy`) + middleware `role:admin` no grupo de rotas |
| V5 Input Validation | sim | Validação de IP/CIDR via `filter_var(FILTER_VALIDATE_IP)` + regex CIDR (ver Code Examples) — nunca aceitar string livre não validada nas rotas de config |
| V6 Cryptography | não aplicável | nenhum dado sensível novo (IPs internos e flags de invalidação não são segredos) |
| V7 Error Handling / Logging | sim | Trilha de invalidação via `activity()` é exatamente o controle V7 (Repudiation) — mesma justificativa já usada para `nps_survey_events` na Fase 94 |

### Known Threat Patterns for este stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Admin mal-intencionado invalida respostas legítimas para inflar o próprio bônus | Repudiation / Tampering | `activity()->causedBy()` grava quem invalidou — auditável a posteriori; considerar (fora de escopo desta fase) alerta/relatório periódico de invalidações por admin |
| Usuário final tenta descobrir a regra de detecção via tentativa/erro na mensagem de bloqueio | Information Disclosure | Mensagem genérica em `Nps/Blocked.jsx` (ver Padrão 1) — não menciona IP, sessão, nem "detectamos que você é da ECF" |
| CSRF na rota PATCH de config de IPs / invalidação | Tampering | Rotas já protegidas pelo CSRF padrão do grupo `web` (o projeto só desabilita CSRF em `/implementacao/*`, confirmado no CLAUDE.md — as rotas NPS admin ficam dentro da proteção padrão) |

## Sources

### Primary (HIGH confidence — leitura direta do código-fonte nesta sessão)
- `app/Http/Controllers/NpsController.php` (1667 linhas, lido por completo)
- `app/Services/Nps/NpsSuspicionService.php`, `app/Services/Nps/NpsSnapshotService.php`
- `config/nps.php`, `app/Models/NpsSurveyEvent.php`, `app/Models/NpsSurvey.php`, `app/Models/NpsResponse.php`, `app/Models/NpsScoreAssignment.php`, `app/Models/Configuracao.php`
- `app/Services/DesempenhoScoreService.php` (métodos `computeCached`, `compute`, `computeNpsMedio`, `notasPorAtribuicao`, `notasLegado`)
- `app/Http/Controllers/PerformanceController.php`, `app/Http/Controllers/DashboardController.php`, `app/Http/Controllers/PortfolioController.php` (grep + leitura de trechos — dual-path de cada arquivo lido separadamente, confirmando que `PortfolioController` NÃO reusa `notasNpsDoUsuarioPorResposta` do `PerformanceController`), `app/Jobs/CalculateGoalResults.php`
- `database/migrations/2026_07_16_100001..100003_*.php` (Fase 94), `database/migrations/2026_07_14_200001_create_nps_snapshot_tables.php` (Fase 79)
- `database/migrations/2026_07_14_100001_add_shopee_to_servicos_setor_enum.php`, `database/migrations/2026_07_13_101151_alter_nps_template_questions_tipo_add_texto_livre.php` (padrão enum+SQLite)
- `resources/js/Pages/Nps/Configuracao.jsx`, `resources/js/Pages/Nps/Index.jsx`, `resources/js/Pages/Nps/Expired.jsx`, `resources/js/Pages/Nps/AlreadyCompleted.jsx`
- `app/Console/Commands/MarkCustIdStatus.php`, `app/Http/Controllers/NotificacaoController.php` (padrão `activity()` explícito)
- `.planning/phases/94-.../94-01-SUMMARY.md`, `94-02-SUMMARY.md`, `.planning/phases/95-.../95-01-SUMMARY.md`
- `.planning/phases/96-.../96-CONTEXT.md`, `.planning/ROADMAP.md`

### Secondary (MEDIUM confidence)
- Memória do projeto `project_enum_setor_sqlite_check.md` (2 dias, cross-verificada com as 2 migrations reais que a implementam)
- Memória do projeto `project_nps_modelo_principal.md` (cross-verificada lendo `->principal()`/`NpsScoreAssignment` diretamente)
- Memória do projeto `project_desempenho_compute_cache.md` (cross-verificada lendo `computeCached()`)

### Tertiary (LOW confidence)
- Nenhuma — toda a pesquisa desta fase foi feita por leitura direta de código-fonte, sem WebSearch (fase 100% interna ao codebase, sem dependência de doc externa).

## Metadata

**Confidence breakdown:**
- Standard stack: HIGH — nenhuma dependência nova, tudo lido diretamente do `composer.json`/código já em produção
- Architecture: HIGH — os 3 padrões recomendados são réplicas de padrões já rodando em produção neste mesmo módulo (não são hipóteses)
- Pitfalls: HIGH — todos os 5 pitfalls foram confirmados lendo código real (cache TTL, hasOne sem ordenação, enum+SQLite, call-sites, dual-path legado sem assignment) — nenhum pitfall depende de suposição não verificada

**Research date:** 2026-07-17
**Valid until:** 2026-08-16 (30 dias — código interno estável, sem dependência de API externa que possa mudar)
