# Phase 127: Service administrativo de contrato — orquestração (v22.0) - Research

**Researched:** 2026-08-11
**Domain:** Orquestração de geração de contrato (fila Laravel + `database` queue/cache, integração
Clicksign via `ClicksignClient` já existente, congelamento de dados em `contrato_assinaturas`)
**Confidence:** MÉDIA — a integração Clicksign está bem medida (herdada da Fase 126); a parte nova
desta fase (fila com espaçamento, schema de N contratos por empresa) não tem precedente medido e
depende de uma decisão de arquitetura que **não estava resolvida** nos documentos de entrada.

## Summary

Esta pesquisa não achou nenhuma pergunta em aberto sobre a API da Clicksign — isso já está fechado
pela Fase 126 e pelo `CLICKSIGN-SANDBOX-EMPIRICO.md`. O que achou foi um **buraco de arquitetura
entre duas decisões travadas em dias diferentes**: a Fase 125 (10/08) desenhou
`contrato_assinaturas` com um índice único que permite **no máximo um contrato em andamento por
EMPRESA**; a D-21 (11/08, um dia depois, registrada em `126-CONTEXT.md`) mudou o modelo de "um
envelope por empresa" para **"um envelope por SERVIÇO"** — uma empresa com 2 serviços contratados
precisa de **2 registros `ContratoAssinatura` simultaneamente em `rascunho`**. Isso colide de
frente com a constraint que a Fase 125 construiu, e **nenhum documento revisitou essa constraint**
depois da D-21. Este é o achado mais importante desta pesquisa — ver `## Tensão de arquitetura
(bloqueante)` abaixo.

Fora esse ponto, as outras 5 perguntas têm resposta direta lida do código: (1) `RateLimited` e
`WithoutOverlapping` funcionam corretamente com cache `database` (comprovado por leitura do
`DatabaseStore` do Laravel — usa transação + `lockForUpdate()`, é atômico); o projeto já usa
exatamente esse padrão em produção para a Adman (`RateLimiter::for('adman-api', ...)` +
`RateLimited` nos Jobs). (2) O padrão de job de trabalho longo com API externa está bem
estabelecido em dois precedentes (`AnalyzeCompanySugadoresJob`, `SyncAdmanCompanyJob`) e deve ser
seguido, não reinventado. (3) O congelamento do `servicos_snapshot` vem de `contratos_servico`, e o
"armadilha do `company_users`" citada no brief **não se aplica** a este ponto específico — os
signatários são fixos por config, não por `company_users`. (4) O padrão de captura de
`QueryException` 23000 já existe em 3 lugares do projeto (NPS) e deve ser copiado literalmente. (5)
`PendenciasComerciaisService::calcular()` **não cobre** os 3 bloqueantes do Success Criteria 1 —
tem que ser um checável novo, próprio desta fase. (6) A forma menos invasiva de "parar no rascunho"
é um parâmetro booleano opcional em `ClicksignClient`, não um método novo duplicando a sequência.

**Primary recommendation:** Resolver a tensão de arquitetura (schema `servico_id` + índice composto)
ANTES de planejar tarefas — do contrário qualquer plano gerado vai codificar um bug garantido (2º
serviço da mesma empresa sempre esbarra na constraint da Fase 125). Depois disso, seguir os 5
precedentes já medidos/lidos do código listados acima, sem inventar caminho novo.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|---|---|---|---|
| Decidir se empresa está pronta (bloqueio) | API/Backend (Service) | — | Regra de negócio pura, sem I/O externo; deve rodar ANTES de qualquer chamada HTTP (Success Criteria 1) |
| Congelar `servicos_snapshot` | API/Backend (Service) | Database | Leitura de `contratos_servico` + gravação JSON congelada — vive no mesmo service que decide o bloqueio |
| Espaçamento de chamadas Clicksign | Queue Worker (Job + Middleware) | Cache (`database`) | Rate limit é recurso compartilhado entre workers — só um bucket atômico via cache resolve corretamente |
| Montagem do envelope (15 chamadas) | Queue Worker (Job → `ClicksignClient`) | — | D-14 da Fase 126: nunca síncrono numa request HTTP (timeout de nginx/php-fpm) |
| Idempotência do disparo | Database (constraint) | Model (guard de leitura amigável) | D-05: constraint é a fonte de verdade; guard de código é só UX |
| Seleção do modelo `.docx` por serviço | Database (`servicos`) + Config | API/Backend | Precisa sobreviver a novo serviço sem deploy — coluna, não `if` hardcoded |

## Tensão de arquitetura (bloqueante — resolver antes de planejar)

> **LIDO DO CÓDIGO + INFERIDO.** Nenhum documento de entrada (125-CONTEXT, 126-CONTEXT, 127-CONTEXT,
> ROADMAP) resolve isto explicitamente. Não é hipótese — é uma contradição verificável entre a
> migration existente e a decisão D-21.

### Os dois fatos que se contradizem

1. **`database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php`** (Fase 125,
   10/08/2026) — `LIDO DO CÓDIGO`: cria o índice único `ca_company_andamento_uniq` sobre a coluna
   derivada `company_id_em_andamento`. O `ContratoAssinatura::booted()` (hook `saving`) preenche
   essa coluna com `company_id` enquanto `status` está em `rascunho`/`aguardando_assinaturas`, e
   `NULL` fora disso. Como MariaDB e SQLite tratam múltiplos `NULL` como distintos em índice único,
   isso garante **no máximo UM `ContratoAssinatura` em andamento POR EMPRESA** — nunca dois, mesmo
   que sejam serviços diferentes. O comentário da migration é explícito: *"no máximo um em
   andamento"* (singular, sem menção a serviço). O `125-CONTEXT.md` linha 92 confirma: *"Uma empresa
   pode ter vários contratos ao longo do tempo..., mas no máximo um em andamento."*

2. **D-21 (`126-CONTEXT.md`, 11/08/2026, um dia depois)** — `LIDO DO CÓDIGO/CONTEXT`: *"Empresa com
   N serviços recebe N contratos"*. Cada serviço tem seu próprio modelo `.docx`, seu próprio
   envelope, seus próprios `clicksign_envelope_id`/`clicksign_document_id`. Como a tabela só tem
   UMA coluna `clicksign_envelope_id` (também única) por linha, isso só pode significar **uma linha
   `ContratoAssinatura` por serviço**, não uma linha por empresa guardando N envelopes.

Se uma empresa tem 2 serviços ativos e o job do serviço A grava a primeira linha (`status =
rascunho`, D-02 nunca avança sozinho desse estado), o job do serviço B, ao tentar criar a SEGUNDA
linha com o mesmo `company_id` e `status = rascunho` por default, **sempre** esbarra em
`ca_company_andamento_uniq` — não é corrida, é garantido toda vez que uma empresa tem mais de um
serviço, que é exatamente o caso que a D-21 foi desenhada para cobrir.

### Por que isto não é uma leitura errada minha

