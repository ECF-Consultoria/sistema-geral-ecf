# Fase 129: Webhook Clicksign (v22.0) - Mapa de Padrões

**Mapeado:** 2026-08-12
**Arquivos analisados:** 9 (novos) + 2 (modificados)
**Análogos encontrados:** 9 / 9

## File Classification

| Arquivo novo/modificado | Papel | Fluxo de dados | Análogo mais próximo | Qualidade do match |
|---|---|---|---|---|
| `app/Http/Controllers/Api/ClicksignWebhookController.php` | controller | request-response (webhook) | `app/Http/Controllers/Api/HubspotWebhookController.php` | exato |
| `app/Jobs/ProcessarEventoClicksignJob.php` | job (event-driven) | event-driven | `app/Jobs/GerarContratoAssinaturaJob.php` | exato (mesma cadeia, mesmo client) |
| `database/migrations/..._create_contrato_assinatura_eventos_table.php` | migration | CRUD (evento bruto) | `database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php` | forte (mesmo propósito) — mas precisa somar a disciplina de índice único nomeado de `..._100001_create_contrato_assinatura_signatarios_table.php` |
| `app/Models/ContratoAssinaturaEvento.php` | model | CRUD | `app/Models/HubspotEvento.php` (não lido — inferir pela migration) + `app/Models/ContratoAssinaturaSignatario.php` (convenções de fillable/const) | forte |
| `database/migrations/..._create_contrato_liberacoes_table.php` (D-05) | migration | CRUD (histórico/auditoria) | `database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php` | forte (mesma família de tabela, mesmas armadilhas de índice) |
| `app/Models/ContratoLiberacao.php` (D-05, nome sugerido) | model | CRUD | `app/Models/ContratoAssinaturaSignatario.php` | role-match |
| `app/Services/Operacional/EmpresaOperacionalRouter.php` (MODIFICADO — método novo `liberarEmpresa()`/guard por-serviço) | service | CRUD + decisão de negócio | ele mesmo (`rotearServico`/`rotearCadastro`/`criarFicha`) | exato (extensão do mesmo arquivo) |
| `app/Console/Commands/ClicksignVerificarAssinatura.php` (D-09, nome sugerido `clicksign:verificar-assinatura`) | command (sonda) | transform/verificação | `app/Console/Commands/ClicksignSondarModelo.php` | exato |
| `routes/web.php` (MODIFICADO — rota nova `/api/webhooks/clicksign`) | route | request-response | bloco `webhooks.hubspot` (linhas 75-83) | exato |
| `config/services.php` (MODIFICADO se faltar chave) | config | — | bloco `clicksign` já existente (linhas 205-254) | exato — `webhook_secret` **já existe**, não precisa criar |
| `tests/Feature/ClicksignWebhookAssinaturaTest.php` + demais (ver Wave 0 Gaps do RESEARCH) | test | request-response | `tests/Feature/Phase34HubspotWebhookTest.php` | exato |

## Pattern Assignments

### `app/Http/Controllers/Api/ClicksignWebhookController.php` (controller, request-response)

**Analog:** `app/Http/Controllers/Api/HubspotWebhookController.php`

**Imports pattern** (linhas 1-25 do análogo):
```php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\ContratoServico;
use App\Models\HubspotEvento;
// ... (Clicksign: trocar por ContratoAssinaturaEvento, ContratoAssinatura,
// ContratoAssinaturaSignatario, ClicksignClient, ProcessarEventoClicksignJob)
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
```

**Disciplina de raw body + HMAC timing-safe** (linhas 68-90) — **copiar a DISCIPLINA, NUNCA a fórmula**:
```php
public function receive(Request $request, HubspotApiClient $api): JsonResponse
{
    $rawBody = $request->getContent();
    $secret  = (string) config('services.hubspot.client_secret');

    $sigHdr = (string) $request->header('X-HubSpot-Signature-v3', '');
    $tsHdr  = (string) $request->header('X-HubSpot-Request-Timestamp', '');

    // ── 1. Valida timestamp (replay window 5min) ─────────────────────────
    $ts = (int) $tsHdr;
    if ($ts < 1 || abs((int) (microtime(true) * 1000) - $ts) > self::REPLAY_WINDOW_MS) {
        $this->gravarInvalido($rawBody, 'timestamp invalido ou ausente', $request);
        return response()->json(['error' => 'unauthorized'], 401);
    }

    // ── 2. Calcula HMAC esperado ──────────────────────────────────────────
    $methodUriBody = $request->method() . $request->fullUrl() . $rawBody . $tsHdr;
    $expected      = base64_encode(hash_hmac('sha256', $methodUriBody, $secret, true));

    if ($sigHdr === '' || !hash_equals($expected, $sigHdr)) {
        $this->gravarInvalido($rawBody, 'signature invalida ou ausente', $request);
        return response()->json(['error' => 'unauthorized'], 401);
    }
    // ...
}
```

