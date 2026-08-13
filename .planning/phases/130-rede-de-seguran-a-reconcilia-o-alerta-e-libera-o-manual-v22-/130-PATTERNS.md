# Phase 130: Rede de segurança — reconciliação, alerta e liberação manual (v22.0) - Pattern Map

**Mapeado em:** 2026-08-13
**Arquivos analisados:** 13 novos + 2 migrations opcionais
**Análogos encontrados:** 13 / 13 (todos os arquivos previstos têm análogo real lido linha a linha)

Esta fase é composição pura sobre a Fase 129. Nenhum análogo abaixo foi inferido — todos foram
abertos e lidos de fato. Onde o RESEARCH.md já tinha citado um trecho, este documento aprofunda com
a leitura direta do arquivo (linhas exatas) para o planner colar sem re-abrir nada.

## File Classification

| Novo/Modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Console/Commands/ClicksignReconciliar.php` | console command (orquestrador) | batch → dispatch por-registro | `app/Console/Commands/SyncAdmanData.php` | exato (mesmo padrão fan-out) |
| `app/Console/Commands/ClicksignAlertarPresos.php` | console command (leitura local + notify) | batch, sem HTTP | `app/Console/Commands/AnalyzeSugadores.php` (estrutura) + `HubspotWebhookController::notificarComercialSePendente()` (disparo) | role-match forte |
| `app/Console/Commands/ClicksignVerificarVarredura.php` | console command (auto-monitoramento) | batch, sem HTTP | mesma dupla acima; não existe hoje nenhum comando "verifica ausência de carimbo" — é composição nova dos dois padrões | role-match (sem precedente exato de "checa staleness") |
| `app/Jobs/ReconciliarContratoClicksignJob.php` | job (fila, HTTP externo) | request-response externo + escrita | `app/Jobs/ProcessarEventoClicksignJob.php` | exato (mesma família Clicksign) |
| `app/Notifications/ContratoPresoNotification.php` (ou nome equivalente) | notification | event-driven | `app/Notifications/EmpresaHubspotPendenteNotification.php` | exato |
| `app/Support/AudienciaRedeSeguranca.php` (nome sugerido pelo RESEARCH; confirmar no plano) | serviço puro (resolução de audiência) | transform | `app/Support/AudienciaComercial.php` | role-match (⚠️ ver seção Shared Patterns — NÃO copiar a lógica interna, só a forma da classe) |
| `app/Http/Controllers/ContratoLiberacaoManualController.php` | controller (index+store) | request-response, CRUD parcial (create-only) | `app/Http/Controllers/ContratoPdfAssinadoController.php` (trava `role:admin` + guard de caminho) + `AdminController::configuracoesFinanceiro()`/`salvarConfiguracoesFinanceiro()` (par index/store com `Inertia::render`+`validate`+`back()->with('success')`) | exato (dois análogos combinados) |
| `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` | página React (form admin) | request-response | `resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx` | exato |
| `app/Models/ContratoLiberacao.php` (MODIFICADO — só adicionar constantes) | model (constantes de domínio) | — | próprio arquivo atual (ver abaixo) | exato (é edição, não criação) |
| `app/Models/ContratoAssinatura.php` (MODIFICADO — coluna `ultimo_alerta_em` opcional) | model | — | próprio arquivo atual | exato |
| `database/migrations/..._add_ultimo_alerta_em_to_contrato_assinaturas_table.php` | migration aditiva | — | `2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php` | exato (mesma tabela, mesma técnica) |
| `database/migrations/..._add_motivo_slug_to_contrato_liberacoes_table.php` (opcional, D-12 opção 2) | migration aditiva | — | mesmo análogo acima | exato |
| `tests/Feature/Phase130/*.php` (8 arquivos, ver RESEARCH §9) | teste Feature | — | `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php` (corrida) + `tests/Feature/Phase35HubspotNotifyTest.php` (Notification::fake) + `ClicksignVerificarAssinaturaCommandTest.php` (artisan()->assertExitCode) | exato (3 análogos, um por tipo de teste) |

## Pattern Assignments

### `app/Console/Commands/ClicksignReconciliar.php` (console command, batch → fan-out)

**Análogo:** `app/Console/Commands/SyncAdmanData.php` (lido inteiro, 103 linhas)

**Padrão a copiar — SELECT + fan-out de dispatch, sem HTTP direto no comando** (linhas 73-98):
```php
$companies = Company::query()
    ->where('active', true)
    ->where(function ($q) { /* ... filtro de elegibilidade ... */ })
    ->get();

$total = $companies->count();
if ($total === 0) {
    $this->info('Nenhuma empresa ativa com ID Adman/ML — nada a enfileirar.');
    return self::SUCCESS;
}

foreach ($companies as $i => $company) {
    SyncAdmanCompanyJob::dispatch($company)
        ->delay(now()->addSeconds($i * 7));
}

$this->info("Enfileirados {$total} SyncAdmanCompanyJob...");
return self::SUCCESS;
```

**Adaptação para esta fase (RESEARCH §Pattern 1, D-06/D-07/D-08):** trocar o `SyncAdmanCompanyJob`
por `ReconciliarContratoClicksignJob`, e a query por DUAS seleções separadas (RESEARCH linha
330-345):
```php
// Escopo 1 — reconsulta Clicksign (D-07)
ContratoAssinatura::where('status', ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS)
    ->whereNotNull('clicksign_envelope_id')
    ->get();

// Escopo 2 — PDF pendente (D-08), redisparo direto sem job novo
ContratoAssinatura::where('status', ContratoAssinatura::STATUS_ASSINADO)
    ->whereNull('pdf_assinado_path')
    ->get();