- A `PLANO` da migration da Fase 125 foi escrito **antes** da D-21 existir (D-21 é do dia seguinte).
  Não houve chance de a Fase 125 ter previsto o modelo de N contratos.
- `ContratoServico::PLACEHOLDER`/`ContratoPdfService::montarDados()` e
  `ContratoVariaveisModeloService::mapa()` **já leem `servicos_snapshot` como array — inclusive um
  array de 1 item funciona sem mudança nenhuma** (`montarServicos()` mapeia, `montarVigencia()` tira
  min/max de 1 elemento = a própria data, `somarValores()` soma 1 elemento = o próprio valor). Ou
  seja: o código de leitura já está pronto para "1 linha = 1 serviço" — só o schema/gravação que não
  foi ajustado.
- O ROADMAP (`## Phase 127`) tem Success Criteria escritos no singular (*"gera envelope"*, *"um
  contrato pode ter prazo diferente"*) que fazem sentido tanto no modelo antigo quanto no novo — não
  contradizem a leitura acima, só não a resolvem.

### Caminho recomendado (menor mudança, reusa o padrão já existente)

1. Adicionar `servico_id` (FK para `servicos`, `NOT NULL`, `restrictOnDelete` ou equivalente — um
   contrato não pode sobreviver à exclusão do serviço que ele representa) em
   `contrato_assinaturas`.
2. Trocar a granularidade da constraint: em vez de UMA coluna derivada
   (`company_id_em_andamento`), duas colunas derivadas preenchidas juntas no mesmo hook `saving`
   (`company_id_em_andamento` E `servico_id_em_andamento`, ambas `NULL` fora de andamento, ambas
   preenchidas juntas quando em andamento) com um índice **composto** único sobre as duas — nome
   curto, à mão (armadilha MariaDB 1059 dos 64 caracteres, já documentada no projeto).
   `emAndamentoDaEmpresa(int $companyId)` vira `emAndamentoDoServico(int $companyId, int
   $servicoId)` (ou mantém a assinatura antiga como atalho que varre por serviço, a decisão de nome
   é discrição do planner).
3. `servicos_snapshot` (JSON) de cada linha passa a conter **um único item** (array com 1
   elemento) — a forma já é compatível com `ContratoPdfService`/`ContratoVariaveisModeloService`
   sem mudança de código neles.
4. Isto é uma **migration nova desta fase** — segue as 3 armadilhas de schema do projeto (nomear
   índice à mão, `nullable()` antes de `nullOnDelete` se aplicável, nunca `enum`), já documentadas
   em `125-CONTEXT.md`.

**Alternativa descartada:** manter 1 linha por empresa com uma tabela filha
`contrato_assinatura_envelopes` (1:N) para guardar N `clicksign_envelope_id`. Rejeitada por ser
mudança maior (nova tabela, nova FK, reescrita de `ContratoPdfService`/`ContratoVariaveisModeloService`
para não depender mais de "1 linha = 1 documento"), quando a Opção A já resolve com uma coluna e
zero mudança nos dois services de leitura. Fica registrado para o planner decidir com contexto
completo, não como recomendação own.

**Isto muda a resposta às perguntas 1, 3, 4, 5, 6 do brief** — todas assumem, corretamente, que
existe UM job por contrato/serviço; a diferença é que "empresa está com contrato em andamento" (a
checagem de idempotência do Success Criteria 5 — `iniciarParaEmpresa()` chamado duas vezes não cria
dois envelopes) precisa iterar por serviço, não checar a empresa como unidade atômica.

## User Constraints

<user_constraints>
### Locked Decisions (127-CONTEXT.md)

- **D-01** — Empresa com N serviços gera N contratos, por FILA com espaçamento. Motivo medido: 15
  chamadas por envelope × janela de 20/min = dois serviços simultâneos estoura garantido. Infra:
  fila `database` + workers `ecf-worker:*`. Precedente: `AnalyzeCompanySugadoresJob`.
- **D-02** — O sistema PARA no rascunho; quem envia ao cliente é o Comercial. Motivo medido: não
  existe pré-visualizar sem ativar (§10.4 do empírico), e variável faltando vira branco silencioso
  (§10.5). ⚠️ Consequência: `enviado_em` NÃO pode ser gravado por quem monta o envelope — só o
  webhook (Fase 129) sabe que foi enviado.
- **D-03** — Prazo e lembrete vão na CRIAÇÃO do envelope, não na ativação. MEDIDO: `POST /envelopes`
  aceita `deadline_at`/`remind_interval` e devolve os valores pedidos. NÃO MEDIDO: se o prazo
  definido na criação sobrevive à ativação pela interface web — gate obrigatório desta fase.
- **D-04** — Falha no meio da montagem: cancela tudo e recomeça (`DELETE /envelopes/{id}` → 204 em
  `draft`, já implementado em `ClicksignClient::montarEnvelopeComum()`). `DELETE` em envelope
  `running` NÃO foi medido, mas o rollback desta fase só roda antes da ativação.
- **D-05** — Idempotência vem do banco (`ca_company_andamento_uniq`), não de checagem de código que
  corre risco de corrida. ⚠️ Ver `## Tensão de arquitetura` acima — a granularidade desta constraint
  precisa mudar de "por empresa" para "por empresa+serviço" para D-01 e D-05 conviverem.

### Claude's Discretion

- Nomes finais de classe/método/arquivo desta fase.
- Como resolver a tensão de arquitetura (`servico_id` + índice composto é a recomendação desta
  pesquisa, não uma decisão travada pelo usuário).
- Layout exato do job (`GerarContratoAssinaturaJob` ou nome equivalente).
- Se `prazo_dias`/`lembrete_dias` viram colunas persistidas em `contrato_assinaturas` (recomendado
  por esta pesquisa para DADOS-06) ou permanecem parâmetro transitório do job.

### Deferred Ideas (OUT OF SCOPE desta fase)

- Webhook, download do PDF assinado, `contrato_assinatura_eventos` → Fase 129.
- Alerta de contrato preso, reconciliação, liberação manual → Fase 130.
- Tela, permissão `admin.contratos`, coleta dos campos que faltam → Fase 131.
- Cutover para a conta de produção → Fase 132.
- Ligar o bloqueio do roteamento operacional → Fase 133.
- Decisão A4 (quais das 7 pendências comerciais valem para empresa cadastrada à mão) → Fase 128.
- Modelos `.docx` dos demais serviços (Shopee etc.) — trabalho humano, não código.
</user_constraints>

## Phase Requirements

<phase_requirements>
| ID | Descrição | Suporte da pesquisa |
|---|---|---|
| CLICK-02 | O sistema cria o envelope na Clicksign com documento, signatários e requisitos | `ClicksignClient::montarEnvelopePorModelo()` já existe e já faz isso; só precisa do parâmetro `$ativar=false` (ver `## Q6`) |
| CLICK-08 | Envelope criado já com lembrete automático nativo, sem scheduler próprio | `criarEnvelope()` aceita `remind_interval` nos atributos — D-03 já resolvido no client, falta só o orquestrador passar o valor certo na CRIAÇÃO (não na ativação, que não roda nesta fase) |
| DADOS-06 | Cada contrato pode ter seu próprio prazo de assinatura | Precisa de valor configurável por chamada + (recomendado) persistência em coluna nova — ver `## Q1 e prazo` |
| REDE-05 | Validar dados mínimos (CNPJ, e-mail, nome de quem assina, datas — presença e formato) ANTES de gerar PDF/envelope, mostrando o que falta na tela do Administrativo (Fase 131 consome, esta fase produz o resultado da checagem) | `PendenciasComerciaisService` NÃO cobre isso — checagem nova, ver `## Q5` |
</phase_requirements>

## Standard Stack

Nenhuma biblioteca nova. Toda a integração usa o que já está instalado (`laravel/framework ^12.0`,
fila `database`, cache `database`). **Nenhum pacote externo a instalar nesta fase** — pular a seção
de auditoria de legitimidade de pacotes (não aplicável).

### Alternativas consideradas

| Instead of | Could use | Tradeoff |
|---|---|---|
| `RateLimited` middleware (cache `database`) | Redis dedicado só para o bucket Clicksign | Redis já está configurado no projeto (`REDIS_CACHE_DB=2`) mas é "opcional" — `CACHE_STORE=database` é o padrão de produção hoje (`STACK.md` linha 88); introduzir dependência de Redis só para este bucket quebraria o precedente 'adman-api', que já roda em produção com o cache padrão. Não trocar sem necessidade comprovada. |
| `->delay()` fixo no dispatch | `RateLimited` com bucket global | `->delay()` só resolve o espaçamento DENTRO da mesma empresa (N serviços dispatchados juntos); não protege contra DUAS empresas diferentes disparando geração ao mesmo tempo — o rate limit da Clicksign é da CONTA inteira, não por empresa. `RateLimited` com bucket `'global'` é a única opção que cobre os dois casos, e é exatamente o padrão que `'adman-api'` já usa (`Limit::perMinute(8)->by('global')`, LIDO DO CÓDIGO em `AppServiceProvider::boot()`). |

## Architecture Patterns

### Diagrama de fluxo

```
Comercial clica "Gerar contrato" (Fase 131, fora do escopo — aqui é o ENTRY POINT do service)
        │
        ▼
ContratoClicksignService::iniciarParaEmpresa(Company $company)
        │
        ├─▶ 1. Checagem de bloqueio (Success Criteria 1) — SEM nenhuma chamada HTTP
        │      e-mail do cliente presente e formato válido?
        │      CNPJ presente e formato válido?
        │      nome do contato presente?
        │      ──▶ se faltar QUALQUER um: devolve erro nomeando o campo, PARA AQUI.
        │
        ├─▶ 2. Para cada ContratoServico ativo da empresa (contratos_servico.ativo=true):
        │      guard emAndamentoDoServico(company_id, servico_id) — já existe rascunho/
        │      aguardando_assinaturas para este serviço? ──▶ se sim, pula (idempotência UX)
        │      cria ContratoAssinatura (status=rascunho, servico_id, servicos_snapshot=[item])
        │        ──▶ constraint composta é a garantia REAL (D-05); guard acima é só UX
        │      dispatch(GerarContratoAssinaturaJob::class, [$contratoAssinatura])
        │        ──▶ [WithoutOverlapping('clicksign-envelope-global')]
        │        ──▶ [RateLimited('clicksign-envelope')]  (bucket global, database cache)
        │
        ▼ (assíncrono, fila database, worker ecf-worker:*)
GerarContratoAssinaturaJob::handle(ClicksignClient, ContratoVariaveisModeloService)
        │
        ├─▶ resolve template_id pelo servico (servicos.clicksign_template_id ou config)
        ├─▶ ContratoVariaveisModeloService::montar($contratoAssinatura) → variaveis + pendentes
        ├─▶ ClicksignClient::montarEnvelopePorModelo(..., $ativar = false)  ◀── D-02
        │      cria envelope (com deadline_at/remind_interval na CRIAÇÃO — D-03)
        │      anexa documento por modelo
        │      adiciona 4 signatários (cliente + 3 fixos da ECF)
        │      cria 8 requisitos (qualificação + autenticação)
        │      NÃO ativa (D-02) — envelope fica em draft
        │      [em qualquer falha: rollback D-04, DELETE do envelope parcial, propaga exceção]
        ├─▶ sucesso: grava clicksign_envelope_id/clicksign_document_id, status permanece 'rascunho'
        └─▶ falha definitiva (tries esgotados): status=erro, erro_mensagem podada (sem PII)
```

### Padrão de Job (extraído de `AnalyzeCompanySugadoresJob` + `SyncAdmanCompanyJob`)

`LIDO DO CÓDIGO` — os dois precedentes concordam neste desenho; seguir sem inventar variação:

```php
// app/Jobs/GerarContratoAssinaturaJob.php (nome ilustrativo — discrição do planner)
class GerarContratoAssinaturaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;       // SyncAdmanCompanyJob usa 3; 4 é o de retry mais generoso (Adman)
    public int $timeout = 300;   // INFERIDO — 15 chamadas sequenciais, timeout HTTP de 30s cada
                                  // no client + até 3 retries internos por chamada (200/400/600ms).
                                  // Não há medição de tempo total real; 300s dá folga generosa sem
                                  // aproximar do limite prático do worker.

    public function __construct(
        public readonly ContratoAssinatura $contratoAssinatura,
    ) {}

    public function backoff(): array
    {
        return [60, 300, 900]; // idêntico ao SyncAdmanCompanyJob — 1min, 5min, 15min
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('clicksign-envelope-global'))->releaseAfter(5),
            new RateLimited('clicksign-envelope'),
        ];
    }

    public function handle(ClicksignClient $client, ContratoVariaveisModeloService $variaveis): void
    {
        // ... monta e chama montarEnvelopePorModelo(..., ativar: false) ...
    }

    public function failed(\Throwable $e): void
    {
        Log::error("[Clicksign] Falha definitiva ao gerar contrato #{$this->contratoAssinatura->id} " .
                   "empresa {$this->contratoAssinatura->company_id}: {$e->getMessage()}");
        // atualizar status=erro fica aqui ou dentro de handle() num catch — decisão do planner,
        // mas TEM que existir: um job falho sem status=erro deixa a empresa presa na constraint
        // "em andamento" para sempre sem ninguém saber por quê.
    }
}
```

**Diferença importante em relação aos dois precedentes:** nem `AnalyzeCompanySugadoresJob` nem
`SyncAdmanCompanyJob` fazem rollback explícito de estado externo em caso de falha — eles só
logam e re-tentam. Este job PRECISA, porque a D-04 exige cancelar o envelope Clicksign
parcialmente montado. Essa parte já está dentro de `ClicksignClient::montarEnvelopeComum()`
(catch interno chama `cancelarEnvelope()`), então o job em si **não duplica** essa lógica — só
precisa deixar a exceção propagar normalmente para acionar `tries`/`backoff`/`failed()`.

### RateLimiter registration (novo, mirror de 'adman-api')

`LIDO DO CÓDIGO` — `AppServiceProvider::boot()` já tem o padrão exato a copiar:

```php
// app/Providers/AppServiceProvider.php — adicionar ao lado de 'adman-api'
RateLimiter::for('clicksign-envelope', function () {
    // 1/min: um envelope consome 15 das 20 chamadas/min medidas (janela sandbox,
    // §1 do empírico) — 1 job por minuto deixa 5 de folga para outra atividade
    // na conta (clicksign:sondar-modelo, consultas manuais, etc.). Ajustar se a
    // conta de produção tiver janela diferente (gate desta fase, ainda não medido
    // contra produção).
    return Limit::perMinute(1)->by('global');
});
```

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---|---|---|---|
| Espaçar chamadas entre workers concorrentes | `sleep()`/checagem manual de "última vez que rodei" | `RateLimited` middleware + `RateLimiter::for()` | Já é o padrão do projeto (`adman-api`), atômico via cache, testado em produção com múltiplos workers `ecf-worker:*` |
| Garantir exclusão mútua entre jobs concorrentes | Flag booleana em coluna/cache lida com `if` | `WithoutOverlapping` (Cache::lock, atômico mesmo com cache `database` — `DatabaseLock`) | Fecha a pequena janela de corrida entre `tooManyAttempts()`/`hit()` do `RateLimited` (dois checks separados, não atômicos entre si) |
| Detectar duplicidade de contrato em andamento | `if (ContratoAssinatura::where(...)->exists())` antes de criar | Índice único no banco + capturar `QueryException` 23000 | D-05 explícito: checagem de código corre risco de corrida entre dois workers; a constraint não |
| Checar CNPJ/e-mail/nome válidos | Duplicar `PendenciasComerciaisService::calcular()` ou estender suas 7 pendências | Checagem NOVA e dedicada (ver `## Q5`) | `calcular()` é gated por `is_origem_hubspot` — silenciosamente vazio para empresa cadastrada à mão, e não checa e-mail nem CNPJ. Reusar o método daria falso "sem pendência" |
| Retry de chamada HTTP à Clicksign | Novo mecanismo de retry no job | Já existe em `ClicksignClient::enviar()` (retry 3x, só 429/5xx) | Duplicar geraria retry-de-retry (job E client tentando de novo), multiplicando chamadas contra a janela apertada de 20/min |

**Key insight:** quase todo o "não construir na mão" desta fase já foi resolvido pela Fase 126
dentro do `ClicksignClient`. O trabalho novo real é orquestração (decidir QUANDO chamar, com que
espaçamento, e gravar o resultado) — não reimplementar chamadas HTTP nem lógica de retry.

## Respostas às 6 perguntas do brief

### Q1 — Espaçar jobs contra rate limit, fila `database`

**MEDIDO/LIDO DO CÓDIGO — confirmado, não é palpite:**

- `Illuminate\Cache\DatabaseStore::increment()` roda dentro de `$connection->transaction()` com
  `lockForUpdate()` (lido em
  `vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php:271-296`) — **atômico**, mesmo
  sob concorrência real de workers.
- `Illuminate\Cache\DatabaseStore::lock()` devolve `DatabaseLock` (linha 340) — `Cache::lock()`
  funciona com cache `database`, então `WithoutOverlapping` (que usa `Cache::lock()` por baixo)
  também funciona.
- `Illuminate\Queue\Middleware\RateLimited::handleJob()` (lido em
  `vendor/laravel/framework/.../RateLimited.php`) chama `$job->release($delay)` quando o limite
  estoura — NÃO marca falha, só reagenda. Funciona igual com fila `database` (release = atualizar
  `available_at` na tabela `jobs`).
- **Precedente vivo no próprio projeto**, em produção: `RateLimiter::for('adman-api', fn () =>
  Limit::perMinute(8)->by('global'))` em `AppServiceProvider::boot()` + `middleware(): [new
  RateLimited('adman-api')]` em `AnalyzeCompanySugadoresJob`, `SyncAdmanCompanyJob`
  (parcialmente — ver nota abaixo) e outros. Isso já roda com workers concorrentes
  (`ecf-worker_00/01`, citado em comentário de `AdmanMcpService.php`).

**Recomendação:** copiar o padrão `'adman-api'` literalmente para um bucket novo
`'clicksign-envelope'`, `Limit::perMinute(1)->by('global')` (ver seção Architecture Patterns
acima). Adicionar `WithoutOverlapping('clicksign-envelope-global')` como camada extra: `RateLimited`
sozinho tem uma pequena janela de corrida (`tooManyAttempts()` e `hit()` são duas chamadas
separadas, não uma operação atômica única) — em teoria dois workers podem passar pelo check quase
juntos. Com um envelope custando 15 de 20 chamadas, essa corrida rara na pior hipótese soma 30
chamadas e estoura 429 — que o `ClicksignClient::enviar()` já trata com retry, e se esgotar,
aciona o `tries`/`backoff` do job. Não é corrupção de dado, é só um contrato demorando mais para
sair — mas `WithoutOverlapping` fecha essa lacuna de graça, sem custo de infra novo.

**Sobre `->delay()` calculado no dispatch:** não é suficiente sozinho. Resolve o espaçamento DENTRO
da mesma leva de N jobs de UMA empresa, mas o rate limit da Clicksign é da CONTA inteira — duas
empresas diferentes gerando contrato quase ao mesmo tempo (Comercial de dois analistas clicando
"gerar" em sequência) burlariam um `->delay()` calculado só a partir do índice do serviço dentro da
própria empresa. Pode ser usado como CAMADA ADICIONAL (ex.: `delay(($indice) * 5)` só para não
lançar N jobs no exato mesmo segundo, reduzindo a chance de bater na corrida do `RateLimited`), mas
não substitui o bucket global.

**⚠️ Discrepância a resolver com o time, não com pesquisa adicional:** comentários no código
(`AppServiceProvider.php`, `AdmanMcpService.php`) dizem "via cache **Redis**" para o bucket
`adman-api`, mas o `config/cache.php` + `.planning/codebase/STACK.md` (linha 88) documentam
`CACHE_STORE=database` como padrão de produção, com Redis "opcional" só para isolamento de chaves.
Não deu para confirmar QUAL store roda de fato no `.env` do VPS a partir deste repositório (gitignored). **Isto não muda a recomendação** — o mecanismo funciona corretamente nos dois stores
(comprovado acima para `database`; `redis` é ainda mais rápido/atômico nativamente) — mas o
comentário do código pode estar desatualizado ou o VPS pode estar em `redis` sem que o `.env.example`
reflita isso. Não é bloqueante para esta fase.

### Q2 — Padrão de job de trabalho longo com API externa (LIDO DO CÓDIGO)

| Aspecto | `AnalyzeCompanySugadoresJob` | `SyncAdmanCompanyJob` | Recomendação para esta fase |
|---|---|---|---|
| `tries` | 4 (janela de rate-limit ampla, ~1h) | 3 | 3 — mais perto do caso Adman-simples; a janela Clicksign é de 1 minuto, não 1 hora, então não precisa de tantas tentativas |
| `timeout` | 900 (paginação pesada) | 120 (chamada única) | ~300 (INFERIDO — 15 chamadas sequenciais, sem paginação, mas sem medição de tempo real) |
| `backoff()` | `[180, 900, 1800, 3600]` | `[60, 300, 900]` | `[60, 300, 900]` — copiar o de `SyncAdmanCompanyJob`, mesmo formato de problema (chamada de API simples, não paginação) |
| `middleware()` | `[new RateLimited('adman-api')]` | nenhum | `[WithoutOverlapping(...), new RateLimited('clicksign-envelope')]` |
| `failed()` | `Log::error` com tag `[AnalyzeCompanySugadoresJob]` + empresa id/nome | `Log::error` com tag `[SyncAdmanCompanyJob]` + empresa id/nome | Mesmo padrão, tag `[GerarContratoAssinaturaJob]` (ou nome escolhido) + `contrato_assinatura->id` + `company_id` — **nunca** incluir e-mail/nome do signatário (mesma regra WR-11 já aplicada em `ClicksignClient`) |
| Injeção de dependência em `handle()` | via container (`SugadorAnalysisService $service`) | via container (`AdmanService $adman`) | via container (`ClicksignClient $client, ContratoVariaveisModeloService $variaveis`) |
| Log de sucesso | `Log::info` com tag `[Sugadores]` dentro do `handle()` | nenhum log de sucesso explícito (delega ao `AdmanService`) | seguir o padrão de `ClicksignClient::enviar()`, que já loga cada chamada via canal `ecf-webhooks` — o job não precisa duplicar log de cada chamada, só um log de conclusão do contrato |

### Q3 — Onde e como congelar o `servicos_snapshot`

**LIDO DO CÓDIGO.** A fonte é `Company::contratosServico()` filtrado por `ativo=true` (mesmo
padrão de `PendenciasComerciaisService::calcular()`, que já usa `$c->contratosServico->where('ativo',
true)`). Cada `ContratoServico` tem `servico_id`, `valor_contratado` (`decimal:2`),
`data_contratacao`/`data_vencimento` (`date:Y-m-d`) — exatamente a forma que
`ContratoAssinaturaFactory::comSnapshot()` já usa como fixture e que `ContratoPdfService::montarDados()`
espera (`servico`, `valor_contratado`, `data_contratacao`, `data_vencimento`). O nome do serviço
(`servico` no snapshot) vem de `Servico::$nome`, via `->servico->nome` do relacionamento
`ContratoServico::servico()`.

Sob o modelo D-21 (um contrato por serviço — ver `## Tensão de arquitetura`), o congelamento vira
**um item por linha `ContratoAssinatura`**, não a lista inteira: para cada `ContratoServico` ativo,
criar uma `ContratoAssinatura` cujo `servicos_snapshot` é `[[ 'servico' => ..., 'valor_contratado'
=> ..., 'data_contratacao' => ..., 'data_vencimento' => ... ]]` (array de 1 elemento — forma
compatível com `ContratoPdfService`/`ContratoVariaveisModeloService`, comprovado acima).

**Sobre a armadilha do `company_users`:** conferida e **não se aplica a este ponto**. Os 4
signatários de todo contrato são fixos (D-08 da Fase 126): o cliente (via
`Company::email_cliente`/`nome_contato`) + 3 pessoas da ECF lidas de
`config('services.clicksign.signatarios_ecf')`, nunca de `company_users`. O congelamento do
snapshot também não passa por `company_users` — vem inteiramente de `contratos_servico` +
`servicos`. A armadilha (`company_users` ter várias linhas por empresa+role, uma por serviço) é
real no projeto (documentada em `.planning/learnings/desempenho-bonificacao.md:309`), mas relevante
para telas de carteira/bônus, não para esta fase — **não há necessidade de tocar em
`company_users` em nenhum ponto do fluxo desta fase.**

### Q4 — Idempotência por constraint: como capturar a violação (LIDO DO CÓDIGO — precedente existe)

**Precedente direto no projeto**, usado em 2 controllers de NPS (`NpsController.php:1835`,
`NpsGrupoController.php:301`) para o MESMO tipo de guarda (índice único parcial):

```php
try {
    // ... criação do ContratoAssinatura + dispatch do job ...
} catch (\Illuminate\Database\QueryException $e) {
    if ((string) $e->getCode() === '23000') {
        // já existe um contrato em andamento para esta empresa+serviço —
        // idempotência do Success Criteria 5, sem checagem prévia com risco
        // de corrida.
        return /* resposta apropriada, não é erro de sistema */;
    }
    throw $e;
}
```

**Por que `getCode() === '23000'` (SQLSTATE) e não `errorInfo[1] === 1062`:** o projeto usa o
código SQLSTATE genérico, que é **o mesmo em MariaDB e SQLite** — os testes rodam em SQLite
(`phpunit.xml`: `QUEUE_CONNECTION=sync`, banco de teste tipicamente SQLite neste projeto) e
`errno 1062` é específico do MySQL/MariaDB; SQLite devolve outro errno para violação de UNIQUE. Usar
o SQLSTATE `'23000'` é a única forma de o mesmo `catch` funcionar igual em teste e produção — e é
exatamente o que os 2 precedentes já fazem. **Copiar literalmente**, não reinventar checagem por
`errorInfo`.

**Onde o `catch` deve viver:** em torno da criação do `ContratoAssinatura` (INSERT), não em torno
do dispatch do job — a violação acontece no INSERT, antes de qualquer job existir. Isso também
significa que a checagem roda **antes** de qualquer chamada HTTP à Clicksign, reforçando o Success
Criteria 1.

### Q5 — `PendenciasComerciaisService::calcular()` NÃO serve para o Success Criteria 1

**LIDO DO CÓDIGO — resposta direta: NÃO cobre, e não deve ser estendido.**

O método `calcular()` (`app/Services/Comercial/PendenciasComerciaisService.php`):

1. **Retorna array vazio para QUALQUER empresa que não seja `is_origem_hubspot`** (linha 42-44:
   `if (!$c->is_origem_hubspot) { return []; }`). Empresa cadastrada à mão pelo Comercial —
   exatamente o caso que a Fase 128 (A4) ainda vai decidir — sempre passaria pela checagem sem
   nenhuma pendência detectada, mesmo faltando e-mail ou CNPJ. Usar este método aqui abriria uma
   brecha onde o bloqueio do Success Criteria 1 simplesmente não roda para metade das empresas.
2. Das 7 pendências que ele calcula (`sem_servico`, `sem_valor`, `servico_nao_reconhecido`,
   `sem_setor`, `sem_contato`, `valor_revisar`, `possivel_duplicidade`), **nenhuma checa e-mail do
   cliente** (`email_cliente`) e **nenhuma checa CNPJ**. `sem_contato` checa só `nome_contato`
   vazio — cobre 1 dos 3 bloqueantes (nome do contato), mas não os outros 2.
3. As 7 pendências existem para um propósito diferente (dado comercial incompleto pós-handoff
   HubSpot — Fase 124/114), não para o gate jurídico "documento pode sair com este dado". São
   conceitos vizinhos, não o mesmo conceito.

**Recomendação:** criar uma checagem NOVA e pequena, própria desta fase (ex.: método num novo
`ContratoClicksignService` ou serviço dedicado), que valida:

```php
// Formato ilustrativo — nomes/local são discrição do planner
private function dadosMinimosFaltando(Company $company): array
{
    $faltando = [];

    if (blank($company->email_cliente) || !filter_var($company->email_cliente, FILTER_VALIDATE_EMAIL)) {
        $faltando[] = 'email_cliente';
    }

    // REDE-05 pede "presença e formato" — não checksum de dígito verificador
    // (não é isso que o requisito pede, e não há helper de validação de CNPJ
    // no projeto hoje — LIDO DO CÓDIGO, grep não achou nenhum). Formato: 14
    // dígitos após remover pontuação.
    $cnpjDigitos = preg_replace('/\D/', '', (string) $company->cnpj);
    if (blank($company->cnpj) || strlen($cnpjDigitos) !== 14) {
        $faltando[] = 'cnpj';
    }

    if (blank($company->nome_contato)) {
        $faltando[] = 'nome_contato';
    }

    return $faltando;
}
```

**Não gated por `is_origem_hubspot`** — precisa rodar para toda empresa, HubSpot ou cadastrada à
mão, porque o dado que falta (e-mail, CNPJ, nome) é o mesmo problema em qualquer origem.

### Q6 — Parar no rascunho (D-02): forma menos invasiva

**LIDO DO CÓDIGO.** `ClicksignClient::montarEnvelopeComum()` (privado, chamado por
`montarEnvelope()` e `montarEnvelopePorModelo()`) tem UMA única chamada de ativação, no fim do
`try`:

```php
$passoAtual = 'ativar envelope';
$this->ativarEnvelope($envelopeId);
```

A forma menos invasiva é um **parâmetro booleano opcional** propagado pelos 3 métodos (`false` só
onde a Fase 127 precisa; default `true` preserva 100% do comportamento e dos testes já escritos na
Fase 126):

```php
public function montarEnvelopePorModelo(
    array $dadosEnvelope,
    string $nomeArquivo,
    string $templateId,
    array $variaveis,
    array $signatarioCliente,
    bool $ativar = true,          // NOVO — default preserva comportamento atual
): array {
    return $this->montarEnvelopeComum(
        $dadosEnvelope, $signatarioCliente, 'anexar documento por modelo',
        fn (string $envelopeId) => $this->anexarDocumentoPorModelo($envelopeId, $nomeArquivo, $templateId, $variaveis),
        $ativar,                    // repassa
    );
}

private function montarEnvelopeComum(
    array $dadosEnvelope,
    array $signatarioCliente,
    string $passoAnexar,
    \Closure $anexarDocumento,
    bool $ativar = true,          // NOVO
): array {
    // ... sequência atual idêntica até o fim dos requisitos ...

    if ($ativar) {
        $passoAtual = 'ativar envelope';
        $this->ativarEnvelope($envelopeId);
    }

    return [ /* mesmo shape de retorno */ ];
    // catch/rollback (D-04) continua EXATAMENTE igual — roda em qualquer
    // exceção antes deste ponto, independente de $ativar
}
```

**Por que isto não quebra o rollback compartilhado (D-04):** o `catch` que dispara
`cancelarEnvelope()` envolve TODA a sequência (documento, signatários, requisitos, ativação), e
`$ativar=false` só remove a ÚLTIMA etapa de dentro do `try` — se qualquer etapa ANTERIOR falhar, o
comportamento é idêntico ao de hoje. Se a etapa de ativação simplesmente não roda (porque
`$ativar=false`), não há nada para dar errado ali, então o rollback nunca precisa lidar com um caso
novo.

**Por que não duplicar a sequência (método novo separado):** duplicar copiaria as ~50 linhas de
`montarEnvelopeComum()` — qualquer bugfix futuro (ex.: mudança no rollback ou na ordem dos
requisitos) precisaria ser aplicado em dois lugares, exatamente o tipo de duplicação que o
docblock da classe já evita entre `montarEnvelope()`/`montarEnvelopePorModelo()` via
`montarEnvelopeComum()`.

**Consequência sobre `deadline_at`/`remind_interval` (D-03):** como a ativação NÃO roda nesta fase,
os parâmetros de `ativarEnvelope(string $envelopeId, int $prazoDias, int $lembreteDias)` também não
rodam. D-03 já antecipa isso: *"Prazo e lembrete vão na CRIAÇÃO do envelope, não na ativação"* — ou
seja, `$dadosEnvelope` passado para `criarEnvelope()` (primeira chamada de
`montarEnvelopeComum()`) já precisa incluir `deadline_at`/`remind_interval` calculados a partir do
prazo desejado. Isto é responsabilidade do orquestrador desta fase (montar `$dadosEnvelope` com
esses dois atributos), não do `ClicksignClient` — o client já aceita qualquer atributo dentro de
`criarEnvelope(array $atributos)` sem mudança nenhuma.

## Common Pitfalls

### Pitfall 1: Constraint da Fase 125 bloqueando o 2º serviço da mesma empresa

**O que dá errado:** todo contrato de empresa com 2+ serviços ativos falha no 2º (e seguintes) com
`QueryException 23000`, mesmo sem nenhum bug de corrida — é determinístico, acontece sempre.
**Por que acontece:** ver `## Tensão de arquitetura` — constraint por `company_id`, decisão D-21 é
por `company_id + servico_id`.
**Como evitar:** resolver a migration/coluna `servico_id` + índice composto ANTES de escrever
qualquer plano de tarefa desta fase.
**Sinal de alerta:** teste de integração com empresa de 1 serviço só passa "por acaso" — sempre
testar com empresa de 2+ serviços ativos.

### Pitfall 2: Confundir a resposta do evento `sign` com o payload de entrada do signatário

**O que dá errado:** já aconteceu 3 vezes nesta milestone (`communicate_by`, cancelamento por
`PATCH status`, `filename` com `.pdf`) — modelar fixture a partir de resposta da API e presumir que
o mesmo formato vale como entrada.
**Como evitar (regra já registrada em `126-CONTEXT.md`):** toda fixture nova declara no docblock se
é ENTRADA ou SAÍDA, e se é MEDIDO ou NÃO MEDIDO. Esta fase não introduz payload novo (reusa
`ClicksignClient` como está), mas se algum teste novo precisar de fixture de resposta de
`criarEnvelope()` com `deadline_at`/`remind_interval`, usar a resposta MEDIDA em §9 do empírico, não
inventar.

### Pitfall 3: Job "silencioso" quando todas as tentativas se esgotam

**O que dá errado:** se `failed()` só loga e não atualiza `status = erro`, a empresa fica presa com
`company_id_em_andamento` (ou `servico_id_em_andamento`, pós-fix) preenchido para sempre — nenhum
contrato novo pode ser gerado para aquele serviço, e ninguém vê isso na tela até a Fase 131 existir.
**Como evitar:** `failed()` DEVE gravar `status = ContratoAssinatura::STATUS_ERRO` +
`erro_mensagem` (podada, sem PII — mesma regra WR-11 já aplicada no `ClicksignClient`). Isto libera
a constraint (o hook `saving` zera a coluna derivada quando `status` sai de
`STATUS_EM_ANDAMENTO`) e permite nova tentativa manual depois.
**Sinal de alerta:** teste que força falha nas 3 tentativas e verifica se a empresa consegue gerar
contrato de novo depois.

### Pitfall 4: Reconsultar o envelope para "confirmar" cada etapa

**O que dá errado:** o orçamento é 15 chamadas contra uma janela de 20 — qualquer `consultarEnvelope()`
extra por curiosidade/log estoura o orçamento antes até de terminar a montagem.
**Como evitar:** confiar no retorno de cada chamada (`$res->json('data')`, já desembrulhado — §9.5
do empírico) em vez de reconsultar. `ClicksignClient` já não reconsulta em nenhum ponto — não
introduzir isso no job novo.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|---|---|---|
| A1 | `Limit::perMinute(1)->by('global')` é o valor certo para o bucket `clicksign-envelope` | Architecture Patterns / Q1 | Se a janela real de produção for maior que 20/min (só sandbox foi medido — §1 do empírico), 1/min é conservador demais e desperdiça throughput; se for MENOR, ainda sobra risco de estouro em concorrência rara. Ajustar depois de medir produção (gate #2 desta fase, já listado em `127-CONTEXT.md`) |
| A2 | `timeout = 300` no job novo | Q2 / Architecture Patterns | Nenhuma medição de tempo real de 15 chamadas sequenciais existe; se alguma chamada precisar dos 3 retries internos (até ~90s por chamada em teoria), 300s pode não bastar em cenário de degradação simultânea da API. Sem dado para calibrar melhor — flag para o planner considerar medir no gate/checkpoint humano |
| A3 | Recomendação de coluna `servico_id` + índice composto (Opção A) é a melhor resolução da tensão de arquitetura | Tensão de arquitetura | É uma recomendação de menor mudança, não uma medição — o planner (ou um checkpoint humano) deve confirmar antes de codificar, porque afeta o schema já em produção da Fase 125 |
| A4 | "CNPJ válido" (REDE-05) significa formato (14 dígitos), não dígito verificador | Q5 | Se o requisito realmente quer validação de dígito verificador (algoritmo do CNPJ), a checagem proposta deixaria passar CNPJ com formato certo mas matematicamente inválido. `REDE-05` no `REQUIREMENTS-v22.md` diz literalmente "presença e formato", o que apoia a leitura mais simples, mas vale confirmar em checkpoint se o Administrativo precisa de mais rigor |

## Open Questions

1. **A tensão de arquitetura (`## Tensão de arquitetura`) precisa de decisão explícita antes do
   plano.**
   - O que sabemos: a constraint atual é por empresa; D-21 exige por empresa+serviço.
   - O que é incerto: se a resolução (Opção A, coluna `servico_id`) é aceitável sem checkpoint
     humano, dado que mexe num schema que a Fase 125 já colocou em produção com testes/factories
     já escritos que presumem "1 linha = todos os serviços da empresa" (`ContratoAssinaturaFactory::comSnapshot()`
     tem 3 serviços no exemplo default).
   - Recomendação: o planner deve tratar isto como Task 0 / fundação da fase, com um checkpoint
     humano curto confirmando a direção antes de gerar as demais tarefas — o custo de planejar em
     cima da leitura errada é maior que o custo de uma pergunta.

2. **Persistência de `prazo_dias`/`lembrete_dias` (DADOS-06).**
   - O que sabemos: o valor precisa ser configurável por contrato e refletido no envelope criado
     (Success Criteria 3 do ROADMAP).
   - O que é incerto: se DADOS-06 exige que o valor ESCOLHIDO fique auditável depois (nova coluna)
     ou se basta ele ir no payload da chamada sem sobreviver no nosso banco (já que a Clicksign
     "é a fonte" via `deadline_at` do envelope, reconsultável por API).
   - Recomendação: dado que a Fase 130 (REDE-02, alerta de contrato preso) provavelmente precisa
     saber o prazo real para calcular "há quanto tempo é aceitável esperar", persistir é mais seguro
     — mas confirmar com quem vai planejar a 130 evita retrabalho de migration.

## Environment Availability

Não aplicável — nenhuma dependência externa nova. `ClicksignClient` já configurado (Fase 126),
fila/cache `database` já em uso, PHP/Composer/Node já disponíveis no ambiente (ver `CLAUDE.md`).

## Validation Architecture

### Test Framework

| Property | Value |
|---|---|
| Framework | PHPUnit 11.x (`phpunit/phpunit ^11.5.50`) |
| Config file | `phpunit.xml` — `QUEUE_CONNECTION=sync` nos testes (jobs rodam inline, sem fila real) |
| Quick run command | `C:\xampp\php\php.exe artisan test --filter=ContratoClicksign` (ajustar filtro ao nome real das classes) |
| Full suite command | `C:\xampp\php\php.exe artisan test` |

### Phase Requirements → Test Map

| Req ID | Comportamento | Tipo de teste | Comando automatizado | Arquivo existe? |
|---|---|---|---|---|
| REDE-05 | Recusa sem chamar Clicksign quando falta e-mail/CNPJ/nome | unit | `artisan test --filter=DadosMinimosTest` | ❌ Wave 0 |
| CLICK-02 | Envelope criado com documento+signatários+requisitos, sem ativar | feature (Http::fake) | `artisan test --filter=GerarContratoAssinaturaJobTest` | ❌ Wave 0 |
| CLICK-08 | `remind_interval` presente na criação | feature (Http::fake, asserta payload de `criarEnvelope`) | mesmo arquivo acima | ❌ Wave 0 |
| DADOS-06 | Prazo customizado por contrato refletido no envelope | feature (Http::fake) | mesmo arquivo acima | ❌ Wave 0 |
| Success Criteria 5 (idempotência) | 2ª chamada não cria 2º envelope | unit/feature — força `QueryException 23000` | `artisan test --filter=IdempotenciaContratoTest` | ❌ Wave 0 |
| Espaçamento (D-01) | `RateLimited`/`WithoutOverlapping` aplicados | unit (assert `middleware()` do job) | mesmo arquivo do job | ❌ Wave 0 |

⚠️ **Nota sobre `Http::fake()` nesta fase:** seguindo D-15 (126-CONTEXT.md), toda fixture nova de
resposta Clicksign deve ser cópia literal do que está em `CLICKSIGN-SANDBOX-EMPIRICO.md` — nunca
inventada. Para `criarEnvelope()` com `deadline_at`/`remind_interval`, usar a resposta medida na
D-03/§ do empírico (10 dias / lembrete 7, testado em 11/08/2026).

### Sampling Rate

- Por commit de tarefa: `artisan test --filter=<Classe>`
- Por merge de wave: `artisan test` completo
- Gate de fase: suíte completa verde antes de `/gsd:verify-work`

### Wave 0 Gaps

- [ ] `tests/Unit/DadosMinimosContratoTest.php` — cobre REDE-05 (Q5 desta pesquisa)
- [ ] `tests/Feature/GerarContratoAssinaturaJobTest.php` — cobre CLICK-02/CLICK-08/DADOS-06, com
      `Http::fake()` de fixtures copiadas do empírico
- [ ] `tests/Feature/IdempotenciaContratoTest.php` — cobre Success Criteria 5, força
      `QueryException 23000` real (SQLite dos testes já suporta unique constraint)
- [ ] Migration de `servico_id` em `contrato_assinaturas` (se a Opção A da tensão de arquitetura
      for confirmada) precisa de teste cross-driver (SQLite dos testes vs. MariaDB de produção) —
      ver as 3 armadilhas de schema já documentadas no projeto

## Security Domain

### ASVS Categories aplicáveis

| ASVS Category | Aplica | Controle padrão |
|---|---|---|
| V2 Authentication | Não (esta fase não lida com autenticação de usuário) | — |
| V4 Access Control | Indireto | `iniciarParaEmpresa()` deve ser chamado só de contexto autorizado (Fase 131 cuida da permissão `admin.contratos` — esta fase não expõe rota HTTP nova, só o service) |
| V5 Input Validation | Sim | Validação de e-mail/CNPJ/nome ANTES de qualquer I/O externo (REDE-05) — já coberto pela checagem da Q5 |
| V6 Cryptography | Não | Nenhum dado criptografado novo nesta fase |
| V9 Communications (dados em trânsito) | Sim | Já coberto por `ClicksignClient` (Fase 126) — HTTPS, token nunca logado |

### Padrões de ameaça conhecidos

| Padrão | STRIDE | Mitigação padrão |
|---|---|---|
| PII do signatário (nome/e-mail) vazando em log de erro | Information Disclosure | Já mitigado no `ClicksignClient` (WR-11) — o job novo deve seguir a mesma disciplina: `failed()`/exceções só logam id/status, nunca corpo de resposta ou dados de contato |
| Job duplicado por double-click no botão "gerar" | Tampering/DoS (uso indevido) | Constraint de banco (D-05) + `QueryException 23000` — já coberto pela recomendação da Q4 |
| Rate limit da conta Clicksign estourado por concorrência entre empresas | Denial of Service (indireto — bloqueia geração de contrato para todo mundo) | Bucket global `RateLimited` + `WithoutOverlapping` (Q1) |

## Sources

### Primary (LIDO DO CÓDIGO — arquivos deste repositório, alta confiança)

- `app/Services/Clicksign/ClicksignClient.php` — sequência completa de `montarEnvelopeComum()`,
  rollback D-04, headers, retry
- `app/Services/Clicksign/ContratoVariaveisModeloService.php` — mapa de variáveis, compatibilidade
  com array de 1 serviço
- `app/Services/ContratoPdfService.php` — forma de `montarDados()`, `PLACEHOLDER`, `campos_pendentes`
- `app/Services/Comercial/PendenciasComerciaisService.php` — as 7 pendências, gate `is_origem_hubspot`
- `app/Models/ContratoAssinatura.php`, `ContratoAssinaturaSignatario.php` — estados, hook `saving`,
  constraint
- `app/Models/Company.php`, `ContratoServico.php`, `Servico.php` — campos fonte do snapshot
- `app/Jobs/AnalyzeCompanySugadoresJob.php`, `SyncAdmanCompanyJob.php` — padrão de job
- `app/Providers/AppServiceProvider.php` — registro de `RateLimiter::for()`
- `app/Http/Controllers/NpsController.php:1835`, `NpsGrupoController.php:301` — precedente de
  captura `QueryException` 23000
- `vendor/laravel/framework/src/Illuminate/Cache/DatabaseStore.php`,
  `vendor/laravel/framework/src/Illuminate/Queue/Middleware/RateLimited.php`,
  `vendor/laravel/framework/src/Illuminate/Cache/RateLimiter.php` — comportamento atômico com
  cache `database`
- `database/migrations/2026_08_10_100000_create_contrato_assinaturas_table.php` — constraint atual
- `config/queue.php`, `config/cache.php`, `config/services.php` — infra confirmada

### Secondary (DOCUMENTADO — docs do projeto, alta confiança dentro do projeto)

- `.planning/research/CLICKSIGN-SANDBOX-EMPIRICO.md` §1, §9, §10 — API medida contra sandbox real
  (não re-verificada nesta pesquisa, por instrução explícita do escopo)
- `.planning/phases/126-client-clicksign-pdf-do-contrato-v22-0/126-CONTEXT.md` — D-16 a D-21
- `.planning/phases/125-estrutura-de-dados-administrativa-v22-0/125-CONTEXT.md` — D-01, D-10, schema
- `.planning/phases/127-service-administrativo-de-contrato-orquestra-o-v22-0/127-CONTEXT.md`
- `.planning/REQUIREMENTS-v22.md` — texto exato de CLICK-02/08, DADOS-06, REDE-05
- `.planning/ROADMAP.md` — Success Criteria da Fase 127 e das fases vizinhas
- `.planning/codebase/STACK.md` — cache/queue store padrão de produção
- `.planning/learnings/desempenho-bonificacao.md` — armadilha `company_users`

### Tertiary (nenhuma — esta pesquisa não usou WebSearch/WebFetch por instrução explícita do escopo)

## Metadata

**Confidence breakdown:**
- Integração Clicksign (client, rollback, rate limit mecânico): ALTA — tudo lido do código +
  medições já feitas pela Fase 126, nada novo verificado por mim que não fosse leitura direta
- Padrão de job/fila: ALTA — 2 precedentes concordantes + verificação do comportamento do framework
  no próprio `vendor/`
- Schema/arquitetura de N contratos por serviço: MÉDIA-BAIXA — é uma CONTRADIÇÃO real encontrada
  por leitura cruzada, mas a RESOLUÇÃO recomendada (Opção A) é uma proposta desta pesquisa, não uma
  decisão já tomada por ninguém — precisa de confirmação humana antes de virar plano
- Checagem de dados mínimos (Q5): MÉDIA — a inadequação do `PendenciasComerciaisService` é fato
  lido do código; o desenho da checagem nova é proposta, não medição

**Research date:** 2026-08-11
**Valid until:** ~15 dias (domínio interno estável, mas a tensão de arquitetura pode ser resolvida
de forma diferente da recomendada aqui — revalidar se qualquer decisão de schema mudar antes do
plano ser escrito)
