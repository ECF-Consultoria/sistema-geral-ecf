# Fase 193 — O FECHAMENTO MEDIDO

**Do aprovado ao ar: o portão fecha o ciclo.** Wave 5 de 5, escrita em 21/10/2026.

⚠️⚠️ **ESTE DOCUMENTO É MEDIÇÃO DATADA, NÃO GATE PERENE.** Cada número vale para o commit escrito ao
lado e **não deve ser "atualizado" por sessão futura** — quem quiser o número de hoje, **mede hoje**.
⚠️ **E um fechamento que afirma número velho é pior que um fechamento sem número:** tudo o que está
aqui foi **RE-MEDIDO** nesta wave, com o comando ao lado, e nada foi copiado das SUMMARYs nem do
`193-BASELINES.md`.

⚠️⚠️ **NENHUM EXECUTOR DESTA FASE VIU TELA RENDERIZADA, E NENHUM AFIRMA QUE VIU.** Não há navegador,
nenhuma empresa tem `ml_token` de verdade (a rota dá **404**), `pub_aprovacao_ofertas` está **vazia em
produção** e **esta fase não foi deployada**. ➡️ A prova desta fase é **teste com `Http::fake` +
`preventStrayRequests()`** (e `Queue::fake()` onde o caminho despacharia job), nunca tela.

---

## §1 — A PARTIDA, E O HEAD NAS DUAS PONTAS

```
PARTIDA:   0b9c51e0   (capturada na PRIMEIRA ação, antes de qualquer leitura — confere com o esperado)
```

⚠️ **A PARTIDA foi usada SÓ como referência de leitura.** Em nenhum momento ela serviu de extremo de
range para medir território — o território saiu do **ESCOPO DO ASSUNTO `(193-0N)` no `%s`**, em duas
passadas independentes (§3).

⚠️ **E ela é, por si, uma medição:** `git rev-list --count 0b9c51e0..HEAD` devolveu **0** no início
desta wave, o que significa que **nenhum commit alheio havia entrado** quando comecei. ⚠️ *"Não
commitou" ≠ "não está trabalhando"* — ver a ressalva da FACE 2 em §3.

### Os commits desta wave

| commit | tarefa | assunto |
|---|---|---|
| `2f888014` | 2 (parte) | `docs(193-05)`: o fechamento medido — território, PUB-03, a fronteira D-11 e os 10 critérios |
| `73bcac1c` | 1 | `test(193-05)`: os gates transversais do fechamento — e **nenhum deles chama git** |
| `602b4d62` | 1 (conserto) | `fix(193-05)`: a suíte COMPLETA achou **1 regressão MINHA** — o docblock com forma de declaração |
| `87f29f2e` | 2 | `docs(193-05)`: as dívidas da fase, cada uma com DONO |
| (o seguinte) | 2 (fecho) | `docs(193-05)`: as baselines re-medidas e o self-check |
| (o último) | — | `docs(193-05)`: a SUMMARY |

⚠️⚠️ **O DOCUMENTO FOI COMMITADO ANTES DO GATE, E A ORDEM É DELIBERADA — DIVERGÊNCIA DECLARADA DO
PLANO.** O `193-05-PLAN.md` põe o gate na tarefa 1 e o documento na tarefa 2, **mas o próprio
`<behavior>` da tarefa 1 exige asseverar sobre o `193-FECHAMENTO.md`**: um gate cujo **sujeito não
existe** não é mensurável, e commitar o gate primeiro deixaria um commit na história **com o gate
vermelho por ausência do alvo**. ➡️ Documento primeiro (`2f888014`), gate depois (`73bcac1c`).
⚠️ **Commit atômico por unidade coerente foi preservado**, e nada ficou sem commit em nenhum momento
(⚠️ **três agentes caíram na Fase 187**, um deixando 2/3 de um plano sem commit). ⚠️ O `193-01` fez a
mesma troca de ordem, com razão medida.

---

## §2 — AS BASELINES, RE-MEDIDAS NESTE HEAD, COM ISOLAMENTO PROVADO POR LINHA DE COMANDO

```
PARTIDA:   0b9c51e0
COMMIT:    87f29f2e   (o HEAD das duas pontas da rodada valida; os commits de docs vem depois)
BUILD:     exit REAL 0 · 98 s · "built in 1m 32s"      HEAD 73bcac1c nas DUAS pontas
MANIFEST:  500 entradas / 204 paginas   (controle Aprovacao.jsx PRESENTE, isca NaoExiste9z7.jsx AUSENTE)
JS:        6031 / 6030 pass / 1 fail / 0 skipped       (exit REAL 0)  HEAD 73bcac1c nas DUAS pontas
PHP:       2702 / 25119 / 0                            (exit REAL 0, 954 s)  HEAD 87f29f2e nas DUAS pontas
                                                       ^ MEDIDO em 87f29f2e; depois dele vieram DOIS
                                                         commits de conserto (761ab6a4, bd0574ba) que
                                                         so ACRESCENTAM assercao a metodos que ja
                                                         existiam -- a contagem de TESTES nao pode ter
                                                         mudado (nenhum metodo novo), e os gates que
                                                         LEEM este arquivo foram TODOS re-rodados no
                                                         HEAD final bd0574ba, verdes e IDENTICOS
                                                       ^ o RECORTE, pelo --suite php: a suite PHP
                                                         COMPLETA NAO E MEDIVEL nesta maquina
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

### §2.1 — ⚠️⚠️ A PRIMEIRA RODADA DO `--suite php` FOI DESCARTADA — E A CAUSA FUI **EU**

```
rodada 1:  HEAD 73bcac1c nas duas pontas · T0 19:16:26 · T1 19:35:33 UTC · 1122 s
           PHP: 2702 / 25112 / 1          EXIT_REAL = 1
           ⨯ 193-03 > o manifesto tem bijecao com os metodos e nenhum id orfao 193 03
rodada 2:  HEAD 87f29f2e nas duas pontas · T0 19:44:21 · T1 20:00:15 UTC ·  954 s
           PHP: 2702 / 25119 / 0          EXIT_REAL = 0      <- A MEDICAO QUE VALE
```

⚠️⚠️ **A falha NÃO era corrida nem commit alheio: era MINHA**, e está contada no §15, FURO 6. ➡️ **A
medição válida é a SEGUNDA**, depois do conserto (`602b4d62`), e ela tem `EXIT_REAL` **0** com o
HEAD conferido no **início e no fim**.

⚠️ **O `EXIT_REAL` foi gravado DENTRO do script de medição, de `$?` na linha seguinte ao harness** —
e isso não é zelo: a notificação do processo em background reportou *"failed with exit code 1"* na
rodada 1 e *"completed (exit code 0)"* na rodada 2, mas **CINCO waves** desta milestone já leram
`exit 0` de um `tail` com o harness saindo **1**. ⚠️ **Quem mede o exit é `$?`, não a notificação.**

### ⚠️ A aritmética do Δ — cada parcela atribuída pelo commit que **CRIOU** o arquivo

⚠️ **Por `git log --diff-filter=A` do arquivo, NUNCA por range** — o range engloba os meus próprios
commits, e a 189-07 caiu exatamente nisso.

| linha | referência anterior | medido agora | Δ | atribuição / veredicto |
|---|---|---|---|---|
| **BUILD** | `exit 0 · 86 s` (wave 04) | **exit 0 · 98 s** | +12 s | ⚠️ **INFORMATIVO** — tempo de build nesta máquina não é invariante (cache do Vite, carga). O que importa é o `exit 0` |
| **MANIFEST** | 500 / 204 | **500 / 204** | **0** | ✅ **IGUAL**, e o invariante **real** provado: `numstat` **VAZIO** em `resources/js/` nos commits do escopo |
| **JS testes** | 6031 (wave 04 e quick) | **6031** | **0** | ✅ **IGUAL.** Os meus commits tocam **ZERO** teste JS |
| **JS falhas** | 1 | **1** | **0** | **pré-existente, Fase 87**, identificada por **SEMÂNTICA**: *"Características secundárias nasce recolhido (é o grupo que mais infla)"*. ⚠️ **ATRIBUÍDA POR EXECUÇÃO ISOLADA:** `estrutura-grade-glide.test.js` sozinho dá **19 / 18 pass / 1 fail / 0 skipped**, `EXIT_REAL=1`. **NÃO corrigida** |
| **JS skipped** | 0 | **0** | 0 | ✅ e isso é consequência do método: o `build` rodou **antes e sozinho**, então os blocos que degradam para `skip` por `public/build/` ausente **não** degradaram |
| **PHP testes** (o RECORTE) | **2692** (quick `261021-fix`) | **2702** | **+10** | ⚠️ **MEUS, e a aritmética fecha NA VÍRGULA:** os **10** métodos de `FechamentoDaFase193Test.php`, **criado por `73bcac1c`** (`git log --diff-filter=A` → só o meu commit). ⚠️ **Nenhum teste alheio entrou** — a FACE 2 do território veio **vazia** |
| **PHP falhas** | 0 (quick) | **0** | **0** | ✅ e a rodada 1 (que deu **1**) foi **descartada com a causa identificada e consertada** — ver §15 FURO 6 |
| **PHP asserções** | 24.988 (quick) | **25.119** | +131 | ⚠️ **INFORMATIVO**, não comparável: o harness tem `REF_PHP_TESTS` e **não** tem referência de asserções. ⚠️ E o `+7` entre as duas rodadas (25.112 → 25.119) é o meu gate novo com as duas iscas |
| **PROTÓTIPO** | 1432 l. / `68624883ce35547e` | **idem** | 0 | ✅ |

⚠️⚠️ **NENHUM `REF_PHP_FALHAS` FOI CRIADO, em nenhuma das cinco waves nem aqui.** Um limiar de
falhas aceito seria **a porta dos fundos que o harness existe para fechar**.

⚠️ As falhas que a `193-BASELINES.md` §4 prevê (`CalcularFaixaTest`, `CompanyServiceTypeTest`,
`Phase39`) ficam **FORA** do recorte; as de `CockpitDaContaTest` (que **oscilam com a hora do dia**) e
as de `SincronizaPortalCompletoTest`/`PortalFotosTest` (corrida de `storage/framework/testing`)
**passaram** nas duas rodadas. ⚠️ **A hipótese do listener continua HIPÓTESE** — os dois experimentos
que a testariam saíram verdes.

### ⚠️ Uma contaminação MEDIDA e DECLARADA, em vez de omitida

Na **rodada 1** o script gravou `PHP_EXE_VIVOS_ANTES=1`: havia **um** `php.exe` vivo no instante
`T0`, embora eu tivesse medido **0** por PowerShell poucos segundos antes de lançar o processo.
⚠️ **Não identifiquei a origem** (provável resíduo de um `verificar-testes.sh`/`php -l` meu que
ainda não havia encerrado). ➡️ Na **rodada 2** — a que vale — o script gravou
**`PHP_EXE_VIVOS_ANTES=0`**. ⚠️ **Registro isto porque "isolamento provado" não pode ser slogan:**
a quick de ontem mediu **SETE `php.exe` de outra sessão** rodando **o mesmo recorte** nesta árvore,
e *"contagem de suíte com outra suíte ao lado não é medição"*.

### ⚠️ O isolamento, **PROVADO POR LINHA DE COMANDO** — não por nome de processo

```
php.exe vivos  =  0        (ZERO, imediatamente antes de cada rodada de PHP)
node.exe vivos = 16 POR NOME  ->  ZERO era `node --test`, `vite` ou `npm run`
```

Inspecionei a **linha de comando** de cada um dos 16 (`Get-CimInstance Win32_Process`): **Adobe
Creative Cloud** (1), **runtimes do Codex** (9, incluindo `@oai/cua-repl` e `server.mjs`), **servidores
MCP** (`digisac-ecf.mjs` ×2 e `mcp-remote` da Adman subindo por `npx-cli.js` ×4). ⚠️⚠️ **Filtro por
NOME acusa falso** — e isso não é zelo: a quick de ontem mediu **SETE `php.exe` de OUTRA SESSÃO
rodando PHPUnit nesta mesma árvore**, inclusive **o mesmo recorte**, com processos novos nascendo a
cada poucos segundos. Ela **esperou a liberação** e só então mediu.

➡️ **DECLARADO: não houve build nem outra suíte concorrente em nenhuma das medições desta wave.**

### ⚠️⚠️ A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL NESTA MÁQUINA — e eu meço pelo RECORTE

`artisan test` sem filtro **aborta com Fatal de memória** em
`vendor/.../Illuminate/Http/Testing/FileFactory.php:87`, a ~6% de 10.653 testes, e
⚠️ **`-d memory_limit=-1` NÃO resolve** (o estouro é em processo **FILHO**). ➡️ O número `PHP:` acima é
o **RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e **eu digo
isso** em vez de deixar o leitor supor que a suíte inteira passou.

### ⚠️ O MANIFEST: o invariante é *"a 193 não mexe no manifest"*, não um número

```
git diff --numstat c4529cca..HEAD -- resources/js/   ->  SÓ DOIS, e os dois ALHEIOS:
   342  0  resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx
    53  0  resources/js/Pages/Mlb/Publicador/Agente.jsx
