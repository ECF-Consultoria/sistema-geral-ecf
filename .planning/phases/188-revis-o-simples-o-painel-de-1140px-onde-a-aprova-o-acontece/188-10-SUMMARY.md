---
phase: 188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece
plan: 10
subsystem: publicador/aprovacao
tags: [tela, painel-de-revisao, pendencias, rodape, disabled, criterio-8, criterio-9, criterio-10, criterio-16, criterio-17]
requires:
  - "188-02 — `painel.pendencias[*].destino` e `painel.rodape` resolvidos no servidor"
  - "188-05 — as rotas `salvar-revisao` e `aprovar`"
  - "188-06 — a rota `conferir-alteracao` (commitada em `1f2dbe44`)"
  - "188-07 — a casca do painel, os slots e o helper único `escreverNoPainel`"
  - "186 — `BloqueiosDaOfertaService::resumoDoRodape()` e `VocabularioDoPortao::TUDO_CONFERIDO`"
provides:
  - "`PendenciasDoPainel.jsx` — as pendências, cada uma com o seu botão de correção"
  - "`RodapeDoPainel.jsx` — o rodapé fixo de 66px, com o `disabled=` de \"Aprovar para publicação\""
  - "os slots `pendencias` e `rodape` em `PainelDeRevisao.jsx`, e o fio deles na página"
affects:
  - "188-11 — religa `onSolicitarAjustes` e `onAbrirModal` (modais de ajuste e exceção)"
  - "188-12 — religa `onAbrirModal` do frete, o `onConflito` e os handlers da coluna de preço"
  - "188-13 — o fechamento, a bijeção dos treze pares plano×manifesto e o roteiro da conta 459"
tech-stack:
  added: []
  patterns:
    - "o destino de correção é DADO do servidor; a tela obedece, não decide"
    - "`disabled=` por ATRIBUTO, elemento por árvore com índice ABSOLUTO"
    - "literal de tela sem dono no servidor precisa de expectativa CONGELADA no gate"
key-files:
  created:
    - resources/js/Components/Mlb/Publicador/aprovacao/PendenciasDoPainel.jsx
    - resources/js/Components/Mlb/Publicador/aprovacao/RodapeDoPainel.jsx
    - tests/js/publicador-painel-rodape-188.test.js
    - tests/gates/contagens-188-10.txt
  modified:
    - resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx
    - resources/js/Pages/Mlb/Publicador/Aprovacao.jsx
    - tests/js/publicador-vocabulario-novo.test.js
    - tests/js/publicador-fila-fechamento-187.test.js
    - .planning/phases/188-revis-o-simples-o-painel-de-1140px-onde-a-aprova-o-acontece/188-FID01.md
decisions:
  - "O rodapé ganhou slot PRÓPRIO, separado do `children`: medido por script, `:712` é IRMÃO do corpo rolável de `:562`, e dentro dele rolaria para fora da tela"
  - "A pendência de `alteracao_nao_conferida` liga a rota de BLOQ-07 pelo helper único; os handlers da COLUNA DE PREÇO seguem `null` (não são do `files` deste plano) — fica para a 188-12"
  - "A página foi ligada como desvio declarado, pelo mesmo precedente da 188-09: sem o fio as duas peças não apareceriam na tela, e nenhuma wave de 11 a 13 tem a página no `files`"
metrics:
  duration: "~3h"
  completed: 2026-10-19
---

# Fase 188 Plano 10: as pendências com o seu botão de correção e o rodapé de 66px — Summary

As pendências com **cada uma levando ao lugar certo** (os nove destinos LIDOS do payload, com os
dois `null` **sem botão e com o texto ficando**) e o rodapé fixo de 66px onde **"Aprovar para
publicação" fica `disabled=` enquanto houver bloqueio** — com o atributo conferido **no botão de
aprovar, por árvore com índice absoluto**, e três iscas provando a ancoragem.

---

## §1 — A PARTIDA, E O ESTADO FINAL

| o quê | valor |
|---|---|
| **PARTIDA (capturada na 1ª ação)** | **`4c3dde27`** |
| commit da tarefa 1 | `cb8cea0f` |
| commit da tarefa 2 | `5098d743` |
| commit do conserto da sonda (da mutação sobrevivente) | `32b6500b` |
| árvore nos MEUS arquivos no fim | `git diff HEAD --numstat` **VAZIO** |
| `git stash list` | **27** entradas — ⚠️ **APENAS LISTADAS**, nenhum `git stash` em forma nenhuma |

⚠️ **Território por ESCOPO DO ASSUNTO, não por range** (`188-REGUA.md` §6 regra 9). As DUAS passadas
independentes concordaram, e os arquivos tocados são **exatamente** oito:

```
PASSADA 1 — git log --grep='(188-10)'           → 3 commits, 8 arquivos
PASSADA 2 — git diff --numstat <C>^ <C>, um por um → os MESMOS 8 arquivos

resources/js/Components/Mlb/Publicador/aprovacao/PendenciasDoPainel.jsx   (novo, 510 linhas)
resources/js/Components/Mlb/Publicador/aprovacao/RodapeDoPainel.jsx       (novo, 483 linhas)
resources/js/Components/Mlb/Publicador/aprovacao/PainelDeRevisao.jsx      (+37 −2)
resources/js/Pages/Mlb/Publicador/Aprovacao.jsx                           (+87)
tests/js/publicador-painel-rodape-188.test.js                             (novo, 2296 linhas)
tests/js/publicador-vocabulario-novo.test.js                              (+33)
tests/js/publicador-fila-fechamento-187.test.js                           (+5)
tests/gates/contagens-188-10.txt                                          (novo, 324 linhas)
```

**ISCA (a)** — a sonda **PEGA** arquivo vetado: testada contra `AppLayout.jsx`,
`PublicadorHeader.jsx`, `app/Services/Creative`, `AnunciarML.jsx`, `App\Support\Publicador`,
`app/Mcp`, os três do GATE-07 e `188-REGUA.md` → **zero acusados**.
**ISCA (b)** — a sonda **ACHA** `RodapeDoPainel.jsx`, que eu realmente toquei → **não está cega**.

⚠️ **NÃO conferi o AUTOR:** as três máquinas desta árvore commitam com o mesmo `user.name`
(`MB.ECF-100376`), e `%an` é vazio por construção aqui.

---

## §2 — AS BASELINES, com as falhas NOMEADAS e ATRIBUÍDAS

| medida | PARTIDA (`4c3dde27`) | FIM (`32b6500b`) | leitura |
|---|---|---|---|
| **JS** (`--suite js`) | **5654 / 5653 pass / 1 fail / 0 skipped** | **5710 / 5709 pass / 1 fail / 0 skipped** | **+56**, e a soma FECHA |
| **PHP** `tests/Feature/Publicador/Aprovacao` | **319 passed (5616 assertions) / 0 falhas** | **319 passed (5616 assertions) / 0 falhas** | **IDÊNTICO** |
| **BUILD** (`npm run build`) | — | **exit 0** | `assets/app-C856y01j.css` |
| **MANIFEST** (pelo JSON) | — | **495 entradas / 202 páginas** | página da fila **presente**, isca **ausente** |
| **PROTÓTIPO** | 1432 linhas · `68624883ce35547e` | **idêntico** | não se moveu |

