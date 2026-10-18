# A régua da Fase 188 — comum aos 13 planos

> **Este arquivo é referenciado por `<context>` em TODOS os `188-NN-PLAN.md`.** Ele existe para que
> nenhum plano repita a régua pela metade — repetir pela metade foi como três gates da Fase 187
> nasceram vazios. **Leia-o inteiro antes da tarefa 1 do seu plano.**
>
> ⚠️ Ele **não substitui** `.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md`, que é
> **leitura obrigatória** (597 linhas, 13 casos de asserção vazia, 8 normalizações do CSS
> construído, §4b, §4c). Este arquivo é o recorte **desta fase**.

---

## §1 — O QUE A 186 E A 187 JÁ ENTREGARAM: **CHAMAR, NUNCA REIMPLEMENTAR**

⚠️ **As Fases 186 e 187 estão EM PRODUÇÃO** (186 em `a4c4187f`; **187 inteira em `7791d7aa`,
18/10/2026** — zero migrations pendentes, 9 workers RUNNING, HTTP 200 em 0,53 s).
⚠️ **A conferência visual da 187 pelo usuário está ABERTA.** Nenhum plano desta fase pode afirmar
"a tela da fila está validada".

### Servidor da 186 (`app/Services/Publicador/Aprovacao/`) — **SOMENTE LEITURA**

| peça | o que entrega | assinatura que você vai chamar |
|---|---|---|
| `AndamentoDaOfertaService` | **ÚNICA PORTA** dos 8 estados, 13 transições | `mover(oferta, destino, quem, motivo)` · `filaDaConta(companyId, mlbEmpresaId): Builder` · `ordenarFila(Collection)` · `faixaDeAndamento(Builder)` |
| `AprovacaoDaOfertaService` | aprovar · reabrir · conferir alteração · lote · frete · preço — **transação única** | `aprovar(oferta, quem, ?versaoEsperada)` · `aprovarEmLote(iterable, quem)` · `conferirAlteracao(oferta, quem)` · `salvarRevisao(oferta, quem)` · `tentarDeNovo(oferta, ?quem)` · `registrarMudanca(...)` · `confirmarFrete(oferta, valor, origem, ?quem)` · `registrarPrecoSalvo(oferta, de, para, ?quem)` |
| `BloqueiosDaOfertaService` | os **8 bloqueios**, cadeia **exclusiva** `frete → prejuízo → margem` | `para(oferta): array{bloqueios, chaves, kinds, aprovavel, ao_vivo, resumo, na_galeria, pendentes, com_alerta, foco_imagem_id, composicao}` · `resumoDoRodape(array)` · `contagemDeFotos(oferta)` |
| `ComposicaoFinanceiraService` | as 7 linhas com origem | `para(oferta, ?precoForcado): ComposicaoDaOferta` · `simular(oferta, precoNovo)` · `comparacaoComAIrma(oferta)` · `voceRecebe(...)` · `congelar(oferta, momento)` · `precoDoAlvo(oferta)` · `margemMinimaComOrigem(companyId)` |
| `RevisaoDeImagemService` | as 11 regras de imagem | `aprovar(imagem, quem)` · `aprovarSemAlerta(conjunto, quem): int` · `reprovar(imagem, quem, motivo)` · `substituirPorOriginal(imagem, original, quem)` · `recolocarOriginal(imagem, quem)` · `reordenar(conjunto, ordem, quem)` · `definirPrincipal(imagem, quem)` · `marcarRegenerando(...)` · `concluirRegeneracao(...)` |
| `AjustesDaOfertaService` | ajustes com escopo + exceção | `pedir(oferta, quem, escopo, item, texto)` · `resolver(ajuste, quem)` · `abertosQueAfetam(oferta): Collection` · `aplicarExcecao(oferta, quem, tipo, justificativa)` · `ofertasAfetadas(ajuste)` |
| `HistoricoDaOfertaService` | o histórico por oferta | `registrar(oferta, quem, tipo, texto, ?versao)` · `registrarTransicao(...)` · `linhaDoTempo(oferta)` |
| `VocabularioDoPortao` | o vocabulário travado | `OS_OITO` · `BLOQUEIOS_EM_ORDEM` · `CADEIA_EXCLUSIVA` · `ABREVIACOES` · `ROTULOS` · `CORRECOES` · `TUDO_CONFERIDO` · `BLOQUEADO_PREFIXO` · `INCOMPLETO` |
| `ComposicaoDaOferta` (DTO) | o resultado da composição | `preco` · `linhas` · `total` · `resultado` · `margem` · `somaFixa` · `equilibrio` · `precoAlvo` · `incompleto` · `estimadoPorBaixo` · `margemMinima` · `margemMinimaOrigem` · `notaDaMargem` · `precoDeVariante` · `notaDoPreco` · `linhasDaConta()` · `linhasInformativas()` · `linha(chave)` · `rotuloDoResultado()` · `rotuloDaMargem()` · `rotuloDoMinimo()` |
| `LinhaFinanceira` (DTO) | uma linha com origem | `chave` · `rotulo` · `detalhe` · `valor` · `origem` · `nota` · `informativa` · `ehPendente()` · `rotuloDaOrigem()` |

### Servidor da 187 (`app/Services/Publicador/Aprovacao/Fila/`) — **SOMENTE LEITURA**

`FilaDeAprovacaoService` (`paraAConta`, `contadorDaAba`, `faixa`, `ordenar`, `margemMinima`) ·
`FiltrosDaFilaService` · `GrupoDaFilaService` · `GaleriaDaOfertaService` (`doRascunho(rascunhoId)`,
`tituloDoQuadrado(...)`) · `LinhaDaOfertaService` · `LoteDaFilaService` · `ProximoARevisarService`
(`aoVivo`, `escolher`, `para`, `rotuloDaEspera(minutos)`) · `AquecimentoDeTarifasService`.

### Tela da 187 (`resources/js/Components/Mlb/Publicador/aprovacao/`) — **SOMENTE LEITURA**

21 arquivos, **todos NO AR**. ⚠️ **Compor o mínimo, nunca reescrever.**

