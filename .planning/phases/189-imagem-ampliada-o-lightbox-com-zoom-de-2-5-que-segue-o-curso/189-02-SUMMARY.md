---
phase: 189-imagem-ampliada-o-lightbox-com-zoom-de-2-5-que-segue-o-curso
plan: 02
subsystem: publicador-aprovacao
tags: [lightbox, original-de-referencia, img-13, prop-lightbox, custo-fixo, mutacao]
wave: 2
requires: [189-REGUA.md, 189-CONTRATO-188.md, 189-BASELINES.md, 189-FID01.md, PubImagemReferencia, FotosDoPainelService, PainelDeRevisaoService]
provides:
  - app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php (`para($oferta, $imagens)`)
  - a prop-raiz `lightbox` no `MlbPublicadorAprovacaoController::index`, recortada ao `?oferta=`
  - tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php (9 metodos, 3 ids)
  - tests/gates/contagens-189-02.txt (3 ids, bijecao exata, zero orfao)
affects: [189-05, 189-06, 189-07, 189-08]
tech-stack:
  added: []
  patterns: [uma-fonte-so, lista-constante, isca-nas-duas-direcoes, ancora-por-nome, escopo-do-assunto, custo-fixo-por-whereIn]
key-files:
  created:
    - app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php
    - tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php
    - tests/gates/contagens-189-02.txt
  modified:
    - app/Http/Controllers/MlbPublicadorAprovacaoController.php
decisions:
  - "A prop e `lightbox.originais[<id da revisao>]`, e a forma bate byte a byte com o que a 189-05 esperou"
  - "O estado IMG-13 e LIDO das constantes de PubImagemReferencia — nunca redigitado"
  - "Original de OUTRO rascunho e tratada como AUSENTE, e o id dela nao entra no payload (T-189-10)"
  - "O efeito do EditorEmUso esta declarado por NOME de arquivo e de METODO, nunca por linha"
  - "A regua de 'nada vai ao ML' e HONESTA em duas formas: zero LITERAL no servico, lista CONSTANTE medida na rota"
  - "O criterio de aceitacao `grep -c 'faixa' == 1` do plano e FALSO ja na partida (3 no CRU, 2 sao comentario) — DECLARADO, nao 'ajustado'"
metrics:
  duration: ~3h
  completed: 2026-10-20
  tasks: 2
  commits: 5
  mutacoes: 4
---

# Fase 189 Plano 02: o servidor do lightbox — a original de referência e o estado IMG-13 Summary

O **único dado novo de servidor da Fase 189**: `LightboxDoPainelService::para($oferta, $imagens)`
devolve, por imagem da galeria do painel, se **há original de referência** e a **URL dela pela mesma
rota de arquivo que já está no ar** — e, quando não há, o **estado declarado de IMG-13 lido das
constantes de `PubImagemReferencia`**, nunca redigitado. A prop-raiz `lightbox` entra no
`MlbPublicadorAprovacaoController::index` **recortada ao `?oferta=`**, calculada depois do literal
porque **lê** o `painel` já montado. Nenhum serviço das Fases 186/187/188 foi alterado.

---

## 1 — A PARTIDA, e o que mudou na árvore durante a wave

```
PARTIDA:  2444feea  (2444feea992d340a4653525887899bff0087ed4c)  — capturada na PRIMEIRA ação
          ⚠️ REFERÊNCIA DE LEITURA, nunca extremo de range.
HEAD no fim: e3bd852d
```

⚠️⚠️ **DOIS commits de OUTRA SESSÃO entraram entre a minha PARTIDA e o meu primeiro commit** — e o
segundo encosta em arquivo que é **INTOCÁVEL para mim**, então fica registrado para as waves 189-04 e
189-06:

| commit | assunto | arquivos que interessam às outras waves |
|---|---|---|
| `927117b0` | `fix(publicador): acervo de imagens abre com "Só deste produto" marcado` | `resources/js/Components/Publicador/useAcervoDaConta.js` |
| `31b73ed9` (2026-10-20 11:31) | `fix(publicador): header global cola no topo, sem a faixa de 24px ao rolar` | ⚠️ `header/PublicadorHeader.jsx`, `aprovacao/FiltrosDaFila.jsx`, **`Pages/Mlb/Publicador/Aprovacao.jsx`**, **`tests/js/publicador-fila-zindex-187.test.js`**, `publicador-header-nas-paginas.test.js`, `publicador-header-render.test.js` |

➡️ **Eu não toquei em nenhum deles** (PI no §10 prova). ⚠️ **A 189-04 (ordem de camadas) e a 189-06
(montagem e repasse da prop na página) precisam saber que `publicador-fila-zindex-187.test.js` e
`Aprovacao.jsx` acabaram de mudar por fora da fase.** Medido depois disso: `--suite js`
**5874 / 5873 / 1 / 0** (a única falha é a herdada da Fase 87), e o gate
`187-05 proximo-render` **passa** (`# fail 0`, tests=76).

**Os commits deste plano (escopo do assunto `(189-02)`), em ordem:**

| commit | assunto |
|---|---|
| `95c4c12f` | `test(189-02): os casos da original de referencia e do estado IMG-13 — RED medido` |
| `9dc3b5a4` | `feat(189-02): LightboxDoPainelService — a original de referencia, o estado IMG-13 do MODELO e o custo FIXO` |
| `0efca35e` | `test(189-02): a prop \`lightbox\` na rota, o territorio e a bijecao — RED medido` |
| `33664ef9` | `feat(189-02): a prop \`lightbox\` recortada ao \`?oferta=\`, e a isca da trava que era CEGA` |
| `e3bd852d` | `fix(189-02): a ancora do inline lida SEM comentario — o criterio do plano e FALSO na partida` |

**Arquivos tocados, os ÚNICOS quatro** (PI no §10): `LightboxDoPainelService.php` (novo, 296 linhas),
`LightboxDoPainelServiceTest.php` (novo, 1156 linhas), `contagens-189-02.txt` (novo) e
`MlbPublicadorAprovacaoController.php` (**+30 / −0**).

⚠️ **Os GATES de TDD estão na ordem:** `test(...)` → `feat(...)` em cada uma das duas tarefas, com
o **RED MEDIDO** (não afirmado) em cada uma — ver §3.

---

## 2 — As baselines RE-MEDIDAS

### ⚠️⚠️ A suíte PHP COMPLETA **não é medível nesta máquina** — e isso é medida, não desculpa

`artisan test` / `vendor/bin/phpunit` sobre a suíte inteira **abortam com Fatal** por memória em
`Illuminate\Http\Testing\FileFactory`, em **processo FILHO** (há testes que disparam
`artisan test`/`verificar-testes.sh` como subprocesso, e o filho nasce com o default de 512M), e
`-d memory_limit=-1` **não resolve**. ➡️ **A medida é o RECORTE** —
`tests/Unit/Publicador` + `tests/Feature/Publicador` —, que é exatamente o que
`verificar-testes.sh --suite php` roda. **"0 falhas na suíte PHP inteira" não é um número que esta
árvore produza num comando só, e não foi tentado nem reportado como se tivesse.**