**O +56 do JS, conferido:** 42 (os testes do gate novo) + 14 (o GATE-03, que cresce 7 por `.jsx`
novo: 632 → 639 → 646) = **56**. ✓

### ⚠️ A ÚNICA falha JS, atribuída por EXECUÇÃO ISOLADA

```
node --test --test-reporter=tap tests/js/estrutura-grade-glide.test.js
  → # tests 19 / # pass 18 / # fail 1
  → not ok 17 - Características secundárias nasce recolhido (é o grupo que mais infla)
```

É a **pré-existente da Fase 87**, aceita por `REF_JS_FALHAS=1`. **Não corrigida**, e **não é desta
fase** — provado rodando o arquivo SOZINHO.

### ⚠️ A régua PHP: `0` é o alvo, e ela foi atingida

`tests/Feature/Publicador/Aprovacao` saiu **0 falhas na partida E no fim**. ⚠️ **Nenhuma corrida de
`storage/framework/testing` nesta execução** — esta wave toca **ZERO `.php`** (confirmado: os três
commits não têm nenhum arquivo `.php`), então não havia suíte PHPUnit minha para disputar o
diretório com ninguém.
⚠️ As duas falhas conhecidas e **ALHEIAS** de `tests/Feature/Publicador/CockpitDaContaTest.php`
(**Fase 179**, **FORA** de `Aprovacao/`) **não entram neste recorte** — e o caminho
`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` foi reconferido por `test -e` e
**NÃO EXISTE**: é o **nono fantasma** desta milestone, confirmado.
⚠️ **NUNCA li `${TMPDIR:-/tmp}/ultima-suite-php.log`** durante execução (caminho FIXO e
COMPARTILHADO — a wave 7 reportou `0 falhas` quando eram 11). Toda saída foi para arquivo próprio no
scratchpad, com nome carregando o plano.

---

## §3 — ⚠️⚠️ O CRITÉRIO 8: o `disabled=` ANCORADO, e as TRÊS ISCAS

**A forma é `/disabled=/` e NUNCA `/disabled/`** — a segunda casa com a classe
`disabled:opacity-40` e o gate passaria com o botão **habilitado**. ⚠️ **E a regex sozinha não
basta:** lida sobre o HTML do rodapé inteiro ela é **gate LARGO**.

➡️ **A ancoragem:** `botaoDeAprovarEm(html)` localiza o `<button>` por **índice ABSOLUTO na árvore de
botões** e o confere pelo **rótulo único** `Aprovar para publicação`; só então lê o atributo.
⚠️ **`els[0]` mediria "Salvar revisão"** — e no rodapé real o botão de aprovar está no índice **2**
de **4** (medido e asserido).

| isca | o que prova | resultado |
|---|---|---|
| **(a)** `<button class="h-[38px] disabled:opacity-40">Aprovar para publicação</button>` | a CLASSE não é lida como atributo | `desabilitado === false` ✓ · e a premissa é provada nas duas direções: `/disabled/` **casa**, `/disabled=/` **não** |
| **(b)** `<button class="h-[38px]" disabled="">Aprovar…</button>` | a sonda **não está cega** | `desabilitado === true` ✓ |
| **(c)** ⚠️⚠️ `disabled=""` em **"Salvar revisão"**, com o de aprovar limpo | **a ancoragem** | o gate lê o de aprovar como **`false`** ✓ — a 1ª redação do plano (regex no rodapé inteiro) **passaria aqui com o botão habilitado e bloqueio de pé** |

**As duas direções do comportamento, provadas:**
- com bloqueio **e** `live` → o atributo **está** lá, com `cursor-not-allowed`;
- com **zero bloqueios e `live`** → o atributo **não** está lá, com `cursor-pointer`.

**E `disabled={false}` no React não emite atributo nenhum** — é por isso que o gate mede o
**ATRIBUTO** e não uma classe.

### ⚠️ `aprovavel` NUNCA é calculado no cliente (T-188-90)

`rodape.aprovavel` vem de `BloqueiosDaOfertaService::para()['aprovavel']`, que é
`live && B.length === 0` (`:1099`). ⚠️ **A trava REAL é `AprovacaoDaOfertaService::aprovar()`**
(plano 188-05, provado chamando a rota **DIRETO**); o `disabled=` é **conforto**.
**Provado:** o arquivo que DESENHA o botão não pode nem **VER** `pendencias`/`bloqueios`/`.filter(`/
`.length`; e `aprovavel` só aparece em DUAS formas de leitura (lista CONSTANTE). **Só `true`
libera** — `'true'`, `1`, `{}`, `[]`, `'sim'`, `-1` e `'false'` deixam o botão apagado.

---

## §4 — ⚠️⚠️ O CRITÉRIO 9: os NOVE destinos, LIDOS do payload

⚠️⚠️ **A TELA OBEDECE, ELA NÃO DECIDE.** Há gate procurando as **nove chaves de bloqueio** na fonte
SEM COMENTÁRIO — nenhuma pode aparecer —, com **isca nas duas direções** (elas **estão** em
`VocabularioDoPortao.php`, e é lá que moram).

| # | chave | correção | destino LIDO do payload | provado |
|---|---|---|---|---|
| 1 | `em_preparacao` | *(vazio)* | **`null`** | sem botão, texto fica ✓ |
| 2 | `fotos_a_conferir` | Conferir fotos | `{tipo:'foto', imagem_id:910}` | abre **aquela** imagem ✓ |
| 3 | `sem_fotos` | Adicionar fotos | `{tipo:'foto', imagem_id:null}` | abre a coluna, que **diz que está vazia** (IMG-12) ✓ |
| 4a | `frete_ausente` | Informar frete | `{tipo:'modal', modal:'frete'}` | abre o frete ✓ |
| 4b | `frete_ausente` | **Solicitar ajuste** | `{tipo:'modal', modal:'ajustes'}` | ⚠️ **o SEGUNDO destino** ✓ |
| 5 | `resultado_negativo` | Solicitar exceção | `{tipo:'modal', modal:'excecao', excecao:'resultado_negativo'}` | leva o **TIPO** ✓ |
| 6 | `margem_abaixo_do_minimo` | Solicitar exceção | `{…, excecao:'margem_abaixo_do_minimo'}` | leva o **TIPO** ✓ |
| 7 | `alteracao_nao_conferida` | Conferir alteração | `{tipo:'acao', acao:'conferir_alteracao'}` | ⚠️ **confere SEM alterar o preço** ✓ |
| 8 | `ajuste_em_aberto` | — | **`null`** | ⚠️ **PERGUNTA-188-B** ✓ |
| 9 | `titulo_longo` | — | **`null`** | ⚠️ **PERGUNTA-188-B** ✓ |

