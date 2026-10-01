---
quick_id: 261001-nkx
type: execute
wave: 1
depends_on: []
autonomous: true
files_modified:
  - .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md
  - config/services.php
  - .env.example
  - app/Services/Creative/Contracts/ImageGenerationProvider.php
  - app/Services/Creative/Dto/CreativeGenerationRequest.php
  - app/Services/Creative/Dto/CreativeGenerationResult.php
  - app/Services/Creative/FalhaDeGeracaoTrocavel.php
  - app/Services/Creative/GeminiImageProvider.php
  - app/Console/Commands/CreativeTestGemini.php
  - tests/Unit/GeminiImageProviderTest.php

must_haves:
  truths:
    - "As notas tecnicas da investigacao do publicador existem como artefato versionado, em pt-BR, com caminhos e numeros de linha."
    - "A chave da Gemini e lida de .env via config, nunca hardcoded, e o .env.example documenta as chaves novas."
    - "Existe uma interface de provedor de imagem e uma implementacao Gemini que aceita imagens de referencia e devolve o binario gerado + metadados."
    - "`php artisan creative:test-gemini` faz uma chamada real de texto (e opcionalmente de imagem) e reporta modelo/latencia/status sem expor a chave."
    - "A suite automatizada cobre sucesso, chave ausente, erro trocavel, erro definitivo e 200-sem-imagem, sem chamar a API real."
  artifacts:
    - path: ".planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md"
      provides: "Registro da investigacao obrigatoria do §1.1 do plano canonico"
      contains: "drive_imagens"
    - path: "config/services.php"
      provides: "Bloco 'creative' centralizando provedor/modelos/timeouts"
      contains: "'creative'"
    - path: "app/Services/Creative/Contracts/ImageGenerationProvider.php"
      provides: "Contrato de provedor de geracao de imagem"
      contains: "interface ImageGenerationProvider"
    - path: "app/Services/Creative/GeminiImageProvider.php"
      provides: "Implementacao Gemini (Interactions API)"
      contains: "x-goog-api-key"
    - path: "app/Console/Commands/CreativeTestGemini.php"
      provides: "Prova tecnica manual de conectividade e geracao"
      contains: "creative:test-gemini"
    - path: "tests/Unit/GeminiImageProviderTest.php"
      provides: "Cobertura do provider com Http::fake()"
      contains: "Http::fake"
  key_links:
    - from: "app/Services/Creative/GeminiImageProvider.php"
      to: "config/services.php (bloco creative)"
      via: "config('services.creative')"
      pattern: "config\\('services\\.creative"
    - from: "app/Console/Commands/CreativeTestGemini.php"
      to: "app/Services/Creative/Contracts/ImageGenerationProvider.php"
      via: "injecao do contrato no handle()"
      pattern: "ImageGenerationProvider"
---

<objective>
Entregar a **V0.1 do Creative Engine** (§20 do plano canonico `plano-incubadora-v1`): o registro da
investigacao obrigatoria do §1.1 e a **prova tecnica isolada** de geracao de imagem por IA com a
Gemini — config centralizada, provider atras de interface, comando de teste manual e cobertura
automatizada. Nada toca o fluxo do publicador.

Proposito: responder "a Gemini consegue gerar um criativo fiel a partir da foto real do produto?"
com codigo de verdade, **antes** de existir tabela, job, rota ou UI. Se a resposta for nao, o custo
jogado fora e este spike, nao a arquitetura inteira.

Saida: um artefato de notas tecnicas em pt-BR + uma camada `App\Services\Creative` com 1 interface,
1 implementacao, 2 DTOs, 1 comando artisan e 1 teste unitario.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@CLAUDE.md

**Molde obrigatorio do cliente HTTP de IA** (ler o docblock de `chamar()`/`chamarModelo()` e o
metodo `mensagemAmigavel()` — as licoes foram pagas em producao):
@app/Services/Ia/AnaliseAnuncioService.php
@app/Services/Ia/FalhaTrocavel.php

**Molde da config** — bloco `'llm'` em `config/services.php` (linhas ~340-380) e o bloco LLM do
`.env.example` (linhas ~278-287). Copiar o ESTILO: `env()` com default real, comentario em pt-BR
explicando o PORQUE de cada escolha, chave sem default.

**Molde de teste com `Http::fake()`** (atencao ao aviso sobre acumulo de stubs no docblock):
@tests/Feature/AnuncioIaAnaliseTest.php

**Plano canonico do usuario** (ler SO §1.1, §6, §8.6, §9.1, §13.3, §17 e §20/V0.1 — o resto e
escopo futuro):
@plano-incubadora-v1

<interfaces>
<!-- Fatos ja verificados contra o codigo e contra a doc oficial. NAO re-investigar. -->

