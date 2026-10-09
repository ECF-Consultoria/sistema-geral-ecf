---
phase: 175-publicador-etapa-3-produto-fases
plan: 05
subsystem: api
tags: [publicador, fases, kits, previa, estoque, multideposito, throttle, idor, phpunit]

# Dependency graph
requires:
  - phase: 175-01
    provides: "colunas `produto_base_id`, `quantidade_kit`, `fase`, `estoque_calculado` e o unique `pubprod_base_qtd_uq`"
  - phase: 175-02
    provides: "`CriarFaseService::criar(PubProduto, array)` com as recusas KIT-01..KIT-04 e `PubProduto::familia()/proximaFase()/proximaQuantidade()`"
  - phase: 175-04
    provides: "`MlbPublicadorFaseController` (a casa dos endpoints), `FamiliaDeFasesService::MOTIVO_FASE_1` e a rota `publicador.produto`"
  - phase: 164 (Publicador)
    provides: "`ProgramasPublicadorService::resolver()/produtosQuery()`, `CategorySchemaRepository::obter()`, `PalavrasChaveService::ajustarTitulo()` e o padrão de throttle do `publicador.produtos.criar`"
  - phase: Portal (AtorDoPortal)
    provides: "`AtorDoPortal::daEquipe()` e o formato de `ator` de `PublicacaoService::atorParaGravar()`"
provides:
  - "`App\\Services\\Publicador\\PreviaDaFaseService` — 5 cálculos puros (SKU, SELLER_SKU, título, descrição, estoque) + `previa()` montando o payload do painel"
  - "Rota nomeada `mlb.anuncios.publicador.fases.previa` (`GET …/produtos/{produto}/fases/previa?quantidade=N`, `throttle:120,1`)"
  - "Rota nomeada `mlb.anuncios.publicador.fases.criar` (`POST …/produtos/{produto}/fases`, `throttle:60,1`, 201 com `{produto:{id}, url, capa_pedida}`)"
  - "Aviso estruturado `preco_vazio` no payload da prévia — a decisão do usuário de 2026-10-08 vira DADO do servidor, não frase no front"
  - "Gate servidor da Fase 1 publicada (inclui `PARTIALLY_PUBLISHED`) na criação da fase, com a regra `KIT-05`"
affects: [175-06, 175-07, 175-08, 175-09, 175-10]

# Tech tracking
tech-stack:
  added: []
  patterns:
    - "Prévia e criação compartilham UMA classe de cálculo: o POST recalcula no servidor, então a prévia nunca divergir do Confirmar é estrutural, não disciplina"
    - "Avisos como dado estruturado uniforme (`list<{chave, mensagem}>`), com `mensagem` sempre string — nenhum texto de regra hardcoded no React"
    - "Estoque derivado NUNCA aceita valor do corpo: o campo simplesmente não existe no `validate()` e o controller sobrescreve pelo cálculo (T-175-18)"
    - "`erro_campo` separado de `avisos`: um único bloqueio (quantidade duplicada) contra N avisos que não travam o Confirmar"
    - "Enriquecimento cosmético (max_title_length) protegido por `catch (\\Throwable)` com fallback — nunca 500 por um número de apoio"

key-files:
  created:
    - app/Services/Publicador/PreviaDaFaseService.php
    - tests/Unit/Publicador/PreviaDaFaseServiceTest.php
    - tests/Feature/Publicador/CriarFaseEndpointTest.php
  modified:
    - app/Http/Controllers/MlbPublicadorFaseController.php
    - routes/mlb_anuncios.php