| número | 189-01 (hoje, mais cedo) | 189-02 ANTES | 189-02 DEPOIS | veredicto |
|---|---|---|---|---|
| PHP recorte — testes | 2586 | **2586** | **2595** | — |
| PHP recorte — asserções | 23726 | **23726** | **23877** | ⚠️ asserção **não é invariante** |
| PHP recorte — falhas | **0** | **0** | **0** | a régua é `0` |
| JS — testes / pass / fail / skipped | 5874/5873/1/0 | **5874 / 5873 / 1 / 0** | (intocado: este plano **não toca JS**) | **IGUAL** |

**Comandos:** `sh tests/gates/verificar-testes.sh --suite php` e `--suite js`.

⚠️⚠️ **A falha de 189-01 §3.3 ESTÁ CONSERTADA.** Aquela wave mediu **1 falha** no recorte
(`FotosDoPainelServiceTest::test_a_galeria_final_ordenada_traz_url_do_arquivo_nos_DOIS_casos_188_03`,
pelo ponteiro de LINHA do `PreparoIaDoRascunhoService`). O commit **`2444feea`** — a minha PARTIDA —
reancorou aquele gate por **NOME**, e o recorte mediu **0 falhas** no começo desta wave.
Reconferido por gate: `188-03 galeria-e-marcadores` → **3 passed (91 assertions)**.

### As falhas conhecidas, nomeadas e atribuídas por execução ISOLADA — **nenhuma corrigida**

| falha | atribuição ISOLADA | veredicto |
|---|---|---|
| JS, a única: `tests/js/estrutura-grade-glide.test.js`, caso *"Características secundárias nasce recolhido (é o grupo que mais infla)"* (Fase 87) | `node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js` → **# tests 19 · # pass 18 · # fail 1 · # skipped 0** | pré-existente, é o `REF_JS_FALHAS=1` cravado no harness. **NÃO corrigida** |
| `tests/Feature/Publicador/CockpitDaContaTest.php` (Fase 179, fronteira de dia) | **não apareceu** nesta medição (oscila com a hora do dia) | não é desta fase |
| FORA do recorte: `Tests\Unit\CalcularFaixaTest` (9), `CompanyServiceTypeTest::test_service_type_aceita_polo` (1), `Phase39\MercadoLivreSugadoresProviderTest` (2) | **fora do recorte** — não medidas aqui | não são desta fase. **NÃO corrigidas, NÃO contadas como regressão, e `REF_PHP_FALHAS` NÃO foi criado** |

⚠️ **Ressalva honesta sobre a medição ANTES:** o `--suite php` de abertura foi disparado **antes** de
eu criar o `LightboxDoPainelService.php`, mas o arquivo (ainda untracked) estava no disco durante
parte da execução. Isso **não muda o veredicto**, e a prova não é argumento: os gates que varrem
`app/` inteiro foram rodados **explicitamente** depois (`186-09 andamento-um-lugar-so` 1/20,
`186-06 dado-15-sem-escrita` 1/14, `186-09 transversais` 12/258 — todos **inalterados**).

---

## 3 — O RED de cada tarefa, MEDIDO

⚠️ **Não é "fiz TDD": é o número.** O serviço foi **movido para o scratchpad** (e restaurado **DA
CÓPIA**, `git checkout` **nunca**) para medir o vermelho:

```
sem o serviço no disco:
  original-de-referencia   -> Tests: 3 failed (2 assertions)   BindingResolutionException
  estado-img13-e-recorte   -> Tests: 3 failed (0 assertions)   BindingResolutionException
com o serviço:
  original-de-referencia   -> Tests: 3 passed (45 assertions)
  estado-img13-e-recorte   -> Tests: 3 passed (32 assertions)

tarefa 2, com o controller AINDA sem a prop:
  prop-lightbox-e-sem-http -> Tests: 3 failed (10 assertions)
com a prop:
  prop-lightbox-e-sem-http -> Tests: 3 passed (75 assertions)
```

---

## 4 — ⚠️⚠️ A PROP QUE EU ENTREGO: o NOME, a FORMA, e a confirmação de que bate com a 189-03/189-05

**Nome:** prop-raiz **`lightbox`**. **Forma:**

```php
['originais' => [ <id da revisão (pub_aprovacao_imagens.id)> => <bloco>|null ]]

// upload (`de_ia !== true`)        -> null
// IA COM vínculo utilizável        -> ['disponivel' => true,  'url_arquivo' => ?string, 'quantas' => int]
// IA SEM vínculo utilizável        -> ['disponivel' => false, 'estado' => 'sem_original_de_referencia',
//                                      'rotulo' => …, 'apoio' => …, 'icone' => 'image-off',
//                                      'url_arquivo' => null]
```

### A confirmação, por leitura do plano consumidor

⚠️ **A wave 189-03 (que roda em paralelo) NÃO consome esta prop** — medido: `grep` de
`originais`/`lightbox.originais` em `189-03-PLAN.md` devolve **zero**, e `189-03-SUMMARY.md` **ainda
não existe** no disco (conferido). Quem consome é a **189-05** (a lateral) e a **189-06** (o
contexto).

**`189-05-PLAN.md:76`-`:80`, lido por `sed`:**

```
// O dado da original — `lightbox.originais[<id da imagem>]` (LightboxDoPainelService, 189-02):
//   null  → imagem Original (sem bloco)
//   { disponivel: true,  url_arquivo: ?string, quantas: int }
//   { disponivel: false, estado: 'sem_original_de_referencia', rotulo, apoio, icone: 'image-off', url_arquivo: null }
//   ⚠️ a forma EXATA é a da 189-02-SUMMARY — se divergir desta, a SUMMARY ganha.
```

➡️ **BATE byte a byte** — as três formas, os nomes das chaves, o valor literal de `estado` e de
`icone`. **Não houve divergência, então não houve nada a devolver.** E o `189-06-PLAN.md:185` lê
`{ oferta_id, versao, ao_vivo, imagens, originais }` — a chave `originais` é a minha.

⚠️ **A única nota de vocabulário:** a 189-05 escreve *"`<id da imagem>`"* e eu escrevo *"id da
revisão"*. **É o MESMO número** — `painel.fotos.imagens[*].id` é `pub_aprovacao_imagens.id` —, e a
189-05 indexa exatamente por esse campo (`189-05-PLAN.md:113`: *"`originais[id] === null`"*). Fica
declarado para ninguém procurar `pub_imagens.id` ali.

### INV-13 conferido antes de acrescentar prop-raiz

O `189-CONTRATO-188.md` INV-13 mediu que **não existe** asserção de conjunto FECHADO das chaves-raiz
desta página. Reconferido por mim: a única `array_keys($props` de
`tests/Feature/Publicador/Aprovacao/` é de `AquecerTarifasDaFilaTest` (tela de **Publicações**) e usa
`assertArrayHasKey` (conjunto **ABERTO**). ➡️ **Acrescentar `lightbox` não quebra conjunto fechado
nenhum, e não houve o que PARAR e devolver.**