Config atual de IA (texto-only, OpenAI-compatible, hoje na NVIDIA):
config('services.llm') => ['base_url', 'key', 'model', 'fallbacks', 'timeout', 'max_tokens']

API Gemini — Interactions API (conferido na doc oficial em 2026-10-01):
  POST https://generativelanguage.googleapis.com/v1beta/interactions
  Header de auth: x-goog-api-key: <chave>      (NAO Bearer, NAO query param)
  Chamada SINCRONA — nao devolve job para polling.

  Body de IMAGEM com referencias inline (ate 14 imagens, conforme o modelo):
    model: "gemini-3.1-flash-image"
    input: [ {type:"text", text:"<prompt>"},
             {type:"image", mime_type:"image/jpeg", data:"<BASE64>"} ]
    response_format: {type:"image", mime_type:"image/jpeg", aspect_ratio:"1:1", image_size:"2K"}
  Resposta de IMAGEM: base64 em `interaction.output_image.data`

  Body de TEXTO:
    model: "gemini-3.8-flash"
    input: "<prompt>"
  Resposta de TEXTO: `interaction.outputText`

  image_size: "512px" | "1K" | "2K" | "4K"   |   aspect_ratio: "1:1" | "16:9" | "3:2" | "4:5" ...
  Modelos de imagem validos: gemini-3.1-flash-lite-image, gemini-3.1-flash-image (Nano Banana 2),
    gemini-3-pro-image (Nano Banana Pro), gemini-2.5-flash-image (legado).
  Imagen 4 (imagen-4.0-*) esta DEPRECADO, shutdown 17/08/2026 — nao usar.
  CORRECAO AO PLANO CANONICO §6.2: `gemini-3.1-flash` NAO EXISTE. O flash estavel de texto e
    `gemini-3.8-flash`.
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Tarefa 1: Registrar as notas tecnicas da investigacao do publicador</name>
  <files>.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md</files>
  <action>
Criar o artefato de notas tecnicas em **pt-BR**, cumprindo o item 9 do §1.1 do plano canonico
("registre as descobertas em um pequeno bloco de notas tecnico antes de codar"). A investigacao JA
FOI FEITA contra o codigo real — esta tarefa e de REGISTRO, nao de re-investigacao. Nao abrir os
arquivos citados para "conferir"; os achados abaixo sao verificados.

Estrutura obrigatoria do documento:

**Secao "1. O que ja existe de IA no publicador"** — a camada de IA madura e em producao:
`app/Services/Ia/AnaliseAnuncioService.php` (512 linhas) roda a metodologia MAG T8 em 5 etapas
(analise estrategica, titulos, descricao, ficha tecnica, grava rascunho), orquestrada por
`app/Jobs/GerarAnaliseAnuncioIaJob.php` na fila `high` — **nunca** a `default`: medido em producao
em 21/09/2026 a `default` tinha 170 jobs represados e a analise ficou 395s sem worker. O front faz
polling em `resources/js/Pages/Mlb/components/PainelAnunciarIa.jsx`. Consequencia que importa:
a "Parte 1" do PDF (§4.1 do plano canonico) **ja esta construida** — o Creative Engine e a Parte 2
(roteiro de imagens), nao um recomeco.

**Secao "2. Provedor de IA atual e por que ele nao serve para imagem"** — `config('services.llm')`
expoe base_url/key/model/fallbacks/timeout/max_tokens, hoje apontando para a NVIDIA (tier gratis) e
**texto-only**. Fallback por modelo via `app/Services/Ia/FalhaTrocavel.php`: sobrecarga, timeout ou
modelo fora = trocar de modelo, **nunca** retentar o mesmo. O cliente HTTP nao se reaproveita para
a Gemini (auth e payload diferentes), mas as licoes dele sao obrigatorias no provider novo.

**Secao "3. Tabelas e convencao de nomes"** — `ml_anuncio_rascunhos` (payload json, status enum
rascunho/validado/publicando/publicado/erro) e `ml_anuncio_ia_analises` (resultado json,
status/etapa/tentativas, `LIMITE_MINUTOS=15` como trava anti-loop-infinito). Convencao real do
modulo: prefixo `ml_`, colunas em snake_case pt-BR.

