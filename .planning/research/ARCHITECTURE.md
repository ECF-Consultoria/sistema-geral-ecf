# Pesquisa de Arquitetura — Etapa Administrativa de Contrato (v22.0)

**Domínio:** Integração de uma etapa administrativa (geração de contrato + assinatura Clicksign) no fluxo de entrada de empresas de um Laravel 12 + Inertia + React em produção
**Pesquisado em:** 2026-08-07
**Confiança:** HIGH (baseado em leitura direta do código, não do plano)

> Nota: este arquivo substitui uma pesquisa antiga de arquitetura (v2.0 — Módulo de
> Fechamento Administrativo, 2026-05-19), que tratava de outro escopo (tela de
> fechamento mensal) e não é mais relevante para a milestone atual.

## Resumo executivo

O sistema hoje tem **dois caminhos de entrada de empresa** que terminam, cada um à sua
maneira, roteando a empresa direto para o operacional (`MlbEmpresa` + `MlbImplementacao`)
no mesmo fôlego em que criam `Company` e `ContratoServico`. Não existe hoje nenhum
ponto de espera entre "contrato comercial fechado" e "operacional começou a trabalhar".
A v22.0 precisa inserir esse ponto de espera SEM duplicar a lógica de roteamento (hoje
já duplicada 2x) e sem correr o risco de, por falha externa (Clicksign fora do ar,
webhook que não chega), parar silenciosamente a entrada de empresas novas na operação.

A boa notícia, confirmada lendo o código e não só o plano: o sistema já tem **dois
precedentes arquiteturais diretamente reaproveitáveis** para tudo que a v22.0 precisa:

1. **Idempotência de webhook** — `HubspotWebhookController` já resolve isso em duas
   camadas (evento e dado) com um padrão maduro, testado em produção desde a Fase 34,
   que serve de literal copy-paste estrutural para o webhook Clicksign.
2. **Kill switch sem deploy + override manual auditado** — `Configuracao` (chave/valor)
   e `BonusAuditoriaController`/`BonusInvalidacao` (toggle com `invalidated_by` +
   `motivo`) já são o padrão do projeto para "desligar uma regra sem deploy" e "ação
   manual do admin com registro de quem e por quê". Não é preciso inventar nada novo
   nesse eixo — é aplicar o mesmo padrão a um domínio novo.

O ponto de maior risco não é técnico, é de **sequenciamento**: o roteamento operacional
hoje é síncrono e acontece dentro da MESMA transação que cria a empresa (nos dois
caminhos). Cortar esse fluxo exige que o "bloqueio" seja a ÚLTIMA coisa a entrar em
produção, atrás de um kill switch testado em modo observação, porque o efeito de um
bug aqui não é um 500 visível — é a operação silenciosamente parar de receber empresas.

## Arquitetura atual (as-is)

```
┌──────────────────────────┐        ┌──────────────────────────────────┐
│   Entrada HubSpot          │        │   Entrada Manual (Comercial)      │
│   (webhook, assíncrono)    │        │   (form, síncrono)                │
├──────────────────────────┤        ├──────────────────────────────────┤
│ HubspotWebhookController   │        │ ComercialController::store()      │
│  ::receive()                │        │  routes/web.php:457               │
│  ::processar()  (linha 132) │        │                                    │
│  ::criarEmpresa() (linha    │        │                                    │
│    498, DB::transaction)    │        │                                    │
└──────────┬─────────────────┘        └────────────┬───────────────────┘
           │                                          │
           │  dentro da MESMA DB::transaction          │  dentro da MESMA
           ▼                                          ▼  DB::transaction
   Company::create()                          Company::create()
   (ou enriquecerEmpresaExistente)             (linha 645)
           │                                          │
           ▼                                          ▼
   persistirContratos()                       ContratoServico::create()
   linha 644 → linha 797                       (loop, linha 663-681)
   (guard hubspot_line_item_id,                (SEM guard de duplicidade —
    linhas 811-819)                             empresa é sempre nova aqui)
           │                                          │
           ▼                                          ▼
   foreach ($servicosCriados)                 $tiposImplementacao = ...
     rotearImplementacao()                     ->map(servicoDisparaImplementacao)
     linhas 649-651 → 929                       linhas 685-689
           │                                          │
           ▼                                          ▼
   guard: MlbEmpresa::where(company_id)        foreach ($tiposImplementacao)
     ->exists() → return (linha 938)             cria MlbEmpresa inline
           │                                       (linhas 691-715, SEM guard —
           ▼                                        não precisa: empresa nova)
   cria MlbEmpresa + MlbImplementacao
   (polos/assessoria/incubadora)
           │                                          │
           └──────────────► OPERACIONAL ◄─────────────┘
                    (mesma transação, sem espera)
```

**Achado central:** os dois caminhos convergem na mesma REGRA
(`ComercialController::servicoDisparaImplementacao()`, linhas 54-62, estático e já
reusado pelo webhook — linha 931), mas duplicam a MECÂNICA de criar `MlbEmpresa`. O
webhook tem guard anti-duplicidade (linha 936-940); o Comercial não tem, porque nesse
caminho a empresa acabou de ser criada na mesma transação e nunca pode ter uma
`MlbEmpresa` prévia — mas isso é uma invariante IMPLÍCITA que se perde se algum dia o
Comercial passar a operar sobre empresa existente. Ao extrair para
`EmpresaOperacionalRouter`, o guard deve estar dentro do service sempre, não
condicionado ao caminho de chamada.

## Arquitetura proposta (to-be)