⚠️ **`frete_ausente` tem DOIS destinos, e NÃO cravei o modal de frete nele** — o destino segue a
correção que a 186 resolveu. Se a tela ignorasse isso, o rótulo *"Solicitar ajuste"* abriria o modal
de **FRETE**, que é o que VOC-04 existe para impedir. ⚠️ E a prova discrimina: a outra pendência de
foto abre **outra** imagem (`911`), então uma tela que ignorasse o `imagem_id` e sempre abrisse a
primeira passaria no primeiro caso e **reprovaria no segundo**.

### ⚠️⚠️ PERGUNTA-188-B: os dois `null` **SEM BOTÃO**, com o TEXTO ficando

**MEDIDO no render:** com as nove pendências e a oferta `live`, saem **6 botões** — nunca 9 — e os
**três** textos sem destino (`em_preparacao`, `ajuste_em_aberto`, `titulo_longo`) continuam na tela.
- **ZERO `onClick` vazio:** `doesNotMatch(/onClick=\{\(\)\s*=>\s*\{\s*\}\}/)`;
- **ZERO `disabled` decorativo:** `doesNotMatch(/\bdisabled\b/)` na seção de pendências **inteira**
  (ela não usa a palavra em lugar nenhum), mais a asserção de que nenhum botão de correção nasce
  desabilitado;
- **ISCA:** a MESMA pendência **com** destino produz botão, com o rótulo vindo de `correcao` — sem
  essa direção o caso passaria com a tela nunca desenhando botão nenhum.

É a régua "nada nasce decorativo", que o usuário exerceu **cinco** vezes. A decisão está registrada
em `188-REGUA.md` §12: **declarada, não inventada aqui**.

### ⚠️ `fotos_a_conferir` nas DUAS cores, com a MESMA chave

`:1295` — vermelho (`#F87171` / `rgba(239,68,68,0.14)`) **quando há alerta**, âmbar
(`#FBBF24` / `rgba(245,158,11,0.14)`) sem. É o ramo **mais fácil de omitir**, porque os outros três
(`loss`, `freight`, `nophoto`) são por chave FIXA. **Provado com a MESMA chave nas duas cores**, mais
`notDeepEqual` das classes (duas iguais apagariam a distinção) e a prova de que a tela **LÊ** a
severidade em vez de recalculá-la.

### ⚠️ `hasFix && live`, com a FIXTURE QUE DISCRIMINA

A fixture manda `destino` e `correcao` **PREENCHIDOS** com `live: false` — o **payload
contraditório**. O servidor já os zera fora do `live`, então **sem esse payload o gate mediria o
SERVIDOR** e a guarda da tela ficaria **inobservável**: foi exatamente a sobrevivente da wave 188-09.
Com ele: **zero botões**, e os nove textos continuam. ⚠️ Mais `live` **por FORMA** — `'true'`, `1`,
`{}`, `[]` e `'sim'` não destravam nada.

---

## §5 — ⚠️⚠️ O CRITÉRIO 10: o rodapé, e a divergência DECLARADA

| estado | o que a tela mostra | cor |
|---|---|---|
| **sem bloqueio** | o literal **`Fotos, preço e conteúdo conferidos`** | **verde** (`text-pub-ok-txt`) |
| **com bloqueio** | `Bloqueado: ` + o **PRIMEIRO** abreviado + ` +N` | **vermelho** (`text-pub-danger-txt`) |
| **fora do `live`** | um dos **5** textos de `:1297` | **a cor do ANDAMENTO** |

➡️ **A tela EXIBE `rodape.motivo`. Ela não monta frase nenhuma.** Há gate provando que nenhum `.jsx`
deste plano tem o `join(' · ')`, o prefixo `Bloqueado: `, o literal verde ou a montagem do ` +N`.

### ⚠️⚠️ A DIVERGÊNCIA CONTRA `:1297`, com `arquivo:linha` — e ela é **INTERNA AO PROTÓTIPO**

| fonte | o que produz |
|---|---|
| `design_handoff_publicador/Aprovacao.dc.html:1297` (o **PAINEL**) | `'Bloqueado: ' + inf.B.map(b => this.SHORT[b.k]).join(' · ')` — **TODAS** as abreviações |
| `design_handoff_publicador/Aprovacao.dc.html:1238` (a **FILA**) | `'Bloqueado: ' + this.SHORT[b0.k] + (inf.B.length > 1 ? ' +' + (inf.B.length - 1) : '')` |
| produto (186, **EM PRODUÇÃO**) — `app/Services/Publicador/Aprovacao/BloqueiosDaOfertaService.php` | `resumoDoRodape()`: o **PRIMEIRO** + ` +N` dos demais |

⚠️⚠️ **MEDIDO por script: o protótipo tem as DUAS formas, e `:1238` é EXATAMENTE a do produto.**
➡️ Não é o protótipo perdendo — é ele sendo **internamente inconsistente**, e o **critério 10** (que
diz textualmente *"com bloqueio, o motivo em vermelho com o **primeiro abreviado**"*) desempatando.
⚠️ **Portanto NÃO é um quinto conflito protótipo × regra do projeto, e não houve nada a devolver.**
Recopiar o `join` criaria a **segunda verdade da mesma frase na mesma tela** (o rodapé da fila e o do
painel divergiriam) — o precedente da PERGUNTA-P6.

### ⚠️ O literal verde é **LIDO** de `vocabularioDoPortao.jsx`

`M_VOCABULARIO.TUDO_CONFERIDO` é importado **do módulo** pelo teste e congelado contra
`'Fotos, preço e conteúdo conferidos'`. ⚠️ **Um vocabulário só**, chaves pt-BR nos dois lados
(`VocabularioDoPortao::TUDO_CONFERIDO` no PHP), e **ZERO `||` aceitando as duas formas**.
⚠️ **E a frase de `:708` NÃO é a do rodapé:** são **duas frases diferentes, em dois lugares da mesma
tela** — `Nenhuma pendência · fotos aprovadas, composição financeira completa e margem dentro da
regra` explica **o que** foi conferido; a do rodapé é o **veredicto**. Há asserção de `notEqual`
entre as duas e de que a seção de pendências **não copia** a do rodapé.

### ⚠️ `isLive` provado nos DOIS lados

- **com `live`:** `['Salvar revisão', 'Solicitar ajustes', 'Aprovar para publicação', 'Próximo ↓']`
  — na ORDEM de `:714`/`:715`/`:719`/`:721`;
- **fora do `live`:** `['Próximo ↓']`, e só.
- ⚠️ `notDeepEqual` entre os dois: **um gate que só testasse o caso `live` passaria com um rodapé que
  nunca esconde nada**.
- ⚠️ **"Próximo ↓ existe sempre" quer dizer "NÃO depende do `live`"** (`:721` está fora dos
  `sc-if`), **não** "é desenhado sem destino": sem `proxima_id` válido (`0`, `-1`, `'7702'`, `1.5`,
  `{}`, `NaN`) ou sem `onIr`, ele **some** — a MESMA régua do `SetaDoPainel` da 188-07
  (`TopoDoPainel.jsx:183`). **Botão sem destino é D4 violado.**