**⚠️ Diferenças estruturais obrigatórias para a Clicksign (NÃO copiar 1:1):**
- Header é `Content-Hmac` (formato `sha256=<hex>`), não `X-HubSpot-Signature-v3`.
- **Não existe timestamp separado** — sem replay window nativa (a Clicksign não manda header de tempo). Não criar `REPLAY_WINDOW_MS` para Clicksign; mitigar replay só via idempotência de `payload_hash` (CLICK-04).
- Fórmula é uma das 4 candidatas do gate A1 (D-08) — **nenhuma delas é a do HubSpot**. Ver `129-RESEARCH.md` "Lacuna Prioritária §2" para o placeholder de varredura.
- Representação é **hex** (`sha256=<hex>`), não base64.
- Resposta em caso de assinatura inválida é `401` (igual ao HubSpot), mas o CONTEXT (D-10) é explícito: para JSON malformado/payload vazio a resposta é `200`, **diferente** do HubSpot que sempre responde `200` até para timestamp/assinatura inválidos. A Clicksign responde `401` para assinatura inválida — não copiar o "200 sempre" do HubSpot.

**Gravação bruta em falha de validação** (`gravarInvalido()`, linhas 1018-1036):
```php
private function gravarInvalido(string $rawBody, string $motivo, Request $request): void
{
    HubspotEvento::create([
        'signature_valid' => false,
        'payload'         => [
            'raw'    => mb_strcut($rawBody, 0, self::RAW_BODY_MAX_BYTES),
            'motivo' => $motivo,
            'ip'     => $request->ip(),
        ],
        'status'   => 'erro',
        'erro_msg' => $motivo,
    ]);

    Log::channel('ecf-webhooks')->warning('[HubSpot Webhook] Requisicao invalida', [
        'motivo'    => $motivo,
        'ip'        => $request->ip(),
        'body_size' => strlen($rawBody),
    ]);
}
```
Reaproveitar literal: `RAW_BODY_MAX_BYTES = 65_000`, `mb_strcut()` para truncar, `Log::channel('ecf-webhooks')`, nunca logar o secret nem o `payload` inteiro fora do truncado.

**Idempotência por `payload_hash` UNIQUE — precedente `127-06`, citado no RESEARCH:**
```php
try {
    $evento = ContratoAssinaturaEvento::create([
        'contrato_assinatura_id' => $contrato->id,
        'name'                   => $nomeEvento,
        'payload'                => $payloadDecodificado,
        'payload_hash'           => hash('sha256', $rawBody),
    ]);
} catch (\Illuminate\Database\QueryException $e) {
    if ((string) $e->getCode() === '23000') {
        return response()->json(['ok' => true]); // já processado — idempotência OK
    }
    throw $e;
}
```
**Usar `$e->getCode()` (SQLSTATE), nunca `$e->errorInfo[1]`** — MariaDB/SQLite divergem no código numérico do MySQL, não no SQLSTATE.

**Rota escapando do CSRF** — `bootstrap/app.php:21-24` já isenta `api/webhooks/*`, **não precisa tocar nesse arquivo**:
```php
$middleware->validateCsrfTokens(except: [
    'implementacao/*',
    'api/webhooks/*',   // Phase 26 — receivers HMAC
]);
```
Bloco de rota a copiar de `routes/web.php:75-83` (precedente exato, inclusive o middleware defensivo redundante e o rate limit):
```php
// ─── Receiver de webhooks HubSpot (Phase 34 Plan 34-04) ──────────────────────
Route::post('/api/webhooks/hubspot', [\App\Http\Controllers\Api\HubspotWebhookController::class, 'receive'])
    ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
    ->middleware('throttle:60,1')
    ->name('webhooks.hubspot');
```
**Nota:** o namespace real da classe de middleware neste projeto (Laravel 12) é `\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken`, **não** `VerifyCsrfToken` (o RESEARCH.md citou o nome antigo — o código real diverge; usar o nome confirmado aqui).