```
┌──────────────────────────┐        ┌──────────────────────────────────┐
│   Entrada HubSpot          │        │   Entrada Manual (Comercial)      │
├──────────────────────────┤        ├──────────────────────────────────┤
│ criarEmpresa()              │        │ store()                            │
│  cria Company + Contratos   │        │  cria Company + Contratos          │
│  (SEM MUDANÇA — mesma        │        │  (SEM MUDANÇA — mesma              │
│   transaction)               │        │   transaction)                     │
└──────────┬─────────────────┘        └────────────┬───────────────────┘
           │  <<< CORTE >>> — ponto exato descrito na seção 1 abaixo
           ▼                                          ▼
   PendenciasComerciaisService::calcular($company)
           │
           ├── tem pendência ──────► status administrativo = aguardando_comercial
           │                          (NÃO chama Clicksign, NÃO libera operacional)
           │
           └── sem pendência ──────► ContratoClicksignService::iniciarParaEmpresa()
                                       (fora da transaction de criação — ver seção 4)
                                              │
                                              ▼
                                     Clicksign: envelope criado, enviado
                                       status = enviado / aguardando_assinaturas
                                              │
                          ┌───────────────────┴───────────────────┐
                          ▼ (assíncrono, dias depois)              ▼ (override admin)
              Webhook Clicksign (novo)                  Tela Administrativa
              /api/webhooks/clicksign                    botão "Liberar manualmente"
              idempotência payload_hash                  (seção 6)
                          │                                        │
                          └───────────────► EmpresaOperacionalRouter::liberarEmpresa($company)
                                             (MESMO service, MESMO guard, chamado dos
                                              DOIS pontos — sem duplicar lógica)
                                                       │
                                                       ▼
                                                 OPERACIONAL
                                       (guard MlbEmpresa::where(company_id)->exists()
                                        continua sendo o backstop final de idempotência)
```

O `EmpresaOperacionalRouter` vira o **único** ponto de entrada para "mandar empresa
pro operacional" — chamado por 3 disparadores possíveis (webhook Clicksign, botão
manual do admin, e o kill switch em modo desligado que chama direto após
`persistirContratos`), nunca inline em controller.

## Pontos de integração exatos

### 1. Corte no caminho HubSpot

**Arquivo:** `app/Http/Controllers/Api/HubspotWebhookController.php`
**Método:** `criarEmpresa()` (linhas 498-717), dentro do `DB::transaction` (linha 513)

O corte é entre estas duas linhas hoje consecutivas:

```php
// linha 644
$servicosCriados = $this->persistirContratos($company, $handoff, $evento);

// linhas 649-651 — ESTE BLOCO é o que precisa parar de rodar incondicionalmente
foreach ($servicosCriados as $nomeServico) {
    $this->rotearImplementacao($company, $nomeServico);
}
```

**O que precisa acontecer no lugar:** depois de `persistirContratos()` retornar
`$servicosCriados`, chamar `PendenciasComerciaisService::calcular($company)`. Se vazio,
disparar `ContratoClicksignService::iniciarParaEmpresa($company)` — mas **fora** da
`DB::transaction` atual (ver seção 4, por quê). Dentro da transaction, o máximo que deve
acontecer é criar/atualizar o registro `ContratoAssinatura` em estado inicial
(`pronto_para_contrato`), nunca uma chamada HTTP externa.

O método privado `rotearImplementacao()` (linhas 929-963) inteiro migra para dentro de
`EmpresaOperacionalRouter::liberarEmpresa()` — ele já é praticamente um service em forma
de método privado, só precisa trocar de casa.

### 2. Corte no caminho Comercial

**Arquivo:** `app/Http/Controllers/ComercialController.php`
**Método:** `store()` (linhas 593-748), dentro do `DB::transaction` (linha 643)

O corte é entre estas linhas:

```php
// linha 681 (fim do loop que cria ContratoServico)
$servicosCriados->push($servico);

// linhas 683-715 — ESTE BLOCO (roteamento inline) precisa parar de rodar
// incondicionalmente
$tiposImplementacao = $servicosCriados
    ->map(fn($s) => self::servicoDisparaImplementacao($s->nome))
    ->filter()->unique()->values();

foreach ($tiposImplementacao as $tipo) {
    if ($tipo === 'polos') { ... }
    elseif ($tipo === 'assessoria') { ... }
    elseif ($tipo === 'incubadora') { ... }
}
```

Mesmo tratamento: `PendenciasComerciaisService::calcular($company)` decide se segue
para `ContratoClicksignService::iniciarParaEmpresa()` (fora da transaction) ou fica em
`aguardando_comercial`.

**Diferença importante em relação ao webhook:** o plano (`plano-administrativo-
clicksign.md` linha 122) diz "Se a empresa não for origem HubSpot, manter comportamento
atual da UI" para `PendenciasComerciaisService`. Isso é sobre a UI de listagem
(`ComercialController::listagem()`, que hoje já restringe pendências comerciais a
`is_origem_hubspot` — linha 469-471 de `ComercialController.php`), não sobre o gate de
liberação administrativa. Para o GATE (decidir se manda pro Clicksign), a regra deve
avaliar toda empresa nova, HubSpot ou manual — senão toda empresa cadastrada
manualmente pula a etapa de contrato inteira, o que contradiz o objetivo da milestone.
Isso é uma decisão de escopo que falta no plano e deveria ir para discuss-phase da
Fase 1: **pendências para GATE (bloqueia Clicksign) ≠ pendências para UI (badges na
listagem)** — mesmo cálculo, usos diferentes.