⚠️⚠️ **`CascaDeModal.jsx` É DA 187 (plano 09). A FASE 188 A IMPORTA, NUNCA A RECRIA.** O Coverage
Map atribui a "casca comum" à 188 (MOD-05), mas o **modal de lote** é da 187 — então ela **nasceu
lá**. **Duas cascas divergiriam**, e a divergente seria descoberta por alguém vendo dois modais com
raios diferentes na mesma tela. Está declarado dentro do arquivo e **tem gate**
(`fechamento-arquivos-e-manifestos`, em `contagens-187-11.txt`).

Named exports que você vai usar: `CLASSES_BACKDROP` (`z-[42]`, `pt-[96px]`, `bg-black/[0.65]`),
`CLASSES_CAIXA` (`w-[560px]`, `max-h-[calc(100vh-140px)]`, `rounded-pub-card`, `shadow-pub-modal`),
`CLASSES_TOPO`, `CLASSES_TITULO`, `CLASSES_SUB`, `CLASSES_CORPO`, `CLASSES_RODAPE`,
`CLASSES_BOTAO`, `CLASSES_CANCELAR`, `CLASSES_SUBMETER`, `CLASSES_SUBMETER_ATIVO`,
`CLASSES_SUBMETER_APAGADO`, `ROTULO_CANCELAR`, `GLIFO_FECHAR`, `TITULO_FECHAR`, `TopoDoModal`,
`RodapeDoModal`, e o default `CascaDeModal({aberto, titulo, sub, rotuloDoBotao, podeSubmeter,
onFechar, onSubmeter, children})`.

### ⚠️⚠️ `AndamentoDaOfertaService` tem DUAS CAMADAS DE GATE

A **camada A** lista quem pode **MENCIONAR** o andamento; a **camada B** prova que ninguém de fora
**ESCREVE**. A 187-08 acrescentou à **B** uma lista de **EXPOSITORES com a exceção PROVADA, não
afirmada** — o expositor **tem** de casar a sonda larga (senão a exceção é linha morta) **e** não
pode ter nenhuma forma de escrita — e **remediu `contagens-186-09.txt`**
(`andamento-um-lugar-so` **13 → 19**, `transversais` **251 → 257**).

➡️ **Esta fase ESCREVE andamento nos critérios 12, 13 e 16 — SEMPRE pela única porta
(`AndamentoDaOfertaService::mover`), nunca por `update`/`save` do campo.** Se o seu arquivo
**EXPÕE** o andamento numa chave de saída: **entre na lista de EXPOSITORES com PROVA e REMEÇA**
`contagens-186-09.txt`, declarando no plano. ⚠️ **NÃO renomeie chave para fugir do gate; NÃO
enfraqueça camada.** Essa é a **única** exceção de território já aberta nesta fase.

---

## §2 — AS DECISÕES DO USUÁRIO QUE INCIDEM NESTE PAINEL

1. ⚠️⚠️ **APROVAR JÁ PUBLICA**, sem segundo clique, rótulo **"Aprovar para publicação"** inalterado.
   **Nada de "confirmar publicação", "desfazer", "segundo passo", "cancelar aprovação"** —
   **gate de ausência COM ISCA**, válido para as Fases 187-194. ⚠️ **Aprovar ENFILEIRA; a escrita no
   ML é da Fase 193** (critério 18). **Declare a fronteira no docblock.**