---

### `app/Jobs/ProcessarEventoClicksignJob.php` (job, event-driven)

**Analog:** `app/Jobs/GerarContratoAssinaturaJob.php`

**Estrutura de classe (tries/timeout/backoff)** (linhas 36-66):
```php
class GerarContratoAssinaturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 300;

    public function __construct(public readonly ContratoAssinatura $contratoAssinatura)
    {
    }

    public function backoff(): array
    {
        return [60, 300, 900];
    }
}
```
Para o job da 129, o construtor deve receber `ContratoAssinaturaEvento $evento` (não o contrato — o evento é o que dispara, e pode não ter contrato resolvido ainda em casos raros). `tries`/`backoff` podem seguir os mesmos valores — mesma classe de problema (chamada composta à Clicksign).

**`handle()` — chamar `ClicksignClient::consultarEnvelope()`, nunca reusar payload do webhook** (D-06, D-12 — reforçado por `GerarContratoAssinaturaJob::handle()` linhas 135-203 como modelo de estrutura: guard de reentrega no topo, log com `contrato_id`/`company_id`, nunca PII).

**`failed()` — estado de falha legível (D-11)** (linhas 217-229):
```php
public function failed(\Throwable $e): void
{
    $contrato = $this->contratoAssinatura->fresh() ?? $this->contratoAssinatura;

    Log::error('[GerarContratoAssinaturaJob] Falha definitiva ao montar envelope', [
        'contrato_id' => $contrato->id,
        'company_id'  => $contrato->company_id,
    ]);

    $contrato->status        = ContratoAssinatura::STATUS_ERRO;
    $contrato->erro_mensagem = $this->podarPii($e->getMessage());
    $contrato->save();
}

private function podarPii(string $mensagem): string
{
    $semEmail = preg_replace('/[^\s]+@[^\s]+/', '[e-mail removido]', $mensagem) ?? $mensagem;
    return mb_substr($semEmail, 0, 500);
}
```
Aplicar a mesma disciplina de `podarPii()` no job da 129 — a `ContratoAssinaturaEvento` (ou um campo próprio nela) precisa registrar o estado de falha legível que a Fase 130 vai ler (D-11, "sem canal de alerta novo, só o sinal").

**Middleware de rate-limit/exclusão mútua** (linhas 90-96) — só necessário se o job da 129 também fizer chamadas custosas contra a mesma janela de 20/min; avaliar se `ProcessarEventoClicksignJob` precisa do mesmo `WithoutOverlapping`/`RateLimited('clicksign-envelope')` já registrado em `AppServiceProvider::boot()` (linhas 69-86) — a reconsulta de envelope (D-06) é 1 chamada, mais leve que os 15 de `montarEnvelope*()`, mas ainda compete pela mesma janela.

---

### `database/migrations/..._create_contrato_assinatura_eventos_table.php` (migration, CRUD)

**Analog primário:** `database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php` (schema de evento bruto de provedor)
**Analog secundário (disciplina de índice):** `database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php`

**Schema de referência do HubSpot** (linhas 19-64):
```php
Schema::create('hubspot_eventos', function (Blueprint $table) {
    $table->id();
    $table->tinyInteger('signature_valid')->default(0);
    $table->string('portal_id', 50)->nullable();
    // ... campos específicos do HubSpot ...
    $table->json('payload');
    $table->enum('status', ['recebido', 'processado', 'ignorado', 'erro'])->default('recebido');
    $table->text('erro_msg')->nullable();
    $table->foreignId('company_id_criada')->nullable()->constrained('companies')->nullOnDelete();
    $table->timestamp('processado_em')->nullable();
    $table->timestamps();

    $table->index(['status', 'created_at']);
    $table->index('object_id');
});
```

