# Fase 135: Onboarding Geral por Serviço — Mapa de Padrões

**Mapeado em:** 2026-08-11
**Arquivos analisados:** ~28 (5 migrations, 5 models, 1 observer, 1 registry de resolvers com 2 contracts + 2 factories,
2-3 services, 3-4 controllers, 2-3 FormRequests, rotas, 3 páginas React + sub-componentes, 1 comando Artisan, 1 factory de teste)
**Análogos encontrados:** 26 / 28 (2 sem precedente direto — marcados explicitamente)

> **Escopo travado:** v1 cobre só o template de Gestão (D-08). O onboarding de Polos
> (`mlb_implementacoes`, `MlbImplementacaoController`, `/implementacao/*`) é **intocado**
> (D-02/SC-02) — citado aqui só como fonte de molde de FORMA, nunca como código a
> reaproveitar ou modificar.

---

## File Classification

| Novo/Modificado | Papel | Fluxo de dado | Análogo mais próximo | Qualidade |
|---|---|---|---|---|
| `database/migrations/..._create_onboarding_templates_table.php` | migration | CRUD (schema) | `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php:47-100` | role-match (versionamento é técnica nova, índice único parcial é exato) |
| `database/migrations/..._create_template_passos_table.php` | migration | CRUD (schema) | mesma migration acima (tabela 2, `nps_template_questions`) | role-match |
| `database/migrations/..._create_onboardings_table.php` | migration | CRUD (schema) | `database/migrations/2026_05_11_000001_create_mlb_implementacoes_table.php` (FK+token+json) | role-match |
| `database/migrations/..._create_onboarding_passos_table.php` | migration | CRUD (schema) | mesma migration acima + padrão JSON de `dados` | role-match |
| `database/migrations/..._create_onboarding_links_table.php` | migration | CRUD (schema) | `2026_05_11_000001_create_mlb_implementacoes_table.php` (coluna `token` unique) | exact (estrutura idêntica: 1 token por dono) |
| `app/Models/OnboardingTemplate.php` | model | CRUD | `app/Models/NpsTemplate.php` (não lido, mas mesmo papel) + `app/Models/Servico.php` (constantes/scopes) | role-match |
| `app/Models/TemplatePasso.php` | model | CRUD | `app/Models/ContratoServico.php` (pivot enriquecida com fillable/casts) | role-match |
| `app/Models/Onboarding.php` | model | CRUD | `app/Models/MlbImplementacao.php` (fillable + casts + JSON) | role-match |
| `app/Models/OnboardingPasso.php` | model | CRUD | `app/Models/MlbImplementacao.php` (cast `array` em coluna JSON) | role-match |
| `app/Models/OnboardingLink.php` | model | CRUD | `app/Models/MlbImplementacao.php` (coluna `token`) | role-match |
| `app/Observers/ContratoServicoObserver.php` | observer | event-driven | `app/Observers/MlbEmpresaObserver.php` (lido inteiro) | exact |
| `app/Models/ContratoServico.php` (edição — adicionar `#[ObservedBy]`) | model | event-driven | `app/Models/MlbEmpresa.php:1-16` | exact |
| `app/Contracts/OnboardingResolver.php` (interface do catálogo D-09) | contract | strategy/registry | `app/Contracts/SugadoresAdsProvider.php` + `app/Contracts/MetricsProvider.php` (lidos inteiros) | exact |
| `app/Services/Onboarding/Resolvers/*.php` (5 classes: AdmanAccountId, AdmanGrant, MlTokenAtivo, AnunciosAtivosInativos, MetricasConta) | service (strategy) | request-response / job-backed | implementações concretas dos Contracts acima (`AdmanSugadoresProvider`, `AdmanMetricsProvider` — mesmo papel) | role-match |
| `app/Services/Onboarding/OnboardingResolverFactory.php` (catálogo fechado) | service (factory) | registry | `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (lido inteiro) | exact |
| `app/Services/Onboarding/OnboardingEngineService.php` (criar onboarding do template, avaliar dependências, transicionar status) | service | orquestração | `app/Services/AdmanService.php` (estrutura de service com métodos por responsabilidade) + `app/Console/Commands/DiagnoseCustId.php` (padrão de classificação por resultado) | role-match |
| `app/Jobs/ResolveOnboardingPassoJob.php` (resolver Adman/ML assíncrono) | job | event-driven / async | `app/Jobs/SyncAdmanCompanyJob.php` (molde completo lido no RESEARCH) | exact |
| `app/Console/Commands/OnboardingReavaliarPassos.php` (reavaliação periódica dos passos "aguardando coleta") | command | batch | `app/Console/Commands/WarmDesempenhoCache.php` (lido — comando batch agendado, `--user=*`, throttle por item) | role-match |
| `app/Http/Controllers/OnboardingController.php` (painel operacional) | controller | request-response | `app/Http/Controllers/ServicoController.php` (lido inteiro — CRUD simples) + estrutura de listagem agregada de `NpsTemplateController::index` | role-match |
| `app/Http/Controllers/OnboardingTemplateController.php` (CRUD admin) | controller | CRUD | `app/Http/Controllers/NpsTemplateController.php` (lido — `index`/`store` com invariantes canônicos) | exact |
| `app/Http/Controllers/OnboardingPublicoController.php` (portal cliente por token) | controller | request-response, sem auth | `app/Http/Controllers/MlbImplementacaoController.php::gerarLink/workspace` (lido, D-02 = só forma) | role-match (forma, não reuso de código) |
| `app/Http/Requests/StoreOnboardingTemplateRequest.php` / `UpdateOnboardingTemplateRequest.php` (com guarda de ciclo) | FormRequest | validação | `app/Http/Requests/UpdateBonusFaixaRequest.php` (lido inteiro — `withValidator` com regra composta) | exact |
| `routes/web.php` (grupo `/onboarding*` admin + interno) | rotas | request-response | `routes/web.php:157-219` (grupo `role:admin` do NPS) + `routes/web.php:773-778` (`Route::resource('servicos', ...)`) | exact |
| `routes/web.php` (prefixo público novo, ex. `onboarding-cliente/{token}`) | rotas | request-response, sem CSRF | `routes/web.php:90-95` + `bootstrap/app.php:21-24` | exact |
| `resources/js/Pages/Onboarding/Painel.jsx` (Tela 1) | página React | request-response | `resources/js/Pages/Nps/Configuracao.jsx` (estrutura de página com sub-componentes) — **forma**, não `Polos/Painel.jsx` (D-02) | role-match |
| `resources/js/Pages/Onboarding/Templates/Index.jsx` (Tela 2) | página React | CRUD | `resources/js/Pages/Nps/Configuracao.jsx` (lido — duas telas `list`/`edit`) | exact |
| `resources/js/Pages/Onboarding/Publico.jsx` (Tela 3) | página React | request-response, sem auth | `resources/js/Pages/Mlb/ImplementacaoPublica.jsx` (lido — `ProgressHeader`) + `resources/js/Pages/Nps/Expired.jsx` (lido — estado de erro) | role-match (forma) |
| `database/factories/ContratoServicoFactory.php` (gap Wave 0) | factory | test fixture | `database/factories/CompanyFactory.php` (lido inteiro) | role-match |
| `tests/Feature/Phase135/*.php` | test | feature | `tests/Feature/Phase112HubspotHandoffWebhookTest.php` (citado no RESEARCH, não lido nesta passada) | role-match |

---

## Pattern Assignments

### 1. Registry/Factory de resolvers automáticos (D-09) — a peça mais valiosa

**Análogos (lidos inteiros):**
- `app/Contracts/SugadoresAdsProvider.php` (interface, 134 linhas)
- `app/Contracts/MetricsProvider.php` (interface, 85 linhas)
- `app/Services/Sugadores/SugadoresAdsProviderFactory.php` (factory, 73 linhas)
- `app/Services/Metrics/MetricsProviderFactory.php` (factory, 107 linhas)

O projeto já resolveu **exatamente** o problema de D-09 duas vezes — "escolher implementação por chave, catálogo fechado, sem texto livre". O padrão canônico é **Contract (interface) + implementações concretas + Factory injetada por DI que resolve por regra fixa**, nunca por string arbitrária vinda do request.

**Contract — trecho real** (`app/Contracts/SugadoresAdsProvider.php:29-49`):
```php
interface SugadoresAdsProvider
{
    /**
     * Retorna true se este provider sabe lidar com a empresa.
     */
    public function supports(Company $company): bool;

    /**
     * Identificador estável do provider para logs/relatórios/dry-run output.
     * Valores conhecidos: 'adman', 'ml'. Não traduzir.
     */
    public function name(): string;

    public function fetchCampaigns(Company $company): array;
    // ... demais métodos do contrato normalizado
}
```

**Factory — trecho real** (`app/Services/Sugadores/SugadoresAdsProviderFactory.php:32-72`):
```php
class SugadoresAdsProviderFactory
{
    public function __construct(
        private AdmanSugadoresProvider $admanProvider,
        private MercadoLivreSugadoresProvider $mlProvider,
    ) {}