git log --diff-filter=A -- <cada um>                 ->  56ade5f0  feat(quick-261020-s95): AG-07 do agente
```

⚠️ **ATRIBUÍDO PELO COMMIT QUE CRIOU O ARQUIVO (`--diff-filter=A`), NUNCA POR RANGE** — o range engloba
os commits da própria fase, e a 189-07 caiu exatamente nisso. **Nenhum dos 13 commits do escopo
`(193-0N)` toca `resources/js/`**, e o `numstat` deles em `package.json`, `package-lock.json`,
`vite.config.js`, `tailwind.config.js`, `composer.json` e `composer.lock` é **VAZIO**.

⚠️ **NÃO SUBI NÚMERO EM LUGAR NENHUM**, e não editei a `193-BASELINES.md` (artefato do `193-01`).
O que **rodou** é o controle que importa: `Aprovacao.jsx` **PRESENTE** no manifest e a isca
`resources/js/Pages/Mlb/NaoExiste9z7.jsx` **AUSENTE** — *"página fora do manifest SOME"* é o invariante
real, e ele **não depende** da contagem total.

### ⚠️ O `+1` que eu consegui isolar, e o `+1` que NÃO consegui — declarado

| parcela | atribuição |
|---|---|
| **+1 entrada / +1 página** | `resources/js/Pages/Mlb/Publicador/Agente.jsx`, criado por `56ade5f0`. ⚠️ **Medido no JSON:** é a **única** entrada do manifest que casa `/gente/` |
| **+1 entrada não-página** (295 → 296) | ⚠️⚠️ **NÃO ISOLADA, e a razão é medida:** `public/build/manifest.json` **não é rastreado pelo git** (`git ls-files --error-unmatch` → *"did not match any file"*), logo obter o manifest antigo exigiria **rebuild em árvore anterior**, que exige `git checkout` — **proibido** nesta árvore. ⚠️ **E não é a 193:** o `numstat` dos 13 commits do escopo em `resources/js/` é **VAZIO**. ⚠️ **E não é o `ConteudoDoAgente`:** ele é **import estático** (`Agente.jsx:5`), e componente aninhado é **FUNDIDO** no chunk da página — medido: ele **não** aparece no manifest |

---

## §3 — O TERRITÓRIO: `PI(193-05)` POR ESCOPO DO ASSUNTO, DUAS PASSADAS, NUNCA PELO AUTOR

### As duas passadas, por mecanismos DIFERENTES — e idênticas

```
PASSADA A:  git log --grep='(193-0N)' --format='%h|%s'  |  grep -F '(193-0N)'      ->  13 commits
PASSADA B:  git log --format='%h|%s' --all | awk '$2 ~ /\(193-(01|02|03|04|05)\)/' ->  13 commits
diff A B                                                                           ->  (IDÊNTICOS)
```

⚠️ **A passada A usa `--grep` SEM JANELA**, de propósito: foi exatamente a **janela deslizante**
(`git log -80`) que invalidou o gate `188-07 casca-do-painel` ontem — o commit da fase andou para a
posição **81** e o gate reprovou **por andar o repositório**. ⚠️ A **sobre-captura** do `--grep` (ele
lê a mensagem inteira) é **inofensiva aqui de propósito**: o filtro seguinte é por **`%s`**, então
commit que só cite o escopo no **corpo** é descartado. *A sobre-captura amplia o candidato, nunca o
veredicto.*

### A interseção com os proibidos — **VAZIA**, e a sonda **não está cega**

```
arquivos distintos tocados pelos 13 commits do escopo   ->  19
interseção com a lista de 24 proibidos                  ->  (VAZIA)
ISCA: injetando `routes/console.php` na lista            ->  ACUSOU: routes/console.php
```

➡️ **A isca prova que a interseção não é vazia por construção.** Os 19 arquivos são exatamente a
lista fechada da `193-REGUA.md` §8: os 5 de produção, os 4 de teste, os 4 manifestos `193-0N`, o
`contagens-186-04.txt` (remedido pelo `193-02`, autorizado), as 4 SUMMARYs e a `193-BASELINES.md`
(preenchida pelo `193-01`, autorizada).

### ⚠️⚠️ AS TRÊS FACES DO LIMITE — e cada uma medida

| face | medição |
|---|---|
| **sobre-captura** (`--grep` lê a mensagem inteira) | **neutralizada** pelo filtro por `%s`: as duas passadas devolveram o **mesmo** conjunto de 13 |
| ⚠️ **sub-captura** (commit alheio que mexe no meu território sem citar o escopo) | medida **À MÃO, por diferença**: dos 26 commits da janela da fase (`c4529cca..HEAD`), **13 são do escopo** e **13 são alheios** — `quick-261020-s95`/`-ozm`/`-oeg`, `quick-261021-fix`, `feat(193)`/`fix(193)` do orquestrador e `docs(193-fix)`. ⚠️ **Nenhum deles toca os 5 arquivos de produção desta fase**; os 4 alvos que eles movem estão atribuídos um a um na tabela abaixo |
| **o autor** | ⚠️ **vazio por construção**: o repositório tem **CINCO** identidades (`MB.ECF-100376` **3727**, `ECF Dev` **2008**, `Dev ECF` **57**, `root` **5**, `ecfmaycon` **4**) e **os 13 commits do escopo são TODOS da primeira** — a mesma das waves alheias. ⚠️ **NUNCA usei `%an` como discriminador** |

⚠️⚠️ **A RESSALVA QUE A FACE 2 NÃO COBRE, DECLARADA:** o território por **commit** não vê sessão que
está trabalhando **sem commitar**. Na quick de ontem a FACE 2 veio **VAZIA** — o vizinho não havia
commitado nada — **e ele estava rodando campanha de PHPUnit nesta árvore**. ➡️ *"Não commitou" ≠ "não
está trabalhando"*, e é por isso que o isolamento é provado por **linha de comando** (§2) e não por
silêncio do `git log`.

### Os 32 alvos intocáveis — `test -e` em CADA UM, e o `numstat` ao lado

⚠️ **`test -e` ANTES de acreditar no vazio:** `git diff -- <caminho que não existe>` sai **VAZIO**, e
vazio é **indistinguível de "intocado"**. São **DEZOITO** fantasmas nesta milestone; o fechamento da
189 pegou **6 de 32 (19%)**, **todos de NOMES** — *"um NOME não é um CAMINHO"*.

```
32 alvos  ->  32 OK no disco  ->  ZERO fantasma
ISCA: app/Services/Mlb/MlPublicacaoService.php                    -> FANTASMA (correto)
ISCA: app/Services/Publicador/Aprovacao/Fila/ResumoRapidoService.php -> FANTASMA (correto)
ISCA: new_layout_publicador/Aprovacao.dc.html                     -> FANTASMA (correto)
numstat c4529cca..HEAD nos 32  ->  28 VAZIOS, 4 MEXIDOS — e os 4 atribuídos:
```

| alvo movido | Δ | atribuição (`git log … -- <arquivo>`, **nunca por range**) |
|---|---|---|
| `tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php` | **+10 / −0** | ⚠️ **ALHEIO** — `56ade5f0` (`quick-261020-s95`, AG-07 do agente, **outra sessão**) |
| `tests/gates/contagens-186-09.txt` | **+2 / −2** | ⚠️ **ALHEIO** — `56ade5f0`, com a razão **escrita em linha** no próprio manifesto (*"Remedido, nao ajustado"*) |
| `.planning/ROADMAP.md` | **+4 / −0** | ⚠️ **ORQUESTRADOR** (GATE-07) — `05c26309` (decisão 23), `b222df47` (decisão 24), `ddbfa230` (decisões 25 e 26) |
| `.planning/STATE.md` | **+3 / −1** | ⚠️ **ORQUESTRADOR** (GATE-07) — `e341d3ef`, `0b9c51e0`; e `56ade5f0`, **alheio** |

⚠️⚠️ **E A LEITURA CERTA DO `contagens-186-09.txt`:** o invariante **não é** *"contagem idêntica"*, e
sim ***"contagem não mudada POR MIM"***. Ele está em **`259 / 53 / 21 / 31`**, remedido por commit
**alheio** — **não é regressão desta fase**, e **não é motivo de parada**.

ALVO-INTOCADO: app/Services/Mlb/Publicacao/MlPublicacaoService.php
ALVO-INTOCADO: app/Services/Publicador/PublicacaoService.php
ALVO-INTOCADO: app/Services/Publicador/Fila/FilaPublicacaoService.php
ALVO-INTOCADO: app/Services/Publicador/Fila/AgendadorDaFila.php
ALVO-INTOCADO: app/Services/Publicador/Fila/ResumoRapidoService.php
ALVO-INTOCADO: app/Jobs/Publicador/PublicarRascunhoJob.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AprovacaoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/VocabularioDoPortao.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/HistoricoDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/AjustesDaOfertaService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/RevisaoDeImagemService.php
ALVO-INTOCADO: app/Services/Publicador/Aprovacao/ComposicaoFinanceiraService.php
ALVO-INTOCADO: app/Services/Publicador/Agente/PreparadorDaAprovacao.php
ALVO-INTOCADO: app/Services/Publicador/Alavancas/Acoes/AlterarNoConvite.php
ALVO-INTOCADO: resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx
ALVO-INTOCADO: resources/js/Layouts/AppLayout.jsx
ALVO-INTOCADO: resources/js/Components/Mlb/Publicador/header/PublicadorHeader.jsx
ALVO-INTOCADO: tests/js/estrutura-meus-anuncios.test.js
ALVO-INTOCADO: tests/js/publicador-header-zindex-ordem.test.js
ALVO-INTOCADO: tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php
ALVO-INTOCADO: tests/gates/contagens-186-09.txt
ALVO-INTOCADO: tests/gates/contagens-188-05.txt
ALVO-INTOCADO: tailwind.config.js
ALVO-INTOCADO: package.json
ALVO-INTOCADO: composer.json
ALVO-INTOCADO: vite.config.js
ALVO-INTOCADO: .planning/ROADMAP.md
ALVO-INTOCADO: .planning/STATE.md
ALVO-INTOCADO: .planning/REQUIREMENTS-v27.md

---

## §4 — ⚠️⚠️ PUB-03: O `MlPublicacaoService` COM `numstat` VAZIO — E O `PublicacaoService` TAMBÉM

```
test -e app/Services/Mlb/Publicacao/MlPublicacaoService.php   ->  OK, 175 linhas
test -e app/Services/Publicador/PublicacaoService.php         ->  OK, 777 linhas
test -e app/Services/Mlb/MlPublicacaoService.php              ->  FANTASMA (a isca, e é um caminho
                                                                  que JÁ apareceu em briefing)