```

⚠️ **Não copiar o `->delay($i * 7)` de `SyncAdmanData`** — aquele delay existe para respeitar
10 rpm do bucket Adman (`ADMAN_RATE_LIMIT_RPM`). O bucket desta fase (`clicksign-webhook`, 3/min
GLOBAL — achado do RESEARCH, `AppServiceProvider.php`) já é aplicado DENTRO do job via
`RateLimited('clicksign-webhook')` (ver seção do Job abaixo) — duplicar o throttle no comando e no
job seria redundante e dificultaria calibrar o número certo em um lugar só.

**Carimbo de execução (D-09, RESEARCH pergunta 1) — grava ao final do `handle()`:**
```php
Configuracao::set('clicksign_reconciliacao_status', json_encode([
    'executado_em'      => now()->toIso8601String(),
    'vistos'            => $vistos,
    'corrigidos'        => $corrigidos,
    'pdfs_redisparados' => $pdfsRedisparados,
    'erro'              => null,
]));
```
Se o `handle()` inteiro lançar exceção antes de chegar aqui, capturar em `try/catch (\Throwable $e)`
no nível do comando e gravar o carimbo com `'erro' => $e->getMessage()` mesmo assim — senão o
comando "morre calado" e o D-09 (checagem de ausência) fica sem sinal do motivo.

---

### `app/Console/Commands/ClicksignAlertarPresos.php` (console command, batch sem HTTP)

**Análogo de disparo de notificação (audiência + envio + log defensivo):**
`app/Http/Controllers/Api/HubspotWebhookController.php` linhas 976-1012 — método
`notificarComercialSePendente()`, lido inteiro:

```php
private function notificarComercialSePendente(Company $company, HubspotEvento $evento): void
{
    try {
        $pendencias = $this->calcularPendencias($company);
        if (empty($pendencias)) {
            return;
        }

        $audiencia = AudienciaComercial::lideresEPermissionados();
        if ($audiencia->isEmpty()) {
            Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Audiencia Comercial vazia — notificacao nao enviada', [
                'evento_id'  => $evento->id,
                'company_id' => $company->id,
                'pendencias' => $pendencias,
            ]);
            return;
        }

        Notification::send(
            $audiencia,
            new EmpresaHubspotPendenteNotification($company, $pendencias),
        );

        Log::channel('ecf-webhooks')->info('[HubSpot Webhook] Notificacao Comercial enviada', [
            'evento_id'           => $evento->id,
            'company_id'          => $company->id,
            'pendencias'          => $pendencias,
            'destinatarios_count' => $audiencia->count(),
        ]);
    } catch (\Throwable $e) {
        Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Falha ao notificar Comercial (nao bloqueia o webhook)', [
            'evento_id'  => $evento->id,
            'company_id' => $company->id,
            'erro'       => $e->getMessage(),
        ]);
    }
}
```

**Adaptar:** trocar `AudienciaComercial::lideresEPermissionados()` por
`AudienciaRedeSeguranca::adminsEComercial()` (D-02 — ver seção Shared Patterns, é DIFERENTE de
propósito) e `EmpresaHubspotPendenteNotification` pela notification nova desta fase. O padrão
"log warning quando audiência vazia, log info com `destinatarios_count` quando envia" é a MESMA
disciplina pedida no `code_context` do CONTEXT.md — copiar literalmente, não reinventar.

**Query completa do recorte "preso" (D-03/D-04/D-05 combinados)** — já resolvida linha a linha no
RESEARCH.md (linhas 754-776), reproduzida aqui por ser o núcleo do comando:
```php
$candidatos = ContratoAssinatura::whereIn('status', ContratoAssinatura::STATUS_TODOS)
    ->whereNull('liberado_em')
    ->get()
    ->filter(function (ContratoAssinatura $c) {
        $dataBase = match ($c->status) {
            ContratoAssinatura::STATUS_RASCUNHO => $c->created_at,
            ContratoAssinatura::STATUS_AGUARDANDO_ASSINATURAS => $c->enviado_em ?? $c->created_at,
            ContratoAssinatura::STATUS_ASSINADO => $c->assinado_em ?? $c->updated_at,
            default => $c->updated_at,
        };
        $limiarDias = min(
            (int) Configuracao::get('rede_alerta_dias_fixo', 5),
            (int) round($c->prazoDiasEfetivo() * (float) Configuracao::get('rede_alerta_fracao_prazo', 0.5))
        );
        return $dataBase->diffInDays(now()) >= $limiarDias;
    })
    ->filter(function (ContratoAssinatura $c) {
        $intervalo = (int) Configuracao::get('rede_alerta_repeticao_dias', 3);
        return $c->ultimo_alerta_em === null || $c->ultimo_alerta_em->lt(now()->subDays($intervalo));
    });
```
`ContratoAssinatura::STATUS_TODOS` já existe (`app/Models/ContratoAssinatura.php` linhas 102-109,
os 7 estados). `prazoDiasEfetivo()` já existe (linhas 230-233, ver excerpt abaixo).

**`prazoDiasEfetivo()` — leitura direta, não reimplementar** (`app/Models/ContratoAssinatura.php`
linhas 230-233):
```php
public function prazoDiasEfetivo(): int
{
    return $this->prazo_dias ?? (int) config('services.clicksign.prazo_dias_padrao');
}
```
⚠️ Existe também `lembreteDiasEfetivo()` (linhas 240-243) — **não usar**, é o `remind_interval`
nativo da Clicksign para o SIGNATÁRIO cliente, propósito e público diferentes do alerta desta fase
(equipe ECF). Confundir os dois é o Pitfall citado no RESEARCH.

---

### `app/Console/Commands/ClicksignVerificarVarredura.php` (auto-monitoramento, D-09)

**Sem análogo direto no projeto** — não existe hoje nenhum comando "verifica se outro comando
rodou". É composição de dois padrões já lidos acima: leitura via `Configuracao::get()` (ver
`Configuracao.php` abaixo) + disparo de notificação (mesmo padrão de `ClicksignAlertarPresos`,
reusar a MESMA `AudienciaRedeSeguranca` e a MESMA notification, com mensagem distinta — "a
varredura de reconciliação não rodou hoje").

```php
$statusJson = Configuracao::get('clicksign_reconciliacao_status');
$status     = $statusJson ? json_decode($statusJson, true) : null;
$executadoEm = $status ? Carbon::parse($status['executado_em']) : null;