    public function for(Company $company, ?string $forceName = null): SugadoresAdsProvider
    {
        if ($forceName === 'adman') {
            return $this->admanProvider;
        }
        if ($forceName === 'ml') {
            return $this->mlProvider;
        }
        if ($this->mlProvider->supports($company)) {
            return $this->mlProvider;
        }
        if ($this->admanProvider->supports($company)) {
            return $this->admanProvider;
        }
        throw new \RuntimeException(
            "Empresa {$company->id} sem provider compatível "
            . '(sem adman_account_id e sem mlToken ativo).'
        );
    }
}
```

**Como adaptar para D-09 (5 resolvers automáticos, não 2 alternativos da mesma empresa):**

A diferença estrutural: Sugadores/Metrics resolvem **qual provider** atende uma empresa (escolha exclusiva/alternativa). O catálogo `auto_fonte` de D-09 é diferente — não é "escolher 1 entre N candidatos", é "cada `template_passos.auto_fonte` aponta para EXATAMENTE 1 resolver por chave fixa" (ex.: `adman_account_id_preenchido`, `adman_grant_ativo`, `ml_token_ativo`, `acervo_coletado`, `metricas_conta`). O molde certo aqui é mais próximo de um **registry por chave** (`array<string, class-string<OnboardingResolver>>` resolvido via `app()->make()`) do que de um factory de fallback — mas a FORMA (interface enxuta com `supports()`-like + método de execução + `name()` estável para logs/UI) é a mesma dos dois exemplos acima. Sugestão de shape:

```php
// app/Contracts/OnboardingResolver.php — molde direto de SugadoresAdsProvider acima
interface OnboardingResolver
{
    public function chave(): string; // bate 1:1 com template_passos.auto_fonte
    public function label(): string; // rótulo legível pro Select da Tela 2 (D-09 UI)
    public function resolver(Onboarding $onboarding, OnboardingPasso $passo): OnboardingResolverResultado;
}

// app/Services/Onboarding/OnboardingResolverFactory.php — molde de SugadoresAdsProviderFactory
class OnboardingResolverFactory
{
    /** @var array<string, OnboardingResolver> */
    private array $porChave;

    public function __construct(iterable $resolvers) // binding explícito no AppServiceProvider
    {
        foreach ($resolvers as $r) { $this->porChave[$r->chave()] = $r; }
    }