### ⚠️ A forma do JSON é OBJETO, não lista — e há gate

As chaves são ids (**≥ 1**, nunca `0`), então `json_encode` emite `{"7":…}`. Se virasse **lista
posicional**, a tela leria `originais[0]` e pegaria o bloco da foto **errada**. Há asserção cobrando
`assertStringStartsWith('{')` **e** `assertNotContains(0, array_keys(...))`.

---

## 5 — A URL da original: a rota REUSADA, e `url_arquivo = null` testado nos DOIS casos

**Ponteiros reconfirmados por `grep -n`/`sed` nesta wave** (a notação de dois-pontos de OUTRO arquivo
vai como `arquivo:N`):

| ponteiro | medido agora | o que o plano/briefing dizia |
|---|---|---|
| `routes/mlb_anuncios.php:158` — `Route::prefix('publicador/produtos/{produto}')` | **158** | o plano diz `:157` → **divergência de VALOR**, declarada (o docblock do `FotosDoPainelService` já diz `158`, e está certo) |
| `routes/mlb_anuncios.php:178` — `/fotos/{imagem}/arquivo` | **178** | OK |
| `app/Http/Controllers/MlbPublicadorController.php:212` — `arquivoFoto` | **212** | OK |
| `app/Http/Controllers/MlbPublicadorController.php:405` — `EditorEmUso::marcar` | **405** | OK |
| `config/publicador.php:148` — `'editor_em_uso_min' => 3` | **148** | OK |
| `app/Models/PubImagemReferencia.php:130` — `originaisDe` | **130** | OK |
| `app/Services/.../FotosDoPainelService.php:548` (`imagem()`), `:608` (`urlDoArquivo()`) | **548 / 608** | OK |
| `MlbPublicadorAprovacaoController.php` — payload `:365`→**`:369`**, `'painel'` `:483`→**`:487`** | **+4** | ⚠️ **deslocaram pela MINHA edição** (4 linhas na assinatura): a injeção está em `:199`, o `exibidas` em `:503` e a prop em `:529`. Registrado para o 189-05/189-06 **não** usarem os números do INV-13 |

**A URL:** `route('mlb.anuncios.publicador.fotos.arquivo', ['produto' => $oferta->produto_id,
'imagem' => <id da ORIGINAL>])`. O teste **não** compara com string escrita à mão: compara com o
`route()` montado no próprio teste.

**Os DOIS casos de `url_arquivo`, testados:**

| caso | resultado | asserção |
|---|---|---|
| original **com** `caminho` | a URL da rota, com o id da ORIGINAL | `assertSame(route(...), $duas['url_arquivo'])` |
| original com `caminho === null` | **`disponivel: true` e `url_arquivo: null`** | `assertNull(...)` **mais** `assertNotSame('', ...)` — `<img src="">` recarrega a própria página |

⚠️ **E a prova de que NUNCA é a imagem de IA:** a URL da revisão de IA **existe** na fixture (isca
cega: `assertNotNull($daRevisao)` — sem ela "diferente" não provaria nada) e é **diferente**; o id no
parâmetro `imagem` é o da ORIGINAL. A **mutação (i)** confirma: trocar `imagemOriginal` por `imagem`
mata o gate em `LightboxDoPainelServiceTest.php:424`.

⚠️ **REUSAR NÃO É EDITAR:** `App\Support\Publicador` (inclui `ArquivosDeImagem` e `EditorEmUso`),
`Acervo*` e `ImagemAssetService` ficam **intocados** — o R2 virou **privado em produção em
16/10/2026** e o bucket **não serve URL pública**; a rota serve **BYTES**. Provado pela **PI(189-02)**
(§10) e por sonda de território no próprio gate.

---

## 6 — ⚠️⚠️ O efeito do `EditorEmUso`, DECLARADO — por NOME, nunca por linha

O docblock do serviço declara, por extenso: **abrir a original no lightbox SUSPENDE o agendamento e
o preparo por IA daquele produto por 3 minutos**, porque a rota passa por
**`EditorEmUso::marcar`** (em **`MlbPublicadorController`**, método **`produto`**), e os **3 minutos**
vêm de `config/publicador.php`, chave `publicador.preparo_ia.editor_em_uso_min` (valor resolvido
conferido por asserção: **3**).

**Os CINCO arquivos leitores, por NOME de arquivo e de MÉTODO** (é a contagem que este projeto usa
desde o gate da 188-03: cinco **arquivos**, seis pontos de chamada):

| # | arquivo | método | o que acontece |
|---|---|---|---|
| 1 | `AgendadorDaFila` | `checar()` | `EditorEmUso::emUso` devolve *"esperar"*: o produto não é agendado nesta passada |
| 2 | `PreparoIaDoRascunhoService` | `preparar()` | o preparo por IA do lote pula o produto |
| 3 | `PreparoIaDoRascunhoService` | `sincronizarAgora()` | idem, **no segundo laço** |
| 4 | `PreparoIaDoRascunhoService` | `executarEtapa()` | idem, **na escrita do rascunho** |
| 5 | `GarantiaPadrao` | `aplicarNaConta()` | a garantia padrão não é escrita por baixo de quem edita |
| 6 | `ExcluirProdutoService` | `impedimento()` | a exclusão do produto espera |

**Razão do usuário (19/10/2026): *é o que o portão quer*** — `AgendadorDaFila` diz, no próprio
comentário, *"Gente com o editor aberto: não publica por baixo dela"*, e revisar é trabalhar no
produto. **"Servir a foto sem marcar" foi oferecido e RECUSADO** (exigiria rota nova sobre
`App\Support\Publicador`, território de outra sessão).

### ⚠️ O gate que cobra isso, e por que ele é EXPLICITAMENTE ancorado por NOME

- lista **CONSTANTE** de **18** nomes/fatos, **UMA** asserção coletando os ausentes, mais
  `assertCount(18, $deveNomear)` como **isca do recorte** (tirar um nome só AFROUXA o gate);
- **isca nas duas direções em memória:** o docblock completo PASSA; a cópia mutilada
  (`AgendadorDaFila` → `o agendamento`) REPROVA;
- **anti-fantasma por NOME:** os 5 arquivos são lidos **sem comentário** e exige-se que cada um
  contenha `EditorEmUso::` **e** o método nomeado;
- ⚠️⚠️ **sonda que RECUSA ponteiro de LINHA voltar ao docblock**
  (`/(AgendadorDaFila|…|FotosDoPainelService)(\.php)?:\d+/` → **0**), com isca provando que ela acha
  a forma proibida (`'veja PreparoIaDoRascunhoService:131 ali'` → **1**);