**Secao "4. Como a imagem chega ao ML hoje (e onde ela NAO esta)"** — fotos **nao** sao guardadas
localmente: com variacoes o wizard sobe cada arquivo DIRETO para o ML via
`App\Services\Mlb\Publicacao\MlImagemService::enviar()` (POST /pictures/items/upload, devolve
`picture_id` + `url`) e guarda so os ids; item simples nao tem upload nenhum — o operador COLA uma
URL (`resources/js/Pages/Mlb/AnunciarML.jsx:2440`, com a dica literal "Upload direto sera
adicionado numa proxima versao").

**Secao "5. Onde vive a foto bruta do cliente"** — num link de Google Drive guardado como TEXTO:
item `drive_imagens` do onboarding (`App\Models\MlbImplementacao:354`, tipo `link_admin`, gravado em
`dados.links_admin.drive_imagens`). A tela so mostra um botao para o humano clicar
(`resources/js/Pages/.../ImplementacaoPublicador.jsx:607`). **Nao existe acesso programatico**: o
OAuth do projeto e so Calendar (`calendar.readonly` + `calendar.events`) e o ECF Drive
(`files.ecfconsultoria.com.br`) serve CSV/JSON de dados, nao fotos.

**Secao "6. Forma do wizard"** — 5 etapas, e a ULTIMA ja e "Imagem e frete"
(`AnunciarML.jsx:22-28`); o arquivo tem 2857 linhas. O padrao do modulo para coisa assincrona e
componente separado com polling (`PainelAnunciarIa`, 469 linhas), nunca codigo inline.

**Secao "7. Gate de acesso"** — `routes/mlb_anuncios.php`, grupo `['auth','verified','role:admin']`,
prefix `mlb/anuncios`, name `mlb.anuncios.`. Existe tambem `routes/incubadora_publicador.php` com
gate por modulo, mas o usuario decidiu NAO usar esse caminho.

**Secao "8. Credenciais"** — nao existe `GEMINI_API_KEY` no `.env` nem no `.env.example`. So
existem GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI (Calendar). Chave nova e exclusiva do Creative Engine.

**Secao "9. Fatos da API Gemini conferidos em 2026-10-01"** — reproduzir o bloco `<interfaces>`
deste plano: endpoint unico `POST /v1beta/interactions`, auth por header `x-goog-api-key`, body de
imagem com referencias inline em base64, resposta em `interaction.output_image.data`, body/resposta
de texto, valores aceitos de `image_size`/`aspect_ratio`, lista de modelos de imagem validos.
Registrar que para o Mercado Livre o certo e `aspect_ratio: "1:1"` (o §13.3 pede 1200x1200; "2K" em
1:1 atende com folga o minimo de 500px do ML). Registrar que Imagen 4 esta deprecado (shutdown
17/08/2026).

**Secao "10. Correcao ao plano canonico"** — o `gemini-3.1-flash` que o §6.2 propoe para texto e
validacao **nao existe**; o flash estavel atual de texto e `gemini-3.8-flash`, e e este o default
adotado. Registrar tambem que o default de imagem do spike e `gemini-3.1-flash-image` (barato e
rapido para iterar na prova de fidelidade), com `gemini-3-pro-image` alcancavel por env quando a
fidelidade exigir — nada hardcoded.

**Secao "11. Decisoes travadas pelo usuario"** — as tres, marcadas como LOCKED:
(D-01) o Creative Engine mora DENTRO de `/mlb/anuncios` sob `role:admin`, nao no modulo Incubadora;
(D-02) origem da imagem de referencia — a foto original fica no Google Drive do cliente, e na hora
de publicar o operador faz UPLOAD dela no sistema; o sistema **nao guarda** essa imagem de forma
definitiva, ela existe apenas como BASE para o Gemini. Ou seja: upload EFEMERO/temporario (disco
privado, retencao curta), nunca acervo permanente, e **sem** integracao programatica com o Google
Drive. Isto direciona o V0.3; construir o staging de upload esta fora deste spike;
(D-03) modo de renderizacao inicial e FULL_AI (§9.1), com a arquitetura preparada para COMPOSITE no
futuro — mas COMPOSITE **nao** se constroi agora.

**Secao "12. O que o plano canonico assume e nao confere com o codigo"** — curta, tres itens:
(a) o §13.3 modela `reference_images` como lista de *paths* em storage, mas hoje nao existe acervo
de foto em storage nenhum (ver secoes 4 e 5) — por isso a decisao D-02 do usuario, e por isso o
provider desta entrega recebe **bytes**, nao caminho;
(b) o §14 fala de "UI da etapa final" como se a ultima etapa estivesse livre, mas a 5a etapa do
wizard ja e "Imagem e frete" — integrar vai exigir conviver com ela, nao ocupar o lugar dela;
(c) o §12.1 fala de paralelismo (`GEMINI_MAX_PARALLEL_JOBS`) sem mencionar fila, e no projeto a fila
tem nome: trabalho interativo vai para a `high`; a `default` congestiona (ver secao 1).

Fechar com uma secao curta **"Escopo deste spike"**: o que entra (notas, config, provider, comando,
teste) e o que explicitamente fica fora (tabelas `creative_projects`/`creative_assets`, migrations,
jobs, rotas, controllers, qualquer JSX, o staging de upload, CreativeContextBuilder /
ProductTruthBuilder / CreativePlanner / CreativeValidator, integracao com o wizard).
  </action>
  <verify>
    <automated>test -f .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md &amp;&amp; grep -c "drive_imagens" .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md &amp;&amp; grep -c "gemini-3.8-flash" .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md &amp;&amp; grep -c "x-goog-api-key" .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md</automated>
  </verify>
  <done>
Arquivo existe, em pt-BR, com as 12 secoes + "Escopo deste spike". Cada achado de codigo cita
caminho de arquivo (e numero de linha onde o achado o tem). A correcao do nome do modelo de texto e
as 3 decisoes travadas estao registradas explicitamente como LOCKED.
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 2: Config centralizada + interface do provedor + GeminiImageProvider</name>
  <files>config/services.php, .env.example, app/Services/Creative/Contracts/ImageGenerationProvider.php, app/Services/Creative/Dto/CreativeGenerationRequest.php, app/Services/Creative/Dto/CreativeGenerationResult.php, app/Services/Creative/FalhaDeGeracaoTrocavel.php, app/Services/Creative/GeminiImageProvider.php</files>
  <behavior>
    - Chave ausente em `services.creative.gemini.key` -> `RuntimeException` com mensagem em pt-BR citando `GEMINI_API_KEY`, sem nenhuma chamada HTTP.
    - Chamada de imagem bem-sucedida -> `CreativeGenerationResult` com os BYTES decodificados (nao o base64), `mime`, `modelo` (o que respondeu), `latencia_ms` > 0 e `status` de sucesso.
    - HTTP 503/429/500/502/504/408 -> `FalhaDeGeracaoTrocavel` (outro modelo pode resolver).
    - HTTP 401/403/400 -> `RuntimeException` (trocar de modelo nao resolve), com mensagem amigavel em pt-BR.
    - HTTP 200 sem `interaction.output_image.data` -> `FalhaDeGeracaoTrocavel` com mensagem explicando que o modelo respondeu sem imagem.
    - Chamada de texto bem-sucedida -> devolve a string de `interaction.outputText`.
    - O base64 (entrada ou saida) NUNCA aparece em log.
  </behavior>
  <action>
**(a) Bloco `'creative'` em `config/services.php`**, logo depois do bloco `'llm'`, seguindo o mesmo
estilo: cabecalho `/* |--- ... */` em pt-BR explicando o PORQUE de cada escolha, `env()` com default
real, chave **sem default**. Campos:

- `enabled` <- `CREATIVE_ENGINE_ENABLED`, default `false` (spike: nasce desligado de proposito).
- `provider` <- `CREATIVE_IMAGE_PROVIDER`, default `gemini`.
- `render_mode` <- `CREATIVE_RENDER_MODE`, default `full_ai` (per D-03; COMPOSITE e futuro).
- `gemini.base_url` <- `GEMINI_BASE_URL`, default `https://generativelanguage.googleapis.com/v1beta`.
- `gemini.key` <- `GEMINI_API_KEY` (sem default).
- `gemini.text_model` <- `GEMINI_TEXT_MODEL`, default `gemini-3.8-flash`. Comentar que o
  `gemini-3.1-flash` do planejamento **nao existe** — conferido na doc oficial em 2026-10-01.
- `gemini.image_model` <- `GEMINI_IMAGE_MODEL`, default `gemini-3.1-flash-image` (Nano Banana 2:
  barato e rapido para iterar a prova de fidelidade). Comentar que `gemini-3-pro-image` e o degrau
  de cima quando a fidelidade exigir, e que Imagen 4 esta deprecado (shutdown 17/08/2026).
- `gemini.image_fallbacks` <- `GEMINI_IMAGE_MODEL_FALLBACK`, default `''`. Lista por virgula, mesma
  semantica do `llm.fallbacks`.
- `gemini.aspect_ratio` <- `GEMINI_ASPECT_RATIO`, default `1:1` (Mercado Livre; §13.3 pede
  1200x1200 e `2K` em 1:1 atende com folga o minimo de 500px do ML).
- `gemini.image_size` <- `GEMINI_IMAGE_SIZE`, default `2K`.
- `gemini.mime` <- `GEMINI_IMAGE_MIME`, default `image/jpeg`.
- `gemini.timeout` <- `GEMINI_TIMEOUT_SECONDS`, default `120`. Comentar que e **por chamada**.
- `gemini.connect_timeout` <- `GEMINI_CONNECT_TIMEOUT`, default `15`. Comentar o porque, copiando a
  licao do `AnaliseAnuncioService`: conectar e rapido ou nao e; separar do tempo de geracao evita
  esperar o timeout inteiro por um endpoint fora do ar.

**(b) `.env.example`** — acrescentar um bloco `# ─── Creative Engine (geracao de imagem por IA) ───`
com todas as chaves acima, comentario em pt-BR no estilo do bloco LLM existente: onde se tira a
chave (Google AI Studio), que a chave fica **so** no `.env`, e que `CREATIVE_ENGINE_ENABLED=false`
e intencional ate a prova de fidelidade passar.

**(c) `app/Services/Creative/Contracts/ImageGenerationProvider.php`** — interface, per §8.6:
`gerarImagem(CreativeGenerationRequest $request): CreativeGenerationResult` e
`gerarTexto(string $prompt): string`. O segundo metodo existe porque o comando de teste do §6.3
precisa de uma chamada de texto e porque a validacao do V0.4 vai usar o mesmo contrato — nao e
escopo-creep, e o minimo que o teste minimo exige. Docblock em pt-BR declarando as excecoes.

**(d) DTOs** — readonly classes com construtor de propriedades promovidas:
- `CreativeGenerationRequest`: `string $prompt`, `array $imagensReferencia` (lista de
  `['mime' => string, 'bytes' => string]` — **bytes crus**, nao path: ver secao 12(a) das notas e a
  decisao D-02, a imagem de referencia e upload efemero), `?string $aspectRatio = null`,
  `?string $imageSize = null`, `array $metadata = []`. Um `static` helper
  `comImagem(string $prompt, string $bytes, string $mime)` para o caso de uma referencia so.
  Validar no construtor que `imagensReferencia` tem no maximo 14 itens (teto do modelo) e lancar
  `InvalidArgumentException` em pt-BR se passar.
- `CreativeGenerationResult`: `string $bytes`, `string $mime`, `string $modelo`, `int $latenciaMs`,
  `string $status`, `array $meta = []`. Metodo `tamanhoBytes(): int`.

**(e) `app/Services/Creative/FalhaDeGeracaoTrocavel.php`** — `extends \RuntimeException`, docblock em
pt-BR copiando a ideia do `FalhaTrocavel`: falha em que OUTRO modelo pode dar certo. Classe propria
em vez de reusar a de `Services\Ia` porque sao camadas distintas e a de la e documentada como
interna ao servico de analise.

**(f) `app/Services/Creative/GeminiImageProvider.php`** — implementa a interface. Obrigatorio reusar
as licoes do `AnaliseAnuncioService`:
- `private const HTTP_TROCA_MODELO = [404, 408, 410, 429, 500, 502, 503, 504];`
- Monta a lista de modelos: `image_model` + `image_fallbacks` (trim, filter, unique), tenta em
  ordem, captura `FalhaDeGeracaoTrocavel` e passa ao proximo; se todos falharem, relanca
  `RuntimeException` com a ultima mensagem. **Nunca** retentar o mesmo modelo.
- `Http::withHeaders(['x-goog-api-key' => $cfg['key']])` — NAO `withToken`, NAO query param.
- `->timeout($cfg['timeout'])->connectTimeout($cfg['connect_timeout'])`.
- `->post(rtrim($base,'/') . '/interactions', [...])` com o body exato do bloco `<interfaces>`:
  `model`, `input` (array com `{type:text}` + um `{type:image, mime_type, data: base64}` por
  referencia) e `response_format` (`{type:image, mime_type, aspect_ratio, image_size}`).
- `catch (ConnectionException)` -> `Log::warning` com tag `[Creative]` + modelo + timeout, depois
  `FalhaDeGeracaoTrocavel` com mensagem em pt-BR.
- `! $resposta->successful()` -> `Log::warning('[Creative] Falha do provedor', ['status','modelo',
  'corpo' => mb_substr($resposta->body(), 0, 400)])`, traduz por um `mensagemAmigavel(int, string)`
  privado (401/403 -> "A chave da Gemini foi recusada. Confira GEMINI_API_KEY."; 429 -> limite de
  uso; 503 -> sobrecarregado; 404 -> modelo nao existe, confira GEMINI_IMAGE_MODEL; 400 -> pedido
  recusado), e lanca `FalhaDeGeracaoTrocavel` se o status esta em `HTTP_TROCA_MODELO`, senao
  `RuntimeException`.
- 200 sem `interaction.output_image.data` -> `FalhaDeGeracaoTrocavel` com mensagem explicando que o
  modelo respondeu sem imagem (pode ter recusado o pedido por politica de conteudo).
- Decodifica com `base64_decode($dado, true)`; `false` -> `FalhaDeGeracaoTrocavel`.
- `gerarTexto()`: mesmo endpoint, body `['model' => text_model, 'input' => $prompt]`, le
  `interaction.outputText`; vazio -> `RuntimeException` em pt-BR.
- **SEGURANCA (§17):** nunca logar a chave, nunca logar o body inteiro do pedido, e o base64 de
  entrada ou saida **jamais** vai para o log — logar so modelo, latencia, status, tamanho em bytes e
  quantidade de referencias. Comentar isso no codigo em pt-BR.
- Comentarios de classe/metodo em pt-BR, no estilo denso do `AnaliseAnuncioService`: explicar o
  PORQUE (header em vez de Bearer, connectTimeout separado, sem retry no mesmo modelo).

Nao registrar binding no container nesta tarefa alem do minimo que o comando precisa — se precisar,
amarrar `ImageGenerationProvider::class` -> `GeminiImageProvider::class` no `AppServiceProvider`
com um `singleton` de uma linha e comentario em pt-BR. Nao criar ServiceProvider novo.
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe -l app/Services/Creative/GeminiImageProvider.php &amp;&amp; C:/xampp/php/php.exe -l config/services.php &amp;&amp; C:/xampp/php/php.exe artisan config:clear &amp;&amp; C:/xampp/php/php.exe -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); \$c=config('services.creative'); if(empty(\$c['gemini']['text_model'])||empty(\$c['gemini']['image_model'])||!array_key_exists('connect_timeout',\$c['gemini'])) exit(1); echo 'ok';" &amp;&amp; grep -v '^#' .env.example | grep -c 'GEMINI_API_KEY' &amp;&amp; grep -c 'x-goog-api-key' app/Services/Creative/GeminiImageProvider.php</automated>
  </verify>
  <done>
