# Phase 126: Client Clicksign + PDF do contrato (v22.0) - Research

**Pesquisado em:** 2026-08-10
**Domínio:** wrapper HTTP para API externa (Clicksign v3) sem vazamento de credencial + geração de PDF jurídico em pt-BR (DomPDF)
**Confiança:** ALTA

## Summary

Esta pesquisa **não** investiga a API da Clicksign — isso já está fechado, medido e com
precedência sobre a doc oficial em `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`. O que
faltava para planejar bem esta fase eram três coisas concretas, todas resolvidas por leitura
direta do código e do vendor deste projeto: (1) **onde exatamente** o token pode vazar em log —
não é hipótese, é um método real do `Response` do Laravel (`transferStats->getRequest()`) que
carrega os headers da requisição, incluindo `Authorization`; (2) como o precedente
`RelatorioMensalPdfService` resolveu acentuação pt-BR e quebra de página no DomPDF, e onde ele
**não precisou** resolver nada porque nunca teve dado de comprimento variável vindo de banco; e
(3) que o projeto já tem um teste que faz exatamente o que o Success Criteria 1 pede — inspecionar
o conteúdo logado com um mock de `LoggerInterface` — só que hoje só cobre o `HubspotApiClient`.

**A maior armadilha da fase não é a API: é copiar `HubspotApiClient::withToken()`.** Esse helper do
Laravel sempre prefixa `Bearer `, e a Clicksign devolve 401 com o prefixo (medido). Isso já está
documentado no CONTEXT e no `.env.example`, mas vale repetir aqui porque é o padrão mais próximo
que existe no codebase — e é justamente o padrão errado para este client.

A tensão de dados da D-05 (dia de vencimento e forma de pagamento não existem no banco) não tem
solução técnica — é decisão de produto. Esta pesquisa levanta as opções concretas na seção
`## Tensão de Dados — Opções para o Planner`, mas não escolhe.

**Primary recommendation:** construir `ClicksignClient` com `Http::withHeaders()` (nunca
`withToken()`), devolvendo respostas cruas (arrays) para quem chama decidir, com
`ClicksignException` própria carregando `código` + `mensagem` da API; testar "nenhum token vaza"
com o mesmo padrão de mock de `LoggerInterface` já usado em
`tests/Feature/Phase111HubspotApiClientTest.php`. Construir `ContratoPdfService` como cópia direta
da estrutura do `RelatorioMensalPdfService` (Blade com CSS inline, DejaVu Sans, `page-break-inside:
avoid` por seção), separando o texto jurídico em `resources/views/contratos/clausulas.blade.php`
(D-01) e recebendo os dados já resolvidos como array (D-04: lê `servicos_snapshot`, nunca ao vivo).

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Chamadas HTTP à Clicksign (envelope/documento/signatário/requisito/notificação/consulta/cancelamento) | API/Backend (Service) | — | `ClicksignClient` é infraestrutura pura, sem lógica de negócio; a orquestração fica na Fase 127 |
| Geração do PDF do contrato | API/Backend (Service) | — | `ContratoPdfService` roda server-side via DomPDF; nenhuma renderização no browser |
| Texto jurídico das cláusulas | API/Backend (View Blade) | — | View isolada, versionada em git — não é dado de banco (D-01) |
| Persistência de `pdf_path`/`pdf_assinado_path` | Database/Storage | API/Backend | Migration + colunas no `ContratoAssinatura`; o service grava o arquivo, o controller/job (fora desta fase) grava o caminho |
| Arquivo binário do PDF | Database/Storage (filesystem local) | — | `storage/app/`, privado, fora de `public/` (D-06) — nunca S3 nesta fase |
| Prevenção de vazamento de token em log | API/Backend (Service + teste) | — | Responsabilidade do próprio client — nenhuma camada externa filtra log por ele |

## Standard Stack

### Core

| Library | Version | Purpose | Why Standard |
|---------|---------|---------|--------------|
| `barryvdh/laravel-dompdf` | `v3.1.2` [VERIFIED: composer.lock] | Facade `Pdf::loadView()->output()` sobre o Dompdf | Já em uso no projeto (`RelatorioMensalPdfService`); requer `dompdf/dompdf: ^3.0` |
| `dompdf/dompdf` | `v3.1.5` [VERIFIED: composer.lock] | Engine de renderização PDF | Dependência transitiva já resolvida — não precisa `composer require` |
| `Illuminate\Support\Facades\Http` | Laravel 12.x (já no projeto) | Cliente HTTP para a Clicksign | Mesmo cliente usado por `HubspotApiClient`; **não** usar `->withToken()` (ver Pitfalls) |
| `Illuminate\Support\Facades\Log` + canal `ecf-webhooks` | já configurado em `config/logging.php:135` | Log de chamadas/erros do client | Canal dedicado já existe (retenção 14 dias, `daily`); reusar, não criar canal novo |

**Nenhum pacote novo a instalar.** Toda a stack necessária já está no `composer.lock`. A seção
`## Package Legitimacy Audit` abaixo reflete isso — não há auditoria de pacote novo a fazer.

### Supporting