- **isca negativa que mede o que importa:** o próprio serviço **cita** `EditorEmUso::marcar` em
  prosa e **não chama nada** — `assertStringNotContainsString('EditorEmUso::', semComentarios(...))`.
  É a prova de que a leitura sem comentário é **carga**, não zelo.

⚠️ **E este é o ÚNICO gate deste plano que LÊ COMENTÁRIO DE PROPÓSITO** — o docblock **é** o artefato
sob teste. A inversão está **declarada** no arquivo de teste e no manifesto: *"consertá-lo" para
`semComentarios()` faria o gate medir NADA*.

⚠️ **Por que nenhum número de linha:** a redação equivalente da 188-03 fixava
`PreparoIaDoRascunhoService:131` e **reprovou trabalho correto** em 20/10/2026 — o commit `cd582f83`,
de outra sessão, acrescentou uma linha acima e as três chamadas desceram para **132/181/367**. **Não
voltei nada para número.**

---

## 7 — O ordinal e x/y: LIDOS do servidor, não renumerados

⚠️ Este plano **não toca** `marcadores` nem `verificacoes`: eles vêm de
`FotosDoPainelService::para()` (188-03) e **são repassados pelo `painel`**, intactos. A normalização
do ordinal (`PubAprovacaoImagem::alertas()` sem `orderBy`, que dava ordens **diferentes** em MariaDB e
SQLite) vive naquele serviço e foi **CHAMADA, nunca reimplementada** — `LightboxDoPainelService` não
tem a palavra `ordinal` nem `marcador`. **x/y continuam PERCENTUAL (0,00–100,00), saneados a 0–100**
pelo serviço da 188: teto `decimal(5,2)` = 999,99, e estourar dá erro 1264 no MariaDB que o SQLite dos
testes **não** pega. **Nada aqui renumera nada.**

➡️ **Quem desenha o ordinal na lateral é o 189-05**, que o lê de `item.ordinal` (INV-07: é named
export e **nunca recontado**).

---

## 8 — Escrita: **este plano não escreve nada**

Não há aprovar/reprovar aqui — a rota que eu edito é de **leitura** (`index`). Portanto:

- **nenhuma chamada a `escreverNoPainel`** e **nenhuma rota nova**: Aprovar/Reprovar foto do lightbox
  reusam as rotas do 188-05, e isso é da **189-07** (que chamará `escritasDaColuna({empresaId,
  ofertaId, versao, onConflito})` — INV-03/A6 mediu que ela já recebe oferta e versão **por
  parâmetro**, o que resolve a questão 189-B sem helper novo);
- **`versao_esperada`** continua sendo responsabilidade do helper único — e **não foi refatorado**
  (decisão registrada: `recusaDoPainel` tem 18 chamadas);
- gate provando **nenhuma forma de ESCRITA** no serviço
  (`create|insert|updateOrCreate|…|save|delete|fill` → **0**), com isca provando que a sonda acha o
  caso canônico;
- **`pub_imagem_referencias` não é escrita**: `186-06 dado-15-sem-escrita` → **1 passed (14
  assertions)**, inalterado;
- **nenhuma chave de andamento** no payload: `grep -v '^\s*\*' <servico> | grep -c "'andamento'"` →
  **0**, e `186-09 andamento-um-lugar-so` → **1 passed (20 assertions)**, **inalterado** (o serviço
  **não** entrou na lista de EXPOSITORES, e não precisava).

**APROVAR ENFILEIRA; a escrita no Mercado Livre é da Fase 193** — declarado no docblock. **A Fase 192
("Gerar novamente com IA") não foi antecipada** (gate de ausência das quatro formas de segundo passo,
com isca de cada forma).

---

## 9 — ⚠️ A prova de que nada vai ao ML, **na forma HONESTA** (e as duas iscas)

⚠️⚠️ **Prometer zero absoluto na rota seria um gate que o desenho contradiz** — abrir o painel **LÊ a
tarifa real** (`BloqueiosDaOfertaService::para()` → `/sites/MLB/listing_prices`), decisão do usuário de
15/10/2026. ➡️ A régua tem **duas formas, as duas medidas:**

| forma | o que é provado | isca |
|---|---|---|
| **zero LITERAL no serviço** | `LightboxDoPainelService::para()` sozinho → gravador **vazio** | uma requisição **DELIBERADA** (`Http::get('https://api.mercadolibre.com/items/MLB1')`) **TEM** de aparecer — e aparece |
| **lista CONSTANTE na rota** | os caminhos do ML na requisição com `?oferta=` são **exatamente** `['/sites/MLB/listing_prices']`, **medido** | `assertCount(1, $permitidos)` (a lista não encolhe em silêncio) + endpoint NOVO REPROVA |

### ⚠️⚠️ ACHADO: a minha PRIMEIRA ISCA da trava de rede era **CEGA** — e o vermelho a pegou

Eu tentei provar `preventStrayRequests()` re-faqueando o facade
(`Http::fake(['api.mercadolibre.com/*' => …])`) e esperando `StrayRequestException` numa URL não
faqueada. **Não lançou.** Razão de desenho do framework: **`Factory::fake()` ACRESCENTA stubs, não os
substitui** — o callback catch-all do `setUp()` continua casando tudo. ➡️ Aquela "isca" provava
**NADA**. A forma que mede, agora no arquivo com a explicação para ninguém a reescrever:

1. **(a)** `Http::preventingStrayRequests()` do facade **REAL** é `true` — é a trava deste arquivo;
2. **(b)** o **mecanismo LANÇA**, numa `Factory` **NOVA** (sem o catch-all): a URL **faqueada** devolve
   **200** (isca cega — senão a trava reprovaria pelo motivo errado) e a **não faqueada** lança
   `StrayRequestException`. ➡️ **LANÇOU.**

---

## 10 — PI(189-02): o território por ESCOPO DO ASSUNTO, com o LIMITE declarado

⚠️ **`git diff --numstat` "sai vazio" é PROIBIDO como gate** (depois do commit ele compara a ÁRVORE e
sai vazio SEMPRE). A prova é a receita da `189-REGUA.md` §5, por script
(`scratchpad/pi-189-02.sh`). **Saída colada:**

```
=== (1) test -e em CADA alvo (anti-fantasma) ===
  OK   existe: app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php
  OK   existe: app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
  OK   existe: app/Models/PubImagemReferencia.php
  OK   existe: routes/mlb_anuncios.php
  OK   existe: app/Http/Controllers/MlbPublicadorController.php
  OK   existe: app/Support/Publicador
  OK   existe: app/Services/Creative
  OK   existe: .planning/ROADMAP.md
  OK   existe: .planning/REQUIREMENTS-v27.md
  OK   existe: .planning/STATE.md

=== (2) os commits do escopo (189-02) (por ASSUNTO) ===
  e3bd852d
  33664ef9
  0efca35e
  9dc3b5a4
  95c4c12f
  total=5
  (os que casam SO pelo corpo, informacao):

=== (3) PASSADA 1: git show --stat por commit ===
  app/Http/Controllers/MlbPublicadorAprovacaoController.php
  app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php
  tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php
  tests/gates/contagens-189-02.txt

=== (4) PASSADA 2: git log --name-only de uma vez (so os do assunto) ===
  OK   as duas passadas dao o MESMO conjunto

=== (5) INTERSECAO com os alvos (tem de ser VAZIA) ===
  OK   intersecao VAZIA

=== (6a) ISCA: o escopo (188-03) sobre .../FotosDoPainelService.php tem de ACUSAR ===
  OK   a sonda ENXERGA (commits=6)

=== (6b) ISCA: nenhum commit do MEU escopo tem assunto de outra fase ===
  OK   o recorte nao vaza para outra sessao

RESULTADO: falhas=0
```