`config('services.creative')` resolve com todos os campos; `.env.example` documenta as chaves novas
em pt-BR; a interface, os dois DTOs, a excecao trocavel e o `GeminiImageProvider` existem e passam
no lint; nenhuma chave aparece hardcoded em codigo (`grep -rn "AIza" app config` nao retorna nada).
  </done>
</task>

<task type="auto" tdd="true">
  <name>Tarefa 3: Comando creative:test-gemini + teste do provider com Http::fake()</name>
  <files>app/Console/Commands/CreativeTestGemini.php, tests/Unit/GeminiImageProviderTest.php</files>
  <behavior>
    - `GeminiImageProviderTest::test_gera_imagem_com_sucesso` — `Http::fake()` devolve 200 com base64 valido em `interaction.output_image.data`; o result traz os bytes decodificados, mime, modelo e latencia_ms >= 0.
    - `test_chave_ausente_falha_sem_chamar_a_api` — `services.creative.gemini.key` vazia; espera `RuntimeException` citando `GEMINI_API_KEY`; `Http::assertNothingSent()`.
    - `test_erro_trocavel_tenta_o_proximo_modelo` — fake devolve 503 e, na 2a chamada, 200; com `image_fallbacks` configurado o result vem do modelo reserva; `Http::assertSentCount(2)`.
    - `test_erro_definitivo_nao_troca_de_modelo` — fake devolve 401; espera `RuntimeException` (nao `FalhaDeGeracaoTrocavel`) e `Http::assertSentCount(1)` mesmo com fallback configurado.
    - `test_200_sem_imagem_e_tratado` — fake devolve 200 com `interaction` sem `output_image`; espera falha com mensagem em pt-BR.
    - `test_payload_manda_auth_por_header_e_referencia_em_base64` — inspeciona o pedido capturado: header `x-goog-api-key` presente, sem `Authorization`, e `input` contem um item `type: image` com o base64 da referencia.
  </behavior>
  <action>