    public function for(string $autoFonte): OnboardingResolver
    {
        return $this->porChave[$autoFonte]
            ?? throw new \RuntimeException("auto_fonte '{$autoFonte}' sem resolver registrado — catálogo fechado (D-09).");
    }
}
```

O `OnboardingResolverResultado` deve ter 3 estados (D-11): `concluido` / `nao_coletado` / `indeterminado` — nunca um `bool` — para poder distinguir "zero real" de "ainda não coletou" de "429/timeout, tenta de novo depois" (mesma distinção 3-estados que `DiagnoseCustId::validarViaAdman()` já usa com `CAT_VALIDADO_API` / erro 400-404-500 / erro indefinido).

**Sonda Adman (D-18, passo 4) — trecho real** (`app/Console/Commands/DiagnoseCustId.php:288-315`, já adaptado para retorno em vez de print no `135-RESEARCH.md`):
```php
private function validarViaAdman(?string $custId, string $data, string $marketplace = 'meli'): string
{
    if (!$custId) {
        return self::CAT_OK;
    }
    try {
        $this->adman->fetchPerformance($custId, $data, $data, 3, $marketplace);
        $categoria = self::CAT_VALIDADO_API; // grant ativo — independe de haver movimento no dia
    } catch (\Throwable $e) {
        $msg = $e->getMessage();
        if (str_contains($msg, '500') || str_contains($msg, '400') || str_contains($msg, '404')) {
            $categoria = self::CAT_INVALIDO_CONFIRMADO; // Adman não reconhece o cust_id
        } else {
            $categoria = self::CAT_ERRO_INDEFINIDO; // 429/timeout — indeterminado, não conclua
        }
    }
    usleep(7_000_000); // ADMAN_RATE_LIMIT_RPM = 10 — throttle sempre, mesmo em erro
    return $categoria;
}
```
Este é o resolver do passo 4 quase pronto — só trocar "imprimir" por "retornar `OnboardingResolverResultado`". **Não reimplementar a classificação — herdar exatamente esta.**

---

### 2. Observer em `ContratoServico` (D-13)

**Análogo (lido inteiro):** `app/Observers/MlbEmpresaObserver.php` + registro em `app/Models/MlbEmpresa.php:1-16`.

**Registro via atributo PHP** — trecho real (`app/Models/MlbEmpresa.php:1-16`):
```php
namespace App\Models;

use App\Observers\MlbEmpresaObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Traits\LogsActivity;

#[ObservedBy(MlbEmpresaObserver::class)]
class MlbEmpresa extends Model
{
    use LogsActivity;
    // ...
}
```
Em `ContratoServico`, adicionar exatamente este padrão: `use App\Observers\ContratoServicoObserver; use Illuminate\Database\Eloquent\Attributes\ObservedBy;` + `#[ObservedBy(ContratoServicoObserver::class)]` na classe (`app/Models/ContratoServico.php:17`).

**Hooks e guarda contra loop** — trecho real (`app/Observers/MlbEmpresaObserver.php:30-44`):
```php
class MlbEmpresaObserver
{
    public function created(MlbEmpresa $empresa): void
    {
        $this->propagarCustId($empresa);
    }

    public function updated(MlbEmpresa $empresa): void
    {
        if (! $empresa->wasChanged('cust_id')) {
            return; // guard: não roda em todo update, só quando o campo relevante mudou
        }
        $this->propagarCustId($empresa);
    }
    // ...
}
```
**Como evita recursão:** o molde atualiza um model DIFERENTE (`Publicacao`), então nunca re-dispara o próprio Observer. O `ContratoServicoObserver` para esta fase só deve **criar** `Onboarding`/`OnboardingPasso` (models diferentes de `ContratoServico`) — não precisa de `saveQuietly()`. Só usar `saveQuietly()` se algum dia o Observer precisar reescrever o próprio `ContratoServico` (não é o caso do desenho atual).

**Auditoria** — trecho real (`app/Observers/MlbEmpresaObserver.php:76-83`):
```php
activity('mlb')
    ->withProperties([...])
    ->log("Cust ID propagado para {$afetadas} publicação(ões) da empresa \"{$empresa->nome}\"");
```
Usar `activity('onboarding')->withProperties([...])->log(...)` no Observer novo, mesma disciplina.

**Os 4 call-sites — trechos reais confirmados:**

1. `app/Http/Controllers/Api/HubspotWebhookController.php:842-861` (dentro de `DB::transaction()` aberta em `:513`):
```php
$contrato = ContratoServico::create([
    'company_id' => $company->id,
    'servico_id' => $c['servico_id'],
    'valor_contratado' => $c['valor_contratado'],
    'data_contratacao' => now()->toDateString(),
    'data_vencimento' => null,
    'ativo' => true,
    'observacoes' => $observacoes,
    // ... campos hubspot_*
]);
```

2. `app/Http/Controllers/ComercialController.php:669-678` (dentro de `DB::transaction()` aberta em `:643`):
```php
ContratoServico::create([
    'company_id'       => $company->id,
    'servico_id'       => $servico->id,
    'valor_contratado' => isset($item['valor_contratado']) ? (float) $item['valor_contratado'] : (float) $servico->valor_padrao,
    'data_contratacao' => now()->toDateString(),
    'data_vencimento'  => null,
    'ativo'            => true,
]);
```

3. `app/Http/Controllers/CompanyController.php:957-964` (SEM `DB::transaction()` visível — via relação):
```php
$company->contratosServico()->create([
    'servico_id'       => $data['servico_id'],
    'valor_contratado' => $data['valor_contratado'],
    'data_contratacao' => $data['data_contratacao'],
    'data_vencimento'  => $data['data_vencimento'] ?? null,
    'observacoes'      => $data['observacoes'] ?? null,
    'ativo'            => true,
]);
```