### 3. Não existe hoje um terceiro ponto de entrada

Vale registrar negativamente: não há nenhum outro lugar no código que crie `Company` +
dispare roteamento operacional. `CompanyController` não cria empresa (comentário na
linha 726 de `routes/web.php`: "Cadastro de empresa removido de /companies"). Os dois
pontos acima são exaustivos — confirma a alegação do plano.

## Extração dos services propostos

### `PendenciasComerciaisService`

**O que move:** o corpo de `ComercialController::calcularPendenciasComerciais()`
(linhas 467-578) — método privado, 111 linhas, já testável isoladamente porque só
depende de `Company` com relações eager-loaded (`contratosServico`, `hubspotEventos`).
Não tem dependência de request/sessão — extração é mecânica.

**Risco de regressão:** BAIXO, com uma ressalva. O método usa uma `static $matchCache`
(linha 494) com escopo de processo PHP-FPM (não de request) para cachear resolução de
nome de line item — isso é uma otimização válida dentro de um único request que itera
várias empresas (`listagem()`), mas se o service virar singleton via DI padrão do
Laravel (`app(PendenciasComerciaisService::class)`), o cache estático sobrevive entre
requests diferentes na mesma worker do PHP-FPM. Não é um bug (o cache é só de
`HubspotLineItemMapping::paraNome()`, que muda raramente), mas é um comportamento a
preservar conscientemente, não perder na migração — se virar propriedade de instância
em vez de `static`, e o service for singleton, o efeito é o mesmo; se virar `static`
dentro de um service com binding `bind()` (não singleton) o cache se perde a cada
resolução e a otimização desaparece silenciosamente sem quebrar nada.

O segundo ponto de atenção: o método é chamado hoje só de dentro de `listagem()`
(contexto UI, filtra por `is_origem_hubspot`). Ao reusá-lo como GATE do fluxo
administrativo (chamado logo após `persistirContratos()`, com a `Company` recém-criada
e AINDA DENTRO da mesma `DB::transaction`), as relações (`contratosServico`,
`hubspotEventos`) podem não estar carregadas do jeito que o método espera — hoje ele
é chamado sobre uma `Company` que passou por `$c->refresh()->load([...])` em outro
lugar do fluxo (`calcularPendencias()`, não confundir com
`calcularPendenciasComerciais()` — são DOIS métodos parecidos, ver nota abaixo). Ao
extrair, o service precisa fazer seu próprio load/refresh determinístico, não herdar
o estado de eager-loading do call site.

**Nota de nomenclatura já armadilhada no código-fonte:** existem HOJE dois métodos com
nomes quase idênticos e responsabilidades diferentes — `ComercialController::
calcularPendenciasComerciais()` (linhas 467-578, 7 slugs, UI da listagem) e
`HubspotWebhookController::calcularPendencias()` (linhas 975-997, 4 slugs diferentes:
`sem_responsavel`, `sem_cust_id`, `sem_email_colaborador`, `sem_servico` — usado só para
decidir se notifica o Comercial). **Não são o mesmo conceito.** O plano
(`plano-administrativo-clicksign.md` linhas 108-117) descreve os 7 slugs de
`calcularPendenciasComerciais()` como o contrato de `PendenciasComerciaisService::
calcular()` — está certo, mas o nome genérico do plano pode levar quem implementar a
confundir com o outro método. Vale nomear o service de forma que não colida
mentalmente: `calcular()` retornando os 7 slugs de pendência COMERCIAL, deixando o
`calcularPendencias()` do webhook (pendência para notificação) fora de escopo,
inalterado.

### `EmpresaOperacionalRouter`

**O que move:**
- `HubspotWebhookController::rotearImplementacao()` (linhas 929-963) — corpo inteiro,
  incluindo o guard `MlbEmpresa::where('company_id', $company->id)->exists()`
  (linha 938).
- O bloco equivalente inline de `ComercialController::store()` (linhas 683-715).

**Assinatura proposta no plano:** `liberarEmpresa(Company $company): void` — recebe só
a `Company`, não o nome do serviço. Isso é uma mudança de forma importante: hoje
`rotearImplementacao(Company $company, string $nomeServico)` é chamado em loop, uma vez
por serviço criado (linha 649-651), porque uma empresa pode ter 2+ line items que
mapeiam para tipos diferentes (o guard de duplicidade de `MlbEmpresa` existe
EXATAMENTE por causa disso — 2 serviços, 1 única `MlbEmpresa`). Se o novo
`liberarEmpresa()` recebe só `Company`, ele precisa RE-DERIVAR os nomes de serviço a
partir de `$company->contratosServico()->where('ativo', true)` em vez de receber a
lista pronta — isso é uma diferença de contrato de dados que não está explícita no
plano e precisa ser resolvida no discuss-phase: **o router lê os contratos ativos da
empresa no momento da liberação (correto para o caso Clicksign — dias depois, pode
haver contrato adicional) em vez de receber a lista do momento da criação (o
comportamento atual)**. Essa mudança é desejável para a v22.0 porque entre "contrato
gerado" e "assinatura completa" a empresa pode legitimamente ganhar um serviço
adicional negociado à parte — mas é uma mudança de comportamento, não uma extração pura,
e deveria ser marcada como decisão explícita, não acidente de refactor.

**Risco de regressão:** MÉDIO, concentrado em dois pontos:
1. Guard de idempotência (`MlbEmpresa::where('company_id')->exists()`) precisa
   continuar sendo o PRIMEIRO passo do método novo — ele é o que impede duplicação
   quando `liberarEmpresa()` for chamado 2x (replay de webhook Clicksign, ou clique
   duplo no botão manual, ou corrida entre webhook e liberação manual simultâneos).
