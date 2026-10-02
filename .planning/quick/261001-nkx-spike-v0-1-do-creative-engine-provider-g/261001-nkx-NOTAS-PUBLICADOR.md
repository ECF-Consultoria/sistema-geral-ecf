# Notas técnicas — investigação do Publicador para o Creative Engine V0.1

Registro do item 9 do §1.1 do plano canônico `plano-incubadora-v1`: as descobertas abaixo já foram
verificadas contra o código real **antes** desta entrega. Este documento é de REGISTRO, não convite
a reabrir os arquivos citados "para conferir".

## 1. O que já existe de IA no publicador

A camada de IA madura e em produção é `app/Services/Ia/AnaliseAnuncioService.php` (512 linhas): roda
a metodologia MAG T8 em etapas (análise estratégica, títulos, descrição, ficha técnica, grava
rascunho), orquestrada por `app/Jobs/GerarAnaliseAnuncioIaJob.php` na fila `high` — **nunca** a
`default`: medido em produção em 21/09/2026 a `default` tinha 170 jobs represados e a análise ficou
395s sem worker. O front faz polling em
`resources/js/Pages/Mlb/components/PainelAnunciarIa.jsx`.

Consequência que importa: a "Parte 1" do PDF (§4.1 do plano canônico) **já está construída** — o
Creative Engine é a Parte 2 (roteiro de imagens), não um recomeço.

## 2. Provedor de IA atual e por que ele não serve para imagem

`config('services.llm')` (`config/services.php:371-380`) expõe `base_url`/`key`/`model`/`fallbacks`/
`timeout`/`max_tokens`, hoje apontando para a NVIDIA (tier grátis) e **texto-only**. Fallback por
modelo via `app/Services/Ia/FalhaTrocavel.php`: sobrecarga, timeout ou modelo fora = trocar de
modelo, **nunca** retentar o mesmo (`app/Services/Ia/AnaliseAnuncioService.php:148-159`). O cliente
HTTP não se reaproveita para a Gemini (auth e payload diferentes), mas as lições dele — sem retry no
mesmo modelo, connectTimeout separado do timeout de geração, mensagem amigável traduzida do status
HTTP — são obrigatórias no provider novo.

## 3. Tabelas e convenção de nomes

`ml_anuncio_rascunhos` (payload json, status enum rascunho/validado/publicando/publicado/erro) e
`ml_anuncio_ia_analises` (resultado json, status/etapa/tentativas, `LIMITE_MINUTOS=15` como trava
anti-loop-infinito). Convenção real do módulo: prefixo `ml_`, colunas em snake_case pt-BR.

## 4. Como a imagem chega ao ML hoje (e onde ela NÃO está)