### ⚠️ DECISÃO 1: aprovar JÁ PUBLICA

Os **quatro** rótulos proibidos (`confirmar publicação`, `desfazer`, `segundo passo`,
`cancelar aprovação`, mais as variantes capitalizadas) estão **AUSENTES**, lidos **SEM COMENTÁRIO**,
com isca. ⚠️ **E há a asserção INVERTIDA:** o docblock **tem** de citá-los, senão a razão da ausência
vira invisível e a próxima pessoa os reintroduz. O docblock também declara **"Fase 193"** e
**"não há desfazer"**. O rótulo `Aprovar para publicação` está **congelado byte a byte**.

---

## §6 — ⚠️⚠️ O CRITÉRIO 17: TODA escrita passa pelo helper `escreverNoPainel`

O verificador marcou o critério 17 como **EM RISCO** porque 08/09/11 não citavam o helper. **Esta
wave o fecha pelo seu lado**, com as **três** escritas que ela liga:

| escrita | rota | `versao_esperada` no corpo | prova |
|---|---|---|---|
| **Salvar revisão** (critério 16 / COL-01 / AND-06) | `mlb.anuncios.meus.aprovacao.painel.salvar-revisao` | **SIM** | chamada GRAVADA pelo stub, corpo conferido |
| **Aprovar para publicação** | `mlb.anuncios.meus.aprovacao.painel.aprovar` | **SIM** | idem |
| **Conferir alteração** (BLOQ-07) | `mlb.anuncios.meus.aprovacao.painel.conferir-alteracao` | **SIM** | idem |

⚠️ **`versao_esperada` é OPCIONAL no corpo da action**, então **omiti-la** faz
`AprovacaoDaOfertaService::exigirVersao()` receber `null` e **LIBERAR** a escrita — é literalmente
*"sobrescrever em silêncio"*, numa tela em que **aprovar JÁ PUBLICA e não há desfazer**.
➡️ **Nenhum `router.post` direto** nos dois `.jsx` (lista CONSTANTE, com isca que tolera espaço:
`router . post(` também é pega), e os dois **usam** o helper (ausência sem presença é gate sobre zero
casos).
⚠️ **Sem empresa, oferta ou versão não há handler, e sem handler o botão não é desenhado.** `0`,
`-1`, `'459'` e `1.5` são **RECUSADOS** — ⚠️ `0` é id **plausível**, e com ele a escrita sairia para
uma rota que não existe: a pessoa veria a ação acontecer e nada mudaria.
⚠️ **ZERO `localStorage`** — o critério 16 é explícito: a revisão parcial fica no **SERVIDOR**.
⚠️ **A mutação `viii-escrita-sem-versao` MORREU**, então o invariante é observável.

---

## §7 — OS HANDLERS DA COLUNA DE PREÇO: **NÃO LIGUEI, e por quê**

A wave 9 deixou a coluna de preço **pronta para receber** e não ligou `onSimular`/`onDescartar`/
`onSalvar`/`onConferirAlteracao` de propósito. As rotas da **188-06 JÁ EXISTEM** (medido:
`routes/mlb_anuncios.php:630`, `:636`, `:646`, `:654`, `:660`).

➡️ **Eu NÃO os liguei**, e a razão é de território: `ColunaDePreco.jsx` e `KpisDoPainel.jsx`
**não estão no `files_modified` deste plano**. ⚠️ Religá-los é da **wave 188-12**, e há gate da 188-09
(`publicador-painel-preco-188.test.js:1741`) cobrando `onSimular={null}` na página — que continua lá.

⚠️ **O que EU liguei, e é deste plano:** a pendência de `alteracao_nao_conferida` (critério 9,
destino `tipo:'acao'`) chama a rota de BLOQ-07 pelo helper único.
⚠️ **A assimetria fica DECLARADA:** a linha de pendência oferece *"Conferir alteração"*; o aviso da
coluna de preço ainda não. ➡️ **As duas chamam o MESMO método**
(`AprovacaoDaOfertaService::conferirAlteracao()`), então **não há duas verdades de COMPORTAMENTO** —
só de **disponibilidade**, e ela está escrita no código (`Aprovacao.jsx`, bloco da 188-10) e aqui.

---

## §8 — A TABELA FID-01 **POR SCRIPT**, com as três colunas próprias

⚠️ **MEDI POR SCRIPT** (`php -r` lendo o arquivo em array, mais varredura independente por faixa),
**não por leitura**.
⚠️ **DIRETÓRIO CITADO:** `design_handoff_publicador/Aprovacao.dc.html` — **1432 linhas**, `sha256`
`68624883ce35547e7a16fab4c3b5598bb8bb4b641fd72ea098756b8f8706d8e2`. `find . -maxdepth 3 -name
Aprovacao.dc.html` devolve **só** esse caminho (há **dois** pacotes de handoff na árvore).
⚠️ Ele **NÃO é versionado**: o bloco que depende dele **degrada para `skip` EXPLÍCITO e NOMEADO**.

| # | elemento | linha | `cursor` | `white-space` | `text-overflow` | conferido |
|---|---|---|---|---|---|---|
| E1 | `<section>` Pendências | `:699` | **—** | **—** | **—** | ✓ |
| E2 | título `Pendências` | `:700` | **—** | **—** | **—** | ✓ |
| E3 | item (`min-height:34px`) | `:702` | **—** | **—** | **—** | ✓ |
| E4 | glifo 18px | `:703` | **—** | **—** | **—** | ✓ ⚠️ **+ OMISSÃO** |
| E5 | texto `flex:1` | `:704` | **—** | **—** | **—** | ✓ |
| E6 | **botão de correção** | `:705` | **`pointer`** | **`nowrap`** (1ª prop) | **—** | ✓ |
| E7 | mensagem verde + `✓` | `:708` | **—** | **—** | **—** | ✓ ⚠️ **+ OMISSÃO** |
| E8 | **rodapé 66px** | `:712` | **—** | **—** | **—** | ✓ |
| E9 | "Salvar revisão" | `:714` | **`pointer`** | **`nowrap`** (1ª prop) | **—** | ✓ |
| E10 | "Solicitar ajustes" | `:715` | **`pointer`** | **`nowrap`** (1ª prop) | **—** | ✓ (idem E9, **medido**) |
| E11 | motivo (`max-width:420px`) | **`:717`** | **—** | **—** | **—** | ✓ (era `:718`) |
| E12 | **"Aprovar para publicação"** | `:719` | ⚠️ **`{{ P.approveCursor }}`** | **`nowrap`** (1ª prop) | **—** | ✓ |
| E13 | "Próximo ↓" | `:721` | **`pointer`** | **`nowrap`** (1ª prop) | **—** | ✓ |

**A varredura independente da faixa `:699`-`:721`:**