if ($executadoEm === null || $executadoEm->lt(now()->subHours(26))) {
    // dispara a mesma notificação/audiência da D-02, mensagem distinta
}
```
Agendar em horário DIFERENTE de `clicksign:reconciliar` (RESEARCH: reconciliação 07:00 → checagem
08:00, 1h de folga). Ver `routes/console.php` para o padrão de `dailyAt` abaixo.

---

### `app/Jobs/ReconciliarContratoClicksignJob.php` (job, HTTP externo + escrita)

**Análogo:** `app/Jobs/ProcessarEventoClicksignJob.php` (lido inteiro, 327 linhas) — é
LITERALMENTE o mesmo trabalho (reconsultar envelope, sincronizar signatários, avaliar gate, liberar
empresa, redisparar PDF), só trocando o gatilho de "evento chegou" para "varredura decidiu olhar".

**Imports** (linhas 1-20):
```php
namespace App\Jobs;

use App\Models\ContratoAssinatura;
use App\Models\ContratoAssinaturaEvento;
use App\Models\ContratoAssinaturaSignatario;
use App\Models\ContratoLiberacao;
use App\Services\Clicksign\ClicksignClient;
use App\Services\Clicksign\ContratoSignatariosSyncService;
use App\Services\Contratos\GateLiberacaoOperacionalService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
```
Trocar `ContratoAssinaturaEvento` pelo `ContratoAssinatura $contratoAssinatura` como parâmetro do
construtor (não há evento por trás da reconciliação — RESEARCH Anti-Pattern explícito: "Ler
`$evento->payload` dentro do job de reconciliação" está na lista do que NÃO fazer).

**Middleware — mesmo bucket, chave adaptada** (linhas 79-87, e comparar com
`BaixarPdfContratoAssinadoJob.php` linhas 70-76 para a variante por-contrato):
```php
public function middleware(): array
{
    $chave = 'clicksign-reconciliar-' . $this->contratoAssinatura->id;

    return [
        (new WithoutOverlapping($chave))->releaseAfter(10),
        new RateLimited('clicksign-webhook'), // MESMO bucket — 3/min GLOBAL
    ];
}
```

**Núcleo do `handle()` — reconsulta + sync + gate + liberação** (linhas 148-240, trecho relevante):
```php
$envelope       = $client->consultarEnvelope($contrato->clicksign_envelope_id);
$statusEnvelope = $envelope['attributes']['status'] ?? null; // NUNCA $envelope['data']['attributes']

if (filled($contrato->clicksign_document_id)) {
    $eventosDoc = $client->listarEventosDoDocumento($contrato->clicksign_envelope_id, $contrato->clicksign_document_id);
    $sync->aplicar($contrato, $eventosDoc);
}

$veredito = $gate->avaliar($contrato, $envelope);

if ($veredito['liberar'] === true) {
    $assinadoEm = $contrato->signatarios()
        ->where('papel', ContratoAssinaturaSignatario::PAPEL_CONTRATANTE)
        ->max('assinado_em');

    $contrato->status      = ContratoAssinatura::STATUS_ASSINADO;
    $contrato->assinado_em = $assinadoEm ?? now();
    $contrato->save();

    $router->liberarEmpresa($contrato->company, $contrato->servico, ContratoLiberacao::VIA_RECONCILIACAO, contrato: $contrato);

    if ($contrato->status === ContratoAssinatura::STATUS_ASSINADO && blank($contrato->pdf_assinado_path)) {
        try {
            BaixarPdfContratoAssinadoJob::dispatch($contrato);
        } catch (\Throwable $e) {
            Log::channel('ecf-webhooks')->warning('[ReconciliarContratoClicksignJob] Falha ao enfileirar download do PDF (liberacao ja aconteceu)', [
                'contrato_id' => $contrato->id,
                'company_id'  => $contrato->company_id,
            ]);
        }
    }
}
```
⚠️ `ContratoLiberacao::VIA_RECONCILIACAO` **ainda não existe** — hoje o model só tem `VIA_WEBHOOK` e
`VIA_MANUAL` (`app/Models/ContratoLiberacao.php` linhas 49-56, lido e confirmado). É uma constante
nova de 1 linha, cabe em `string(20)` sem migration (ver seção do model abaixo).

**`failed()` — canal `ecf-webhooks`, disciplina obrigatória (IN-01 da revisão da Fase 129)**
(linhas 298-314):
```php
public function failed(\Throwable $e): void
{
    $contrato = $this->contratoAssinatura->fresh() ?? $this->contratoAssinatura;

    Log::channel('ecf-webhooks')->error('[ReconciliarContratoClicksignJob] Falha definitiva ao reconciliar', [
        'contrato_id' => $contrato->id,
        'company_id'  => $contrato->company_id,
    ]);

    // gravar sinal de erro se aplicável — ver podarPii() abaixo
}

private function podarPii(string $mensagem): string
{
    $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;
    return mb_substr($semEmail, 0, 500);
}
```
**Não usar outro canal de log** — a memória do projeto já registra que dois jobs Clicksign da Fase
129 tiveram que ser corrigidos (`IN-01`) por logar no canal padrão em vez de `ecf-webhooks`, o único
que a Fase 130 varre para triagem.

---

### `app/Notifications/ContratoPresoNotification.php` (notification)

**Análogo obrigatório, lido inteiro:** `app/Notifications/EmpresaHubspotPendenteNotification.php`
(72 linhas) + `app/Notifications/BaseNotification.php` (87 linhas).

**Herança — não sobrescrever `via()` nem `toArray()`, o payload canônico de 6 chaves vem da base**
(`BaseNotification.php` linhas 37-85):
```php
abstract class BaseNotification extends Notification
{
    public function __construct(
        public string $titulo,
        public string $mensagem,
        public Categoria $categoria,
        public ?int $autorUserId = null,
        public ?string $url = null,
        public array $meta = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'titulo'        => $this->titulo,
            'mensagem'      => $this->mensagem,
            'categoria'     => $this->categoria->value,
            'autor_user_id' => $this->autorUserId,
            'url'           => $this->url,
            'meta'          => $this->meta,
        ];
    }
}
```

**Molde de subclasse concreta** (`EmpresaHubspotPendenteNotification.php` inteiro):
```php
class EmpresaHubspotPendenteNotification extends BaseNotification
{
    private const LABELS_PENDENCIAS = [ /* mapa slug→label pt-BR */ ];