| Library | Version | Purpose | When to Use |
|---------|---------|---------|-------------|
| `Illuminate\Support\Facades\Storage` (disk `local`) | Laravel 12.x | Gravar o PDF em `storage/app/` | D-06 — PDF privado, fora de `public/` |
| `Illuminate\Bus\Queueable` / `ShouldQueue` | Laravel 12.x | Job de fila (D-14) que chama o `ClicksignClient` | Consumido pela Fase 127, não por esta — mas o client deve ser "job-friendly" (sem estado de request) |

### Alternatives Considered

| Instead of | Could Use | Tradeoff |
|------------|-----------|----------|
| SDK oficial/comunitário Clicksign | Cliente HTTP próprio (`Http::` puro) | Já decidido no `REQUIREMENTS-v22.md` § Out of Scope: SDK oficial abandonado desde 2020, comunitário fala com API v1 legada (não conhece Envelope v3). **Não reabrir esta decisão.** |
| `Http::withToken()` | `Http::withHeaders(['Authorization' => $token, ...])` | `withToken()` prefixa `Bearer ` automaticamente (fixo no Laravel, não configurável por chamada); a Clicksign exige token puro (medido: gate #2) |

**Installation:**
```bash
# Nenhuma instalação necessária — barryvdh/laravel-dompdf e dompdf/dompdf já estão no composer.lock
```

**Version verification:** Confirmado via leitura direta de `composer.lock` (linhas 10-72 para
`barryvdh/laravel-dompdf` v3.1.2, linhas 537-596 para `dompdf/dompdf` v3.1.5). `laravel/framework`
(fonte do `Http` client) está fixado em `^12.0` pelo `composer.json` do projeto — sem necessidade
de checar versão específica, é o mesmo cliente já usado por `HubspotApiClient`.

## Package Legitimacy Audit

**Não aplicável nesta fase.** Nenhum pacote novo é instalado — `barryvdh/laravel-dompdf` e
`dompdf/dompdf` já estão resolvidos no `composer.lock` do projeto (uso existente via
`RelatorioMensalPdfService`), e o cliente HTTP é o `Illuminate\Support\Facades\Http` nativo do
Laravel. A decisão de **não** usar SDK Clicksign (oficial ou comunitário) já está travada em
`REQUIREMENTS-v22.md` § Out of Scope, com motivo registrado (abandono / API v1 legada).

## Architecture Patterns

### System Architecture Diagram

```
┌─────────────────────────┐        ┌──────────────────────────┐
│  Fase 127 (fora do       │        │  Fase 131 (fora do        │
│  escopo desta fase) —    │        │  escopo) — tela lê o       │
│  Job que ORQUESTRA        │        │  pdf_path via rota         │
│  envelope + PDF            │        │  autenticada                │
└──────────┬───────────────┘        └──────────────┬─────────────┘
           │ chama                                    │ lê
           ▼                                            ▼
┌───────────────────────┐     grava binário     ┌───────────────────┐
│   ContratoPdfService    │────────────────────▶│  storage/app/       │
│   (Task B desta fase)    │                       │  contratos/*.pdf    │
│   - lê servicos_snapshot │                       │  (D-06, privado)    │
│   - monta dados p/ Blade │                       └───────────────────┘
│   - Pdf::loadView()      │
└──────────┬────────────────┘
           │ inclui via @include
           ▼
┌───────────────────────────┐
│ resources/views/contratos/  │  ← texto jurídico isolado (D-01)
│ clausulas.blade.php          │     versão git, sem lógica PHP de dados
└───────────────────────────┘

┌───────────────────────┐   HTTP (Http::withHeaders)   ┌─────────────────────┐
│   ClicksignClient        │─────────────────────────────▶│ sandbox.clicksign.com │
│   (Task A desta fase)     │◀─────────────────────────────│ /api/v3                │
│   - envelope/documento/   │   respostas JSON:API           └─────────────────────┘
│     signer/requirement/   │
│     notification/query/   │   em erro: lança ClicksignException
│     cancel                │   (código + mensagem da API, nunca o token)
│   - NUNCA loga o token    │
└───────────────────────┘
           ▲
           │ chamado de dentro de um Job (D-14) — Fase 127, fora desta fase
```

O leitor consegue seguir os dois blocos independentes (PDF e Client) desde a entrada até o
armazenamento — nenhum dos dois depende do outro dentro desta fase; a seta pontilhada de cima
(Fase 127 chamando ambos) é o ponto de integração que a fase seguinte constrói.

### Recommended Project Structure
```
app/
├── Services/
│   ├── ClicksignClient.php          # Task A — chamadas HTTP, sem lógica de orquestração
│   └── ContratoPdfService.php       # Task B — gera binário PDF a partir do snapshot
├── Exceptions/
│   └── ClicksignException.php       # D-10 — código + mensagem da API
resources/
└── views/
    └── contratos/
        ├── clausulas.blade.php      # D-01 — texto jurídico isolado
        └── pdf.blade.php            # layout/CSS + @include('contratos.clausulas')
database/
├── migrations/
│   └── 2026_08_10_1xxxxx_add_pdf_paths_to_contrato_assinaturas_table.php  # D-03
└── factories/
    └── ContratoAssinaturaFactory.php  # já existe (Fase 125) — precisa de state com servicos_snapshot preenchido
tests/
└── Feature/
    └── Phase126/
        ├── ClicksignClientTest.php           # Http::fake() com fixtures do SANDBOX-EMPIRICO.md
        ├── ClicksignClientNaoVazaTokenTest.php  # mock LoggerInterface — Success Criteria 1
        ├── ContratoPdfServiceTest.php        # empresa real (via factory), nome extremo
        └── MigrationFase126ConvencoesTest.php  # mesma guarda estática da Fase 125
```

### Pattern 1: Cliente HTTP que nunca vaza credencial
**What:** `Http::withHeaders()` explícito (nunca `withToken()`), log só com `status()` +
identificadores (envelope id, document id) — nunca o corpo bruto da requisição nem o header
`Authorization`.
**When to use:** Em toda chamada do `ClicksignClient`.
**Example:**
```php
// Fonte: padrão já usado em app/Services/HubspotApiClient.php, ADAPTADO
// (HubspotApiClient usa withToken() DE PROPÓSITO porque o HubSpot exige "Bearer ".
//  A Clicksign é o OPOSTO — token puro. Copiar a ESTRUTURA, não a chamada withToken().)
$res = Http::withHeaders([
    'Authorization' => $this->token,   // SEM prefixo Bearer — gate #2, medido
    'Accept'        => 'application/vnd.api+json',
    'Content-Type'  => 'application/vnd.api+json',
])->post(self::BASE . '/envelopes', $payload);

if (!$res->ok()) {
    // NUNCA logar $this->token nem o header Authorization.
    Log::channel('ecf-webhooks')->warning('[Clicksign] Falha ao criar envelope', [
        'status' => $res->status(),
        'body'   => $res->json('errors') ?? $res->body(), // corpo de erro da Clicksign, não o request
    ]);
    throw new ClicksignException($res->json('errors.0.detail') ?? 'Erro desconhecido', $res->status());
}
```

### Pattern 2: PDF isolando texto jurídico de montagem de dados (D-01)
**What:** A view de layout (`contratos/pdf.blade.php`) recebe só dados já resolvidos (array
plano) e faz `@include('contratos.clausulas', ['dados' => $dados])`; o Blade de cláusulas contém
só HTML + `{{ }}` de interpolação, nenhuma lógica de busca de dados.
**When to use:** Sempre — é decisão travada (D-01), não escolha de implementação.
**Example:**
```php
// Fonte: estrutura análoga a app/Services/RelatorioMensalPdfService.php
class ContratoPdfService
{
    public function gerar(array $dadosContrato): string
    {
        $pdf = Pdf::loadView('contratos.pdf', ['dados' => $dadosContrato])
            ->setPaper('A4');

        return $pdf->output();
    }
}
```

### Pattern 3: Acentuação pt-BR e quebra de página no DomPDF (Success Criteria 5)
**What:** confirmado por leitura direta do precedente — não é hipótese.
- **Acentuação:** `<meta charset="UTF-8">` na `<head>` da view + `font-family: 'DejaVu Sans',
  sans-serif` no CSS. DejaVu Sans é a fonte padrão do Dompdf com suporte UTF-8 completo — **não**
  requer `@font-face` nem fonte customizada. Sem essas duas linhas, o Dompdf renderiza acento
  errado ou caractere ausente.
- **Quebra de bloco no meio da página:** `page-break-inside: avoid` na classe `.section` (cada
  bloco lógico — cabeçalho, cláusula, tabela — é envolvido em `<div class="section">`). O
  precedente **não usa** `orphans`/`widows` — o Dompdf tem suporte parcial e inconsistente para
  essas propriedades CSS2; `page-break-inside: avoid` no container é o que de fato funciona e é o
  que o precedente usa.
- **Nome longo / caractere especial:** o precedente **nunca precisou resolver isso** — os dados
  que ele recebe (KPIs, rankings) têm comprimento controlado pela API ECF Drive. Nenhum
  `word-break` ou `overflow-wrap` está declarado no CSS do precedente. **Isto é gap real para
  Success Criteria 5** — ver Pitfall 3 abaixo.
**Example:**
```css
/* Fonte: resources/views/emails/relatorios/mensal-pdf.blade.php linhas 12-17, 28 */
body { font-family: 'DejaVu Sans', sans-serif; font-size: 11px; }
.section { margin-bottom: 24px; page-break-inside: avoid; }
```

### Anti-Patterns to Avoid
- **Copiar `Http::withToken($token)` do `HubspotApiClient` para o `ClicksignClient`:** produz 401
  silencioso (o Laravel prefixa `Bearer ` sempre — não há flag para desligar em `withToken()`).
  Confirmado no vendor: não existe parâmetro em `PendingRequest::withToken()` para omitir o
  prefixo.
- **Logar `$res->body()` ou `$res->json()` completo em erro de criação de signatário:** a resposta
  da Clicksign pode ecoar nome/e-mail do signatário que você acabou de enviar (é o próprio corpo
  da sua requisição refletido no erro de validação) — CONTEXT já sinaliza isso como achado WR-11
  (segunda cópia de PII). Logar só os campos necessários para debug (`status`, `errors[].detail`,
  `errors[].source.pointer`), nunca o payload inteiro.
- **Usar `orphans`/`widows` para controlar quebra de página no Dompdf:** suporte inconsistente;
  o precedente usa `page-break-inside: avoid` em containers, que é o padrão que de fato funciona.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Retry com backoff em 429/5xx | Loop manual com `sleep()` | Já existe em `AdmanService`/`SugadorAnalysisService` um padrão de retry — reusar a mesma disciplina (nunca `sleep()` bloqueante num Job — usar `backoff()` do próprio Job de fila para o caso de falha total, e retry interno só para os passos dentro de uma única execução) | `sleep()` dentro do `handle()` do Job segura o worker preso; o padrão do projeto é backoff do próprio mecanismo de fila |
| Content-Type/Accept manuais por chamada | Montar headers a cada método | `Http::withHeaders([...])` construído uma vez no construtor via método privado `baseRequest()` | Evita repetir os 3 headers em 7 métodos e esquecer um |
| Formatação de moeda/data pt-BR no Blade | `number_format` espalhado sem padrão | Reusar os helpers locais do próprio Blade (`$fmtBRL`, padrão `@php` no topo da view, igual ao precedente) — **não** criar um novo helper global só para esta fase | O precedente já resolveu isso; duplicar helper global cria duas fontes de formatação pt-BR no projeto |

**Key insight:** Este domínio (client HTTP + PDF) tem dois precedentes diretos no próprio
codebase (`HubspotApiClient` e `RelatorioMensalPdfService`). O trabalho desta fase é **adaptar**,
não inventar — e a adaptação mais perigosa é justamente onde os dois domínios divergem do
precedente mais próximo (prefixo Bearer; ausência de tratamento para dado de comprimento
variável).

## Runtime State Inventory

Não aplicável — esta é uma fase de construção nova (client HTTP inédito + service de PDF novo +
migration aditiva), não rename/refactor/migração de dado existente. A migration D-03 adiciona
colunas a uma tabela criada na fase anterior (125) e ainda sem dado em produção (a Fase 125 só
criou schema, nenhum contrato foi gerado ainda) — não há registros existentes para migrar.

## Common Pitfalls

### Pitfall 1: Copiar `Http::withToken()` do `HubspotApiClient`
**What goes wrong:** Toda chamada retorna 401 "Access Token inválido", mesmo com o token correto.
**Why it happens:** `withToken()` do Laravel sempre prepend `Bearer ` no header `Authorization` —
não há parâmetro para desligar isso. A Clicksign exige o token puro (medido: gate #2 fechado).
**How to avoid:** Usar `Http::withHeaders(['Authorization' => $token, ...])` desde o primeiro
método escrito. Isso já está documentado no `.env.example` (linhas 187-188) e no CONTEXT — mas é
fácil de esquecer se o dev "confiar" no precedente mais próximo do codebase.
**Warning signs:** 401 consistente mesmo após confirmar o token no `.env`; teste de sandbox real
(não `Http::fake()`) é o único jeito de pegar isso — os testes automatizados com fixture nunca vão
acusar, porque a fixture não valida o header enviado a menos que o teste explicitamente assert
sobre isso (`Http::fake()` + `Http::assertSent(fn ($req) => ...)`).

### Pitfall 2: Token vazando via `transferStats` em vez de via mensagem de exceção
**What goes wrong:** Um dev assume que "não logar `$this->token` diretamente" é suficiente, e
comete `Log::error('Falha Clicksign', ['response' => $res])` ou serializa o objeto `Response`
inteiro, ou chama `$res->dump()`/`$res->handlerStats()` em debug que sobrevive ao code review.
**Why it happens:** `Illuminate\Http\Client\Response` carrega uma propriedade pública
`$transferStats` (objeto `GuzzleHttp\TransferStats`) que expõe `getRequest()` — **a requisição
original, com headers, incluindo `Authorization`**. Confirmado por leitura direta do vendor:
`vendor/laravel/framework/src/Illuminate/Http/Client/Response.php` linha 484
(`$this->transferStats?->getRequest()`), usado internamente pelo método `dump()`. Isso não é uma
hipótese — é o mesmo mecanismo que devolveria o header se alguém logasse o objeto `Response`
inteiro (ex.: `json_encode($res)` num contexto de log, ou um `dd($res)` esquecido).
**Also confirmed:** `RequestException::prepareMessage()` (mesmo vendor, `RequestException.php`
linha 105-121) inclui um **resumo do corpo da RESPOSTA** na mensagem da exceção (truncado a 120
caracteres por padrão via `Message::bodySummary()`) — isso é o corpo que a Clicksign devolveu, não
a requisição que você enviou, então não vaza o token por esse caminho especificamente. O vetor
real é o `transferStats` do `Response`, não a `RequestException`.
**How to avoid:** O `ClicksignClient` deve extrair **campos específicos** antes de logar
(`$res->status()`, `$res->json('errors')`), nunca o objeto `$res` inteiro nem `$e->getTrace()` com
argumentos completos (`ini_set` não configurável por código de forma confiável — usar Sentry-like
allowlist de campos é mais seguro do que confiar em truncamento). O teste do Success Criteria 1
deve cobrir explicitamente o cenário de exceção (não só o `catch` feliz).
**Warning signs:** Qualquer linha de código que passe `$res`, `$response`, ou `$e` inteiro para
`Log::*()` sem extrair campos nomeados primeiro.

### Pitfall 3: PDF sem tratamento para nome de empresa extremo (Success Criteria 5)
**What goes wrong:** Nome de empresa muito longo (ex.: razão social composta) ou com caractere
especial fora do BMP básico estoura a largura da célula da tabela e desalinha o layout, ou — pior
— empurra uma cláusula para atravessar a quebra de página mesmo com `page-break-inside: avoid` se
o bloco inteiro for maior que uma página.
**Why it happens:** O precedente (`RelatorioMensalPdfService`) nunca precisou lidar com isso —
seus dados vêm de uma API com nomes já truncados/curtos. O CSS do precedente não declara
`word-break` nem `overflow-wrap` em nenhuma célula de tabela.
**How to avoid:** Adicionar `word-wrap: break-word` (ou `overflow-wrap: break-word`) explicitamente
nas células que recebem `razão social`/`nome do contato` no novo Blade — não presumir que o
Dompdf quebra automaticamente como um browser faria. Testar com um valor de fixture
deliberadamente longo (80+ caracteres) e com acento/caractere especial (ex.: `Ç`, `Ã`, `–`)
combinado.
**Warning signs:** Teste `ContratoPdfServiceTest` sem nenhum caso de nome > 60 caracteres — é sinal
de que o Success Criteria 5 não está coberto de fato, só o caminho feliz.

### Pitfall 4: `pdf_path`/`pdf_assinado_path` esquecidas do `$fillable`
**What goes wrong:** A migration D-03 adiciona as colunas no banco, mas `ContratoAssinatura::
$fillable` (que já lista 9 campos explícitos, ver `app/Models/ContratoAssinatura.php` linhas
48-59) não é atualizado — `mass assignment` das novas colunas falha silenciosamente (Eloquent
ignora chaves fora do `$fillable`, sem erro).
**Why it happens:** A migration e o model são arquivos separados; é fácil lembrar de um e
esquecer do outro, especialmente porque o projeto usa `$fillable` explícito (nunca `$guarded =
[]`) por disciplina de segurança (T-125-01/12) — o que é correto, mas exige o passo extra.
**How to avoid:** Task explícita no plano: "adicionar `pdf_path` e `pdf_assinado_path` ao
`$fillable` de `ContratoAssinatura`" junto com a migration, não como afterthought.
**Warning signs:** Teste de schema (`ContratoAssinaturaSchemaTest.php`, precedente da Fase 125)
que confere colunas existe, mas não confere `$fillable` — vale considerar reforçar isso no plano.

### Pitfall 5: `config('services.clicksign')` inexistente
**What goes wrong:** O `.env.example` já tem as 5 chaves `CLICKSIGN_*` (Fase 125 as introduziu),
mas `config/services.php` **não tem** bloco `'clicksign' => [...]` — só existe o bloco `'hubspot'`.
Se o `ClicksignClient` ler `env('CLICKSIGN_ACCESS_TOKEN')` diretamente (em vez de
`config('services.clicksign.access_token')`), funciona em dev mas quebra a convenção do projeto
(`env()` direto fora de arquivo de config é anti-padrão Laravel — cache de config em produção não
recarrega `.env`).
**Why it happens:** O bloco de config nunca foi criado; só o `.env.example` foi preparado
antecipadamente pela Fase 125.
**How to avoid:** Task no plano: criar o bloco `'clicksign'` em `config/services.php` espelhando a
estrutura do bloco `'hubspot'` (linhas 118+), com `env`, `base_url`, `access_token`,
`webhook_secret`, `api_user_email`.
**Warning signs:** `grep -n "clicksign" config/services.php` retorna vazio antes da task, deve
retornar o bloco depois.

## Code Examples

### Teste que inspeciona log sem vazamento de token (Success Criteria 1)
```php
// Fonte: tests/Feature/Phase111HubspotApiClientTest.php linhas 249-274 (precedente literal
// já existente no projeto — reusar a estrutura, adaptar para ClicksignClient/canal ecf-webhooks)
public function test_nenhum_metodo_vaza_token_em_log_de_falha(): void
{
    $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
    $logger->shouldReceive('warning')
        ->withArgs(function ($message, $context = []) {
            $ctxJson = json_encode($context);
            return !str_contains((string) $message, 'fake-clicksign-token')
                && !str_contains($ctxJson, 'fake-clicksign-token');
        })
        ->zeroOrMoreTimes();
    $logger->shouldReceive('info')->andReturnNull();
    $logger->shouldReceive('error')
        ->withArgs(function ($message, $context = []) {
            $ctxJson = json_encode($context);
            return !str_contains((string) $message, 'fake-clicksign-token')
                && !str_contains($ctxJson, 'fake-clicksign-token');
        })
        ->zeroOrMoreTimes();

    Log::shouldReceive('channel')->with('ecf-webhooks')->andReturn($logger);

    Http::fake([
        'https://sandbox.clicksign.com/api/v3/envelopes' => Http::response([
            'errors' => [['code' => 'bad_request', 'detail' => 'erro simulado']],
        ], 400),
    ]);

    $client = new ClicksignClient(token: 'fake-clicksign-token');

    $this->expectException(ClicksignException::class);
    $client->criarEnvelope([...]);
}
```

### Fixture literal do sandbox para `content_base64` (D-15)
```php
// Fonte: .planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md §2 — cópia LITERAL do erro real
// medido, NÃO invenção. Usar como fixture de "caminho de erro" (base64 puro deve falhar).
Http::fake([
    'https://sandbox.clicksign.com/api/v3/envelopes/*/documents' => Http::response([
        'errors' => [[
            'code'   => 'bad_request',
            'status' => 400,
            'source' => ['pointer' => '/data/attributes/content_base64'],
            'detail' => 'content_base64 Formatação do campo inválida. O valor deve ser um Data URI completo.',
        ]],
    ], 400),
]);
```

### Grava PDF em disco privado (D-06)
```php
// Fonte: padrão idêntico a app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php linha 72
// (Storage::disk('local')->put — storage/app/, fora de public/)
$pdfBinary = $pdfService->gerar($dadosContrato);
$pdfPath   = "contratos/contrato-{$contrato->id}.pdf";
Storage::disk('local')->put($pdfPath, $pdfBinary);
$contrato->update(['pdf_path' => $pdfPath]);
```

## Tensão de Dados — Opções para o Planner

O CONTEXT já registra a tensão (Success Criteria 3 pede empresa real; dia de vencimento e forma de
pagamento não existem no banco). Esta pesquisa não decide, mas levanta as opções técnicas
concretas para o planejador escolher:

| Opção | Como funciona | Prós | Contras |
|---|---|---|---|
| **A — Parâmetros do service** | `ContratoPdfService::gerar()` recebe `diaVencimento` e `formaPagamento` como argumentos, opcionais (`?string`), vindos de fora (a 131 os coleta e passa) | Não força placeholder no PDF; a 131 simplesmente passa os dados reais quando existirem | Nesta fase, o teste de "empresa real do banco" (Success Criteria 3) precisa decidir o que passar — se `null`, cai na opção B por baixo |
| **B — Placeholder textual visível** | Quando o dado não vem, a view renderiza um texto como `"A definir"` ou `"[A COMPLETAR]"` em vez de branco silencioso | Simples, sem mudança de assinatura; documento nunca sai com campo em branco (regra explícita do CONTEXT) | Se ninguém completar depois (131 atrasar), contrato pode ser enviado à Clicksign com placeholder visível — jurídico? |
| **C — Ambas combinadas** | Parâmetro opcional (A) + fallback de placeholder (B) quando o parâmetro vier `null` | Cobre os dois cenários: 126 sozinha (teste com dado real do banco, sem vencimento/pagamento) e 127+131 completos depois | Mais uma decisão de copy (texto exato do placeholder) que alguém precisa aprovar |

**Recomendação desta pesquisa (não decisão):** Opção C é a que menos acopla esta fase à 131 e
menos viola a regra explícita do CONTEXT ("o que não vale é o PDF sair com campo em branco
silencioso"). O parâmetro `null` + placeholder visível resolve tecnicamente o Success Criteria 3
("dados de uma empresa real do banco") sem a Fase 126 precisar inventar uma coluna nova que
pertence à ADM-01 (Fase 131). **O planner decide o texto exato do placeholder e se isso precisa de
confirmação do usuário** (é dado que aparece em documento jurídico enviado ao cliente).

Quanto a **razão social**: `companies.name` é o único campo hoje (não há `razao_social`
separado). Recomendação: usar `companies.name` como razão social nesta fase — não é a tensão
registrada no CONTEXT (que fala de dia de vencimento/forma de pagamento/endereço), mas vale o
planner confirmar que `name` já contém o texto de razão social ou só o "nome fantasia" — não
verificado nesta pesquisa (LOW confidence, ver Assumptions Log).

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `companies.name` contém a razão social (não apenas nome fantasia) | Tensão de Dados | Se `name` for nome fantasia, o PDF sairia juridicamente incorreto — precisa confirmação humana antes de assumir |
| A2 | O texto de placeholder ("A definir" ou similar) é aceitável para os campos faltantes num documento que vai à Clicksign | Tensão de Dados / Opção C | Se o time jurídico da ECF não aceitar isso, o planner precisa de outra estratégia (ex.: bloquear geração até 131 existir) |

## Open Questions

1. **`config/services.php` precisa do bloco `clicksign`?**
   - What we know: `.env.example` já tem as 5 chaves; `HubspotApiClient` lê via
     `config('services.hubspot.access_token')`.
   - What's unclear: se o plano vai criar esse bloco nesta fase ou se já existe em algum commit
     não lido por esta pesquisa (verificado: não existe hoje, `grep` vazio).
   - Recommendation: task explícita no plano para criar `config/services.php['clicksign']` —
     ver Pitfall 5.

2. **Onde o mapa D-08 → vocabulário Clicksign (`contratante`/`contratada`/`testemunha` →
   `sign`/`party`/`contractor`) deve viver?**
   - What we know: é discricionário do Claude (CONTEXT: "constante, match, config").
   - What's unclear: nada tecnicamente — é só escolha de estilo.
   - Recommendation: constante `PAPEL_PARA_CLICKSIGN_ROLE` (array associativo) no próprio
     `ClicksignClient` ou em `ContratoAssinaturaSignatario`, seguindo o padrão `SCREAMING_SNAKE`
     de constantes já visto no projeto.

## Environment Availability

| Dependency | Required By | Available | Version | Fallback |
|------------|------------|-----------|---------|----------|
| PHP CLI | Rodar testes/artisan | ✓ | 8.2.12 (`C:/xampp/php/php.exe`, fora do PATH) | Sempre invocar caminho absoluto |
| Composer packages (`barryvdh/laravel-dompdf`, `dompdf/dompdf`) | Geração de PDF | ✓ | v3.1.2 / v3.1.5 (composer.lock) | — |
| Rede para `sandbox.clicksign.com` | Testes manuais/exploratórios contra o sandbox real (fora da suíte automatizada, que usa `Http::fake()`) | Não verificado nesta sessão | — | Suíte automatizada não depende disso — só checagem manual eventual |
| MySQL/MariaDB local | Rodar migration contra DB real antes do deploy | ✗ (mysqld local não está rodando, conforme instrução do foco de pesquisa) | — | Testes usam SQLite (`:memory:`); ver `## Validation Architecture` para como isso afeta o Success Criteria 3 |

**Missing dependencies com fallback:** MySQL local ausente — testes automatizados rodam contra
SQLite in-memory (`phpunit.xml`: `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`), que já é o
padrão do projeto. Success Criteria 3 ("empresa real do banco") deve ser interpretado como "dados
com a forma e o volume de uma empresa real" via factory/seed determinístico dentro da suíte —
não como conexão ao MariaDB de produção. Ver seção seguinte.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`), config em `phpunit.xml` |
| Config file | `phpunit.xml` (raiz do projeto) — `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`, `QUEUE_CONNECTION=sync` |
| Quick run command | `C:/xampp/php/php.exe artisan test --filter=Phase126` |
| Full suite command | `C:/xampp/php/php.exe artisan test` |

⚠️ **NUNCA** rodar `phpunit` (binário puro) sem argumentos de filtro/timeout explícito. Dois
comandos do projeto chamam `set_time_limit(300)`
(`app/Console/Commands/SyncGrantsFromEcfDrive.php:23` e
`app/Console/Commands/SyncGrantsFromSftp.php:22`) — se algum teste da suíte completa disparar
esses commands sem mock, o processo do PHPUnit pode ser morto pelo próprio `set_time_limit`.
Preferir `php artisan test --filter=<algo específico>` durante o desenvolvimento da fase, e reservar
a suíte completa para o gate final.

### Phase Requirements → Test Map

| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| CLICK-01 | Nenhuma chamada do `ClicksignClient` loga o token, em sucesso ou erro | unit/feature | `php artisan test --filter=ClicksignClientNaoVazaTokenTest` | ❌ Wave 0 |
| CLICK-01 (implícito) | Client monta os 7 tipos de chamada (envelope/doc/signer/requirement/notification/query/cancel) com `content_base64` no formato Data URI correto e header sem `Bearer` | feature | `php artisan test --filter=ClicksignClientTest` | ❌ Wave 0 |
| PDF-01 | PDF traz razão social, CNPJ, contato, serviços e valores de uma empresa real (via factory) | feature | `php artisan test --filter=ContratoPdfServiceTest` | ❌ Wave 0 |
| PDF-02 | Texto jurídico vive em view separada, sem lógica de dados misturada | feature (assert de estrutura de arquivo) ou revisão de code review | `php artisan test --filter=ContratoPdfServiceTest` (case que troca o texto do Blade e confere que o dado não muda) | ❌ Wave 0 |
| PDF-03 | PDF com nome extremo mantém acentuação e não corta cláusula no meio da página | feature | `php artisan test --filter=ContratoPdfServiceTest::test_nome_extremo` | ❌ Wave 0 |
| D-03 (migration) | `pdf_path`/`pdf_assinado_path` existem, sem violar as 3 armadilhas de schema do projeto | feature (guarda estática, texto da migration) | `php artisan test --filter=MigrationFase126ConvencoesTest` | ❌ Wave 0 |
| D-12 (rollback) | Falha no meio da criação cancela o envelope antes de propagar erro | feature | `php artisan test --filter=ClicksignClientTest::test_rollback` | ❌ Wave 0 |
| D-11 (retry) | Retry só em 429/5xx, nunca em 4xx | feature | `php artisan test --filter=ClicksignClientTest::test_retry` | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=Phase126`
- **Per wave merge:** `php artisan test` (suíte completa — cuidado com o `set_time_limit`, ver
  aviso acima; se a suíte completa travar, isolar rodando `tests/Feature` e `tests/Unit`
  separadamente)
- **Phase gate:** Suíte completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps
- [ ] `tests/Feature/Phase126/ClicksignClientTest.php` — cobre CLICK-01 (chamadas)
- [ ] `tests/Feature/Phase126/ClicksignClientNaoVazaTokenTest.php` — cobre CLICK-01 (log)
- [ ] `tests/Feature/Phase126/ContratoPdfServiceTest.php` — cobre PDF-01/02/03
- [ ] `tests/Feature/Phase126/MigrationFase126ConvencoesTest.php` — guarda estática da migration D-03
- [ ] Fixtures: extrair os payloads literais do `CLICKSIGN-SANDBOX-EMPIRICO.md` para um array PHP
      reutilizável (ex.: `tests/Fixtures/ClicksignSandboxFixtures.php`) — evita colar JSON inline
      repetidamente nos testes e centraliza a disciplina de anonimização (D-15 ⚠️)
- [ ] Factory: `ContratoAssinaturaFactory` precisa de um novo `state()` que preencha
      `servicos_snapshot` com dado realista (hoje só o `definition()` base tem `null`) — necessário
      para o teste do Success Criteria 3 ler dados de serviços/valores

*(Nenhum framework de teste a instalar — PHPUnit 11.x já configurado e em uso extensivo.)*

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | não (não é autenticação de usuário do sistema) | — |
| V3 Session Management | não | — |
| V4 Access Control | não nesta fase (rota autenticada para servir o PDF é escopo da Fase 131) | — |
| V5 Input Validation | sim | `content_base64` deve ser validado como Data URI antes de enviar (evita erro 400 tardio); dados do `servicos_snapshot` já vêm validados/congelados pela Fase 125 |
| V6 Cryptography | não diretamente — mas o token é segredo de longa duração | Armazenar só em `.env` / `config()`, nunca em código, nunca logar (ver Pitfall 2) |

### Known Threat Patterns for este domínio

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Vazamento de credencial via log (token Clicksign) | Information Disclosure | Extrair campos específicos antes de logar; nunca logar `$res`/`$e` inteiros (Pitfall 2); teste automatizado que inspeciona `LoggerInterface` mockado |
| PII do signatário ecoada de volta pela API em erro de validação | Information Disclosure | Logar só `status` + `errors[].detail`/`source.pointer`, nunca o corpo completo (WR-11 já sinalizado no CONTEXT/125-REVIEW) |
| PDF com dado sensível (CNPJ, contato, valores) acessível por URL adivinhável | Information Disclosure | D-06 já resolve: `storage/app/`, fora de `public/`; rota autenticada é escopo da Fase 131, mas o arquivo em si já nasce inacessível sem ela |
| Documento jurídico com campo em branco silencioso | Tampering (integridade do documento) | Placeholder visível em vez de branco (ver Tensão de Dados) — não é ataque, mas é integridade de documento com validade jurídica |

## Sources

### Primary (ALTA confiança — leitura direta do codebase/vendor deste projeto)
- `app/Services/RelatorioMensalPdfService.php` — molde de geração de PDF
- `resources/views/emails/relatorios/mensal-pdf.blade.php` — molde de CSS/acentuação/quebra de página
- `app/Services/HubspotApiClient.php` — molde de client HTTP + log resiliente
- `app/Jobs/AnalyzeCompanySugadoresJob.php` — molde de Job de fila (tries/timeout/backoff/failed())
- `app/Jobs/EcfWebhook/HandleRelatorioGeradoJob.php` — molde de `Storage::disk('local')->put()`
- `tests/Feature/Phase111HubspotApiClientTest.php` — precedente literal do teste "não vaza token"
- `tests/Feature/Phase122/GateFixmarg03BaseTest.php` — precedente de `Log::spy()`/`shouldHaveReceived`
- `tests/Feature/Phase125/MigrationsFase125ConvencoesTest.php` — guarda estática de migration a replicar
- `vendor/laravel/framework/src/Illuminate/Http/Client/RequestException.php` — confirma o que entra na mensagem da exceção (corpo da RESPOSTA, truncado)
- `vendor/laravel/framework/src/Illuminate/Http/Client/Response.php` (linha 484) — confirma `transferStats->getRequest()` como vetor real de vazamento
- `composer.lock` — versões exatas `barryvdh/laravel-dompdf` v3.1.2 e `dompdf/dompdf` v3.1.5
- `app/Models/ContratoAssinatura.php`, `ContratoAssinaturaSignatario.php`, `ContratoServico.php`, `Company.php` — schema consumido/produzido por esta fase
- `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` — schema atual, base para a migration aditiva D-03
- `database/factories/ContratoAssinaturaFactory.php` — factory existente, gap identificado (sem state com `servicos_snapshot` preenchido)
- `config/logging.php` (linha 135) — canal `ecf-webhooks` já configurado
- `config/services.php` (linha 118+) — confirma ausência de bloco `clicksign` (Pitfall 5)
- `.env.example` (linhas 184-194) — chaves Clicksign já preparadas pela Fase 125
- `phpunit.xml` — config de teste (SQLite in-memory, queue sync)
- `.planning/phases/126-.../126-CONTEXT.md`, `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md`, `.planning/REQUIREMENTS-v22.md`, `.planning/phases/125-.../125-CONTEXT.md` — canonical refs desta fase

### Secondary / Tertiary
- Nenhuma fonte externa (WebSearch/Context7) foi necessária — o foco da pesquisa foi
  explicitamente restrito ao que já está resolvido no codebase e no CONTEXT/sandbox empírico. A
  API da Clicksign em si está fora do escopo desta pesquisa por instrução explícita.

## Metadata

**Confidence breakdown:**
- Standard stack: ALTA — nenhum pacote novo, versões confirmadas por leitura direta do `composer.lock`
- Architecture (client HTTP + PDF): ALTA — dois precedentes diretos no codebase, lidos linha a linha
- Vazamento de token em log: ALTA — confirmado por leitura do vendor Laravel, não hipótese
- Pitfall de nome extremo no DomPDF: MÉDIA — o gap está confirmado (precedente não trata), mas a
  solução exata (`word-wrap`) não foi testada nesta pesquisa, só recomendada por conhecimento do
  Dompdf/CSS
- Tensão de dados (razão social = `companies.name`?): BAIXA — não verificado nesta sessão, marcado
  em Assumptions Log

**Research date:** 2026-08-10
**Valid until:** ~30 dias (domínio estável — Laravel/DomPDF não mudam rápido; se a Clicksign
mudar a API v3 antes disso, o `CLICKSIGN-SANDBOX-EMPIRICO.md` precisa remedição, não este arquivo)