4. `app/Http/Controllers/CompanyGroupController.php:73-93` (LOOP sem `DB::transaction()` ao redor — risco de N onboardings numa request):
```php
foreach ($group->companies()->get() as $company) {
    $jaTem = ContratoServico::where('company_id', $company->id)
        ->where('servico_id', $data['servico_id'])
        ->where('ativo', true)->exists();
    if ($jaTem) { $pulados++; continue; }

    ContratoServico::create([
        'company_id' => $company->id, 'servico_id' => $data['servico_id'],
        'valor_contratado' => $data['valor_contratado'], 'data_contratacao' => $data['data_contratacao'],
        'data_vencimento' => $data['data_vencimento'] ?? null, 'observacoes' => $data['observacoes'] ?? null,
        'ativo' => true,
    ]);
    $criados++;
}
```
**Consequência de design (Pitfall 5 do RESEARCH):** o `ContratoServicoObserver::created()` deve ficar limitado a criar linhas de `onboardings`/`onboarding_passos` (barato, sem I/O de rede) — qualquer resolver de rede fica em Job disparado a partir daqui, nunca inline, porque este call-site em particular pode rodar N vezes numa única request sem transação.

**Responsável sugerido (D-17)** — trecho real (`app/Models/Company.php:249-266`):
```php
public function responsavelDoServicoOuConsolidado(string $role, int $servicoId): \Illuminate\Support\Collection
{
    $especificos = $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivot('servico_id', $servicoId)
        ->distinct('users.id')
        ->get();

    if ($especificos->isNotEmpty()) {
        return $especificos;
    }

    return $this->belongsToMany(User::class, 'company_users')
        ->wherePivot('role', $role)
        ->wherePivotNull('servico_id')
        ->distinct('users.id')
        ->get();
}
```
**Atenção (Open Question 2 do RESEARCH, não travada):** o `$role` correto para o onboarding de Gestão não está confirmado no código — é uma leitura (`'consultor'`), não um fato verificado. Confirmar antes de travar o resolver de responsável sugerido.

---

### 3. Migration — versionamento (D-07) + índice único parcial multi-driver

**Análogo mais próximo (não é precedente direto — a técnica de índice é reaproveitável, o schema de versionamento é novo):** `database/migrations/2026_07_07_100001_create_nps_templates_v15_tables.php:47-100`.

**Índice único parcial multi-driver — trecho real** (linhas 90-99):
```php
$driver = DB::connection()->getDriverName();
if ($driver === 'sqlite') {
    // SQLite 3.31+ suporta partial unique index direto.
    DB::statement("CREATE UNIQUE INDEX nps_templates_default_uniq ON nps_templates(is_default) WHERE is_default = 1");
} else {
    // MySQL 5.7+ / MariaDB 10.2+: coluna virtual gerada + unique nela.
    // CASE retorna NULL quando is_default=0; NULL não colide com NULL em unique.
    DB::statement("ALTER TABLE nps_templates ADD COLUMN is_default_key TINYINT GENERATED ALWAYS AS (CASE WHEN is_default = 1 THEN 1 END) VIRTUAL");
    DB::statement("ALTER TABLE nps_templates ADD UNIQUE INDEX nps_templates_default_uniq (is_default_key)");
}
```
Aplicar em `onboarding_templates` trocando `is_default` por `ativo` e o escopo por `WHERE ativo = 1` — mas para "1 versão ativa **por serviço**" (D-07), o índice precisa ser composto: `UNIQUE(servico_id) WHERE ativo = 1` no SQLite, e no MariaDB a coluna virtual precisa incluir `servico_id` na expressão gerada (`CASE WHEN ativo = 1 THEN servico_id END`) para que a unicidade seja por combinação, não global.

**Migration com `Schema::hasTable` como guarda de idempotência** — padrão confirmado em ~15 migrations do projeto, mesmo arquivo linha 47: `if (! Schema::hasTable('nps_templates')) { Schema::create(...) }`.

**Armadilha CONFIRMADA — não usar `UPDATE tabela alias SET` (JOIN-update, MySQL-only) em nenhuma migration nova.** Já derrubou a suíte SQLite uma vez (incidente Painel Polos/Decola, 2026-08-03, `.planning/learnings/painel-polos-status-e-meta.md`). Usar `DB::table(...)->update([...])` do Query Builder para qualquer backfill.

**Coluna JSON — trecho real** (`database/migrations/2026_05_11_000001_create_mlb_implementacoes_table.php:11-18`):
```php
Schema::create('mlb_implementacoes', function (Blueprint $table) {
    $table->id();
    $table->foreignId('empresa_id')->unique()->constrained('mlb_empresas')->cascadeOnDelete();
    $table->string('token', 64)->unique();
    $table->json('dados')->nullable();
    $table->timestamp('ultimo_acesso')->nullable();
    $table->timestamps();
});
```
Aplicar o mesmo padrão de `$table->json('valor')->nullable()` em `onboarding_passos` e `$table->json('condicao')->nullable()` em `template_passos`, com o cast espelhado no model (ver seção 5 abaixo).

**`onboarding_links` — molde de "1 token por dono" idêntico ao de `mlb_implementacoes`:**
```php
$table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete(); // 1 por EMPRESA (D-06)
$table->string('token', 64)->unique();
```

**SEM precedente no projeto:** versionamento numérico imutável (`versao` inteiro incremental + `publicado_em`, nova linha nunca `UPDATE` na antiga). É técnica nova — nenhuma migration existente faz "content versioning" por linha nova; o precedente mais próximo (`nps_templates`) edita in-place e congela a RESPOSTA via snapshot-por-linha, mecanismo diferente. **Escrever do zero**, reaproveitando só a técnica de índice único parcial acima.

---

### 4. Rota pública por token + CSRF desligado (D-06, Tela 3)

**Análogo (D-02: só forma, nunca reuso de código):** `app/Http/Controllers/MlbImplementacaoController.php` + `routes/web.php:90-95` + `bootstrap/app.php:21-24`.

**Geração de token** — trecho real (`MlbImplementacaoController.php:576-590`):
```php
public function gerarLink(Request $request, MlbEmpresa $empresa)
{
    $this->checkAccess($request);

    $impl = MlbImplementacao::firstOrCreate(
        ['empresa_id' => $empresa->id],
        [
            'token' => Str::random(48),
            'dados' => MlbImplementacao::dadosPadrao(),
        ]
    );

    $msg = $impl->wasRecentlyCreated ? 'Link de implementação gerado.' : 'Empresa já possui link de implementação.';
    return back()->with('success', $msg);
}
```
Para D-06 (1 link por EMPRESA, agregando serviços): `OnboardingLink::firstOrCreate(['company_id' => $company->id], ['token' => Str::random(48)])` — mesmíssima forma, trocando `empresa_id` (MlbEmpresa) por `company_id` (Company).

