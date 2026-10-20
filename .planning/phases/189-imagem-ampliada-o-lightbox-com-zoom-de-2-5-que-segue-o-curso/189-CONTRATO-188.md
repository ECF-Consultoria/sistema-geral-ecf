# 189-CONTRATO-188.md — o inventário MEDIDO do que a Fase 188 entregou e a 189 consome

**Medido em 20/10/2026**, no HEAD `337f29c5` (partida `e250f9fb`), **por comando**, arquivo por
arquivo no disco.

> ⚠️⚠️ **POR QUE ESTE DOCUMENTO EXISTE.** Os planos 189-02..189-07 foram escritos quando a Fase 188
> estava em **2 de 13 waves**: eles citam peças **pelo plano que as cria**, não pelo código. Sem um
> documento **medido**, cada executor faria a sua "caça ao tesouro" e o primeiro nome errado viraria
> caminho fantasma — foram **dez** nesta milestone, **três** vindos de briefing.
>
> ⚠️ **Quando este inventário e a `189-REGUA.md` §2 divergirem, VALE ESTE** (é o que a própria régua
> manda: *"os planos 189-02..189-07 leem o inventário, não esta tabela"*).
>
> ⚠️ **Isto é MEDIÇÃO, não confirmação:** onde a peça não existe como o plano da 188 previa, está
> escrito **o que existe**.

---

## ⚠️⚠️ ACHADOS QUE MUDAM O 189-05, O 189-06 E O 189-07

### A1 — ⚠️ BOA NOTÍCIA: o `ModalDeReprovarImagem` já está **FORA** do `<aside>` (INV-04)

O **AVISO-189-3** pedia à sessão da 188 que não o montasse dentro do `<aside>` `z-[40]`. **Ela
atendeu.** Medido por **profundidade estrutural** (índice de abertura/fechamento da tag, não
aparência): o `<aside>` abre em `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:711` e **fecha em `:715`**; o slot `modais` é
renderizado em **`:717`**, ou seja **IRMÃO do `<aside>`**.
➡️ **O 189-07 NÃO precisa mover a montagem**, e o `z-[41]` do lightbox não esconderá o modal.
⚠️ **E o 188-11 declarou a razão por escrito** (`resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:654`-`:672`), citando o aviso.

### A2 — ⚠️⚠️ O gate de ausência de "Ampliar" NÃO É UM: são **NOVE** em JS e **DOIS** em PHP (INV-06)

O plano 189-06 diz que inverte *"o gate do 188-08"*. **Medido: há nove asserções de ausência de
`/Ampliar/` em `tests/js/` e duas em PHP.** A boa notícia é que **oito das nove são recortadas à
lista CONSTANTE dos arquivos do próprio plano** (`FONTES_DO_PLANO`), e só **duas** listas incluem
arquivo que a 189 edita. ➡️ **O que o 189-06 tem de saber, medido:**

