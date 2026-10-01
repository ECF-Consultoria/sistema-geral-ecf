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