### ⚠️⚠️ O LIMITE DO ESCOPO, **medido por mim**, não herdado do briefing

`git log --grep` casa a **MENSAGEM INTEIRA** (assunto **+ corpo**) — então ele **SOBRE-captura**.
Medido nesta wave com `(186-01)`:

```
SO-CORPO 1c4ff10f test(189-01): a mutacao SOBREVIVENTE investigada — ...
SO-CORPO b73819c5 fix(189-01): a PI do bloco 3 recorta por ASSUNTO — ...
SO-CORPO e1fa96d3 docs(188-13): a SUMMARY do fechamento -- ...
SO-CORPO 90dfa946 docs(188-13): o fechamento medido -- ...
SO-CORPO 1d34e0da docs(186): as tres respostas do usuario nos planos 01, 07 e 09
ASSUNTO  091340b6 / 010e87f6 / 28b45b40 / 060aacdc / da26aa08 / 6e9c5149  (os 6 reais)
```

➡️ **CINCO commits casam `(186-01)` só pelo CORPO**, dois deles com assunto `docs(188-13)`. Sem o
recorte por **ASSUNTO** (que a wave 189-01 consertou em `b73819c5`), bastaria uma fase futura
**citar** `(189-02)` no corpo para esta PI reprovar trabalho correto.

⚠️ **E ele também tem o lado oposto:** o recorte por assunto **não vê** um commit que tocou meus
arquivos sem carregar `(189-02)` no assunto (erro de digitação, ou outra sessão), e **não** distingue
autor (as três máquinas rodam com o mesmo `user.name` `MB.ECF-100376`, então `%an` é vazio por
construção). ➡️ **É melhor que range — e range cru não passa nesta árvore —, mas NÃO é perfeito.
Fica declarado.**

⚠️ **Hoje `commitsSoNoCorpo('(189-02)')` é VAZIO** (nenhuma sobre-captura no meu escopo). Isso é
estado de hoje, **não** garantia: é por isso que o recorte por assunto fica.

---

## 11 — Os gates: os 3 ids deste plano e as 7 não-regressões

⚠️ **1º argumento `189-02`** (o número nu `02` resolveria `contagens-186-02.txt`, a fase ERRADA, em
silêncio).

| id | filtro | medido |
|---|---|---|
| `189-02 original-de-referencia` | 3 métodos | **3 passed (45 assertions)** |
| `189-02 estado-img13-e-recorte` | 3 métodos | **3 passed (32 assertions)** |
| `189-02 prop-lightbox-e-sem-http` | 3 métodos | **3 passed (75 assertions)** |

**Não-regressão de manifesto ALHEIO — todas com a contagem que o manifesto DELAS declara, nenhuma
remedida para caber:**

| id | medido | declarado |
|---|---|---|
| `186-09 andamento-um-lugar-so` | 1 passed (20 assertions) | `tests=1 assertions=20` ✓ |
| `186-09 transversais` | 12 passed (258 assertions) | `tests=12 assertions=258` ✓ |
| `186-06 dado-15-sem-escrita` | 1 passed (14 assertions) | `tests=1 assertions=14` ✓ |
| `188-02 painel-na-rota` | 2 passed (41 assertions) | `tests=2 assertions=41` ✓ |
| `188-02 custo-do-painel` | 2 passed (24 assertions) | `tests=2 assertions=24` ✓ |
| `188-03 galeria-e-marcadores` | 3 passed (91 assertions) | `tests=3 assertions=91` ✓ |
| `187-04 pagina-rota` | 6 passed (185 assertions) | `tests=6 assertions=185` ✓ |
| `187-05 proximo-render` (JS — lê o TEXTO do controller) | `# fail 0`, tests=76 | `tests=76` ✓ |

### `--autoteste`, COLADO

```
== JS: saida REAL de 'node --test --test-reporter=tap' ==
  OK   caso 1: 2 verdes, N=2 -> PASSA (como esperado)
  OK   caso 2: 1 de 2 falhando -> REPROVA (como esperado)
  OK   caso 3: 2 verdes mas N=344 (arquivo nao carregou) -> REPROVA (como esperado)
  OK   caso 4: ISCA >=: 2 verdes com >=2 (igual ao minimo) -> PASSA (como esperado)
  OK   caso 5: ISCA >=: 2 verdes com >=1 (acima do minimo, teste novo so soma) -> PASSA (como esperado)
  OK   caso 6: ISCA >=: 2 verdes com >=5 (encolheu ou nao carregou) -> REPROVA (como esperado)
  OK   caso 7: ISCA >=: falha nao e perdoada pelo >= (o '# fail 0' vem ANTES) -> REPROVA (como esperado)
  OK   caso 8: ISCA G1: 2 verdes com reporter PADRAO (sem tap) -> REPROVA (como esperado)
== PHP: shape ANSI REAL do Collision, so a linha 'Tests:' variada ==
  OK   caso 9: Tests: 1 passed (22 assertions), N=1 A=22 -> PASSA (como esperado)
  OK   caso 10: Tests: 2 failed, 1 passed (9 assertions) -> REPROVA (como esperado)
  OK   caso 11: Tests: 1 error, 1 passed (3 assertions) -> REPROVA (como esperado)
  OK   caso 12: Tests: 1 passed (5 assertions) com A=22 esperado -> REPROVA (como esperado)
  OK   caso 13: No tests found. -> REPROVA (como esperado)
  OK   caso 14: ISCA ANSI: o caso que passa limpo, SEM limpar_ansi -> REPROVA (como esperado)
== MANIFESTO: a resolucao por fase (187-NN) e a compatibilidade com a 186 ==
  OK   caso 15: '187-01' resolve contagens-187-01.txt E o arquivo existe -> PASSA (como esperado)
  OK   caso 16: ISCA: '01' e '186-01' no MESMO caminho (186), e '187-01' no OUTRO -> PASSA (como esperado)
== MANIFESTO: incompleto tem de ABORTAR ==
  OK   caso 17: manifesto 'g js a.test.js :: tests=0' -> ABORTOU (como esperado)
  OK   caso 18: manifesto 'g js a.test.js ::' -> ABORTOU (como esperado)
  OK   caso 19: manifesto 'g php test_x :: tests=1' -> ABORTOU (como esperado)
  OK   caso 20: id ausente no manifesto -> ABORTOU (como esperado)
---
AUTOTESTE: 20 casos, 0 divergencia. As duas formas REPROVAM quando devem.
```

