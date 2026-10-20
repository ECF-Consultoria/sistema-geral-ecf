# Fase 189 — O FECHAMENTO MEDIDO

> **O lightbox da imagem ampliada: tela cheia por portal, zoom de 2,5× que segue o cursor,
> marcadores numerados batendo com as verificações, e a lateral de 320px.**
>
> Escrito pelo plano **189-08**, wave **7 de 7**, em **20/10/2026**.
>
> ⚠️⚠️ **TODO número deste documento foi RE-MEDIDO nesta sessão** — nenhum foi copiado das sete
> SUMMARYs nem do `189-BASELINES.md`. A régua é a que o fechamento da Fase 186 teve de aprender à
> força: *"um fechamento que afirma número velho é pior que um fechamento sem número"*.
>
> ⚠️⚠️ **E a FORMA FIXA é o que impede o "ajuste" silencioso:** critério que não passa recebe
> **RESSALVA** ou **REPROVA**, com a razão e com o **dono** — e **nunca é reescrito para caber**.

---

## ⚠️⚠️ §0 — A FASE **NÃO ESTÁ DEPLOYADA**, e isso muda o que este documento pode afirmar

| afirmação | o que ela significa aqui |
|---|---|
| *"a Fase 189 entregou X"* | **X está no CÓDIGO, commitado nesta árvore local** |
| a afirmação de que esta fase já estaria rodando para os usuários | ⚠️ **FALSA.** Esta fase **NÃO foi deployada**, **não foi empurrada** (`push`) e **não tocou no VPS** |
| *"o usuário pode conferir X"* | ⚠️ **só depois de um deploy que ELE autorize** — e a §11 diz isso na primeira linha |

⚠️ **As Fases 186 e 187 estão em produção; as 188 e 189 NÃO.** O Coverage Map do `ROADMAP.md` tem
de usar, para a 189, a forma **"Entregue no CÓDIGO (20/10/2026) — ⚠️ NÃO DEPLOYADO"**,
deliberadamente **diferente** da forma *"em produção"* das duas primeiras. Copiar a forma delas
afirmaria um deploy inexistente (ameaça **T-189-60**, *Repudiation*).

⚠️ **E a árvore local está 58 commits à frente de `origin/main`** — há trabalho de **outras duas
sessões** aqui dentro que também não foi publicado, incluindo o produtor de ofertas (`c810a8ac`) e o
conserto das cinco telas pretas (`85c3b46b`).

---

## ⚠️⚠️ §1 — NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA

**Todo** render desta fase foi `esbuild` + `react-dom/server`. ➡️ Neste documento e nas sete
SUMMARYs, *"a tela mostra X"* significa **exatamente** *"o HTML gerado contém X"*, e nada além.

| o que NÃO foi feito, e por quê | medida |
|---|---|
| nenhum navegador foi aberto | não há navegador nesta máquina |
| nenhuma rota foi visitada de verdade | o banco local **não tem empresa com `ml_token`** → a rota do painel dá **404** |
| nenhuma oferta real foi aberta | `pub_aprovacao_ofertas` está **VAZIA**: ⚠️ **a fila nasce vazia**, e só ganha oferta de verdade na Fase 194 |
| `mysqld` local | **parado** — nada desta fase depende dele |

⚠️⚠️ **E há um limite mais fino que isto, medido pela quick `261020-tdz`:** *"a página renderiza"*
significa *"o render de SERVIDOR executa o corpo e devolve HTML sem lançar"* — **e nem isso cobre o
painel**, porque `renderToStaticMarkup` **não desenha portal**. Medido: o HTML da página de aprovação
tem **exatamente o mesmo tamanho** com `painel: null` e com payload cheio (**`28119` nos dois**).
➡️ Um gate de render que só olhasse o tamanho do HTML seria **vazio por construção**.

⚠️ **O que esta fase NÃO prova, declarado:**
- o **MOVIMENTO do zoom** (a origem do `transform` é **função pura provada**; o acompanhamento do
  cursor em runtime, não);
- o **runtime do teclado** (`Esc`, `←/→`, `↑/↓`) e a **devolução do foco** (as funções e os handlers
  são provados; o despacho pelo navegador, não);
- o **portal em runtime** (travado como **PROXY DE FONTE**, declarado no próprio gate — ver §10).

➡️ **Por isso os critérios 2 e 7 saem RESSALVA, e não OK** (§4).

---

## §2 — AS **7** LINHAS DE FORMA FIXA, RE-MEDIDAS NESTA SESSÃO

```
PARTIDA:   55d972414f519d6a8006c767643c6a8f18b7ad84   (curto: 55d97241) — a PRIMEIRA acao desta sessao
COMMIT:    55d97241   <- as medicoes de suite/build/manifest/prototipo abaixo rodaram NESTE HEAD
BUILD:     exit 0 em 44 s
MANIFEST:  498 entradas / 203 paginas (lido pelo JSON) / isca ausente / controle presente
JS:        6012 / 6011 pass / 1 fail / 0 skipped
PHP:       2631 / 24041 / 0        (RECORTE: tests/Unit/Publicador + tests/Feature/Publicador)
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html - 1432 linhas - sha256 68624883ce35547e
```

⚠️⚠️ **`PARTIDA` e `COMMIT` são a MESMA coisa aqui, e isso é DECLARADO, não descuido.** As medições
rodaram no HEAD de partida, com a árvore **livre dos arquivos deste plano** (`git diff HEAD` vazio
nos meus caminhos, e os `??` alheios não entram) — ou seja, **num estado COMMITADO e
reproduzível**. ➡️ O delta que **este** plano acrescenta ao recorte PHP são os **9 métodos** do
`FechamentoDaFase189Test.php`, medidos **separadamente** em §2.4 e **somados por aritmética
declarada**, nunca escondidos dentro da linha `PHP:`.

⚠️ **`PARTIDA` é referência de LEITURA, NUNCA extremo de range.** O range cru nesta árvore traz
trabalho de outras sessões — a 187-11 mediu **111 commits, 43 alheios**, e o diff cru **acusando 12
de 60 alvos vetados**. O território desta fase é provado por **ESCOPO DO ASSUNTO** (§3).

## §2.1 — A **PROCEDÊNCIA** DE CADA LINHA: qual comando, em que commit

| linha | comando exato |
|---|---|
| `PARTIDA` | `git rev-parse --short HEAD` e `git rev-parse HEAD` — **a primeira ação desta sessão**, antes de qualquer leitura |
| `COMMIT` | `git rev-parse --short HEAD`, reconferido antes de cada suíte (nenhum commit meu entre as medições) |
| `BUILD` | `T0=$(date +%s); npm run build; echo $?; T1=$(date +%s); echo $((T1-T0))` |
| `MANIFEST` | `node scratchpad/manifest-189-08.cjs public/build/manifest.json` — lê o **JSON**, nunca `grep` |
| `JS` | `sh tests/gates/verificar-testes.sh --suite js` (com `EXIT=$?` **medido na linha seguinte**, sem pipe) |
| `PHP` | `sh tests/gates/verificar-testes.sh --suite php` (ele roda `tests/Unit/Publicador` + `tests/Feature/Publicador`) |
| `PROTOTIPO` | `find . -maxdepth 3 -name Aprovacao.dc.html` → `test -e` → `wc -l` → `sha256sum` |

⚠️⚠️ **Por que o `MANIFEST` é lido pelo JSON e nunca por `grep`:** o hash do bundle **sai às vezes com
hífen** (`assets/app--5yuaubs.js`, medido na abertura desta fase) e um `grep -E "app-[A-Za-z0-9]*\.js"`
**não casaria**. ⚠️ Nesta rodada ele **não** saiu com hífen (`assets/app-D68bhFmO.js`) — o que **não
enfraquece a regra, fortalece**: o hífen é **do sorteio do build**, então um `grep` que funcione hoje
**quebra amanhã sem ninguém mexer em nada**. `sha256` do `manifest.json` desta rodada:
**`8d562b9d212bb245`**.

| sonda do manifest | resultado |
|---|---|
| **ISCA** `resources/js/Pages/Mlb/NaoExiste9z7.jsx` | **ausente** ✓ (se saísse presente, a leitura estaria cega) |
| **CONTROLE** `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` | **presente** ✓ → `assets/Aprovacao-DeSGKB74.js` |
| **CONTROLE** `resources/js/app.jsx` | **presente** ✓ → `assets/app-D68bhFmO.js` |
| `resources/css/app.css` **como chave** | **ausente** — e está **certo** (divergência **N5** da 187, reconfirmada) |

⚠️⚠️ **Página React fora do manifest SOME da aplicação, sem erro nenhum.** É por isso que o controle
positivo existe: um manifest que perdesse a `Aprovacao.jsx` passaria num gate que só contasse
entradas.

⚠️⚠️ **E o INVARIANTE FALSO, reconfirmado nesta rodada:** os `.jsx` novos desta fase **NÃO entram no
manifest**.

```
COMPONENTE_NO_MANIFEST[LightboxDaImagem.jsx]  = NAO
COMPONENTE_NO_MANIFEST[LateralDoLightbox.jsx] = NAO
COMPONENTE_NO_MANIFEST[PainelDeRevisao.jsx]   = NAO
```

➡️ **Componente aninhado é FUNDIDO no chunk da PÁGINA; só a PÁGINA aparece.** Todo gate que afirme
*"o componente novo entra no manifest"* é **FALSO neste projeto** — vazio por construção se conferir
ausência, e **reprova trabalho correto** se exigir presença.

### ⚠️ O protótipo **não se moveu** — e o diretório é CITADO

`find . -maxdepth 3 -name Aprovacao.dc.html` devolve **só**
`./design_handoff_publicador/Aprovacao.dc.html`. ⚠️ Há **DOIS** pacotes de handoff na árvore
(`design_handoff_publicador/` e `new_layout_publicador/`) **com conteúdo diferente** — por isso o
diretório é sempre citado. **`new_layout_publicador/Aprovacao.dc.html` NÃO existe.**

`sha256` completo: `68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2`; **1432** linhas
por `wc -l`. ➡️ **A tabela FID-01 inteira continua medindo o MESMO documento**, nas três medições da
Fase 187, nas sete da 188 e nas sete desta.
⚠️ **`1432` × `1433` é MÉTODO DE MEDIDA, não divergência:** `split(/\r?\n/)` sobre este arquivo dá
**1433** (a última linha termina com `\n` e o `split` produz um elemento vazio no fim). A contagem
oficial é **`wc -l`**.
⚠️ **Nenhum dos dois pacotes é versionado (`??`)** — gate que dependa de lê-los **degrada para `skip`
EXPLÍCITO e NOMEADO**, nunca para "conferido".

## §2.2 — A TABELA MECÂNICA contra o `189-BASELINES.md` — uma linha por número

Referência: abertura da fase, commit **`e250f9fb`**, 20/10/2026 (`189-BASELINES.md` §1).
⚠️ **ACIMA é ESPERADO** (as 7 waves acrescentam testes, e duas sessões paralelas também);
**ABAIXO é regressão.** ⚠️⚠️ **A referência NÃO se "ajusta".**

| número | abertura (`e250f9fb`) | re-medido (`55d97241`) | veredicto | razão |
|---|---|---|---|---|
| `BUILD` exit | 0 | **0** | **OK — IGUAL** | — |
| `BUILD` segundos | 85 s | **44 s** | **INFORMATIVO** | tempo é da máquina, não régua |
| `MANIFEST` entradas | 498 | **498** | **OK — IGUAL** | ⚠️ os **2** `.jsx` desta fase produziram **ZERO** entradas novas — são **componentes**, não pontos de entrada do Vite |
| `MANIFEST` páginas | 203 | **203** | **OK — IGUAL** | esta fase **não cria página**: a `Aprovacao.jsx` é da **Fase 187** |
| `MANIFEST` isca | ausente | **ausente** | **OK** | a leitura não está cega |
| `MANIFEST` controle | presente | **presente** | **OK** | a página do painel **existe e compila** |
| `JS` testes | 5871 | **6012** | **OK — ACIMA** | **+141**, atribuídos **um a um** em §2.3 (a soma FECHA EXATO) |
| `JS` passando | 5870 | **6011** | **OK — ACIMA** | **+141** |
| `JS` falhas | 1 | **1** | **OK — IGUAL** | a **mesma** pré-existente da **Fase 87**, nomeada por SEMÂNTICA e **atribuída por execução ISOLADA** em §2.4 |
| `JS` skipped | 0 | **0** | **OK** | nenhuma camada dormindo |
| `PHP` testes (recorte) | 2583 | **2631** | **OK — ACIMA** | **+48**, atribuídos por `git show --name-only` em §2.3 (a soma FECHA EXATO) |
| `PHP` asserções | 23.684 | **24.041** | **INFORMATIVO** | ⚠️ **não é invariante nesta suíte** — ver abaixo |
| `PHP` falhas (recorte) | 1 | **0** | ⚠️⚠️ **ACHADO BOM** | a falha **ALHEIA** que a 189-01 devolveu como pergunta foi **CONSERTADA NA RAIZ** — §2.5 |
| `PROTÓTIPO` linhas | 1432 | **1432** | **OK** | não se moveu |
| `PROTÓTIPO` sha256 | `68624883ce35547e` | **`68624883ce35547e`** | **OK** | não se moveu |

⚠️ **O número de ASSERÇÕES do PHP não é invariante nesta suíte**, e a Fase 188 já provou por quê: os
casos de **fronteira de dia** percorrem **ramos diferentes** conforme a hora, e ramo diferente
exercita número diferente de asserções (mediu-se **−5** com testes idênticos). ➡️ **Compare TESTES e
FALHAS; trate asserções como informativo.** O harness faz exatamente isso: tem `REF_PHP_TESTS` e
**não** tem referência de asserções. ⚠️⚠️ **E NÃO existe `REF_PHP_FALHAS` — ele não deve passar a
existir**, e o próprio `verificar-testes.sh` declara isso na fonte.

## §2.3 — O Δ ATRIBUÍDO, item por item — e as duas somas FECHAM EXATO

⚠️⚠️ **Atribuição por `git show --name-only` do commit que CRIOU cada arquivo, NUNCA por range** — o
range englobaria os commits da própria fase, e foi exatamente nisso que a 189-07 caiu.

**JS: `5871 → 6012` = `+141`**

| o que | testes | medido por |
|---|---|---|
| `publicador-lightbox-tokens-189.test.js` (189-01) | **3** | `189-01 tokens-e-tamanhos-189` → `# tests 3` |
| `publicador-lightbox-casca-189.test.js` (189-03) | **27** | `189-03 lightbox-casca` → `# tests 27` |
| `publicador-lightbox-camadas-189.test.js` (189-04) | **16** | `189-04 camadas-e-css` → `# tests 16` |
| `publicador-lightbox-lateral-189.test.js` (189-05) | **27** | `189-05 lateral-do-lightbox` → `# tests 27` |
| `publicador-lightbox-painel-189.test.js` (189-06) | **22** | `189-06 lightbox-no-painel` → `# tests 22` |
| `publicador-lightbox-acoes-189.test.js` (189-07) | **25** | `189-07 acoes-do-lightbox` → `# tests 25` |
| **subtotal dos gates novos da Fase 189** | **120** | |
| `publicador-vocabulario-novo.test.js`: **681 → 695** | **+14** | cresce **por construção** (um sub-teste por arquivo varrido) |
| `publicador-pagina-aprovacao-tdz-261020.test.js` (**quick ALHEIA**) | **7** | `261020-01 pagina-aprovacao-tdz` → `# tests 7` |
| **TOTAL** | **141** | **`5871 + 141 = 6012`** ✓ |

⚠️⚠️ **A soma fechar EXATO é a prova de que a atribuição está COMPLETA** — se houvesse um `.test.js`
alheio não contado, ela não fecharia. ⚠️ E os cinco arquivos de teste **alheios** que esta fase
EDITOU **não mudaram de contagem** (medido: `188-07 casca-do-painel` **45**, `188-08
coluna-de-fotos` **32**, `187-01 tokens-da-fila` **17**) — **nenhum número alheio foi subido**.

**PHP (recorte): `2583 → 2631` = `+48`**

