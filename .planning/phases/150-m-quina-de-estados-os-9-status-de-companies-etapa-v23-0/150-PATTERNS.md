# Phase 150: Máquina de estados — os 9 status de `companies.etapa` (v23.0) - Pattern Map

**Mapeado:** 2026-09-01
**Arquivos analisados:** 10 (novos/modificados)
**Analogs encontrados:** 10 / 10

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dado | Analog mais próximo | Qualidade do match |
|---|---|---|---|---|
| `database/migrations/2026_09_01_*_add_etapa_to_companies_table.php` | migration | batch (aditiva) | `database/migrations/2026_07_23_100000_create_company_manager_history_table.php` (bom) / `2026_05_25_100001_add_status_to_companies.php` (anti-padrão) | role-match |
| `app/Models/Company.php` (+const, +docblock, +accessor pendência) | model | CRUD | o próprio `Company.php` (constantes/accessors já existentes no mesmo arquivo) | exact |
| `app/Services/FluxoEntrada/EtapaTransicaoService.php` (nome livre) | service | event-driven (transição de estado) | `app/Services/Contratos/GatilhoContratoAdministrativoService.php` | exact |
| Migration/tabela de histórico (`company_etapa_transicoes`, SE Opção B) | migration + model | event-driven (append-only) | `app/Models/CompanyManagerHistory.php` + sua migration | exact |
| Comando Artisan de backfill (`etapa:backfill` ou similar) | utility (console command) | batch | `app/Console/Commands/OnboardingBackfillContratos.php` | exact |
| `app/Http/Controllers/CompanyController.php::index()` (+filtro etapa/pendência) | controller | request-response | o próprio `CompanyController::index()` (filtro `cust_id_status` já existente, linhas 86-100/150) | exact |
| `resources/js/Pages/Companies/Index.jsx` (+controles de filtro) | component | request-response | o próprio `Index.jsx` (filtro `cust_id_status`, linhas 172-187, 409-417) | exact |
| `tests/Unit/Phase150/CompanyEtapaConstantesTest.php` | test | — | nenhum unit test de constantes de model encontrado — molde estrutural genérico PHPUnit | sem analog direto |
| `tests/Feature/Phase150/EtapaBackfillTest.php` | test | batch | `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` | exact |
| `tests/Feature/Phase150/EtapaFiltroListagemTest.php` | test | request-response | `tests/Feature/Phase37CompaniesPerformanceFilterTest.php::test_filtro_cust_id_status_invalido_continua_funcional` (linha 295) | exact |
| `tests/Unit/Phase150/EtapaTransicaoServiceTest.php` | test | event-driven | nenhum unit test do `GatilhoContratoAdministrativoService` foi localizado nesta busca — seguir a assinatura pública de `avaliar()`/`dispararSeElegivel()` como contrato a testar | role-match |
| Fixture compartilhada de teste (`CriaEmpresaComEtapa`, opcional) | test (trait) | — | `tests/Feature/V16/CriaCenarioResponsaveis.php` | exact |

## Pattern Assignments

### `app/Services/FluxoEntrada/EtapaTransicaoService.php` (service, event-driven)

**Analog:** `app/Services/Contratos/GatilhoContratoAdministrativoService.php` (íntegro, 187 linhas)

Este é o molde direto citado em D-12. Copiar a **forma**, não o guard de reentrância
(D-11 diz que a transição é sempre por chamada explícita — não há laço Observer → escrita →
Observer aqui, então `self::$emAvaliacao` NÃO deve ser copiado).

**Imports pattern** (linhas 1-10):
```php
<?php

namespace App\Services\Contratos;

use App\Services\Clicksign\CongelamentoEmissaoService;
use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Services\Clicksign\ContratoClicksignService;
use App\Services\Comercial\PendenciasComerciaisService;
use Illuminate\Support\Facades\Log;
```

**Docblock de classe explicando o par puro/efeito** (linhas 12-36) — replicar o estilo:
explica a sequência, o motivo da ordem, e a fronteira de responsabilidade com serviços
vizinhos (aqui, `EmpresaOperacionalRouter`; no caso da 137, com o que quer que dispare
`transicionar()` nas Fases 151-142).