2. `criarImplementacaoPolo()` do `ComercialController` (linhas 970-981) hoje passa
   `$handoff` (dados do wizard, ex.: `gmail_colaborador`) para
   `MlbImplementacaoFactory::criarParaPolo($empresa, $handoff)`. O caminho do webhook
   chama `MlbImplementacaoFactory::criarParaPolo($mlbEmp)` SEM segundo argumento
   (linha 949 de `HubspotWebhookController.php`) — o `$handoff` do wizard não existe
   nesse caminho. Se `liberarEmpresa($company)` unifica os dois, o segundo argumento
   vira sempre ausente (comportamento do webhook), e o Comercial PERDE o
   preenchimento de `gmail_colaborador` na implementação — a menos que o router
   aceite um parâmetro opcional de contexto extra, ou que esse dado passe a ser lido
   de uma coluna da própria `Company`/`ContratoAssinatura` em vez de um array
   volátil. Isso é uma perda de funcionalidade silenciosa se não for tratada
   explicitamente na extração — vale registrar como item de teste obrigatório
   (Fase 13 do plano item 8: "Cadastro manual Comercial segue o mesmo fluxo do
   HubSpot" — esse teste, se escrito ingenuamente, pode passar sem cobrir a perda do
   `gmail_colaborador`).

## Padrão de idempotência do webhook HubSpot — o que copiar para o Clicksign

O webhook HubSpot resolve idempotência em **duas camadas independentes**, e as duas
são relevantes para o Clicksign:

### Camada 1 — idempotência de INGESTÃO de evento

Toda entrega HTTP vira uma linha em `hubspot_eventos` (migration
`2026_06_12_300002_create_hubspot_eventos_table.php`), **mesmo se inválida**
(`signature_valid=false`, ver `gravarInvalido()`, linhas 1049-1067). O guard de
não-reprocessar fica no método `processar()`, linhas 152-165:

```php
$jaProcessado = HubspotEvento::where('object_id', $evento->object_id)
    ->where('id', '!=', $evento->id)
    ->whereNotNull('company_id_criada')
    ->exists();
```

Ou seja: a idempotência NÃO é por hash do payload — é por `object_id` (o ID do deal no
HubSpot) mais a condição de que outro evento já produziu uma empresa. Isso funciona
porque o HubSpot manda múltiplos webhooks para o MESMO deal ao longo do tempo
(propriedades diferentes mudando), e o filtro de negócio (`dealstage` == fechado-ganho)
já reduz o volume relevante a poucos eventos por deal.

**O plano da v22.0 (linha 231, 239) propõe `payload_hash` único** em vez de
`object_id` — essa é uma escolha correta e SUPERIOR ao padrão HubSpot para o caso
Clicksign, não uma cópia cega: o HubSpot tem um identificador de negócio estável
(`object_id` = ID do deal) para agrupar eventos relacionados; a Clicksign manda eventos
de **assinatura por documento/envelope**, potencialmente reentregues idênticos por
retry de rede — nesse caso, dedup por hash do payload bruto é mais direto e não exige
resolver antes "qual é o campo estável de agrupamento". Vale manter os DOIS campos
(`provider_event_id` E `payload_hash`) como o plano já desenha (tabela
`contrato_assinatura_eventos`, linhas 223-241) — `payload_hash` como guard de
reentrega idêntica, `provider_event_id` como chave de busca/agrupamento por evento
lógico da Clicksign (quando a doc oficial confirmar o nome do campo).

### Camada 2 — idempotência de DADO (o que realmente evita duplicação)

Esta é a camada que mais importa e que o plano subestima ao descrevê-la só como
"guard `hubspot_line_item_id`". Na prática, há **dois guards de dado diferentes, em
dois pontos diferentes do pipeline**, e o Clicksign precisa dos dois equivalentes:

1. **Guard de contrato** (`persistirContratos()`, linhas 811-819):
   ```php
   $duplicado = $temLineItem
       ? ContratoServico::where('company_id', $company->id)
           ->where('hubspot_line_item_id', $c['hubspot_line_item_id'])->exists()
       : ContratoServico::where('company_id', $company->id)
           ->where('servico_id', $c['servico_id'])->where('ativo', true)->exists();
   ```
   Este guard protege contra duplicar o EFEITO de negócio (criar um segundo contrato
   idêntico), não contra reprocessar o evento em si. É o padrão certo para o
   equivalente Clicksign: antes de marcar `ContratoAssinatura` como `assinado` e
   preencher `signed_at`, checar se já não está nesse estado (ou em estado posterior).

2. **Guard de roteamento operacional** (`rotearImplementacao()`, linha 938):
   ```php
   if (MlbEmpresa::where('company_id', $company->id)->exists()) {
       return;
   }
   ```
   Este é o guard que MAIS importa para a v22.0, porque é ele quem vai proteger contra
   o cenário mais perigoso: webhook Clicksign duplicado (reentrega de rede, replay
   manual do admin, corrida entre webhook e botão de liberação manual) tentando
   liberar a mesma empresa duas vezes. **Este guard já existe, já está testado em
   produção, e não precisa ser reescrito — só precisa continuar sendo o primeiro passo
   de `EmpresaOperacionalRouter::liberarEmpresa()`.**

### O que copiar literalmente para `ClicksignWebhookController`