2. ⚠️ **Preço por variação: PIOR CASO** (menor preço ativo), e a tela **NOMEIA** ("pior caso entre N
   variações"); com um preço só, **sem nota**. Já vem resolvido em
   `ComposicaoDaOferta::$precoDeVariante`/`$notaDoPreco` — **ler, não recalcular**.
   ⚠️ Na fila (187-08) a frase é só `title`; **a frase VISÍVEL é desta fase** (decisão P1, 15/10).
3. ⚠️ **"Embalagem" nasce `Pendente` e NÃO anula o resultado** — exceção **deliberada e única** à
   regra do `null` (sem ela **100%** das ofertas diriam "Incompleto" e nada seria aprovável); o
   resultado sai marcado **estimado por baixo** (`ComposicaoDaOferta::$estimadoPorBaixo`), e
   **prejuízo/margem DISPARAM** sobre ele. ⚠️ **"Outros custos" nasce R$ 0,00 `Confirmado`.**
   ⚠️ O **FRETE** ausente, no MESMO cenário, **anula** — é a isca que separa as duas.
4. ⚠️⚠️ **Decisão 9 — cada margem DIZ DE ONDE VEM, e há DUAS de propósito:** no portão/editor a
   **tarifa real do ML** (`sale_fee_amount`); na visão rápida do lote o **parâmetro da
   Precificação** (`app/Services/Publicador/Fila/ResumoRapidoService:384-391` — ⚠️ o caminho **NÃO**
   é `.../Aprovacao/Fila/`, e confundi-lo com `FilaDeAprovacaoService` **APAGARIA** esta decisão). O
   docblock de `EditorRascunhoService:370-372` diz **"diferente DE PROPÓSITO… não unificar"**.
   ➡️ A tela **nomeia**: a frase `· com a tarifa real do Mercado Livre` já é montada por
   `ComposicaoDaOferta:271` e vem em `notaDaMargem` — **LER dali**. Deixar sem texto foi **recusado**.
5. ⚠️ **O bloqueio de fotos cobre TODOS os grupos** — a oferta só é aprovável com **todas** as
   variações **e** as **gerais** conferidas. **Aprovação cor por cor foi RECUSADA** (aprovar já
   publica e não há desfazer).
6. ⚠️ **A exceção só REGISTRA quem liberou**; **"exceção aguardando autorização" NÃO EXISTE.**
   ⚠️ **E libera SÓ `loss` e `margin`** (critério 11) — nunca fotos, frete, alteração, ajuste, título.
7. ⚠️ **Vocabulário travado: "Resultado estimado por venda" / "Margem de contribuição estimada".
   NUNCA "lucro líquido"** (⚠️ `lucro_liquido` é campo **legítimo** do Portal — **recorte a sonda à
   lista CONSTANTE dos arquivos novos do seu plano**; varrer `app/` barrou um deploy na 186).
   ⚠️ **Cor nunca é o único sinal.** ⚠️ **Jargão não vai para a tela.**
8. ⚠️ **"Tudo de uma vez, e só libera com todas conferidas"** — aprovar silenciosamente só as que
   passam foi **recusado**; a tela **diz quem ficou de fora e por quê** (vale para o critério 5).

---

## §3 — FIDELIDADE (FID-01..04): o placar é ~146 a 2

⚠️ **MEÇA no `.dc.html`; o PROTÓTIPO GANHA** em **VALORES, RÓTULOS e ORDEM**. Divergência vai para a
SUMMARY com `arquivo:linha`, confirmada pela **SEMÂNTICA**, nunca pelo número de linha.
⚠️ **DECLARE COMO MEDIU: SCRIPT é confiável, LEITURA não.**

⚠️⚠️ **Ele NUNCA ganha nos NÚMEROS de dinheiro:** as taxas de `fin()` (`:1064`-`:1066`) são **ficção
declarada** (*"taxa de exemplo"*, `src:'est'`) e com as fontes reais o `o1` **inverte de aprovável
para bloqueado**. ➡️ **"O protótipo ganha" vale para a FÓRMULA, os RÓTULOS e a ORDEM, NUNCA para os
NÚMEROS.**

⚠️ **A seção F do README é PROSA e já contradisse `info()`** (chamou de "margem baixa" um caso que dá
**prejuízo** pela cadeia exclusiva): **só `info()` decide bloqueio.** Não misture as duas fontes.

### As DUAS derrotas do protótipo — as duas por conflito entre regras do projeto, resolvido PELO USUÁRIO

1. `'3 dias'` por extenso contra o `' d'` dele, mantendo `min`/`h` abreviados de propósito.
2. O toast do lote mantendo o vocabulário travado contra o *"N ofertas aprovadas"* de `:1167`.

➡️ ⚠️⚠️ **Se você achar um TERCEIRO conflito desses, NÃO DECIDA: escreva a pergunta no plano, pare a
tarefa e devolva.** (Uma já está aberta: **PERGUNTA-188-A**, em `188-FID01.md` §A.)

### FID-02/03/04 — as justificativas VETADAS e os tokens proibidos

**FID-02, NÃO aceitas:** *"ficou mais parecido com o resto do sistema"* · *"mantive o token
`ecf-*`"* · *"o componente que já existe faz parecido"* · *"o Tailwind não tem esse passo"*.
**FID-03:** `text-[13.5px]`, nunca `text-sm`. **FID-04:** vetados `ecf-bg`, `ecf-card`,
`ecf-yellow`, `font-display`, `border-white/[0.08]`.
**viewBox** protótipo `0 0 16 16` × Lucide `0 0 24 24` → **`strokeWidth × 1,5`**.

### ⚠️⚠️ COMO A TABELA ERRA: por **OMISSÃO** e por **REFERÊNCIA**, quase nunca por valor

Placar de ponteiros da 187: **9/16 · 3 · 7/12 · 8/9 · 13/20 · 11/17 · 19/25**.
Omissões recorrentes: `cursor:pointer` **onze** vezes numa fase; `white-space:nowrap` **sete** na
187 (**três** sendo a PRIMEIRA propriedade da linha); e **wrappers inteiros** ausentes.

➡️ **`cursor`, `white-space` e `text-overflow` vão em COLUNAS PRÓPRIAS** na tabela FID-01 de cada
plano, e **o executor trata OMISSÃO como o erro mais provável e reconfere CADA ponteiro por `sed`**.

---

## §4 — O PORTAL E O Z-INDEX: a armadilha técnica central desta fase

⚠️⚠️ **O critério 1 exige o painel POR PORTAL, acima do header, SEM alterá-lo.** O
`backdrop-filter` do `PublicadorHeader` (`:171`, `backdrop-blur-[14px] backdrop-saturate-[1.4]`)
**cria containing block para `position:fixed`** — sem portal um descendente dele fica preso.

⚠️⚠️ **E o renderizador de SERVIDOR do React NÃO renderiza portal** — o teste de render veria
**vazio e passaria POR OMISSÃO**. ➡️ **A Fase 178 resolveu exportando as peças internas como NAMED
EXPORTS e testando-as diretamente; a 187-09 repetiu.** Esta fase faz o mesmo, **e o docblock declara
por que existe**:

- guarda `typeof document === 'undefined'` **antes** do `createPortal`, para o render de servidor
  devolver `null` em vez de explodir;
- **todas** as peças internas como **named exports**, conferidas e **exercidas no CLIQUE**
  diretamente;
- ⚠️ **a raiz do portal declara `font-pub text-pub-txt`** — **conteúdo portado NÃO herda a fonte**
  (medido na Fase 178: sem isso o modal inteiro perde a Geist e cai em Inter). É o REND-05, e é
  **exatamente** no limite do portal que a herança quebra.

### A ordem travada, e o remapeamento desta fase

⚠️ **Ordem travada: `filtros 10 < header 30 < menu lateral e painel 40`**
(`tests/js/publicador-header-zindex-ordem.test.js`, invariante medido do bug de 12/10 que o usuário
relatou). ⚠️ **REMAPEIE preservando a ORDEM RELATIVA; NUNCA copie os números do protótipo** (lá o
header é 50 e as peças usam 44/45/60/100/120).

Os números **JÁ EM USO e medidos no disco**:

| peça | arquivo | z |
|---|---|---|
| filtros da lista | (180) | `10` |
| header global | `header/PublicadorHeader.jsx:171` | `z-30` |
| menu lateral / painel do produto | (178/180) | `40` |
| barra de seleção em lote | `aprovacao/BarraDeSelecao.jsx:58` | `z-[35]` |
| casca de modal | `aprovacao/CascaDeModal.jsx:54` | `z-[42]` |
| toast | `aprovacao/ToastDaFila.jsx:69` | `z-[45]` |
| popovers do header | `header/PublicadorHeader.jsx:167` | `z-[61]` |
| modal "Nova publicação" | `header/ModalNovaPublicacao.jsx:88`/`:101` | `z-[80]` |

➡️ **O remapeamento desta fase: backdrop do painel `z-[39]`, painel `z-[40]`.** Preserva
`header(30) < backdrop(39) < painel(40) < modal(42) < toast(45)`, que é **a mesma ordem relativa** do
protótipo (`header 50 < backdrop 69 < painel 70 < modal 100 < toast 120`).

⚠️ **Medido na 187-09: `45` é o número do POPOVER e o toast legitimamente ficou em `z-[45]`** — um
gate com lista larga de números do protótipo **reprova código certo**. Ancore no **comportamento**
(a ordem relativa), não numa lista de números proibidos.

⚠️ **Os popovers da 187 NÃO precisam de portal** (são `absolute` dentro de pai `relative`) —
medido. **Não confunda os casos, não "conserte" os popovers.**

⚠️ **Divergência de ambiente JÁ MEDIDA na 187-09:** peça `fixed` como "irmã do `<main>`" é
**impossível** sem tocar `AppLayout.jsx` (**intocável**) — as peças da 187 são irmãs do
`PublicadorHeader`, o único elemento com `backdrop-filter`, e isso **preserva** o invariante.
⚠️ **O `backdrop-blur-md` de `AppLayout:1068` está FORA do `</main>`** — sonda que varre até o fim do
arquivo **reprova código certo**.

⚠️ **O critério 1 exige o teste de z-index existente INTACTO:**
`git diff --numstat -- tests/js/publicador-header-zindex-ordem.test.js` tem de sair **vazio**.

---

## §5 — ARMADILHAS DE TELA

### ⚠️⚠️ A TELA PRETA — critério 19, literalmente

Voltou **QUATRO** vezes na Fase 180 e **UMA** na 187-06 — essa **NO CLIQUE**.

- `String(valor)` **LANÇA** com `{toString: null}`;
- `Object.prototype.hasOwnProperty.call(obj, chave)` **LANÇA** (`TypeError: Cannot convert object to
  primitive value`) — a busca de chave **coage para string**;
- **qualquer `obj[chave]`** com chave `{toString: null}` **LANÇA** pelo mesmo motivo;
- `textoSeguro(NaN)` devolve a string `'NaN'` (`typeof NaN` é `'number'`);
- **prop de NÓ chegando objeto comum faz o React LANÇAR** e mata a página.

➡️ **Render REAL** (esbuild + `react-dom/server`) com cada campo **objeto, nulo, ausente, NaN e
array**, a prop inteira nula, **e TAMBÉM no CLIQUE** (a 187-08 exportou o handler porque
`renderToStaticMarkup` **não dispara `onClick`**); `doesNotMatch(/\[object Object\]|NaN|undefined|
Invalid Date/)`; **nunca** `String()`/`JSON.stringify()` na mensagem de asserção (eles lançam / mentem).
⚠️ **Guarda de tipo ANTES de qualquer `obj[chave]`/`hasOwnProperty`, sempre que a chave vier do
servidor.** ⚠️ **Há gate da 187-04 que PROÍBE `String(` na página — está certo, não o afrouxe.**

### ⚠️⚠️ Rollup elimina variável de escopo do componente usada dentro de `.map()`

Este painel mapeia **grade de 6 colunas, marcadores x/y, 7 linhas de composição, 3 KPIs, pendências
e 4 modais**: ➡️ **flag DENTRO do callback.**
⚠️ **Correção medida na wave 187-09:** expressão de `.map()` é aceita quando tudo que ela lê é
**parâmetro** ou **nome de MÓDULO**, que o Rollup nunca elide.

### ⚠️⚠️ `tailwind.config.js` varre `*.jsx` e NÃO `*.js`

Classe num `.js` **não gera CSS e não existe para a tela**, com render verde.
⚠️ **Todo arquivo de PEÇA é `.jsx`.** ⚠️ **MAS o invariante certo é "nenhuma PEÇA nova sob
`resources/` é `.js`"** — a 187-11 mediu que o *"nenhum arquivo novo é `.js`"* de um plano era
**literalmente FALSO** (há 9 `.js`, **todos testes**). **Não escreva o invariante errado.**

### Transições, timers e tamanhos

⚠️ `duration-[Nms]` / `delay-[…]` / `ease-[…]` **NÃO COMPILAM** neste projeto (o plugin
`tailwindcss-animate` registra `duration`/`delay`/`ease` como utilitários de animação e o Tailwind
recusa o valor arbitrário). ➡️ `style={{ transitionDuration: '180ms' }}`, com `duration-200` como
**controle** da sonda.
⚠️ **Timer é FUNÇÃO PURA e o runtime NÃO é provado** (não há navegador) — **exija a declaração**.
⚠️ **`text-[21px]` é o ÚNICO tamanho novo desta fase** (os 3 KPIs, `:642`-`:644`) e é o plano
**188-01** que o libera em `TAMANHOS_PERMITIDOS`. Todos os outros do painel e dos 4 modais
(9.5 · 10 · 10.5 · 11 · 11.5 · 12 · 12.5 · 13 · 13.5 · 14 · 15 · 16 · 20) **já estão na lista** —
medido por script em 18/10/2026.

### ⚠️ NADA de `localStorage` nem "Restaurar dados de exemplo"

São artefatos do protótipo (`KEY = 'ecf-aprovacao-v1'`, `:914`/`:978`/`:1007`).
⚠️ **O critério 16 é explícito: a revisão parcial fica no SERVIDOR.** Filtros, oferta aberta e
seleção vivem na **URL** (COL-03).

### ⚠️ `npm run build` e o manifest

Obrigatório; manifest **pelo JSON** (`node --input-type=commonjs`, porque o `package.json` é
`"type":"module"`), **nunca por grep** (o hash sai com hífen: `assets/app--5yuaubs.js`).
⚠️ **Medido na 187-11: `lerSemComentarios` COME o `/**/` do glob** (`'./resources/js/**/*.jsx'`
chega como `'./resources/js*.jsx'`) — **leitura CRUA para globs**; há **três** iscas invertidas no
repositório exigindo que o helper continue assim.
⚠️ **Página React fora do manifest SOME da aplicação**, sem erro nenhum.

---

## §6 — GATES: prescritos assim, nenhuma armadilha pode repetir

### O harness

⚠️⚠️ **`sh tests/gates/verificar-testes.sh 188-NN <id>` em TODO gate, e o 1º argumento é `188-NN`,
NÃO só o número** — `08` sozinho resolve `contagens-186-08.txt`, **a fase errada, em silêncio**
(compatibilidade **DE PROPÓSITO**: a 186 está em produção e seus 163 ids são chamados pelo número
nu; os casos 15/16 do `--autoteste` guardam isso).
⚠️ **Cada plano cria `tests/gates/contagens-188-NN.txt`; manifesto incompleto ABORTA** (id ausente,
`tests=` ausente, `tests=0`).
⚠️ **`--autoteste` passa 20/0 — exija rodar e COLAR a saída.**
⚠️ **NÃO chamar `node --test` / `artisan test` direto nos gates** (learnings §4, casos **12 e 13**:
o reporter do Node v24 emite **`ℹ`**, não `#`, então `grep "^# tests"` **nunca casa**; e
`grep -E "Tests:|OK"` **passa com teste falhando** — o filtro do `error` é obrigatório).

### A forma dos ids

⚠️ **Nenhum id com mais de TRÊS métodos** — alternância grande equivale a filtro de classe, passa
dos 120 s do shell e o helper **RECUSA** (a 186-07 virou 7 ids, a 187-06 virou 4, a 187-07 virou 7).
⚠️ **Bijeção ids ↔ métodos por script, nos DOIS sentidos:** método sem id **NUNCA RODA** (foi o
sobrevivente da mutação do 186-03) e id órfão é gate sobre ZERO casos.
⚠️ **Nenhum id repetido dentro do mesmo manifesto** (o harness pega o **primeiro**).

⚠️⚠️ **`tests/js/publicador-vocabulario-novo.test.js` tem id PRÓPRIO** (`vocabulario-tamanhos`,
forma **`>=`**, em `contagens-187-01.txt`) e **hoje mede 548** (piso `>=443`). **NUNCA embuti-lo num
id composto** — a contagem dele **cresce por construção** (um sub-teste por arquivo varrido) e
embuti-lo fez a wave 5 da 187 reprovar DOIS ids de outras waves e exigiu consertar QUATRO
manifestos. ⚠️ **E `aprovacao/` já está em `DIRETORIOS_FECHADOS` com `assert.deepEqual(noDisco,
declarados)`: TODO arquivo `.jsx` novo entra em `ARQUIVOS_NOVOS` (`:45`)** ou o gate passa **por
omissão** — e o `deepEqual` reprova na hora (comportamento certo). ⚠️ Pré-declarar **não é
possível**: `FONTES` lê todos os caminhos e **LANÇA** com arquivo inexistente. **É por isso que as
waves que criam `.jsx` são SEQUENCIAIS.**

### ⚠️⚠️ COLISÃO DE HOMÔNIMO — conferir por SCRIPT, nunca de cabeça

O `--filter` do PHPUnit é regex **NÃO ANCORADO**, e **todo plano `_0N` desta fase tem homônimo
possível nas Fases 186 **E** 187, as duas EM PRODUÇÃO.** Método **superstring** de uma delas faz o id
daquela fase **SOMAR** e reprovar.
➡️ **Conferência por script nas DUAS direções contra todo `test_*` de `tests/`** (a 187-11 mediu
`ARQUIVOS=26 METODOS=389 HOMONIMOS=0`), **incluindo PREFIXO** — nome que é prefixo de outro casa os
**DOIS**. ⚠️ **Todo método seu leva `_188_NN` no nome, e o conserto de colisão é sufixo no método
NOVO — NUNCA levantar a contagem do manifesto alheio.**

### As treze regras que fazem um gate medir algo

1. **Teste sem id no manifesto NUNCA RODA.**
2. **Palavra no DOCBLOCK que explica "não uso isso" reprova pelo próprio conserto** → **ler o código
   SEM COMENTÁRIO** (`semComentarios()`). Houve TRÊS mutações abortando por isso na wave 187-04.
3. ⚠️ **Teste toda regex contra um caso que DEVE casar** (as waves da 187 acharam 2+1+2+4+3 furos nas
   próprias sondas).
4. **Gate sobre ZERO casos não prova nada.** Asserção de ausência rodada com dado VAZIO é vazia por
   construção: rode **com as props preenchidas**.
5. ⚠️⚠️ **Contagem que depende de QUANTOS ARQUIVOS/TABELAS/COMMITS/LINHAS EXISTEM é frágil — NOVE
   ocorrências, todas consertadas na RAIZ** (infratores **coletados** numa asserção de contagem
   fixa). ⚠️ **E "piso" (`>= N`) NÃO prova PRESENÇA** — a 187-11 mediu uma sobrevivente exatamente
   aí (com 13 itens, perder um deixava 12 e o piso passava): **o conserto é lista CONSTANTE, NUNCA
   elevar o piso.**
6. ⚠️ **Asserção VAZIA quando escrita vira FALSO POSITIVO depois** — de toda negativa pergunte:
   **o desenho PROÍBE isso, ou eu só não vi acontecer ainda?** Use **ISCA INVERTIDA** quando a lista
   legítima não pode estar vazia.
7. ⚠️ **Silêncio que depende de UMA LETRA é sorte** (`aprovad` **não** casa `aprovação`). Em pt-BR,
   conte com a cedilha e os acentos.
8. ⚠️⚠️ **GATE MAIS LARGO QUE O INVARIANTE — DEZ ocorrências**, três reprovaram trabalho correto de
   outra sessão, uma **BARROU UM DEPLOY** (o `LeitorShopee` usa `'andamento'` como status de promoção
   da Shopee — **palavra comum do português**) e uma **empurrava para o conserto errado** (renomear
   variável para a régua passar). ➡️ **ANCORE NO COMPORTAMENTO (o operador de chamada), NÃO NA
   PALAVRA**; **gate não cobra vocabulário do projeto inteiro: recorte à lista CONSTANTE dos
   arquivos da fase, com isca do recorte.**
   ⚠️ **E o inverso dá o MESMO prejuízo** (learnings §4c.4): gate **ESTREITO** reprova trabalho certo
   (procurar `não há desfazer` com espaço literal num markdown de 100 colunas onde a frase **quebra**).
9. ⚠️⚠️ **"Prova de território por RANGE" NÃO PASSA nesta árvore** — a 187-11 mediu: 111 commits no
   range, **43 da sessão paralela**, e o diff cru **acusava 12 dos 60 alvos vetados**.
   ➡️ **ESCOPO DO ASSUNTO (`(188-NN)`), duas passadas independentes, isca nas duas direções. O gate
   perene é o ANTI-FANTASMA (`test -e`), não o range.**
   ⚠️ **NÃO confira o AUTOR: as três máquinas desta árvore commitam com o MESMO `user.name`
   (`MB.ECF-100376`)** — `%an` é vazio por construção aqui.
10. ⚠️ **"Lista de nomes" não é "prova de comportamento"** — entrada em lista de exceções tem de ser
    **PROVADA** (o que impede alguém de entrar nela?).
11. ⚠️ **Nome genérico COLIDE e o `--filter` SOMA** → sufixo próprio.
12. ⚠️⚠️ **Toda sonda precisa de ISCA — uma por FORMA que ela DEVE pegar e uma que ela NÃO pode
    pegar.** ⚠️ **"O que precisaria estar errado no mundo para este gate falhar?" — "Nada" é ACHADO.**
13. ⚠️ **O critério 8 JÁ TRAZ a armadilha escrita:** `/disabled=/` e **nunca** `/disabled/`, que casa
    com `disabled:opacity-40` — mesma família do `/0%/` que casava "100%", do `includes('h-[64px]')`
    que casava `min-h-[64px]`, e do `includes('w-[')` que reprovava o `w-[1.5px]` legítimo.
    **Igualdade de STRING com isca do quase-igual** — `includes`/`substring` casa prefixo **e sufixo
    de outro nome** e já mordeu **cinco** vezes.

### Os DOIS gates por elemento, e as OITO normalizações do CSS construído

⚠️ **CSS **e** HTML** (igualdade de conjunto de classes), elemento por **árvore com índice
ABSOLUTO** (nunca `indexOf` de classes — **quatro** gates deste projeto mediam o irmão errado),
seletor com **TODOS** os metacaracteres escapados. ⚠️ **A 8ª normalização é que o escape passou a ser
GERAL**, porque antes reportava **AUSENTE** classe que **EXISTIA** (`rgba(…)`, `calc(…)`, `var(…)`,
colchetes, pontos, parênteses). ⚠️ **A sonda de CSS NÃO protege a fidelidade de UM arquivo** (o CSS é
do último build e a mesma classe pode ter outro dono) — **os dois gates não se substituem**.
⚠️ **Isca de ausência tem de ser classe inexistente no projeto INTEIRO** (`w-[13px]` parecia
inventada e saiu presente; `h-[64px]` e `left-[30%]` **existem**).
⚠️ **Regex em ARQUIVO escrito por ferramenta.** Nunca `node -e` (o escape do shell mastiga) e
**nunca heredoc — ele colapsa `\\` em `\` mesmo com `<<'EOF'`**.
⚠️ **Tire o ANSI antes de filtrar** saída de `artisan test`/`npm`: em 13/10 as cores comeram **todas
as 12 linhas de resultado** e a campanha inteira pareceu verde sem provar nada.

---

## §7 — MUTAÇÃO: a receita, e o que a 187 aprendeu doendo

1. **COMMITE o trabalho ANTES de mutar.**
2. `cp <arquivo> <scratchpad>/copia-188-NN-<caminho>` **com NOME QUE CARREGUE O PLANO**.
3. Ocorrência **travada em 1 ANTES** (recuse se ≠ 1) e **linha conferida DEPOIS**.
4. Restauração **DA CÓPIA** em `trap EXIT` (um executor da 187 teve o script **crashar no meio** e
   deixar o arquivo mutado no disco).
5. `sha256` contra `git show HEAD:<arquivo>` **com `tr -d '\r'` nos dois lados**.
6. ⚠️⚠️ **`git checkout` NUNCA** — ele restaura o estado **COMMITADO** e já apagou ~90 linhas de gate
   não commitado.

⚠️⚠️ **O NOME ÚNICO NÃO É ZELO — learnings §4c.3:** o scratchpad é **COMPARTILHADO** entre subagentes
lançados em paralelo pela mesma sessão, e na 187 o `mutar.sh` de uma wave foi **SOBRESCRITO** pela
outra no meio do trabalho, obrigando a **refazer a campanha inteira**. ➡️ **Todo** auxiliar leva o
plano no nome (`mutar-188-NN.sh`, `sha-188-NN.sh`, `out-188-NN-<rotulo>.txt`), e em execução paralela
**confira o `sha256` do próprio script** antes de confiar no veredicto. **Conclusão de mutação tirada
com script de nome genérico, em paralelo, NÃO VALE.**

⚠️ **Sobrevivente é INVESTIGADA, nunca presumida equivalente** (na milestone: **132 mutações, 5
eventos de sobrevivência, 4 lacunas REAIS**). ⚠️ **Mutação que NÃO reprova quando deveria também é
informação.** ⚠️ **FIXTURE QUE DISCRIMINA** — na 187-06 as duas sobreviventes eram falha da
**fixture**, não da asserção (uma oferta só não distinguia nada).
⚠️ **Dois regimes de contagem** (`tests=23,14`) quando a sonda tem `skip` por `mtime`: **meça as
duas** antes da campanha, senão a 1ª execução aborta ou a campanha inteira marca "SOBREVIVEU" por um
motivo que **não é o código**.

---

## §8 — BASELINES: medir ANTES de editar, registrar, **não ajustar**

Referência do fim da Fase 187 (`6ba51bd6`, 18/10/2026) — **o plano 188-01 RE-MEDE; os outros LEEM de
`188-BASELINES.md`**:

```
BUILD: exit 0
MANIFEST: 495 entradas / 202 paginas / isca ausente
JS: 5489 / 5488 pass / 1 fail / 0 skipped
PHP: 2493 / 21920 / 2
PROTOTIPO design_handoff_publicador/Aprovacao.dc.html: 1432 linhas sha256 68624883ce35547e
```

⚠️ **Contagem ACIMA da baseline é ESPERADA (a sessão paralela acrescenta testes); ABAIXO é
regressão.** A referência **não se "ajusta"**.

### ⚠️⚠️ AS 2 FALHAS PHP NÃO SÃO DESTA FASE e vão e voltam COM A HORA DO DIA

`tests/Feature/Publicador/CockpitDaContaTest.php:966` e `:1318` (**Fase 179**, em produção) —
fronteira de dia; **vermelhas 16/10, verdes 17/10, vermelhas 18/10, sem mudança de código**. O
arquivo usa `now()` **52** vezes.
⚠️⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE — é o NONO
caminho fantasma desta milestone.** O real é **fora** de `Aprovacao/`.
➡️ **NÃO consertar, NÃO contar como regressão, NÃO criar `REF_PHP_FALHAS`** — ⚠️ **mas se passar de
2, ou se falhar um teste DIFERENTE, É DA FASE e tem de ser investigado.**
⚠️ **A única falha JS é a pré-existente `tests/js/estrutura-grade-glide.test.js:122`** (Fase 87,
*"Características secundárias nasce recolhido"*) — **não corrigir**, aceita por `REF_JS_FALHAS=1`.

---

## §9 — TERRITÓRIO

### ⚠️ NÃO alterar NENHUM serviço da 186 nem da 187 — **CHAMÁ-los**

**Precisar mudar: o plano PARA e devolve a pergunta.**
⚠️ **Exceção já aberta e DECLARADA:** a **lista de EXPOSITORES da camada B** do gate de andamento
(§1) — entra-se **com prova** e **remede-se** `contagens-186-09.txt`.

### SOMENTE LEITURA

`vocabularioDoPortao.jsx` · `andamentoDoPortao.jsx` · `SeloDeAndamentoInterno.jsx` · os 21 `.jsx` de
`aprovacao/` (⚠️ **compor o mínimo, não reescrever — estão NO AR**) · `DadosEfetivosService` ·
`SimuladorVoceRecebe` (⚠️ em **`app/Support/Publicador/Validacao/`**) · `AnaliseAlavancasService`
(⚠️ em **`app/Services/Publicador/Alavancas/`**) · `EstruturaPrecificacaoParametros` ·
`EditorRascunhoService` · `ResumoRapidoService` (⚠️ em **`app/Services/Publicador/Fila/`**).

### PROIBIDO tocar

`app/Services/Creative/` · `MlbPublicadorCriativoController` · `MlAnuncioCriativoKit` ·
`PublicadorCriativoKitPresenter` · endpoints `criativo*` · `AnunciarML.jsx` · `PainelCriativosIa.jsx`
· `KitCriativosGrade.jsx` · `PublicadorCriativo*Service` · `LimparReferenciasCriativos` ·
**a retenção de 48 h** · o editor (`Pages/Mlb/Publicador/Editor.jsx`,
`resources/js/Components/Publicador/Mesa/` — ⚠️ **SEM** o `Mlb`) · `useCriativosDoPublicador.js`
(⚠️ em **`resources/js/Components/Publicador/`**) · **`App\Support\Publicador`** ·
`MlPublicacaoService` (⚠️ o real é `app/Services/Mlb/Publicacao/MlPublicacaoService.php`) ·
`AppLayout.jsx` · `Bling*` · `Services/Portal/` · **`app/Mcp/`** ·
**`Acervo*`/`ImagemAssetService`** (a v28 virou o acervo para o **R2 em produção** em 16/10 — ver
`.planning/learnings/acervo-imagens-r2.md`).

⚠️⚠️ **D-11 e D-22 INTOCÁVEIS** (`tests/js/estrutura-meus-anuncios.test.js`, por **SEMÂNTICA**, nunca
por número de linha). ⚠️ **A fronteira desta fase é o critério 18: o painel APROVA (e aprovar
ENFILEIRA), NÃO PUBLICA — a escrita no ML é da Fase 193.**

⚠️ **A Visão Geral (179), o corpo de Publicações (180) e a fila (187) estão NO AR** — não mexer além
do necessário para o painel abrir. ⚠️ **A Fase 189 é o lightbox e a 191 a revisão detalhada: NÃO as
antecipe** (o critério 9 só **leva** à detalhada na aba certa, pela rota que a 190/191 criará — e
**enquanto ela não existir, o botão leva ao que existe hoje ou não é desenhado**; botão sem destino é
D4 violado).

⚠️ `design_handoff_publicador/` e `new_layout_publicador/` são **somente leitura** e **NÃO
versionados** (`??`) — gate que dependa de lê-los **degrada para `skip` EXPLÍCITO e NOMEADO**, nunca
para "conferido".

⚠️⚠️ **Rode `test -e` em CADA caminho que você escrever — são NOVE fantasmas nesta milestone, e TRÊS
vieram de briefing do orquestrador.** Caminho fantasma num plano custa uma tarefa inteira.

⚠️ **NÃO** tocar em `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md` nem
`.planning/STATE.md` — **nenhum dos três pertence a plano nenhum** (GATE-07); quem edita é o
orquestrador, **à mão, em bloco ADITIVO**.
⚠️ `tests/Feature/CompanyPortfolioAccessTest.php` aparece `??` e **não é desta fase**.

### ⚠️⚠️ AVISO A QUEM EDITAR `MlbPublicadorAprovacaoController`

`$mlbEmpresaId` existe **DUAS vezes** — em **`:195`** e em **`:559`** —, resultado das edições
paralelas das waves 187-09/10. ➡️ **Âncora por número de linha escreve no lugar errado.** Use âncora
**semântica**: o nome do método, a linha inteira da atribuição com o contexto em volta.

⚠️ **E não "arrume" o que está escrito de propósito:** `'faixa' => $fila->faixa($base)` e
`'proximo_a_revisar' => $proximo->para($base)` ficam **INLINE** porque
`tests/js/publicador-fila-proximo-187.test.js` lê esse TEXTO; guardá-los em variável **reprova aquele
gate** (medido em 16/10).

---

## §10 — AMBIENTE

- ⚠️ `php` **NÃO** está no PATH: **`C:/xampp/php/php.exe`** (o harness já usa `PHP_BIN`).
- ⚠️ `artisan tinker` precisa de **`< /dev/null`**.
- ⚠️ O `mysqld` local **pode não estar em execução** — confira `tasklist | grep mysqld` antes de
  qualquer coisa que dependa do banco local. **Esta fase NÃO tem migration.**
- ⚠️ **NÃO deployar, NÃO dar push, NÃO tocar no VPS.** ⚠️ **Leitura de produção pode estar
  BLOQUEADA — PARE e devolva em vez de tentar.**
- ⚠️ Árvore **COMPARTILHADA com outro dev ativo e outras sessões**: `git add -- <caminho>` **e
  pathspec no `git commit -- <caminho>`**; **`-m` ANTES do `--`**; ⚠️ **`-F <arquivo>` SEMPRE** (uma
  wave usou `-m` e **perdeu o `Co-Authored-By`**). **NUNCA** `git add -A`, `git stash`, `git reset`,
  `git checkout`, `git commit --amend`, `git clean`.
- ⚠️ **"Árvore limpa" por `git status` ABORTA** pelo `??` de outras sessões (há mais de 30) — use
  `git diff HEAD` **nos SEUS arquivos**.
- ⚠️ **NÃO** rodar `pint` · **NÃO** criar worktree · **NÃO** usar `Write` em arquivo existente ·
  **NÃO** rodar `state.advance-plan` (corrompeu o `STATE.md` deste projeto **seis** vezes).
- ⚠️ **Teste que para de provar algo é pior que teste ausente:** cirurgia é **reaponte com veto**, e
  **CONTE TESTES, não linhas**.

### ⚠️⚠️ TRÊS AGENTES CAÍRAM NA FASE 187

Travamento de 600 s, erro de rede `ENOTFOUND`, e turno esgotado — e **um deixou 2/3 de um plano sem
commit**.

➡️ ⚠️⚠️ **COMMIT ATÔMICO POR TAREFA.** E, em todo plano: **se o turno pesar, PARE, commite o
provado, e diga EM QUE TAREFA PAROU** — na SUMMARY e na devolução ao orquestrador. Plano parado pela
metade com commit é retomável; sem commit, não é.

---

## §11 — COMO O USUÁRIO CONFERE (e o que nenhum executor pode afirmar)

⚠️⚠️ **NENHUM EXECUTOR PODE AFIRMAR TER VISTO TELA RENDERIZADA.** Todo render desta fase é
**esbuild + `react-dom/server`**: *"a tela mostra X"* significa **"o HTML gerado contém X"**.

Medido, e é o ambiente:
- o banco local **não tem empresa com `ml_token`** → a rota dá **404**;
- `pub_aprovacao_ofertas` está **VAZIA**, e **a fila nasce vazia até a Fase 194** (decisão **P3** do
  usuário: é honesto, **não** é defeito);
- **não há navegador** e a leitura de produção pode estar bloqueada.

➡️ **Cada plano que produz tela escreve o seu trecho do roteiro da conta 459**, e o plano **188-13**
o consolida. ➡️ **E cada plano que depende de oferta de verdade monta FIXTURE** (factory/seed de
teste), nunca "abrir e ver".

---

## §12 — AS PERGUNTAS DESTA FASE (forma fixa; **"a definir" NÃO é dono**)

> ⚠️ Lista viva. Quem achar pergunta nova **acrescenta aqui com dono** e **devolve** — não decide.

```
PERGUNTA-188-A: ABERTA — dono: usuário — o rótulo "VERIFICAÇÃO AUTOMÁTICA · APOIO" no PAINEL (E2).
  MEDIDO POR SCRIPT em 18/10/2026: o literal existe UMA vez no protótipo, em `:369`, que é a REVISÃO
  DETALHADA (E4). No PAINEL (`:633`) o protótipo escreve o prefixo INLINE, minúsculo, em #71717A:
  `Verificação automática{{ a.n }} · ` + texto — e `a.n` é `''` quando a severidade é `ok` e o
  ORDINAL do alerta quando não (então sai literalmente "Verificação automática2 · …", SEM espaço
  antes do número). O ROADMAP (critério 4) e o REQUIREMENTS (REVS-10) pedem o literal em MAIÚSCULAS
  no painel. É o TERCEIRO conflito protótipo × regra do projeto da milestone, e os dois anteriores
  foram decididos PELO USUÁRIO. Opções: (a) obedecer o protótipo (prefixo inline minúsculo, sem
  cabeçalho) e registrar divergência FID-01 contra o requisito; (b) obedecer REVS-10 (cabeçalho
  "VERIFICAÇÃO AUTOMÁTICA · APOIO" também no painel) e registrar divergência FID-01 contra o
  protótipo. ⚠️ O argumento SEMÂNTICO de (b): é o cabeçalho que diz "APOIO" — sem ele a verificação
  automática pode ser lida como VEREDICTO, e o REVS-10 pede "como APOIO" justamente para impedir
  isso (D7). BLOQUEIA: o rótulo da tarefa 3 do 188-03 e da tarefa 2 do 188-08
PERGUNTA-188-B: ABERTA — dono: Fase 190/191 — para onde o botão de correção de `ajuste` e de `titulo`
  leva HOJE. `fix()` (`:1171`-`:1178`) manda `openDetail(o.id, 'pend')` e `openDetail(o.id,
  'conteudo')`, e a ROTA da revisão detalhada (`…/aprovacao/{oferta}/{aba}`) é da Fase 190/191 e NÃO
  nasce aqui (está escrito em `routes/mlb_anuncios.php:491`). ➡️ Decisão do plano 188-10, DECLARADA e
  não inventada: enquanto a rota não existir, os dois botões NÃO são desenhados e o texto do bloqueio
  continua aparecendo — "nada nasce decorativo" (D4) proíbe botão sem destino, e o usuário já exerceu
  essa régua TRÊS vezes. ⚠️ Se o usuário preferir um destino provisório, ele diz; o plano não inventa
PERGUNTA-VISUAL-187: ABERTA — dono: usuário — a conferência visual da FILA (Fase 187) na conta 459,
  roteiro de 8 itens em `187-FECHAMENTO.md` §11. Nada desta fase a substitui nem a presume feita
PERGUNTA-17: ABERTA — dono: Fase 194 — BUG LATENTE: o recorte da fila usa as duas âncoras com `AND`
  e `mlb_empresa_id` é ANULÁVEL. Hoje não é bug (nada cria oferta). NÃO bloqueia a 188
PERGUNTA-13: ABERTA — dono: Fase 193 — `PUBLICADO => []` é TERMINAL. Mexer na foto de anúncio JÁ NO
  AR é outro fluxo. NÃO bloqueia a 188
```