**(a) `app/Console/Commands/CreativeTestGemini.php`** — comando `creative:test-gemini`, per §6.3.
Assinatura: `creative:test-gemini {--imagem= : Caminho de uma foto local para usar como referencia}
{--prompt= : Prompt alternativo} {--saida= : Onde gravar a imagem gerada}`. Recebe
`ImageGenerationProvider` por injecao no `handle()` (controller/comando nao fala HTTP cru — §8.6
regra 1 e 2). Fluxo:

1. Conferir `config('services.creative.gemini.key')`. Vazia -> `$this->error()` em pt-BR dizendo
   para preencher `GEMINI_API_KEY` no `.env` e `return self::FAILURE`. **Nunca** imprimir a chave;
   quando ela existe, reportar so o comprimento e os 4 ultimos caracteres.
2. Chamada de TEXTO: prompt curto e deterministico (ex.: "Responda apenas com a palavra OK."),
   cronometrar, imprimir modelo configurado, latencia em ms, status e os primeiros ~120 caracteres
   da resposta.
3. Se `--imagem` foi passado: validar que o arquivo existe e e legivel, descobrir o mime
   (`mime_content_type`), ler os bytes, montar o `CreativeGenerationRequest` com o prompt
   (`--prompt` ou um default de teste de fidelidade: foto de produto em fundo limpo, estilo
   marketplace, **sem inventar texto nem especificacao**), chamar `gerarImagem()`, cronometrar.