```
cursor:pointer                PRESENTE em 4 : 705 714 715 721
cursor:{{ P.approveCursor }}  PRESENTE em 1 : 719
cursor                        AUSENTE  em 8 : 699 700 702 703 704 708 712 717
white-space:nowrap            PRESENTE em 5 : 705 714 715 719 721
white-space:nowrap            AUSENTE  em 8 : 699 700 702 703 704 708 712 717
text-overflow:ellipsis        PRESENTE em 0 : (nenhum dos 13)
```

⚠️⚠️ **`:719` fica FORA das DUAS listas de `cursor` de propósito** — o dele é **variável**, um dos 4
pares de E12. Afirmá-lo em qualquer das duas seria **medir o irmão errado**, e um gate que o exigisse
`cursor:pointer` **reprovaria código certo** (o botão apagado é `not-allowed`).
⚠️ **`white-space:nowrap` é a PRIMEIRA propriedade da linha em TODAS as cinco** — a posição em que a
Fase 187 o perdeu três vezes.
**ISCA nas duas direções:** `:705` **tem** `cursor:` e a sonda acha; `:699` **não tem** e a sonda não
acha.

### ⚠️⚠️ AS DUAS OMISSÕES QUE EU ACHEI, e as duas são LOAD-BEARING

| onde | o que a §E **não** citava | o que quebra sem isso |
|---|---|---|
| **E4** `:703` | `display:grid;place-items:center` | ⚠️ é ele que **CENTRA o `!`** no círculo de 18px; sem ele o glifo encosta no canto |
| **E7** `:708` | `display:grid;place-items:center` (no glifo `✓`) | idem, com o `✓` |

Asseridas no **protótipo** e no **CSS construído**. Registradas na **§P** do `188-FID01.md`.

### ⚠️ A ESTRUTURA, MEDIDA POR SCRIPT: o rodapé é **IRMÃO** do corpo

```
:550  <aside>                prof 1 → 2
:562  corpo rolável          prof 2 → 3
:586  wrapper das 2 colunas  prof 3 → 4  … :697 fecha (4 → 3)
:699  <section> Pendências   prof 3 → 4  ← DENTRO do corpo
:710  </div>                 prof 3 → 2  ← FECHA o corpo
:712  rodapé de 66px         prof 2 → 3  ← ⚠️ IRMÃO do corpo
:723  </aside>               prof 2 → 1
```

⚠️ **ISCA:** a sonda **distingue** as duas profundidades (`notEqual`) — se tudo saísse igual ela não
mediria nada.

### ⚠️ `max-w-[420px]` (painel) e **NÃO** `560px` (fila)

`:717` mede **420px**; `:539` (a fila) mede **560px** — **medidas diferentes de propósito**.
Asserido: o conjunto de classes do motivo **tem** `max-w-[420px]` e **não tem** `max-w-[560px]`, mais
a prova de que `RodapeDaFila.jsx` **não** mede 420px (um gate que exigisse o mesmo nos dois
**reprovaria código certo**).
⚠️ **E o `max-w-[560px]` EXISTE no CSS construído, por OUTRO dono** — então ele **não serve como
isca de ausência**. É o mesmo achado do `grid-cols-6` da 188-08: **a sonda de CSS não protege a
fidelidade de UM arquivo**. ➡️ A prova de que o painel não usa 560px **mora na FONTE**.

### ⚠️ `RodapeDaFila.jsx` NÃO foi reusado

Ele é a **LEGENDA** do rodapé da **FILA** — outro componente, outro uso. *"O componente que já existe
faz parecido"* é justificativa **VETADA** (FID-02), e forçar o reuso de uma geometria diferente é a
outra metade do mesmo erro. ⚠️ `numstat` dele sai **VAZIO**.

### ⚠️ `:705` e `:590` — MEDIDOS IDÊNTICOS, e as constantes ficam PRÓPRIAS

Os dois `style` são **byte a byte iguais** no protótipo. As duas constantes ficam separadas de
propósito (importar a do vizinho faria uma mudança em `:590` reescrever `:705` **em silêncio**), e há
asserção provando que **continuam idênticas** — divergência futura fica **VISÍVEL**, não automática.

### ⚠️ `font-[800]` e **não** `font-extrabold`

`:703` e `:708` medem `font-weight:800`, e o **GATE-03** proíbe a escala **NOMEADA**. O valor
arbitrário entrega o **mesmo peso computado** — precedente de `AnelDeProgresso.jsx:67`,
`CabecalhoDaFila.jsx:186`, `TabelaDaFila.jsx:139`, `GradeDeMiniaturas.jsx:132` e
`IdentificacaoDaOferta.jsx:212`, **todas em produção**. Há prova no **CSS construído** de que
`font-[800]` **compila** para `font-weight:800`, e **isca invertida** de que a sonda de peso **não**
reprova o conserto certo.

---

## §9 — CADA PONTEIRO QUE EU RECONFIRMEI, e onde o plano/FID01 divergia

⚠️ **PREFERI O FID01 À PROSA DO PLANO, e reconferi TUDO por `sed`** — e achei a **SÉTIMA**
confirmação da lição da §J, mais um achado novo: **o documento se contradizia.**

| o que a §E / a prosa dizia | real (medido) | o que está DE FATO na linha citada |
|---|---|---|
| ⚠️⚠️ os **4 pares de cor do aprovar** em **`:1143`** (§E e o `<interfaces>`) | **`:1307`** | `:1143` é `freightMissing: f.missing` — e a **§L e a §O do MESMO documento** já o CONFIRMARAM assim |
| o `reason`/`join` em **`:1307`** (prosa do plano, **2×**) | **`:1297`** | `:1307` é `canApprove`/`approveBg`/`approveCursor` — o **E12** |
| `hasFix` + cores em **`:1304`** | **`:1295`** | `:1304` é `shared`/`sharedNote`/`safeCount` |
| **E11** em **`:718`** | **`:717`** | `:718` é o `<sc-if>` — ⚠️ a correção **R8** da §I.1 estava **CERTA** |
| o 2º `isLive` em **`:716`** | **`:718`** | `:716` é o `</sc-if>` — ⚠️ a correção **R9** estava **CERTA** |
| `foco_imagem_id` em **`:1171`** | **`:1172`** | `:1171` é a abertura `fix(o, k) {` |

➡️ ⚠️⚠️ **O ACHADO NOVO: a §E contradiz a §L e a §O.** As três citam `:1143` — duas dizendo que é o
`freightMissing` (**certo**) e uma dizendo que são os 4 pares de cor (**errado**). ⚠️ **Quando DUAS
seções do FID01 citarem a MESMA linha para assuntos DIFERENTES, uma está errada, e o `sed` é o único
juiz.** E o padrão da §J se repete: `:1143` é **legítimo e load-bearing**, então quem lê a §E sem
medir acha que está no lugar certo.

**CONFIRMADOS** (casam na linha citada, medidos um por um): `:699`-`:708` · `:712`-`:721` · `:539` ·
`:590` · `:713` · `:974` · `:1089` · `:1092`-`:1094` · `:1099` · **`:1238`** · `:1295` · `:1297` ·
`:1307` · `:1172`.