git diff --numstat c4529cca..HEAD -- app/Services/Mlb/Publicacao/MlPublicacaoService.php  ->  VAZIO
git diff --numstat c4529cca..HEAD -- app/Services/Publicador/PublicacaoService.php        ->  VAZIO
ISCA do numstat (um arquivo que eu SEI que mudou):
git diff --numstat c4529cca..HEAD -- …/Aprovacao/AndamentoDaOfertaService.php             ->  47  3
```

⚠️ **A isca é o que separa *"vazio porque nada mudou"* de *"vazio porque a sonda está morta"*.** E a
janela usada é a da **FASE INTEIRA** (`c4529cca..HEAD`, **26** commits), não a desta wave: ela é
**estritamente mais forte**, porque cobre também os 13 commits alheios — ninguém tocou os dois
arquivos, nem a fase nem os vizinhos.

⚠️⚠️ **E A DIVERGÊNCIA MEDIDA CONTRA O BRIEFING, que torna PUB-03 mais fácil e não mais difícil:**
`MlPublicacaoService::publicar()` recebe **`MlAnuncioRascunho`** — o rascunho do **assistente antigo**
(`/mlb/anuncios/wizard/{company}`). Ele **não está no caminho do Publicador**. ➡️ O que PUB-02 reusa é
`FilaPublicacaoService` → `AgendadorDaFila` → `PublicacaoService::iniciar()` → `PublicarRascunhoJob`
(fila **`high`**) → `PublicacaoService::executarFatia()`, e **o único `POST /items` do sistema está em
`app/Services/Publicador/PublicacaoService.php:442`** — ⚠️ **não** em `Mlb/Publicacao/`.

⚠️ **Este gate NÃO é sobre zero casos:** o alvo existe (175 linhas) e o controle positivo está
asseverado (`'POST', '/items'` continua no `PublicacaoService`). E a versão **COMPORTAMENTAL** do diff
vazio também: **nenhum dos 5 arquivos desta fase menciona `MlPublicacaoService` em código** (lido sem
comentário: **0**; no texto **CRU**: **1**, na prosa que explica justamente que ele não está no
caminho — a **isca do leitor**).

⚠️ **A trava de conta continua valendo, e é a MESMA de PUB-06:** `ContasLiberadas::exigir($ancora)`
dentro de `PublicacaoService::iniciar()`, **fail-closed** (lista vazia = **ninguém**). ⚠️ **Nenhum
feature-flag novo foi criado** — um segundo interruptor faria **duas verdades** sobre quem pode
publicar.

---

## §5 — ⚠️⚠️ A FRONTEIRA D-11 / D-22: INTACTA, NÃO ESTENDIDA, E COM GATE PRÓPRIO DO OUTRO LADO

O **D-11** diz que a **lista de Publicações nunca escreve** no Mercado Livre. Esta fase **leva oferta
ao ar** — logo a fronteira tem de ficar **explícita nos dois sentidos**. ⚠️ **Afrouxar a lista e
estender o gate são erros OPOSTOS**, e os dois quebram a fronteira declarada.

### (a) INTACTO — por `numstat` **e** por igualdade de CONJUNTO

```
numstat c4529cca..HEAD -- tests/js/estrutura-meus-anuncios.test.js        ->  VAZIO
numstat c4529cca..HEAD -- tests/js/publicador-header-zindex-ordem.test.js ->  VAZIO
node --test (ISOLADO, exit lido de $? SEM pipe na frente):
   estrutura-meus-anuncios   EXIT_REAL=0   # tests 30  # pass 30  # fail 0  # skipped 0
   publicador-header-zindex  EXIT_REAL=0   # tests  7  # pass  7  # fail 0  # skipped 0
sha256: 914cb2ded90e59bc (D-11, 709 l.)  ·  3e4536c0df9966a8 (z-index, 189 l.)
```

⚠️ **Igualdade de CONJUNTO, nunca piso:** o gate congela os **24 nomes declarados** do arquivo do D-11
e os **7** do z-index, mais as contagens de `assert.` (**97** e **15**). ⚠️ **Piso não serviria:** com
24 nomes, perder **um** deixaria 23 e qualquer piso razoável passaria — foi a sobrevivente **M5** do
fechamento da 187.

⚠️ **24 declarações rodam 30 testes**, e isso é medição: as seis primeiras estão num laço sobre **dois**
caminhos (`${caminho}`). Congelar a contagem de **declaração** mede o mesmo invariante e é durável;
congelar a de execução exigiria rodar `node` de dentro do PHPUnit.

### O D-11 e o D-22 por **SEMÂNTICA**, e o HOMÔNIMO que uma sonda ingênua engoliria

| família | nomes medidos |
|---|---|
| **D-11** (3) | *"D-11: nenhum router.put/delete/patch — a tela nunca escreve no ML"* · *"D-11: o único router.post existente aponta para meus.atualizar"* · *"180: D-11 endurecido — nenhum verbo de escrita entra por outra porta"* |
| **D-22** (1) | *"D-22: a nota aparece como \"de 86\", nunca convertida para 100"* |

⚠️⚠️ **O HOMÔNIMO, MEDIDO:** o mesmo arquivo tem *"abas: ModoAnuncioTabs não aponta mais para
mlb.anuncios.wizard **(D22)** e desabilita sem Company (D23)"* — que **não** é o D-22 da nota. Uma
sonda `/D-?22/` casaria os **dois** (há **7** ocorrências de `D-?22` no arquivo, em 5 contextos
diferentes). ➡️ O D-22 é identificado pelo **prefixo do nome** (`D-22:`), e há asserção provando que o
teste de **abas NÃO entra** no conjunto. ⚠️ **O plano citava `≈ :250, :256, :285`** — três linhas; a
família real tem **quatro** nomes relevantes (os três do D-11 + o D-22), e ancorar por **linha** é
exatamente o que um deslocamento de outro dev quebra.

### (b) NÃO ESTENDIDO — e a sonda **acusa** um sintético

```
marcas da fase procuradas no arquivo do D-11:
   PublicacaoDoPortaoService · PublicadorPortaoPublicacao · portao-publicacao
   tentarDeNovo · aoAprovar · 193
   ->  ESTENDIDO = []  (nenhuma)
ISCA (+): texto sintético `assert.ok(fonte.includes('PublicacaoDoPortaoService'))`
   ->  ACUSOU: ['PublicacaoDoPortaoService']