    public function __construct(Company $company, array $pendencias)
    {
        $pendenciasHumanizadas = collect($pendencias)
            ->map(fn (string $slug) => self::LABELS_PENDENCIAS[$slug] ?? $slug)
            ->implode(', ');

        parent::__construct(
            titulo:      "Empresa nova via HubSpot com pendências: {$company->name}",
            mensagem:    "Pendências: {$pendenciasHumanizadas}",
            categoria:   Categoria::MANUAL,
            autorUserId: null, // Sistema — sem autor humano
            url:         route('companies.show', $company->id),
            meta:        ['company_id' => $company->id, 'pendencias' => array_values($pendencias), 'fonte' => 'hubspot'],
        );
    }
}
```
Adaptar: `titulo` = `"Empresa parada há X dias: {$company->name}"` (ou similar, texto simples per
D-05/"linguagem simples" do CONTEXT), `mensagem` deve conter a CAUSA (rascunho parado / aguardando
além do prazo / recusado / expirado / erro — D-05) e O QUE FAZER (link para a tela de liberação
manual, D-10). `categoria: Categoria::MANUAL` (RESEARCH pergunta 5 recomenda reusar; adicionar case
novo é troca de 1 linha se o time preferir, ver `Categoria.php` abaixo). `meta` deve incluir
`contrato_assinatura_id` e `status` para a UI do sino distinguir os casos.

**Enum `Categoria` — 4 cases hoje, `MANUAL` é o fallback correto** (`app/Notifications/Categoria.php`
linhas 23-36):
```php
enum Categoria: string
{
    case META_ATRIBUIDA = 'meta_atribuida';
    case META_ATINGIDA  = 'meta_atingida';
    case MANUAL         = 'manual';
    case ALERTA_ECF     = 'alerta_ecf';
}
```

---

### `app/Support/AudienciaRedeSeguranca.php` (D-02)

**Análogo de FORMA (não de lógica interna):** `app/Support/AudienciaComercial.php` (lido inteiro,
67 linhas) — classe estática, sem dependência de HTTP/auth, `Collection<int, User>` de retorno,
união distinct por id.

```php
class AudienciaComercial
{
    public static function lideresEPermissionados(): Collection
    {
        $lideres = collect();
        $setorComercial = Setor::where('slug', 'comercial')->first();
        if ($setorComercial) {
            $lideres = $setorComercial->lideres()->where('active', true)->get();
        }

        $permissionados = User::query()
            ->where('active', true)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission(Permissions::COMERCIAL_CADASTRAR_EMPRESA));

        return $lideres->concat($permissionados)->unique('id')->values();
    }
}
```

⚠️ **NÃO copiar `Setor::lideres()`** (pivot `setor_lideres` — só líderes) — a D-02 pede TODO membro
ativo do setor, que é `Setor::membros()` (`app/Models/Setor.php` linhas 76-81, lido e confirmado):
```php
public function membros(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'user_setores')
        ->withPivot('cargo_id', 'is_principal', 'assigned_at')
        ->withTimestamps();
}
```

**Query recomendada para a classe nova** (RESEARCH pergunta 5, já verificada contra `User::isAdmin()`
e `users.active`):
```php
class AudienciaRedeSeguranca
{
    public static function adminsEComercial(): Collection
    {
        $admins = User::where('active', true)->where('role', 'admin')->get();

        $setorComercial = Setor::where('slug', 'comercial')->first();
        $comerciais = $setorComercial
            ? $setorComercial->membros()->where('active', true)->get()
            : collect();

        return $admins->concat($comerciais)->unique('id')->values();
    }
}
```

---

### `app/Http/Controllers/ContratoLiberacaoManualController.php` (D-10/D-11/D-12)

**Análogo da trava (`role:admin`) e do padrão "controller fino, sem camada extra":**
`app/Http/Controllers/ContratoPdfAssinadoController.php` (lido inteiro, 47 linhas):
```php
class ContratoPdfAssinadoController extends Controller
{
    public function download(ContratoAssinatura $contratoAssinatura): StreamedResponse
    {
        $path = $contratoAssinatura->pdf_assinado_path;
        if (blank($path)) { abort(404); }
        if (str_contains($path, '..') || ! str_starts_with($path, 'contratos/')) { abort(404); }
        if (! Storage::disk('local')->exists($path)) { abort(404); }
        return Storage::disk('local')->download($path, "contrato-{$contratoAssinatura->id}-assinado.pdf");
    }
}
```
Registro em `routes/web.php` (linhas 98-105):
```php
Route::get('/admin/contratos/{contratoAssinatura}/pdf-assinado', [\App\Http\Controllers\ContratoPdfAssinadoController::class, 'download'])
    ->middleware(['auth', 'role:admin'])
    ->name('contratos.pdf-assinado');
```
Comentário de topo do controller documenta EXPLICITAMENTE que `role:admin` é a trava CORRETA até a
Fase 131 criar `admin.contratos` — reusar a mesma frase de intenção no novo controller.

**Análogo do par index()/store() com `Inertia::render` + `validate()` + `back()->with('success')`:**
`AdminController::configuracoesFinanceiro()`/`salvarConfiguracoesFinanceiro()`
(`app/Http/Controllers/AdminController.php` linhas 768-802):
```php
public function configuracoesFinanceiro()
{
    $json          = Configuracao::get('email_destinatarios_fechamento');
    $destinatarios = $json ? json_decode($json, true) : [];

    return Inertia::render('Admin/ConfiguracoesFinanceiro', [
        'destinatarios' => $destinatarios,
        // ... demais props
    ]);
}