key-decisions:
  - "DECISÃO DO USUÁRIO (2026-10-08, `175-DECISOES.md` item 2): o kit nasce com preço vazio **com aviso no painel** (opção `b-aviso-no-painel`). Implementado como o aviso `preco_vazio` na lista de avisos da prévia, para o painel só exibir. Não bloqueia o Confirmar; a exigência do preço fica onde já estava (conferir/publicar)."
  - "`PalavrasChaveService::ajustarTitulo()` só é chamado no ramo do CORTE. Ele também remove tudo o que não é letra/número/espaço: rodá-lo sempre transformaria 'Cadeira 1,5m - Preta' (já conferido na Fase 1) em 'Cadeira 15m Preta' sem avisar."
  - "`estoque` do kit é a SOMA DOS DIVIDIDOS por depósito, não `floor(soma ÷ N)` — as duas contas dão números diferentes (A=5, B=5, N=3: 2 contra 3) e a soma dos divididos é o que o editor grava e o ML recebe por depósito."
  - "Estoque nulo no base continua nulo no kit (aviso `estoque_desconhecido`), nunca zero: zero diria 'sem unidades' e bloquearia a publicação por um dado que ninguém digitou."
  - "O `campo` da recusa 422 sai de `RegraViolada::$contexto['campo']`, não fixo em `quantidade`: KIT-03/KIT-04 marcam o campo, KIT-01/02/05 são recusas do produto inteiro."
  - "Gate da Fase 1 publicada lê `pub_rascunhos.status` direto em vez de chamar `prontidao()`: para os status PUBLISHED/PARTIALLY_PUBLISHED o resultado é idêntico (o ramo de validação do `prontidao()` só vale para DRAFT), sem a consulta extra de validações."
  - "Pedir prévia/criação pelo id de um KIT resolve no BASE da família (`$p->base ?? $p`), mesma regra do `mostrar()` — é o que impede cadeia de kits pela URL."
  - "A variante do kit ganha `ativa` no payload da prévia (campo além da forma do plano): o painel precisa saber qual linha mostrar apagada, e variante desligada no base não gera aviso de estoque."

patterns-established:
  - "Payload de prévia com formato testado campo a campo (`assertIsString`/`array_keys`), por causa da tela preta de 07/10 em produção"
  - "Throttle nomeado por endpoint no padrão `publicador.<recurso>.<acao>`, com teto do vizinho de mesma natureza"
  - "Teste de rota por `Route::getRoutes()->getByName()->gatherMiddleware()/wheres` em vez de `route:list` (que trava com o `.env` local)"

requirements-completed: [FASE-04, FASE-05]

# Metrics
duration: 52min
completed: 2026-10-08
---

# Fase 175 Plano 05: Prévia e criação da fase (§4 da ETAPA-3) Summary

**`PreviaDaFaseService` calcula no servidor tudo o que o painel "Criar Fase 2" mostra — SKU `-KIT{N}`, título cortado por palavra inteira, descrição, estoque `floor(÷N)` por variante E por depósito — e os endpoints `fases.previa` (lê, zero escrita) e `fases.criar` (uma transação, 201 com `?etapa=condicoes`) usam exatamente o mesmo cálculo, então a prévia não pode divergir do Confirmar.**

## Performance

- **Duration:** ~52 min
- **Tasks:** 3 de 3 (a Task 3 era checkpoint, respondido antes da wave — ver abaixo)
- **Files modified:** 5 (3 criados, 2 editados)

## Accomplishments

- **`app/Services/Publicador/PreviaDaFaseService.php` (492 linhas)** — 5 funções `public static` PURAS (`skuSugerido`, `sellerSkuSugerido`, `tituloSugerido`, `descricaoSugerida`, `estoqueDoKit`) e `previa()` montando o payload, com 7 chaves de aviso (`preco_vazio`, `sku_repetido`, `estoque_zero`, `estoque_desconhecido`, `titulo_cortado`, `sem_categoria`, `sem_rascunho`) e um único `erro_campo`.
- **Dois endpoints** no grupo `role:admin`, com `{conta}` só pelo resolver, `where('conta', '(empresa|company)-[0-9]+')`, `whereNumber('produto')` e throttle nomeado (`120,1` na prévia; `60,1` na criação, o mesmo teto do `publicador.produtos.criar`).
- **A decisão do preço vazio virou dado do servidor**, não texto no React — ver "A decisão da Task 3" abaixo.
- **56 testes novos** (28 unit + 28 feature, 164 asserções no feature), incluindo o caso multidepósito que distingue soma-dos-divididos de floor-da-soma, os 7 formatos de quantidade recusada e as três provas de que o corpo não manda em âncora, estoque nem ator.