⚠️ **`split(/\r?\n/)` dá 1433 e não 1432** (a última é vazia) — o gate aceita os dois.
⚠️ **ANTI-FANTASMA:** `test -e` em **cada** caminho citado → zero fantasmas; e
`tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` **confirmado inexistente** (o nono).

**A §P foi acrescentada ao `188-FID01.md`** (1148 → 1425 linhas, +277), e **`fid01-remedida` segue VERDE**
(1 passed / 13 assertions, **inalterado**).

---

## §10 — AS LISTAS CONSTANTES E O MANIFESTO

### O que eu DECLAREI

| lista | arquivo | o que entrou |
|---|---|---|
| **`ARQUIVOS_NOVOS`** | `tests/js/publicador-vocabulario-novo.test.js:45` | `PendenciasDoPainel.jsx` (tarefa 1) · `RodapeDoPainel.jsx` (tarefa 2) |
| **`JSX_DEPOIS_DA_187`** | `tests/js/publicador-fila-fechamento-187.test.js:119` | os **mesmos dois** |

⚠️ **São DUAS escriturações para `aprovacao/`, e isso é DELIBERADO** — o último conserto da família
RECUSOU criar uma terceira. **Declarar numa e esquecer a outra REPROVA**, e é esse o comportamento
certo. ⚠️ **Cada `.jsx` na MESMA TAREFA que o cria** (`188-REGUA.md` §13): o `deepEqual` de
`DIRETORIOS_FECHADOS` fica vermelho **na hora**, e árvore vermelha no HEAD pode **BARRAR DEPLOY**.
⚠️ **E pré-declarar não é possível:** `FONTES` lê todos os caminhos e **LANÇA** com arquivo
inexistente.

### O que eu **NÃO** mexi, e por quê — declarado, não omitido

| lista | por quê |
|---|---|
| **`SLOTS_DE_NO_DEPOIS_DA_187`** (as TRÊS pontas) | ⚠️ não abri `isValidElement(` novo **NA PÁGINA**. Os slots `pendencias`/`rodape` nascem em `PainelDeRevisao.jsx`, que **não é a página**, e viajam por expressão — exatamente como `colunaDeFotos`/`colunaDePreco` desde a 188-07 |
| **`ESTADO_LOCAL_DA_PAGINA`** | não acrescentei `useState(` na página: os modais são das waves 11/12, e sem eles não há estado de modal. **Há asserção** cobrando zero `useState(` nos meus `.jsx` |
| **`QUEM_USA_SHADOW_PUB_PAINEL`** | nenhum `.jsx` desta wave usa o token. **Há asserção** |
| **`LIBERADOS_DEPOIS_DA_187`** | **NENHUM tamanho novo.** Os `text-[Npx]` realmente escritos são **10.5 · 12 · 12.5 · 13 · 13.5 · 15**, e os **seis** já estavam na lista (o `21` da 188-01 não é usado aqui). **Há asserção por MEDIÇÃO** dos `text-[Npx]`, não por leitura |
| **`VARRIDOS_DECLARADOS`** | nenhum `.jsx` novo em `publicacoes/`, `identidade/` ou `visaogeral/` |

### Os ids REMEDIDOS, um por invocação, com número MEDIDO

| id | manifesto | ANTES | DEPOIS | leitura |
|---|---|---|---|---|
| `vocabulario-tamanhos` | `contagens-188-10.txt` | 632 | **639** → **646** | ⚠️ **cresce por construção** (7 sub-testes por `.jsx`) — por isso a forma é `>=` |
| `fechamento-arquivos-e-manifestos` | `contagens-187-11.txt` | 6 | **6** | **INALTERADO** (as listas moram DENTRO de um `test()`) |
| `gate-rename` | `contagens-186-01.txt` | 23 | **23** | **INALTERADO** (lê `JSX_DEPOIS_DA_187` como INVARIANTE) |
| `casca-do-painel` | `contagens-188-07.txt` | 45 | **45** | **INALTERADO** ✓ |
| `coluna-de-fotos` | `contagens-188-08.txt` | 32 | **32** | **INALTERADO** ✓ |
| `coluna-de-preco` | `contagens-188-09.txt` | 48 | **48** | **INALTERADO** ✓ |
| `tokens-e-tamanhos` | `contagens-188-01.txt` | 9 | **9** | **INALTERADO** ✓ |
| `fid01-remedida` | `contagens-188-01.txt` | 1/13 | **1/13** | **INALTERADO**, mesmo com a §P nova ✓ |
| `baselines-medidas` | `contagens-188-01.txt` | 1/7 | **1/7** | **INALTERADO** ✓ |

⚠️ **Nenhum número foi SUBIDO para calar gate, e nenhum foi rebaixado para contagem.**
⚠️ **As waves 8 e 9 têm `PainelDeRevisao.jsx` em `INTOCAVEIS` com `numstat` contra `HEAD`** — e,
**depois do commit**, o diff é vazio e os dois passam. Foi por isso que os re-rodei **depois** de
commitar.

### O manifesto `tests/gates/contagens-188-10.txt`

```
pendencias-e-rodape   js  tests/js/publicador-painel-rodape-188.test.js  ::  tests=42,37
vocabulario-tamanhos  js  tests/js/publicador-vocabulario-novo.test.js   ::  tests=>=646
```

⚠️ **`vocabulario-tamanhos` em LINHA PRÓPRIA**, forma **`>=`**, **N MEDIDO nos DOIS pontos** (639 com
o `.jsx` da tarefa 1, **646** com o da tarefa 2 — a soma fecha). O piso declarado é o **segundo**.
⚠️ **O proibido é UMA coisa só: embuti-lo num id que rode OUTROS arquivos** — há asserção provando
que a linha dele cita **exatamente UM** arquivo.
⚠️ **OS DOIS REGIMES (`42,37`), medidos pelas DUAS pontas:** **42** depois do `npm run build`; **37**
com o `mtime` da fonte adiantado por `touch` e devolvido por `touch -d` (o original capturado por
`stat -c %y` **antes**). A camada de CSS cai em **`skip` NOMEADO**, com a razão escrita.
⚠️ **Nenhum id com mais de TRÊS métodos. Nenhum id `php` neste manifesto** (declarado: a colisão de
`--filter` **não tem onde acontecer**). **Nenhum id repetido.** **Um id por arquivo de gate.**

### A BIJEÇÃO, por script, nos DOIS sentidos

```
DECLARADOS no manifesto : pendencias-e-rodape, vocabulario-tamanhos
USADOS em <verify>      : pendencias-e-rodape, vocabulario-tamanhos
ÓRFÃOS (declarado e nunca rodado)      : NENHUM
SEM DECLARAÇÃO (rodado e não declarado): NENHUM
ISCA: um id inventado seria acusado?   : SIM (sonda viva)

EXPANSOR DE ALTERNÂNCIA (obrigatório):
  test_(a|b)_188_10 → test_a_188_10, test_b_188_10
  test_simples      → test_simples
  ISCA: /test_[A-Za-z0-9_]+/ casa a alternância? NÃO  ← o furo MEDIDO pela 188-05 (SEM_ID=14)

NOMES DE TESTE no gate : 32 de topo · SEM sufixo `188_10`: NENHUM · REPETIDOS: NENHUM
```