---

## 12 — Bijeção (com o EXPANSOR) e homônimo

**ZERO id órfão, ZERO método sem id**, provado **por script dentro do próprio gate**
(`test_territorio_e_bijecao_do_manifesto_do_lightbox_189_02`), nos dois sentidos, com **UMA** asserção
por sentido coletando os infratores. Mapa:

```
tarefa 1 -> original-de-referencia · estado-img13-e-recorte
tarefa 2 -> prop-lightbox-e-sem-http
```

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA, e ele não é zelo — é o furo medido pela 189-01:**
`/test_[A-Za-z0-9_]+/` casa **ZERO** num filtro `test_(a|b)` (depois de `test_` vem `(`), e foi isso
que produziu `SEM_ID=14` **acusando trabalho correto**. O gate tem isca nos dois lados:

```
expandir('test_(a_189_02|b_189_02)')                        -> ['test_a_189_02','test_b_189_02']
preg_match_all('/test_[A-Za-z0-9_]+/', 'test_(a|b)')        -> 0
```

E cobra, por id: **no máximo TRÊS métodos** e `tests=` **igual** à contagem de métodos.

**HOMÔNIMO — medido por script, nas DUAS direções, incluindo PREFIXO, contra todo `test_*` de
`tests/`:**

```
ARQUIVOS=1226  METODOS=7885  MEUS=6 (na medição da tarefa 1; 9 no fim)  HOMONIMOS=0  PREFIXOS=0
ISCA (a sonda VÊ): test_territorio_e_ausencias_do_plano => 7 métodos REAIS
     _187_06, _187_07, _187_08, _187_09, _187_10, _188_02, _188_03
CONTROLE: test_metodo_que_ninguem_escreveu_189_02 => 0
```

⚠️ **Sem a isca, `HOMONIMOS=0` seria indistinguível de sonda cega.** A varredura **roda em TODA
execução do gate** (não é medição de uma vez só), e o conserto de colisão seria **sufixo no método
NOVO**, nunca levantar contagem alheia.

---

## 13 — A campanha de mutação (`mutar-189-02.sh`) — 4 de 4 MORRERAM, e **no lugar certo**

⚠️ **Nome com o plano** porque o scratchpad é compartilhado com a 189-03 (na Fase 187 um script de
wave foi **sobrescrito** e a campanha foi refeita inteira). Receita cumprida: commit ANTES, `cp -p`,
ocorrência **travada em 1** por `substr_count` (não `grep -c`, que conta LINHAS), linha conferida,
aplicação provada por **divergência de sha256**, `php -l`, restauração **DA CÓPIA** em `trap EXIT`,
`sha256` final contra `git show HEAD:` com `tr -d '\r'`. **`git checkout` nunca.**

```
=== PRISTINO ===  A: 3 passed (45)   B: 3 passed (32)   C: 3 passed (3 passed (75))

(i)   $linha->imagemOriginal -> $linha->imagem        linha 223  sha 4d34eb24->7d303291
      Tests: 2 failed, 1 passed (25 assertions)  total=3  -> MORREU
(ii)  if ($rascunhoId === null || ...) -> if (false)   linha 231  sha 4d34eb24->4771b11f
      Tests: 1 failed, 2 passed (24 assertions)  total=3  -> MORREU
(iii) SEM_ORIGINAL_ROTULO -> literal com letra trocada linha 270  sha 4d34eb24->6cbfc104
      Tests: 1 failed, 2 passed (21 assertions)  total=3  -> MORREU
(iv)  ? null : -> ? ['originais' => []] :             linha 529  sha 0aba0625->178d4640
      Tests: 1 failed, 2 passed (61 assertions)  total=3  -> MORREU

RESULTADO DA CAMPANHA: sobreviventes/erros=0
[trap] OK  LightboxDoPainelService.php identico ao HEAD
[trap] OK  MlbPublicadorAprovacaoController.php identico ao HEAD
```

⚠️⚠️ **O TOTAL do mutado é IGUAL ao do pristino (3 em cada)** — sem isso o vermelho poderia ser
"o arquivo não carregou", que não prova nada.

### ⚠️ A INVESTIGAÇÃO DE **ONDE** cada mutação morreu — a lição da wave 1

A wave 189-01 mediu que uma mutação pode **morrer pela asserção ERRADA** e assim **não provar nada**.
Então eu reapliquei cada uma isolada e li a linha do veredicto:

| mutação | morreu em | é a asserção certa? |
|---|---|---|
| (i) | `LightboxDoPainelServiceTest.php:424` (*"a URL não é a da PRIMEIRA original na ordem de `posicao`"*) **e** `:525` (*"o `whereIn` divergiu de `originaisDe()`"*) | **SIM** — é o invariante IMG-13 + a igualdade das duas leituras |
| (ii) | `:751` — `assertFalse($bloco['disponivel'])`, *"a original de OUTRO rascunho virou bloco disponível"* | **SIM** — é T-189-10 |
| (iii) | `:694` — o `assertSame` da forma inteira contra as **constantes do modelo** | **SIM** — é "uma fonte só" |
| (iv) | `:876` — `assertNull($semOferta['lightbox'])`, *"montada SEM `?oferta=`"* | **SIM** — é o recorte |

**Nenhuma sobrevivente, e nenhuma morte no lugar errado.**

---

## 14 — ⚠️⚠️ DIVERGÊNCIAS, com `arquivo:linha`

### D1 — ⚠️ O critério de aceitação do plano sobre o `inline` é **FALSO NA PARTIDA**

`189-02-PLAN.md` (acceptance da tarefa 2) cobra:

> `grep -c "'faixa' => \$fila->faixa(\$base)" app/Http/Controllers/MlbPublicadorAprovacaoController.php` devolve **1**

**Medido: 3 — e era 3 no commit de partida `2444feea`, antes desta wave.**

| ocorrência | o que é |
|---|---|
| `app/Http/Controllers/MlbPublicadorAprovacaoController.php:280` | **COMENTÁRIO** (wave 187) |
| `:304` | **COMENTÁRIO** (*"a chamada fica ESCRITA como …"*, wave 187) |
| `:385` (hoje `:389` depois da minha edição) | **o CÓDIGO** — o único que importa |

➡️ É a família *"comentário que explica X casa com o gate que conta X"* (a mesma que reprovou 5 dos
12 planos da Fase 184): **o `grep` cru é mais largo que o invariante e nenhum estado desta árvore o
satisfaz.** Medido **sem comentário**: **1 e 1** (idem para `'proximo_a_revisar' =>
$proximo->para($base)`).