## Task Commits

1. **Task 1 — `PreviaDaFaseService` (TDD)** — `4ad514a2` (test) → `c0b07261` (feat)
2. **Task 2 — endpoints `previa` e `criar` (TDD)** — `4e119286` (test) → `6591db43` (feat)
3. **Task 3 — checkpoint do preço do kit** — sem commit de código: respondido em `175-DECISOES.md` e implementado dentro da Task 1 (aviso `preco_vazio`)

## Testes: antes e depois

| Suíte | Antes | Depois |
|---|---|---|
| `tests/{Feature,Unit}/Publicador` (PHP) | **965 ✓** | **1021 ✓** (+56, 5049 asserções) |
| `npm run test:js` | 1450 ✓ / **2 ✗** | 1450 ✓ / **2 ✗** (idênticas) |

As 2 falhas JS são as **pré-existentes** já mapeadas no briefing — `estrutura-grade-glide.test.js` ("Características secundárias nasce recolhido") e `polosEntrantes.test.js` ("FASES_TERMINAIS"). Não viraram 3, e **nada de frontend foi tocado** nesta plan: `git diff --stat` de `resources/js/` sai vazio (inclusive `usePublicador.js`, como mandado), então `npm run build` não era necessário.

## A decisão da Task 3 (checkpoint `blocking`)

**O checkpoint NÃO foi reaberto.** A resposta do usuário está em
`.planning/phases/175-publicador-etapa-3-produto-fases/175-DECISOES.md`, item 2,
registrada em **2026-10-08**, antes desta wave começar:

> *"Como especificado, com aviso no painel"* — opção `b-aviso-no-painel`.

### Como ficou representada no payload

O painel React é do `175-07`, então o que cabia a este plano era o lado servidor.
A frase **não** está hardcoded no front: ela é um item da lista `avisos` da
prévia, no mesmo formato uniforme de todos os outros avisos:

```json
{ "chave": "preco_vazio",
  "mensagem": "O kit vai nascer sem preço. Ele é criado normalmente, mas só vai para o ar depois que você informar o valor de cada tipo de anúncio no editor do kit." }
```

Propriedades provadas por teste (`test_aviso_do_preco_vazio_vem_sempre_e_nao_bloqueia`):

- o aviso vem **sempre**, em toda prévia;
- `erro_campo` continua `null` — o aviso **não bloqueia** o Confirmar;
- o payload **não tem** `preco` nem `preco_sugerido` (decisão 6 do handoff intacta:
  o painel segue sem campo de preço e sem sugestão);
- a mensagem é pt-BR, sem jargão, e diz as duas coisas que a decisão pede (falta o
  preço; o kit é criado mas só publica depois do valor).

### Item obrigatório que isso acrescenta ao 175-07

> **O `PainelCriarFase` precisa renderizar os `avisos` da prévia antes do botão
> Confirmar** — todos eles, inclusive `preco_vazio`, lendo `aviso.mensagem` do
> servidor e **nunca** escrevendo a frase no componente. O aviso é informativo:
> o Confirmar fica habilitado. O único bloqueio do painel é `erro_campo`, que
> marca o input da quantidade.

## Item H do briefing — a etapa por querystring

**Confirmado: o editor ACEITA etapa por querystring. Nada foi inventado.**

Medido em `resources/js/Pages/Mlb/Publicador/Editor.jsx` (L62, L69, L81) e
`resources/js/Components/Publicador/apoio.js` (L83-91):

- `const PARAMETRO_ETAPA = 'etapa';`
- `etapaLembrada()` lê `new URLSearchParams(window.location.search).get(PARAMETRO_ETAPA)`
  e passa por `etapaValida()` **antes** do sessionStorage e do `ETAPA_INICIAL` —
  ou seja, a URL tem prioridade sobre a etapa lembrada do produto;
- `ETAPAS` tem exatamente 4 chaves: `produto`, `detalhes`, `imagens`, **`condicoes`**
  (título "Condições de venda"), e `etapaValida()` só devolve chave que existe nessa lista.