public function salvarConfiguracoesFinanceiro(Request $request)
{
    $validated = $request->validate([
        'destinatarios'    => 'array',
        'destinatarios.*'  => 'email',
        // ...
    ]);

    Configuracao::set('email_destinatarios_fechamento', json_encode($validated['destinatarios'] ?? []));
    // ...

    return back()->with('success', 'Configurações salvas com sucesso.');
}
```

**Rota — grupo `role:admin` já existe em `routes/web.php` linha 1009** (`Route::middleware(['auth',
'verified', 'role:admin'])->prefix('administrativo')->name('admin.')->group(...)`); a rota da D-10
pode entrar DENTRO desse grupo existente (`admin.contratos-liberacao-manual.*`) OU num par próprio
como o RESEARCH sugeriu (`admin/contratos/liberacao-manual`, fora do prefixo `administrativo`,
espelhando `ContratoPdfAssinadoController`). **Decisão do plano** — os dois padrões já existem no
projeto, nenhum é "o errado".

**Controller `store()` — D-11 (ignora o gate, mostra estado real) + D-12 (motivo lista+detalhe)**
(molde do RESEARCH, adaptado com os nomes reais confirmados):
```php
public function store(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
{
    $data = $request->validate([
        'company_id'     => 'required|exists:companies,id',
        'servico_id'     => 'required|exists:servicos,id',
        'motivo_slug'    => ['required', Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)],
        'motivo_detalhe' => 'required|string|max:1000',
    ]);

    $router->liberarEmpresa(
        Company::findOrFail($data['company_id']),
        Servico::findOrFail($data['servico_id']),
        ContratoLiberacao::VIA_MANUAL, // já existe, zero mudança
        liberadoPorUserId: $request->user()->id,
        motivo: $data['motivo_slug'] . ': ' . $data['motivo_detalhe'], // ou 2 colunas, ver Migrations
    );

    return back()->with('success', 'Empresa liberada manualmente.');
}
```
⚠️ **Não chamar `GateLiberacaoOperacionalService::avaliar()` aqui** — é o núcleo da D-11: a
liberação manual IGNORA o gate de propósito. Chamá-lo tornaria a tela inútil no exato cenário em
que ela existe para ser usada (Clicksign fora do ar / envelope 404 / rascunho apagado).

**Idempotência já herdada de graça:** `EmpresaOperacionalRouter::liberarEmpresa()` (lido inteiro,
ver excerpt completo abaixo em Shared Patterns) já faz o guard de leitura +
`ContratoLiberacao::existeParaServico()` + `try/catch (QueryException $e)` sobre a constraint
`cl_empresa_servico_uniq` — o controller NÃO precisa (e não deve) duplicar nenhum desses guards.

---

### `resources/js/Pages/Admin/ContratosLiberacaoManual.jsx` (D-10, página descartável)

**Análogo, lido inteiro (246 linhas):** `resources/js/Pages/Admin/ConfiguracoesFinanceiro.jsx`.

**Padrão de imports + `useForm` + `post` com `preserveScroll`** (linhas 1-20, 48-50):
```jsx
import AppLayout from '@/Layouts/AppLayout';
import { Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { cn } from '@/lib/utils';

export default function ConfiguracoesFinanceiro({ destinatarios, ultimo_envio, /* ... */ }) {
    const { data, setData, post, processing } = useForm({
        destinatarios: destinatarios || [],
        // ...
    });

    function salvar() {
        post(route('admin.configuracoes.financeiro.salvar'), { preserveScroll: true });
    }
    // ...
}
```

**Padrão de card com tokens `ecf-*` + `cn()`** (linhas 70-97, o `inputCls` reutilizável e o card
com ícone):
```jsx
const inputCls = 'h-9 px-3 rounded-lg border border-white/[0.08] bg-white/[0.03] text-[13px] text-white/80 focus:outline-none focus:border-ecf-yellow/40 transition-colors';

<div className="rounded-xl border border-white/[0.08] bg-white/[0.02] p-6 space-y-5">
    <div className="flex items-center gap-3">
        <div className="flex items-center justify-center w-9 h-9 rounded-lg bg-ecf-yellow/10 shrink-0">
            <Mail size={16} className="text-ecf-yellow" />
        </div>
        <div>
            <h2 className="text-[15px] font-semibold text-white">Destinatários</h2>
            <p className="text-[13px] text-white/40 mt-0.5">...</p>
        </div>
    </div>
    {/* ... */}
</div>
```

**Botão salvar com `processing`** (linhas 222-229):
```jsx
<button
    type="button"
    onClick={salvar}
    disabled={processing}
    className="h-9 px-4 rounded-lg bg-ecf-yellow text-black text-[13px] font-semibold hover:bg-ecf-yellow/90 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
>
    {processing ? 'Salvando...' : 'Salvar configurações'}
</button>
```

**D-11 exige um elemento NOVO que este análogo não tem — o destaque de estado real antes de
confirmar** (ex.: "este contrato foi RECUSADO pelo cliente"). Nenhum análogo direto no projeto para
um "badge de estado" inline num form; usar `cn()` condicional no mesmo espírito do bloco "Botão
ativar/desativar" do próprio `ConfiguracoesFinanceiro.jsx` (linhas 164-175, troca de classe conforme
estado booleano) — adaptar para uma faixa de aviso vermelha quando `status === 'recusado'`/`'erro'`/
`'expirado'`:
```jsx
{contrato.status === 'recusado' && (
    <div className="flex items-center gap-2 px-3 py-2.5 rounded-lg border border-red-500/30 bg-red-500/[0.06] text-red-300 text-[13px] font-semibold">
        Este contrato foi RECUSADO pelo cliente. A liberação manual ainda é possível, mas fica registrada com autor e motivo.
    </div>
)}
```

---

### `app/Models/ContratoLiberacao.php` (MODIFICADO — só constantes, zero migration)

**Estado atual, lido inteiro** (`app/Models/ContratoLiberacao.php` linhas 49-56) — hoje só tem 2
vias:
```php
public const VIA_WEBHOOK = 'webhook';
public const VIA_MANUAL  = 'manual';

/** As 2 vias possíveis (D-05). */
public const VIA_TODAS = [
    self::VIA_WEBHOOK,
    self::VIA_MANUAL,
];
```
**Adicionar** (a coluna já é `string(20)`, "reconciliacao" tem 14 chars, cabe sem migration — D-05
da Fase 129 já previu):
```php
public const VIA_RECONCILIACAO = 'reconciliacao';

public const VIA_TODAS = [
    self::VIA_WEBHOOK,
    self::VIA_MANUAL,
    self::VIA_RECONCILIACAO,
];
```
**E os motivos da D-12** (RESEARCH pergunta 7, sugestão de partida):
```php
public const MOTIVO_WEBHOOK_NAO_CHEGOU        = 'webhook_nao_chegou';
public const MOTIVO_ASSINOU_FORA_DO_SISTEMA   = 'cliente_assinou_fora_do_sistema';
public const MOTIVO_DECISAO_COMERCIAL         = 'decisao_comercial';
public const MOTIVO_OUTRO                     = 'outro';

public const MOTIVOS_MANUAIS = [
    self::MOTIVO_WEBHOOK_NAO_CHEGOU,
    self::MOTIVO_ASSINOU_FORA_DO_SISTEMA,
    self::MOTIVO_DECISAO_COMERCIAL,
    self::MOTIVO_OUTRO,
];
```

---

## Reuso — NÃO virar arquivo novo

### `app/Services/Operacional/EmpresaOperacionalRouter.php` — `liberarEmpresa()` (lido inteiro, 364 linhas)

**Já resolve o SC4 desta fase.** O plano deve PROVAR que resolve (teste adaptado), não
reimplementar. Corpo completo do método relevante (linhas 252-327):
```php
public function liberarEmpresa(
    Company $company,
    Servico $servico,
    string $via,
    ?ContratoAssinatura $contrato = null,
    ?int $liberadoPorUserId = null,
    ?string $motivo = null,
    array $handoff = []
): ContratoLiberacao {
    $existente = ContratoLiberacao::existeParaServico($company->id, $servico->id);
    if ($existente !== null) {
        return $existente;
    }

    try {
        $liberacao = ContratoLiberacao::create([
            'company_id'             => $company->id,
            'servico_id'             => $servico->id,
            'contrato_assinatura_id' => $contrato?->id,
            'via'                    => $via,
            'liberado_por_user_id'   => $liberadoPorUserId,
            'motivo'                 => $motivo,
            'gerou_ficha'            => false,
            'liberado_em'            => now(),
        ]);
    } catch (QueryException $e) {
        if ((string) $e->getCode() === '23000') {
            return ContratoLiberacao::existeParaServico($company->id, $servico->id) ?? throw $e;
        }
        throw $e;
    }

    $gerouFicha = $this->aplicarRoteamento($company, [$servico->nome], $handoff, guardPorEmpresa: true);

    if ($gerouFicha) {
        $liberacao->gerou_ficha = true;
        $liberacao->save();
    }

    if ($contrato !== null) {
        $contrato->liberado_em = now();
        $contrato->save();
    }

    return $liberacao;
}
```
**A trava (lock) vive dentro de `aplicarRoteamento()`**, chamado por `liberarEmpresa()` — a chave é
`'operacional-guarda-empresa-' . $companyId` (`Cache::lock()`, TTL 10s, `protected function
lockDaEmpresa()` linhas 208-211). Herdada automaticamente por QUALQUER chamador de
`liberarEmpresa()`, inclusive o controller da D-10 e o job de reconciliação — nenhum dos dois
precisa (nem deve) criar `Cache::lock()` próprio.

### `app/Models/ContratoLiberacao.php` — coluna `via` já é `string(20)` com `VIA_TODAS`

Ver migration `2026_08_14_100001_create_contrato_liberacoes_table.php` linha 59 —
`$table->string('via', 20);` — "reconciliacao" (14 chars) e "manual" (6 chars) cabem sem alterar
schema.

### `app/Models/Configuracao.php` — candidato ao carimbo da D-09 (API real, lido inteiro, 35 linhas)

```php
class Configuracao extends Model
{
    protected $table = 'configuracoes';
    protected $fillable = ['chave', 'valor'];

    public static function get(string $chave, $default = null): mixed
    {
        return static::where('chave', $chave)->value('valor') ?? $default;
    }

    public static function set(string $chave, $valor): void
    {
        static::updateOrCreate(['chave' => $chave], ['valor' => $valor]);
    }
}
```
`valor` é `text`, não JSON nativo — gravar `json_encode()` numa chave, ler com `json_decode(...,
true)`. Zero migration. Docblock da classe já lista "datas de último envio" como exemplo de uso —
é literalmente o caso do D-09.

### `app/Services/Clicksign/ClicksignClient.php` — `consultarEnvelope()` (lido inteiro, linhas 323-326 + `enviar()` 742-797)

```php
public function consultarEnvelope(string $envelopeId): array
{
    return $this->enviar('get', "/envelopes/{$envelopeId}", [], 'consultar envelope');
}
```
**Confirmado por leitura de `enviar()` (linha 779): `return $res->json('data') ?? [];`** — o
método já STRIPA a chave `data` do envelope JSON:API antes de devolver. Por isso o array retornado
tem `attributes` NO TOPO (`$envelope['attributes']['status']`), nunca
`$envelope['data']['attributes']['status']`. Isso é o mesmo array que
`GateLiberacaoOperacionalService::avaliar(ContratoAssinatura, array $envelopeReconsultado)` já
espera como segundo parâmetro — reuso direto, zero adaptação de shape.

### `app/Jobs/BaixarPdfContratoAssinadoJob.php` — o job que a D-08 redispara (lido inteiro, 213 linhas)

Já é idempotente por construção — guard no topo do `handle()` (linhas 82-86):
```php
if (filled($contrato->pdf_assinado_path) && Storage::disk('local')->exists($contrato->pdf_assinado_path)) {
    return;
}
```
E já tem `WithoutOverlapping('clicksign-pdf-' . $this->contratoAssinatura->id)` (linha 73). Chamar
`BaixarPdfContratoAssinadoJob::dispatch($contrato)` de novo a partir da reconciliação é seguro por
desenho — **nenhum guard adicional é necessário no comando/job novo desta fase**.

---

## Shared Patterns

### Canal de log `ecf-webhooks` — obrigatório em TODO job desta fase

**Fonte:** `app/Jobs/ProcessarEventoClicksignJob.php` linha 305, `app/Jobs/
BaixarPdfContratoAssinadoJob.php` linha 193.
**Aplicar a:** `ReconciliarContratoClicksignJob`, e qualquer log de `ClicksignReconciliar`/
`ClicksignAlertarPresos`/`ClicksignVerificarVarredura` que toque o mesmo subsistema.
```php
Log::channel('ecf-webhooks')->error('[NomeDoJob] Falha definitiva ...', [ /* ids, nunca payload cru */ ]);
```
A memória do projeto já registra que dois jobs da Fase 129 tiveram que ser corrigidos (IN-01) por
logar no canal padrão — é a ÚNICA fonte de triagem que a Fase 130 varre.

### `role:admin` — trava de acesso até a Fase 131

**Fonte:** `routes/web.php` linhas 100-105 (`ContratoPdfAssinadoController`) e linha 1009 (grupo
`administrativo`).
**Aplicar a:** rota(s) de `ContratoLiberacaoManualController`.
```php
Route::middleware(['auth', 'verified', 'role:admin'])->group(function () {
    Route::get('/admin/contratos/liberacao-manual',  [ContratoLiberacaoManualController::class, 'index'])->name('contratos.liberacao-manual.index');
    Route::post('/admin/contratos/liberacao-manual', [ContratoLiberacaoManualController::class, 'store'])->name('contratos.liberacao-manual.store');
});
```

### `EmpresaOperacionalRouter::liberarEmpresa()` — ponto único, idempotente, travado

Ver excerpt completo na seção Reuso acima. **Aplicar a:** `ContratoLiberacaoManualController::store()`
e `ReconciliarContratoClicksignJob::handle()`. Nenhum dos dois deve criar `Cache::lock()` próprio
nem duplicar o guard `ContratoLiberacao::existeParaServico()`.

### `Notification::send($audiencia, new X())` + log de audiência vazia

**Fonte:** `app/Http/Controllers/Api/HubspotWebhookController.php` linhas 976-1012 (excerpt completo
na seção do comando de alerta acima).
**Aplicar a:** `ClicksignAlertarPresos` e `ClicksignVerificarVarredura` — os dois disparam
notificação para a mesma audiência (D-02), e os dois devem logar `warning` quando a audiência vem
vazia em vez de falhar silenciosamente.

### ⚠️ Divergência a NÃO herdar: `AudienciaComercial::lideresEPermissionados()` é mais estreita que a D-02

**Fonte:** `app/Support/AudienciaComercial.php` linhas 42-66 (excerpt completo acima).
Esse helper devolve (a) só LÍDERES do setor Comercial (`Setor::lideres()`, pivot `setor_lideres`) +
(b) usuários com a permission `comercial.cadastrar_empresa`. A D-02 desta fase quer TODO membro
ATIVO do setor Comercial (`Setor::membros()`, pivot `user_setores`) união `role:admin`. Um analista
comercial comum, membro do setor mas sem a permission nem liderança, NÃO apareceria em
`lideresEPermissionados()` — é exatamente a lacuna que a D-02 aponta. **Usar `Setor::membros()`, não
`Setor::lideres()`, na classe nova.**

### Agendamento — `Schedule::command(...)->dailyAt(...)->withoutOverlapping()->name(...)`

**Fonte:** `routes/console.php` linhas 15-38 (excerpt completo):
```php
Schedule::command('adman:sync')
    ->dailyAt('11:00')
    ->name('adman-sync-d1')
    ->withoutOverlapping();
```
**Aplicar a:** as 3 entradas novas (`clicksign:reconciliar`, `clicksign:alertar-presos`,
`clicksign:verificar-varredura`). O comentário de topo de cada bloco no `routes/console.php`
existente sempre justifica O HORÁRIO escolhido (dependência de outro job, fuso BRT) — seguir a
mesma disciplina de comentário, e lembrar (D-06/RESEARCH) que a checagem de ausência (D-09) precisa
rodar em horário DIFERENTE do `clicksign:reconciliar`, com folga (ex.: reconciliação 07:00,
checagem 08:00).

---

## Excerpts de Migration — armadilhas de MariaDB (caso o plano prefira tabela nova a `Configuracao`)

Três migrations do próprio subsistema (Fase 125/129) já documentam as 3 armadilhas no comentário de
topo — reproduzidas aqui com a técnica exata usada:

### 1. Migration aditiva simples, sem FK, sem índice — molde para `ultimo_alerta_em`

**Fonte:** `database/migrations/2026_08_14_100002_add_pdf_assinado_erro_to_contrato_assinaturas_table.php` (lida inteira, 51 linhas):
```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_erro')) {
                $table->text('pdf_assinado_erro')->nullable()->after('pdf_assinado_path');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'pdf_assinado_erro')) {
                $table->dropColumn('pdf_assinado_erro');
            }
        });
    }
};
```
Trocar `pdf_assinado_erro`/`text` por `ultimo_alerta_em`/`timestamp`. O guard
`Schema::hasColumn(...)` antes de adicionar/remover é a disciplina do projeto para migrations
idempotentes (relevante se alguém rodar a migration 2x em ambientes diferentes).

### 2. FK `nullOnDelete()` EXIGE `->nullable()` na coluna (erro 1830 — invisível no SQLite)

**Fonte:** `database/migrations/2026_08_14_100001_create_contrato_liberacoes_table.php` linhas
61-65 e 104-110 (lida inteira):
```php
// Nullable é OBRIGATÓRIO: a FK abaixo é `nullOnDelete` e o MariaDB recusa
// `ON DELETE SET NULL` em coluna NOT NULL (erro 1830, invisível no SQLite).
$table->unsignedBigInteger('liberado_por_user_id')->nullable();
// ...
$table->foreign('liberado_por_user_id', 'cl_user_fk')
    ->references('id')->on('users')
    ->nullOnDelete();