**Núcleo puro — `avaliar()`, molde de `podeTransicionar()`** (linhas 55-105):
```php
/**
 * Decide, SEM nenhum efeito colateral — não cria `ContratoAssinatura`,
 * não despacha job, não grava nada em `Company`. Ver docblock da classe
 * para a ordem e o motivo de cada passo.
 *
 * @return array{status: string, pendencias: array<int, string>, motivo?: string}
 */
public function avaliar(Company $company): array
{
    // 1. ...regra, pode retornar cedo com motivo nomeado...
    if (! $existeServicoQueExigeContrato) {
        return ['status' => 'isento', 'pendencias' => [], 'motivo' => 'nenhum servico exige contrato'];
    }

    // 2. ...próxima regra...
    if (ContratoAssinatura::emAndamentoDaEmpresa($company->id) !== null) {
        return ['status' => 'ja_em_andamento', 'pendencias' => []];
    }

    // 3. Elegível.
    return ['status' => 'elegivel', 'pendencias' => []];
}
```
Para `podeTransicionar(Company $company, string $etapaDestino): array`, o RESEARCH.md já
prevê o shape exato adaptado (linhas 641-663 do RESEARCH):
```php
/**
 * @return array{permitido: bool, requisito_faltante?: string, motivo?: string}
 */
public function podeTransicionar(Company $company, string $etapaDestino): array
{
    $etapaAtual = $company->etapa; // pode ser NULL (legado, D-03)

    if (! $this->transicaoPermitida($etapaAtual, $etapaDestino)) {
        return [
            'permitido' => false,
            'requisito_faltante' => "transição de '{$etapaAtual}' para '{$etapaDestino}' não é permitida",
        ];
    }

    // ...checagens específicas por destino (ex.: destino=4 exige contrato assinado)...

    return ['permitido' => true];
}
```