**Rota + método workspace público, sem auth** — trecho real (`MlbImplementacaoController.php:967-969`):
```php
public function workspace(string $token)
{
    $impl = MlbImplementacao::where('token', $token)->with('empresa')->firstOrFail();
    $impl->update(['ultimo_acesso' => now()]);
    // ... monta payload e Inertia::render('Mlb/ImplementacaoPublica', [...])
}
```
Registro de rota — trecho real (`routes/web.php:90-91`, fora de qualquer grupo `auth`):
```php
Route::get('/implementacao/{token}', [MlbImplementacaoController::class, 'workspace'])->name('implementacao.workspace');
Route::patch('/implementacao/{token}', [MlbImplementacaoController::class, 'salvarItem'])->name('implementacao.salvar');
```

**CSRF desligado** — trecho real (`bootstrap/app.php:21-24`):
```php
$middleware->validateCsrfTokens(except: [
    'implementacao/*',
    'api/webhooks/*',   // Phase 26 — receivers HMAC (ECF Drive em /api/webhooks/ecf; futuros parceiros entram aqui)
]);
```
**Ação exigida:** adicionar uma entrada NOVA e DISTINTA neste array para o prefixo do motor novo (ex. `'onboarding-cliente/*'`) — **nunca reutilizar `implementacao/*`**, que é exclusivo do Polos (D-02).

**Sem expiração de token** — nenhuma coluna `expires_at` em `mlb_implementacoes` (7 migrations incrementais revisadas). Risco já aceito no precedente; herdar o mesmo (não é regressão desta fase se o motor novo também nascer sem expiração).

---

### 5. Coluna JSON — cast em model

**Análogo (lido):** `app/Models/MlbImplementacao.php:13-52`.
```php
protected $fillable = [
    'empresa_id', 'token', 'dados', 'ultimo_acesso',
    // ...
];

protected $casts = [
    'dados'            => 'array',
    'ultimo_acesso'    => 'datetime',
    'data_solicitacao' => 'date',
    'grupo_whatsapp'   => 'boolean',
    // ...
];
```
Aplicar exatamente este padrão em `OnboardingPasso::$casts = ['valor' => 'array', 'feito_em' => 'datetime', 'auto_em' => 'datetime']` e em `TemplatePasso::$casts = ['condicao' => 'array', 'depende_de' => 'array', 'obrigatorio' => 'boolean']`.

---

### 6. FormRequest com guarda de ciclo (SC-08)

**Análogo mais forte (lido inteiro):** `app/Http/Requests/UpdateBonusFaixaRequest.php` — regra composta via `withValidator` com `$validator->after()`, exatamente o mecanismo que a guarda de ciclo em `depende_de` precisa.

**Trecho real (`app/Http/Requests/UpdateBonusFaixaRequest.php:70-118`):**
```php
public function withValidator(Validator $validator): void
{
    $validator->after(function (Validator $v) {
        $faixaAtual = $this->route('faixa');
        if (! $faixaAtual instanceof BonusFaixa) {
            return;
        }
        // ... regra 1: comparação simples
        // ── Regra 2: sem sobreposição com outras faixas ativas ───────────
        $outrasAtivas = BonusFaixa::query()
            ->where('id', '!=', $faixaAtual->id)
            ->where('ativo', true)
            ->get(['id', 'slug', 'nome', 'nota_min', 'nota_max']);

        foreach ($outrasAtivas as $outra) {
            if ($novoMin <= $outraMax && $novoMax >= $outraMin) {
                $v->errors()->add('nota_min', "Sobreposição com a faixa \"{$outra->nome}\" [...]");
                return;
            }
        }
    });
}
```
**Autorização em camada dupla (belt-and-suspenders)** — trecho real (mesma classe, linhas 42-45):
```php
public function authorize(): bool
{
    return $this->user()?->isAdmin() === true;
}
```
Mesmo padrão em `StoreNpsTemplateRequest.php:28-31`. Usar em `StoreOnboardingTemplateRequest`/`UpdateOnboardingTemplateRequest`.

**Para a guarda de ciclo especificamente:** não existe precedente de DFS/detecção de ciclo em grafo no projeto (`135-RESEARCH.md` Seção C confirma — grep vazio). A técnica de validação **composta dentro de `withValidator`** é o molde correto (mesmo mecanismo do exemplo acima), mas o algoritmo (DFS sobre `depende_de` dos passos do payload) é implementação nova — não force analogia além da FORMA "regra de negócio cara fica no `after()` do FormRequest, mensagens de erro específicas por campo via `$v->errors()->add(...)`".

**Mensagens em pt-BR, sempre por campo** — reforça o padrão de `UpdateBonusFaixaRequest::messages()` (linhas 127-146) e `StoreNpsTemplateRequest::messages()` (linhas 55-65): nunca deixar a tradução genérica do Laravel vazar pro toast.

---

### 7. Controller CRUD simples (Tela 2, catálogo)

**Análogo (lido inteiro, 89 linhas):** `app/Http/Controllers/ServicoController.php`.
```php
class ServicoController extends Controller
{
    public function index()
    {
        $servicos = Servico::query()
            ->withCount(['contratos as contratos_ativos_count' => fn($q) => $q->where('ativo', true)])
            ->orderBy('nome')->get();
        return Inertia::render('Servicos/Index', ['servicos' => $servicos]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([...]);
        $data['ativo'] = $data['ativo'] ?? true;
        Servico::create($data);
        return back()->with('success', 'Serviço criado.');
    }
    // update/destroy no mesmo espírito — validate() inline, back()->with('success', ...)
}
```
Rota registrada como resource dentro do grupo admin — trecho real (`routes/web.php:770-774`):
```php
// ─── Módulo Serviços (Frente A) ──────────────────────────────────
// Catálogo de serviços + contratos por empresa. Acesso admin-only
// herdado do grupo pai.
Route::resource('servicos', ServicoController::class)
    ->only(['index', 'store', 'update', 'destroy']);
```