Do bloco de segurança do `HubspotWebhookController` (linhas 69-126):
- Ler raw body via `$request->getContent()` ANTES de qualquer parse — bytes precisam
  bater com o cálculo de HMAC.
- Validar timestamp/replay window ANTES de validar assinatura (defesa em profundidade
  barata).
- `hash_equals()` para comparação de HMAC (timing-safe) — não usar `===`.
- Em qualquer falha de validação: gravar o evento mesmo assim (`signature_valid=false`),
  responder 401 sem detalhar o motivo no corpo da resposta (só no log), e truncar o raw
  body em bytes fixos (65KB, `RAW_BODY_MAX_BYTES`) para não estourar disco com payloads
  hostis.
- Nunca logar o secret — só IP e tamanho do payload.
- `withoutMiddleware([ValidateCsrfToken::class])` + throttle na rota — mas note que
  `bootstrap/app.php` já isenta `api/webhooks/*` via wildcard (linha 23), então a nova
  rota `/api/webhooks/clicksign` **não precisa de nenhuma mudança em
  `bootstrap/app.php`** — só precisa registrar a rota com o prefixo certo.
- Responder sempre `200`/`ok` para o provedor mesmo em erro de processamento interno
  (padrão do HubSpot: erro inesperado grava `status='erro'` e retorna 200, para o
  provedor não ficar re-tentando indefinidamente um erro que não vai se resolver
  sozinho) — **esta é uma decisão de negócio que precisa ser tomada explicitamente
  para a Clicksign**: se o erro for transiente (timeout de banco), 200 impede retry
  automático do provedor e depende inteiramente do alerta de "contrato preso" (Fase de
  rede de segurança) para pegar o caso. Vale considerar diferenciar: erro de
  VALIDAÇÃO (assinatura/payload malformado) → sempre 200 (não é recuperável por
  retry); erro de PROCESSAMENTO INTERNO (banco fora, exception inesperada) →
  potencialmente vale retornar 5xx para a Clicksign re-tentar, já que a idempotência
  por `payload_hash` torna reentrega segura. Isso diverge do padrão HubSpot copiado
  cegamente e deveria ser uma decisão consciente no discuss-phase da Fase 9.

## Fluxo de dados: da criação da empresa até a liberação operacional

```
1. Company criada + ContratoServico persistido
   (transaction A — já existe hoje, não muda)
   ONDE PODE FALHAR HOJE: já tratado (try/catch no processar(), rollback da transaction)

2. PendenciasComerciaisService::calcular($company)  [NOVO]
   ONDE PODE FALHAR: leitura pura de relações já carregadas — falha aqui é bug de
   código, não falha externa. Deve rodar DENTRO da transaction A (barato, síncrono).

3a. COM pendência → ContratoAssinatura criado em status aguardando_comercial [NOVO]
    (fim de linha até o Comercial resolver manualmente)

3b. SEM pendência → ContratoClicksignService::iniciarParaEmpresa($company) [NOVO]
    ONDE PODE FALHAR: chamada HTTP externa (Clicksign). NÃO deve estar dentro da
    transaction A — se a Clicksign demorar ou timeout, não pode segurar um lock de
    linha da tabela `companies`/`contratos_servico` pelo tempo da chamada HTTP. Padrão
    recomendado: transaction A commita normalmente (empresa + contrato + registro
    ContratoAssinatura em 'pronto_para_contrato'); a chamada à Clicksign acontece
    DEPOIS do commit, dentro de um bloco try/catch que, em caso de falha, marca
    ContratoAssinatura como status='erro' + error_message, SEM re-lançar a exceção pro
    controller (mesmo padrão de "erro não derruba o response" já usado em
    notificarComercialSePendente(), linhas 1007-1043, que envolve o disparo em
    try/catch(\Throwable) fora da transaction por motivo idêntico).
    ALTERNATIVA MAIS SEGURA (recomendada para o rollout inicial): despachar via QUEUE
    JOB em vez de chamada síncrona no fim do controller — isola completamente a
    latência/instabilidade da Clicksign do tempo de resposta do webhook HubSpot e do
    form do Comercial, e dá retry automático de graça (Laravel Queue já é a infra
    padrão do projeto, ver AnalyzeCompanySugadoresJob/SyncAdmanCompanyJob).

4. Clicksign processa (envelope, documento, signatários) — fora do sistema
   ONDE PODE FALHAR: qualquer chamada da sequência (criarEnvelope → adicionarDocumento
   → adicionarSignatario → adicionarRequisito → enviarNotificacao) pode falhar
   isoladamente. Não é uma operação atômica do lado da Clicksign — o
   ContratoClicksignService precisa decidir o que fazer com um envelope
   parcialmente montado (ex.: documento criado mas signatário falhou). Ponto cego
   do plano: não há menção a cancelamento/rollback do lado Clicksign quando uma
   etapa intermediária falha — a Fase 6 do plano (linhas 355-385) deveria incluir
   isso explicitamente, ou pelo menos deixar claro que o estado 'erro' pode conviver
   com um envelope órfão do lado da Clicksign que precisa ser cancelado manualmente.

5. Empresa aguarda assinatura (dias) — estado persistido em ContratoAssinatura,
   status=enviado/aguardando_assinaturas
   ONDE PODE FALHAR: nada do lado do sistema — é espera passiva. O RISCO aqui é
   silencioso: se o webhook de assinatura nunca chegar (rede, bug na Clicksign,
   configuração errada de URL), a empresa fica presa PARA SEMPRE sem alarme. Este é
   exatamente o motivo do requisito de "alerta de contrato preso além de N dias" da
   milestone — precisa de um comando agendado (`schedule:run`, padrão já usado no
   projeto) que varre ContratoAssinatura em status intermediário há mais de N dias
   e notifica.

6. Webhook Clicksign chega → ClicksignWebhookController [NOVO]
   ONDE PODE FALHAR: validação HMAC, parse, lookup de ContratoAssinatura por
   clicksign_envelope_id/document_id. Segue o padrão da seção anterior: falha de
   validação grava evento + 401; falha de lookup (envelope não encontrado) precisa de
   uma decisão — gravar como erro E continuar retornando 200 (mesmo padrão do
   HubSpot) para não travar a fila de retry da Clicksign com um caso que só um humano
   resolve.

7. Todos os signatários obrigatórios assinam → status=assinado, signed_at preenchido
   ONDE PODE FALHAR: escrita no banco — padrão, sem risco novo.

8. EmpresaOperacionalRouter::liberarEmpresa($company)
   ONDE PODE FALHAR: guard de idempotência já cobre reentrega; falha real aqui
   (exception dentro da criação de MlbEmpresa/MlbImplementacao) precisa deixar
   ContratoAssinatura em um estado que NÃO seja 'liberado_operacional' — senão a
   tela administrativa mostra "liberado" mas o operacional não tem a empresa. Marcar
   released_to_operational_at SÓ DEPOIS de liberarEmpresa() retornar sem exceção,
   nunca antes (evita o falso-positivo mais perigoso do fluxo inteiro).

9. Activity log do evento de liberação (padrão já usado em todo o sistema,
   spatie/laravel-activitylog) — sem risco novo.
```