⚠️⚠️ **E AQUI UM FURO NA MINHA PRÓPRIA SONDA AD-HOC, declarado:** a 1ª redação do script de bijeção
usava `/^test\(.(.*?)., /m`, que **para no 1º `", "` DENTRO do nome** e o trunca — ela acusou **19
nomes CORRETOS** de não ter o sufixo. ➡️ Remedido com `/^test\('([^']*)'/m` (a forma que o gate já
usava, e cujo resultado era o correto), **com a regex em ARQUIVO** e não em `php -r` pelo shell. É a
mesma família dos quatro furos que a wave 188-09 achou.

### GATE-03 e o `--autoteste`

**GATE-03: 646 / 646 pass / 0 fail / 0 skipped — VERDE.** ✓

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

## §11 — A MUTAÇÃO, com `mutar-188-10.sh`

**8 mutações · 8 MORTAS · 0 SOBREVIVENTES** (na 2ª rodada; na 1ª foram 7/1 — ver abaixo).

| # | mutação | morreu em |
|---|---|---|
| i | tirar o atributo `disabled` **deixando a classe** | 3 testes (critério 8, T-188-90, `isLive`) |
| ii | calcular `aprovavel` no cliente | 4 testes |
| iii | desenhar botão com `destino === null` | 1 teste (`hasFix && live`) |
| iv | `fotos_a_conferir` sempre âmbar | 2 testes (`:1295`, geometria) |
| v | mostrar "Salvar revisão" fora do `live` | 1 teste (`:713`/`:718`) |
| vi | trocar o literal verde de `:708` por um **sinônimo** | 3 testes (**depois do conserto**) |
| vii | `max-w-[560px]` no motivo | 1 teste (geometria do rodapé) |
| viii | omitir `versao_esperada` na escrita de aprovar | 1 teste (critério 16/17) |

**A receita, cumprida:** commit **ANTES** · `cp -p` com nome que carrega o plano
(`copia-188-10-*.jsx`) · ocorrência **travada em 1 ANTES** por string exata (⚠️ por
`grep -o | wc -l`, **não** `grep -c`, que conta LINHAS) · **linha conferida DEPOIS** · aplicação
provada por **divergência de `sha256`** em todas as 8 · restauração **DA CÓPIA** em `trap EXIT` ·
`sha256` contra `git show HEAD:` **com `tr -d '\r'` nos dois lados**, conferido **antes e depois** ·
⚠️ **`# tests` do mutado IGUAL ao do pristino (37) em todas as 8** — se caísse, o arquivo não teria
carregado e o veredicto não valeria · ⚠️ **`git checkout` NUNCA** · `sha256` do próprio script
impresso (execução paralela).

### ⚠️⚠️ A SOBREVIVENTE, INVESTIGADA — e ela achou DEFEITO REAL **na minha sonda**

A mutação **(vi)** trocou o literal de `:708` por um **sinônimo** e o gate ficou **VERDE**. A causa:

```js
assert.ok(texto(html).includes(M_PENDENCIAS.SEM_PENDENCIA_TEXTO))
```

— a asserção lia o literal **do mesmo módulo** que renderizou o HTML. **Tautologia:** trocar o
literal muda os **dois lados juntos**. A pergunta da régua — *"o que precisaria estar errado no mundo
para este gate falhar?"* — tinha a resposta **"nada"**, e eu não a fiz na hora.
⚠️ **E o mesmo furo estava em SEIS outros literais da tela, por omissão.**

**O conserto, em DUAS CAMADAS (commit `32b6500b`):**
1. caso novo **"OS LITERAIS DESTA TELA, CONGELADOS byte a byte"** — a expectativa fica **ESCRITA no
   gate**, por **igualdade de STRING** (nunca `includes`, que casa prefixo e sufixo e já mordeu cinco
   vezes), com **isca do quase-igual** incluindo o sinônimo exato da mutação, o literal com ponto
   final, o truncado, `publicacao` sem cedilha e `Próximo` sem a seta. ⚠️ Esta camada **não depende
   do protótipo** — ele não é versionado, e um gate que só vivesse lá voltaria a ser tautológico;
2. o **cruzamento** `em(708).includes(<o literal do MÓDULO>)` no bloco do protótipo, que é o **DONO**
   destes literais (ao contrário de *"Preço e resultado"*, que tem dono no servidor). Idem para
   `:700`, o glifo `✓` e os quatro rótulos de `:714`/`:715`/`:719`/`:721`.

⚠️ **Os dois regimes subiram de `41,36` para `42,37` — e o +1 é um CASO NOVO, não número subido para
calar gate.** O manifesto registra a razão. **Com ele, a mutação (vi) MORRE.**

➡️ **É a 4ª vez nesta milestone que uma sobrevivente achou defeito REAL**, e está registrada na §P do
`188-FID01.md` como lição para as waves 11-13: **literal de tela sem dono no servidor precisa de
expectativa CONGELADA no gate; ler o literal do módulo que você está testando não prova nada.**

---

## §12 — DESVIOS E DIVERGÊNCIAS, com `arquivo:linha`

### 1. ⚠️⚠️ O rodapé ganhou slot PRÓPRIO — divergência do `@param children` da 188-07

`PainelDeRevisao.jsx:564` (antes) dizia *"o resto do corpo (pendências e rodapé, plano 188-10)"*.
**MEDIDO por script:** `:712` é **IRMÃO** do corpo de `:562` (`:710` o fecha), e dentro do corpo
(`flex-1 overflow-y-auto`) o rodapé **ROLARIA PARA FORA DA TELA** — `flex:none` não o salvaria.
➡️ **Dois slots:** `pendencias` **dentro** do corpo, `rodape` **irmão** dele. A medição está escrita
no código (`PainelDeRevisao.jsx`, bloco em `RaizDoPainel`) e na §P. ⚠️ **Com todo teste de render
passando**, se tivesse ficado num slot só.

### 2. ⚠️⚠️ A página foi ligada — desvio do `files_modified`, pelo precedente da 188-09

`resources/js/Pages/Mlb/Publicador/Aprovacao.jsx` **não está** no `files_modified` do
`188-10-PLAN.md`. **Liguei de todo jeito**, e as razões:
- **a wave 188-09 fez exatamente o mesmo** (o `files_modified` dela também não lista a página, e o
  bloco da coluna de preço está lá);
- sem o fio, as duas peças existiriam no disco e **não apareceriam na tela** — e os critérios **8, 9
  e 10** falam do que a tela **MOSTRA**;
- ⚠️ **nenhuma wave de 11 a 13 tem a página no `files`** (conferido nos três planos): não haveria
  dono depois de mim.

Nada mais foi tocado na página: `onSimular={null}` (cobrado pelo gate da 188-09) e
`escreverNoPainel({` (cobrado pelo da 188-07) continuam intactos, e os dois gates **passaram**.