```
Não se aplica a `ultimo_alerta_em` (sem FK). Aplicaria SE o plano optar pela D-12 opção 2
(`motivo_slug` novo) — mas `motivo_slug` é string livre, sem FK, então também não é afetada.

### 3. Nome de índice/constraint acima de 64 caracteres — falha SILENCIOSA (erro 1059)

**Fonte:** mesma migration, linhas 92-94 e comentário de topo (linhas 34-37):
```php
// Nome curto de propósito — armadilha 1059: nome de índice/constraint acima
// de 64 caracteres falha SILENCIOSAMENTE (cria a tabela SEM o índice e
// deixa a migration `Pending`).
$table->unique(['company_id', 'servico_id'], 'cl_empresa_servico_uniq');
$table->index('liberado_em', 'cl_liberado_em_idx');

$table->foreign('company_id', 'cl_company_fk')
    ->references('id')->on('companies')
    ->cascadeOnDelete();
```
Todo índice/FK desta migration é NOMEADO À MÃO com prefixo curto (`cl_`). Nenhuma migration prevista
nesta fase (`ultimo_alerta_em`, `motivo_slug` opcional) precisa de índice novo — nenhum dos dois é
critério de busca — mas SE o plano decidir indexar `ultimo_alerta_em` para acelerar a query do
alerta, seguir esta convenção (`ca_ultimo_alerta_idx` ou similar, curto).

### 4. `string()`, nunca `enum()` de banco — CHECK do MariaDB derruba o SQLite dos testes

**Fonte:** `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` linhas
56-59 (lida inteira, comentário + código):
```php
// STRING, nunca `enum` de banco (D-04). CHECK de enum quebra a suíte no
// SQLite e exige migration por driver pra crescer.
$table->string('status', 40)->default('rascunho');
```
Já é a convenção usada por `via` (`contrato_liberacoes`) e por `status` (`contrato_assinaturas`).
Nenhuma coluna nova desta fase (`ultimo_alerta_em`, `motivo_slug`) é um vocabulário fechado que
precisaria de `enum` de qualquer forma — `motivo_slug`, se criado, também deve ser `string`, com a
lista fechada garantida em código (`Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)` no controller, não
no schema).

---

## Testes — Feature em `tests/Feature/Phase130/`

### Análogo obrigatório de corrida (SC4): `tests/Feature/Phase129/LiberarEmpresaCorridaConcorrenteTest.php`

Lido inteiro (199 linhas). Técnica: subclasse anônima de `EmpresaOperacionalRouter` que troca
`lockDaEmpresa()` (protected) por um decorator que dispara a "chamada concorrente" exatamente antes
de disputar a trava real — sem paralelismo de SO de verdade (PHPUnit é single-thread). Excerpt do
núcleo (linhas 71-124 e 126-163):
```php
private function routerComGatilhoDeCorrida(): EmpresaOperacionalRouter
{
    return new class extends EmpresaOperacionalRouter {
        public $antesDeDisputarLock = null;

        protected function lockDaEmpresa(int $companyId): Lock
        {
            $lockReal = parent::lockDaEmpresa($companyId);
            $gatilho  = $this->antesDeDisputarLock;
            $this->antesDeDisputarLock = null;

            return new class($lockReal, $gatilho) implements Lock {
                // ... delega get/release/owner/forceRelease ao lock real;
                // block() dispara $gatilho ANTES de chamar $this->lock->block()
            };
        }
    };
}