| arquivo novo no recorte | métodos | criado por |
|---|---|---|
| `tests/Feature/Publicador/Agente/ConfiguracaoEExecucaoDoAgenteTest.php` | **14** | `1253cc86 feat(quick-261020-nsz): AG-01 do agente` — ⚠️ **ALHEIO** |
| `tests/Feature/Publicador/Agente/LimitesPausaERecuperacaoDoAgenteTest.php` | **8** | `e20a7653 feat(quick-261020-ozm): AG-06 do agente` — ⚠️ **ALHEIO** |
| `tests/Feature/Publicador/Agente/EntradaDoPreparoDoAgenteTest.php` | **8** | `1a823d6f feat(quick-261020-nzr): AG-02 do agente` — ⚠️ **ALHEIO** |
| `tests/Feature/Publicador/Agente/EncaminharParaAprovacaoTest.php` | **6** | `c810a8ac feat(quick-261020-oeg): AG-03 do agente` — ⚠️ **ALHEIO** |
| `tests/Feature/Publicador/Aprovacao/LightboxDoPainelServiceTest.php` | **9** | `95c4c12f test(189-02)` — **DESTA FASE** |
| `tests/Feature/Publicador/Aprovacao/FechamentoDeBaselines189Test.php` | **3** | `337f29c5 test(189-01)` — **DESTA FASE** |
| **TOTAL** | **48** | **`2583 + 48 = 2631`** ✓ |

➡️ **36 de 48 são ALHEIOS** (as quatro quicks do Agente Publicador, de outra sessão) e **12 são
desta fase**. ⚠️ **E o conserto de raiz `2444feea` NÃO acrescentou método nenhum** (12 → 12 no
`FotosDoPainelServiceTest`): o `tests=3` do `contagens-188-03.txt` ficou **igual**, e só o
`assertions` subiu (**84 → 91**) — porque o gate ficou **MAIS FORTE**, não porque alguém elevou um
número.

⚠️ **O que ESTE plano acrescenta, medido separadamente e NÃO embutido na linha `PHP:`:** os **9**
métodos `_189_08` do `FechamentoDaFase189Test.php` (3 por id, nos três ids). ➡️ Depois do commit
deste plano, o recorte PHP passa a **2640**.

## §2.4 — AS FALHAS QUE **NÃO SÃO DESTA FASE**, nomeadas por SEMÂNTICA e **NÃO corrigidas**

### JS — a única: `tests/js/estrutura-grade-glide.test.js` (Fase 87)

Caso **"Características secundárias nasce recolhido (é o grupo que mais infla)"**.
**Atribuída por execução ISOLADA**, como a régua exige:

```
node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js
→ not ok 17 - Caracteristicas secundarias nasce recolhido (e o grupo que mais infla)
→ # tests 19 · # pass 18 · # fail 1 · # skipped 0
```

É a pré-existente herdada, e é o `REF_JS_FALHAS=1` cravado no harness. **NÃO corrigida.**
⚠️ **E a régua segue valendo: qualquer OUTRA falha, ou mais de uma no JS, É DA FASE.**

### PHP (recorte) — **ZERO falhas**, e as duas que oscilam **passaram**

A dupla de `tests/Feature/Publicador/CockpitDaContaTest.php` (Fase 179, `:966` e `:1318`), que
**oscila com a hora do dia**, está **VERDE nesta medição**.
⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** — é um dos
caminhos fantasma desta milestone. O real está **FORA** de `Aprovacao/`.

### ⚠️ As falhas **FORA do recorte**, declaradas e não medidas aqui

`CalcularFaixaTest` (9) · `CompanyServiceTypeTest::test_service_type_aceita_polo` (1) · `Phase39` (2)
— **nenhuma é desta fase**, e nenhuma está dentro do recorte que o harness mede. ⚠️ **Elas não
foram medidas nesta sessão** porque a suíte PHP inteira **não é medível nesta máquina** (§2.6).

### ⚠️⚠️ O ambiente que produz falha FANTASMA, e como atribuir

- **Corrida de `storage/framework/testing`** entre suítes simultâneas: produz falhas que **PASSAM EM
  ISOLAMENTO**. ➡️ **Atribua rodando o arquivo SOZINHO.**
- **Suíte longa com `npm run build` concorrente**: a quick `261020-tdz` mediu `5955 / 2 fail / 7
  skipped` numa rodada assim — **50 testes abaixo**, e **7 `skipped` onde havia 0**, porque três
  blocos degradam para `skip` NOMEADO quando `public/build/` não está pronto. Re-medida **sem
  concorrência: `6012 / 0 skipped`**. ➡️ *"Suíte longa em árvore compartilhada mede um alvo que se
  move."* **As medições deste documento foram feitas em série, uma por vez.**
- ⚠️⚠️ **NUNCA leia `${TMPDIR:-/tmp}/ultima-suite-php.log` durante uma execução** — é caminho **FIXO e
  COMPARTILHADO**; uma wave leu e reportou **`0 falhas` quando eram 11**. As linhas deste documento
  vêm da **saída direta** do harness, redirecionada para arquivo **próprio** no scratchpad.

## §2.5 — ⚠️⚠️ A FALHA QUE A 189-01 DEVOLVEU FOI **CONSERTADA NA RAIZ** — e é o **15º** caso

A 189-01 mediu uma falha **ALHEIA** no recorte e **devolveu como pergunta em vez de consertar**:
`FotosDoPainelServiceTest.php:609` reprovava porque o gate do **188-03** fixava o **número de linha**
dos cinco leitores de `EditorEmUso::`, e o commit `cd582f83` (do **outro dev**) acrescentou uma linha
acima de `:131`, deslocando três ponteiros para `:132`/`:181`/`:367`.

**Medido agora:**

```
git log -1 --diff-filter=M -- tests/.../FotosDoPainelServiceTest.php
→ 2444feea  2026-10-20 10:06:29 -0300
   fix(188-03): os cinco leitores do sinal ancorados por NOME, não por LINHA
```

➡️ **O conserto foi EXATAMENTE o de RAIZ que a 189-01 prescreveu** — *"o gate procurar `EditorEmUso::`
por SEMÂNTICA em vez de por número de linha"*:

| antes (cobrado do docblock) | depois |
|---|---|
| `'config/publicador.php:148'`, `'MlbPublicadorController:405'`, `'AgendadorDaFila:337'`, `'PreparoIaDoRascunhoService:131'`, `':180'`, `':366'`, `'GarantiaPadrao:192'`, `'ExcluirProdutoService:195'` — **OITO números de linha** | os **arquivos por NOME** e `'EditorEmUso::emUso'`, **zero número de linha** |

⚠️⚠️ **E NENHUM NÚMERO FOI SUBIDO:** `tests=3` ficou **igual**; só `assertions` subiu de **84** para
**91**, porque a lista por nome tem mais itens — o gate ficou **mais forte**. ➡️ Este é o **15º gate
de âncora frágil consertado na RAIZ nesta milestone**, e segue valendo o placar: **nenhum por
elevação de número.** O 14º foi o `contagens-186-02.txt:83` (`5f4fbb5f`, §14).

## §2.6 — ⚠️⚠️ A SUÍTE PHP **INTEIRA** NÃO É MEDÍVEL NESTA MÁQUINA — medido por mim

```
C:/xampp/php/php.exe artisan test
→ Fatal error: Allowed memory size of 536870912 bytes exhausted (tried to allocate 12288 bytes)
   in C:\xampp\htdocs\ecf_admin\ecf_admin\vendor\laravel\framework\src\Illuminate\Http\Testing\FileFactory.php
   on line 87
→ EXIT = 255
```

⚠️ **`-d memory_limit=-1` NÃO resolve**: o estouro acontece num processo **FILHO**, e o limite do
processo pai não o alcança. ➡️ **A régua desta fase é: MEÇA PELO RECORTE e DIGA que a inteira não é
medível.** É o que a linha `PHP:` deste documento declara, e é o que o `--suite php` do harness
mede.

---

## §3 — O **TERRITÓRIO**, PELOS DOIS CAMINHOS

### ⚠️⚠️ §3.0 — O AUTOR **QUASE NÃO** DISCRIMINA — e aqui o briefing perdeu da medição

O briefing desta sessão afirma: *"o autor não discrimina, as três máquinas usam `MB.ECF-100376`"`*.
**Medido:**

```
git log --format='%an' -120 | sort | uniq -c
→ 118  MB.ECF-100376
→   2  ECF Dev     (cd582f83 e b173fca6 — o OUTRO DEV)