Logo a resposta do `fases.criar` é
`route('mlb.anuncios.publicador.editor', ['produto' => $kit->id]).'?etapa=condicoes'`,
que leva o operador direto à etapa Condições de venda, como a §4 pede. **Sem
limitação a registrar.** (Efeito colateral bom: ao abrir, o `guardarEtapa()`
grava `condicoes` no sessionStorage daquele produto, então um F5 continua lá.)

## Decisões Made

Ver `key-decisions` no frontmatter. As três que mais importam para quem vier depois:

1. **`ajustarTitulo()` só no corte.** É o cortador do módulo e foi reaproveitado
   (nenhum cortador novo), mas ele também higieniza (`[^\p{L}\p{N} ]` → espaço).
   Chamá-lo sempre mudaria em silêncio um título que uma pessoa já conferiu na
   Fase 1. Então ele entra só quando `"Kit {N} " + título` passa do
   `max_title_length` — e aí o aviso `titulo_cortado` anuncia.
2. **Soma dos divididos, nunca floor da soma.** Com A=5, B=5 e N=3 o certo é
   1+1=2; `floor(10÷3)` daria 3 — um item fantasma por variante. Tem teste com
   fixture escolhida justamente para as duas contas divergirem.
3. **Nulo ≠ zero no estoque.** Estoque nunca digitado no base vira estoque nulo
   no kit + aviso `estoque_desconhecido`. Zero diria "sem unidades" e reprovaria
   a publicação por um dado que ninguém informou.

## Deviations from Plan

### Divergências deliberadas contra a letra do plano (nenhuma contra a intenção)