**⚠️ Divergências obrigatórias para `contrato_assinatura_eventos` (não copiar 1:1):**
1. **`status` deve ser STRING, não `enum` de banco.** O `hubspot_eventos.status` usa `enum` — o RESEARCH.md (§1 "Lacuna Prioritária") recomenda explicitamente **NÃO fazer isso** para `name` (nome do evento) porque a lista de eventos documentada não é exaustiva (`update_block_after_refusal` medido e não documentado). Pela convenção geral do projeto (D-04 já usada em `ContratoAssinatura`/`ContratoAssinaturaSignatario`: "STRING + constantes, nunca enum de banco"), a coluna `status` desta tabela também deve seguir STRING — coerência com o resto da cadeia Clicksign, e evita a armadilha de `enum` + MariaDB CHECK já registrada no projeto (`project_enum_setor_sqlite_check` na memória).
2. **`payload_hash` STRING(64) com índice ÚNICO nomeado à mão** — `hubspot_eventos` NÃO tem esse índice (idempotência dela é por consulta, não por constraint). Para a Clicksign o CONTEXT exige constraint de banco (corrida de retry concorrente). Nomear curto, ex. `cae_payload_hash_uniq` (ver Pitfall D do RESEARCH — a tabela some SEM o índice em silêncio se o nome autogerado passar de 64 chars).
3. **FK para `contrato_assinaturas` nomeada à mão** (mesma disciplina de `cas_contrato_fk` em `contrato_assinatura_signatarios`), porque `contrato_assinatura_eventos_contrato_assinatura_id_foreign` passa de 64 caracteres.
4. **`payload` JSON genérico, sem promover campos do `document` embutido** — Pitfall C do RESEARCH: a forma real do corpo do webhook nunca foi medida contra este projeto; promover campos antes da medição arrisca schema errado.

**Comentário de topo a seguir** — mesmo padrão do `contrato_assinatura_signatarios` (linhas 7-42): citar as decisões travadas (D-05, D-06, D-08...) e as armadilhas de MariaDB conhecidas ANTES do `Schema::create`, não depois.

---

### `database/migrations/..._create_contrato_liberacoes_table.php` (D-05, migration nova)

**Analog:** `database/migrations/2026_08_10_100001_create_contrato_assinatura_signatarios_table.php` (mesma família — tabela de histórico ligada a `contrato_assinaturas`, mesmas armadilhas de FK/índice)

Campos sugeridos pela D-05 do CONTEXT ("quem, quando, por quê e por qual via"):
- `company_id` (FK), `servico_id` (FK) — a liberação é por (empresa, serviço), D-01.
- `contrato_assinatura_id` (FK, nullable se a Fase 130 permitir liberação manual sem contrato de referência — checar D-05 do CONTEXT: "campo em `ContratoServico` separaria a liberação da evidência que a causou", logo o vínculo ao contrato é intencional).
- `via` — STRING curta (`webhook` | `manual`), nunca enum de banco.
- `liberado_por_user_id` (FK nullable, `nullOnDelete` — mesma disciplina de `user_id` em `contrato_assinatura_signatarios`, D-07).
- `liberado_em` (timestamp).
- `motivo`/`observacao` (text nullable) para a liberação manual da Fase 130.

**Guard de idempotência do EFEITO (não só ingestão)** — RESEARCH.md, seção "Idempotência", "Segundo guard necessário": antes de criar uma liberação nova, checar se já existe liberação para (empresa, serviço) — não confiar em "este evento específico ainda não processei". Índice único recomendado: `(company_id, servico_id)` nomeado à mão (ex. `cl_empresa_servico_uniq`), aplicando a mesma disciplina de 64 caracteres.

---

### `app/Services/Operacional/EmpresaOperacionalRouter.php` (MODIFICADO — método novo, D-01/D-02)

**Arquivo já lido por inteiro** (168 linhas) — trechos relevantes:

**Porta já existente que a D-01 encaixa** (linhas 59-68):
```php
public function rotearServico(Company $company, string $nomeServico, array $handoff = []): void
{
    $this->rotear($company, [$nomeServico], $handoff, guardPorEmpresa: true);
}
```