### 7b. Controller CRUD com versionamento e invariantes (Tela 2, template)

**Análogo mais próximo para a MECÂNICA de publicar (lido):** `app/Http/Controllers/NpsTemplateController.php:107-119` (`store()`).
```php
public function store(StoreNpsTemplateRequest $request)
{
    $data = $request->validated();

    // Invariantes canônicos — nunca respeitar payload nesses 2 campos.
    $data['is_default'] = false;
    $data['active']     = true;

    $data['priority']                ??= 0;
    $data['envio_automatico_mensal'] ??= true;

    $template = NpsTemplate::create($data);
    // ...
}
```
Mesmo espírito para `OnboardingTemplateController::store()`: o controller (não o request) força invariantes de sistema (`versao = próxima disponível para o servico_id`, `publicado_em = now()`), nunca aceita esses campos do payload do admin. O `NpsTemplateController` **não versiona** (edita in-place) — a mecânica de "criar N+1 em vez de UPDATE" não tem precedente aqui; usar esta classe só como molde de "onde ficam os invariantes de sistema", não como molde de versionamento.

---

### 8. Painel operacional (Tela 1) — listagem agregada admin/interno

**Análogo de FORMA (D-02 proíbe reuso de código, só estrutura):** `resources/js/Pages/Polos/Painel.jsx:104-126` — padrão `situacaoDe()` + `SITUACAO_LABEL` de agregação de múltiplos flags booleanos num chip único de situação. **Não copiar nomes nem rótulos** — a Tela 1 desta fase tem vocabulário próprio (ver UI-SPEC, seção Copywriting).

**Trecho real, só como referência de MECANISMO (não copiar literal):**
```js
const SITUACAO_LABEL = {
    problema:       'Com problema',
    fora_meta:      'Desconsiderada da meta',
    fora_prazo:     'Fora do prazo',
    pendente_envio: 'Pendente de envio',
    sem_ficha:      'Sem ficha',
    ads_off:        'ADS desligado',
    ok:             'Sem pendências',
};
function situacaoDe(e) {
    const f = [];
    if (e.problema)                        f.push('problema');
    if (e.problema_desconsidera_meta)      f.push('fora_meta');
    if (e.fora_do_prazo)                   f.push('fora_prazo');
    if (e.status_envio === 'falta_enviar') f.push('pendente_envio');
    if (!e.impl_id)                        f.push('sem_ficha');
    if (e.ads_desligado)                   f.push('ads_off');
    return f.length ? f : ['ok'];
}
```
O padrão a reaproveitar: função pura `situacaoDe(onboarding)` → array de flags → mapeada para os 7 estados semânticos do UI-SPEC (`rascunho`, `vencido`, `aguardando_x`, `coletando`, `pronto_para_concluir`, `concluido`) — mas com a lógica NOVA especificada no `135-UI-SPEC.md`.

**Componente `StatChip` (reutilizável literalmente, sem reescrita):** `resources/js/Components/StatChip.jsx:16-31`.
```jsx
const TONES = {
    red: '...', amber: '...', green: '...', yellow: '...', neutral: '...',
};
export default function StatChip({ label, count, tone = 'neutral', active = false, onClick, icon: Icon, title }) {
    const desabilitado = typeof onClick !== 'function' || (!count && !active);
    return (
        <button type="button" onClick={onClick} disabled={desabilitado} ...>
            {Icon && <Icon size={12} className="shrink-0" />}
            <span className="truncate">{label}</span>
            <span className="font-bold tabular-nums">{count ?? 0}</span>
        </button>
    );
}
```
Este componente é `tone`-based e cross-domínio — **importar direto**, não recriar.

---

### 9. CRUD de template (Tela 2) — estrutura de página React

**Análogo (lido, imports/estrutura):** `resources/js/Pages/Nps/Configuracao.jsx:1-51`.
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { useState, useEffect, useMemo, useRef, useCallback } from 'react';
import { router, useForm } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { Button } from '@/Components/ui/button';