4. Gravar o binario gerado para inspecao humana: `--saida` ou, por default,
   `storage/app/private/creative-testes/{timestamp}-{modelo}.jpg` (`Storage::disk('local')` ou
   `File::ensureDirectoryExists` + `file_put_contents`). Imprimir o caminho ABSOLUTO para o humano
   abrir, mais tamanho em KB, modelo que respondeu e latencia.
5. Capturar `\Throwable`, imprimir `$e->getMessage()` (que ja e amigavel e em pt-BR) e devolver
   `self::FAILURE`. Nunca estourar stack trace com payload.
6. Sem `--imagem`, avisar em pt-BR que so a conectividade de texto foi testada e mostrar o exemplo
   de uso com `--imagem=`.

Comentario de classe em pt-BR explicando que este comando E a prova tecnica do §6.3 / V0.1: ele
existe para responder "a Gemini devolve um criativo fiel a partir da foto real?" **antes** de
qualquer tabela, job ou UI — e que ele chama a API de verdade (custa credito), ao contrario do teste
automatizado.

**(b) `tests/Unit/GeminiImageProviderTest.php`** — cobre o `behavior` acima. Regras:
- `extends Tests\TestCase`, **sem** `RefreshDatabase` (nada aqui toca banco, e a suite roda SQLite).
- `Http::preventStrayRequests()` no `setUp()` — pedido sem stub vira excecao em vez de ir a internet.
- `config([...])` no `setUp()` apontando `services.creative.gemini.*` para valores de teste
  (`base_url` fake, `key` => 'chave-de-teste', modelos de teste, `image_fallbacks` => '' por
  default). Atencao ao aviso do `AnuncioIaAnaliseTest`: **`Http::fake()` acumula e o primeiro stub
  que casa vence** — nao por fake generico no setUp.