**O guard que a D-02 exige repensar** (linhas 90-132, `private function rotear()`):
```php
private function rotear(Company $company, iterable $nomesServicos, array $handoff, bool $guardPorEmpresa): void
{
    if ($this->bloqueioAtivo()) { /* ... REDE-01, não mexer ... */ return; }

    $tipos = collect($nomesServicos)
        ->map(fn(string $nome) => ComercialController::servicoDisparaImplementacao($nome))
        ->filter();

    if (!$guardPorEmpresa) {
        $tipos = $tipos->unique();
    }

    foreach ($tipos->values() as $tipo) {
        if ($guardPorEmpresa && MlbEmpresa::where('company_id', $company->id)->exists()) {
            return;
        }
        $this->criarFicha($company, $tipo, $handoff);
    }
}
```
**⚠️ Atenção crítica do CONTEXT (D-02):** este `guardPorEmpresa` roda dentro do MESMO loop de UMA chamada — ele nunca vê os serviços liberados em webhooks separados ao longo de semanas. A liberação por webhook chama `rotearServico()` **uma vez por evento**, então cada chamada só tem 1 serviço na lista — a garantia de "1 ficha só" precisa ser reconferida como já está (o `exists()` reconsulta o banco a cada chamada, então SOBREVIVE a chamadas separadas no tempo — a garantia é por RECONSULTA, não por estado em memória). O plano deve confirmar explicitamente que esse comportamento já cobre D-02 sem mudança, ou documentar por que precisa mudar.

**Onde o `liberarEmpresa()` da Fase 130 provavelmente deve entrar** (D-03: "liberada" é estado próprio, gravado independente de gerar ficha) — este service é o "lugar único" (docblock de classe, linhas 12-34) e a Fase 130 espera esse método aqui (RESEARCH "Integration Points"). Se o plano da 129 decidir criar o método (mesmo que a Fase 130 seja quem o USA de verdade), seguir a mesma forma de método público fino delegando a um `private` — padrão `rotearServico()`/`rotearCadastro()` → `rotear()`.

---

### `app/Console/Commands/ClicksignVerificarAssinatura.php` (D-09, command)

**Analog:** `app/Console/Commands/ClicksignSondarModelo.php` (molde completo já lido — 530 linhas)

**Padrões a copiar:**
- `protected $signature` com opções nomeadas e descrição inline (linhas 45-53).
- Dry-run como padrão, `--confirmar` para executar de fato (linhas 99-106, `imprimirPlano()`).
- Guard de ambiente (`garantirAmbienteSeguro()`, linhas 118-139) — para o comando da D-09, avaliar se aplica (o comando SÓ LÊ um evento já gravado e recalcula hash local — não faz chamada de rede à Clicksign, então talvez não precise do guard de produção; mas se ele também tiver que buscar o secret de produção, reavaliar).
- Saída segura nunca vazando token/segredo (`imprimirErroSeguro()`, linhas 511-522) — para a 129, nunca imprimir o `secret` nem o hash completo sem contexto explícito de debug controlado.
- `$this->table(...)` para apresentar resultado tabular (linhas 174-179, 448-451) — útil para "evento X: fórmula 1 bate? fórmula 2? fórmula 3? fórmula 4?".

**Diferença de propósito:** `ClicksignSondarModelo` faz chamadas de rede reais (mede contra o sandbox); o comando da D-09 é **read-only local** — pega um `ContratoAssinaturaEvento` já gravado (com `payload` bruto) e recalcula os candidatos de HMAC contra o `payload_hash`/raw armazenado, sem chamar a Clicksign. Nome sugerido pelo CONTEXT: `clicksign:verificar-assinatura`.

---

## Shared Patterns

### Autenticação de webhook — HMAC timing-safe + raw body
**Fonte:** `app/Http/Controllers/Api/HubspotWebhookController.php:68-90`
**Aplicar a:** `ClicksignWebhookController::receive()`
```php
$rawBody = $request->getContent(); // SEMPRE antes de qualquer parsing
// ... comparação com hash_equals(), NUNCA === ou ==
```
Reusar a disciplina (raw body primeiro, `hash_equals`, nunca logar o secret), **não** a fórmula nem a presença de timestamp (ver seção do controller acima).

### Gravação bruta de evento — sempre, mesmo em falha de validação
**Fonte:** `HubspotWebhookController::gravarInvalido()` (linhas 1018-1036) + `hubspot_eventos` (schema)
**Aplicar a:** `ClicksignWebhookController` (toda entrada, válida ou não) e ao schema de `contrato_assinatura_eventos`
Truncamento de 65KB, `Log::channel('ecf-webhooks')`, `payload` como JSON, nunca vazar secret.