**Achado adicional relevante para a Fase 4/5 (client Clicksign):** o service
`ContratoClicksignService::iniciarParaEmpresa()` (linha 375 do plano) precisa ser
idempotente ELE MESMO, independente do webhook — o plano já registra isso corretamente
na regra "Não gerar novo contrato se já existir contrato em status enviado,
aguardando_assinaturas, assinado ou liberado_operacional" (linhas 383-384), mas essa
regra vive fora da tabela `contrato_assinatura_eventos` (que só cobre idempotência de
webhook). É um TERCEIRO guard de idempotência, na camada de "iniciar processo", análogo
em espírito aos dois guards do HubSpot mas em um ponto diferente do pipeline (evitar
disparo duplo de `iniciarParaEmpresa`, não evitar reprocessar webhook).

## Ordem de construção sugerida — "observa mas não bloqueia" antes do bloqueio

O requisito central da milestone (`.planning/PROJECT.md` linha 33) é que a ordem de
entrega permita produção em modo observação ANTES do bloqueio. Isso não é só "ligar uma
flag por último" — é uma restrição que afeta a ORDEM DAS FASES, porque o corte nos dois
controllers (seção 1) e a extração dos services (seção 2) já são, por si só, o
componente de maior risco (mexer no caminho que teve 3 bugs corrigidos em 05-06/08,
ver `.planning/PROJECT.md` linha 34) — e não podem ficar pendurados sem uso em produção
por muitas fases, sob pena de divergir do código real por refactors paralelos (outro
dev na mesma branch, deploys frequentes).

**Ordem recomendada** (não numerada como fases do roadmap — é a ordem de DEPENDÊNCIA
técnica que o roadmapper deve respeitar ao sequenciar):

1. **Extração pura dos services, SEM mudar comportamento.**
   `PendenciasComerciaisService` e `EmpresaOperacionalRouter` nascem como wrappers que
   os dois controllers passam a chamar, mas o resultado final observável é IDÊNTICO ao
   atual (liberação ainda acontece na hora, dentro da transaction). Isto isola o risco
   de regressão do refactor mecânico do risco de mudança de comportamento — dá pra
   rodar a suíte de testes e confirmar zero diferença antes de tocar em fluxo novo.
   Esta fase, sozinha, já reduz a duplicação (requisito do plano) e pode ir a produção
   sem o menor risco de bloquear operação, porque comportamentalmente nada mudou.

2. **Kill switch instalado ANTES de qualquer bloqueio existir.**
   Usar o padrão `Configuracao::get/set` (já existe, `app/Models/Configuracao.php`,
   chave/valor, mesmo mecanismo do `nps_dia_cobranca`) com uma chave como
   `administrativo_bloqueio_ativo` (bool, default `false`/observação). Todo o código
   que decide "bloquear ou não" já nasce condicionado a essa chave, e a chave nasce
   DESLIGADA. Isso significa que os passos 3 e 4 abaixo podem ir a produção com o
   bloqueio construído mas INERTE — validando o caminho feliz (Clicksign gerando
   envelope, webhook processando) em paralelo com o fluxo antigo continuando a
   liberar tudo direto, sem esperar assinatura.

3. **Estrutura de dados + client Clicksign + webhook, rodando em modo observação.**
   Fases 2-9 do plano (tabelas, models, client, service, PDF, gatilhos, webhook)
   entram em produção com o corte da seção 1 já presente mas SEM EFEITO — porque a
   chamada a `EmpresaOperacionalRouter::liberarEmpresa()` continua acontecendo
   imediatamente após `persistirContratos()` quando `administrativo_bloqueio_ativo`
   está `false`, IGUAL a hoje. A geração de contrato e o fluxo Clicksign rodam em
   paralelo, alimentando `contrato_assinaturas`, mas sem gatilhar nem impedir nada do
   lado operacional. Isso valida o pipeline inteiro (envelope, PDF, webhook,
   idempotência) contra dados reais de produção sem risco de parar a operação — o pior
   caso de um bug aqui é um `ContratoAssinatura` com status errado, não uma empresa
   presa fora do operacional.