- Helper privado para montar o corpo de resposta de sucesso com `base64_encode('bytes-falsos-jpeg')`.
- Para a sequencia 503 -> 200, usar `Http::fakeSequence()` ou um fake por closure com contador.
- Docblock de classe em pt-BR dizendo que **nenhum** teste aqui chama a API real e que a prova real
  e o `creative:test-gemini` (manual, custa credito).
  </action>
  <verify>
    <automated>C:/xampp/php/php.exe -l app/Console/Commands/CreativeTestGemini.php &amp;&amp; C:/xampp/php/php.exe artisan list --raw | grep -c 'creative:test-gemini' &amp;&amp; C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/GeminiImageProviderTest.php</automated>
  </verify>
  <done>
`php artisan list` mostra `creative:test-gemini`; `php artisan creative:test-gemini` sem chave falha
com mensagem em pt-BR e sem vazar a chave; os 6 testes do `GeminiImageProviderTest` passam; nenhum
teste faz pedido real (garantido por `Http::preventStrayRequests()`).
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| app -> API Gemini (internet) | Sai prompt + foto do cliente em base64; volta binario nao confiavel |
| operador admin -> `creative:test-gemini` | Argumento `--imagem`/`--saida` e caminho de arquivo controlado por humano (acesso CLI ja e privilegiado) |
| `.env` / config cache -> codigo | `GEMINI_API_KEY` e segredo; `config:cache` em deploy tem que resolver |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-nkx-01 | Information Disclosure | `GeminiImageProvider` logs | mitigate | Log so com modelo/latencia/status/tamanho; chave, body do pedido e base64 (entrada e saida) NUNCA vao ao log; corpo de erro truncado em 400 chars (§17.1) |
| T-nkx-02 | Information Disclosure | `CreativeTestGemini` stdout | mitigate | Comando nunca imprime a chave — so comprimento e 4 ultimos caracteres; `catch \Throwable` imprime a mensagem amigavel, nunca o payload |
| T-nkx-03 | Information Disclosure | Chave em repositorio | mitigate | Chave so via `env('GEMINI_API_KEY')` sem default; `.env.example` entra com valor VAZIO; gate de verificacao `grep -rn "AIza" app config` sem resultado |
| T-nkx-04 | Information Disclosure | Imagem de referencia (foto do cliente) | mitigate | Nada e gravado como acervo neste spike; a saida de teste vai para `storage/app/private/` (disco privado, fora do `public/`) — per D-02, a referencia e efemera por design |
| T-nkx-05 | Denial of Service | Chamada sincrona a provedor externo | mitigate | `timeout` por chamada + `connectTimeout` curto SEPARADO; sem retry do mesmo modelo (licao medida em 29/09/2026 no `AnaliseAnuncioService`); fallback percorre a lista UMA vez |
| T-nkx-06 | Tampering | Binario devolvido pela Gemini | accept | `base64_decode(strict)` e o unico gate; o consumidor neste spike e um humano inspecionando o arquivo em disco privado. Validacao de conteudo e o `CreativeValidator` do V0.4, fora de escopo |
| T-nkx-07 | Elevation of Privilege | Superficie de acesso | accept | Spike nao cria rota, controller nem UI; unico ponto de entrada e artisan, que exige shell no servidor. O gate `role:admin` de `/mlb/anuncios` entra quando houver rota (D-01) |
| T-nkx-SC | Tampering | npm/composer installs | mitigate | **Nenhum pacote novo e instalado** neste spike (`Http`, `Storage`, `File` sao do framework). Se surgir necessidade de pacote, PARAR e abrir checkpoint humano — nao instalar dentro desta tarefa |
</threat_model>