### Idempotência de banco — SQLSTATE 23000, nunca `errorInfo[1]`
**Fonte:** `127-06-SUMMARY.md` (citado no RESEARCH, precedente `NpsController.php:1835`)
**Aplicar a:** `ClicksignWebhookController` (ingestão de evento) e ao guard de efeito da liberação (D-05)
```php
catch (\Illuminate\Database\QueryException $e) {
    if ((string) $e->getCode() === '23000') { /* já existe */ }
    throw $e;
}
```

### Reconsulta antes de agir — nunca confiar no payload do evento sozinho
**Fonte:** D-06 (CONTEXT) + `ClicksignClient::consultarEnvelope()` (linhas 318-326)
**Aplicar a:** `ProcessarEventoClicksignJob` (reconsulta o envelope QUE MUDOU) e ao download do PDF (D-12, sempre reconsultar por link fresco antes de baixar)

### Log seguro — nunca corpo bruto de resposta/erro, nunca PII
**Fonte:** `ClicksignClient::enviar()` (linhas 751-776) e `GerarContratoAssinaturaJob::podarPii()` (linhas 236-241)
**Aplicar a:** todo log desta fase (controller, job, comando) — só campos nomeados (`contexto`, `status`, `codigo`, `ponteiro`), nunca `$res->json()` inteiro nem e-mail/nome de signatário.

### Rota de webhook — CSRF isento por convenção já existente, throttle dedicado
**Fonte:** `routes/web.php:75-83`, `bootstrap/app.php:21-24`
**Aplicar a:** rota nova `/api/webhooks/clicksign`
Não precisa tocar em `bootstrap/app.php` (já cobre `api/webhooks/*`); replicar `->withoutMiddleware([...ValidateCsrfToken::class])` + `->middleware('throttle:60,1')` como defesa redundante e documentada.

### Nomeação manual de índice/FK — disciplina obrigatória para tabelas novas
**Fonte:** comentário de topo de `2026_08_10_100001_create_contrato_assinatura_signatarios_table.php` (linhas 28-42) + Pitfall D do RESEARCH
**Aplicar a:** `contrato_assinatura_eventos` e `contrato_liberacoes` (D-05) — qualquer FK/índice cujo nome autogerado passe de 64 caracteres falha SILENCIOSAMENTE no MariaDB (migration fica `Pending`, tabela nasce sem a garantia). Nomear tudo à mão desde o primeiro rascunho.

## No Analog Found

Nenhum arquivo desta fase ficou sem análogo — a cadeia Clicksign (Fases 125-128) e o webhook HubSpot (Fase 34) juntos cobrem todos os papéis (controller de webhook, job de fila, migration de evento bruto, migration de histórico, comando-sonda, service de roteamento). O único ponto genuinamente NOVO sem precedente direto no código é a **fórmula do HMAC em si** (gate A1) — mas isso é, por desenho do CONTEXT (D-08), resolvido por medição, não por padrão de código.

## Metadata

**Escopo da busca de análogos:** `app/Http/Controllers/Api/`, `app/Jobs/`, `app/Services/Clicksign/`, `app/Services/Operacional/`, `app/Console/Commands/`, `app/Models/`, `database/migrations/` (Fases 34, 125-128), `routes/web.php`, `bootstrap/app.php`, `config/services.php`, `tests/Feature/Phase34HubspotWebhookTest.php`
**Arquivos lidos por inteiro:** `EmpresaOperacionalRouter.php`, `ClicksignClient.php`, `GerarContratoAssinaturaJob.php`, `ContratoAssinatura.php`, `ContratoAssinaturaSignatario.php`, `ClicksignSondarModelo.php`, `ClicksignException.php`, `2026_06_12_300002_create_hubspot_eventos_table.php`, `2026_08_10_100001_create_contrato_assinatura_signatarios_table.php`
**Arquivos lidos por trecho (grep + offset/limit):** `HubspotWebhookController.php` (linhas 1-140, 1000-1050), `routes/web.php` (55-93), `bootstrap/app.php` (18-26), `AppServiceProvider.php` (37-90), `Phase34HubspotWebhookTest.php` (1-70), `config/services.php` (195-254)
**Data da extração:** 2026-08-12