**1. [Rule 2 — correção] `maxTitulo()` captura `\Throwable`, não só `RegraViolada`**
- **Found during:** Task 1
- **Issue:** O plano manda `try/catch (RegraViolada)` no `CategorySchemaRepository::obter()`.
  Medido: com a categoria fora do cache e o ML indisponível, o erro que chega
  primeiro é `RuntimeException` de `MlColetaService::66` ("Resposta de token sem
  access_token"), lançada pela camada de token em `ClienteMlPublicador::99` —
  **não** `RegraViolada`. Com o catch estreito, a prévia inteira morria em 500 por
  causa de um número cosmético.
- **Fix:** `catch (\Throwable $e)` com `Log::warning` e fallback 60 + aviso
  `sem_categoria`. Coberto por `test_categoria_ilegivel_nao_derruba_a_previa`.
- **Files:** `app/Services/Publicador/PreviaDaFaseService.php`
- **Committed in:** `c0b07261`

**2. [Rule 1 — correção de precisão] `campo` da recusa 422 vem do contexto, não fixo**
- **Issue:** O plano escreve `'campo' => 'quantidade'` na recusa. Mas o
  `CriarFaseService` já carimba `contexto['campo'] = 'quantidade'` só nas recusas
  que são de campo (KIT-03, KIT-04); KIT-01/KIT-02 são do produto inteiro. Fixo
  em `quantidade`, o painel marcaria o input errado em "este produto já é um kit".
- **Fix:** `'campo' => $e->contexto['campo'] ?? null`.
- **Committed in:** `6591db43`

**3. [Rule 2 — regra que faltava] gate `KIT-05` da Fase 1 publicada**
- **Issue:** O `<behavior>` pede 422 "Publique a Fase 1 primeiro" para base não
  publicado, mas nenhuma das recusas KIT-01..KIT-04 do `CriarFaseService` cobre
  isso (KIT-01 é "sem rascunho", que é outra coisa). O painel só *esconde* o
  botão, e esconder não é impedir.
- **Fix:** gate no controller com `RegraViolada('KIT-05', FamiliaDeFasesService::MOTIVO_FASE_1)`,
  reaproveitando a MESMA constante que a tela usa no `motivo`. `PARTIALLY_PUBLISHED`
  passa (§9). Base **sem** rascunho não cai aqui de propósito: ele segue para o
  KIT-01, cuja mensagem diz o que fazer ("Abra a Fase 1 no editor antes").
- **Committed in:** `6591db43`

**4. Campo `ativa` a mais no mapa de variantes da prévia**
- A forma no plano é `{seller_sku, estoque, depositos}`. Acrescentei `ativa`
  (aditivo, nada removido): o clone copia TODAS as variantes do base — inclusive
  desligadas e órfãs —, então todas precisam de linha no mapa (senão a variante
  do kit nasce com estoque NULL), mas o painel precisa distinguir qual mostrar
  apagada. A mesma distinção é o que faz variante desligada **não** gerar aviso
  de estoque zero.

**5. `estoqueDoKit()` sinaliza o zero do estoque inteiro por `estoque === 0`, não por `zerou`**
- `zerou` guarda só NOMES de depósito de verdade. Produto simples que zera não
  tem nome a colocar ali; quem monta o aviso vê `estoque === 0`. Mantém `zerou`
  honesto (lista de nomes) em vez de aceitar um sentinela vazio.

**Total deviations:** 5 (3 Rule 1/2 — corretude; 2 de forma aditiva).
**Impact:** nenhuma amplia escopo. As três primeiras eram necessárias para a
corretude/segurança; as duas últimas são precisão de contrato.

## Issues Encountered

1. **`Http::assertNothingSent()` estrito não serve em requisição que passa pelo
   middleware web.** Como o briefing avisou: o `HandleInertiaRequests` busca
   `files.ecfconsultoria.com.br/api/v1/signals` em todo request autenticado,
   inclusive nos JSON. O teste unitário (serviço puro) usa o estrito; o teste de
   endpoint usa `Http::assertNotSent(fn ($r) => str_contains($r->url(), 'mercadolibre'))`.
2. **`@dataProvider` em doc-comment gera WARN de deprecação no PHPUnit 11.** Trocado
   por `#[DataProvider]` (as duas convenções existem no repo; a de atributo não
   deixa ruído).
3. **Colisão `pub_produtos.fase` × `estrutura_ofertas.fase`:** nenhum SELECT desta
   plan faz JOIN entre as duas tabelas, então a armadilha não se materializou —
   mas está anotada no docblock da classe para quem acrescentar JOIN depois.

## Como o usuário confere isto (produção, conta #459 "Dev 02 Testes API")

⚠️ **Ainda não há botão na tela — o painel é o `175-07`.** O banco local não tem
empresa com `ml_token`, então a conferência à mão é em produção. Nenhum passo
abaixo cria anúncio no Mercado Livre nem gasta cota: a prévia é leitura pura e a
criação só grava no banco do Publicador.

**Roteiro clicável (prévia — não grava nada):**

1. Abrir `https://admin.ecfconsultoria.com.br/mlb/anuncios/publicador` e escolher
   a conta **#459 "Dev 02 Testes API"**.
2. Clicar num produto que já tenha a **Fase 1 publicada** (cartão "Publicada" ou
   "Parte publicada"). A URL fica
   `…/publicador/empresas/empresa-459/produtos/{ID}` — anotar o `{ID}`.
3. Abrir o DevTools (F12) → aba **Console** e colar:
   ```js
   fetch(location.pathname + '/fases/previa?quantidade=2', { headers: { Accept: 'application/json' } })
     .then(r => r.json()).then(console.log)
   ```
4. **O que tem de aparecer no objeto:**
   - `sku` terminando em **`-KIT2`**;
   - `titulo_por_tipo` com cada título começando em **"Kit 2 "**;
   - `descricao` abrindo com **"Este kit contém 2 unidades de …"**;
   - `variantes` com `estoque` = **metade arredondada para baixo** do estoque do
     produto base (e, se a conta for multidepósito, um `depositos` com cada
     depósito dividido por 2);
   - `avisos` contendo um item com `chave: "preco_vazio"` — **é a decisão de
     2026-10-08 chegando na tela**;
   - `erro_campo` em `null` (ou a frase "Já existe Kit 2 deste produto." se esse
     kit já existir — aí trocar para `quantidade=3` e repetir).
5. Trocar `quantidade=2` por `quantidade=1` e repetir: tem de voltar **422** com
   erro no campo `quantidade` (a regra "um kit tem 2 unidades ou mais").

**Criar de verdade (grava no banco; faça só se quiser ver a fase nascer):**

6. No mesmo Console:
   ```js
   fetch(location.pathname + '/fases', {
     method: 'POST',
     headers: { 'Content-Type': 'application/json', Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content
                  ?? JSON.parse(document.getElementById('app').dataset.page).props.csrf_token },
     body: JSON.stringify({ quantidade: 2, sku: 'SEU-SKU-KIT2' })
   }).then(r => r.json()).then(console.log)
   ```
7. **O que tem de acontecer:** resposta com `produto.id` e `url` terminando em
   **`?etapa=condicoes`**. Abrir essa `url`: o editor do kit abre **já na etapa
   "Condições de venda"**, com os preços em branco (é o esperado) e o estoque
   dividido. Voltar à tela do Produto: a família agora mostra **dois cartões**
   (Fase 1 e Fase 2 · Kit 2).
8. Repetir o passo 6 com a MESMA `quantidade`: tem de voltar **422** com
   `"Já existe Kit 2 deste produto."`, `regra: "KIT-04"` e `campo: "quantidade"`,
   **sem** criar nada.

## Next Phase Readiness

**Pronto para o `175-07` (o painel React):**

- `GET …/fases/previa?quantidade=N` → `{quantidade, sku, titulo_por_tipo, descricao, variantes{chave:{seller_sku, estoque, depositos, ativa}}, avisos[{chave, mensagem}], erro_campo, max_title_length, tipos[]}`.
- `POST …/fases` aceita `{quantidade, sku, titulo?, descricao?, seller_skus?, capa?}` e devolve `{produto:{id}, url, capa_pedida}`.
- O painel deve: chamar a prévia a cada mudança de N (é ela que atualiza SKU,
  título, descrição e estoque "se ainda não editados"), renderizar **todos** os
  `avisos` antes do Confirmar (inclusive `preco_vazio`), tratar `erro_campo` como
  erro do input de quantidade, e **não** mostrar campo de preço.
- `capa_pedida` já volta na resposta; **nenhum job é disparado aqui** — quem
  dispara a geração da capa é o `175-07`/`175-06`.

**Pendências herdadas que este plano NÃO resolve (de propósito):**

- **"Sugerir com IA"** (título + descrição do kit) é outro par de endpoints da §8
  (`POST …/fases/ia`, `GET …/fases/ia/{alvo}`) e não existe ainda.
- A **capa do kit** segue a decisão 1 do `175-DECISOES.md` (DOIS slots,
  `lifestyle` + `hero`), que supera o texto do `175-06` — não foi tocada aqui.
- `REQUIREMENTS.md` da raiz continua parado na v17.0 (memória conhecida): os IDs
  `FASE-04`/`FASE-05` deste plano vivem no `REQUIREMENTS-v*.md` da milestone e
  precisam ser marcados à mão no fechamento da fase.

---
*Phase: 175-publicador-etapa-3-produto-fases · Plano 05*
*Completed: 2026-10-08*

## Self-Check: PASSED

Conferido por reconsulta, não por lembrança:

- `app/Services/Publicador/PreviaDaFaseService.php` — existe, 492 linhas (`min_lines: 150` do plano, atendido)
- `app/Http/Controllers/MlbPublicadorFaseController.php` — existe, 323 linhas, com 4 menções a `CriarFaseService` (o `key_link` do plano)
- `tests/Unit/Publicador/PreviaDaFaseServiceTest.php` — existe, 528 linhas, 28 ✓
- `tests/Feature/Publicador/CriarFaseEndpointTest.php` — existe, 610 linhas, 28 ✓ / 164 asserções
- `routes/mlb_anuncios.php` — as duas rotas nomeadas existem e o teste prova `role:admin`, throttle nomeado e os `where`
- Commits `4ad514a2`, `c0b07261`, `4e119286`, `6591db43` — todos presentes em `git log`
- `tests/{Feature,Unit}/Publicador`: **1021 ✓** (baseline 965, +56, zero regressão)
- `resources/js/`: `git diff --stat` vazio — `usePublicador.js` intocado, nenhum build necessário