**Núcleo com efeito — `dispararSeElegivel()`, molde de `transicionar()`** (linhas 119-185,
extrato sem o guard de reentrância que não se aplica aqui):
```php
public function dispararSeElegivel(Company $company): array
{
    // ...checagem prévia de interruptor/pré-condição (se houver)...

    try {
        $avaliacao = $this->avaliar($company);

        if ($avaliacao['status'] !== 'elegivel') {
            Log::info("[GatilhoContrato] empresa {$company->id} ({$company->name}) não elegível — status={$avaliacao['status']}");

            return $avaliacao;
        }

        $resultado = $this->clicksign->iniciarParaEmpresa($company);

        return ['status' => 'disparado', 'resultado' => $resultado];
    } catch (\Throwable $e) {
        Log::error("[GatilhoContrato] falha ao avaliar/disparar contrato para empresa {$company->id} ({$company->name}): {$e->getMessage()}");

        return ['status' => 'erro', 'erro' => $e->getMessage()];
    }
}
```
Para `transicionar(Company $company, string $destino, User $por, ?string $motivo)`: seguir
esta mesma casca (`podeTransicionar()` primeiro, recusa nomeada se inválido, `try/catch
(\Throwable)`, log com `[nome-do-canal]` + `company->id`/`name`, e — diferente do molde — a
gravação real de `companies.etapa` seguida do registro de histórico (D-16), sempre em
`save()`/`update()` que toca **só** `etapa` (ver Pitfall 6 abaixo, "Shared Patterns").

**Erro/recusa nomeada (ETAPA-06):** o padrão do projeto é **nunca** mensagem genérica —
sempre `status`/`requisito_faltante` com o motivo específico (`'isento'`, `'ja_em_andamento'`,
`"transição de '{$de}' para '{$para}' não é permitida"`). Seguir essa granularidade.

---

### Precedente adicional de estrutura de serviço — `app/Services/Operacional/EmpresaOperacionalRouter.php`

**Analog:** ele mesmo (íntegro, 397 linhas) — não é o molde do par puro/efeito, mas é o
precedente citado no CONTEXT para "serviço sem chamador de produção" (Pattern 3 do RESEARCH).

**Uso nesta fase:** confirma que é seguro e é convenção do projeto entregar
`EtapaTransicaoService` **sem plugar nenhum controller/job de produção ainda** — só testes —
com o mesmo racional documentado no próprio arquivo:
```php
// Source: app/Services/Operacional/EmpresaOperacionalRouter.php:36-39
* Este service NÃO tem chamador ainda — os dois controllers continuam com
* o código inline de hoje. Religar os dois caminhos para consumir este
* router é escopo do plano 124-05, de propósito: separa o risco de
* escrever o service novo do risco de trocar o caminho de produção.
```
Copiar esta mesma frase de docblock (adaptada) na classe nova, apontando para as Fases
138/139/141/142 como quem vai plugar os chamadores reais.

Também vale copiar o padrão de nomear a constante de contrato entre fases quando a chave/
nome é compartilhada entre múltiplas fases futuras (linhas 43-49 do arquivo, sobre
`CHAVE_BLOQUEIO`) — mesmo espírito para `Company::ETAPAS`/`ETAPA_*`, que as Fases 151-143
também vão consumir.

---

### `app/Models/Company.php` (model, CRUD) — constantes, docblocks, accessor de pendência

**Analog:** o próprio arquivo (689 linhas) — não tem nenhuma constante hoje (confirmado por
leitura integral: zero ocorrência de `public const`). O padrão de accessor a seguir é o já
usado no mesmo model:

**Padrão de accessor simples de leitura única** (linhas 113-117, `getCustIdAttribute`):
```php
public function getCustIdAttribute(): ?string
{
    $custId = $this->adman_account_id ?: $this->ml_store_id;
    return $custId !== '' ? $custId : null;
}
```
Usar esta mesma forma para o accessor de pendência que D-19 exige (`Company::pendenciaAberta():
bool` ou equivalente `getPendenciaAbertaAttribute()`), copiando a disciplina de comentário-
docblock que precede cada accessor do arquivo (ex.: linhas 93-112, explica prioridade e
motivo, com data/fase).

**`$fillable`** (linhas 34-72) — adicionar `'etapa'` na lista, seguindo o padrão de comentário
inline por seção que já existe:
```php
protected $fillable = [
    'name', 'cnpj', 'adman_account_id', 'adman_store_id', 'ml_store_id',
    'cust_id_status', 'marketplace',
    'segment', 'active', 'status', 'notes', 'email_cliente', 'telefone',
    // ...
];
```

**`$casts`** (linhas 74-91) — se `etapa` precisar de cast (provavelmente não, é string simples
já validada contra `ETAPAS`), seguir o padrão `'campo' => 'tipo'` já usado.

**`getActivitylogOptions()`** (linhas 20-32) — hoje **não** inclui `etapa`. Decisão explícita do
RESEARCH (linha 283 do CONTEXT / linha 417-421 do RESEARCH): avaliar se entra aqui é
insuficiente para D-16 (não carrega motivo/retrocesso) — ver "D-16 — histórico" abaixo.

**`managerHistory()`** (linhas 355-359) é o molde de relação `hasMany` a copiar SE a Opção B
(tabela dedicada) for escolhida para o histórico de transição:
```php
/** Fase 108 — histórico de entrada/saída de responsáveis (analista/estrategista). */
public function managerHistory()
{
    return $this->hasMany(CompanyManagerHistory::class)->latest();
}
```

**`analistaPerformance()`/`estrategistaPerformance()`** (linhas 305-353) — **fonte obrigatória
do balde 1 do backfill (D-04)**. Reproduzido aqui porque um dev que reescrever a query à mão
tropeça exatamente no Pitfall 5 do RESEARCH (papel de analista na pivot é `'consultor'`,
nunca `'analista'`):
```php
public function analistaPerformance()
{
    return $this->belongsToMany(User::class, 'company_users')
        ->withPivot('assigned_at')
        ->wherePivot('role', 'consultor')   // ← "analista" de negócio = role 'consultor' na pivot
        ->where(function ($q) {
            $q->whereIn('company_users.servico_id', function ($sub) {
                $sub->select('id')->from('servicos')->where('setor', Servico::SETOR_PERFORMANCE);
            })->orWhere(function ($q2) {
                $q2->whereNull('company_users.servico_id')
                   ->whereExists(function ($sub2) {
                       $sub2->select(DB::raw(1))
                            ->from('contratos_servico as ct')
                            ->join('servicos as s2', 's2.id', '=', 'ct.servico_id')
                            ->whereColumn('ct.company_id', 'company_users.company_id')
                            ->where('ct.ativo', true)
                            ->where('s2.setor', Servico::SETOR_PERFORMANCE);
                   });
            });
        })
        ->distinct('users.id');
}
```
`estrategistaPerformance()` é idêntica, trocando `'role', 'consultor'` por `'role',
'estrategista'`.

---

### Migration `add_etapa_to_companies_table` (migration, batch/aditiva)

**Analog positivo:** `database/migrations/2026_07_23_100000_create_company_manager_history_table.php`
(íntegro, 38 linhas) — aditiva pura, `up()`/`down()` simétricos e sem side effect.

**Anti-analog — NÃO seguir:** `database/migrations/2026_05_25_100001_add_status_to_companies.php`
(íntegro, 49 linhas). É o precedente ruim citado por D-07. Reproduzido integralmente porque
o padrão do PATTERNS.md é dar trecho concreto, e aqui o trecho concreto é "o que não fazer":
```php
// Source: database/migrations/2026_05_25_100001_add_status_to_companies.php:17-47
public function up(): void
{
    // Adiciona a coluna status (idempotente via ifNotExists)
    if (!Schema::hasColumn('companies', 'status')) {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('status')->nullable()->default('ativo')->after('active');
        });
    }

    // Backfill: todos os registros existentes sem status recebem 'ativo'
    DB::table('companies')
        ->whereNull('status')
        ->update(['status' => 'ativo']);

    // Renomeia service_type='polo' → 'polos'
    DB::table('companies')
        ->where('service_type', 'polo')
        ->update(['service_type' => 'polos']);
}

public function down(): void
{
    // Reverte rename polos → polo
    DB::table('companies')
        ->where('service_type', 'polos')
        ->update(['service_type' => 'polo']);

    Schema::table('companies', function (Blueprint $table) {
        $table->dropColumn('status');
    });
}
```
**Por que é anti-padrão (repetir a análise de D-07 no PLAN/commit):** o `up()`/`down()` mistura
DUAS mudanças de propósitos diferentes (criação de coluna `status` + rename de
`service_type`); reverter uma parte obriga reverter as duas. A migration de `etapa` deve
tocar **só** `companies.etapa` — criação, nullable, sem default, nada mais. Nenhum backfill de
dado dentro da própria migration (o backfill de D-04/D-05 é o comando Artisan separado,
seguindo `OnboardingBackfillContratos`, não a migration).

**Shape correto a seguir** (baseado no molde `company_manager_history`, adaptado a
`Schema::table` em vez de `Schema::create`):
```php
public function up(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->string('etapa')->nullable()->after('status'); // D-03: nullable, SEM default
        $table->index('etapa'); // opcional — ver Open Question 1 do RESEARCH
    });
}

public function down(): void
{
    Schema::table('companies', function (Blueprint $table) {
        $table->dropColumn('etapa');
    });
}
```

---

### Histórico de transição (D-16) — SE tabela dedicada (Opção B, recomendação do RESEARCH)

**Analog:** `app/Models/CompanyManagerHistory.php` (íntegro, 42 linhas) +
`database/migrations/2026_07_23_100000_create_company_manager_history_table.php` (íntegro,
38 linhas) — "é praticamente o desenho que a Fase 150 precisa, só trocando os nomes dos
campos" (RESEARCH, linha 28).

**Model completo, para copiar a forma:**
```php
// Source: app/Models/CompanyManagerHistory.php (integral)
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 108 — Registro imutável de entrada/saída de responsável (analista/
 * estrategista) de uma empresa. Populado a cada troca em
 * CompanyController::update(). Sem updated_at (log append-only).
 */
class CompanyManagerHistory extends Model
{
    protected $table = 'company_manager_history';

    public const UPDATED_AT = null;

    protected $fillable = [
        'company_id',
        'user_id',
        'papel',    // analista | estrategista
        'evento',   // entrada | saida
        'changed_by',
    ];

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
```

**Migration completa, para copiar a forma** (índice composto, `UPDATED_AT = null`, FKs com
`cascadeOnDelete`/`nullOnDelete`):
```php
// Source: database/migrations/2026_07_23_100000_create_company_manager_history_table.php (integral)
public function up(): void
{
    Schema::create('company_manager_history', function (Blueprint $table) {
        $table->id();
        $table->foreignId('company_id')->constrained()->cascadeOnDelete();
        $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
        $table->string('papel', 20);   // analista | estrategista
        $table->string('evento', 10);  // entrada | saida
        $table->foreignId('changed_by')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamp('created_at')->nullable();

        $table->index(['company_id', 'created_at']);
    });
}

public function down(): void
{
    Schema::dropIfExists('company_manager_history');
}
```
**Adaptação sugerida para `company_etapa_transicoes`:** trocar `papel`/`evento` por
`etapa_anterior` (nullable — a primeira transição parte de `NULL`, D-03) e `etapa_nova`
(NOT NULL); `user_id` é o ator (D-13); `changed_by` não se aplica (não há "quem mudou quem
mudou" aqui — só um ator por linha); adicionar `motivo` (nullable) e `retrocesso` (boolean,
default `false`, D-15). Manter `UPDATED_AT = null` e o índice `(company_id, created_at)`.

**Alternativa (Opção A, `activitylog` custom)** — só se o planner preferir não criar tabela
nova. Dois precedentes reais de uso manual/custom (canal próprio, não o trait automático):
```php
// Source: app/Services/Portal/PortalAuditoria.php:31-37
activity(self::CANAL)
    ->performedOn($usuario)
    ->causedBy($usuario)
    ->withProperties(['evento' => 'codigo_enviado', 'ip' => $ip])
    ->log("Código de acesso enviado para {$usuario->email}");
```
```php
// Source: app/Services/RevisaoService.php:313-320
private function log(User $user, Publicacao $pub, string $acao, array $props = []): void
{
    activity('mlb')
        ->causedBy($user)
        ->withProperties(array_merge(['mlb_code' => $pub->mlb_code, ...], $props))
        ->log($acao);
}
```
Ver RESEARCH.md seção "D-16 — Trade-off histórico" (linhas 410-506) para os prós/contras
completos — decisão é do planner, não travada pelo CONTEXT.

---

### Comando de backfill (utility, batch) — ETAPA-02, D-04/D-05/D-08

**Analog:** `app/Console/Commands/OnboardingBackfillContratos.php` (íntegro, 160 linhas) — é o
molde mais próximo no projeto de "comando dry-run-por-padrão com `--apply`, contagem por
balde e log". Reproduzido em blocos porque cada bloco mapeia direto a uma decisão travada:

**Signature — dry-run como padrão, `--apply` explícito** (linhas 36-42):
```php
class OnboardingBackfillContratos extends Command
{
    protected $signature = 'onboarding:backfill-contratos
        {--apply       : Grava de verdade. Sem esta flag o comando só mostra o que faria}
        {--servico=    : Restringe a um servico_id}
        {--company=    : Restringe a um company_id}
        {--limite=500  : Máximo de contratos processados nesta passada}';

    protected $description = 'Cria onboarding em rascunho para contratos de serviço ativos que ficaram sem (dry-run por padrão)';
```
Para a fase 150, sugestão de nome: `etapa:backfill {--apply}` — os dois baldes de D-04 não
precisam de `--limite`/`--servico` (não há filtro parcial no requisito), mas o padrão
dry-run-por-padrão + `--apply` é diretamente aplicável.

**Contagem em memória + `$this->table()`, nunca só stdout como prova** (linhas 71-148,
recortado): note que o comando já pratica a disciplina de D-08 — o `$this->info()`/`$this->table()`
é para o operador *ver*, mas a fonte de verdade de "quantos foram" é sempre reconsultável no
banco depois (o comando não é a prova, é o log de operação):
```php
$this->info(sprintf(
    '[Onboarding] %d contrato(s) ativo(s) sem onboarding.%s',
    $contratos->count(),
    $apply ? '' : ' MODO DRY-RUN — nada será gravado (use --apply).'
));

$criados = 0;
$semDefinicao = 0;
// ...

foreach ($contratos as $contrato) {
    if (! $apply) {
        // dry-run: só CLASSIFICA, nunca escreve
        $linhas[] = [$contrato->id, $empresa, $servico, $temDefinicao ? 'criaria' : 'serviço sem definição'];
        continue;
    }

    try {
        // ...efeito real...
    } catch (\Throwable $e) {
        $falhas++;
        Log::error("[Onboarding] falha no backfill do contrato {$contrato->id} ...: {$e->getMessage()}");
    }
}

$this->table(['Contrato', 'Empresa', 'Serviço', 'Resultado'], $linhas);
```
Para D-08 especificamente: o comando de `etapa:backfill` deve emitir os TRÊS números
(`total`, `com etapa 9`, `com etapa NULL`) tanto ANTES quanto DEPOIS de `--apply`, e o
`VERIFICATION.md` da fase deve reconsultar o banco diretamente (`SELECT COUNT(*) FROM
companies WHERE etapa = 'em_operacao'` etc.) — nunca aceitar o stdout do comando como prova
(disciplina do `.planning/learnings/desempenho-bonificacao.md`, citada em D-08).

**Query do balde 1, usando as relações corretas** (RESEARCH.md linhas 684-697, shape
sugerido):
```php
$idsBalde1 = Company::query()
    ->where(function ($q) {
        $q->whereHas('analistaPerformance')
          ->orWhereHas('estrategistaPerformance');
    })
    ->pluck('id');

DB::table('companies')->whereIn('id', $idsBalde1)->update(['etapa' => Company::ETAPA_EM_OPERACAO]);
// Todo o resto fica NULL — não precisa de update (nasce NULL, D-03).
```

---

### `app/Http/Controllers/CompanyController.php::index()` (controller, request-response) — filtro ETAPA-05

**Analog:** o próprio `index()` (linhas 84-350), filtro `cust_id_status` já existente.

**Parse do query param com allow-list e fallback silencioso `null`** (linhas 86-92):
```php
// Phase 18 W5-T4 — Filtro opcional por cust_id_status. Aceita apenas
// valores do dominio da coluna ENUM; fora disso, ignora silenciosamente
// (no-op em when() — preserva comportamento anterior).
$custIdStatusFilter = $request->input('cust_id_status');
if (!in_array($custIdStatusFilter, ['ok', 'invalido', 'desconhecido', 'nao_aplicavel'], true)) {
    $custIdStatusFilter = null;
}
```
Molde direto para `$etapaFilter`/`$comPendenciaFilter` (D-21, D-22, D-23) — RESEARCH.md já dá
o shape adaptado (linhas 667-680):
```php
$etapaFilter = $request->input('etapa'); // aceita um dos 9 valores OU o sentinela 'sem_etapa' (D-22)
if (! in_array($etapaFilter, [...Company::ETAPAS, 'sem_etapa'], true)) {
    $etapaFilter = null;
}

$comPendenciaFilter = $request->boolean('com_pendencia'); // ETAPA-05, independente do de etapa (D-23)

// ...
->when($etapaFilter === 'sem_etapa', fn($q) => $q->whereNull('etapa'))
->when($etapaFilter && $etapaFilter !== 'sem_etapa', fn($q) => $q->where('etapa', $etapaFilter))
->when($comPendenciaFilter, fn($q) => $q->where('pendencia_aberta', true)) // ou join, se tabela própria (D-18)
```

**Aplicação do `when()` na query base** (linha 150, dentro da cadeia iniciada em `Company::with([...])`
na linha 106):
```php
->when($custIdStatusFilter, fn($q) => $q->where('cust_id_status', $custIdStatusFilter))
```
Os dois novos filtros (`etapa`, `com_pendencia`) entram na mesma cadeia `->when(...)`, **depois**
de `whereDoesntHave('mlbEmpresa')` (linha 139) e do `whereHas('contratosServico', ...)` (linhas
144-149) — nunca antes, para preservar a ordem/semântica que o Pitfall 3 do RESEARCH descreve
("preservar a tela = preservar o recorte inteiro, não só o derivado").

**Devolução do filtro ao Inertia como prop `filters`** (linhas 346-349):
```php
'filters'        => [
    'cust_id_status' => $custIdStatusFilter,
    'sort'           => $sort,
],
```
Adicionar `'etapa' => $etapaFilter, 'com_pendencia' => $comPendenciaFilter` aqui.

**Ponto de atenção — ETAPA-03 (Pitfall 1 do RESEARCH):** `update()` (linhas 829-853) usa
`$request->validate([...])` com lista FECHADA de chaves — `etapa` não está lá. **Não
adicionar `etapa` a essa lista.** É a defesa (por ausência, hoje; por teste de regressão,
depois de `EtapaPontoUnicoTest.php`) contra o controller furar o ponto único de escrita.

---

### `resources/js/Pages/Companies/Index.jsx` (component, request-response) — controles de filtro

**Analog:** o próprio arquivo, filtro `cust_id_status` (linhas 172, 177-179, 409-417).

**Leitura do filtro ativo a partir da prop `filters`** (linha 172):
```jsx
const custIdStatusFilter = filters.cust_id_status || '';
```

**Disparo do filtro via `router.get` server-side, com `preserveState`/`preserveScroll`**
(linhas 177-179):
```jsx
const aplicarCustIdFilter = (valor) => {
    router.get(route('companies.index'), valor ? { cust_id_status: valor } : {}, { preserveState: true, preserveScroll: true });
};
```
Molde direto para `aplicarEtapaFilter`/`aplicarComPendenciaFilter` (D-20, D-21) — server-side
por query param, nunca `Array.filter` em memória (o `em_operacao` já é o contra-exemplo a NÃO
seguir para o filtro novo — ver abaixo).

**Select nativo estilizado com `ecf-*`/`cn()`** (linhas 409-417):
```jsx
<select
    value={custIdStatusFilter}
    onChange={e => aplicarCustIdFilter(e.target.value)}
    className="h-9 pl-3 pr-8 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 cursor-pointer"
    title="Filtrar por status do cust_id"
>
    <option value="">Todas as empresas</option>
    <option value="invalido">Apenas Cust ID Inválido</option>
</select>
```
Para o filtro de etapa (D-22): a opção `"Sem etapa (legado)"` precisa existir como opção de
PRIMEIRA CLASSE (não escondida/no fim da lista) — o array `Company::ETAPAS` do backend
alimenta as 9 opções normais + esta.

**Contra-exemplo a NÃO copiar para o filtro novo — filtro de CLIENTE** (linha 242):
```jsx
// `em_operacao` vem do CompanyController (E de analista+estrategista) e é
// proposital que NÃO seja a negação da pendência `sem_responsavel` (um OU).
const emOperacao = companies.filter(c => c.em_operacao);
```
Este é o padrão que D-21 explicitamente rejeita para o filtro novo ("filtro server-side por
query param... e não filtro de cliente"). `em_operacao` é derivado hoje e permanece assim até
a Fase 155 — não é o molde para `etapa`/`pendência`, é só contexto de por que a aba "Empresas"
filtra em cima da lista já filtrada pelo backend.

---

### Testes — moldes de Feature test para `/companies`

**Analog:** `tests/Feature/Phase37CompaniesPerformanceFilterTest.php` (356 linhas) +
`tests/Feature/V16/CriaCenarioResponsaveis.php` (trait, 145 linhas íntegras).

**Helpers de setup do teste (`actingAsAdmin`, `criarServico`, `criarEmpresa`, `criarContrato`,
`payloadCompanies`)** (linhas 41-94):
```php
private function actingAsAdmin(): User
{
    $admin = User::create([
        'name'     => 'Admin Phase37-06 ' . uniqid(),
        'email'    => 'admin.p37-06.' . uniqid() . '@ecf.test',
        'password' => bcrypt('senha'),
        'role'     => 'admin',
        'active'   => true,
    ]);
    $this->actingAs($admin);
    return $admin;
}

private function payloadCompanies($response): \Illuminate\Support\Collection
{
    return collect($response->viewData('page')['props']['companies']);
}
```

**Teste de filtro por query param inválido/válido — molde EXATO para `EtapaFiltroListagemTest`**
(linhas 295-313):
```php
public function test_filtro_cust_id_status_invalido_continua_funcional(): void
{
    $this->actingAsAdmin();
    $servico = $this->criarServico('Gestao', Servico::SETOR_PERFORMANCE);

    $empresaInvalida = $this->criarEmpresa(['cust_id_status' => 'invalido']);
    $this->criarContrato($empresaInvalida, $servico, true);

    $empresaOk = $this->criarEmpresa(['cust_id_status' => 'ok']);
    $this->criarContrato($empresaOk, $servico, true);

    $response = $this->get('/companies?cust_id_status=invalido');
    $ids = $this->payloadCompanies($response)->pluck('id');

    $this->assertContains($empresaInvalida->id, $ids->all(),
        'Filtro cust_id_status=invalido deve manter empresa invalida no payload');
    $this->assertNotContains($empresaOk->id, $ids->all(),
        'Filtro cust_id_status=invalido deve excluir empresa OK');
}
```

**Trait de fixture — molde para `CriaEmpresaComEtapa` (opcional, reduz duplicação de setup)**
(linhas 22-105 do `CriaCenarioResponsaveis.php`, íntegro na íntegra acima em "Reusable
Assets"): usa `DB::table` puro (não Eloquent) para controlar `servico_id` explicitamente na
pivot, porque não existem factories de `Servico`/`ContratoServico` no projeto — mesma
disciplina se aplica a qualquer fixture nova desta fase.

**Pré-requisito de ambiente antes de rodar QUALQUER suíte `Feature` neste worktree** (Pitfall
4 do RESEARCH — confirmado nesta pesquisa, worktree sem `node_modules/`/`public/build/`):
```bash
echo "http://localhost:5173" > public/hot
# ...rodar a suíte...
rm public/hot   # REMOVER depois — nunca deixar órfão (ver project_vite_hot_orfao_local.md)
```

## Shared Patterns

### Ponto único de decisão de leitura de flag paralelo (D-19)

**Fonte:** `app/Http/Controllers/PolosController.php:1865-1868`
**Aplicar em:** `Company::pendenciaAberta()` (ou nome equivalente do accessor)
```php
/**
 * @param  array<string,mixed>  $ativo  Linha do roster (MlbEmpresa::toArray ou CSV)
 */
private function desconsideraDaMeta(array $ativo): bool
{
    return (bool) ($ativo['problema'] ?? false)
        && (bool) ($ativo['problema_desconsidera_meta'] ?? false);
}
```
**Nota de adaptação:** este precedente opera sobre um **array** (dado de CSV); para `Company`
o equivalente correto é um **accessor no model** (`getPendenciaAbertaAttribute()` ou método
público simples), porque D-19 pede explicitamente "accessor/helper no `Company`", não um
método de controller. Nenhum controller/query deve ler a(s) coluna(s) de pendência
diretamente — sempre passar por este ponto único, sob pena de repetir o bug histórico do
Painel Polos (`.planning/learnings/painel-polos-status-e-meta.md` §1).

### Par puro/efeito para decisão de negócio reutilizável entre backend e UI (D-12)

**Fonte:** `app/Services/Contratos/GatilhoContratoAdministrativoService.php:62-105` (puro) +
`:131-185` (efeito) — ver excerto completo na seção "Pattern Assignments" acima.
**Aplicar em:** `EtapaTransicaoService::podeTransicionar()` / `::transicionar()`. Mesma fonte
de verdade serve tanto a recusa nomeada da ETAPA-06 quanto — nas fases futuras — o
`podeTransicionar()` chamado do frontend para desabilitar botão, sem duplicar a régua de
transições permitidas (D-14).

### `\Throwable` + log com tag entre colchetes + `company->id`/`company->name`

**Fonte:** `app/Services/Contratos/GatilhoContratoAdministrativoService.php:178-181` e
`app/Console/Commands/OnboardingBackfillContratos.php:127-134`.
**Aplicar em:** qualquer bloco de efeito do serviço de transição e do comando de backfill.
```php
} catch (\Throwable $e) {
    Log::error("[GatilhoContrato] falha ao avaliar/disparar contrato para empresa {$company->id} ({$company->name}): {$e->getMessage()}");

    return ['status' => 'erro', 'erro' => $e->getMessage()];
}
```

### Comando de backfill dry-run-por-padrão com `--apply`

**Fonte:** `app/Console/Commands/OnboardingBackfillContratos.php` (íntegro) — ver excerto
completo acima.
**Aplicar em:** o comando de backfill de `etapa` (ETAPA-02). Padrão: sem `--apply` só lista/
classifica (nenhuma escrita); com `--apply` grava e loga; sempre termina com contagem por
balde impressa (mas NUNCA aceita como prova — reconsultar o banco, D-08).

### Filtro server-side por query param com allow-list + fallback `null` silencioso

**Fonte:** `app/Http/Controllers/CompanyController.php:86-100` (backend) +
`resources/js/Pages/Companies/Index.jsx:172-187, 409-417` (frontend).
**Aplicar em:** `CompanyController::index()` (filtros `etapa`/`com_pendencia`, ETAPA-05) e no
componente de filtro correspondente em `Companies/Index.jsx`. Nunca filtro de cliente
(`Array.filter`) para dado que já pode ser filtrado no servidor — ver o contra-exemplo
`em_operacao` documentado acima.

### Guard contra laço de Observer em `Company::updated()`

**Fonte:** `app/Observers/CompanyGatilhoContratoObserver.php:49, 53-55`.
```php
public const CAMPOS_GATILHO = ['email_cliente', 'cnpj', 'nome_contato'];

public function updated(Company $company): void
{
    if (! $company->wasChanged(self::CAMPOS_GATILHO)) {
        return;
    }
    // ...reavalia o gate administrativo...
}
```
**Aplicar como restrição, não como código a copiar:** `etapa` **não** está em
`CAMPOS_GATILHO`, então gravar só `etapa` hoje não reaciona este Observer — mas
`transicionar()` deve gravar **apenas** o campo `etapa` (e nada mais) no `save()`/`update()`
que toca `Company`, nunca combinado com outros campos no mesmo save, para não disparar este
ou outros observers de carona (Pitfall 6 do RESEARCH). Qualquer dado adicional relacionado à
transição (ex.: dados de onboarding, na Fase 155) deve ser gravado em chamada `save()`
separada.

## No Analog Found

| Arquivo | Papel | Fluxo de dado | Motivo |
|---|---|---|---|
| `tests/Unit/Phase150/CompanyEtapaConstantesTest.php` | test | — | Nenhum teste unitário de "constantes + ordem" em model foi localizado no projeto — é o primeiro model com `public const` de domínio. Estrutura genérica PHPUnit (`assertEquals`, `assertSame`) resolve sem molde específico. |
| `tests/Unit/Phase150/EtapaTransicaoServiceTest.php` | test | event-driven | Não foi localizado teste unitário existente para `GatilhoContratoAdministrativoService` nesta busca (pode existir e não ter sido encontrado pela busca de nome; se existir, é o molde natural). Seguir a assinatura pública documentada (`avaliar()`/`podeTransicionar()` puro, sem mock de I/O) como contrato mínimo. |

## Metadata

**Escopo da busca de analogs:** `app/Services/`, `app/Models/`, `app/Http/Controllers/`,
`app/Console/Commands/`, `app/Observers/`, `database/migrations/`, `resources/js/Pages/Companies/`,
`tests/Feature/`, `tests/Feature/V16/`.
**Arquivos lidos por completo:** `GatilhoContratoAdministrativoService.php`,
`EmpresaOperacionalRouter.php`, `Company.php`, `CompanyManagerHistory.php` + migration,
`2026_05_25_100001_add_status_to_companies.php`, `OnboardingBackfillContratos.php`,
`CriaCenarioResponsaveis.php`.
**Arquivos lidos por trecho (offset/limit ou grep-then-read):** `CompanyController.php`
(84-350, 829-918), `Companies/Index.jsx` (160-260, 400-430), `PolosController.php`
(1862-1986 via grep de contexto), `CompanyGatilhoContratoObserver.php` (trecho `CAMPOS_GATILHO`),
`Phase37CompaniesPerformanceFilterTest.php` (1-120, 295-313).
**Data de extração:** 2026-09-01

## PATTERN MAPPING COMPLETE