Fotos **não** são guardadas localmente: com variações, o wizard sobe cada arquivo DIRETO para o ML
via `App\Services\Mlb\Publicacao\MlImagemService::enviar()` (POST `/pictures/items/upload`, devolve
`picture_id` + `url`) e guarda só os ids; item simples não tem upload nenhum — o operador COLA uma
URL (`resources/js/Pages/Mlb/AnunciarML.jsx:2440`, com a dica literal "Upload direto será adicionado
numa próxima versão").

## 5. Onde vive a foto bruta do cliente

Num link de Google Drive guardado como TEXTO: item `drive_imagens` do onboarding
(`App\Models\MlbImplementacao:354`, tipo `link_admin`, gravado em `dados.links_admin.drive_imagens`).
A tela só mostra um botão para o humano clicar
(`resources/js/Pages/.../ImplementacaoPublicador.jsx:607`). **Não existe acesso programático**: o
OAuth do projeto é só Calendar (`calendar.readonly` + `calendar.events`) e o ECF Drive
(`files.ecfconsultoria.com.br`) serve CSV/JSON de dados, não fotos.

## 6. Forma do wizard

5 etapas, e a ÚLTIMA já é "Imagem e frete" (`AnunciarML.jsx:22-28`); o arquivo tem 2857 linhas. O
padrão do módulo para coisa assíncrona é componente separado com polling (`PainelAnunciarIa`, 469
linhas), nunca código inline.

## 7. Gate de acesso

`routes/mlb_anuncios.php`, grupo `['auth','verified','role:admin']`, prefix `mlb/anuncios`, name
`mlb.anuncios.`. Existe também `routes/incubadora_publicador.php` com gate por módulo, mas o usuário
decidiu NÃO usar esse caminho.

## 8. Credenciais

Não existe `GEMINI_API_KEY` no `.env` nem no `.env.example`. Só existem GOOGLE_CLIENT_ID/SECRET/
REDIRECT_URI (Calendar). Chave nova é exclusiva do Creative Engine.

## 9. Fatos da API Gemini conferidos em 2026-10-01

Endpoint único `POST https://generativelanguage.googleapis.com/v1beta/interactions`. Auth por header
`x-goog-api-key: <chave>` (NÃO Bearer, NÃO query param). Chamada SÍNCRONA — não devolve job para
polling.

Body de IMAGEM com referências inline (até 14 imagens, conforme o modelo):
```
model: "gemini-3.1-flash-image"
input: [ {type:"text", text:"<prompt>"},
         {type:"image", mime_type:"image/jpeg", data:"<BASE64>"} ]
response_format: {type:"image", mime_type:"image/jpeg", aspect_ratio:"1:1", image_size:"2K"}
```
Resposta de IMAGEM: base64 em `interaction.output_image.data`.

Body de TEXTO:
```
model: "gemini-3.8-flash"
input: "<prompt>"
```
Resposta de TEXTO: `interaction.outputText`.

`image_size`: `512px` | `1K` | `2K` | `4K`. `aspect_ratio`: `1:1` | `16:9` | `3:2` | `4:5` ...

Modelos de imagem válidos: `gemini-3.1-flash-lite-image`, `gemini-3.1-flash-image` (Nano Banana 2),
`gemini-3-pro-image` (Nano Banana Pro), `gemini-2.5-flash-image` (legado). Imagen 4
(`imagen-4.0-*`) está DEPRECADO, shutdown 17/08/2026 — não usar.

Para o Mercado Livre o certo é `aspect_ratio: "1:1"` (o §13.3 pede 1200x1200; "2K" em 1:1 atende com
folga o mínimo de 500px do ML).

## 10. Correção ao plano canônico

O `gemini-3.1-flash` que o §6.2 propõe para texto e validação **não existe**; o flash estável atual
de texto é `gemini-3.8-flash`, e é este o default adotado. O default de imagem do spike é
`gemini-3.1-flash-image` (barato e rápido para iterar na prova de fidelidade), com
`gemini-3-pro-image` alcançável por env quando a fidelidade exigir — nada hardcoded.

## 11. Decisões travadas pelo usuário (LOCKED)

- **(D-01) LOCKED** — o Creative Engine mora DENTRO de `/mlb/anuncios` sob `role:admin`, não no
  módulo Incubadora.
- **(D-02) LOCKED** — origem da imagem de referência: a foto original fica no Google Drive do
  cliente, e na hora de publicar o operador faz UPLOAD dela no sistema; o sistema **não guarda**
  essa imagem de forma definitiva, ela existe apenas como BASE para o Gemini. Ou seja: upload
  EFÊMERO/temporário (disco privado, retenção curta), nunca acervo permanente, e **sem** integração
  programática com o Google Drive. Isto direciona o V0.3; construir o staging de upload está fora
  deste spike.
- **(D-03) LOCKED** — modo de renderização inicial é FULL_AI (§9.1), com a arquitetura preparada para
  COMPOSITE no futuro — mas COMPOSITE **não** se constrói agora.

## 12. O que o plano canônico assume e não confere com o código

(a) o §13.3 modela `reference_images` como lista de *paths* em storage, mas hoje não existe acervo de
foto em storage nenhum (ver seções 4 e 5) — por isso a decisão D-02 do usuário, e por isso o provider
desta entrega recebe **bytes**, não caminho.

(b) o §14 fala de "UI da etapa final" como se a última etapa estivesse livre, mas a 5ª etapa do
wizard já é "Imagem e frete" — integrar vai exigir conviver com ela, não ocupar o lugar dela.

(c) o §12.1 fala de paralelismo (`GEMINI_MAX_PARALLEL_JOBS`) sem mencionar fila, e no projeto a fila
tem nome: trabalho interativo vai para a `high`; a `default` congestiona (ver seção 1).

## Escopo deste spike

**Entra:** estas notas; bloco `'creative'` em `config/services.php` + `.env.example`; interface
`ImageGenerationProvider` + DTOs `CreativeGenerationRequest`/`CreativeGenerationResult`; exceção
`FalhaDeGeracaoTrocavel`; implementação `GeminiImageProvider`; comando `creative:test-gemini`; teste
unitário `GeminiImageProviderTest` com `Http::fake()`.

**Fica fora (explicitamente):** tabelas `creative_projects`/`creative_assets`, qualquer migration,
jobs, rotas, controllers, qualquer `.jsx`, o staging de upload efêmero (D-02), `CreativeContextBuilder`
/ `ProductTruthBuilder` / `CreativePlanner` / `CreativeValidator`, e qualquer integração com o wizard
de publicação.

---

## 13. Medição contra a API real (2026-10-01, chave de produção)

A chave foi criada e testada no mesmo dia. Três fatos que só a chamada real revelou:

**(a) A forma da resposta não é a que a documentação resume.** A página de docs descreve
`interaction.outputText` e `interaction.output_image.data`. A API devolve objeto **plano**, com o
conteúdo em `steps[]`:

```json
{"id":"…","status":"completed","usage":{…},"service_tier":"standard","object":"…","model":"…",
 "steps":[{"type":"thought","signature":"…"},
          {"type":"model_output","content":[{"type":"text","text":"ok"}]}]}
```

O passo `thought` vem **antes** e não tem conteúdo — quem lê `steps[0]` lê o passo errado. O provider
foi corrigido para varrer `steps[]` atrás do `model_output`; a grafia da doc ficou como último
recurso. ⚠️ **Os testes com `Http::fake()` não pegaram isso** porque os fakes foram escritos a partir
da mesma forma errada — fake construído de documentação, não de medição, confirma o próprio engano.

**(b) GERAÇÃO DE IMAGEM EXIGE TIER PAGO.** Com chave válida, **todos** os modelos de imagem
responderam HTTP 429 `limit: 0 requests per day on Free Tier`: `gemini-3.1-flash-image`,
`gemini-3.1-flash-lite-image`, `gemini-3-pro-image` e `gemini-2.5-flash-image` (este com "0 input
tokens per minute"). Texto funciona no tier grátis. **É este o bloqueio da prova de fidelidade do
§16** — não a chave, que está boa. Upgrade em https://ai.dev/rate-limit.

**(c) O modelo de texto default congestiona.** `gemini-3.8-flash` devolveu 503 `experiencing high
demand` em chamadas seguidas, enquanto `gemini-3.5-flash-lite` respondia em 1,3s com a mesma chave.
Daí a reserva de texto (`GEMINI_TEXT_MODEL_FALLBACK`) passar a existir e já vir preenchida.

⚠️ **A forma do `content` de IMAGEM segue INFERIDA**, não medida: sem tier pago não houve nenhuma
resposta 200 de imagem para conferir. O extrator aceita várias grafias (`data`, `image_data`,
`inline_data.data`, `image.data`) justamente por isso — **confirmar na primeira geração real**.

⚠️ **A chave chegou dentro do `.env.example` por engano** (arquivo versionado). Foi movida para o
`.env` antes de qualquer commit e **não entrou no histórico** (conferido com `git log -S`). Chave de
API nunca vai no `.env.example`, que existe só como modelo com valores vazios.

---

## 14. Primeira geração real (2026-10-01, tier pago) — e o alerta que ela trouxe

Com billing vinculado ao projeto da chave, a geração passou a funcionar na hora, sem mudar
uma linha de código: `gemini-3.1-flash-image`, HTTP 200, 15,3s, JPEG 2048×2048 válido (1.437 KB).

**Forma da resposta de imagem CONFIRMADA** (era inferida até aqui, e a inferência estava certa):

```json
"steps":[{"type":"thought"},
         {"type":"model_output","content":[{"type":"image","mime_type":"image/jpeg","data":"<base64>"}]}]
```

### ⚠️ O achado que importa para o §16: o modelo REESCREVE texto e NÚMEROS

O teste usou por acaso um print de dashboard (denso em texto) como referência. O modelo preservou
muito bem **layout, cor de marca, logo e composição** — e corrompeu quase todo texto pequeno:

| no original | no gerado |
|---|---|
| `Wallet Overview & Spending` | `Wallet Walleteet & Spending` / `Winld Wahaenes & Spending` |
| `Steady Growth Savings` | `Stkady Growth Savings` |
| `Transactions` | `Transaations` |
| `GBP` | `GEP` |
| `+1.5%` | `+1.4%` e `+24%` |
| `€28,345.00` | `€29,345.00` |
| `£25,000.00` | `£25,500.00` |
| `1 USD = 122.20 BDT` | `132.25 BDT` |
| `Cashflow $33,847.00` | `$23,847.00` |

Também **duplicou a tela inteira** (dois painéis empilhados num mockup de notebook) — alucinação de
composição, não de detalhe.

**Por que isto decide desenho e não é só curiosidade.** O §16 lista como ELIMINATÓRIO "inventar
especificação técnica" e "escrever potência, voltagem, medida ou capacidade diferente do cadastro".
Os slots planejados `SPECIFICATIONS`, `DIMENSIONS` e `BENEFITS` são exatamente os que carregam
badges do tipo `1200W` / `220V` / `13mm`. Um modelo que troca `€28.345` por `€29.345` sozinho vai
trocar `1200W` por `1800W` — e num anúncio de Mercado Livre isso é informação falsa ao consumidor.

**Leitura honesta dos limites deste teste:** a referência era um print denso em texto, o pior caso
possível para renderização de texto; foto de produto com pouco texto tende a ir muito melhor **no
produto em si**. O que NÃO muda é a conclusão sobre texto sobreposto, porque a falha não foi "copiar
mal a foto", foi "reescrever caracteres".

**Direção que isto sugere para o V0.2/V0.4** (decisão do usuário, não tomada aqui): o `FULL_AI` do
§9.1 parece adequado para os slots visuais (`HERO`, `LIFESTYLE`, `ANGLES`, `DETAIL`), e os slots com
texto/número cravado são candidatos naturais ao `COMPOSITE` do §9.2 — IA gera o cenário, o sistema
desenha o texto por template. Era a hipótese que o próprio plano levantava; a primeira medição real
já aponta para ela. Confirmar com foto de produto de verdade antes de fechar.

---

## 15. Teste de fidelidade com produto REAL (2026-10-01) — slot LIFESTYLE

Produto: gabinete de cozinha branco de um cliente, 3 fotos (3/4 em fundo branco, frontal com o
interior à mostra, e uma ambientada), enviadas pela equipe como chegam pelo Drive.

**As 3 fotos foram mandadas juntas como referência na MESMA chamada** — o comando passou a aceitar
`--imagem` repetido para isso. É o que o §8.2 pede: quanto mais ângulos do mesmo produto, menos o
modelo precisa inventar o que não vê.

`gemini-3.1-flash-image`, HTTP 200, 15,3s, 1.866 KB, 2048×2048. Prompt no formato MASTER+SLOT do
§8.5 (regras de fidelidade explícitas + proibição total de texto).

**Resultado: o produto foi preservado.** Conferido elemento a elemento contra a referência —
2 portas à esquerda e coluna de 3 gavetas à direita na mesma proporção, puxadores de perfil
horizontal prateado, tampo branco com rodabanca ao fundo, 4 pés cônicos prateados, branco fosco
mantido. A ambientação (cozinha real, luz natural lateral, bancada vizinha em madeira) é de
qualidade comercial e nenhum texto foi inventado — o prompt proibia, e foi obedecido.

### O contraste com a seção 14 é o achado de desenho

| | referência | resultado |
|---|---|---|
| print de dashboard (denso em texto) | §14 | layout ok, **texto e números reescritos**, tela duplicada |
| gabinete, 3 ângulos, sem texto | §15 | **produto preservado**, ambientação comercial |

A conclusão não é "o modelo é bom" nem "o modelo é ruim": é que **a fraqueza dele é texto, não
geometria**. Isso separa a biblioteca de slots do §8.4 em dois grupos com risco MUITO diferente:

- **Seguros em `FULL_AI`** — `HERO`, `LIFESTYLE`, `WHITE_BACKGROUND`, `ANGLES`, `DETAIL`: só forma,
  cor e cena. É o que acabou de ser medido.
- **Perigosos em `FULL_AI`** — `SPECIFICATIONS`, `DIMENSIONS`, `BENEFITS`, `COMPARISON`: carregam
  número e texto cravados, exatamente o que a §14 mostrou o modelo reescrevendo. Candidatos ao
  `COMPOSITE` do §9.2, onde a IA gera só o cenário e o sistema desenha o texto por template a partir
  do cadastro.

⚠️ **Amostra de UM produto e UM slot.** Antes de fechar desenho, repetir com produto que tenha
detalhe físico difícil (peça pequena, produto com logo aplicado, kit com vários itens) — categorias
onde "preservar o produto" é mais difícil que num móvel branco de superfície lisa.

---

## 16. Comparação dos três modelos de imagem (2026-10-01) — mesmo produto, mesmo prompt

Mesmas 3 fotos de referência do gabinete, mesmo prompt MASTER+SLOT, uma execução por modelo.
Opções `--modelo` e `--tamanho` foram acrescentadas ao comando para isto (o §19 pede custo por kit).

| modelo | saída | latência | arquivo | preço/imagem | kit de 7 |
|---|---|---|---|---|---|
| `gemini-3.1-flash-lite-image` | 1K | 6,7s | 552 KB | US$ 0,0336 | US$ 0,24 |
| `gemini-3.1-flash-image` | 2K | 16,9s | 2.019 KB | US$ 0,101 | US$ 0,71 |
| `gemini-3-pro-image` | 2K | 25,7s | 2.021 KB | US$ 0,134 | US$ 0,94 |

(Preços do tier pago, tabela oficial de 2026-10-01. `flash-image` em 1K cai para US$ 0,067.)

### O que cada um fez com o MESMO pedido

**lite** — geometria certa (3 gavetas, pés, rodabanca), mas **reproduziu o gabinete SEM as portas**,
copiando o estado da foto de referência que mostra o interior aberto, e repetiu a encenação das
referências (as mesmas panelas, potes e bananas). Mostrar o móvel sem as portas que ele tem
**descaracteriza o produto** — é o §16 item 5 ("remover item que deveria estar no kit"). Além disso
só entrega 1K.

**flash** — portas fechadas, 2 portas + coluna de 3 gavetas, puxadores de perfil, tampo com
rodabanca, 4 pés cônicos. Ambientação boa e **respeitou a proibição de texto**. É o equilíbrio.

**pro** — a melhor fotografia das três, de longe, e geometria do produto correta. **Mas violou a
instrução explícita de não escrever texto**: colocou livros sobre a bancada com títulos ilegíveis
("Morosofolli 88", "BOOKSOFIK"). Não foi texto sobre o produto, foi texto em adereço de cena — e
ainda assim é texto inventado numa peça que vai para anúncio.

### A conclusão que isso muda

**O modelo premium não é automaticamente a escolha certa.** O `pro` enfeita mais a cena, e é
justamente no enfeite que o texto se infiltra. Para um pipeline em que "nenhum texto inventado" é
regra, mais capacidade criativa é mais superfície de risco.

Recomendação para o V0.2 (decisão do usuário): **`gemini-3.1-flash-image` como default**, `pro`
reservado para slot que precise de fotografia excepcional e passe por revisão humana atenta, e
**`lite` descartado** para ambientação — o desconto de 70% não paga descaracterizar o produto.

⚠️ **O `lite` devolve HTTP 404 com `image_size=2K`** — em qualquer pedido, com ou sem referência.
Não é "modelo inexistente": o mesmo pedido em `1K` responde 200. Como o nosso default é 2K, ele
falharia sempre, e a mensagem genérica do Google ("Requested entity was not found") mandaria quem
depura caçar nome de modelo errado. A mensagem de 404 do provider foi reescrita para citar
`GEMINI_IMAGE_SIZE`.

⚠️ **Custo de regeneração não está nas contas acima.** O §12.2 prevê até 2 tentativas por asset;
no pior caso o kit dobra de preço. A taxa real de regeneração é uma das métricas do §19 e só o uso
mede.

---

## 17. Kit de 7 nos três modelos (2026-10-02) — CORRIGE a conclusão da seção 16

O usuário recusou, com razão, a conclusão da §16: um teste por modelo não condena modelo nenhum.
E apontou o erro de leitura — o `lite` gerou o gabinete sem portas porque **uma das referências
mostra o móvel sem portas** (a foto do interior aberto). Ele estava seguindo uma referência, não
alucinando. A §16 condenou o `lite` por um comportamento que o próprio material de entrada induziu.

Refeito: **kit de 7 criativos, mesmo produto, mesmos 3 ângulos, mesmos 7 prompts, nos 3 modelos**
(21 imagens). Cada modelo no seu melhor: `lite` em 1K (teto dele), `flash` e `pro` em 2K.
Saída em `storage/app/private/creative-testes/kit/<modelo>/`.

Slots: 1-HERO, 2-LIFESTYLE, 3-ANGLES, 4-DETAIL, 5-INTERIOR, 6-BENEFITS, 7-SPECIFICATIONS.
Os slots 6 e 7 **fixam as palavras exatas no prompt**, para conferir letra por letra quem reescreve.

### ⚠️ A CORREÇÃO QUE MAIS IMPORTA: a §14 mediu a coisa errada

**Os três modelos escreveram o texto PERFEITAMENTE** nos slots 6 e 7 — "Duas portas amplas",
"Tres gavetas", "Tampo com rodabanca", "Pes em aluminio", todas exatas, em `lite`, `flash` e `pro`.
No `lite` e no `pro` as chamadas ainda apontam para o elemento certo do móvel.

A §14 concluiu que "o modelo reescreve texto". **Errado — faltou distinguir duas tarefas
diferentes:**

| tarefa | resultado |
|---|---|
| texto que o modelo precisa **LER de uma imagem de referência** e reproduzir | corrompe (§14: "Transactions"→"Transaations", "€28.345"→"€29.345") |
| texto **DADO no prompt** como string exata para desenhar | **sai correto** (§17, nos três modelos) |

Isso muda o desenho na direção OPOSTA à que a §15 sugeria. No Creative Engine, badge e headline vêm
do **cadastro do anúncio**, passados ao prompt pelo `CreativePromptBuilder` (§8.5) — ou seja, caem
no caso que FUNCIONA. O `COMPOSITE` do §9.2 **não é pré-requisito** para slot com texto, como a §15
deu a entender. Ele continua valendo como opção de controle fino de layout/fonte, não como remendo
de confiabilidade.

Continua de pé da §14: nunca pedir ao modelo para COPIAR texto que só existe dentro de uma foto.

### O que foi conferido imagem a imagem (8 das 21)

| slot | lite (1K) | flash (2K) | pro (2K) |
|---|---|---|---|
| 1-HERO | ❌ **três portas** (produto tem duas) | ✓ fiel | ✓ fiel |
| 2-LIFESTYLE | ✓ fiel (2 portas) | — | — |
| 6-BENEFITS | ✓ texto exato, chamadas certas | — | ✓ texto exato, chamadas certas |
| 7-SPECIFICATIONS | ✓ texto exato | ⚠️ texto exato, mas **fantasma do móvel** duplicado embaixo | ✓ texto exato, composição limpa |

⚠️ **O erro do `lite` no HERO não é sistemático**: no LIFESTYLE do mesmo kit ele acertou as duas
portas. É falha esporádica de contagem — exatamente o tipo de coisa que a validação automática do
§8.8 e a revisão humana do §14.1 existem para pegar, e que o botão de regenerar resolve.

⚠️ **O `flash` também falhou uma vez**, com o fantasma duplicado no slot 7. Nenhum dos três é
imune; os três erram esporadicamente e em slots diferentes.

### Custo medido do kit de 7

| modelo | resolução | tempo total | custo do kit |
|---|---|---|---|
| `gemini-3.1-flash-lite-image` | 1K | ~50s | US$ 0,24 |
| `gemini-3.1-flash-image` | 2K | ~112s | US$ 0,71 |
| `gemini-3-pro-image` | 2K | ~158s | US$ 0,94 |

### Conclusão (substitui a da §16)

Nenhum modelo é descartável e nenhum é confiável sozinho — **a taxa de erro esporádico dos três
justifica a validação automática + revisão humana que o plano já prevê**, e é o argumento mais forte
a favor do botão "Regenerar" por imagem (§14.1 item 5).

Para escolher: `flash` segue a recomendação por equilíbrio, mas agora **sem condenar o `lite`** —
a 1/3 do preço e 1/2 do tempo ele entregou 3 dos 4 slots conferidos sem defeito, e pode ser a
escolha certa para slots simples ou para a primeira tentativa antes de escalar para um modelo maior
numa regeneração. Decisão do usuário.

⚠️ **13 das 21 imagens ainda não foram conferidas uma a uma** (slots 3, 4, 5 nos três modelos e
parte dos 2 e 6). A tabela acima é amostra, não censo.

---

## 18. O "pé a mais" NÃO era defeito — e o erro foi do prompt (2026-10-02)

Os três modelos desenharam um pé central além dos quatro dos cantos. Foi levantado como defeito
compartilhado e testado: prompt novo com `CONTAGEM OBRIGATORIA ... EXATAMENTE QUATRO pes conicos,
um em cada canto. NAO desenhe nenhum pe adicional no meio`. Os três **mantiveram** o pé central.

Antes de registrar como viés de modelo, o usuário conferiu a foto original: **o produto TEM cinco
pés, com um centralizado**. Os modelos estavam seguindo a referência corretamente. Não houve
defeito nenhum.

### ⚠️ A lição é sobre o PROMPT, e vale mais que o teste

Quem errou foi a instrução: afirmou "exatamente quatro pés" sem conferir o produto. Isso é
literalmente a *forbidden claim* do §8.2 — característica que o cadastro não sustenta — só que
cometida do lado de quem escreve o prompt, não do modelo.

**Consequência direta para o `ProductTruthBuilder` (§8.2) e o `CreativePromptBuilder` (§8.5):**
contagem de peça (portas, gavetas, pés, itens do kit) só pode entrar no prompt quando vier do
CADASTRO ou de leitura conferida da referência. Jamais de suposição de quem monta o prompt, e
jamais de "parece que são quatro". Um número errado no prompt é pior que número nenhum: o modelo
obedece com confiança e o resultado passa despercebido na revisão, porque a imagem fica coerente
consigo mesma.

Isso reforça o desenho do §8.2: o Product Truth precisa ser explicitamente a lista do que foi
VERIFICADO, e tudo que não estiver lá deve ficar fora do prompt em vez de ser preenchido por
inferência.

## 19. O defeito real do `lite`: contagem de portas e gavetas, em mais de um slot

Separado do falso alarme dos pés, o `lite` tem um problema de verdade, apontado pelo usuário e
conferido imagem a imagem no kit de 2026-10-02:

| slot | esperado | o que o `lite` gerou |
|---|---|---|
| 1-HERO | 2 portas | **3 portas** |
| 5-INTERIOR | coluna com 3 gavetas | **2 gavetas** |
| 2-LIFESTYLE | 2 portas | 2 portas ✓ |
| 6-BENEFITS / 7-SPECIFICATIONS | — | contagem ✓ e texto exato ✓ |

Ou seja: **não é falha esporádica de um slot**, como a §17 registrou. É recorrente dentro do mesmo
kit, no mesmo produto, com as mesmas referências. A §17 errou ao chamar de "esporádica" com base em
dois slots; com o kit inteiro conferido, o `lite` erra contagem com frequência que o descarta para
este uso.

`flash` e `pro` não apresentaram erro de contagem em nenhum slot conferido.

## 20. Decisão do usuário: `flash` é o modelo (2026-10-02)

Escolhido pelo usuário depois de ver os três kits completos: **`gemini-3.1-flash-image`**.

Bate com o medido: único sem erro de contagem entre os conferidos, entrega 2K (o `lite` só faz 1K),
e fica no meio de tempo e preço — US$ 0,71 o kit de 7 contra US$ 0,94 do `pro`, e 112s contra 158s.
O `pro` entrega a melhor fotografia mas custa 32% mais e foi o único a inventar adereço com texto
(§16). É o default do V0.2; `GEMINI_IMAGE_MODEL` já está nele.