4. **Tela administrativa + alerta de contrato preso.**
   Construídos e validados ainda com o kill switch desligado — o admin já pode ver o
   estado real dos contratos, o alerta de N dias já pode disparar (calibrando o valor
   de N com dados reais de quanto tempo assinatura leva de verdade, informação que só
   existe depois do passo 3 rodar um tempo em produção).

5. **Liga o bloqueio.**
   Só depois dos passos 3-4 terem rodado em produção por tempo suficiente para
   confirmar que: (a) o webhook Clicksign chega de forma confiável, (b) o alerta de
   contrato preso funciona, (c) o botão de liberação manual (seção 6) foi testado
   manualmente pelo menos uma vez em produção. Vira o interruptor
   (`administrativo_bloqueio_ativo = true`) — dali em diante, o corte da seção 1
   deixa de chamar `liberarEmpresa()` direto e passa a esperar
   `iniciarParaEmpresa()` + webhook/manual.

**Por que essa ordem e não a do plano (que trata o kill switch como item da Fase 1,
mas não formaliza ELE MESMO como gate de rollout entre as fases seguintes):** o plano
já lista a rede de segurança como requisito (`plano-administrativo-clicksign.md` não
tem essa seção explicitamente — ela é um requisito que entrou DEPOIS do plano
canônico, registrado em `.planning/PROJECT.md` linha 29 como "não estava no plano
original"). Isso significa que o roadmapper precisa tratar o kill switch como
INFRAESTRUTURA da Fase 1 (não uma fase separada e tardia), porque cada fase seguinte
depende dele existir para poder ir a produção com segurança.

## Onde encaixar a liberação manual pelo admin (override)

**Não deve existir lógica de liberação duplicada.** O botão manual na tela
administrativa (`ContratoAssinaturaController`, ainda não criado) deve chamar
exatamente `EmpresaOperacionalRouter::liberarEmpresa($company)` — o MESMO método
chamado pelo webhook Clicksign. A diferença entre os dois caminhos é só QUEM decide
disparar e o registro de auditoria em volta da chamada, não a ação em si.

O padrão de auditoria já existe no projeto e deve ser copiado, não reinventado:
`BonusAuditoriaController::toggle()` (linhas 178-209) grava `BonusInvalidacao` com
`invalidated_by` (o `User` que agiu) e `motivo` (texto livre, nullable) — exatamente
o par de campos que a milestone pede para a liberação manual
(`.planning/PROJECT.md` linha 29: "com registro de quem liberou e por quê"). O plano já
prevê os campos certos na tabela `contrato_assinaturas` (`released_to_operational_at`,
mas falta `released_by` e `release_reason`/`override_motivo` explícitos — vale
adicionar esses dois campos na migration da Fase 2, hoje ausentes do desenho de
schema do plano nas linhas 172-198).

Fluxo recomendado para o botão manual:
1. Admin abre `ContratoAssinatura` em qualquer status que não seja
   `liberado_operacional`.
2. Preenche motivo (campo obrigatório — diferente do `BonusInvalidacao`, que tem
   motivo `nullable`; aqui faz sentido ser obrigatório porque é uma exceção ao fluxo
   de segurança da milestone, não uma correção de dado).
3. Controller chama `EmpresaOperacionalRouter::liberarEmpresa($company)` diretamente —
   MESMO service, sem passar por `ContratoClicksignService` nem por validação de
   assinatura completa.
4. Grava em `ContratoAssinatura`: `status='liberado_operacional'`,
   `released_to_operational_at=now()`, `released_by=$user->id`,
   `release_reason=$motivo`.
5. Activity log (`spatie/laravel-activitylog`, padrão do projeto) registra o evento
   com o mesmo nível de detalhe de qualquer outra ação administrativa sensível.

Isso significa que `EmpresaOperacionalRouter::liberarEmpresa()` PRECISA continuar
idempotente mesmo quando chamado fora do fluxo Clicksign — o guard
`MlbEmpresa::where('company_id')->exists()` (herdado do código atual, seção 2) é o
que garante que um admin clicando "liberar manualmente" numa empresa que JÁ foi
liberada pelo webhook (corrida entre os dois caminhos) não duplica nada.

## Componentes novos vs. modificados

### Novos

| Componente | Tipo | Observação |
|---|---|---|
| `App\Services\Comercial\PendenciasComerciaisService` | Service | Extrai `ComercialController::calcularPendenciasComerciais()` |
| `App\Services\Operacional\EmpresaOperacionalRouter` | Service | Extrai `rotearImplementacao()` dos dois controllers |
| `App\Services\Clicksign\ClicksignClient` | Service | Client HTTP puro, sem lógica de negócio |
| `App\Services\Administrativo\ContratoClicksignService` | Service | Orquestra client + PDF + persistência de estado |
| `App\Services\Administrativo\ContratoPdfService` | Service | Opcional (plano marca como "opcional") |
| `App\Models\ContratoAssinatura` | Model | + relação em `Company` (`contratoAssinaturas`/`contratoAssinaturaAtual`) |
| `App\Models\ContratoAssinaturaSignatario` | Model | |
| `App\Models\ContratoAssinaturaEvento` | Model | idempotência via `payload_hash` único |
| `App\Http\Controllers\Api\ClicksignWebhookController` | Controller | Espelha `HubspotWebhookController` estruturalmente |
| `App\Http\Controllers\Administrativo\ContratoAssinaturaController` | Controller | CRUD + botão liberar manual |
| `resources/js/Pages/Administrativo/Contratos.jsx` | Página React | |
| `resources/views/pdf/contrato-prestacao-servico.blade.php` | View Blade (DomPDF) | CSS inline, sem Tailwind |
| Migrations: `contrato_assinaturas`, `contrato_assinatura_signatarios`, `contrato_assinatura_eventos` | Migration | Ver nota sobre `released_by`/`release_reason` faltantes no desenho atual |
| Comando agendado de alerta ("contrato preso além de N dias") | Artisan Command | Registrado em `routes/console.php` (padrão do projeto, Laravel 11+ style — não `Kernel.php`) |
| Chave `administrativo_bloqueio_ativo` (ou nome equivalente) | Config runtime | Via `Configuracao::get/set`, não `.env` — precisa mudar sem deploy |

### Modificados

| Componente | Mudança | Risco |
|---|---|---|
| `app/Http/Controllers/Api/HubspotWebhookController.php` | Remove `rotearImplementacao()` (move pro Router); corta chamada direta em `criarEmpresa()` (linhas 649-651) por chamada condicionada ao kill switch | ALTO — caminho recém-mexido (3 bugs em 05-06/08) |
| `app/Http/Controllers/ComercialController.php` | Remove bloco inline de roteamento (linhas 683-715); corta `store()` de forma equivalente; mantém `servicoDisparaImplementacao()` como está (já é estático e reusado) | ALTO — mesmo motivo |
| `app/Models/Company.php` | + relações `contratoAssinaturas()`, `contratoAssinaturaAtual()` | BAIXO |
| `Permissions.php` / `Modules.php` | + `admin.contratos` / módulo `administrativo.contratos` | BAIXO — padrão mecânico já usado dezenas de vezes |
| `resources/js/Pages/Comercial/EmpresasListagem.jsx` | + badge de status administrativo, botão "Gerar contrato" | BAIXO — aditivo |
| `routes/web.php` | + grupo `administrativo/contratos` (autenticado) + rota pública `/api/webhooks/clicksign` | BAIXO — mecânico |
| `config/services.php` | + bloco `clicksign` | BAIXO |
| `.env.example` | + 4 chaves Clicksign | BAIXO |

### Explicitamente NÃO modificado (verificado no código, não no plano)

- `bootstrap/app.php` — o wildcard `api/webhooks/*` (linha 23) já cobre a isenção de
  CSRF para a rota nova; não precisa de entrada específica.
- `ComercialController::servicoDisparaImplementacao()` — helper estático puro, fonte
  única da regra Polos/Assessoria/Incubadora; continua sendo chamado de dentro do
  `EmpresaOperacionalRouter` exatamente como é hoje chamado pelos dois controllers.

## Riscos arquiteturais que o plano não cobre explicitamente

1. **Ausência de rollback do lado Clicksign** quando uma etapa intermediária de
   montagem do envelope falha (ver seção "Fluxo de dados", passo 4).
2. **Diferença de assinatura entre `rotearImplementacao($company, $nomeServico)` (por
   serviço, loop) e `liberarEmpresa($company)` (por empresa, sem loop)** — exige
   decisão explícita sobre re-derivar serviços no momento da liberação em vez de
   receber a lista do momento da criação (seção "Extração dos services").
3. **Perda silenciosa de `gmail_colaborador`** ao unificar o caminho Comercial
   (que passa `$handoff`) com o caminho HubSpot (que não passa) dentro do mesmo
   `liberarEmpresa()`, se a assinatura não acomodar contexto extra opcional.
4. **`PendenciasComerciaisService` como GATE (bloqueia Clicksign) vs. como fonte da
   UI de badges** — mesmo cálculo, mas a regra de "não calcular pendência para
   empresa não-HubSpot" (linha 469-471 do código atual) precisa ser revisitada: se
   empresas cadastradas manualmente pelo Comercial nunca tiverem pendência
   comercial calculada, elas pulam o gate inteiro e vão direto para Clicksign sem
   checagem — o que pode ser a intenção (Comercial já validou manualmente ao
   cadastrar) ou pode ser um buraco não percebido. Decisão de negócio pendente,
   não uma questão técnica.
5. **`released_by` / `release_reason`** ausentes do desenho de schema do plano
   (`plano-administrativo-clicksign.md` linhas 172-198) apesar de serem exigidos
   pela redação do requisito de rede de segurança em `.planning/PROJECT.md`.

## Fontes

- `app/Http/Controllers/Api/HubspotWebhookController.php` (leitura completa, 1069 linhas)
- `app/Http/Controllers/ComercialController.php` (leitura completa, 982 linhas)
- `app/Support/Permissions.php`, `app/Support/Modules.php` (leitura completa)
- `routes/web.php` (leitura completa, 1032 linhas)
- `app/Models/MlbEmpresa.php`, `app/Models/Configuracao.php` (trechos relevantes)
- `app/Http/Controllers/BonusAuditoriaController.php` (leitura completa — precedente
  de override manual auditado)
- `database/migrations/2026_06_12_300002_create_hubspot_eventos_table.php`
- `bootstrap/app.php` (isenção CSRF)
- `plano-administrativo-clicksign.md` (plano canônico, raiz do repo)
- `.planning/PROJECT.md` (contexto da milestone v22.0)

---
*Pesquisa de arquitetura para: integração da etapa administrativa de contrato (v22.0)*
*Pesquisado em: 2026-08-07*