autor nos 39 commits do ESCOPO DO ASSUNTO da Fase 189:
→  39  MB.ECF-100376   (um valor só)
```

➡️ **A conclusão do briefing está CERTA, a premissa está INCOMPLETA:** há **uma quarta identidade**
nesta árvore (`ECF Dev`), e ela **discrimina** os commits do outro dev. ⚠️ **Mas o autor continua
inútil como gate**, e a razão é de construção: **118 de 120** commits (e **todos os 39** do escopo
desta fase) carregam o MESMO nome — as três sessões de Claude Code são indistinguíveis por `%an`.
⚠️⚠️ **E o detalhe que vale registrar:** o único commit alheio que **de fato quebrou um gate** nesta
fase (`cd582f83`, o deslocamento de +1 da §2.5) **é** author-discriminável. ➡️ **O autor não foi
conferido, de propósito** — mas quem investigar deslocamento alheio tem aí uma pista barata.

### ⚠️ §3.1 — PROVA 1 e PROVA 2: por **ESCOPO DO ASSUNTO**, duas passadas independentes

```
ALVOS = 32   FANTASMAS = 0   COMMITS_DO_ASSUNTO = 39 (distintos: 39)
PASSADA1_CAMINHOS  = 40   PASSADA1_VIOLACOES = 0   (laco de `git show --name-only --format=` por commit)
PASSADA2_CAMINHOS  = 40   PASSADA2_VIOLACOES = 0   (uma varredura de `git log --name-only` + awk)
CONJUNTOS_IGUAIS   = SIM
ARQUIVOS_DE_PACOTE_TOCADOS_PELO_ESCOPO = 0
```

⚠️ **`test -e` em CADA um dos 32 alvos ANTES de qualquer diff** — o anti-fantasma vem primeiro,
sempre, porque `git diff -- <caminho inexistente>` sai **VAZIO** e vazio é **indistinguível de
"intocado"**.

⚠️ **ISCA NAS DUAS DIREÇÕES** (sem ela, *"0 violações"* é indistinguível de sonda morta — e a pergunta
*"o que precisaria estar errado no mundo para este gate falhar?"* teria a resposta **"nada"**, que é
ACHADO):

| isca | medida | leitura |
|---|---|---|
| **A** — pôr no veto **6 alvos que a fase TOCOU** (`PainelDeRevisao.jsx`, `ImagemGrandeDoPainel.jsx`, `ColunaDeFotos.jsx`, `publicador-painel-fotos-188.test.js`, `MlbPublicadorAprovacaoController.php`, `Aprovacao.jsx`) | **1 acerto em cada um dos 6** | a varredura **VÊ** |
| **A2** — o escopo de OUTRA fase sobre alvo que ELA tocou (`(188-07)` × `PainelDeRevisao.jsx`) | **2 acertos** | o veto **casa o que descreve**, mesmo fora desta fase |
| **B** — o escopo `189-NN` casando assunto de outra sessão (`(188-07)`, `(188-08)`, `(190-01)`, `(261019-`, `(quick-261020-tdz)`, `(261020-`) | **0 nos seis** | o recorte **não vaza** |
| **C** — `test -e` num caminho inventado (`app/Mcp/NaoExiste9z7`) | **ausente** | o anti-fantasma **VÊ** |

⚠️ **A contagem do gate perene NÃO depende de quantos commits existem:** o
`FechamentoDaFase189Test::test_o_territorio_189_...` **não chama git**. O anti-fantasma é **UMA**
asserção coletando os fantasmas; os arquivos da fase são **lista CONSTANTE**; e nenhum `return`
antecipado existe. ➡️ **A contagem é a MESMA com zero e com N commits no escopo.**

### ⚠️⚠️ §3.2 — AS **TRÊS FACES** DO LIMITE DO ESCOPO DO ASSUNTO — **todas se concretizaram**

**FACE 1 — SOBRE-CAPTURA: `git log --grep` lê a MENSAGEM INTEIRA, não só o assunto.**

| plano | pelo assunto | pela mensagem inteira | o que entrou a mais |
|---|---|---|---|
| 189-03 | **4** | 5 | `6274b0fd test(189-05): o RED das verificacoes numeradas…` |
| 189-05 | **6** | 8 | `42fc4752 feat(189-07): as acoes da foto…` · `d8b30fdf feat(189-03): o lightbox POR PORTAL…` |
| 189-06 | **9** | 10 | `d8b30fdf feat(189-03): o lightbox POR PORTAL…` |
| **soma** | **39** | **43** | **+4 sobre-capturados** |

➡️ Sem o recorte por **assunto**, bastaria uma fase futura **mencionar `(189-NN)` no corpo** para os
arquivos daquele commit entrarem na interseção e o gate **REPROVAR TRABALHO CORRETO**. ⚠️ **Isto
CORRIGE o briefing da 189-01**, que dizia que o filtro *"não captura"*: **é o contrário — ele
SOBRE-captura.**

**FACE 2 — SUB-CAPTURA: commit que toca arquivo da fase SEM o escopo no assunto.** Medido, e há
**dois** nesta janela:

| commit | assunto | arquivo da fase que ele tocou |
|---|---|---|
| **`62e5efae`** | `fix(gates): o veto de TDZ da 189-07 reprovava pelo proprio conserto` | **`tests/js/publicador-lightbox-acoes-189.test.js`** (gate do **189-07**) |
| **`85c3b46b`** | `fix(quick-261020-tdz): as CINCO telas pretas…` | `resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` (que a 189-06 também edita) |

➡️ **A varredura por assunto `(189-NN)` NÃO vê nenhum dos dois.** A conclusão do território não muda
(nenhum deles toca alvo **vetado**), mas *"escopo do assunto"* é **melhor que o range, não
perfeito** — e os dois casos estão declarados aqui e na §9/§14.

**FACE 3 — o autor quase não discrimina**: §3.0.

### ⚠️⚠️ §3.3 — OS **SEIS** CAMINHOS FANTASMAS QUE O ANTI-FANTASMA PEGOU **NESTA WAVE**

⚠️ Eu construí a lista de alvos a partir dos **NOMES** citados na `189-REGUA.md` §8, no `CLAUDE.md` e
no briefing. **O `test -e` rodou ANTES de qualquer diff, e reprovou 6 de 32 (19%).**

| # | caminho que eu CONSTRUÍ (fantasma) | caminho **REAL**, medido |
|---|---|---|
| 1 | `app/Support/Publicador/ArquivosDeImagem.php` | **`app/Support/Publicador/Imagem/ArquivosDeImagem.php`** |
| 2 | `app/Support/Publicador/ResolvedorGruposImagem.php` | **`app/Support/Publicador/Imagem/ResolvedorGruposImagem.php`** |
| 3 | `app/Services/Mlb/Publicacao/PublicacaoService.php` | **`app/Services/Publicador/PublicacaoService.php`** |
| 4 | `resources/js/Pages/Mlb/PainelCriativosIa.jsx` | **`resources/js/Pages/Mlb/components/PainelCriativosIa.jsx`** |
| 5 | `resources/js/Components/Mlb/KitCriativosGrade.jsx` | **`resources/js/Pages/Mlb/components/KitCriativosGrade.jsx`** |
| 6 | `app/Services/Publicador/Imagem/ImagemAssetService.php` | **`app/Services/Publicador/ImagemAssetService.php`** |

⚠️⚠️ **A LIÇÃO, e ela vale mais que os seis:** *"um NOME não é um CAMINHO"*. A régua e o briefing
citam **classes** (`App\Support\Publicador` *"inclui `ArquivosDeImagem`, `EditorEmUso`,
`ResolvedorGruposImagem`"*) — e dois dos três moram num **subdiretório**, enquanto o terceiro
(`EditorEmUso.php`) está no nível de cima. Um veto escrito a partir do nome é **gate sobre ZERO
casos**, e ele passa **VERDE para sempre**. ➡️ Com os **12** fantasmas já catalogados nesta milestone
(**TRÊS** de briefing do orquestrador), o total chega a **18** — e **o anti-fantasma é o único gate
que os pega**.

### §3.4 — A LISTA DOS **32** ALVOS, conferida no disco, uma por uma

```
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/Painel/FotosDoPainelService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/Painel/PainelDeRevisaoService.php
ALVO-INTOCADO: app/Models/PubImagemReferencia.php
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
ALVO-INTOCADO: tests/js/publicador-header-zindex-ordem.test.js
ALVO-INTOCADO: tests/js/estrutura-meus-anuncios.test.js
ALVO-INTOCADO: resources/js/Layouts/AppLayout.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/CascaDeModal.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/ToastDaFila.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/BarraDeSelecao.jsx
ALVO-INTOCADO: app/Services/Creative
ALVO-INTOCADO: app/Support/Publicador
ALVO-INTOCADO: app/Support/Publicador/Imagem/ArquivosDeImagem.php
ALVO-INTOCADO: app/Support/Publicador/EditorEmUso.php
ALVO-INTOCADO: app/Support/Publicador/Imagem/ResolvedorGruposImagem.php
ALVO-INTOCADO: app/Services/Mlb/Publicacao/MlPublicacaoService.php
ALVO-INTOCADO: resources/js/Components/Publicador/Mesa
ALVO-INTOCADO: resources/js/Pages/Mlb/Publicador/Editor.jsx
ALVO-INTOCADO: resources/js/Components/Publicador/useCriativosDoPublicador.js
ALVO-INTOCADO: app/Mcp
ALVO-INTOCADO: app/Services/Portal
ALVO-INTOCADO: tailwind.config.js
ALVO-INTOCADO: app/Services/Publicador/PublicacaoService.php
ALVO-INTOCADO: app/Services/Publicador/Agente/PreparadorDaAprovacao.php
ALVO-INTOCADO: resources/js/Pages/Mlb/AnunciarML.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/PainelCriativosIa.jsx
ALVO-INTOCADO: resources/js/Pages/Mlb/components/KitCriativosGrade.jsx
ALVO-INTOCADO: resources/js/Components/Publicador/useAcervoDaConta.js
ALVO-INTOCADO: app/Services/Publicador/ImagemAssetService.php
ALVO-INTOCADO: .planning/ROADMAP.md
ALVO-INTOCADO: .planning/REQUIREMENTS-v27.md
ALVO-INTOCADO: .planning/STATE.md
```

⚠️ **Os TRÊS últimos são o GATE-07 e a verificação 4 do plano**, e a interseção é **VAZIA**: nenhum
dos 8 planos desta fase tocou `ROADMAP.md`, `REQUIREMENTS-v27.md` ou `STATE.md`. ⚠️ **O
`state.advance-plan` NÃO foi rodado** — ele corrompeu o `STATE.md` **seis** vezes.
⚠️ **`PreparadorDaAprovacao.php` e `PublicacaoService.php` entraram na lista NESTA wave:** o primeiro
é código **novo de outra sessão** (`c810a8ac`), o segundo é o publicador **real** e alvo da **Fase
193**. Os dois seguem intocados.

### §3.5 — T-189-SC: a fase **não instalou nada**, provado por DUAS vias

| via | medida |
|---|---|
| **FORMA** (a que vale como gate) | os 2 `.jsx` novos importam **só** `react`, `react-dom`, o alias `@/` e caminhos relativos; o `.php` novo usa **só** `App\` e `Illuminate\` — **nenhum nome de pacote novo** |
| `--name-only` nos 39 commits do escopo | `package.json`, `package-lock.json`, `composer.json`, `composer.lock` → **ZERO acertos** |

⚠️ **Nenhum `npm install`/`composer require` foi emitido nesta sessão.** Se um pacote tivesse
falhado, a régua manda **PARAR e devolver um checkpoint de verificação humana** — nunca instalar um
nome parecido (slopsquatting).

### §3.6 — Os **40 caminhos** que o escopo da fase tocou, medidos à mão

**11 artefatos de `.planning/`** (as 7 SUMMARYs, `189-BASELINES.md`, `189-CONTRATO-188.md`,
`189-FID01.md`, `deferred-items.md`) · **1 controller** · **1 serviço novo** · **5 `.jsx`** (2 novos
+ 3 da 188) · **1 página** · **2 `.php` de teste** · **7 manifestos** · **9 `.test.js`** (6 novos + 3
da 187) · **3 `.test.js`** restantes (2 da 188 + o do vocabulário).

⚠️ **Nenhum dos 40 é alvo vetado.** Os **11** que são **alheios** estão declarados na **§9**.

---

## §4 — OS **8** CRITÉRIOS DE SUCESSO, UM POR UM

⚠️⚠️ **São 8, CONTADOS POR MIM** no `ROADMAP.md`, em 20/10/2026:

```
awk '/^### Phase 189:/,/^### Phase 190:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "
  -> 8
numeros extraidos: 1 2 3 4 5 6 7 8   (sem buraco, sem repeticao)
```

⚠️ **E a isca do `awk`, medida:** o mesmo comando sobre a Fase **188** devolve **20**, sobre a **187**
devolve **15** e sobre a **186** devolve **17**. As quatro entradas são **vizinhas** no arquivo, e um
`awk` com a fase errada traz a contagem errada **em silêncio**. **Minha medição bate com a do
planejador: 8.**

⚠️⚠️ **A FORMA FIXA É O QUE IMPEDE O "AJUSTE" SILENCIOSO.** Critério que não passa recebe **RESSALVA**
ou **REPROVA**, **com a razão e com o dono** — e **nunca é reescrito para caber**. (Na Fase 186 o
critério 7 **foi** reescrito, com razão declarada: ele **contradizia o 6** e era **aritmeticamente
impossível**. ⚠️ **Nada dessa natureza apareceu na 189** — eu procurei: os 8 critérios são mutuamente
consistentes e nenhum é aritmeticamente impossível. Se aparecesse, estaria escrito aqui e
**devolvido ao usuário**, não consertado por conta própria.)

⚠️⚠️ **E ONDE EU DIVIRJO DO PLANEJADOR, declarado:** o `189-COBERTURA.md` §2 previa os critérios **2**
e **7** como *"OK com a ressalva declarada"*. **Eu os escrevo como RESSALVA.** Razão: os dois
critérios afirmam **comportamento de RUNTIME** (*"o zoom segue o cursor"*, *"Esc fecha devolvendo o
foco"*) e esta fase prova a **FUNÇÃO PURA**, não o runtime — não há navegador. ➡️ *"OK com ressalva"*
é exatamente a forma que a forma fixa existe para impedir: **ou o veredicto carrega a ressalva, ou a
ressalva desaparece na leitura.** O dono delas é o **usuário**, pela `PERGUNTA-VISUAL-189`.

CRIT-01: OK — tela cheia com fundo `bg-[rgba(5,5,5,0.94)]` e grid `minmax(0,1fr) 320px`, desenhada **POR PORTAL** (`createPortal` para `document.body`, com guarda `typeof document === 'undefined'` — porque o render de SERVIDOR **não desenha portal** e o teste veria **vazio e passaria POR OMISSÃO**), **acima do painel** pela ORDEM RELATIVA (`backdrop z-[39] < painel z-[40] < lightbox z-[41] < modal z-[42] < toast z-[45]`), com o número que o protótipo deu a esta peça (**`90`**) **RECUSADO** por mapa `DO_PROTOTIPO` com isca, e o header **intocado** (`publicador-header-zindex-ordem` 7/7/0, `PI` com interseção vazia) — gate lightbox-casca, 27 + gate camadas-e-css, 16,11 + gate lightbox-no-painel, 22,22 + gate acoes-do-lightbox, 25,25 — dono: —
CRIT-02: RESSALVA — a imagem é **1:1 com até 78vh** (`aspect-square`, `max-h-[78vh]`), o zoom de **2,5×** é **função pura provada** (a origem do `transform` a partir de coordenadas, com clamp defensivo nas duas pontas) e os **marcadores numerados** coincidem com os itens da lateral por **prova cruzada palco × lateral** na MESMA fixture (o `ordinal` vem da prop, **nunca recontado**); ⚠️ **a ressalva é de AMBIENTE e foi CONFIRMADA medindo: o MOVIMENTO do zoom NÃO é provado** — `renderToStaticMarkup` **não dispara `onMouseMove`**, não há navegador, e o que está travado é a função mais o `style` que a consome — gate lightbox-casca, 27 + gate lateral-do-lightbox, 27,27 — dono: usuário (PERGUNTA-VISUAL-189, item 2 da §11)
CRIT-03: OK — o zoom **não usa** `duration-[Nms]`, `delay-[…]` nem `ease-[…]` (elas **não compilam neste projeto**), e a transição vai **inteira por `style`** (`transitionProperty: 'transform'`, `transitionDuration: '150ms'`, `transitionTimingFunction: 'ease'` — o `ease` é o padrão do CSS, e usar a classe `transition-transform` do Tailwind trocaria a curva para `cubic-bezier(0.4,0,0.2,1)`), com gate de ausência do arbitrário **na FONTE SEM COMENTÁRIO** e **`duration-200` como CONTROLE POSITIVO** conferido no CSS CONSTRUÍDO — gate camadas-e-css, 16,11 — dono: —
CRIT-04: RESSALVA — a lateral de 320px entrega **Origem**, **Posição** (com `· principal`), **Estado com TEXTO** (cor nunca é o único sinal), a **original de referência**, as **verificações numeradas** sob o cabeçalho `VERIFICAÇÃO AUTOMÁTICA · APOIO` **importado** (PERGUNTA-189-A (a), usuário, 19/10) e os botões **Reprovar**/**Aprovar foto**; ⚠️ **DUAS ressalvas:** (i) **"Comparar lado a lado na revisão detalhada" NÃO é desenhado** (D-189-3; *"desabilitado com explicação"* foi **oferecido e RECUSADO** na 188; botão sem destino é D4 violado) — **dono: Fase 190 ⛔ CANCELADA em 20/10/2026 (decisão 20, `0607f5a9`) ⇒ AMPL-05 fica sem dono (190/191 fora de escopo em 20/10/2026)**; (ii) a linha **"Variação" NÃO é desenhada**, porque o payload só traz a **chave canônica** (`COLOR=id:52049`) e o rótulo humano é `private static` em território VETADO — **decidido pelo usuário em 19/10** (seguir sem o nome, sem vazar a chave), **dono: sem dono (190/191 fora de escopo em 20/10/2026)**, com o **custo funcional** escrito na §10 — gate lateral-do-lightbox, 27,27 + gate original-de-referencia, 3/45 + gate estado-img13-e-recorte, 3/32
CRIT-05: OK — sem original disponível a lateral mostra o **estado declarado** `Original de referência não disponível`, com o ícone `ImageOff` e a frase de apoio, **LIDOS das constantes do MODELO** (`PubImagemReferencia::SEM_ORIGINAL*`, uma fonte só — a tela **não reescreve o texto**) — **nunca quadro vazio, nunca `<img src="">`** (string vazia num `src` faz o navegador **RECARREGAR A PÁGINA**), e **nunca a imagem de IA repetida**; ⚠️ e **na estreia TODA imagem de IA cai neste estado** (não há backfill; a escrita de `pub_imagem_referencias` é das Fases 192/194, com gate da 186 varrendo `app/` inteiro) — gate estado-img13-e-recorte, 3/32 + gate lateral-do-lightbox, 27,27 — dono: —
CRIT-06: OK — as travas são as **mesmas** da revisão simples: só `live` edita e imagem em `regenerating` **não aceita ação**, provado nos **três** casos nomeados (`ao_vivo`+`pending` tem os dois botões; `regenerating` não; fora do `live` não), com o `ao_vivo` vindo do **CONTEXTO DO LIGHTBOX** e não do painel por baixo, e a **trava REAL no servidor** (as rotas do 188-05 recusam com **422** mesmo chamando direto) — gate lateral-do-lightbox, 27,27 + gate acoes-do-lightbox, 25,25 — dono: —
CRIT-07: RESSALVA — `←/→` trocam a foto **dando a volta** (`(i + d + n) % n`, **diferente** do painel, que **clampa** — o protótipo ganha e as duas regras **não** foram unificadas), trocar de foto **zera o zoom** mas **não** a origem, `Esc` fecha **um degrau por vez** (modal → lightbox → painel) e o painel volta **à foto de onde se abriu** com o foco no `⤢ Ampliar` (PERGUNTA-189-C); ⚠️ **a ressalva é de AMBIENTE e foi CONFIRMADA medindo: o RUNTIME do teclado e a DEVOLUÇÃO DO FOCO não são provados** — as funções puras e os handlers exportados estão travados, o despacho pelo navegador não; ⚠️ e o caso *"a oferta mudou por baixo"* segue sob a **PERGUNTA-189-D (ABERTA)**, com os defaults **D1** e **D2** aplicados e declarados — gate lightbox-casca, 27 + gate lightbox-no-painel, 22,22 + gate acoes-do-lightbox, 25,25 — dono: usuário (PERGUNTA-VISUAL-189, itens 4 e 8 da §11)
CRIT-08: OK — a tabela **FID-01** do lightbox existe em `189-FID01.md` (§A a §J), foi medida contra `design_handoff_publicador/Aprovacao.dc.html` (sha256 `68624883ce35547e`, **1432** linhas, **não se moveu**) e **DECLARA COMO MEDIU**: **6 de 8 planos por SCRIPT e 2 SEM-PROTOTIPO, ZERO por LEITURA** (§5), com `cursor`/`white-space`/`text-overflow` em **colunas próprias**, as divergências classificadas em **OMISSÃO / REFERÊNCIA / VALOR** (placar da re-medição: **0 OMISSÃO · 3 REFERÊNCIA · 4 VALOR · 0 de medida de estilo**) e os ponteiros **RE-MEDIDOS por `sed`** em **cinco** waves — gate fid01-189-remedida, 1/13 + gate baselines-medidas, 1/11 + gate vocabulario-tamanhos, >=695 (medido 695) — dono: —

### Placar

PLACAR-189: OK=5 RESSALVA=3 REPROVA=0

| veredicto | quantos | quais | dono de cada ressalva |
|---|---|---|---|
| **OK** | **5** | 01, 03, 05, 06, 08 | — |
| **RESSALVA** | **3** | **02** · **04** · **07** | **02** e **07**: **usuário** (ambiente — `PERGUNTA-VISUAL-189`) · **04**: **Fase 190 ⛔ CANCELADA ⇒ sem dono (190/191 fora de escopo em 20/10/2026)**, e a 2ª ressalva dele (a "Variação") também **sem dono** |
| **REPROVA** | **0** | — | — |

⚠️ **Nenhum `REPROVA`.** Duas ressalvas são **de AMBIENTE** (não há navegador — e nenhuma delas é
trabalho faltando dentro desta fase); a terceira é **fronteira do desenho decidida pelo usuário**,
cujo dono **deixou de existir** durante a execução.

⚠️ **As ressalvas de AMBIENTE que NÃO viraram veredicto de critério**, porque nenhum critério as
cobra, e que valem para a leitura dos 8:
- **a fila de produção nasce VAZIA** — é honesto, **não** é defeito. ⚠️ Mas o produtor existe:
  `c810a8ac` (AG-03, outra sessão) é **o primeiro código de produção que cria `PubAprovacaoOferta`**
  ➡️ a primeira oferta real **deixou de depender só da Fase 194**;
- **nenhum executor viu tela renderizada** (§1);
- **"✦ Gerar novamente com IA" NÃO é desenhado** nem no painel (`:606`) nem no lightbox (`:762`),
  com **gate de ausência COM ISCA** nos dois — **dono: Fase 192**, que virou a **ÚLTIMA** da
  milestone;
- **esta fase não tem migration**, e isso está travado por gate.

### Os REQ-IDs, um por um

| REQ | entregue por | gate | veredicto |
|---|---|---|---|
| **AMPL-01** | 189-03, 189-04 | lightbox-casca 27 · camadas-e-css 16,11 | **OK** |
| **AMPL-02** | 189-03, 189-06 | lightbox-casca 27 · lightbox-no-painel 22,22 | **RESSALVA** — o MOVIMENTO do zoom não é provado — dono: usuário |
| **AMPL-03** | 189-03, 189-04 | camadas-e-css 16,11 | **OK** |
| **AMPL-04** | 189-05 | lateral-do-lightbox 27,27 | **OK** |
| **AMPL-05** | 189-02, 189-05 | original-de-referencia 3/45 · lateral-do-lightbox 27,27 | **RESSALVA** — a original **É** entregue; "Comparar lado a lado" **não é desenhado** — dono: **sem dono (190/191 fora de escopo em 20/10/2026)** |
| **AMPL-06** | 189-03, 189-05, 189-07 | lightbox-casca 27 · lateral-do-lightbox 27,27 | **OK** |
| **AMPL-07** | 189-05, 189-06, 189-07 | lateral-do-lightbox 27,27 · acoes-do-lightbox 25,25 | **RESSALVA** — "✦ Gerar novamente com IA" não é desenhado — dono: Fase 192 |
| **GATE-09** | 189-03, 189-04 | camadas-e-css 16,11 | **OK** |
| **IMG-09** | 189-05, 189-07 | lateral-do-lightbox 27,27 · acoes-do-lightbox 25,25 | **OK** |
| **IMG-13** | 189-02, 189-05 | estado-img13-e-recorte 3/32 | **OK** |
| **TERR-06** | 189-03..07 | os 32 alvos, 0 violações, 2 passadas, 4 iscas | **OK** |
| **FID-01** | 189-01, 189-08 | fid01-189-remedida 1/13 | **OK** |
| **FID-02** | 189-01, 189-08 | baselines-medidas 1/11 | **OK** |
| **REVS-01** | 189-03, 189-04 | camadas-e-css 16,11 | **OK** (o portal e a ordem de camadas) |
| **REVS-08** | 189-06 | lightbox-no-painel 22,22 · coluna-de-fotos-188 32,28 | **OK** (o `⤢ Ampliar` que a 188 deixou de fora de propósito) |

---

## §5 — A TABELA FID-01 CONSOLIDADA, COM `SCRIPT` OU `SEM-PROTOTIPO` DECLARADO

> *"Tabela derivada de SCRIPT é confiável; tabela derivada de LEITURA não é."* — medido neste
> projeto em 13/10/2026.

```
FID-01-PLANO-01: SCRIPT — a tabela inteira RE-MEDIDA (`fid01-189-01.cjs` + `omissao-189-01.cjs`), 40 atributos `style` do disco x 70 ponteiros do documento, cada ponteiro reconfirmado por `sed`
FID-01-PLANO-02: SEM-PROTOTIPO — plano de SERVIDOR: não há `style` a medir; o contrato é o payload e as constantes do modelo
FID-01-PLANO-03: SCRIPT — §B (a casca e o palco), aberturas/fechamentos contados de `:726` a `:746`
FID-01-PLANO-04: SCRIPT — §B re-medida com `cursor`/`white-space`/`text-overflow` em COLUNAS PRÓPRIAS (`fid-189-04.cjs`), e o `font-weight:800` de `:733` re-medido
FID-01-PLANO-05: SCRIPT — §C (a lateral de 320px), com o diretório CITADO e 26 ponteiros reconfirmados por `sed`
FID-01-PLANO-06: SCRIPT — §D (o `⤢ Ampliar` do painel, `:604`-`:607`)
FID-01-PLANO-07: SCRIPT — §D e §C re-medidas, e o CSS das classes conferido pelo CORPO da regra
FID-01-PLANO-08: SEM-PROTOTIPO — este plano CONSOLIDA e não re-mede o protótipo; o que ele confere é o `sha256` ESCRITO (verificável em qualquer máquina) e o `wc -l`
```

➡️ **6 de 8 por SCRIPT · 2 SEM-PROTOTIPO · ZERO por LEITURA.**

### §5.1 — O placar das divergências da re-medição (§I do `189-FID01.md`)

**7 divergências — 0 por OMISSÃO · 3 por REFERÊNCIA · 4 por VALOR · 0 de medida de estilo.**

⚠️⚠️ **E isto CONTRADIZ a expectativa escrita na nota de coordenação**, que avisava que *"a tabela
erra por OMISSÃO e por REFERÊNCIA, quase nunca por valor"*. Na re-medição da **188-01** houve **11
omissões**; aqui foram **ZERO** (`NAO_CITADOS=[]`, com iscas provando que a leitura não é cega:
`ATRIBUTOS_NO_DISCO=40`, `PONTEIROS_CITADOS_NO_DOC=70`, `:727` presente, a isca `L9999` ausente).
➡️ **A razão provável, e ela é a lição:** esta tabela **já nasceu por SCRIPT**, e a anterior não.

### §5.2 — ⚠️⚠️ OS CASOS EM QUE O **PROTÓTIPO PERDEU** NESTA FASE, juntos, com `arquivo:linha` dos dois lados

| # | o que o protótipo faz | o que a 189 entrega | quem ganhou, e por quê |
|---|---|---|---|
| 1 | **`:760`** desenha **só o glifo** do item de verificação | o item traz o **NÚMERO do marcador** (`ordinal` da prop, elemento próprio, classes **importadas** de `BlocoDaImagemSelecionada.jsx:141`) | **o REQUISITO** — **AMPL-06** e o **critério 2** exigem que os marcadores *"coincidam com os pontos listados na lateral"*. Requisito explícito ganha do protótipo |
| 2 | **`:752`** **ESCONDE** o bloco da original quando não há original | a lateral mostra o **estado declarado** `Original de referência não disponível` | **o REQUISITO** — o **critério 5** e **IMG-13** exigem: *"nunca quadro vazio"* |
| 3 | **`:759`-`:761`** **não tem cabeçalho** na lista de verificações | a lateral traz `VERIFICAÇÃO AUTOMÁTICA · APOIO` **uma vez**, com as classes do cabeçalho do painel (`:369`, o literal **importado** de `BlocoDaImagemSelecionada.jsx:73`, com `·` = U+00B7) | ⚠️ **O USUÁRIO** — PERGUNTA-189-A **(a) SIM**, 19/10/2026. Razão dele: *"sem o cabeçalho a verificação automática pode ser lida como VEREDICTO"*. ➡️ **É a QUARTA derrota do protótipo na milestone, e a quarta por decisão do usuário** |
| 4 | **`:756`** desenha "Comparar lado a lado na revisão detalhada" · **`:762`** desenha "✦ Gerar novamente com IA" | **nenhum dos dois é desenhado** — nem desabilitado, nem com `onClick` vazio | **A RÉGUA D4** (*"nada nasce decorativo"*), via D-189-3. ⚠️ Isto **não** é derrota do protótipo: é **escopo de outra fase** |

⚠️ **E na PERGUNTA-189-B o PROTÓTIPO GANHOU**, e vale registrar porque é o contrapeso: com o lightbox
aberto, `↑/↓` **continuam navegando a fila por baixo** (`:1017`) e **o lightbox guarda o PRÓPRIO
contexto** capturado na abertura (`:1330`) — oferta, versão, `ao_vivo`, imagens e originais. ➡️ **Toda
ação do lightbox usa a oferta/versão DO LIGHTBOX, nunca a do painel por baixo**, provado por fixture
que **discrimina** (oferta A v3 × oferta B v7: abrir na A, `↓` para a B, Aprovar foto → payload com o
id e a versão **da A**), com mutação que troca a fonte **MORRENDO**.
**Placar do protótipo nesta milestone: ~146 a 4.** ⚠️ **Nenhum QUINTO conflito protótipo × regra do
projeto apareceu**, e nada foi devolvido ao usuário por essa via.

### §5.3 — As divergências **ACRESCIDAS**, declaradas (valores escolhidos, SEM medida no protótipo)

| acréscimo | onde | razão |
|---|---|---|
| **clamp defensivo** da origem do zoom nas duas pontas | função pura do 189-03 | coordenada adversa (`NaN`, `Infinity`, negativa) produziria `transform-origin` inválido e **mataria a composição** |
| **`aria-label`** nas setas `←`/`→` | palco do 189-03 | o protótipo desenha só o glifo; sem rótulo o botão é ilegível para leitor de tela |
| os **valores** do estado IMG-13 (`absolute inset-0`, `gap-[6px]`, `p-[12px]`) | lateral do 189-05 | o protótipo **ESCONDE** o bloco (`:752`), então **não há medida a copiar**; o `absolute inset-0` é necessário porque a caixa é `relative aspect-square` — sem ele o estado **não ocupa a caixa**. Mesmo mecanismo do `CLASSES_PLACEHOLDER` do painel |
| `aoClicarNoZoom` entrega o **zoom NOVO** (o `<interfaces>` do 189-05 dizia `onAlternarZoom()` sem argumento) | lateral do 189-05 | é a MESMA forma dos handlers do palco (devolvem um **PEDIDO**), e torna `alternarZoom` um import **VIVO** em vez de deixar a lateral com uma **segunda regra** de alternância |
| `font-[800]` em vez de `font-extrabold` (§C13) | glifo **importado** | **notação, valor IDÊNTICO** (`font-weight:800`); o sub-teste 2 do GATE-03 **PROÍBE** a escala nomeada. Precedente já em produção em `ImagemGrandeDoPainel.jsx:170` |
| `TITULO_DA_ORIGINAL` como placeholder (o protótipo usa `LB.refLabel`, `:1111`) | lateral do 189-05 | **lacuna de DADO**: a prop `lightbox.originais` **não traz** o campo. Ver §10 |

---

## §6 — A CAMPANHA DE MUTAÇÃO CONSOLIDADA

### A disciplina, igual nos sete planos

commit **ANTES** · `cp -p` para **fora** do repositório · script **nomeado com o plano**
(`mutar-189-NN.sh` — ⚠️ o scratchpad é **COMPARTILHADO**, e na Fase 187 um script foi
**SOBRESCRITO** e a campanha teve de ser **refeita inteira**) · ocorrência **travada em 1** por
`substr_count`/`indexOf` (⚠️ **nunca `grep -c`, que conta LINHAS**) · **linha conferida por `sed`
depois** · aplicação provada por **divergência de `sha256`** · restauração **DA CÓPIA** em
`trap EXIT` · `sha256` final contra `git show HEAD:` com `tr -d '\r'` · ⚠️⚠️ **`# tests` do MUTADO
IGUAL ao do PRISTINO** (sem isso, o vermelho poderia ser *"o arquivo não carregou"*, que não prova
nada) · ⚠️⚠️ **`git checkout` NUNCA.**

### Uma linha por plano

| plano | mutações | mortas | sobreviventes | o que a investigação mudou |
|---|---|---|---|---|
| **189-01** | 9 | 8 | **1 (ix)** | ⚠️ **FURO REAL em DUAS camadas:** (a) `git log --grep` casa a **MENSAGEM INTEIRA**, então o recorte por **assunto** é obrigatório — e isso **CORRIGE o briefing**, que dizia que o filtro *"não captura"* (ele **SOBRE-captura**); (b) a proteção era **inobservável** no escopo da fase, e *"premissa inobservável se TRAVA, não se descarta"*: travada **por COMPORTAMENTO** em `1c4ff10f` |
| **189-02** | 4 | **4** | 0 | ⚠️ cada morte foi **reaplicada ISOLADA** e a **linha do veredicto LIDA**, porque a wave 1 mediu que *"uma mutação pode morrer pela asserção ERRADA e assim não provar nada"*. **As quatro morreram no lugar CERTO** |
| **189-03** | 7 | **7** | 0 | nenhuma morreu pela asserção errada (o script compara os mortos com a lista esperada) |
| **189-04** | 8 (rodada 2) | **8** | 0 | ⚠️⚠️ **na RODADA 1 a M1 SOBREVIVEU nos DOIS gates, e investigar MUDOU O RESULTADO: era FURO REAL.** O portal estava provado **só** por `includes('createPortal')`, e o mutante **MANTÉM o identificador**. Conserto em `86b887bf`: as **quatro formas EXATAS** (import, `return createPortal(`, `document.body,`, a guarda de ambiente), com a isca construída **do mutante de verdade** |
| **189-05** | 7 | **7** | 0 | as sete linhas-alvo conferidas por `sed`; `PRISTINO: tests=27 fail=0` |
| **189-06** | 9 | **9** | 0 | ⚠️ a **(v)** sobreviveu na 1ª rodada e **era FURO REAL**: ela cobrava a ausência da 192 **só no regime SEM handler**. Re-rodada completa **9/9** |
| **189-07** | 13 rodadas | **12** | **1 investigada e SUBSTITUÍDA** | ⚠️ a sobrevivente **acusava a MUTAÇÃO, não o gate**: ela **não expressava a ameaça** (`router.post` sem parêntese não é a forma que o código usa). Substituída por uma que expressa |
| **TOTAL** | **57** | **55** | **0 ao fim** (4 eventos, **4 investigados até a causa**) | |

### ⚠️⚠️ O QUE A MUTAÇÃO RENDEU NESTA FASE — **quatro** vezes, e cada uma diferente

1. **189-01 (ix):** a sobrevivente **matava o gate pela asserção ERRADA** e **não provava nada**;
2. **189-04 (M1):** a sobrevivente **acusou FURO REAL** — o portal travado **só por FONTE larga**;
3. **189-06 (v):** a sobrevivente **acusou FURO REAL** — a ausência provada só num regime;
4. **189-07:** a sobrevivente **não expressava a ameaça** — foi a **MUTAÇÃO** que estava errada.

➡️ ⚠️ **"Mutação que SOBREVIVE não é 'equivalente' até você PROVAR que é."** Em **duas** das quatro, o
resultado da fase **mudou**.

### Os DOIS REGIMES de contagem, e qual manifesto os guarda

| id | pristino | mutado | por quê existem dois |
|---|---|---|---|
| `camadas-e-css` | **16** | **11** | o mutante do portal mata 5 casos; sem o 2º regime o `tests=` do mutado pareceria "não carregou" |
| `lateral-do-lightbox` | **27** | **27** | aqui os dois coincidem — e **declarar isso é o que distingue "medi" de "não olhei"** |
| `lightbox-no-painel` | **22** | **22** | idem |
| `coluna-de-fotos-188` | **32** | **28** | contagem **ALHEIA**, declarada **IGUAL** ao que o 188-08 já tinha (`32,28`) — **nada foi subido** |
| `acoes-do-lightbox` | **25** | **25** | ⚠️ e **continuou 25 depois do conserto de `62e5efae`** — §14 |

---

## §7 — OS **8** MANIFESTOS, AS TRÊS BIJEÇÕES E O HOMÔNIMO

| manifesto | ids | manifesto | ids |
|---|---|---|---|
| `contagens-189-01.txt` | 5 | `contagens-189-05.txt` | 2 |
| `contagens-189-02.txt` | 3 | `contagens-189-06.txt` | 3 |
| `contagens-189-03.txt` | 2 | `contagens-189-07.txt` | 2 |
| `contagens-189-04.txt` | 1 | **`contagens-189-08.txt`** | **3** |
| | | **TOTAL** | **21** |

⚠️ **Nenhum id com `tests=0`** (*"`tests=0` não é contagem, é número de fora"* — e o harness
**ABORTA** com ele). ⚠️ **Nenhum id repetido** dentro do mesmo manifesto (o harness pega o
**primeiro**, e o segundo **nunca roda**). ⚠️ **Nenhum id com mais de TRÊS métodos** — medido nos 8:
o maior tem **3**.

### Os 22 ids RE-RODADOS nesta sessão, com o `exit` DO HARNESS

⚠️⚠️ **O `exit` foi medido na linha SEGUINTE ao comando, sem pipe** — `cmd | tail` devolve o exit do
`tail`, **sempre 0**, e foi o erro que a 189-06 cometeu e a 189-07 repetiu.

```
189-01  baselines-medidas          exit=0   Tests: 1 passed (11 assertions)
189-01  contrato-188-inventariado  exit=0   Tests: 1 passed (10 assertions)
189-01  fid01-189-remedida         exit=0   Tests: 1 passed (13 assertions)
189-01  tokens-e-tamanhos-189      exit=0   # tests 3   # pass 3   # fail 0
189-01  vocabulario-tamanhos       exit=0   # tests 695 # pass 695 # fail 0
189-02  original-de-referencia     exit=0   Tests: 3 passed (45 assertions)
189-02  estado-img13-e-recorte     exit=0   Tests: 3 passed (32 assertions)
189-02  prop-lightbox-e-sem-http   exit=0   Tests: 3 passed (75 assertions)
189-03  lightbox-casca             exit=0   # tests 27  # pass 27  # fail 0
189-04  camadas-e-css              exit=0   # tests 16  # pass 16  # fail 0
189-05  lateral-do-lightbox        exit=0   # tests 27  # pass 27  # fail 0
189-06  lightbox-no-painel         exit=0   # tests 22  # pass 22  # fail 0
189-06  coluna-de-fotos-188        exit=0   # tests 32  # pass 32  # fail 0
189-07  acoes-do-lightbox          exit=0   # tests 25  # pass 25  # fail 0
--- as NAO-REGRESSOES declaradas (ids de OUTRAS fases, rodados pelos planos) ---
186-09  transversais               exit=0   Tests: 12 passed (258 assertions)
186-09  andamento-um-lugar-so      exit=0   Tests: 1 passed (20 assertions)
187-01  tokens-da-fila             exit=0   # tests 17  # pass 17  # fail 0
187-04  pagina-rota                exit=0   Tests: 6 passed (185 assertions)
188-02  custo-do-painel            exit=0   Tests: 2 passed (24 assertions)
188-02  painel-na-rota             exit=0   Tests: 2 passed (41 assertions)
188-07  casca-do-painel            exit=0   # tests 45  # pass 45  # fail 0
188-08  coluna-de-fotos            exit=0   # tests 32  # pass 32  # fail 0
--- e o gate da QUICK que consertou as cinco telas pretas ---
261020-01 pagina-aprovacao-tdz     exit=0   # tests 7   # pass 7   # fail 0
--- os TRES ids DESTE plano, medidos depois do commit dos gates ---
189-08  fechamento-territorio      exit=0   Tests: 3 passed (32 assertions)
189-08  fechamento-criterios       exit=0   Tests: 3 passed (32 assertions)
189-08  fechamento-manifestos      exit=0   Tests: 3 passed (21 assertions)
--- e a ISCA do 1o argumento, exercida ---
sh tests/gates/verificar-testes.sh 08 fechamento-territorio
  -> exit=2   "ABORTOU: id 'fechamento-territorio' nao esta em .../contagens-186-08.txt
               (manifesto incompleto)"
  -> o numero NU resolve a fase 186 e o harness ABORTA. A compatibilidade e DE PROPOSITO.
```

⚠️ **25 ids, todos `exit=0`** — e o **número NU** sai **`exit=2`**, como tem de ser. ⚠️ **E nenhuma contagem ALHEIA foi subida:** `casca-do-painel` **45**,
`coluna-de-fotos` **32**, `tokens-da-fila` **17** — **exatamente** o que os manifestos daquelas fases
já declaravam.

### ⚠️⚠️ A TERCEIRA BIJEÇÃO — id ↔ `<verify>` — sobre os OITO pares, por SCRIPT

```
PARES_MEDIDOS = 8
ORFAOS (declarado no manifesto, fora de todo <verify>)  = 0
NAO_DECLARADOS (usado num <verify>, fora do manifesto)  = 0

189-01: manifesto=[baselines-medidas, contrato-188-inventariado, fid01-189-remedida,
                   tokens-e-tamanhos-189, vocabulario-tamanhos]          == verify
189-02: manifesto=[original-de-referencia, estado-img13-e-recorte,
                   prop-lightbox-e-sem-http]                            == verify
189-03: manifesto=[lightbox-casca, vocabulario-tamanhos]                == verify
189-04: manifesto=[camadas-e-css]                                       == verify
189-05: manifesto=[lateral-do-lightbox, vocabulario-tamanhos]           == verify
189-06: manifesto=[lightbox-no-painel, coluna-de-fotos-188,
                   vocabulario-tamanhos]                                == verify
189-07: manifesto=[acoes-do-lightbox, vocabulario-tamanhos]             == verify
189-08: manifesto=[fechamento-territorio, fechamento-criterios,
                   fechamento-manifestos]                               == verify
```

⚠️⚠️ **Por que isto é gate e não zelo:** um id pode estar no manifesto, com contagem **medida**, sobre
métodos que **existem** — e **não ser rodado por `<verify>` nenhum**. ⚠️ **Ele não falha: ele NUNCA
RODA**, e é **indistinguível de um gate verde**. A revisão da Fase 188 achou **nove** assim.

⚠️ **E a sonda lê SÓ os blocos `<verify>`**, não o corpo do plano — medido: um
`verificar-testes.sh 189-08 id-de-prosa` escrito **fora** de `<verify>` **não entra**.

**ISCA NAS DUAS DIREÇÕES, sobre a sonda DE VERDADE:**

| isca | medida |
|---|---|
| id injetado num `<verify>` do `189-08` é **visto** | `true` ✓ |
| o **mesmo** texto lido como `189-07` | `[]` ✓ — o `NN` está **ANCORADO** |
| o **número NU** (`verificar-testes.sh 08 <id>`) | `[]` ✓ — ele resolveria `contagens-186-08.txt`, **a fase ERRADA, em silêncio** |
| texto **fora** de `<verify>` | `[]` ✓ |

**As NÃO-REGRESSÕES declaradas** (ids de outras fases rodados pelos planos desta) **não entram na
bijeção do manifesto 189** — elas são conferidas por **existência no manifesto da fase DONA**, e
todas as **6** passaram (`186-09 andamento-um-lugar-so`, `186-09 transversais` ×3, `188-08
coluna-de-fotos`, `188-07 casca-do-painel`).

### ⚠️ `vocabulario-tamanhos`: em LINHA PRÓPRIA em quem o roda, em NENHUM id composto

```
DECLARAM em linha propria:  189-01, 189-03, 189-05, 189-06, 189-07   (CINCO)
RODAM no <verify>:          os MESMOS cinco
EMBUTIDO em id composto:    0
FORMA:                      `>=N`, com N MEDIDO na wave — >=681, >=688, >=695, >=695, >=695
CONTAGEM REAL AGORA:        695   (`# tests 695 # pass 695 # fail 0`)
```

⚠️⚠️ **As duas exigências são OPOSTAS** e confundi-las foi erro de briefing na Fase 188: *(a)* todo
`<verify>` que usa o id tem a linha no manifesto da **sua** wave (senão o harness **ABORTA**);
*(b)* **nenhum** id que rode **outros** arquivos o inclui (foi o **id COMPOSTO** que fez a wave
187-05 reprovar **dois ids de outras waves**). ⚠️ **A regra é "quem RODA, declara" — não "todos
declaram":** as waves **02** (servidor), **04** (CSS construído) e **08** (fechamento) **não** o
rodam e **estão certas** em não declarar. Um gate que exigisse a linha nos oito **reprovaria três
manifestos corretos**.

### A conferência de **HOMÔNIMO**, por script, nas DUAS direções, **com o EXPANSOR DE ALTERNÂNCIA**

```
ANTES do commit deste plano:
  ARQUIVOS=1231  METODOS=7971  HOMONIMOS=0  PREFIXOS=0
  DECLARADOS_EXPANDIDOS=12  DECLARADOS_SEM_METODO=0  METODOS_DA_FASE_189=12  SEM_ID=0
DEPOIS do commit deste plano (os 9 metodos `_189_08`):
  ARQUIVOS=1232  METODOS=7980  HOMONIMOS=0  PREFIXOS=0
  DECLARADOS_EXPANDIDOS=21  DECLARADOS_SEM_METODO=0  METODOS_DA_FASE_189=21  SEM_ID=0
ISCA_A (nome inventado casa?)        = OK-nao-casa
ISCA_B (controle positivo existe?)   = OK-existe
ISCA_C (substring curta casa N)      = 829   (>1 prova que a sonda de substring VE)
```

⚠️ **`METODOS_DA_FASE_189` = `DECLARADOS_EXPANDIDOS` = 21, nos dois momentos** — ou seja,
**`SEM_ID=0` nas duas direções**: nenhum método `_189_NN` fica fora de manifesto (**teste sem id
NUNCA RODA**, e é a armadilha 1 da Fase 186), e nenhum id declara método que não existe.

⚠️⚠️ **O EXPANSOR DE ALTERNÂNCIA é obrigatório, e a razão é medida:** `/test_[A-Za-z0-9_]+/` **NÃO
casa** `test_(a|b|c)`, porque o nome completo **não aparece literalmente** no manifesto. Uma sonda
sem expansor reportou **`SEM_ID=14`** nesta milestone — **acusando trabalho CORRETO**.
⚠️ **A varredura é sobre TODO `tests/`**, porque o harness roda `artisan test --filter=<regex>` **sem
caminho**, e o `--filter` do PHPUnit é regex **NÃO ANCORADA**. ⚠️ **As Fases 186, 187 e 188 estão em
produção**: método **superstring** de uma delas faria o id daquela fase **SOMAR** e reprovar.
**Medido: zero colisões.** Os 9 métodos deste plano levam **`_189_08`**.

### O harness, re-medido nesta sessão: `--autoteste` **20 casos, 0 divergência**

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
EXIT_DO_AUTOTESTE=0
```

---

## §8 — AS PERGUNTAS E OS AVISOS, **TODOS COM DONO**

⚠️ **Dono indefinido NÃO é dono** — e nenhuma das sete linhas abaixo tem dono indefinido.
⚠️⚠️ **O critério de aceitação do plano para esta seção é uma ARMADILHA POR CONSTRUÇÃO, e eu a
registro em vez de me acomodar nela:** ele manda `grep -c "a definir" <seção de perguntas>` devolver
**0** — mas escrever **a própria regra** aqui (*"X não é dono"*, com X sendo aquela expressão) faria
o `grep` devolver **≥1 mesmo com todas as sete perguntas tendo dono**. É a família catalogada
*"palavra no comentário reprova pelo próprio conserto"* / *"o grep que casa com o comentário"*.
➡️ **O equivalente HONESTO está no gate**, por linha: cada `PERGUNTA-*` tem de casar `dono:\s*\S` e
**não** casar `dono:\s*a\s+definir`. **Medido: 7 de 7 com dono, 0 indefinidos.** Ver §10.1.

```
PERGUNTA-189-A: RESPONDIDA — dono: usuário (19/10/2026) — opção (a) SIM: o cabeçalho "VERIFICAÇÃO AUTOMÁTICA · APOIO" entra UMA vez na lateral, com as classes do cabeçalho do painel (188-08, origem `:369`), MAIS o ordinal por item. Razão dele: sem o cabeçalho a verificação pode ser lida como VEREDICTO. APLICADA EM: 189-05 (tarefa 2). É a QUARTA derrota do protótipo na milestone (§5.2)
PERGUNTA-189-B: RESPONDIDA — dono: usuário (19/10/2026) — opção (b) FIEL AO PROTÓTIPO: `↑/↓` continuam navegando a fila por baixo e o LIGHTBOX GUARDA O PRÓPRIO CONTEXTO capturado na abertura. Toda ação usa a oferta/versão DO LIGHTBOX. APLICADA EM: 189-06 (contexto e teclado) e 189-07 (ações), com fixture A v3 × B v7 que DISCRIMINA e mutação da fonte da versão MORTA
PERGUNTA-189-C: RESPONDIDA — dono: usuário (19/10/2026) — ao fechar, o painel volta à foto de onde o "⤢ Ampliar" foi clicado, com o foco nesse botão (quando a oferta por baixo NÃO mudou). APLICADA EM: 189-06 (tarefa 2)
PERGUNTA-189-D: ABERTA — dono: usuário — NÃO BLOQUEIA, e os dois defaults foram APLICADOS e DECLARADOS. (D1) ao FECHAR com a oferta do painel mudada por `↑/↓`: o painel FICA na oferta para onde a pessoa navegou (B), na foto que B já tinha, com o foco no "⤢ Ampliar" de B — a navegação foi ação explícita dela. Alternativa: voltar o painel para a A (um `router.get` com `?oferta=A`). (D2) DEPOIS de uma ação bem-sucedida com a oferta do painel DIFERENTE: o lightbox FECHA, porque não há como mostrar o estado novo da A sem mover o painel de volta. Alternativa: recarregar na A (`?oferta=A`) e manter o lightbox aberto. ⚠️ A asserção que DISTINGUE default de alternativa é `recarregarNaOferta = null SEMPRE`, e ela está travada no gate `acoes-do-lightbox` — trocar para a alternativa REPROVA, de propósito. ⚠️ As duas escolhas são de PRODUTO e NÃO óbvias; cada alternativa é LOCAL (189-06/189-07)
PERGUNTA-VISUAL-189: ABERTA — dono: usuário — a conferência visual do LIGHTBOX na conta 459, com o roteiro na §11. ⚠️ Só executável depois de um deploy que o usuário autorize, e a fila de produção está VAZIA (o caminho alternativo é o item "Sem oferta na fila" da §11)
PERGUNTA-VISUAL-188: ABERTA — dono: usuário — a conferência visual do PAINEL de revisão simples (Fase 188) continua PENDENTE e SEPARADA. Esta fase NÃO a substitui
PERGUNTA-VISUAL-187: ABERTA — dono: usuário — a conferência visual da FILA de aprovação (Fase 187) continua PENDENTE e SEPARADA. Esta fase NÃO a substitui
```

### Os avisos que esta fase mandou à sessão da 188 — e o que a 188 fez com cada um

| aviso | o que ele dizia | o que a 188 fez |
|---|---|---|
| **AVISO-189-1** | `FotosDoPainelService::para($oferta)` é chamado **SEM** índice → `selecionado = 0` → a `borda` da imagem 0 sai **sempre** `selecionada`. A grade do 188-08 **não pode** ler `borda` cru do servidor | **ATENDIDO**: a seleção é do **CLIENTE**, com borda de **5 ramos** e precedência provada **por CONFLITO** (`coluna-de-fotos` 32/32/0). O lightbox (189-03) segue a mesma regra |
| **AVISO-189-2** | o payload traz `grupo` (**chave canônica**) e `grupo_geral`, mas **nenhum rótulo humano** de variação; o rótulo só existe em `ResolvedorGruposImagem::rotuloDoGrupo`, **privado**, em território VETADO | **ATENDIDO COMO LACUNA DECIDIDA**: o 188-08 **não desenha variação nenhuma** (`BlocoDaImagemSelecionada.jsx:297` tem o comentário declarando), e a 189 **usou o que ele usou**. ⚠️ Custo funcional na §10 |
| **AVISO-189-3** | se o 188-12 montar o `ModalDeReprovarImagem` **DENTRO** do `<aside>` `z-[40]`, o `z-[42]` vale **dentro** do contexto do painel e o modal aberto do lightbox (`z-[41]`) fica **ATRÁS** dele — a pessoa clica "Reprovar" e **nada aparece** | **ATENDIDO**: o 189-07 provou **por ÁRVORE do HTML** (render real, named export da raiz) que o fundo do modal **NÃO é descendente** do `<aside>`. ⚠️ **O índice absoluto é FUNÇÃO DA FIXTURA** (medido: 12 com 1 foto, 15 com 4) — **o invariante é PROFUNDIDADE + IRMANDADE**, e é assim que está travado |

---

## §9 — OS GATES E ARQUIVOS **ALHEIOS** QUE ESTA FASE TOCOU, DECLARADOS

⚠️ **Nenhum destes é violação de território:** a `189-REGUA.md` §8 os autoriza, nominalmente, *"o
mínimo, declarando"*. O que seria violação é **esconder**.

| arquivo alheio | dono | plano que tocou | o que mudou, e a razão |
|---|---|---|---|
| `PainelDeRevisao.jsx` | **188-07** | **189-06**, **189-07** | o **estado** `aberto/indice` do lightbox, o **degrau do `Escape`** (que a 188-07 deixou *"previsto e declarado"* no docblock), `←/→` indo para o lightbox, e a montagem do modal como **irmão** do `<aside>`. ⚠️ `router.post(` medido **1 = 1** contra o HEAD de partida: **nenhuma escrita nova** |
| `ImagemGrandeDoPainel.jsx` | **188-08** | **189-06** | **desenha** o botão `⤢ Ampliar` (protótipo `:607`) — o que a 188-08 deixou de fora **de propósito** (`T-188-75`), e que **REVS-08** atribui a esta fase |
| `ColunaDeFotos.jsx` | **188-08** | **189-06** | o relé da prop do `⤢ Ampliar` até a imagem grande |
| `Aprovacao.jsx` (a página) | **187** | **189-06** | **só** o repasse da prop `lightbox` e o `useState` do contexto |
| `MlbPublicadorAprovacaoController.php` | **188-02** | **189-02** | **só** a montagem da prop `lightbox` (recortada ao `?oferta=`) e a injeção do serviço **novo**. ⚠️ **`PainelDeRevisaoService` NÃO foi tocado** |
| `tests/js/publicador-painel-fotos-188.test.js` | **188-08** | **189-06** | **INVERSÃO** de **exatamente** `:639` e `:640` (a ausência do `⤢ Ampliar`), com prova — e **sem enfraquecer** a ausência de "Gerar novamente com IA". ⚠️ Contagem **`32,28` INALTERADA**; os outros **SETE** gates JS de ausência (+2 PHP) seguem **VERDES e INTOCADOS** |
| `tests/js/publicador-painel-casca-188.test.js` | **188-07** | **189-06** | ⚠️ **o conjunto `ACOES` era FECHADO nas quatro da 188-07** e **REPROVOU o degrau** (`:1264`). Reaponte por **duas listas NOMEADAS** (`ACOES_DA_188_07` exigida uma por uma + `ACOES_DEPOIS_DA_188_07` com a fase dona ao lado), conjunto **ainda FECHADO** (uma sétima ação reprova) e **isca nas duas direções**. Contagem **`45,41` INALTERADA** |
| `tests/js/publicador-fila-tokens-187.test.js` | **187** | **189-01** | **UMA linha** em `LIBERADOS_DEPOIS_DA_187` liberando o tamanho **`18`** (as setas `←`/`→`, `:741`/`:742`) — **pela via canônica que o docblock daquela lista prescreve**. ⚠️ A linha do `21` (da 188-01) **não** foi tocada, e `19`, `21.5`, `23`, `25`, `26` seguem **BARRADOS** |
| `tests/js/publicador-fila-filtros-187.test.js` | **187** | **189-06** | ⚠️ **`ESTADO_LOCAL_DA_PAGINA` reprovou pelo `useState` do lightbox**, e **só a SUÍTE COMPLETA o achou**. Conserto pela **via canônica**: **uma linha NOMEADA** (`doLightbox`) com a fase, o plano e a razão. Lista **ainda fechada nos dois sentidos**; contagem **29** inalterada |
| `tests/js/publicador-fila-fechamento-187.test.js` | **187** | **189-03** | `JSX_DEPOIS_DA_187` — a **segunda** escrituração obrigatória por `.jsx` novo (⚠️ são **DUAS** e isso é **DELIBERADO**; a 188 **RECUSOU** criar a terceira) |
| `tests/js/publicador-vocabulario-novo.test.js` | transversal | **189-03**, **189-05** | `ARQUIVOS_NOVOS` ganhou `LightboxDaImagem.jsx` e `LateralDoLightbox.jsx`, e `TAMANHOS_PERMITIDOS` ganhou o **`18`** — **na MESMA tarefa que criou cada peça** |

### ⚠️ §9.1 — Os gates ALHEIOS que **reprovaram DENTRO** da fase, e de que lado ficou o conserto

| gate | por que reprovou | o conserto, e onde |
|---|---|---|
| `publicador-painel-casca-188.test.js:1264` (188-07) | o conjunto `ACOES` era **FECHADO** nas quatro ações da 188-07 | **do lado da 188-07, por REAPONTE com veto** (§9), contagem alheia **inalterada**. ⚠️ **E a premissa escrita no plano da 189-06 — *"todos os casos do `casca-188` continuam passando sem alteração"* — era FALSA, e isso foi MEDIDO** |
| `publicador-fila-filtros-187.test.js:684` (187-06) | o `useState` novo da página | **via canônica**, uma linha nomeada |
| `publicador-painel-modal-reprovar-188.test.js:581` (188-12) | cobra os **parâmetros literais** do caminho do **PAINEL**, e a 1ª redação da 189-07 os mudou | ⚠️⚠️ **A 189-07 NÃO devolveu: consertou do PRÓPRIO lado**, com **ramo separado**, mantendo o caminho do painel **byte a byte**, e **acrescentou ao seu gate** a asserção que faltava. ➡️ **É o padrão CORRETO:** gate alheio que reprova trabalho novo e **está CERTO** se resolve mudando o trabalho novo, não o gate |
| `publicador-painel-casca-188.test.js:1097` (188-07) | **migration UNTRACKED de OUTRA SESSÃO** no meio da wave | ⚠️ **ninguém consertou** — ver §10. Medido **agora**: `git status --porcelain -- database/migrations` sai **VAZIO** (a outra sessão commitou a migration em `1253cc86`) ➡️ **o gate está VERDE de novo**, e **a Fase 189 não tem migration** |

⚠️⚠️ **E o MAIOR TEMA desta fase, igual ao da 188:** **nenhum número alheio foi SUBIDO.** Os dois
reapontes foram por **LISTA NOMEADA** (não por total), e as duas contagens alheias (`45,41` e
`32,28`) saíram **IGUAIS**. ➡️ *"Número que o trabalho correto derruba se conserta na RAIZ, nunca
elevando."*

---

## §10 — AS DÍVIDAS DESTA FASE, E AS DECISÕES QUE ESTE FECHAMENTO TOMOU

### ⚠️ D-01 — `publicador-lightbox-casca-189.test.js:1352` é MAIS LARGO QUE O INVARIANTE — **decidido: NÃO consertar**

**O que é:** `assert.ok(FONTE_LIGHTBOX.includes('createPortal'), …)`. Um mutante que trocasse o
import por uma função local (`const createPortal = (filho) => filho;`) **mantém o identificador** e
**o gate passa**. Medido pela 189-04:

```
MUTADO M1: includes("createPortal")                     = true     <- a sonda LARGA fica VERDE
MUTADO M1: includes("import { createPortal } from ...") = false    <- a agulha EXATA acusa
```

**A DECISÃO, e a razão MEDIDA: não consertar.** Três motivos, nesta ordem:
1. ⚠️⚠️ **O furo que ela deixava JÁ ESTÁ FECHADO, e eu medi onde:**
   `tests/js/publicador-lightbox-camadas-189.test.js:726`-`:733` (189-04, commit `86b887bf`) trava a
   premissa pelas **quatro formas EXATAS** — `import { createPortal } from 'react-dom';`,
   `return createPortal(`, `document.body,` e `typeof document === 'undefined'` —, **cada uma casando
   1 vez**, com a isca construída **do mutante M1 de verdade** e uma asserção que **registra** que a
   sonda larga continuaria verde. ➡️ O `:1352` é **REDUNDÂNCIA LARGA, não FURO**.
2. Editá-lo mudaria o **`tests=27`** que o `contagens-189-03.txt` declara, numa wave **fechada**, e
   **sem ganhar invariante nenhum** — é remedir contagem por conveniência.
3. Removê-lo também seria errado: é a família do **"conserto barato"** (tirar asserção da lista).

➡️ **Dono: nenhum pendente.** O invariante tem dono (`camadas-e-css`) e está provado. ⚠️ **Mas o
caso fica NOMEADO**, porque ele é a **15ª ocorrência** de *"gate mais largo que o invariante"* nesta
milestone — e uma dessa família **já barrou um deploy**.

### ⚠️⚠️ D-02 — A FAMÍLIA *"gate mede WIP, não território"*: são **24 pontos em 7 arquivos**, não 3 — **decidido: declarar, NÃO consertar**

O `deferred-items.md` registrava **3** casos. **Medido por mim agora:**

```
grep -rn "numstatDe(" tests/js/publicador-painel-*-188.test.js
→ 24 pontos de chamada, em 7 arquivos (preco, rodape, aviso-versao, modais-a, modais-b,
  modal-excecao, modal-reprovar)  —  TODOS com `base` ausente  ->  `HEAD`
+ tests/js/publicador-painel-casca-188.test.js:1084 e :1097  (`git status --porcelain`)
```

⚠️⚠️ **O diagnóstico PRECISO, e ele é pior e melhor do que o registro dizia:**
- **pior:** `git diff --numstat HEAD -- <rel>` compara a **ÁRVORE DE TRABALHO** com o HEAD. Depois do
  commit sai **VAZIO SEMPRE**. ➡️ O gate **reprova ANTES do commit** e fica **verde DEPOIS** — ele
  mede **WIP**, não território, e é **vazio por construção** exatamente quando precisaria falar;
- **pior ainda:** cada um desses pontos **TEM** uma isca de não-cegueira
  (`numstatDe(rel, ARVORE_VAZIA)`), e ela passa. ⚠️ **Mas a isca é ELA MESMA mais larga que o
  invariante:** ela prova que **o pathspec resolve**, **não** que um commit que tocasse o arquivo
  seria pego. ➡️ *"Isca que prova que a sonda vê ALGO não prova que ela vê A AMEAÇA."*
- **melhor:** ⚠️ **o conserto de RAIZ já está no código.** O helper é `numstatDe(rel, base)` e
  **aceita a base**: passar a **PARTIDA do plano** (`numstatDe(rel, PARTIDA)`) transforma os 24
  pontos em gate de **território**. ⚠️ E o **`casca-188:549` JÁ USA a forma certa**
  (`git diff --numstat HEAD~1 HEAD`, um **RANGE**) — ou seja, a forma correta era **conhecida** no
  mesmo arquivo.

**A DECISÃO: declarar, não consertar.** São gates de fase **EM PRODUÇÃO** (188), **fora do
`files_modified` deste plano** (que é o fechamento, o `deferred-items.md`, o gate próprio e o
manifesto), e **fora** do que a `189-REGUA.md` §8 autoriza desta fase a editar. ➡️ **Dono:
usuário / orquestrador** — é quem pode autorizar mexer em gate de fase em produção.
⚠️ **O terceiro caso SE CONCRETIZOU em 20/10** (`casca-188:1097`, a migration untracked da outra
sessão): `casca-do-painel` **45/45/0** → **45/44/1** → **45/45/0**, e **nenhuma regressão de
ninguém**.

### ⚠️ D-03 — Os ponteiros do `189-FID01.md` §G apodreceram **de novo** — RECONFIRMADOS POR `sed` AGORA

| ponteiro escrito no §G | onde está **AGORA** (`sed -n '<N>p'`, 20/10 à noite) |
|---|---|
| `.planning/ROADMAP.md` **L5038** (`MOD-06 (1) \| Phase 190`) | ⚠️ **L5038 está VAZIA.** A linha está em **L5132**: `\| MOD-06 (1) \| ~~Phase 190~~ \| ⛔ **Fora de escopo (20/10/2026)** \|` — a 189-06 mediu `L5081`, a 189-07 mediu `L5132`, e **moveu outra vez** depois dos meus commits de escopo |
| `.planning/ROADMAP.md` **L4730** (`Requirements: … MOD-06`) | ⚠️ **L4730** é hoje *"A comparação com o original de referência NÃO se perde…"*. A linha está em **L4752**: `**Requirements**: REVD-01..09, IMG-07..13, MOD-06` |
| `grep -n "Requirements:.*MOD-06"` **na forma citada** | ⚠️⚠️ **devolve ZERO** — e a razão é o markdown: o arquivo tem `**Requirements**:`, com os asteriscos **ANTES** dos dois-pontos. ➡️ **A forma citada é um gate vazio por construção** |

⚠️ **E um TERCEIRO apodrecimento, novo:** o §G atribui o **MOD-06** à **Fase 190** — que foi
**CANCELADA** em 20/10. ➡️ O ponteiro não só mudou de linha: **mudou de verdade**.

**A DECISÃO: NÃO reescrever o `189-FID01.md`.** **CINCO** arquivos de gate o leem
(`FechamentoDeBaselines189Test.php`, `contagens-189-01.txt`,
`publicador-lightbox-casca-189.test.js`, `publicador-lightbox-tokens-189.test.js`,
`publicador-vocabulario-novo.test.js`), e um deles tem **isca por BYTES da §G**. A régua permite
**ESTENDER**, não reescrever. ➡️ **Dono: usuário / orquestrador.**
⚠️⚠️ **E a regra que fica, de graça:** nesta milestone **`:N` é EXCLUSIVO do protótipo**; linha de
qualquer outro arquivo vai como **`L<N>`** — e **nunca se usa número de linha de artefato
`.planning/` sem RECONFIRMAR por `sed`**. *"Ponteiro de linha para documento VIVO apodrece sozinho"*
— apodreceu **três vezes em dois dias**.

### ⚠️ D-04 — `refLabel`: o placeholder da caixa da original — **sem dono**

**Medido:** o protótipo `:755` é `<image-slot … placeholder="{{ LB.refLabel }}">` e `:1111` define
`refLabel: m.ref ? this.im(m.ref, D).label : ''` — **o placeholder é o RÓTULO da imagem original**.
A prop `lightbox.originais` do **189-02** traz `disponivel`, `url_arquivo` e `quantas`, e **não** o
rótulo. ➡️ O 189-05 usou `TITULO_DA_ORIGINAL` (*"Original de referência"*): **nunca caixa vazia**,
**nunca `<img src="">"`**.
**O conserto é de UMA linha** no `LightboxDoPainelService` (ele **já carrega** a original para montar
a URL; bastaria devolver o `rotulo` dela). ⚠️ **Não é um quinto conflito protótipo × regra** — é
**lacuna de DADO**, a mesma classe do rótulo de variação.
➡️ **Dono: sem dono (190/191 fora de escopo em 20/10/2026) — devolvido ao usuário / orquestrador.**
Impacto: aparece **só** quando o arquivo da original está ausente.

### ⚠️ D-05 — A linha **"Variação"** não é desenhada — **sem dono**, com o CUSTO FUNCIONAL escrito

**Decidido pelo usuário em 19/10:** seguir **sem o nome**, e **não vazar a chave canônica**. O payload
traz `grupo` = `ChaveCanonica::combinacao` (na prática **`COLOR=id:52049`**) e o rótulo humano é
`ResolvedorGruposImagem::rotuloDoGrupo`, **`private static`** em `App\Support\Publicador` —
**território VETADO**. O 188-08 **não desenha variação nenhuma**, e a régua §2 manda *"a 189 USA O
QUE O 188-08 USAR"*.

⚠️⚠️ **O CUSTO FUNCIONAL, escrito para não se perder:** num produto com **3 cores**, as fotos das
três aparecem **juntas na mesma grade, sem dizer qual é de qual cor**. Quem revisa vê 12 fotos e
**não sabe** que as 4 primeiras são da preta. ➡️ Não é defeito de código: é **lacuna de DADO
decidida**, e o conserto é tornar público (ou espelhar) o `rotuloDoGrupo`.
➡️ **Dono: sem dono (190/191 fora de escopo em 20/10/2026).**
⚠️ **E o `A4` do inventário se confirma:** o docblock do 188-08 manda *"ver a divergência escrita em
`VARIACAO`"* e **não há `VARIACAO` naquele arquivo** — erro de **REFERÊNCIA dele**, registrado, **não
desta fase consertar**.

### ⚠️ D-06 — Os critérios de aceitação **VAZIOS ou FALSOS POR CONSTRUÇÃO**, achados pelos planos

⚠️ *"O que precisaria estar errado no mundo para este critério falhar?"* — **"Nada" é ACHADO.**

| de quem | o critério | o que foi medido | o que a wave fez |
|---|---|---|---|
| **189-02** | — | *"o critério é falso na partida"* | trocado |
| **189-05** | `grep -c "aprovacao/LateralDoLightbox.jsx" publicador-vocabulario-novo.test.js` = **1** | **0 — SEMPRE**, e **0 também** para o vizinho `aprovacao/LightboxDaImagem.jsx`: as entradas de `ARQUIVOS_NOVOS` são **template literals** (`${DIR_APROV}/Nome.jsx`), então a substring `aprovacao/` **nunca encosta no nome do arquivo** | trocado pelo equivalente **honesto** (`grep -c "LateralDoLightbox.jsx"` = 1) em **cada uma das DUAS listas**, com sonda não-cega |
| **189-07** | *"SE o modal ou a montagem mudou: PI(189-07) ∩ `contagens-188-12.txt` = ∅"* | ⚠️ **ANTECEDENTE FALSO**: o modal **não precisou mudar** (ele já recebe a imagem por prop e **não escreve**). Com o antecedente falso, o critério **não mede nada** | rodou o **consequente de todo jeito** (os 3 ids do 188-12 em `exit=0` com `19,17`/`22,18`/`12,10` inalterados, a PI com interseção **vazia** e a isca `(188-12)` **ACUSANDO**) e **registrou** que o antecedente é falso |
| **189-07** | um `key_link` do frontmatter (`ModalDeReprovarImagem` em `PainelDeRevisao.jsx:1137`) | ⚠️ satisfeito **SÓ POR COMENTÁRIO** — a linha é **docblock**; o elo REAL é a `Aprovacao.jsx` | registrado |

### ⚠️ §10.1 — OS CRITÉRIOS DE ACEITAÇÃO **DESTE** PLANO, RODADOS — e o que cada um mediu

| critério do 189-08 | rodado | veredicto |
|---|---|---|
| as linhas `COMMIT:`, `BUILD:`, `MANIFEST:`, `JS:`, `PHP:`, `PROTOTIPO:` com o comando ao lado | sim | **OK** — §2 e §2.1 (e acrescentei `PARTIDA:`, porque `PARTIDA` e `COMMIT` **não são a mesma coisa**) |
| `--suite php` ≥ baseline, com no máximo as **2** falhas de `CockpitDaContaTest` | sim | **OK — e MELHOR**: `2631 / 24041 / **0**`. ⚠️ **O critério é satisfeito VACUAMENTE pelo "no máximo 2"** (zero ≤ 2); o que o torna informativo é a §2.5, que diz **por que** passou de 1 para 0 |
| `fechamento-territorio` sai 0; a isca do escopo registrada | sim | **OK** — §3.1, 4 iscas |
| a §9 lista cada arquivo da 188 tocado com plano e razão | sim | **OK** — 11 arquivos |
| `grep -cE '^CRIT-0[1-8]: (OK\|RESSALVA\|REPROVA) — '` = **8** | sim | **OK** |
| `grep -cE '^CRIT-04: RESSALVA — .*dono: Fase 190'` = **1** | sim | ⚠️ **OK, com DESVIO DECLARADO** — ver abaixo |
| o documento tem a ressalva de "Gerar novamente com IA" com `dono: Fase 192` | sim | **OK** — §4 (tabela de REQ-IDs) e §8 |
| `grep -c "a definir"` na seção de perguntas = **0** | sim | ⚠️⚠️ **ARMADILHA POR CONSTRUÇÃO, declarada**: escrever a **própria regra** na seção faria o `grep` devolver **≥1** com todas as perguntas tendo dono. ➡️ Rodei o **equivalente honesto, por linha** (cada `PERGUNTA-*` casa `dono:\s*\S` e **não** casa `dono:\s*a\s+definir`): **7 de 7 com dono, 0 indefinidos**. O critério literal **passa** porque a regra foi reescrita sem a expressão — ver §8 |
| `fechamento-criterios`, `fechamento-manifestos` e `--autoteste` (20/0) saem 0 | sim | **OK** — §7 e §15 |
| a bijeção id ↔ `<verify>` nos 8 pares sem infratores, com as duas iscas reprovando | sim | **OK** — §7 |

⚠️⚠️ **O DESVIO DECLARADO, e é o único deste plano:** o `must_haves` e o critério de aceitação do
plano (escritos em **19/10**) mandam o CRIT-04 sair com **`dono: Fase 190`**. Em **20/10** o usuário
**cancelou a Fase 190** (decisão 20, `0607f5a9`), e o briefing desta wave manda escrever **"sem dono
(190/191 fora de escopo em 20/10/2026)"**. ➡️ **A linha do CRIT-04 carrega AS DUAS COISAS**: a
atribuição que o plano fixou **e** o cancelamento medido. Escrever só a primeira faria o leitor
**esperar entrega de uma fase que não existe**; escrever só a segunda **apagaria** a atribuição
original do plano. ⚠️ **O que NÃO foi feito é o CRIT-04 sair `OK`** — e o gate deste plano o
**proíbe**, nas duas direções.

---

## ⚠️⚠️ §11 — O ROTEIRO DA CONFERÊNCIA VISUAL — CONTA 459

> ⚠️⚠️ **Esta fase NÃO deployou, NÃO deu push e NÃO tocou no VPS.** **Este roteiro só é executável
> depois de um deploy que VOCÊ autorize.**
>
> **Onde:** a conta 459, em `/mlb/anuncios/meus/459`, aba **"Aguardando aprovação"**.
>
> ⚠️ *(Aqui o nome da conta vai **sem negrito**, e isso foi MEDIDO: `conta **459**` **NÃO** casa
> `conta\s+459` — os asteriscos do markdown ficam **ENTRE** as duas palavras. É a mesma família do
> `\s+` obrigatório nas frases que quebram em 100 colunas, com uma face nova.)*
>
> ⚠️ **As conferências visuais das Fases 187 (a fila) e 188 (o painel) continuam PENDENTES e
> SEPARADAS** — esta **não** as substitui.
>
> ⚠️ **Nada abaixo é relato de observação.** Nenhum executor desta fase abriu navegador; tudo o que
> está nas sete SUMMARYs é HTML gerado por `react-dom/server`.

### ⚠️ Sem oferta na fila — **o caso PROVÁVEL hoje**, e é por aqui que se começa

⚠️⚠️ **A fila de produção está VAZIA** (`pub_aprovacao_ofertas` sem linha). ⚠️ **Mas isso pode mudar
sem aviso:** o commit `c810a8ac` (AG-03, de **outra sessão**, ainda **não publicado**) ligou o
`PreparadorDaAprovacao`, que é **o primeiro código de produção que CRIA `PubAprovacaoOferta`**.
➡️ A primeira oferta real **deixou de depender só da Fase 194**.

  1. Em `/mlb/anuncios/meus/459`, aba **"Aguardando aprovação"**: confira que a fila continua
     **honesta** — o texto do vazio, **sem botão** — e que **nada regrediu** na lista de Publicações.
  2. ⚠️ **Se a fila estiver vazia, o lightbox fica conferido junto com a Fase 194**, e isso vai
     registrado como **RESSALVA**, nunca como OK. ➡️ **Pare aqui e me diga que a fila estava vazia.**

### Com oferta na fila — os itens, um por critério

  3. Abrir uma oferta (**Revisar**) → no painel, na imagem grande, o botão **"⤢ Ampliar"** no canto
     inferior direito. Clicar: a tela inteira escurece **por cima do painel**, e o **header continua
     igual** — nada nele mudou. *(critério 1)*
  4. A imagem aparece **quadrada**, grande (até ~78% da altura da tela). Clicar em **"Ampliar 2,5×"**
     na lateral: a imagem aumenta e, **ao mover o mouse sobre ela, a parte ampliada acompanha o
     cursor**; o cursor vira o de "reduzir". O botão passa a dizer **"Reduzir"**. ⚠️ **É este item
     que a RESSALVA do critério 2 espera de você** — o movimento não pôde ser provado sem navegador.
     *(critérios 2 e 3)*
  5. Os **números nos círculos** sobre a foto são **os mesmos** dos itens da lateral (o item "1" fala
     do ponto "1"). ⚠️ Os itens com **✓** **não** têm número — está certo. *(critério 2)*
  6. **←/→** (ou as setas na tela) trocam a foto; **da última, → volta para a primeira** (dá a
     volta — diferente do painel, que para na última). Trocar de foto **volta o zoom para 1×**. As
     miniaturas embaixo marcam a foto atual com a **borda amarela**. *(critério 7)*
  7. A lateral: **Origem**, **Posição** (com `· principal` na principal) e **Estado** (com **texto**,
     não só cor). ⚠️⚠️ **NÃO procure a linha "Variação": ela NÃO existe**, por sua decisão de 19/10 —
     o sistema só tem a chave interna da cor, e mostrá-la seria pior que omiti-la. ⚠️ **O custo:** num
     produto com 3 cores, as fotos das três aparecem juntas **sem dizer qual é de qual cor**.
     *(critério 4 — RESSALVA)*
  8. Numa foto **gerada por IA**: o bloco **"Original de referência"**. ⚠️ **Hoje, em quase todas, vai
     aparecer "Original de referência não disponível"**, com um ícone e a frase de que ela é anterior
     ao registro: **é o ESPERADO**, não defeito — o vínculo só passa a ser gravado nas Fases 192/194.
     Numa foto **Original**, esse bloco **não existe**. *(critério 5)*
  9. ⚠️⚠️ **NÃO procure "Comparar lado a lado na revisão detalhada" nem "✦ Gerar novamente com IA"** —
     o primeiro **não existe** porque a tela de destino foi **cancelada** por sua decisão de 20/10
     (decisão 20: a revisão detalhada saiu do escopo), e o segundo **nasce na Fase 192**. *(critério 4
     — RESSALVA)*
 10. Numa foto **"A conferir"** de uma oferta **em revisão**: **Reprovar** e **Aprovar foto** aparecem
     embaixo da lateral. Numa foto **"Regenerando"**, **aprovada** ou **reprovada**: **não** aparecem.
     *(critério 6)*
 11. **Reprovar** abre o modal de motivo **por cima** do lightbox — e **não escondido atrás dele**.
     **Esc** fecha **só o modal**; outro **Esc** fecha o lightbox, e o painel volta **na mesma foto de
     onde você abriu**, com o foco no "⤢ Ampliar". ⚠️ **É este item que a RESSALVA do critério 7
     espera de você.** *(critério 7)*
     ⚠️⚠️ **SÓ APROVE SE QUISER APROVAR DE VERDADE: aprovar foto vale para as DUAS ofertas do
     conjunto, aprovar a oferta ENFILEIRA, e NÃO HÁ DESFAZER.** A escrita no Mercado Livre é da
     **Fase 193**.
 12. Com o lightbox aberto numa foto da oferta **A**, use **↑/↓**: a fila navega **por baixo** (o
     painel passa à oferta **B**) e o lightbox **continua mostrando as fotos da A** — é a sua resposta
     à PERGUNTA-189-B. ⚠️ Se aprovar/reprovar uma foto ali, a ação vale para **a foto da A** que está
     no lightbox — e, **por padrão** (PERGUNTA-189-D, **ainda ABERTA**), o lightbox **FECHA** depois
     da ação. Fechando com **Esc**, o painel **fica na oferta B**. ➡️ **Diga se preferir o outro
     comportamento** (o painel voltar para a A).

➡️ ⚠️ **Divergência que você relatar vira item com `arquivo:linha`** — numa quick task ou numa
SUMMARY, **nunca "ajuste" silencioso de critério.**

---

## §12 — O QUE O **ORQUESTRADOR** TEM DE MARCAR À MÃO, E POR QUÊ

⚠️ **Nenhum dos 8 planos tocou `ROADMAP.md`, `REQUIREMENTS-v27.md` ou `STATE.md`** (GATE-07, provado
na §3.4). O que segue é para a **mão** dele.

| arquivo | o que marcar | por que à mão |
|---|---|---|
| `.planning/ROADMAP.md` — **Coverage Map** | a linha `\| AMPL-01..AMPL-07 (7) \| Phase 189 \| Pending \|` (hoje **L5129**) → **"⚠️ Entregue no CÓDIGO (20/10/2026) — ⚠️ NÃO DEPLOYADO — CRIT-01, 03, 05, 06, 08 OK; CRIT-02 e CRIT-07 RESSALVA (ambiente: o MOVIMENTO do zoom e o RUNTIME do teclado/foco NÃO são provados — não há navegador; dono: usuário, PERGUNTA-VISUAL-189); CRIT-04 RESSALVA (o "Comparar lado a lado" NÃO é desenhado — dono: Fase 190 ⛔ CANCELADA ⇒ AMPL-05 sem dono; e a linha "Variação" não é desenhada por decisão do usuário de 19/10). AMPL-07 RESSALVA — "✦ Gerar novamente com IA" é da Fase 192"** | ⚠️ **BLOCO ADITIVO, com 0 REMOÇÕES**, conferido por `git diff --numstat` (a coluna de deleções tem de ser **0**). ⚠️ **RECONFIRME a linha por `sed` antes de editar** — o arquivo cresceu de 5399 para **5452** linhas em um dia |
| `.planning/ROADMAP.md` — **transversais** | uma linha `\| FID, GATE, REND, TERR (transversais) \| Phase 189 (repetidos) \|` com: FID-01 **6 de 8 por SCRIPT**, 2 SEM-PROTOTIPO, **zero por LEITURA**; território por **ESCOPO DO ASSUNTO** nos **32** alvos, **0 violações** em 2 passadas, `test -e` **antes** de todo diff (**6 fantasmas PEGOS**), isca nas **4** direções; **57 mutações**, **0 sobreviventes ao fim** (4 eventos, 4 investigados); **21 ids** em 8 manifestos, **0 órfãos** nas três bijeções; `--autoteste` **20/0** | idem |
| `.planning/REQUIREMENTS-v27.md` | nada por comando | ⚠️ ele tem **ZERO checkbox** (`grep -c "^- \["` → **0**), e `requirements.mark-complete` devolve **`not_found`** nele. **Marcar à mão, ou não marcar** |
| `.planning/STATE.md` | posição, decisões e sessão | ⚠️⚠️ **NÃO rode `state.advance-plan`** — ele **corrompeu o `STATE.md` SEIS vezes**. ⚠️ **E o `STATE.md` é CRLF**: âncora de `Edit` com `\n` casa **ZERO** |

---

## §13 — AVISOS ÀS FASES SEGUINTES

⚠️⚠️ **A ordem mudou em 20/10/2026 (decisão 20):** as Fases **190 e 191 estão ⛔ FORA DE ESCOPO**, e o
caminho é **`189 → 193 → 194 → 192`**. ➡️ **Não existe mais "a próxima fase" entre esta e a 193.**

### Para a **Fase 193** (do aprovado ao ar)

- ⚠️⚠️ **O briefing que dizia *"reusar `MlPublicacaoService`"* está ERRADO, e foi MEDIDO:** aquele
  serviço recebe `MlAnuncioRascunho`, o rascunho do **assistente antigo**, e **zero `PubRascunho`**.
  O `POST /items` do **Publicador** está em **`app/Services/Publicador/PublicacaoService.php:442`**
  (⚠️ e **não** em `app/Services/Mlb/Publicacao/`, que foi um fantasma desta wave — §3.3). Obedecer
  o briefing ligaria o portão ao **publicador errado**.
- ⚠️ **A Fase 193 NÃO tem migration:** as três colunas já existem desde a migration da 186.
- ⚠️ **"Aprovar ENFILEIRA, não publica"** é invariante travado por gate **desde a 187**, com gate de
  ausência dos quatro rótulos de "segundo passo". A 193 é quem **inverte** isso — e tem de inverter
  **com prova**, pelo padrão do §9 (lista nomeada, nunca total).

### Para a **Fase 194** (ligar `publicador.criativos_auto.ativo`)

- ⚠️ **O lightbox reusa a MESMA rota de arquivo** (`mlb.anuncios.publicador.fotos.arquivo`) também
  para a **original de referência** ➡️ ele **AMPLIA a janela do `EditorEmUso`** (3 minutos por
  leitura) **enquanto alguém revisa**. Quem vir agendamento parado durante uma revisão longa, a causa
  é esta — e ela está declarada **por NOME** no docblock do `LightboxDoPainelService` (`AgendadorDaFila::checar`,
  `PreparoIaDoRascunhoService::preparar`/`::sincronizarAgora`/`::executarEtapa`,
  `GarantiaPadrao::aplicarNaConta`, `ExcluirProdutoService::impedimento` — **5 arquivos, 6 pontos,
  zero número de linha**).
- ⚠️ **A fila só ganha oferta de verdade aqui** — então **a conferência visual do lightbox com dado
  real depende desta fase**, e é por isso que a `PERGUNTA-VISUAL-189` fica ABERTA.
- ⚠️ **Mas o produtor já existe:** `c810a8ac` (AG-03, outra sessão). **Confira se a fila já não
  encheu antes de você.**

### Para a **Fase 192** (gerar novamente com IA) — agora a **ÚLTIMA** da milestone

- ⚠️ **"✦ Gerar novamente com IA" nasce lá, em DOIS lugares:** no painel (`:606`) **e** no lightbox
  (`:762`). Os dois têm **gate de ausência COM ISCA** hoje — invertê-los exige **prova**, e
  ⚠️ **a mutação (v) da 189-06 já mostrou que a ausência estava provada SÓ num regime**: confira os
  **dois** (com handler e sem).
- ⚠️ **É ela quem escreve `pub_imagem_referencias`** — e é o que tira as imagens novas do estado
  IMG-13. ⚠️ **Hoje há gate da 186 varrendo `app/` INTEIRO** contra essa escrita
  (`VinculoDuravelDaOriginalTest::test_dado_15_esta_fase_nao_escreve_o_vinculo_com_isca_de_dois_lados`):
  a 192 vai ter de **reapontá-lo**, e pelo padrão do §9 — **lista nomeada, nunca total**.
- ⚠️ **O estado IMG-13 desta fase é a fonte única do texto** (`PubImagemReferencia::SEM_ORIGINAL*`).
  **Reusar, não reescrever** — duas numerações da mesma verdade divergem.

### ⚠️ Para quem for mexer na página `Aprovacao.jsx`

⚠️⚠️ **Declare as variáveis ANTES do primeiro construtor de slot.** A quick `261020-tdz` moveu
**quatro blocos** para consertar **cinco** telas pretas, e **recusou de propósito** a alternativa de
mover os *leitores* (tocaria ~83 linhas em vez de 126, mas deixaria a **ordem de declaração
quebrada**, e a próxima wave que acrescentasse um atributo eager perto dos slots
**reintroduziria o defeito**). ➡️ **O gate que guarda isso é
`tests/js/publicador-pagina-aprovacao-tdz-261020.test.js` (7 casos), e ele RENDERIZA a página.**

---

## §14 — OS ACHADOS QUE ESTA FASE PRODUZIU, CONSOLIDADOS

### ⚠️⚠️ 1. AS **CINCO** TELAS PRETAS, e a CAUSA RAIZ que vale mais que o defeito

**O que a 189-07 achou:** o corpo de `export default function Aprovacao(…)` lia **QUATRO** `const` em
posição **EAGER** (prop de JSX atribuída direto a um `const` do corpo, fora de qualquer arrow)
**antes** de elas serem inicializadas — `abrirModalDeReprovar`, `registrarConflito`,
`abrirModalDoPainel`, `precoComSimulacao`, das waves **188-08/09/11/12**. Em JavaScript um `const`
não inicializado está na **TDZ**: a leitura **LANÇA**. E a leitura é **EAGER** —
`<ColunaDeFotos onReprovar={abrirModalDeReprovar} />` compila para
`jsx(ColunaDeFotos, { onReprovar: abrirModalDeReprovar, … })`, que avalia o identificador **na hora**.
➡️ **No instante em que `painel.fotos` não é nulo — ou seja, no instante em que o painel abre com
payload — a página inteira lança.** `ReferenceError: Cannot access 'X' before initialization`.

**⚠️⚠️ ERAM CINCO.** A quick `261020-tdz` (`ee201392` + `85c3b46b`) mediu o quinto:
**`podeEscreverNoPainel`**, declarada em **`:1107`** e lida **EAGER quatro vezes**
(`:1013`, `:1015`, `:1016`, `:1017`) na forma

```jsx
onConferirAlteracao={!podeEscreverNoPainel ? null : () => escreverDireto(…)}
```

— **o TESTE do ternário**, que é avaliado **na hora**. ⚠️ **Ele é TDZ por direito próprio, não "um
dependente"**, como a redação original do item supunha. ⚠️⚠️ **E a sonda que achou os quatro NÃO O
VIA**, medido:

| forma | a sonda da 189-07 |
|---|---|
| `onReprovar={abrirModalDeReprovar}` | **CASA** |
| `onConferirAlteracao={!podeEscreverNoPainel ? null : 1}` | **NÃO VÊ** |
| `onInformarFrete={abrirModalDoPainel === null ? 1 : 2}` | **NÃO VÊ** |

➡️ **O quinto morava na forma que a sonda não enxergava.** (E pelo mesmo motivo
`abrirModalDoPainel` tinha **DUAS** leituras eager, não uma.)

**⚠️⚠️ A CAUSA RAIZ — e é o achado mais valioso desta fase:** **NENHUM gate deste projeto jamais
instanciou o `default export` da página.** Todos montam a página por `esbuild` e exercitam **só os
named exports** (`MOD_PAGINA.subDoToastDoLote(…)` etc.). **O componente nunca era renderizado**, e o
TDZ só acontece na instanciação. ➡️ **O defeito morava no ÚNICO lugar que nenhuma sonda olhava**, e
por isso **TRÊS waves** da 188 o publicaram sem que nada acusasse.

**O gate que faltava agora existe:** `tests/js/publicador-pagina-aprovacao-tdz-261020.test.js`
(7 casos, id `pagina-aprovacao-tdz`, manifesto `tests/gates/contagens-261020-01.txt` — **o primeiro
manifesto de QUICK do projeto**), e ele **RENDERIZA** a página.

| prova da quick | resultado |
|---|---|
| **RED antes** | `7 / 2 pass / 5 fail`, com a mensagem real do V8 para **cada um dos cinco** |
| o movimento é **permutação pura** | `sha256` do **multiset de linhas** idêntico antes e depois (`4a5a99fd…`) |
| **nenhuma TDZ nova** | para cada bloco movido, todo identificador lido provado declarado **antes** — **e o veredicto veio por RENDER**, não por leitura |
| render adverso | **88 renders** (10 campos × 8 valores + o `painel` inteiro), nenhum lançando |
| mutação | **6/6 MORTAS**, cada uma devolvendo UMA declaração à posição antiga e o gate **NOMEANDO o símbolo**; `# tests = 7` igual ao pristino nas seis |

⚠️ **A armadilha que quase fez o render adverso ser gate VAZIO:** `PainelDeRevisao` devolve
`createPortal(…)` e `renderToStaticMarkup` **não desenha portal** — o HTML da página tem **o mesmo
tamanho** com `painel: null` e com payload cheio (**`28119` nos dois**). Resolvido com **duas
montagens**: a real (prova *"não lança"*) e um **espião** que desenha os slots inline, com isca de
não-vacuidade (`28167 → 30588`, **+2421 bytes de slot**).

⚠️ **O conserto está provado mas NÃO foi deployado.** A decisão de deploy é do usuário.
⚠️ **E o que mudou a URGÊNCIA não foi decisão nossa:** `c810a8ac`, de outra sessão, entrou **durante**
a quick e **ligou o produtor que enfileira oferta na aprovação** ➡️ o caminho que faria a tela ficar
preta **deixou de ser hipotético**.

### ⚠️⚠️ 2. O VETO QUE REPROVAVA PELO PRÓPRIO CONSERTO — e as DUAS correções de raiz (`62e5efae`)

O caso `publicador-lightbox-acoes-189.test.js:1097` (189-07) era um **VETO POR SUBCONJUNTO**: ele
acusaria um **quinto** TDZ e ficaria verde quando os quatro fossem consertados. ⚠️ **Mas ele provava
a sua não-cegueira PELA EXISTÊNCIA dos defeitos reais** (`assert.ok(antesDaHora.length > 0)`).
➡️ **No instante do conserto ele ficou VERMELHO: `25 / 24 / 1`.**

⚠️⚠️ **Ele era membro da família que o próprio comentário dele dizia evitar.** Consertado pelo
orquestrador em **`62e5efae`**, com **duas** mudanças de raiz:
1. a prova de não-cegueira foi para **CEBO SINTÉTICO** — um trecho de código de teste que **não
   desaparece** quando o código de produção está certo;
2. ⚠️ **a LISTA DE PERMISSÃO SAIU** — porque, com os cinco consertados, ela virava **PONTO CEGO**:
   deixaria passar **regressão** em qualquer um dos cinco nomes.

A varredura foi **unificada numa função só** (havia **duas cópias** da regex, e duas cópias divergem).
⚠️ **O cebo foi provado por MUTAÇÃO NAS DUAS DIREÇÕES** (regex cega → **morre**; regex larga →
**morre**), com `# tests = 25` **nas duas**. ➡️ **O gate está VERDE, `25/25/0`, e a contagem seguiu
`25` — o `contagens-189-07.txt` NÃO precisou de edição.**

⚠️ **E o LIMITE da sonda ficou registrado no próprio arquivo:** ela é **por LINHA**, então não
distingue `prop={nome}` escrito **dentro de uma arrow na MESMA linha**. Medido: a página real **não
tem** essa forma, logo **hoje não há falso positivo**. A prova larga é a do gate da quick, que
**RENDERIZA**.

### ⚠️ 3. O `contagens-186-02.txt:83` reancorado na RAIZ (`5f4fbb5f`) — o **14º**

A 189-03 e a 189-06 devolveram: `voc-07-gate-03 … tests=399,399` num arquivo **COMPARTILHADO** que
mede **695** ➡️ **reprovava com todos os 695 PASSANDO.** Consertado pelo orquestrador: reancorado em
**`tests=>=399`** (o mínimo que a Fase 186 realmente mediu), com **isca exercida**.
➡️ **14º gate de âncora frágil consertado na RAIZ nesta milestone; o 15º é o da §2.5. NENHUM por
elevação de número.**

### ⚠️ 4. Os **DOIS** gates que o inventário A2 **não mapeou** — e o inventário estava **INCOMPLETO**

O `189-CONTRATO-188.md` A2 mapeou **NOVE** gates que barravam a fase, **todos por `/Ampliar/`**.
⚠️ **Dois barravam sem casar `/Ampliar/`:**

| # | gate | o que ele media | como apareceu |
|---|---|---|---|
| **10º** | `publicador-painel-casca-188.test.js:1264` | o conjunto `ACOES` **FECHADO** nas quatro ações da 188-07, com `includes('fechar-lightbox') === false` | **reprovou o degrau** do `Escape` (`casca-188`: 41/39/**1 fail**/1 skip) |
| **11º** | `publicador-fila-filtros-187.test.js:684` | `ESTADO_LOCAL_DA_PAGINA` × os `useState` da página, **nos dois sentidos** | ⚠️ **só a SUÍTE JS COMPLETA o achou** (`--suite js`: 5980/5978/**2 fail**) |

⚠️⚠️ **E a premissa escrita no plano da 189-06 — *"todos os casos do `casca-188` continuam passando
sem alteração"* — era FALSA, e isso foi MEDIDO.** ➡️ **O inventário por PADRÃO DE TEXTO (`/Ampliar/`)
é mais estreito que o invariante *"o que barra esta fase"*.** A lição: **RODE A SUÍTE COMPLETA** — foi
ela que achou o 11º. ⚠️ E o **12º** que a 189-07 procurou **não apareceu**; o que apareceu foi
**outra coisa** (as cinco telas pretas).

### ⚠️ 5. Os **OITO** erros de briefing do ORQUESTRADOR nesta milestone — e os **DOIS** novos desta wave

⚠️ Estão aqui porque são a prova de que a régua *"a MEDIÇÃO ganha do briefing"* **funciona**, e de que
obedecer o briefing contra a medição teria custado trabalho errado.

| # | o briefing dizia | o que foi MEDIDO |
|---|---|---|
| 1 | *"reusar `MlPublicacaoService`"* (Fase 193) | **ERRADO**: ele recebe `MlAnuncioRascunho` e **zero `PubRascunho`**. O `POST /items` do Publicador está em `PublicacaoService.php:442` |
| 2 | *"quatro telas pretas"* | eram **CINCO**, e o quinto era **invisível** para a sonda que achou os quatro |
| 3 | *"a sonda de `font-extrabold` não acharia a classe"* | ela **ACHA** — 135 ocorrências de donos **fora** do Publicador. ⚠️ **Erro na direção PERIGOSA**: eu disse *"falha"*, o real é *"passa VERDE pelo motivo errado"* |
| 4 | *"o índice 13 da lateral"* | é **FUNÇÃO DA FIXTURA** (13 com 1 imagem, 21 com 3; 12 com 1 foto e 15 com 4 no `z-[42]`). **O invariante é PROFUNDIDADE + IRMANDADE** |
| 5 | *"os ponteiros do view-model estão deslocados"* | os **DOZE** estavam **CERTOS** (189-01, §J1) — havia deslocados **em outro lugar** |
| 6 | *"quatro gates"* / *"cinco leitores"* | uma família de **CINCO**; e `EditorEmUso` tem **5 arquivos / 6 PONTOS** |
| 7 | *"`ResumoRapidoService` em `.../Aprovacao/Fila/`"* | mora em `app/Services/Publicador/Fila/`, e **não** é o `FilaDeAprovacaoService`. **TRÊS** dos dez caminhos fantasma vieram de briefing |
| 8 | *"migration provável"* (Fase 193) | **NÃO TEM** — as três colunas existem desde a migration da 186 |
| **9 (NOVO)** | *"o autor não discrimina, as três máquinas usam `MB.ECF-100376`"* | **premissa INCOMPLETA**: há **duas** identidades nos últimos 120 commits (`MB.ECF-100376` ×118, **`ECF Dev` ×2**). ⚠️ A **conclusão** segue certa (todos os 39 do escopo são o mesmo nome), mas o único commit alheio que **de fato quebrou um gate** nesta fase (`cd582f83`) **é** author-discriminável |
| **10 (NOVO)** | *"a migration `2026_10_20_100000_create_pub_agente_tables.php` é untracked de outra sessão"* | **já está RASTREADA** (`git ls-files` a lista; a outra sessão a commitou em `1253cc86`) ➡️ `git status --porcelain -- database/migrations` sai **VAZIO** e o gate `casca-188:1097` está **VERDE** |

### ⚠️ 6. `rgba(…)` em `background` **normaliza para hex de 8 dígitos** no CSS construído

Medido pela 189-04: `bg-[rgba(5,5,5,0.94)]` sai do build como **hex de 8 dígitos**. ➡️ É a **9ª face**
da família *"a normalização muda o texto que o gate procura"* — e por isso a sonda de CSS lê o
**CORPO da regra**, nunca o literal da fonte. Confirmado pela 189-07: `bg-pub-page/[0.85]` →
**`#0a0a0ad9`** e `border-pub-danger/[0.45]` → **`#ef444473`**.

### ⚠️ 7. A família *"gate vazio por construção"* ganhou **cinco** faces novas nesta fase

1. `git diff --numstat HEAD -- <rel>` depois de commitar compara a **ÁRVORE** e sai **VAZIO SEMPRE**
   (**24 pontos**, §10/D-02);
2. `grep -c "aprovacao/LateralDoLightbox.jsx"` dá **0 SEMPRE**, porque a entrada é **template
   literal** (§10/D-06);
3. `grep -n "Requirements:.*MOD-06"` dá **ZERO**, porque o markdown é `**Requirements**:` (§10/D-03);
4. um `key_link` satisfeito **só por COMENTÁRIO** (§10/D-06);
5. um critério com **ANTECEDENTE FALSO** (§10/D-06).

⚠️⚠️ **A pergunta que pega todas as cinco:** *"o que precisaria estar errado no mundo para este gate
falhar?"* — **"Nada" é ACHADO**, não tranquilidade.

---

## §15 — ONDE ESTE PLANO PAROU

| tarefa | estado |
|---|---|
| **1** — re-medir baselines e território, pelos dois caminhos | **COMPLETA** e **commitada** |
| **2** — os 8 critérios em forma fixa, FID-01 e mutação consolidadas, os 8 manifestos e as bijeções | **COMPLETA** e **commitada** |
| **3** — o roteiro da conferência visual da conta 459 (**checkpoint humano BLOQUEANTE**) | **A §11 está escrita.** ⚠️ **PARADO AQUI, esperando o usuário** |

⚠️ **Esta fase NÃO deployou, NÃO deu push e NÃO tocou no VPS.** ⚠️ **Nenhuma chamada foi feita à API
do Mercado Livre.** ⚠️ **Esta fase não tem migration.**