### 3. ⚠️ Colisão de nome na página, pega pelo **esbuild** e não por teste

A página **já tinha** um `noRodape` — o da **TABELA DA FILA** (`RodapeDaFila`, Fase 187, **NO AR**).
O build reprovou com *"The symbol 'noRodape' has already been declared"* em
`Aprovacao.jsx:935`. ➡️ Sufixo nos **NOVOS** (`noRodapeDoPainel`, `noPendenciasDoPainel`), **nunca**
renomear o da fila — e há asserção cobrando que o da fila **continue com o nome**.
⚠️ **Nenhum teste pegaria isso: só o `npm run build`.**
⚠️ A edição foi por **âncora de TEXTO de duas linhas**, com ocorrência travada em 1 (a 1ª tentativa
**ABORTOU** com `ocorrencias=2`, porque `rodape={noRodape}` existe em `:636` — o da fila — **e** em
`:967`). **Âncora por número de linha escreveria no lugar errado.**

### 4. ⚠️⚠️ DUAS sondas minhas saíram MAIS LARGAS QUE O INVARIANTE, e reprovaram CÓDIGO CERTO

- `/\.length\s*===\s*0/` sobre **todos** os arquivos do plano pegava o `reais.length === 0` da seção
  de pendências — a decisão *"nenhuma pendência"*, **nada a ver** com aprovabilidade;
- `/aprovavel\s*=\s*[^=]/` pegava o **default de parâmetro** (`aprovavel = false,`) e, na 2ª
  redação, o **atributo JSX** (`aprovavel={dados.aprovavel}`) — as duas **legítimas e de LEITURA**.

➡️ **Consertadas na RAIZ, por ancoragem no COMPORTAMENTO:** recorte ao arquivo que **DESENHA** o
botão (ele não pode nem **VER** `pendencias`/`bloqueios`/`.filter(`/`.length`) mais **LISTA CONSTANTE
(allow-list)** das duas formas de leitura, com **isca nas duas direções testada contra a sonda DE
VERDADE**. ⚠️ *"Elevar o piso"* não serviria: **piso não prova presença**.
São a **11ª e a 12ª** ocorrências da família nesta milestone.

### 5. ⚠️ A §E do FID-01 contradizia a §L e a §O do mesmo documento (`:1143` × `:1307`)

Ver §9. **O protótipo ganha no VALOR**, e `:1143` é o `freightMissing`.

### 6. ⚠️ O motivo do rodapé: `resumoDoRodape()` × o `join` de `:1297`

Ver §5. **Já decidido pelo critério 10**, e a divergência é **INTERNA ao protótipo** (`:1238` já é a
forma do produto). **NÃO é um quinto conflito**, e não houve nada a devolver.

### 7. ⚠️ A assimetria do "Conferir alteração"

Ver §7. A pendência o oferece; o aviso da coluna de preço fica para a **188-12**. **Mesmo método no
servidor**, então não há duas verdades de comportamento.

---

## §13 — O QUE EU **NÃO PODE** AFIRMAR, e o trecho do roteiro da conta 459

⚠️⚠️ **NÃO VI TELA RENDERIZADA.** Todo render desta wave é **esbuild + `react-dom/server`**: quando
esta SUMMARY diz *"a tela mostra X"*, isso significa **"o HTML gerado contém X"**. Medido e é o
ambiente: o banco local **não tem empresa com `ml_token`** (a rota dá **404**),
`pub_aprovacao_ofertas` está **VAZIA**, **a fila nasce vazia até a Fase 194** (decisão **P3** do
usuário: é honesto, **não** é defeito), e **não há navegador**.
⚠️ **A conferência visual da Fase 187 pelo usuário segue ABERTA** (PERGUNTA-VISUAL-187). Nada desta
wave a substitui nem a presume feita.

### O trecho do roteiro da conta 459 que esta wave acrescenta

1. abrir uma oferta no painel de 1140px e conferir que **a seção "Pendências" aparece abaixo das duas
   colunas**, dentro da área que rola;
2. conferir que o **rodapé de 66px fica FIXO no pé**, e que ele **não rola** com o corpo;
3. com a oferta **bloqueada**: o motivo em **vermelho** à direita (`Bloqueado: <primeiro> +N`), e
   **"Aprovar para publicação" APAGADO**, com o cursor de "proibido";
4. com a oferta **liberada**: o motivo em **verde**
   (`Fotos, preço e conteúdo conferidos`) e o botão **amarelo e clicável**;
5. clicar o botão de correção de **cada** pendência e conferir que ele leva ao lugar que o texto
   promete — ⚠️ **os de `Ajuste solicitado` e `Título longo` NÃO TÊM botão** (Fase 190/191), e o
   **texto deles tem de continuar aparecendo**;
6. numa oferta **já aprovada**: **só "Próximo ↓"** no rodapé, e o motivo com a **cor do andamento**;
7. ⚠️ **"Solicitar ajustes" ainda NÃO aparece** — o modal é da wave **188-11**, e sem handler o botão
   não é desenhado (D4). **Não é defeito.**

---

## Known Stubs

**Nenhum.** Os handlers que chegam `null` (`onAbrirModal`, `onSolicitarAjustes`, `onConflito`) **não
são stubs**: eles fazem o botão **não ser desenhado** (D4), com o dono declarado no `@param` e no
bloco da página. **Zero `onClick` vazio, zero `disabled` decorativo, zero texto "em breve".**
⚠️ O **único** `disabled` desta wave é o de "Aprovar para publicação", e ele é **o critério 8** — tem
caminho (a ação existe) e tem **o motivo escrito ao lado**, em vermelho, no mesmo rodapé.

## Threat Flags

**Nenhuma superfície nova.** Esta wave toca **ZERO `.php`**, **zero rotas**, **zero migrations** e
**zero endpoints**: ela **chama** rotas que as waves 188-05 e 188-06 já commitaram, sempre pelo
helper único e sempre com `versao_esperada`. O `T-188-SC` está provado por **FORMA**:
`git diff --numstat` em `package.json` e `package-lock.json` sai **VAZIO** (e os dois estão na lista
de INTOCÁVEIS do gate, com isca da árvore vazia).

## Self-Check: PASSADO

- `test -e` em **todos** os arquivos criados e modificados → **todos presentes**;
- os **três** commits existem: `cb8cea0f`, `5098d743`, `32b6500b` (conferidos por
  `git log --grep='(188-10)'`);
- `git diff HEAD --numstat` nos **seis** arquivos de código/teste → **VAZIO** (tudo commitado);
- os **dois** ids do manifesto **VERDES** pelo harness, com `188-10` como 1º argumento;
- os **nove** ids de outras waves re-rodados, **todos PASSOU** e **todas as contagens INALTERADAS**;
- `--autoteste` **20 casos / 0 divergência**;
- mutação **8/8 mortas**, restauração conferida contra o HEAD por `sha256`;
- `npm run build` **exit 0**, manifest **495/202** pelo JSON, isca ausente;
- ⚠️ **A SUMMARY e a §P do FID-01 ficam no commit de fechamento** (abaixo).