⚠️⚠️ **E o meu PRÓPRIO gate tinha a mesma fragilidade, em direção pior:** eu havia usado
`preg_match(...) === 1`, que devolve 1 ao **primeiro** casamento — ele passaria **mesmo se o código
inline fosse removido**, porque os dois comentários contêm o texto. **Consertado**: lido **sem
comentário** com `substr_count(...) === 1`, mais **isca do leitor** cobrando que o CRU tenha os
**3**. ➡️ **Não "ajustei" o critério do plano para passar: declarei que ele é falso e provei o
invariante de verdade.**

### D2 — `routes/mlb_anuncios.php`: o prefixo está em **`:158`**, não `:157`

O `189-02-PLAN.md` (`<interfaces>`) e a `189-REGUA.md` §3 dizem `routes/mlb_anuncios.php:157`.
**Medido: `158`.** (O docblock do `FotosDoPainelService` já diz `158` e está certo.) Classe:
**VALOR**.

### D3 — os ponteiros do INV-13 **deslocaram pela minha edição** (+4)

`189-CONTRATO-188.md` INV-13 diz payload em `MlbPublicadorAprovacaoController.php:365` e `'painel'`
em `:483`. **Hoje: `:369` e `:487`** (a assinatura ganhou 4 linhas com a injeção). Para o 189-05/06:
injeção em **`:199`**, `exibidas` em **`:503`**, a prop em **`:529`**. Classe: **VALOR** (causada por
mim, declarada).

### D4 — o docblock da 188-03 fala de *"CINCO leitores"* e lista **SEIS** entradas

`FotosDoPainelService.php` (docblock da PERGUNTA-188-C) escreve *"os **CINCO leitores**"* e numera
**1..6**. **Medido:** são **5 ARQUIVOS** e **6 pontos** de `EditorEmUso::emUso` (`AgendadorDaFila` 1,
`PreparoIaDoRascunhoService` 3, `GarantiaPadrao` 1, `ExcluirProdutoService` 1), e o gate da 188-03
cobra exatamente `assertCount(5, $sinalPorArquivo)` + `assertSame(8, …)` pontos (os 6 de `emUso` + o
`marcar` + o `chave` do `Cache::forget`). ➡️ **Não é defeito: é a contagem por ARQUIVO.** Reproduzi a
mesma contagem e a mesma redação para as duas pontas não divergirem. Classe: **REFERÊNCIA** (do
documento alheio), **não corrigida** — `app/` da 188 não é território deste plano.

### D5 — achado do meu próprio teste: `json_encode` **escapa a barra**

`assertStringNotContainsString('/fotos/N/arquivo', json_encode(...))` **nunca casaria**, porque o JSON
cru traz `\/fotos\/N\/arquivo`. ➡️ Era **gate vazio por construção**, e foi a **isca do mesmo lado**
(a original legítima TEM de aparecer) que reprovou e achou. Consertado com
`JSON_UNESCAPED_SLASHES` **mais** uma sonda **estrutural** (nenhuma `url_arquivo` do payload aponta
para a original alheia).

### D6 — `new_layout_publicador/Aprovacao.dc.html` não existe; `design_handoff_publicador/` não foi lido

Este plano é **só de servidor**: nenhum gate meu depende do protótipo, então **não houve `skip` a
nomear**. O arquivo `design_handoff_publicador/Aprovacao.dc.html` existe (1432 linhas, sha256
`68624883ce35547e`, medido pela 189-01) e **não foi tocado**.

---

## 15 — As listas constantes que eu **NÃO** mexi, com a razão de cada uma