| gate | onde ele mede | cobre arquivo da 189? | o que o 189-06 faz |
|---|---|---|---|
| `tests/js/publicador-painel-fotos-188.test.js:639`/`:640` | o **HTML RENDERIZADO** de `ColunaDeFotos` com props preenchidas | **SIM** — é o que vai conter o "⤢ Ampliar" | ⚠️ **É ESTE que se INVERTE**, e **só estas duas linhas** |
| `tests/js/publicador-painel-fotos-188.test.js:641` | o mesmo HTML, `/Gerar novamente/` | — | ⚠️ **NÃO TOCAR**: a ausência da Fase 192 continua |
| `tests/js/publicador-painel-casca-188.test.js:1034`/`:1035` | **FONTE** de `PainelDeRevisao.jsx`, `TopoDoPainel.jsx`, `IdentificacaoDaOferta.jsx`, **sem comentários** | **SIM** — `PainelDeRevisao.jsx` | ⚠️⚠️ **ARMADILHA: o 189-06 edita esse arquivo.** O literal `⤢ Ampliar` **não pode** aparecer no CÓDIGO dele (docblock é seguro: a fonte é lida por `lerSemComentarios`). ⚠️ **E uma prop chamada `onAmpliar` casaria `/Ampliar/`** |
| `tests/js/publicador-painel-preco-188.test.js:960` | FONTE de Kpis/Composicao/Simulacao/Irma/**ColunaDePreco** | não | nada |
| `tests/js/publicador-painel-rodape-188.test.js:1118` | FONTE de PendenciasDoPainel/RodapeDoPainel | não | nada |
| `tests/js/publicador-painel-modais-a-188.test.js:895` | FONTE de `ModalDeAjuste.jsx` | não | nada |
| `tests/js/publicador-painel-modal-excecao-188.test.js:867` | FONTE de `ModalDeExcecao.jsx` | não | nada |
| `tests/js/publicador-painel-modais-b-188.test.js:616` | FONTE de `ModalDeFrete.jsx` | não | nada |
| `tests/js/publicador-painel-modal-reprovar-188.test.js:685` | FONTE de `ModalDeReprovarImagem.jsx` | **SIM, se o 189-07 editá-lo** | o 189-07 não escreve "Ampliar" lá; **fica declarado** |
| `tests/js/publicador-painel-aviso-versao-188.test.js:742` | FONTE de `AvisoDeVersaoMudou.jsx` | não | nada |
| `tests/Feature/Publicador/Aprovacao/FotosDoPainelServiceTest.php:1611` | FONTE de `FotosDoPainelService.php` + `PainelDeRevisaoService.php` (**território VETADO**) | não | nada |
| `tests/Feature/Publicador/Aprovacao/PrecoDoPainelServiceTest.php:1471` | FONTE dos serviços de preço (**vetado**) | não | nada |

⚠️ **E há um `/⤢ Ampliar/` que é ASSERÇÃO POSITIVA e tem de continuar passando:**
`tests/js/publicador-painel-fotos-188.test.js:1361` cobra que a linha **`:607` do protótipo** seja o
"⤢ Ampliar" — é conferência do **protótipo**, não do código. **Não mexer.**
⚠️ **E um décimo lugar, que é DOCUMENTO:** `tests/Feature/Publicador/Aprovacao/FechamentoDaFase188Test.php:552` cobra que o roteiro da
188 **declare** a ausência. **Aquele documento é da 188; não se reescreve.**

### A3 — ⚠️⚠️ NÃO EXISTE rótulo de variação desenhado (INV-10): a linha "Variação" da §C7 fica sem fonte

O 188-08 **NÃO desenha** `tipo · variação` (`:623`): desenha **só o tipo**
(`resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx:297`). A **PERGUNTA-188-E foi DEVOLVIDA ao usuário**
(`.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-08-SUMMARY.md:45`), e a decisão registrada é **seguir sem o nome e anotar a lacuna** — a chave
do grupo é **canônica** (`cor=preto` / `COLOR=id:52049`) e o rótulo humano só vive em
`ResolvedorGruposImagem::rotuloDoGrupo`, **`private static`** em território **vetado**
(`App\Support\Publicador`).

➡️ **Para o 189-05 (a §C7 da `189-FID01.md`), a régua §2 resolve sozinha:** *"a 189 USA O QUE O
188-08 USAR"*. Como o 188-08 **não usa nada**, a linha **"Variação" não é desenhada** — e **não se
inventa rótulo nem se vaza a chave canônica para a tela**. ⚠️ **Isto NÃO é um quinto conflito
protótipo × regra do projeto: é lacuna de DADO já decidida pelo usuário em 19/10.**

### A4 — ⚠️ Referência pendente no código da 188: o constante `VARIACAO` **não existe**

`resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx:68` e `:297` mandam *"ver a divergência escrita em `VARIACAO`"`*, mas
**não há** `VARIACAO` naquele arquivo (medido: as duas únicas ocorrências do token são esses dois
comentários). ➡️ **É erro de REFERÊNCIA no docblock da 188-08**, não defeito de comportamento. **Não
é desta fase consertar** — fica registrado para quem for mexer naquele arquivo não procurar em vão.

### A5 — ⚠️ O wrapper `:604` existe, mas só é desenhado em `regenerating` (INV-05)

`CLASSES_OVERLAY` (`resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:205`) é **named export** e tem **exatamente** o estilo
do protótipo `:604`. Mas o wrapper só nasce com `regenerando === true` (`:372`).
➡️ **O 189-06 precisa afrouxar a CONDIÇÃO**, não criar o wrapper: o "⤢ Ampliar" é desenhado
**sempre que há imagem selecionada** (`P.onZoom` só exige `rv.sel`, protótipo `:1319`), **inclusive
em `regenerating`** — e aí os dois ocupam o mesmo wrapper, que é o `flex gap-[6px]` de `:604`.

### A6 — ⚠️ BOA NOTÍCIA: `escritasDaColuna` já aceita a oferta e a versão POR PARÂMETRO (INV-03)

A PERGUNTA-189-B exige que toda ação do lightbox use **a oferta e a versão DO LIGHTBOX**, nunca a do
painel por baixo. Medido: `escritasDaColuna({empresaId, ofertaId, versao, onConflito})`
(`resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx:209`) é **named export** e recebe os três **por parâmetro**.
➡️ **O 189-07 CHAMA essa função com o contexto do lightbox — não precisa de rota nova nem de helper
novo.** ⚠️ E a escrita continua passando pelo **helper único** `escreverNoPainel`.

### A7 — ⚠️ O degrau do lightbox no `Escape` **não existe no código**: está apenas DECLARADO (INV-01)

`resolverAtalhoDoPainel` **não tem** parâmetro de lightbox. O degrau está escrito em prosa em
`resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:479`-`:481` e `:560`-`:562`, com a instrução *"Não antecipar o lightbox"*.
➡️ **O 189-06 ACRESCENTA o parâmetro** (e a cadeia passa a ser `modal → lightbox → painel`).
⚠️ **E a ordem medida do protótipo `:1014` é `modal → lightbox → menu → painel`** — reconfirmada por
`sed` nesta wave.

---

## §1 — OS TREZE ITENS

**Forma fixa:** `INV-NN: <o que> — <arquivo>:<linha> — <valor medido>`.
Cada um produzido **por comando**, escrito ao lado.

INV-01: os named exports de `PainelDeRevisao.jsx` e as duas assinaturas que a 189 consome — `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:297` — **22 named exports** (`grep -nE '^export '`). `escreverNoPainel(pedido = null)` em `:297`, com `{rota, parametros, corpo, versao, onConflito}` e retorno `boolean`; `resolverAtalhoDoPainel(evento = null)` em `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:550`, com `{tecla, temModal, alvoEhCampo, anteriorId, proximaId, imagemAtual, totalDeImagens}` → `{acao, oferta, imagem}|null`. ⚠️ **NENHUM degrau de lightbox previsto no CÓDIGO** — só DECLARADO em `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:479` e `:560` (é ACHADO A7, não erro). Comando: `grep -nE '^export ' <arquivo>` e `sed -n '540,620p'`.

INV-02: onde o painel guarda a FOTO SELECIONADA e como ←/→ a trocam — `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx:350` — **estado LOCAL da página**: `useState({ oferta: null, indice: 0 })`. O índice VIVO é **derivação PURA** em `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx:841` (`fotoAberta.oferta === ofertaDoPainel ? fotoAberta.indice : 0` — oferta diferente volta à foto 1, **sem `useEffect`**); a troca é `trocarFoto(indice)` em `:844`, que **clampa nas duas pontas** (`alvo < 0 || alvo >= totalDeFotos` → ignora); desce para a coluna como `selecionada`/`onSelecionar` (`:860`) e para o painel como `imagemAtual`/`onTrocarImagem` (`:1317`). ⚠️ **NÃO está na URL.** Comando: `grep -n 'fotoAberta\|indiceDaFoto\|trocarFoto' <arquivo>`.

INV-03: os handlers de Aprovar foto e Reprovar, e a rota de cada um — `resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx:209` — `escritasDaColuna({empresaId, ofertaId, versao, onConflito})` (**named export**) devolve `{aprovarSemAlerta, aprovarImagem}`; `aprovarImagem(imagemId)` usa `ROTA_APROVAR_IMAGEM = 'mlb.anuncios.meus.aprovacao.painel.imagem.aprovar'` (`resources/js/Components/Mlb/Publicador/aprovacao/ColunaDeFotos.jsx:116`) e `aprovarSemAlerta` usa `ROTA_SEM_ALERTA` (`:114`). **Reprovar NÃO é rota direta da coluna**: é a prop `onReprovar` (`:276`, consumida em `:309`), que a página liga a `abrirModalDeReprovar`; a escrita acontece no `onSubmeter` do modal, em `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx:1262`, com `'mlb.anuncios.meus.aprovacao.painel.imagem.reprovar'` e `{company, oferta, imagem}` no caminho. ⚠️ **As DUAS passam pelo helper único `escreverNoPainel`, com `versao_esperada` SEMPRE no corpo.** Comando: `grep -n 'ROTA_\|escreverNoPainel\|onReprovar'`.

INV-04: **ONDE o `ModalDeReprovarImagem` é MONTADO** na árvore JSX — `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:717` — **VEREDICTO: `FORA`**. Medido por **estrutura** (não por aparência): `<aside className={CLASSES_PAINEL}>` abre em `resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx:711` e **`</aside>` fecha em `:715`**; o slot `{noModais}` é renderizado em **`:717`** — **irmão** do `<aside>`, dentro do mesmo fragmento. A página passa o modal por esse slot: `<ModalDeReprovarImagem` em `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx:1249`, dentro do array `noModaisDoPainel`, ligado em `:1328` (`modais={noModaisDoPainel}`). ⚠️ **O 189-07 NÃO move montagem nenhuma** (ACHADO A1). Comando: `grep -n '<aside className={CLASSES_PAINEL}>\|</aside>\|{noModais}'`.

INV-05: o wrapper de `:604` em `ImagemGrandeDoPainel.jsx` e as props do componente — `resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:205` — **EXISTE** como named export `CLASSES_OVERLAY = 'absolute bottom-[10px] right-[10px] flex gap-[6px]'`, **idêntico** ao protótipo `:604` (`position:absolute;right:10px;bottom:10px;display:flex;gap:6px`). ⚠️ **Mas só é DESENHADO com `regenerating`**: `const regenerando = dados.estado === ESTADO_REGENERANDO` em `:354` e o ternário em `:372`. A única prop do componente é `imagem` (`resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:348`), e `imagem` ausente → `null` (nada no DOM). Comando: `grep -n 'CLASSES_OVERLAY\|regenerando' <arquivo>`.

INV-06: **TODO** gate de ausência de "Ampliar" do repositório — `tests/js/publicador-painel-fotos-188.test.js:639` — **NOVE asserções em JS + DUAS em PHP**, mapeadas uma a uma na tabela do ACHADO **A2** acima, com o teste dono e o que cada uma mede. O que o 189-06 inverte é **só** `tests/js/publicador-painel-fotos-188.test.js:639` e `:640`; a ausência de "Gerar novamente com IA" fica em `tests/js/publicador-painel-fotos-188.test.js:641` e **NÃO é afrouxada**; a asserção POSITIVA do protótipo em `tests/js/publicador-painel-fotos-188.test.js:1361` continua intocada. ⚠️ **A asserção de `/Ampliar/` sobre a FONTE de `PainelDeRevisao.jsx` vive em `tests/js/publicador-painel-casca-188.test.js:1035`** — e é a armadilha do A2. Comando: `grep -rn 'Ampliar' tests/` + script que sobe até o `test(`/`public function` dono.

INV-07: o **ordinal** e o **cabeçalho APOIO** do item de verificação — `resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx:73` — os DOIS são **named export**, e a 189 os **IMPORTA** (régua §2): `CABECALHO_DAS_VERIFICACOES = 'VERIFICAÇÃO AUTOMÁTICA · APOIO'` (`:73`, o `·` é U+00B7) com `CLASSES_CABECALHO_VERIFICACOES = 'text-[11px] font-semibold tracking-[0.08em] text-pub-txt-4'` (`:127`) — **idêntico** ao protótipo `:369`, re-medido por `sed`; o ordinal é elemento PRÓPRIO com `CLASSES_ORDINAL = 'flex-none text-[12px] font-bold'` (`:141`), desenhado em `:243`, **lido da prop e NUNCA recontado** (`item.ordinal`); a peça inteira é `VerificacoesAutomaticas` (`:219`). ⚠️ **`CLASSES_ITEM` (`:130`) tem `items-start` e o item do LIGHTBOX NÃO tem** (protótipo `:760#1` × `:633` — re-medido): **não reusar `CLASSES_ITEM` cru**. Comando: `grep -nE '^export '` + `sed -n '219,250p'`.

INV-08: o selo de estado e o mapa `estado_cor` → classe — `resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:72` — **named exports**: `TEXTO_DA_COR` (`:72`) e `FUNDO_DA_COR` (`:81`), as duas com as **cinco** chaves do servidor (`verde`, `ambar`, `vermelho`, `neutra`, `neutra_fraca`); `COR_NEUTRA = 'neutra'` (`:95`) é o default de chave desconhecida — **e ele NUNCA cai no verde**, porque *"afirmar aprovação que não existe é o pior lado do erro"*; o resolvedor é `classeDaCor(mapa, chave)` (`:108`, com `hasOwnProperty.call` — nunca `obj[chave]` cru); o selo é `SeloDeEstado({imagem})` (`:325`), que devolve `null` com `estado_rotulo` vazio. ⚠️ **A 189 IMPORTA os três** — segunda cópia do mapa divergiria. Comando: `grep -nE '^export ' <arquivo>`.

INV-09: a borda da miniatura com a seleção do **CLIENTE** — `resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx:90` — `chaveDaBorda(estado, selecionada, comAlerta)` (**named export**, `:90`) espelha `FotosDoPainelService::borda()` **ramo por ramo**, com `selecionada` vencendo (`:91`); a classe sai de `classeDaBorda(chave)` (`:101`) sobre o mapa `BORDA_DA_MINIATURA` (`:68`, **cinco** chaves); o anel da selecionada é `CLASSES_ANEL = 'ring-2 ring-pub-accent'` (`:118`) — **`ring`, não `shadow-[…#FACC15]`**, porque TOK-15 proíbe hex na tela. ⚠️⚠️ **O AVISO-189-1 foi ATENDIDO**: o 188-08 **NÃO** lê a `borda` crua do servidor (está declarado em `resources/js/Components/Mlb/Publicador/aprovacao/GradeDeMiniaturas.jsx:16`-`:21`: *"o `borda` e o `anel` que chegam... usá-los crus deixaria o anel amarelo na imagem 0"*). ⚠️ **A GEOMETRIA é outra no lightbox**: aqui a miniatura é `rounded-[8px]` em grade de 6 colunas (`:115`); no lightbox é `56×56` `rounded-pub-control-sm` em `flex` (§B9) — **a REGRA se importa, a classe de caixa não**. Comando: `grep -nE '^export '`.

INV-10: o rótulo da variação (`tipo · variação`, protótipo `:623`) — `resources/js/Components/Mlb/Publicador/aprovacao/BlocoDaImagemSelecionada.jsx:297` — **NÃO EXISTE**: o 188-08 desenha **só o `tipo_rotulo`** (`{tipo === null ? null : <span className={CLASSES_SUBTITULO}>{tipo}</span>}`), e a decisão está registrada em `.planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-08-SUMMARY.md:45` (*"o rotulo de VARIACAO de `:623` NAO e desenhado… PERGUNTA-188-E DEVOLVIDA"*) e na tabela de lacunas em `:752`. ⚠️ **Nenhum named export de variação**, e o rótulo humano é `private static` em território vetado. ➡️ **ACHADO A3.** Comando: `grep -n 'VARIACAO\|tipo_rotulo\|CLASSES_SUBTITULO'` + `grep -n 'varia' 188-08-SUMMARY.md`.

INV-11: o estado declarado de `url_arquivo` NULA na imagem do painel — `resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:362` — `url === null ? <span className={CLASSES_PLACEHOLDER}>{rotulo}</span> : <img …>`, com `CLASSES_PLACEHOLDER = 'absolute inset-0 grid place-items-center px-[14px] text-center text-[12px]'` (**named export**, `resources/js/Components/Mlb/Publicador/aprovacao/ImagemGrandeDoPainel.jsx:159`). O texto é o `rotulo` (ou o `titulo`) da própria imagem — **nunca** `<img src="">`, *"string vazia num `src` faz o navegador recarregar a própria página"*. ⚠️ **A §H1 da `189-FID01.md` manda o lightbox usar O MESMO estado** — e ele é **importável**. Comando: `grep -n 'CLASSES_PLACEHOLDER'`.

INV-12: a contagem atual do id `vocabulario-tamanhos` — `tests/js/publicador-vocabulario-novo.test.js:643` — **681 testes / 681 pass / 0 fail / 0 skipped** (`node --test --test-reporter=tap tests/js/publicador-vocabulario-novo.test.js`, ANSI tirado antes de ler `# tests`). ⚠️ **É o MESMO número que a 188-12 declarou (`>=681`)**, e isso é esperado: o arquivo faz **7 sub-testes por arquivo varrido**, e **este plano não cria `.jsx` nenhum** — acrescentar um valor ao `Set` de `TAMANHOS_PERMITIDOS` (`tests/js/publicador-vocabulario-novo.test.js:643`) **não cria sub-teste**. ➡️ Os manifestos da 189 usam **`>=681`**, sem subir o piso. Comando: `node --test --test-reporter=tap <arquivo> | grep '^# tests'`.

INV-13: as props-raiz da página `Aprovacao` conferidas por **conjunto fechado** — `app/Http/Controllers/MlbPublicadorAprovacaoController.php:598` — **NÃO EXISTE** asserção de conjunto exato das chaves-raiz. Medido: `grep -rn 'array_keys($props' tests/Feature/Publicador/Aprovacao/` acha **uma** ocorrência, em `tests/Feature/Publicador/Aprovacao/AquecerTarifasDaFilaTest.php:616`, e ela é da tela de **Publicações** (não da Aprovação) e usa `assertArrayHasKey` (conjunto **ABERTO**); os dois `assertSame(array_keys(…))` de `tests/Feature/Publicador/Aprovacao/FilaDeAprovacaoRotaTest.php:503` e `:505` são sobre chaves **ANINHADAS** (`props['faixa'][0]` e `props['margem_minima']`), não a raiz. O payload é montado em `app/Http/Controllers/MlbPublicadorAprovacaoController.php:365` e a chave `'painel'` (recortada ao `?oferta=`) entra em `:483`. ➡️ **O 189-02 pode acrescentar a prop-raiz `lightbox` sem quebrar conjunto fechado nenhum.** Comando: `grep -rn 'array_keys(\$props' tests/Feature/Publicador/Aprovacao/`.

---

## §2 — COMO ESTE INVENTÁRIO FOI MEDIDO, e o que ele NÃO prova

**Medido por COMANDO** (`grep -n`, `sed -n`, `node --test`, scripts `.cjs` escritos por ferramenta no
scratchpad), nunca de memória e nunca por leitura corrida. Cada item traz o comando ao lado.

⚠️ **O que este documento NÃO prova:**
- **não prova comportamento de navegador.** Não há navegador nesta máquina; o `mysqld` local está
  parado, o banco não tem empresa com `ml_token` (a rota dá **404**), `pub_aprovacao_ofertas` está
  **vazia** e a fila só ganha oferta na **Fase 194**. Onde está escrito *"a tela mostra X"*, leia
  **"o HTML gerado contém X"**;
- **não prova que o `escreverNoPainel` funciona em runtime** — o que está medido é a FONTE dele e o
  caminho do `inertia:invalid` que o 188-12 declarou;
- **não é conferência visual.** As conferências visuais das Fases **187 e 188** seguem **ABERTAS** e
  **separadas**; nenhuma é presumida feita.

⚠️ **Todo `arquivo:linha` citado aqui é cobrado por gate** (`contrato-188-inventariado`): o arquivo
tem de **existir** e a linha tem de ser **<= o tamanho dele**. Ponteiro fora do arquivo é erro de
**REFERÊNCIA** — o modo como esta classe de documento mais erra nesta milestone (a re-medição da
188-01 achou **33** divergências: 20 por REFERÊNCIA, 11 por OMISSÃO, 2 por VALOR).