import TemplatesGrid        from '@/Components/Nps/Config/TemplatesGrid';
import TemplateEditForm     from '@/Components/Nps/Config/TemplateEditForm';
// ... sub-componentes locais em Components/<Dominio>/Config/*
```
Estrutura de DUAS TELAS por estado (`list`/`edit`), sem sidebar fixa — replicar para `Onboarding/Templates/Index.jsx`, com sub-componentes em `resources/js/Components/Onboarding/Templates/*` (mesmo padrão de organização por domínio).

**Widget com `useForm` + PATCH inline** — trecho real (`Nps/Configuracao.jsx:52-63`):
```jsx
function DiaCobrancaWidget({ diaAtual }) {
    const { data, setData, patch, processing, errors } = useForm({ dia: diaAtual ?? 25 });
    const submit = (e) => {
        e.preventDefault();
        patch(route('nps.configuracao.dia-cobranca.update'), { preserveScroll: true });
    };
    // ...
}
```

**Sentinela `SEM_VALOR` para Select opcional (armadilha registrada — confirmado em código, não só em memória):** `resources/js/Pages/Mlb/OnboardingFicha.jsx:32-37`.
```jsx
// Sentinela para a opção "—" dos Selects: o Radix proíbe <SelectItem value="">
// (lança erro em runtime e derruba o render → tela preta). Usamos um valor não-vazio
// e mapeamos de volta para '' antes de enviar ao backend.
const SEM_VALOR = '__none__';
const limparSemValor = (obj) =>
    Object.fromEntries(Object.entries(obj).map(([k, v]) => [k, v === SEM_VALOR ? '' : v]));
```
**Aplicar literalmente este par (`SEM_VALOR` + `limparSemValor`) nos 3 campos opcionais do formulário de passo:** `setor_id`, `depende_de` (se Select único), `auto_fonte`.

---

### 10. Portal do cliente (Tela 3) — página pública

**Análogo de forma (D-02: não copiar código do Polos, só estrutura visual):** `resources/js/Pages/Mlb/ImplementacaoPublica.jsx:72-96` (`ProgressHeader`).
```jsx
function ProgressHeader({ empresa_nome, progresso }) {
    const { pct, feitos, total } = progresso;
    const color = pct === 100 ? '#22c55e' : pct >= 60 ? '#eab308' : '#6366f1';
    return (
        <div className="bg-[#0b0c10] border-b border-white/[0.06] sticky top-0 z-10">
            <div className="max-w-2xl mx-auto px-4 py-4">
                <div className="flex items-center justify-between mb-3">
                    <div>
                        <p className="text-white/40 text-[11px] font-semibold uppercase tracking-wider">ECF Consultoria · Onboarding</p>
                        <h1 className="text-white font-display font-bold text-lg mt-0.5">{empresa_nome}</h1>
                    </div>
                    {/* pct/progresso — ver UI-SPEC: aceitável aqui pq é o cliente monitorando o PRÓPRIO progresso, diferente da Tela 1 (SC-11 proíbe % como resposta operacional central) */}
                </div>
            </div>
        </div>
    );
}
```

**Estado de erro (token inválido)** — trecho real (`resources/js/Pages/Nps/Expired.jsx`, arquivo completo, 13 linhas):
```jsx
import { AlertTriangle } from 'lucide-react';

export default function Expired() {
    return (
        <div className="min-h-screen bg-background flex items-center justify-center p-4">
            <div className="text-center space-y-4 max-w-sm">
                <AlertTriangle className="h-20 w-20 text-yellow-400 mx-auto" />
                <h1 className="text-2xl font-bold text-foreground">Link Expirado</h1>
                <p className="text-muted-foreground">Este link de pesquisa expirou. Por favor, solicite um novo link à ECF Consultoria.</p>
            </div>
        </div>
    );
}
```
Molde exato para "Link inválido" da Tela 3 (copy já especificado no UI-SPEC).

**Trava anti-check-vazio (D-16)** — trecho real (`app/Models/MlbImplementacao.php:448-464`):
```php
/**
 * Regra espelhada em resources/js/Pages/Mlb/ImplementacaoPublica.jsx
 * (função itemTemConteudo) — mantê-las em sincronia manualmente.
 */
public static function itemTemConteudo(string $tipo, array $dado): bool
{
    switch ($tipo) {
        case 'select':
            $valor = trim((string) ($dado['valor'] ?? ''));
            return $valor !== '' && $valor !== '---';
        case 'texto':
            return trim((string) ($dado['acesso'] ?? '')) !== '';
        case 'link':
            return trim((string) ($dado['link'] ?? '')) !== '';
        // ...
    }
}
```
**Nota do UI-SPEC (confirmada):** na v1 do template de Gestão, os 2 passos manuais `dono=cliente` (2, 10) são "declaração de ação" — a trava não morde ainda. Só replicar `itemTemConteudo` (backend + frontend, mesma disciplina de sincronia manual documentada no comentário-fonte) se um `tipo` futuro de passo pedir dado digitado.

**Armadilha do manifest do Vite (registrada em memória do projeto, `.planning/learnings/painel-polos-status-e-meta.md` §4):** página React de puro re-export (`export { default } from '../../Outro/Index'`) **não entra no bundle** — a rota quebra em runtime, não no build. Toda página nova desta fase (`Onboarding/Painel.jsx`, `Onboarding/Templates/Index.jsx`, `Onboarding/Publico.jsx`) deve ser um wrapper real que importa e renderiza, nunca um re-export puro.

---

### 11. Job assíncrono para chamada Adman/ML (resolvers 4, 7, 8)

**Análogo (molde completo, citado no RESEARCH):** `app/Jobs/SyncAdmanCompanyJob.php`.
```php
class SyncAdmanCompanyJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public readonly Company $company, public readonly ?string $date = null) {}

    public function backoff(): array { return [60, 300, 900]; }

    public function handle(AdmanService $adman): void
    {
        $adman->syncCompany($this->company, $this->date);
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[SyncAdmanCompanyJob] Falha definitiva empresa {$this->company->id}: {$e->getMessage()}");
    }
}
```
**Regra arquitetural (não opcional):** todo resolver que toca Adman (passo 4, 7) ou dispara `mlb:sync-acervo` (passo 8) **deve** rodar dentro de um Job `ShouldQueue`, nunca síncrono numa request HTTP — restrição já registrada no `CLAUDE.md` ("Long external API calls (Adman) must go through Jobs to avoid nginx/php-fpm timeout") e reforçada pelo Pitfall 2/3 do RESEARCH.

---

### 12. Comando Artisan de reavaliação periódica (passo 8 "aguardando coleta")

**Análogo mais próximo (lido):** `app/Console/Commands/WarmDesempenhoCache.php` — comando batch agendado que itera sobre um conjunto de registros pendentes/quentes e reprocessa, com `try/catch` por item (não deixa 1 falha derrubar o lote) e opções `--user=*`/`--mes=` para escopo parcial.

**Trecho real (assinatura + docblock, `WarmDesempenhoCache.php:59-70`):**
```php
class WarmDesempenhoCache extends Command
{
    protected $signature = 'desempenho:warm-cache
        {--mes= : Aquece só esta competência YYYY-MM (catch-up/sob-demanda) — NÃO aquece o mês corrente automaticamente}
        {--user=* : Aquecer só estes user IDs (debug ou dispatch sob-demanda)}';

    protected $description = 'Aquece o cache do compute() de desempenho pra todos analista/estrategista (roda a cada 8min via schedule).';