| lista | razão de não mexer |
|---|---|
| `ARQUIVOS_NOVOS` (`tests/js/publicador-vocabulario-novo.test.js`) | este plano **não cria `.jsx`** — é só PHP. O GATE-03 compara por `deepEqual` os `.js`/`.jsx` do DISCO com a lista; sem arquivo novo sob `resources/`, nada a declarar |
| `JSX_DEPOIS_DA_187` (`publicador-fila-fechamento-187.test.js`) | idem. São **DUAS** escriturações de propósito (um conserto da 188 **recusou** criar a terceira) — as duas ficam byte a byte |
| `LIBERADOS_DEPOIS_DA_187` / `TAMANHOS_PERMITIDOS` | nenhum tamanho novo aqui. O `18` é da wave **189-01** e o `21` é da **188-01** — **não encostei em nenhum dos dois** |
| `SLOTS_DE_NO_DEPOIS_DA_187` e `ESTADO_LOCAL_DA_PAGINA` | este plano **não toca** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` (quem repassa a prop ao painel é o **189-06**) |
| `QUEM_USA_SHADOW_PUB_PAINEL` | nenhum arquivo deste plano escreve classe de Tailwind — **TOK-15: nenhum hex nem classe sai do PHP** (gate com sonda de hex → 0) |
| `VARRIDOS_DECLARADOS` (`publicador-rename-em-analise-ml.test.js`) | ela declara três diretórios sob `resources/js/Components/Mlb/Publicador/`; este plano não cria arquivo em nenhum |
| **EXPOSITORES de `andamento`** (`contagens-186-09.txt`) | ⚠️ o serviço **NÃO é expositor**: o payload dele **não tem** chave de andamento (asserção cobrando `0` lido sem comentário). A exceção de território da 186 **não foi aberta**, e `186-09 andamento-um-lugar-so` ficou **1/20**, inalterado |
| a blocklist do `18` e os cinco leitores reancorados (`c4317cd0`, `2444feea`) | **não voltei nenhum dos dois para número** — e a sonda do meu gate **recusa** ponteiro de linha voltar |

---

## 16 — ⚠️ O paralelismo com a 189-03: medido, zero colisão

- **`files_modified` em comum: ZERO.** Eu: `MlbPublicadorAprovacaoController.php`,
  `LightboxDoPainelService.php`, `LightboxDoPainelServiceTest.php`, `contagens-189-02.txt`. Ela:
  `LightboxDaImagem.jsx`, `tests/js/publicador-vocabulario-novo.test.js`, o gate JS dela. Confirmado
  pela **PI**: os 4 commits do meu escopo tocaram **exatamente** 4 arquivos, nenhum dela.
- **`git diff HEAD -- <arquivo>` conferido antes de CADA edição** (vazio nos meus três). Não houve WIP
  alheio a contornar, então **não** foi preciso o ritual de cópia + `git show HEAD:` + âncora de texto.
- ⚠️ **Âncora SEMÂNTICA no controller, nunca por linha** — `$mlbEmpresaId` aparece **15 vezes** no
  arquivo (medido). `git show --numstat` do commit: **30 inserções, 0 remoções** no controller.
- ⚠️ **NUNCA li `${TMPDIR:-/tmp}/ultima-suite-php.log`** durante execução (caminho FIXO e
  COMPARTILHADO; uma wave leu estado parcial e reportou `0 falhas` quando eram 11). Só o veredicto do
  harness.
- ⚠️ **Nunca duas suítes PHPUnit ao mesmo tempo:** a wave 189-03 é JS, e eu esperei o `--suite php`
  terminar antes de rodar qualquer gate PHP. **Nenhuma falha que passasse em isolamento apareceu**, e
  cada gate foi rodado por `--filter` isolado.
- `git stash list` → **27 entradas de outras sessões, APENAS listadas.** `git stash` **não** foi rodado
  em forma nenhuma. Untracked alheios (`tests/Feature/CompanyPortfolioAccessTest.php`,
  `tests/js/.pub-lote187-inertia-stub-9672.mjs`) **intocados**.
- `scratchpad` com o nome do plano: `mutar-189-02.sh`, `pi-189-02.sh`.

---

## 17 — O que eu NÃO posso afirmar

⚠️ **Eu não vi tela renderizada, e não há como ver nesta máquina:** sem navegador, `mysqld` local
**parado**, banco sem empresa com `ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` **vazia** e
a fila só ganha oferta na **Fase 194**. Onde se lê *"a tela mostra X"*, leia **"o payload contém X"**.

⚠️⚠️ **As conferências visuais das Fases 187 E 188 pelo usuário seguem ABERTAS e separadas** — nada
daqui as substitui nem as presume feitas.

⚠️ **Nenhum deploy, nenhum push, nenhum acesso ao VPS, nenhuma chamada à API real do ML, nenhuma
migration** (esta fase não tem migration e `artisan migrate` **não** foi rodado).

---

## Deviations from Plan

### Auto-fixed Issues

**1. [Rule 1 - Bug] A primeira isca da trava de rede era CEGA**
- **Found during:** Tarefa 2 (GREEN)
- **Issue:** `Http::fake([...])` para re-faquear o facade **não lança** `StrayRequestException`, porque
  `Factory::fake()` ACRESCENTA stubs e o catch-all do `setUp()` continua casando tudo. A "isca"
  provava nada.
- **Fix:** duas partes — (a) o flag `Http::preventingStrayRequests()` do facade real; (b) o mecanismo
  LANÇANDO numa `Factory` nova, com a URL faqueada passando (isca cega) e a não faqueada lançando.
- **Files modified:** `tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php`
- **Commit:** `33664ef9`

**2. [Rule 1 - Bug] `json_encode` escapa a barra: a sonda de ausência nunca casaria**
- **Found during:** Tarefa 1 (GREEN) — a **isca do mesmo lado** reprovou e achou
- **Fix:** `JSON_UNESCAPED_SLASHES` + sonda **estrutural** (nenhuma `url_arquivo` aponta para a
  original alheia)
- **Files modified:** `tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php`
- **Commit:** `95c4c12f` (já corrigido antes do commit) — a correção está no arquivo com a razão escrita

**3. [Rule 1 - Bug] A minha âncora do `inline` usava `preg_match`, que passa com o código removido**
- **Found during:** a conferência dos critérios de aceitação
- **Issue:** os dois **comentários** do controller contêm o literal `'faixa' => $fila->faixa($base)`,
  então `preg_match(...) === 1` ficaria verde mesmo se a linha de CÓDIGO fosse apagada
- **Fix:** leitura **sem comentário** com `substr_count(...) === 1`, mais isca do leitor cobrando os
  **3** no CRU
- **Files modified:** `tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php`
- **Commit:** `e3bd852d`

### Nada mais

Nenhuma arquitetura mudou, nenhuma dependência foi instalada, nenhum serviço das Fases 186/187/188
foi alterado, e **nenhum checkpoint foi acionado** (nada exigiu PARAR e devolver: a forma da prop bate
com o que a 189-05 esperou, o INV-13 confirmou que não há conjunto fechado, nenhum gate alheio
reprovou, e não apareceu um quinto conflito protótipo × regra do projeto).

---

## Known Stubs

**Nenhum.** O serviço entrega dado real em todos os ramos; o estado declarado de IMG-13 **não é
stub** — é o comportamento especificado (DADO-15 só é escrito nas Fases 192/194, sem backfill
possível, e por isso **na estreia TODA imagem de IA cai nele**). Isso está declarado no docblock, no
modelo da 186 e aqui.

---

## Threat Flags

Nenhuma superfície nova fora do `<threat_model>` do plano. A prop é **leitura**, atrás da trava de
conta que o 188-02 já tem, e os 8 itens do registro (T-189-10 a T-189-16 e T-189-SC) têm teste
nomeado nesta SUMMARY.

---

**Arquivos criados — conferidos por `test -e`:**

```
FOUND: app/Services/Publicador/Aprovacao/Painel/LightboxDoPainelService.php          (296 linhas, min_lines 90)
FOUND: tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php            (1156 linhas, min_lines 200)
FOUND: tests/gates/contagens-189-02.txt
FOUND: .planning/phases/189-.../189-02-SUMMARY.md
```

**Commits — conferidos por `git log --oneline --all | grep`:**

```
FOUND: 95c4c12f   FOUND: 9dc3b5a4   FOUND: 0efca35e   FOUND: 33664ef9   FOUND: e3bd852d
```

**Critérios de aceitação do plano, um por um:**

| critério | medido | veredicto |
|---|---|---|
| `test -e` do serviço sai 0 e `grep -c "originaisDe\|PubImagemReferencia::query"` ≥ 1 | **4** | OK |
| `grep -cE "SEM_ORIGINAL_(ROTULO\|APOIO\|ICONE)"` ≥ 3 | **4** | OK |
| o literal `Original de referência não disponível` **não** aparece no código lido sem comentário | **0** | OK |
| `grep -v '^\s*\*' <servico> \| grep -c "'andamento'"` = **0** | **0** | OK |
| os dois ids do 189-02 da tarefa 1 saem 0 | 45 e 32 asserções | OK |
| `186-09 andamento-um-lugar-so` com `tests=1 assertions=20` | **1/20** | OK, inalterado |
| `grep -c "'lightbox'"` ≥ 1 e `grep -c "LightboxDoPainelService"` ≥ 1 no controller | **1** e **3** | OK |
| `grep -c "'faixa' => $fila->faixa($base)"` = **1** | **3** (2 são comentário; sem comentário: **1**) | ⚠️ **critério FALSO na partida — ver §14/D1** |
| `189-02 prop-lightbox-e-sem-http` sai 0 e a isca do `preventStrayRequests` **lançou** | 3/75 · **LANÇOU** | OK |
| `186-09 transversais` com `tests=12 assertions=258` | **12/258** | OK, inalterado |
| `188-02 painel-na-rota`, `188-02 custo-do-painel`, `187-04 pagina-rota` inalterados | 2/41 · 2/24 · 6/185 | OK |
| mutação (i)(ii)(iii)(iv) MORREM, saída colada | **4 de 4 MORRERAM**, e no lugar certo | OK |
| PI(189-02) com interseção VAZIA e isca que acusa | 5 commits · 4 arquivos · isca OK | OK |

## Self-Check: PASSED

⚠️ **Nada de `STATE.md`, `ROADMAP.md` nem `REQUIREMENTS-v27.md` foi tocado** (GATE-07 e o território
desta fase), e **`state.advance-plan` NÃO foi rodado** — é do orquestrador.