#[Test]
public function duas_liberacoes_concorrentes_de_servicos_diferentes_da_mesma_empresa_criam_uma_unica_mlbempresa(): void
{
    $company = Company::factory()->create();
    $router  = $this->routerComGatilhoDeCorrida();

    $router->antesDeDisputarLock = function () use ($router, $company, /* servico B */) {
        $router->liberarEmpresa($company, /* servico B */, ContratoLiberacao::VIA_WEBHOOK);
    };

    $router->liberarEmpresa($company, /* servico A */, ContratoLiberacao::VIA_WEBHOOK);

    $this->assertSame(1, MlbEmpresa::where('company_id', $company->id)->count());
}
```
**Adaptar para o SC4 desta fase (RESEARCH §8):** trocar um dos dois `via` por
`ContratoLiberacao::VIA_MANUAL` (simulando a corrida real webhook × liberação manual, ou
reconciliação × manual). A asserção central continua `MlbEmpresa::where('company_id',
...)->count() === 1`. **Não escrever mecanismo de lock novo** — o `Cache::lock()` já existe e é
herdado de graça por qualquer chamador de `liberarEmpresa()`.

### Análogo de `Notification::fake()` + audiência: `tests/Feature/Phase35HubspotNotifyTest.php`

Padrão (não lido linha a linha nesta passada — já citado e confiável pelo RESEARCH, mesmo cenário
"audiência calculada + notificação disparada para cada membro + dedup"):
```php
Notification::fake();
// ... roda o comando/ação ...
Notification::assertSentTo($admin, ContratoPresoNotification::class);
Notification::assertSentTo($comercial, ContratoPresoNotification::class);
Notification::assertNotSentTo($usuarioInativo, ContratoPresoNotification::class);
```

### Análogo de teste de comando agendado: `tests/Feature/Phase129/ClicksignVerificarAssinaturaCommandTest.php`

Padrão de invocação (RESEARCH §9, mesmo arquivo listado em Sources):
```php
$this->artisan('clicksign:reconciliar')->assertExitCode(0);
```
Combinar com `Http::fake()` para `consultarEnvelope()`/`consultarDocumento()` — lembrando que
`Http::fake()` só prova FIAÇÃO (reconsulta em vez de confiar em payload antigo, guard de
idempotência funciona, redisparo do PDF acontece), não a forma real do payload (já medida nas Fases
126-129, não precisa remedir).

### Disciplina de asserção — reconsulta ao banco, nunca stdout

Repetida no RESEARCH e já registrada como aprendizado do projeto (`bonus_invalidacoes` /
`snapshot_congelado_mes_fechado`): toda asserção de consolidação desta fase deve reconsultar o banco
fresco —
```php
$this->assertSame(1, ContratoLiberacao::where('company_id', $company->id)->where('via', ContratoLiberacao::VIA_RECONCILIACAO)->count());
Notification::assertSentTo(...);
$statusFresco = json_decode(Configuracao::get('clicksign_reconciliacao_status'), true);
```
nunca `$this->artisan(...)->expectsOutput(...)` como prova de efeito colateral gravado.

## Sem análogo real

Nenhum arquivo previsto ficou sem análogo real lido. O único ponto sem precedente EXATO no projeto
é o comando de "verificar ausência de outro comando" (`ClicksignVerificarVarredura`) — é composição
nova de dois padrões já existentes (leitura de `Configuracao` + disparo de notificação), não um
padrão a copiar de um único arquivo. Sinalizado explicitamente na seção do comando acima.

## Metadata

**Escopo de busca de análogos:** `app/Console/Commands/`, `app/Jobs/`, `app/Notifications/`,
`app/Support/`, `app/Http/Controllers/`, `app/Services/Operacional/`, `app/Services/Clicksign/`,
`app/Models/`, `resources/js/Pages/Admin/`, `routes/`, `database/migrations/`, `tests/Feature/Phase129/`.
**Arquivos abertos e lidos por completo:** 17 (todos citados acima com contagem de linhas).
**Data da extração de padrões:** 2026-08-13