<verification>
```bash
# 1. Notas registradas, com os achados verificaveis
grep -c "drive_imagens\|gemini-3.8-flash\|x-goog-api-key" .planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-NOTAS-PUBLICADOR.md

# 2. Config resolve e documenta
C:/xampp/php/php.exe artisan config:clear
C:/xampp/php/php.exe -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); var_export(array_keys(config('services.creative.gemini')));"
grep -v '^#' .env.example | grep -c GEMINI_

# 3. Nenhuma chave hardcoded
grep -rn "AIza" app config && echo "FALHOU: chave em codigo" || echo "ok: nenhuma chave em codigo"

# 4. Comando registrado
C:/xampp/php/php.exe artisan list --raw | grep creative:test-gemini

# 5. Teste do provider passa e nao chama a internet
C:/xampp/php/php.exe vendor/bin/phpunit tests/Unit/GeminiImageProviderTest.php

# 6. Nada de frontend mudou (nao rodar npm run build)
git status --porcelain resources/ | grep -c . # esperado: 0
```

**Prova de fidelidade (manual, decide o V0.2 — roda o orquestrador/humano, NAO o subagente):**
com `GEMINI_API_KEY` preenchida no `.env` local, rodar
`C:/xampp/php/php.exe artisan creative:test-gemini --imagem="<foto real de produto>"`,
abrir o arquivo gravado em `storage/app/private/creative-testes/` e julgar se o produto foi
PRESERVADO (§16 do plano canonico). Registrar modelo, latencia e o veredito no SUMMARY.
</verification>

<success_criteria>
- [ ] `261001-nkx-NOTAS-PUBLICADOR.md` existe, em pt-BR, com os 8 achados de codigo (com caminhos e
      linhas), os fatos da API Gemini conferidos, a correcao do nome do modelo de texto, as 3
      decisoes travadas e a secao "O que o plano canonico assume e nao confere com o codigo".
- [ ] `config('services.creative')` centraliza provedor, modelos, aspect ratio, image size e os dois
      timeouts; `.env.example` documenta tudo em pt-BR com `GEMINI_API_KEY` vazia.
- [ ] `ImageGenerationProvider` + `GeminiImageProvider` existem; o provider usa header
      `x-goog-api-key`, aceita imagens de referencia, devolve bytes + mime + modelo + latencia_ms +
      status, troca de modelo em falha trocavel e nunca retenta o mesmo.
- [ ] `creative:test-gemini` roda, confere a chave, faz chamada de texto, opcionalmente de imagem,
      grava o binario em disco privado e reporta modelo/latencia/status sem expor a chave.
- [ ] `GeminiImageProviderTest` cobre sucesso, chave ausente, erro trocavel, erro definitivo, 200
      sem imagem e a forma do payload — tudo com `Http::fake()`, zero chamada real, zero migration.
- [ ] Comentarios de codigo em pt-BR. Nenhum arquivo `.jsx`/`.css` tocado; `npm run build` NAO
      rodado; nada deployado; nenhum comando contra o VPS.
- [ ] Commit com caminhos explicitos: `git commit -- config/services.php .env.example
      app/Services/Creative app/Console/Commands/CreativeTestGemini.php
      tests/Unit/GeminiImageProviderTest.php .planning/quick/261001-nkx-*`
      (**arvore compartilhada** — nunca `git add -A`, nunca `git stash`).
</success_criteria>

<output>
Criar `.planning/quick/261001-nkx-spike-v0-1-do-creative-engine-provider-g/261001-nkx-SUMMARY.md`
ao terminar, registrando: modelo de imagem usado, latencia medida, veredito da prova de fidelidade
(se a chave estava disponivel) e o que o V0.2 herda — em especial a decisao D-02 (upload efemero da
foto de referencia, sem integracao com o Google Drive) e o fato de que o provider recebe BYTES, nao
path em storage.
</output>