```

⚠️ Sem a isca, `ESTENDIDO=[]` seria **indistinguível de sonda que não lê nada**.

### (c) O GATE DE ESCRITA **PRÓPRIO** DA ÁREA NOVA — a outra metade do critério 7

```
nos 5 arquivos de produção da fase, lidos SEM COMENTÁRIO (token_get_all):
   Http::  curl_  Guzzle  'POST', '/items'  file_get_contents('http  /items
   ->  INFRATORES = []            (2.993 linhas de código lidas)
   ->  isca do leitor no texto CRU = 4 ocorrências   (a prosa cita as formas de propósito)
controle positivo: `'POST', '/items'` continua ≥1 em app/Services/Publicador/PublicacaoService.php
fila:  onQueue('high') ≥1 em PublicarRascunhoJob  ·  onQueue('default') + onQueue('creative') = 0
```

⚠️⚠️ **E A SONDA É A ESTREITA, POR MEDIÇÃO — NÃO A LARGA.** O briefing afirmou que *"não existe NENHUM
`PUT` no Publicador"*. ⚠️ **FALSO: existem TRÊS** (`AlterarCampanha:211`, `AlterarCupom:146`,
`AlterarNoConvite:155`), e o terceiro usa **`PUT /seller-promotions/items/`**, que **CONTÉM**
`/items/`. ➡️ Sonda por **substring** `/items` **acusa trabalho certo**. O invariante verdadeiro — e o
que sustenta a **decisão 22** — é ***"nenhum `PUT` cujo caminho COMEÇA em `/items`"***, e ele é
**VERDADEIRO**:

```
grep -rnE "'PUT',\s*'/items" app/Services/Publicador/   ->  ZERO  (invariante SUSTENTADO)
grep -rnE "'PUT'.*'/[^']*items" app/Services/Publicador/ ->  AlterarNoConvite.php:155
                                                             ^^ a sonda LARGA acusaria trabalho LEGÍTIMO
```

⚠️ As duas pontas estão **asseveradas no gate**: a estreita dá zero no caso legítimo e pega o caso
proibido; a larga **acusa** o legítimo — e é por isso que a estreita é a certa.

---

## §6 — ⚠️⚠️ OS 10 CRITÉRIOS DO ROADMAP, UM POR UM

⚠️ **SÃO 10, CONTADOS POR MIM — e a contagem foi ISCADA contra as vizinhas**, porque um `awk` errado
traz a fase errada **em silêncio**:

```
awk '/^### Phase 193:/,/^### Phase 194:/' .planning/ROADMAP.md | grep -cE "^  [0-9]+\. "   ->  10
ISCA (as vizinhas):  186 -> 17   187 -> 15   188 -> 20   189 -> 8   192 -> 12   194 -> 9
```

➡️ **As três primeiras batem exatamente com o que aquelas fases declararam** (`CRITERIOS = 20` no
`FechamentoDaFase188Test`, 17 na 186, 15 na 187), logo o recorte está certo. ⚠️ **E a isca de
SUB-captura também rodou:** varri o bloco da 193 por `^ *[0-9]+\. ` (**qualquer** indentação) e
achei **as mesmas 10** — não há critério escondido com indentação diferente.

⚠️⚠️ **O critério **nunca é reescrito** para caber.** Se algo não passa, ele recebe `RESSALVA` ou
`REPROVA` e o fechamento **devolve** — não conserta por conta própria. ⚠️ **E "OK com ressalva" não
existe:** é exatamente o que a forma fixa existe para impedir.

CRIT-01: OK — nada vai ao ML sem passar pelo portão: `aprovado_por`/`aprovado_em`/`versao_aprovada` gravados e no histórico, com o gancho DENTRO da mesma transação · prova: gate `193-02 aprovar-enfileira-atomico` (3 testes / 36 asserções, `EXIT_REAL=0`) + `AndamentoDaOfertaService:333`
CRIT-02: OK — a publicação REUSA a fila existente e a granularidade oferta × produto está resolvida sem duplicar `validacao_id`/`plano_hash`/`revisao`/`ciente` · prova: gate `193-02 enfileirar-anexa-ou-agenda` (3/71) + gate alheio `186-04 oferta-do-portao` (3/67, IDÊNTICO) + `193-03` (as irmãs ligadas ao MESMO item, item `193-G`)
CRIT-03: OK — `MlPublicacaoService` com `numstat` VAZIO na janela da fase inteira, com `test -e` antes, e a trava de conta continua valendo · prova: §4 deste documento + gate `193-05 territorio-e-d11` (versão comportamental) + `ContasLiberadas::exigir()` em `PublicacaoService::iniciar()` (gate `193-03 pub06-sem-regressao`, 2/22)
CRIT-04: OK — `publicando` bloqueia edição (5 rotas recusadas), `publicado` só com MLB confirmado (trava `AND-04-SEM-MLB-CONFIRMADO` no CÓDIGO, no-op byte a byte), `falha` preserva os 4 campos da revisão, e o retry vai direto a `publicando` quando a fila aceita · prova: gates `193-03 bloqueios-e-terminal` (3/31), `193-03 reconciliar-publicando-e-publicado` (3/38), `193-03 reconciliar-falha-e-clausula` (3/50) e `193-04 retry-republica` (3/48) · ⚠️ refinado pelas decisões 21/23/25/26 — ver §6.1
CRIT-05: OK — o erro exibido é o erro REAL do Mercado Livre, e a cláusula *"Nenhuma oferta foi criada; a revisão foi preservada."* entra SÓ quando nada do `listing_type_id` desta oferta foi criado · prova: gate `193-03 reconciliar-falha-e-clausula` (3/50), com literal CONGELADO e o caso PARCIAL como isca invertida
CRIT-06: OK — trava de conta, registro `SENT` ANTES do POST, `5xx`/rede como `UNKNOWN`, sem reenvio por conta própria e RN-93 pelo SKU — **por NÃO-REGRESSÃO**, e o reconciliador JAMAIS toca item `PENDING`/`SENT`/`UNKNOWN` · prova: gates `193-03 pub06-sem-regressao` (2/22) e `193-03 reconciliar-nao-toca-em-voo` (3/39), com a fixture TENDO um item `UNKNOWN` (isca invertida)
CRIT-07: OK — o gate D-11 está intacto (`numstat` vazio + igualdade de CONJUNTO dos 24+7 nomes e das 97+15 asserções, isolados 30/30/0 e 7/7/0), NÃO foi estendido à área nova (com isca que acusa um sintético), e a área nova tem gate de escrita PRÓPRIO · prova: §5 deste documento + gate `193-05 territorio-e-d11` (3 métodos)
CRIT-08: OK — a decisão está registrada (ROADMAP + `193-REGUA.md` §0 + docblock do serviço) e o código faz o que ela diz: as 9 formas de segundo passo AUSENTES nos 5 arquivos lidos sem comentário, e `->enfileirar(` aparece EXATAMENTE 1 vez na única porta · prova: gates `193-02 ausencia-de-segundo-passo-193` (1/23) e `193-05 nada-sem-destino` (a UNIÃO dos 5 arquivos, que nenhuma wave havia varrido junto)
CRIT-09: OK — nenhuma rota, botão ou coluna sem destino: conjunto de **16** rotas `aprovacao*` IDÊNTICO pelo ROUTER, zero migration, zero `.jsx`, zero coluna nova · prova: gates `193-04 nenhuma-rota-nova` (2/26) e `193-05 nada-sem-destino` · ⚠️ a `193-COBERTURA.md` diz *"as 6+5 da 188"* (= 11) e o ROUTER mede **16** — ver §9
CRIT-10: RESSALVA — a validação de ponta a ponta numa oferta real, em produção, na conta 459, É IMPOSSÍVEL NESTA MÁQUINA e nesta wave: sem navegador, sem empresa com `ml_token` (404), com `pub_aprovacao_ofertas` VAZIA em produção e **sem deploy autorizado** · dono: **usuário** · prova: o roteiro numerado da §CHECKPOINT, que declara a dependência em vez de prometer o que não pode entregar

PLACAR-193: OK=9 RESSALVA=1 REPROVA=0

### §6.1 — ⚠️ O que as decisões 21/23/25/26 REFINARAM no critério 4, e por que isso é OK e não RESSALVA

O critério 4 foi escrito em **20/10**, e diz *"'Tentar de novo' muda direto para `publishing`"*. As
decisões **21**, **23**, **25** e **26** chegaram **depois** e refinaram o caminho: quando a **fila
recusa**, o retry **não move** — devolve **422**, grava o **motivo real** no histórico (fora da
transação que volta atrás) e deixa a oferta onde está.

⚠️ **Isso NÃO é um segundo passo** e **não é critério não entregue:**
- a saída *"mover mesmo com a fila recusando"* foi **recusada no planejamento** e pelas waves: deixaria
  a oferta em `publicando` **ligada a item terminado**, e o reconciliador a fecharia como `falha` no
  minuto seguinte — **flapping com a edição bloqueada**, *um estado que mente*;
- o caminho normal (fila aceita) **vai direto a `publicando`**, provado pelo gate `193-04
  retry-republica`;
- a **decisão 25** fechou o único beco que isso tinha criado (item `193-I`): irmã em `falha` **conta**
  como *"já passou pelo portão"*.

➡️ **Veredicto OK, com o refinamento escrito** — e não `OK com ressalva`, que a forma fixa proíbe:
não há entrega faltando nem dono pendente, porque a autoridade aqui são as decisões do usuário, e elas
estão registradas no `ROADMAP.md`.

---

## §7 — ⚠️ AS SETE DECISÕES DO USUÁRIO (20 a 26), E ONDE CADA UMA VIVE NO CÓDIGO

DECISAO-20: a revisão detalhada saiu do escopo — Fases 190/191 CANCELADAS; a ordem é 189 → 193 → 194 → 192 · onde: `.planning/ROADMAP.md` (commit `0607f5a9`); nenhum plano da 193 cita as duas como fornecedoras de nada
DECISAO-21: a oferta que a fila RECUSA fica `aprovado`, com o motivo real no histórico, e o reconciliador repete a cada minuto — o 9º bloqueio foi OFERECIDO e RECUSADO · onde: `PublicacaoDoPortaoService::enfileirar()` (o ramo da recusa) e `reconciliar()` (a 2ª chance); commit `838d1d57`
DECISAO-22: editar foto de anúncio JÁ NO AR fica DIFERIDA para fase própria · onde: nada no código, de propósito — provado por `PubAprovacaoOferta::TRANSICOES[PUBLICADO] === []` (terminal por ESTRUTURA) e por ZERO `PUT` cujo caminho COMEÇA em `/items` (§5)
DECISAO-23: aprovar UMA modalidade NÃO enfileira o produto — sem todos os alvos ATIVOS aprovados, a oferta fica `aprovado` sem `fila_item_id`, com o motivo no histórico · onde: a guarda de `enfileirar()` em `PublicacaoDoPortaoService`, ancorada em `pub_rascunho_alvos.ativo`; gate `193-02 guarda-modalidade-irma` (2/25); commit `05c26309`
DECISAO-24: o clique vale como ciência dos avisos do ML, E os avisos vão ao HISTÓRICO · onde: `PublicacaoDoPortaoService`, lendo o **próprio item da fila** (`ciente = $ciente && $avisos_ml`, logo `ciente === true` significa *"houve aviso e o clique o aceitou"*) — o portão **não** lê a `ConferenciaService` e o painel **não** mudou; gate `193-03 reconciliar-falha-e-clausula`; commit `b222df47`
DECISAO-25: irmã em `falha` CONTA como *"já passou pelo portão"* — fecha o beco do retry (item `193-I`) · onde: `PublicacaoDoPortaoService::JA_PASSARAM_PELO_PORTAO` (4 estados, por igualdade de conjunto + `assertCount(4)`); gate `261021-01 irma-em-falha-conta` (3/61); commit `d3f4bb81`
DECISAO-26: o motivo da RECUSA do retry fica gravado FORA da transação que volta atrás · onde: a action `tentarDeNovo` do `MlbPublicadorAprovacaoController` (gravação no `catch`, depois do rollback, com a transação **não dividida e não removida**); gate `261021-01 motivo-da-recusa-registrado` (2/41); commits `d3f4bb81` e `4aee7add`

⚠️ **As alternativas RECUSADAS, para ninguém reabrir:** publicar em dois atos · publicar com janela
para desfazer · botão separado de publicar · aprovação cor por cor · *"desabilitado com explicação"* ·
o **9º bloqueio** no portão · `aprovado → publicando → falha` imediato.

---

## §8 — ⚠️ OS ITENS DEVOLVIDOS PELAS WAVES — TODOS COM DESTINO, NENHUM REABERTO

DEVOLVIDO-193-E: quais avisos do ML contam como ciência · destino: **resolvido pela DECISÃO 24** — a fonte é o próprio item da fila, e os avisos vão ao histórico
DEVOLVIDO-193-G: as duas irmãs aprovadas do mesmo produto ficariam ligadas a itens diferentes? · destino: **resolvido pelo `193-03`** — elas ficam ligadas ao MESMO item, provado por gate (`reconciliar-publicando-e-publicado`, 3/38)
DEVOLVIDO-193-H: a de-duplicação do histórico é contra toda a linha do tempo ou contra a última linha? · destino: **decidido pelo usuário/orquestrador** — contra a **ÚLTIMA** linha; efeito aceito e declarado: oferta que alterna entre dois motivos escreve duas linhas por ciclo
DEVOLVIDO-193-I: o beco do retry no produto com DUAS modalidades em `falha` · destino: **resolvido pela DECISÃO 25** — `FALHA` entra em `JA_PASSARAM_PELO_PORTAO`, e a mutação provou que isso **não** afrouxa a decisão 23
DEVOLVIDO-193-04-B: o motivo da recusa do retry desaparecia com o rollback · destino: **resolvido pela DECISÃO 26** — gravação fora da transação, no `catch`, com a transação INTACTA
DEVOLVIDO-PERGUNTA-193-B: editar a foto de um anúncio JÁ NO AR (a decisão 13 do ROADMAP) · destino: **resolvido pela DECISÃO 22** — DIFERIDA para fase própria
DEVOLVIDO-PERGUNTA-193-C: o toast *"Publicado e confirmado"* nunca dispara sozinho (a confirmação chega minutos depois e não há polling) · destino: **ASSEVERADA como AUSÊNCIA** — o critério 4 exige justamente que ele NÃO dispare sem MLB; gate `193-03 bloqueios-e-terminal` (`mover` para `publicado` sem MLB é no-op completo **e sem toast**). A decisão de a fila atualizar sozinha fica para a fase de tela

---

## §9 — ⚠️⚠️ AS TRÊS AFIRMAÇÕES DO MEU PRÓPRIO PLANO QUE FICARAM FALSAS — CORRIGIDAS

| # | o que o `193-05-PLAN.md` afirma | o que eu MEDI |
|---|---|---|
| **1** | `:201-202` — *"`pub_aprovacao_ofertas` está VAZIA e **nada em `app/` cria oferta** — dos 27 arquivos que tocam `PubAprovacaoOferta`, nenhum a cria"* | ⚠️⚠️ **FICOU FALSO:** `app/Services/Publicador/Agente/PreparadorDaAprovacao.php:148` chama **`PubAprovacaoOferta::create`** (quick `261020-oeg`, de **outra sessão**). ⚠️ **Nenhuma loja tem o agente ligado, então a tabela SEGUE VAZIA em produção** — mas ➡️ **o critério 10 passa a ser executável DEPOIS DO PILOTO DO AGENTE, não só depois da Fase 194**, o que **antecipa** a prova de ponta a ponta. A `193-REGUA.md` §7 já traz a correção e **SUPERSEDE o plano**, que é artefato do planejador e não foi editado |
| **2** | o invariante do manifest é **"498 / 203"** | ⚠️ **REANCORADO (`b222df47`):** o invariante é ***"a 193 não mexe no manifest"***, provado por `numstat` **VAZIO** em `resources/js/`. Hoje está em **500 / 204**, e o `+2 / +1` é **alheio**. ⚠️ **Não subi número**; rodei o controle que importa (`Aprovacao.jsx` presente, isca ausente) |
| **3** | o invariante do `contagens-186-09.txt` é **"contagem idêntica"** | ⚠️ **REANCORADO (`e341d3ef`):** é ***"contagem não mudada POR VOCÊ"***. Hoje está em **`259 / 53 / 21 / 31`**, remedido por commit **alheio** (`56ade5f0`) com a razão escrita **em linha** (*"Remedido, nao ajustado"*). **Não é regressão** |

⚠️ **E uma quarta, menor, que eu achei medindo:** a `193-COBERTURA.md` §1 (critério 9) escreve *"as
**6+5** da 188 mais nenhuma"* — o que soma **11**. ⚠️ **O ROUTER mede 16.** O número do planejamento
está **errado**; o do router é o que vale, e é por isso que o gate congela o conjunto **por NOME**,
nunca por contagem citada de artefato. ⚠️ **Não editei a `193-COBERTURA.md`** — artefato do
planejador. **Dono: usuário / orquestrador.**

---

## §10 — ⚠️⚠️ OS DOZE ERROS DE BRIEFING DO ORQUESTRADOR — A PROVA DE QUE *"A MEDIÇÃO GANHA"* FUNCIONA

⚠️ **Registrados porque são o argumento de que a régua funciona, e porque TODOS foram achados por
medição de executor** — nenhum por leitura atenta do briefing.

| # | o briefing dizia | a medição |
|---|---|---|
| 1 | *"reusar `MlPublicacaoService`"* | **ERRADO** — ele recebe `MlAnuncioRascunho`, do assistente **antigo** |
| 2 | *"quatro telas pretas"* | eram **CINCO**, e a quinta era **invisível** para a sonda que achou as quatro |
| 3 | *"a sonda de `font-extrabold` não acharia a classe"* | ela **ACHA** (135 ocorrências de outros donos) — erro na **direção perigosa** |
| 4 | *"o índice 13 da lateral"* | é **função da FIXTURA** |
| 5 | *"os ponteiros do view-model estão deslocados"* | os **DOZE estavam certos** |
| 6 | *"quatro gates"* numa família de **CINCO**; *"cinco leitores"* | são **5 arquivos / 6 pontos** |
| 7 | três **caminhos fantasma** | **18 na milestone**; o fechamento da 189 pegou **6 de 32 = 19%**, **todos de NOMES** |
| 8 | *"migration provável"* nesta fase | **NÃO TEM** — provado por asserção sobre o **schema vivo** (`193-01`, `schema-sem-migration` 3/50) |
| 9 | *"o autor são duas identidades"* | são **CINCO** — e o briefing errou **duas vezes** |
| 10 | *"a migration do agente é untracked"* | está **RASTREADA** |
| 11 | *"não existe NENHUM `PUT` no Publicador"* | existem **TRÊS**, e `AlterarNoConvite:155` usa `PUT /seller-promotions/items/`, que **CONTÉM** `/items/` — sonda por substring **acusa trabalho certo**. ⚠️ **Re-medido por mim nesta wave** (§5) |
| 12 | *"se o `188-05` reprovar, é por REQUISIÇÃO"* | **não era** — a fixture **nunca foi publicável** |

➡️ ⚠️ **A REGRA: onde o briefing contradisser uma medição, A MEDIÇÃO GANHA — e o executor DECLARA.**

---

## §11 — ⚠️ OS QUATRO ITENS COM DONO **USUÁRIO / ORQUESTRADOR** — REGISTRADOS, **NÃO** CONSERTADOS

PERGUNTA-GATE-MEDE-WIP: ABERTA — a família *"gate mede WIP, não território"*: **24 pontos em 7 arquivos** chamam `numstatDe(rel)` **sem o 2º argumento**, comparando a **ÁRVORE** em vez de um range; mais dois `git status --porcelain`. Reprovam **ANTES** do commit e ficam **VERDES depois** — não protegem nada e **acusam trabalho certo no meio de uma wave**. ⚠️ O conserto de raiz **JÁ EXISTE no código** (o helper **aceita** a base; `casca-188:549` já usa range `HEAD~1 HEAD`) — dono: **usuário / orquestrador**
PERGUNTA-FID01-189: ABERTA — os ponteiros do `189-FID01.md` §G apodreceram **TRÊS** vezes, e um **atribui MOD-06 à Fase 190, CANCELADA** pela decisão 20 — ⚠️ **não é artefato desta fase** — dono: **usuário / orquestrador**
PERGUNTA-ORFAO-GUARDA-MODALIDADE-IRMA: ABERTA — o id `guarda-modalidade-irma` (`contagens-193-02.txt`, 2/25) está **declarado no manifesto e fora de todo `<verify>`**: ⚠️ **ele não falha, ele simplesmente NUNCA RODA** por iniciativa do plano. A causa é medida: ele **nasceu no meio da wave 02**, quando a decisão 23 chegou, e o `193-02-PLAN.md` é artefato do **planejador** — nenhum executor o edita. ⚠️ **O id FUNCIONA** (a wave 02 e a quick colaram a saída dele) — dono: **usuário / orquestrador**
PERGUNTA-BECO-DO-RETRY: FECHADA — o beco do retry no produto com duas modalidades em `falha` foi fechado pela **decisão 25**, e a mutação provou que isso **não** afrouxa a decisão 23 — dono: **usuário (decidido em 21/10/2026)**

⚠️ **E o 17º gate de âncora frágil consertado na RAIZ nesta milestone foi do orquestrador, ontem:** o
`publicador-painel-casca-188.test.js` era **auto-invalidante por janela deslizante** (`git log -80`;
o commit da fase foi para a posição **81**), **e o vermelho escondia que as duas passadas de
território asseveravam `[] === []`: VAZIAS POR CONSTRUÇÃO.** Reancorado em `--grep` **sem janela** +
a asserção que faltava (`meus.length >= 1`). ⚠️ **Re-medido por mim neste HEAD: `casca-do-painel`
45 / 45 / 0, `EXIT_REAL=0`.** ➡️ **17 consertos na raiz, ZERO por elevação de número.**

---

## §12 — OS GATES ALHEIOS, RE-RODADOS NESTE HEAD **COM O MEU ARQUIVO DE TESTE NO DISCO**

⚠️ **A hora de rodá-los é com o arquivo novo presente** — é quando o risco aparece (o gate
`186-09 bijecao-e-homonimo` varre declarações de método em `tests/`).

| gate | medido agora | esperado | |
|---|---|---|---|
| `186-04 unica-porta` | **1 / 43**, `EXIT_REAL=0` | 43 | ✅ IDÊNTICO — ⚠️ **subir seria PARADA**: a camada B é `glob('app/Services/Publicador/Aprovacao/*.php')` **não recursivo**, e esta wave não cria arquivo ali |
| `186-04 oferta-do-portao` | **3 / 67**, `EXIT_REAL=0` | 67 | ✅ IDÊNTICO |
| `186-09 transversais` | **12 / 259**, `EXIT_REAL=0` | 259 | ✅ não mudado **por mim** (o 259 é de `56ade5f0`, alheio) |
| `186-09 sem-segundo-passo` | **1 / 53**, `EXIT_REAL=0` | 53 | ✅ |
| `186-09 andamento-um-lugar-so` | **1 / 21**, `EXIT_REAL=0` | 21 | ✅ não mudado **por mim** (o 21 é de `56ade5f0`) |
| `186-09 bijecao-e-homonimo` | **1 / 31**, `EXIT_REAL=0` | 31 | ✅ ⚠️ **e isto é prova de desenho:** a minha isca (−) põe o nome alheio por **VARIÁVEL**; como **literal**, o texto ganharia **forma de DECLARAÇÃO** e este gate reprovaria. Foi o furo 1 da wave 04, aplicado **preventivamente** aqui |
| `188-05 aprovar-e-enfileirar` | **3 / 85**, `EXIT_REAL=0` | 85 | ✅ *"zero escrita no Mercado Livre"* continua verde **depois** de a 193 ligar a publicação |
| `188-05 tentar-de-novo` | **2 / 32**, `EXIT_REAL=0` | 32 | ✅ ⚠️⚠️ **estava VERMELHO no fim da wave 04** (`2 failed (2 assertions)`) e a quick `261021-fix` o consertou **por método** (75 inserções / **ZERO** remoções). **Confirmado independentemente por mim** |
| `188-05 ausencia-de-segundo-passo` | **1 / 17**, `EXIT_REAL=0` | 17 | ✅ |
| `188-07 casca-do-painel` | **45 / 45 / 0**, `EXIT_REAL=0` | 45 | ✅ o conserto do orquestrador (`d1cd7713`) holds |
| `tests/js/estrutura-meus-anuncios.test.js` (INTOCÁVEL) | **30 / 30 / 0**, `EXIT_REAL=0` | 30 | ✅ |
| `tests/js/publicador-header-zindex-ordem.test.js` (INTOCÁVEL) | **7 / 7 / 0**, `EXIT_REAL=0` | 7 | ✅ |

⚠️⚠️ **TODO `EXIT_REAL` foi lido de `$?` NA LINHA SEGUINTE, nunca depois de um pipe.** **CINCO waves
caíram nisso**, e uma pegou o harness saindo **1** enquanto a notificação de background dizia *"exit
code 0"*. ⚠️ **E eu caí nisso uma vez nesta própria wave:** a primeira leitura do arquivo do D-11 veio
com `| grep` na frente e reportou `EXIT_REAL=0` — que era o **exit do `grep`**. Refiz sem pipe. **O
erro é tão barato de cometer que ele merece estar escrito aqui.**

### As cinco bijeções, RE-MEDIDAS **depois** do conserto da minha regressão (`602b4d62`)

| gate | medido | esperado | |
|---|---|---|---|
| `193-01 bijecao-manifesto-193-01` | **1 / 20** | 20 | ✅ |
| `193-02 bijecao-manifesto-193-02` | **1 / 32** | 32 | ✅ |
| `193-03 bijecao-manifesto-193-03` | **1 / 33** | 33 | ✅ ⚠️ **era este o VERMELHO da rodada 1** — ver §15 FURO 6 |
| `193-04 bijecao-manifesto-193-04` | **1 / 31** | 31 | ✅ |
| `186-09 bijecao-e-homonimo` (alheio) | **1 / 31** | 31 | ✅ |

### Os QUATRO ids DESTA wave

| id | medido | manifesto | |
|---|---|---|---|
| `193-05 territorio-e-d11` | **3 / 43**, `EXIT_REAL=0` | `tests=3 assertions=43` | ✅ |
| `193-05 nada-sem-destino` | **3 / 15**, `EXIT_REAL=0` | `tests=3 assertions=15` | ✅ |
| `193-05 bijecao-dos-cinco-manifestos` | **1 / 24**, `EXIT_REAL=0` | `tests=1 assertions=24` | ✅ ⚠️ **REMEDIDO 21 → 24**, com a razão **em linha** no manifesto: o conserto do FURO 6 **ACRESCENTOU prova** (um gate novo + duas iscas). **Não é elevação de régua** |
| `193-05 fechamento-de-forma-fixa` | **3 / 49**, `EXIT_REAL=0` | `tests=3 assertions=49` | ✅ |

⚠️⚠️ **E OS MEUS GATES REPROVARAM SEIS VEZES DURANTE A CONSTRUÇÃO — isso é a resposta medida à
pergunta *"o que precisaria estar errado no mundo para este gate falhar?"***:

| # | o gate reprovou porque | e isso prova |
|---|---|---|
| 1 | o manifesto `contagens-193-05.txt` **não existia** | o anti-fantasma do manifesto não é decorativo |
| 2 | o meu conjunto de **órfãos** tinha **5** e são **4** (o `193-01` declara o dele) | a lista congelada acusa a divergência **nomeando-a** |
| 3 | o varredor de homônimo lia o **texto cru** e acusou a isca legítima da wave 03 | *"gate mais largo que o invariante"*, pego pelo próprio gate |
| 4 | a sonda de *"a suíte PHP não é medível"* exigia **minúscula** e vizinhança imediata, e o documento escreve **maiúscula** com `**COMPLETA**` no meio | o lado **ESTREITO** da família *"a frase quebra no markdown e a sonda não"* |
| 5 | o gate novo do FURO 6 varria helpers de nome comum e acusou **14** declarações **minhas** | a mesma família, de novo, em cinco minutos |
| 6 | ⚠️⚠️ a **suíte completa** achou a regressão que **nenhum dos 4 ids pegaria** | *"rode a suíte completa"* **não é formalidade** |

### A isca do 1º argumento, medida **AO VIVO** (não citada de briefing)

```
sh tests/gates/verificar-testes.sh 05 territorio-e-d11
  ->  EXIT_REAL=2   ABORTOU: id 'territorio-e-d11' nao esta em …/contagens-186-05.txt
sh tests/gates/verificar-testes.sh 193-5 territorio-e-d11
  ->  EXIT_REAL=2   ABORTOU: manifesto nao existe: …/contagens-193-5.txt
```

⚠️⚠️ **E repare no que isso significa:** o número **NU** resolveu `contagens-186-05.txt`, que
**EXISTE** — ele só abortou porque o **id** não está lá. ➡️ **Se o nome do id coincidisse, o harness
rodaria o gate da fase ERRADA, em SILÊNCIO, e ficaria VERDE.** É compatibilidade **deliberada** (os
163 ids da Fase 186 são chamados pelo número nu, e aquela fase está em produção).

### `--autoteste`: **20 casos, 0 divergência**, `EXIT_REAL=0` (colado integralmente)

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

### A bijeção dos CINCO manifestos, medida

```
ids declarados nos 5 manifestos        ->  22      (2 + 5 + 6 + 5 + 4)
metodos `_193_0N` declarados na suite  ->  50      (4 + 10 + 15 + 11 + 10)
SEM_ID = 0   ORFAOS = 4 (CONGELADOS e DECLARADOS)   IDS_REPETIDOS = 0   HOMONIMOS = 0
nenhum id com mais de TRES metodos  ·  sufixo `_193_0N` em TODOS os 50
```

⚠️ **E o `tests=` de cada id bate com a quantidade de métodos do filtro**, asseverado por asserção
coletora — um id que declarasse `tests=3` com 2 métodos passaria pelo harness e **mentiria** sobre o
que mede.

---

## §13 — A CAMPANHA DE MUTAÇÃO CONSOLIDADA: **36 MUTAÇÕES, 2 SOBREVIVENTES, 2 CONSERTOS NA RAIZ**

| wave | mutações | sobreviventes | observação |
|---|---|---|---|
| `193-01` | **0** | — | ⚠️ **DECLARADO:** a wave é uma asserção sobre o **schema vivo** e dois manifestos; não há código de produção novo para mutar. **Não houve campanha, e isso é dito** em vez de omitido |
| `193-02` | **5** | ⚠️ **2** | as duas eram **furo REAL** — ver abaixo |
| `193-03` | **13** | **0** | `aoAprovar` vazio · `enfileirar` sempre agenda · `publicado` antes do MLB · MLB sem filtrar `listing_type_id` · `last()` em vez de `first()` · cláusula SEMPRE · cláusula NUNCA · `UNKNOWN` tratado como terminado · motivo genérico · guarda de tipo removida · `is_array($issues)` removida · de-dup desligada · decisão 24 desligada |
| `193-04` | **9** | **0** | retry move sem enfileirar · guarda *"só a partir de falha"* removida · `everyMinute→hourly` · `withoutOverlapping(10)` removido · `onOneServer()` removido · Command chamando `mover()` direto · portão agendado ANTES da fila · comando imprimindo sempre · a recusa não lança (o botão finge que tentou) |
| `261021-fix` (quick) | **9** | **0** | `FALHA` sai da constante · um 5º estado entra · gravação volta para dentro da transação · gravação removida · de-dup contra todo o histórico · conta não liberada · irmã volta a ficar ativa · conferência L3 sai · o recorte volta a ser a cauda do arquivo |
| **`193-05` (esta)** | **9** | ⚠️ **3** | ver a tabela abaixo — **duas eram furo REAL** e uma era **erro de medição meu** |
| **TOTAL** | **45** | **5** | ⚠️ **e as CINCO foram INVESTIGADAS, nunca presumidas equivalentes** |

### ⚠️ A CAMPANHA DESTA WAVE: o alvo é o **documento**, porque é ele que os gates protegem

⚠️ **Mutar o próprio arquivo de teste não provaria nada** (o teste **é** o gate). ➡️ O alvo é o
`193-FECHAMENTO.md`. ⚠️ **Commit ANTES** (`eb1dc77f` na rodada 1, `761ab6a4` na rodada 2);
`mutar-193-05.sh` com `sha256 72c27f96c9407572…` **impresso antes do veredicto e RECONFERIDO igual no
fim**; `mutar-193-05-rodada2.sh` com `sha256 3ed4dfd124ecf39f…`, idem; **validação seca das 8 âncoras
antes** (aplicar → restaurar **sem** rodar gate: todas **VIVAS**); ocorrência **travada em 1** por
string exata; aplicação por **divergência de `sha256`**; **qual linha mudou** impressa depois;
restauração **DA CÓPIA** em `trap EXIT`; `sha256` final contra `git show HEAD:` com `tr -d '\r'` nos
dois lados — **OK nas duas rodadas**. ⚠️⚠️ **`git checkout` NUNCA.**

| # | mutação no documento | gate | veredicto |
|---|---|---|---|
| **m1** | um **critério inteiro desaparece** (`CRIT-07` → `CRIT-X7`) | `fechamento-de-forma-fixa` | **MORREU** `1 failed, 2 passed` |
| **m2** | **veredicto inventado** (`CRIT-05: OK` → `QUASE`) | idem | **MORREU** |
| **m3** | a **RESSALVA perde o dono** (`· dono:` → `· prova-no-dono:`) | idem | ⚠️⚠️ **SOBREVIVEU** → furo REAL, **consertado na raiz** (`761ab6a4`); re-rodada: **MORREU** |
| **m4** | uma pergunta ganha **`dono: a definir`** | idem | **MORREU** |
| **m5** | um `ALVO-INTOCADO:` vira um **caminho FANTASMA** | `territorio-e-d11` | ⚠️ "sobreviveu" na rodada 1 **porque eu rodei o gate ERRADO**; contra o id certo: **MORREU** |
| **m6** | a **numeração do roteiro** quebra (passo `6` → `9`) | `fechamento-de-forma-fixa` | **MORREU** |
| **m7** | o documento passa a **AFIRMAR TER VISTO A TELA** (a forma exata está no script de mutação, **não aqui** — ver abaixo) | idem | **MORREU** |
| **m8** | um veredicto **válido** que faz o **placar não fechar** (`CRIT-01: OK` → `RESSALVA`) | idem | **MORREU** |
| **m9** | um `ALVO-INTOCADO:` é **REMOVIDO** (não trocado) | `territorio-e-d11` | ⚠️⚠️ **SOBREVIVEU** → furo REAL do **PISO**, **consertado na raiz** (`bd0574ba`); re-rodada: **MORREU** |

### ⚠️⚠️ AS TRÊS SOBREVIVENTES DESTA WAVE, INVESTIGADAS

| sobrevivente | o que revelou | conserto |
|---|---|---|
| **m3** | ⚠️ a sonda era `/dono:\s*\S/u` e casava `dono:` **no MEIO de outra palavra**. ➡️ Uma **RESSALVA SEM DONO** passaria com qualquer palavra terminada em `dono:` (`sem-dono:`, `prova-no-dono:`, `codono:`) — e *"ressalva sem dono é dívida que ninguém herda"* é **exatamente** o que o gate existe para impedir | **NA RAIZ:** constante `SONDA_DO_DONO` com **borda à ESQUERDA** `(?<![\p{L}\p{N}\-])`, **com o HÍFEN na classe de exclusão** (foi um hífen que a mutação usou), aplicada nos **dois** pontos que leem dono, mais **as duas iscas**. Manifesto **REMEDIDO** 49 → 51, razão **em linha** |
| **m5** | ⚠️ **não era furo: era erro de MEDIÇÃO meu.** O anti-fantasma mora no id `territorio-e-d11`, e eu rodei a mutação contra o `fechamento-de-forma-fixa`. ➡️ *"Sobrevivente é investigada"* valeu aqui para **absolver** a sonda | nenhum — re-rodada contra o id certo: **MORREU**. ⚠️ **Registrado como erro meu**, não como propriedade do gate |
| **m9** | ⚠️ o gate media a lista por **PISO** (`>= 20`), e com **32** alvos apagar um deixa **31**. ➡️ Família ***"piso `>= N` NÃO prova PRESENÇA"***, a mesma da sobrevivente **M5** do fechamento da 187. ⚠️ O risco é concreto: *"tirar alvo da lista"* é **o conserto barato** que o learnings manda evitar | **NA RAIZ, em duas camadas:** `ALVOS_INTOCADOS = 32` por **tamanho EXATO** (frágil por construção, **declarado**) **+** `ALVOS_CRITICOS` (**9** caminhos) por **presença explícita**, para que nem uma **troca** que preserve o total possa tirar um crítico. ⚠️ **Subconjunto, e não a lista inteira**, para não criar duas verdades livres para divergir |

⚠️ **E uma medição que eu não consegui explicar, declarada em vez de omitida:** o conserto da m9
acrescentou **uma** chamada de asserção ao método 1 (12 → 13), e **a contagem total do id ficou em 43
nas duas medições**. A soma por método fecha (**16 + 14 + 13 = 43**) e o manifesto bate com a medição.
➡️ **Eu NÃO ajustei nada**, e a prova do conserto é a **MUTAÇÃO** (m9 passou a MORRER), **não** a
contagem.

⚠️⚠️ **Em todas as 9 o `# tests` do mutado bate com o do pristino** (`1 failed, 2 passed` = **3** =
pristino, e `3 passed` = **3** nas sobreviventes), logo **cada arquivo CARREGOU** e cada morte é **da
asserção**, não de falha de **boot** — uma mutação desta milestone já morreu por boot e **não provava
nada**.

### ⚠️⚠️ E UM ACHADO DE QUARTA ORDEM: O GATE PEGOU A MINHA PRÓPRIA PROSA

Ao escrever a tabela acima, eu descrevi a mutação **m7** com **a forma exata que ela injeta** — e o
gate `fechamento-de-forma-fixa` **REPROVOU o próprio fechamento** (`1 failed, 2 passed`), acusando a
asserção da **afirmação proibida** — a de que nenhum executor pode dizer ter visto a tela.

⚠️ **E ele reprovou DUAS vezes:** na primeira eu reescrevi a descrição da mutação, e na segunda ele
pegou a minha **citação da mensagem de erro do próprio gate**. ➡️ *A forma proibida não pode aparecer
no documento nem como **relato de que ela foi pega***.

⚠️ **É a família *"gate que reprova pelo próprio conserto"*** — a prosa que **explica** a proibição
casa com a sonda que a **mede**. Mas aqui, diferente dos outros casos da milestone, ⚠️⚠️ **o GATE
ESTÁ CERTO E A PROSA É QUE SE ADAPTA:** o invariante é *"este documento não AFIRMA ter visto tela"*, e
uma sonda que abrisse exceção para *"a tabela de mutação"* viraria uma porta dos fundos de uma linha.
➡️ **A forma proibida vive no script de mutação** (`mutar-193-05.sh`, fora do repositório), **não**
aqui; a tabela a descreve por **paráfrase**.

⚠️ **E a distinção é a lição:** quando a prosa precisa citar o literal proibido **para explicar o
acerto** (como fazem os cinco arquivos desta fase com as nove formas de segundo passo), o conserto é
**ler sem comentário**. Quando a prosa **afirma** a coisa proibida, o conserto é **não afirmar**. ⚠️
*"Ler sem comentário" não serviria aqui*, porque markdown não tem comentário — e é por isso que o
gate deste documento é por **forma fixa de linha**, não por tokenizador.

### ⚠️⚠️ AS DUAS SOBREVIVENTES, INVESTIGADAS — e as duas eram furo REAL

| sobrevivente | o que ela revelou | conserto |
|---|---|---|
| **m1 da 02** — item terminado passa a contar como VIVO | ⚠️ a sonda provava **`produto_ativo`**, não **`status`**: nas fixtures dos dois laços o item terminado tem `produto_ativo` **NULO**, então quem o descartava era o `where('produto_ativo')` e o filtro de `status` era **invisível** para o teste | **NA RAIZ:** entrou o caso do estado **INCONSISTENTE** (item terminado que ainda **OCUPA** o produto), o único em que `status` discrimina. ⚠️ **O número subiu 67 → 71 porque há MAIS prova**, não porque a régua foi elevada — e a razão está **escrita em linha** no manifesto |
| **m3 da 02** — o gancho roda FORA da transação | ⚠️⚠️ `assertGreaterThan(0, $transactionLevel)` era **VAZIA POR CONSTRUÇÃO**: o `RefreshDatabase` abre transação própria em volta de **cada caso**, logo o nível já vem **1** sem `mover()` abrir nada — a asserção era satisfeita pelo **próprio arcabouço de teste** | **NA RAIZ:** reancorada em *"estritamente maior que a referência medida FORA de `mover()`"*. A mutação agora morre nomeando os dois números |

⚠️ **Dois "passou" que NÃO são sobreviventes, investigados e declarados** (quick `261021-fix`): tirar
`FALHA` da constante **não** mata o `193-02 guarda-modalidade-irma` porque aquele caso põe a irmã em
`aguardando_aprovacao` ou **inativa**, **nunca** em `falha` — **inobservável por construção** para ele,
***e é exatamente por isso que a decisão 25 não afrouxa a decisão 23***; e a de-dup contra todo o
histórico **não** mata o `193-04 retry-republica` porque aquele caso tem **uma** recusa só, e com uma
linha as duas formas **coincidem**.

### ⚠️ A forma da mutação, cumprida pelas quatro campanhas

Commit **ANTES**; `cp -p` para **fora** do repo; script com nome que carrega o plano
(`mutar-193-NN.sh`), com o **`sha256` do próprio script impresso ANTES do veredicto e reconferido
depois** (⚠️ o scratchpad é **COMPARTILHADO** e um `mutar.sh` já foi **sobrescrito** no meio de uma
campanha, custando a campanha inteira); ocorrência **travada em 1 ANTES** por string exata; **qual
linha mudou** conferida depois; aplicação por **divergência de `sha256`**; restauração **DA CÓPIA** em
`trap EXIT`; `sha256` final contra `git show HEAD:` com `tr -d '\r'` nos dois lados.
⚠️⚠️ **`git checkout` NUNCA.** ⚠️⚠️ **E o `# tests` do mutado CONFERIDO contra o do pristino em todas
as 36** — uma mutação já morreu por **falha de BOOT** e não provava nada. ⚠️ Houve **validação seca**
das âncoras antes (aplicar → restaurar sem rodar gate): sem ela, **âncora morta dá "morreu" falso**.

⚠️ **UM FALSO ALARME da própria régua de medição, declarado:** o `sed` do script da wave 02 capturava
o **primeiro** número de `Tests: 1 failed, 2 passed` — que é o de **falhas**, não o total — e avisava
*"a contagem de TESTES caiu"*. O total era `1 + 2 = 3` = pristino. **A medição que vale é a do
harness.**

---

## §14 — ⚠️ OS CRITÉRIOS DE ACEITAÇÃO DO **MEU** PLANO QUE EU RODEI E ACHEI VAZIOS, FRACOS OU FALSOS

| critério do `193-05-PLAN.md` | veredicto medido |
|---|---|
| `key_link`: *"`git diff --numstat <partida>..HEAD` **dentro do teste**"* | ⚠️⚠️ **RECUSADO, com medição:** seria **auto-invalidante**. A base é um commit FIXO e o range **cresce para sempre**: no dia em que outra sessão legitimamente editar o `MlPublicacaoService`, o teste — que roda na suíte **toda vez** — fica vermelho por **trabalho alheio correto**. É a família consertada **17** vezes nesta milestone, e o 17º caso foi **ontem**. ➡️ O `numstat` foi medido **em linha de comando** e está **COLADO** aqui (§4); o teste guarda o **anti-fantasma** e a versão **comportamental**. ⚠️ Os fechamentos das Fases **188 e 189** já tomaram esta decisão, com a razão escrita |
| *"range cru `<partida>..HEAD` declarado como INFORMATIVO"* | ⚠️ **VAZIO POR CONSTRUÇÃO no início da wave:** `rev-list --count` deu **0** (PARTIDA == HEAD). ➡️ Registrado como medição (*"nenhum commit alheio havia entrado"*) e **substituído** pela janela da **FASE INTEIRA** (`c4529cca..HEAD`, 26 commits), que é **estritamente mais forte** |
| *"conjunto de rotas idêntico ao da Fase 188 — **as 6+5**"* | ⚠️ **a contagem do artefato está ERRADA**: o ROUTER mede **16**. O conjunto foi congelado **por NOME** |
| *"o gate D-11 … `numstat` vazio"* como prova de *"intacto"* | ⚠️ **FRACO sozinho:** `numstat` vazio prova que **esta janela** não o tocou, não que ele está **íntegro**. ➡️ Acrescentadas a **igualdade de conjunto** dos nomes, a contagem de `assert.` e a **execução isolada** (30/30/0 e 7/7/0) |
| *"os 9 literais ausentes nos arquivos desta fase"* | ✅ mas **cada wave rodou só a SUA lista**. ➡️ Este é o único lugar que roda a sonda sobre a **UNIÃO dos 5** |
| *"nenhuma coluna nova (nenhuma migration desta fase)"* | ⚠️ **duplicaria** o `193-01 schema-sem-migration` (3/50). ➡️ Reancorado no que **este** plano pode provar sem repetir: a lista do território **não contém** nada sob `database/migrations/` nem sob `resources/`, com **controle positivo** das 5 naturezas (senão os dois `assertSame([], …)` seriam vazios por construção) |
| *"gate `js`"* no manifesto desta wave | ⚠️ **NÃO ENTROU, declarado:** os dois `.js` desta fase são **INTOCÁVEIS** e já têm medição isolada; um id `js` aqui rodaria arquivo alheio sob o número desta wave |
| *"igualdade de conjunto das asserções do D-11"* por contagem de `assert.` | ⚠️ **fraca sozinha**: contagem igual com **conteúdo trocado** passaria. ➡️ Por isso a **igualdade dos 24 nomes** é a asserção principal, e a contagem é a segunda camada |

---

## §15 — ⚠️ OS FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS

**FURO 1 — ⚠️⚠️ o `EXIT_REAL` lido DEPOIS DE UM PIPE.** A primeira medição do arquivo do D-11 foi
`node --test … | grep -E '^# (tests|pass|fail)'` e eu imprimi `$?`: deu **0**, que era o exit do
**`grep`**. ➡️ Refeito com redirecionamento para arquivo e `$?` **na linha seguinte**. ⚠️ **O exit real
também era 0** — mas isso é **sorte, não método**: a quick de ontem pegou o real sendo **2** onde o
pipe dizia **0**.

**FURO 2 — a isca da interseção de território saiu com `comm: file 1 is not in sorted order`.** Eu
injetei o caminho **depois** do `sort`. ➡️ Refeita com o `sort` **depois** da injeção, e aí ela
**ACUSOU** (`routes/console.php`). ⚠️ Sem refazer, eu teria registrado *"a isca não acusou"* como se
fosse propriedade da sonda, quando era erro do meu pipeline.

**FURO 3 — a sonda de `PUT /items` que eu quase escrevi era a LARGA.** O briefing dizia *"não existe
NENHUM `PUT` no Publicador"*; medindo, achei **TRÊS**, e um deles **CONTÉM** `/items/`. ➡️ A sonda
ficou **estreita** (`'PUT', '/items`), com as **duas** pontas asseveradas: ela pega o caso proibido e
**não** pega o legítimo, e a **larga acusaria** o legítimo. ⚠️ Esta é a terceira wave seguida em que o
mesmo erro de briefing teria produzido um gate mais largo que o invariante.

**FURO 4 — a sonda do D-22 por `/D-?22/` engoliria o teste de ABAS.** São **7** ocorrências de `D-?22`
no arquivo, em contextos diferentes, e uma delas é `(D22)` falando de `mlb.anuncios.wizard`. ➡️
Reancorada no **prefixo do nome** (`D-22:`), com asserção provando que o teste de abas **não** entra —
e com a asserção de que o teste de abas **ainda existe**, senão a isca perde o sujeito.

**FURO 5 — eu ia congelar a contagem de EXECUÇÃO (30) do arquivo do D-11 como se fosse a de
declaração.** São **24 declarações** que rodam **30 testes** (as seis primeiras estão num laço sobre
dois caminhos). ➡️ Congelei a de **declaração**, que mede o mesmo invariante **sem** depender de rodar
`node` de dentro do PHPUnit — e escrevi a aritmética (`6 × 2 + 18 = 30`) para o leitor seguinte não
"consertar" o número.

**FURO 6 — ⚠️⚠️ EU CAUSEI UMA REGRESSÃO, E QUEM A ACHOU FOI A SUÍTE COMPLETA.** O
`193-03 bijecao-manifesto-193-03` ficou **VERMELHO** na rodada 1 do `--suite php`:

```
⨯ 193-03 > o manifesto tem bijecao com os metodos e nenhum id orfao 193 03
  há método de outra suíte que o `--filter` NÃO ANCORADO deste plano também casaria
  + 0 => 'FechamentoDaFase193Test.php::test_avisos_do_ml_aceitos_vao_para_o_historico_193_03_extra'
```

**A CAUSA, medida:** o varredor de homônimo daquela wave lê o **TEXTO CRU** do arquivo — **comentário
incluído** — com `/function\s+([A-Za-z0-9_]*<nome>[A-Za-z0-9_]*)\s*\(/`, e a primeira redação do meu
docblock **citava o literal inteiro** da isca da wave 03, com a palavra-chave de declaração **colada
ao nome** e o parêntese em seguida. ➡️ Ele leu o meu **comentário** como *"este arquivo declara um
método que já existe em `ReconciliarPublicacaoDoPortaoTest`"*.

⚠️⚠️ **É o FURO 1 DA WAVE 04 AO CONTRÁRIO:** lá o nome alheio estava numa **asserção**; aqui, na
**PROSA**. Eu obedeci a regra *"o nome alheio entra por VARIÁVEL"* nas asserções **e a violei no
docblock**. ➡️ **A regra vale para comentário também**, e agora está escrita.

**CONSERTO NA RAIZ, em DOIS movimentos** (`602b4d62`):
1. a prosa deixou de escrever a forma de declaração — o nome **segue citado**, porque citar é
   necessário para o caso ser nomeável;
2. nasceu um **gate PRÓPRIO** que proíbe essa forma **neste arquivo**, para o próximo executor não
   repetir, com **isca nas duas pontas**: ele pega `function <nome>(` e **não** pega a **citação** do
   nome sozinho.

⚠️ **E o conserto errado, que eu NÃO fiz:** pôr o meu arquivo numa lista de exceção do gate da wave
03, ou alterar aquele gate. ⚠️ Ele **é** mais largo que o invariante dele (lê comentário e string) —
isso está registrado no `deferred-items.md` item 8 —, **mas é gate de wave fechada**, e o meu texto
é que estava errado.

⚠️ **O custo medido:** 1122 s de suíte descartados. ⚠️ **E o ganho:** foi a **suíte COMPLETA** que
achou — nenhum dos meus 4 ids pegaria isto, porque o invariante violado era de **outra** wave. ➡️
***"Rode a suíte completa"* não é formalidade.**

**FURO 7 — e o gate que eu criei no FURO 6 nasceu MAIS LARGO QUE O INVARIANTE.** A primeira redação
dele varria **todas** as declarações da suíte, inclusive helpers de nome comum em pt-BR
(`linha`, `service`, `nomes`, `passo`, `gate`, `rota`, `pub`, `ler`, `coluna`, `linhas`, `ids`,
`publicacao`, `conta`, `publicar`). Como esses nomes são **substring dos MEUS PRÓPRIOS métodos**, ele
acusou **14 nomes — e todos eram as minhas próprias declarações**. ➡️ **Estreitado para nomes
`test_`**, que são os únicos que o `--filter` roda. ⚠️ **Dois furos da mesma família em cinco
minutos**, e os dois achados por **rodar**, não por reler.

**FURO 6 — o gate dos órfãos ia REPROVAR por trabalho de outras waves.** Medindo os cinco pares
plano × manifesto, achei **5 ids órfãos de `<verify>`** — os quatro `bijecao-manifesto-193-0N`
(auto-referência) e o **`guarda-modalidade-irma`**, que é órfão **de verdade**. ⚠️ Um gate de *"zero
órfãos"* reprovaria **trabalho correto de fase fechada**, e consertá-lo exigiria editar
`193-02-PLAN.md`, que é artefato do **planejador**. ➡️ **Conjunto CONGELADO e DECLARADO, com dono:**
órfão **novo** reprova; estes cinco estão escritos. ⚠️ **Apagar um da lista para passar é o conserto
que o learnings proíbe.**

---

## §16 — ⚠️ O QUE ESTA FASE **NÃO** FEZ, E POR QUÊ (é a régua, não omissão)

| item | razão MEDIDA |
|---|---|
| **migration** | as três colunas de ligação (`fila_item_id` com índice `pubapof_fila_item_ix` e FK `pubapof_fila_item_fk` **`nullOnDelete` nullable**, `ml_item_id`, `erro_publicacao`) **já existiam desde a Fase 186** — provado por asserção sobre o **schema VIVO** (`193-01`, 3/50) |
| **o ritual `migrate → rollback → migrate`** | ⚠️ **NÃO SE APLICA, e isso é declarado:** não há DDL nova. A receita fica guardada (`--batch=<N> --path=<arquivo>`, **nunca `--step=1`**, porque o banco local é compartilhado) para o caso de a decisão ser reaberta. ⚠️ **`artisan migrate` NÃO foi rodado** |
| **qualquer `.jsx`** | a caixa de falha, o botão *"Tentar publicar novamente"*, os 8 estados com cor+ícone+texto e os **6 toasts** já existiam (186/187/188) ⇒ o *"UI hint: yes"* do ROADMAP é **superestimativa MEDIDA** |
| **o 7º toast** | VOC-08 é lista **FECHADA** |
| **feature-flag de "ligar publicar"** | a trava é `ContasLiberadas` (**fail-closed**), a mesma que PUB-06 exige; um segundo interruptor faria **duas verdades** |
| **fila nova / job novo** | a publicação é `PublicarRascunhoJob` na **`high`**, e **já era**. ⚠️ A razão está escrita no projeto: em **16/09/2026**, **179 jobs de acervo na `default`** seguraram um webhook da Clicksign **por HORAS**; e ⚠️ **a tabela `jobs` dá `0` e ENGANA, porque a fila de produção é REDIS**. **Declarado, não mudado** |
| **deploy, push, VPS, API real do ML** | ⚠️ **nada disso foi feito.** ⚠️ Para quem for deployar: o `deploy.sh` só reinicia `ecf-worker:*`; depois dele é obrigatório `sudo -u www-data php artisan queue:restart` para pegar `ecf-worker-high` **sem matar job em andamento** (`supervisorctl restart` mataria uma publicação no meio) |
| **`.planning/ROADMAP.md`, `STATE.md`, `REQUIREMENTS-v27.md`** | **GATE-07** — não pertencem a plano nenhum. ⚠️ **`state.advance-plan` NÃO foi rodado** (corrompeu o `STATE.md` **seis** vezes) |
| **o MariaDB local** | ⚠️ **está DE PÉ** (PID **30496**, ligado por outra sessão em 20/10 às 21:45) **e não afeta nada**: `phpunit.xml:27-28` fixa `sqlite` `:memory:`. ⚠️ **Não escrevo "mysqld parado"** — a `193-REGUA.md` §2 diz isso, e **ficou obsoleto** |
| **os 27 `stash`** | ⚠️ **apenas CONTADOS** (`git stash list | wc -l` → **27**). Nenhum `git stash`, `git reset`, `git checkout`, `git clean`, `git add -A` ou `--amend` foi usado |

---

## §17 — ⚠️⚠️ O QUE O ORQUESTRADOR TEM DE MARCAR À MÃO — E POR QUE AS FERRAMENTAS NÃO SERVEM

⚠️ **ESTE PLANO NÃO EDITOU `ROADMAP.md`, `REQUIREMENTS-v27.md` NEM `STATE.md`** (GATE-07). Ele
**lista**:

| o quê | por que à mão |
|---|---|
| **Coverage Map** e a linha `**Plans**:` da Fase 193 no `ROADMAP.md` (hoje `TBD`) | `roadmap update-plan-progress` **reporta sucesso e não escreve nada** nesta milestone |
| os **PUB-01..10** e os `TERR-*`/`GATE-*` no `REQUIREMENTS-v27.md` | `requirements.mark-complete` devolve **`not_found`** para IDs de `REQUIREMENTS-vNN.md` (o `REQUIREMENTS.md` raiz parou na v17.0) |
| o bloco de fechamento no `STATE.md`, **ADITIVO** | ⚠️⚠️ **`gsd-sdk query state.advance-plan` já corrompeu o `STATE.md` SEIS vezes — não rodar.** Conferir por `git diff --numstat` que **removeu 0 linhas** |
| a linha `PLACAR-193: OK=9 RESSALVA=1 REPROVA=0` | para a fase seguinte ler o portão de entrada sem abrir este documento |
| a correção de *"as 6+5 da 188"* → **16 rotas** na `193-COBERTURA.md` | artefato do planejador; eu **devolvo** em vez de editar |
| a correção de *"o MariaDB local está PARADO"* na `193-REGUA.md` §2 | idem — está **DE PÉ** (PID 30496) e não afeta nada |
| o `<verify>` ausente do `guarda-modalidade-irma` em `193-02-PLAN.md` | artefato do planejador; o id **funciona**, mas nenhum plano o invoca |

### ⚠️ Duas inconsistências de registro herdadas, achadas pelas waves e **não** consertadas

1. `STATE.md:4513` diz *"`189-FECHAMENTO.md` (**1407** linhas)"* e o arquivo tem **1432** — três
   commits de `docs(189-08)` cresceram o arquivo **depois** daquela linha. **Dono: orquestrador.**
2. O `ROADMAP.md` tem **duas** tabelas de requisitos que **discordam**: `:5129` diz
   `AMPL-01..AMPL-07 (7) | Phase 189 | Pending`, e `:5201` a declara entregue com o placar.
   **Dono: orquestrador.**

---

## §18 — §AVISOS ÀS FASES SEGUINTES

### Para a **Fase 194** (a próxima)

- ⚠️ **Ela cria a primeira oferta** — e por isso é ela que torna o critério 10 executável. ⚠️ **MAS
  isso foi ANTECIPADO:** `PreparadorDaAprovacao:148` (do **agente**, outra sessão) **já cria oferta**,
  então o roteiro passa a ser executável **depois do PILOTO DO AGENTE**, não só depois da 194.
- `PERGUNTA-17` (`mlb_empresa_id` anulável deixando a oferta invisível na fila) é **dela**. ⚠️ Nenhum
  plano da 193 a agrava, porque nenhum plano da 193 **cria** oferta.
- É com ela que o painel fica **conferido a olho**, e é ela que liga `publicador.criativos_auto.ativo`.
- ⚠️ **O produto com DOIS alvos ativos é o caminho que nenhum teste exercita em produção hoje:** o
  agente encaminha produto com **UM** alvo ativo. Quando houver dois, a guarda da decisão 23 e a
  decisão 25 passam a valer de verdade — **vale medir, não supor**.

### Para a **Fase 192** (última da fila, reancorada no painel)

⚠️⚠️ **ANTES de tocar `app/Services/Creative/`, os endpoints `criativo*`, `AnunciarML.jsx`,
`PainelCriativosIa.jsx` ou `KitCriativosGrade.jsx`: MOSTRE o aviso de coordenação do `CLAUDE.md` ao
usuário e PERGUNTE se ele já combinou com o ECF Dev. NÃO decida por ele.** Ela está bloqueada por
**duas respostas do outro dev**.

### Para quem fechar a próxima fase

⚠️ **A OUTRA SESSÃO ESTÁ ESPERANDO ESTE FECHAMENTO:** ela avisou que o **AG-05b** precisa de patch em
`PublicacaoService::iniciar()` e `abrirTarefaPosPublicacao()`, e que **não começa enquanto a 193
estiver aberta**, porque o gate de `numstat` desta fase reprovaria. ⚠️ **Não é bloqueio desta wave —
é contexto:** o gate do PUB-03 é a razão de ela esperar. ➡️ **Com este fechamento, ela está liberada**,
e o `numstat` do `PublicacaoService` a partir daqui deixa de ser invariante desta fase.

---

## §CHECKPOINT — ROTEIRO DA CONFERÊNCIA VISUAL (critério 10)

PERGUNTA-VISUAL-193: ABERTA — dono: usuário
PERGUNTA-VISUAL-189: ABERTA — dono: usuário (a conferência do lightbox segue aberta e SEPARADA)
PERGUNTA-VISUAL-188: ABERTA — dono: usuário (a conferência do painel de revisão segue aberta e SEPARADA)
PERGUNTA-VISUAL-187: ABERTA — dono: usuário (a conferência da FILA segue aberta e SEPARADA)
PERGUNTA-193-A: FECHADA — a oferta que a fila recusa fica `aprovado` com o motivo real no histórico, e o reconciliador repete; o 9º bloqueio foi OFERECIDO e RECUSADO — dono: usuário (decidido em 20/10/2026, decisão 21)
PERGUNTA-193-B: FECHADA — editar foto de anúncio JÁ NO AR fica DIFERIDA para fase própria — dono: usuário (decidido em 20/10/2026, decisão 22)
PERGUNTA-193-C: FECHADA — o toast *"Publicado e confirmado"* não dispara sozinho, e a AUSÊNCIA dele sem MLB é asseverada por gate; a decisão de a fila atualizar sozinha fica para a fase de tela — dono: Fase 194 ou fase de tela
PERGUNTA-17: ABERTA — `mlb_empresa_id` anulável pode deixar a oferta invisível na fila — dono: Fase 194 (é ela que cria oferta)

⚠️⚠️ **O QUE FOI CONSTRUÍDO, E O QUE NINGUÉM PODE AFIRMAR.** O ciclo está fechado **no servidor**:
aprovar enfileira **na hora** (sem segundo clique e sem desfazer), o passo do minuto espelha o que a
fila fez, `publicando` bloqueia edição, `publicado` só sai com **MLB confirmado**, e a falha
**preserva a revisão aprovada** com *"Tentar publicar novamente"*.
⚠️⚠️ **NADA DISTO FOI VISTO EM TELA, E NENHUM EXECUTOR AFIRMA QUE VIU TELA RENDERIZADA.** Não há
navegador; nenhuma empresa tem `ml_token` de verdade (a rota dá **404**); `pub_aprovacao_ofertas` está
**VAZIA em produção**; **esta fase NÃO foi deployada**; e o cron **não foi visto rodando**. A prova
desta fase é **teste com `Http::fake()` + `preventStrayRequests()`** (e `Queue::fake()` onde o caminho
despacharia job).

⚠️ **ESTE ROTEIRO SÓ É EXECUTÁVEL DEPOIS DE:** *(a)* **deploy autorizado pelo usuário** — e ⚠️ **esta
wave não deploya** —, e *(b)* existir a primeira oferta. ⚠️ **A dependência MUDOU e ficou mais perto:**
era *"depois da **Fase 194**"*; medindo, `PreparadorDaAprovacao:148` **já cria oferta**, então basta o
**piloto do agente** numa loja. Até lá, o critério 10 fica como **RESSALVA declarada**.

Quando houver oferta na fila, em **produção, na conta 459**:

1. Abrir `…/mlb/anuncios/meus/{company}/aprovacao` e confirmar que há oferta em **"Aguardando
   aprovação"**. ⚠️ **Se a fila estiver vazia**, não há o que conferir — e isso **não é defeito**: é o
   estado de hoje. Pare aqui e avise.
2. Abrir o painel de revisão de uma oferta **sem bloqueio** e clicar **"Aprovar para publicação"**.
   ⚠️⚠️ **AVISO ANTES DE CLICAR: aprovar JÁ PUBLICA e não há desfazer.** Confirmar que **não** aparece
   nenhum segundo passo, confirmação, *"desfazer"* ou *"cancelar aprovação"*, e que o toast é
   *"Aprovado — aguardando publicação"*.
3. Confirmar que a oferta passou a **"Aprovado"** e que o painel da **fila de publicação** da conta
   mostra o produto **agendado**.
4. Se o produto tem **Clássico e Premium**, aprovar **as duas** e confirmar que a fila mostra **UM**
   produto, não dois. ⚠️ E que aprovar **só uma** **não** enfileira nada (decisão 23): a oferta fica
   em *"Aprovado"* com o motivo no histórico nomeando a outra modalidade.
5. Esperar a passada do minuto e confirmar que a oferta vai para **"Publicando"**, e que **não é
   possível editá-la** nesse estado.
6. Ao fim, confirmar que ela está **"Publicado"** **com o código `MLB…` visível**, e que o anúncio
   existe no Mercado Livre. ⚠️⚠️ **Se aparecer "Publicado" SEM o MLB, isto é REPROVA** — é o defeito
   central que a fase existe para evitar.
7. Se houver falha, confirmar que: o texto mostrado é o **erro real do Mercado Livre**; a frase
   *"Nenhuma oferta foi criada; a revisão foi preservada."* aparece **só** quando nenhum anúncio foi
   criado; a **versão aprovada** continua a mesma; e **"Tentar publicar novamente"** volta a oferta
   para **"Publicando"**. ⚠️ Se a fila **recusar** o retry, o esperado é **não mover**, com o motivo
   real na tela e no histórico (decisões 21 e 26) — isso **não** é defeito.
8. Conferir no **histórico da oferta** quem aprovou e **em que versão**, e que os **avisos do Mercado
   Livre** aceitos no clique aparecem lá (decisão 24).

⚠️ **Deploy, push e qualquer comando no VPS NÃO são deste plano.** ⚠️ Depois de um deploy futuro:
o `deploy.sh` só reinicia `ecf-worker:*`; é preciso `sudo -u www-data php artisan queue:restart` para
pegar `ecf-worker-high` **sem matar job em andamento**.

---

## §19 — ⚠️ A RE-MEDIÇÃO NO HEAD FINAL (`bd0574ba`), depois dos dois consertos de mutação

⚠️ **A linha `PHP:` foi medida em `87f29f2e`.** Os dois commits de conserto da campanha de mutação
(`761ab6a4`, `bd0574ba`) vieram **depois** e **só acrescentam asserção a métodos que já existiam** —
logo **a contagem de TESTES não pode ter mudado** (nenhum método novo). ⚠️ **Em vez de supor**, eu
re-rodei, no HEAD final, **todos** os gates que **leem** o meu arquivo:

| gate | no HEAD final `bd0574ba` | esperado | |
|---|---|---|---|
| `193-05 territorio-e-d11` | **3 / 43**, `EXIT_REAL=0` | 43 | ✅ |
| `193-05 nada-sem-destino` | **3 / 15**, `EXIT_REAL=0` | 15 | ✅ |
| `193-05 bijecao-dos-cinco-manifestos` | **1 / 24**, `EXIT_REAL=0` | 24 | ✅ |
| `193-05 fechamento-de-forma-fixa` | **3 / 51**, `EXIT_REAL=0` | 51 | ✅ |
| `193-01 bijecao-manifesto-193-01` | **1 / 20**, `EXIT_REAL=0` | 20 | ✅ |
| `193-02 bijecao-manifesto-193-02` | **1 / 32**, `EXIT_REAL=0` | 32 | ✅ |
| `193-03 bijecao-manifesto-193-03` | **1 / 33**, `EXIT_REAL=0` | 33 | ✅ ⚠️ **era o vermelho da rodada 1** |
| `193-04 bijecao-manifesto-193-04` | **1 / 31**, `EXIT_REAL=0` | 31 | ✅ |
| `186-09 bijecao-e-homonimo` (alheio) | **1 / 31**, `EXIT_REAL=0` | 31 | ✅ |
| `186-09 transversais` (alheio) | **12 / 259**, `EXIT_REAL=0` | 259 | ✅ |

⚠️⚠️ **O QUE EU NÃO FIZ, E DIGO:** **não** rodei uma TERCEIRA vez o `--suite php` inteiro (cada
rodada custa ~16-19 min). ➡️ **O que está provado é o que está escrito:** a contagem de **testes** não
pode ter mudado, e **todos** os gates que leem o arquivo alterado estão **verdes e idênticos** no HEAD
final. ⚠️ **Quem quiser o número do HEAD final, mede o HEAD final** — é a regra deste documento, e ela
vale para mim também.

---

## §20 — Self-Check

**Arquivos criados/alterados por esta wave, conferidos no disco:**

```
OK   .planning/phases/193-…/193-FECHAMENTO.md
OK   .planning/phases/193-…/deferred-items.md
OK   .planning/phases/193-…/193-05-SUMMARY.md
OK   tests/Feature/Publicador/Aprovacao/FechamentoDaFase193Test.php
OK   tests/gates/contagens-193-05.txt
```

**Commits, conferidos por `git log --oneline --all | grep`:**

```
OK   2f888014  docs(193-05)  o fechamento medido
OK   73bcac1c  test(193-05)  os gates transversais
OK   602b4d62  fix(193-05)   a regressão achada pela suíte completa
OK   87f29f2e  docs(193-05)  as dívidas com dono
OK   eb1dc77f  docs(193-05)  as baselines re-medidas
OK   761ab6a4  fix(193-05)   a sobrevivente m3 (a borda do dono)
OK   bd0574ba  fix(193-05)   a sobrevivente m9 (o piso)
```

## Self-Check: PASSED