    public function __construct(
        private DesempenhoScoreService $scoreService,
        private MetricPeriodResolver $periodResolver,
        private CompanyScoreSnapshotWriter $snapshotWriter,
    ) { ... }
}
```
**Agendamento** — trecho real (`routes/console.php:338-339`):
```php
Schedule::command('desempenho:warm-cache')
    ->cron('*/8 * * * *')
```
Sugestão para `OnboardingReavaliarPassos` (nome livre, ex. `onboarding:reavaliar-passos`): rodar a cada 5-10min, iterar `OnboardingPasso::where('status', 'aguardando_coleta')`, chamar o resolver correspondente via `OnboardingResolverFactory`, com `try/catch (\Throwable)` por item (nunca derrubar o lote inteiro por 1 falha) — mesma disciplina do molde.

---

### 13. Factory de teste — gap `ContratoServicoFactory`

**Análogo (lido inteiro, 31 linhas):** `database/factories/CompanyFactory.php`.
```php
class CompanyFactory extends Factory
{
    protected $model = Company::class;

    public function definition(): array
    {
        return [
            'name'             => fake()->company(),
            'cnpj'             => fake()->numerify('##.###.###/0001-##'),
            'active'           => true,
            'status'           => 'ativo',
            'marketplace'      => 'meli',
            'adman_account_id' => null,
            'ml_store_id'      => null,
        ];
    }
}
```
`ContratoServico` **não tem** `HasFactory`/factory hoje (confirmado — nenhum arquivo em `database/factories/` para este model; todo teste usa `::create()` explícito). Criar `ContratoServicoFactory` neste mesmo formato minimalista, cobrindo só os campos obrigatórios (`company_id`, `servico_id`, `valor_contratado`, `data_contratacao`, `ativo`), com `ativo => true` como default — é o gap de Wave 0 registrado no `VALIDATION.md`/RESEARCH.

---

## Shared Patterns

### Observer leve + Job pesado (regra arquitetural desta fase)
**Fonte:** `app/Observers/MlbEmpresaObserver.php` (leveza) + `app/Jobs/SyncAdmanCompanyJob.php` (I/O de rede)
**Aplicar a:** `ContratoServicoObserver` + todos os 5 resolvers automáticos (D-03/D-09)
Observer só cria linhas `Onboarding`/`OnboardingPasso` (barato, sem I/O externo). Qualquer chamada Adman/ML/comando de coleta é despachada como `ShouldQueue` a partir do Observer ou de um comando de reavaliação — nunca inline.

### Catálogo fechado via Contract + Factory (D-09/D-14)
**Fonte:** `app/Contracts/SugadoresAdsProvider.php` + `app/Services/Sugadores/SugadoresAdsProviderFactory.php`
**Aplicar a:** `OnboardingResolver` (interface) + `OnboardingResolverFactory` (registry por chave) — nunca aceitar string livre de `auto_fonte`/`condicao` vinda do request.

### `withValidator` para regra composta cara
**Fonte:** `app/Http/Requests/UpdateBonusFaixaRequest.php:70-118`
**Aplicar a:** `StoreOnboardingTemplateRequest`/`UpdateOnboardingTemplateRequest` — guarda de ciclo em `depende_de` roda em `$validator->after()`, mensagens específicas por campo.

### Cast JSON em coluna (`array`)
**Fonte:** `app/Models/MlbImplementacao.php:50-52`
**Aplicar a:** `OnboardingPasso::$casts['valor']`, `TemplatePasso::$casts['condicao']`/`['depende_de']`.

### `role:admin` + FormRequest como camada dupla
**Fonte:** `app/Http/Requests/UpdateBonusFaixaRequest.php:42-45` (`authorize()`) + `routes/web.php:157` (grupo)
**Aplicar a:** todas as rotas/FormRequests do CRUD de template (D-04).

### Sentinela `SEM_VALOR` para Select opcional do Radix
**Fonte:** `resources/js/Pages/Mlb/OnboardingFicha.jsx:32-37`
**Aplicar a:** todo `Select` opcional nas 3 telas novas (`setor_id`, `auto_fonte`, `condicao` na Tela 2).

### Logging com tag de módulo em colchetes
**Fonte:** convenção `CLAUDE.md` + exemplos (`[MLB CustID]`, `[SyncAdmanCompanyJob]`)
**Aplicar a:** todo `Log::info`/`Log::error` novo desta fase — usar `[Onboarding]` como prefixo.

---

## No Analog Found

| Arquivo/Mecanismo | Papel | Fluxo de dado | Razão |
|---|---|---|---|
| Versionamento numérico imutável de template (`versao` incremental + `publicado_em`, nova LINHA nunca UPDATE) | migration + service | CRUD versionado | **SEM ANÁLOGO — padrão novo para o codebase.** `nps_templates` (mais próximo) edita in-place e congela a RESPOSTA via snapshot-por-linha — mecanismo estruturalmente diferente. Reaproveitar só a técnica de índice único parcial (seção 3 acima); desenhar o versionamento do zero. |
| Guarda de detecção de ciclo em grafo de dependências (`depende_de`) | validação (algoritmo DFS) | validação síncrona | **SEM ANÁLOGO — padrão novo.** Grep por "ciclo"/"cycle"/"topological" no projeto não encontrou nenhum precedente de detecção de ciclo em grafo. O mais próximo é a FORMA de onde a regra composta mora (`withValidator`, seção 6 acima) — o algoritmo em si é implementação nova. |

---

## Metadata

**Escopo de busca de análogos:** `app/Models/`, `app/Observers/`, `app/Contracts/`, `app/Services/`, `app/Http/Controllers/`, `app/Http/Requests/`, `app/Jobs/`, `app/Console/Commands/`, `database/migrations/`, `database/factories/`, `routes/`, `bootstrap/app.php`, `resources/js/Pages/`, `resources/js/Components/`
**Arquivos lidos integralmente ou em trechos direcionados:** ~28
**Data de extração:** 2026-08-11
