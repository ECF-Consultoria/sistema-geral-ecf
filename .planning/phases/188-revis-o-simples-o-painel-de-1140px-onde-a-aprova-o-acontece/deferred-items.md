# Dívidas e itens diferidos — Fase 188

> **Escrito pelo plano 188-13 (o fechamento), em 20/10/2026.**
> ⚠️ **Toda dívida aqui tem DONO.** `a definir` **não é dono** — há gate sobre isso no
> `188-FECHAMENTO.md` §8.
> ⚠️ **Nenhum item desta lista é "trabalho faltando dentro da Fase 188".** São fronteiras do
> desenho, decisões do usuário, limites do ambiente e dívidas de **outras** fases que esta fase
> **mediu** e registra para não serem descobertas por acidente.

---

## 1. ⚠️ PERGUNTA-188-E — `recusaDoPainel()` × `back()->withErrors()`

**Dono: fase futura ou quick dedicada.**

**O que foi medido:** as recusas do painel são `response()->json(['message','regra'], 422)`
**CRUAS**, e **o `onError` do Inertia NUNCA é chamado para elas** (em `@inertiajs/core` 2.3.21 a
resposta sem o header `x-inertia` cai em `handleNonInertiaResponse()`, dispara `inertia:invalid` e
**mostra o modal de erro bruto**). ⚠️ **Sem conserto, o aviso de concorrência do critério 17 NUNCA
acenderia.**

**A decisão (orquestrador, 20/10/2026): MANTER o conserto do CLIENTE; NÃO refatorar o servidor.**
`back()->withErrors()` é a forma **idiomática** do Inertia e seria melhor a longo prazo — **mas
`recusaDoPainel` tem 18 chamadas medidas, não nove**. Trocar o formato em 18 pontos, na última wave
da fase, para substituir algo **provado e no ar** (a mutação *"(v) engole o 422"* do 188-12
**MORRE**), é **risco sem ganho**.

**O conserto, para quem o fizer:** trocar `recusaDoPainel()` por `back()->withErrors([...])` nas
**18** chamadas, de uma vez (meia troca criaria **duas convenções** na mesma tela), e então o helper
`escreverNoPainel` pode voltar a usar o `onError` nativo e **dispensar o ouvinte de `invalid`**.
⚠️ **O trade-off escrito:** ganha-se idiomaticidade e perde-se o **CAMPO `regra`** como chave
estruturada — e é por ele que a tela decide **onde marcar o erro**, em vez de casar texto em
português. **Quem fizer a troca precisa decidir onde a `regra` passa a viajar.**

---

## 2. ⚠️ PERGUNTA-188-F — a fixture do gate `casca-do-painel` não reflete o servidor

**Dono: fase futura** (muda a contagem `45,41` daquele gate).

**O que foi medido:** a fixture do gate `casca-do-painel` (plano 188-07) monta a mensagem **com o id
da regra PREFIXADO**, e o servidor **não faz isso**. ➡️ Aquele gate passa pelo **FALLBACK por
substring**, não pelo caminho primário.

⚠️ **NÃO é buraco de cobertura:** o caminho primário — ler o **CAMPO `regra`** — está provado pelos
gates da wave 188-12, **com mutação**. É **redundância que mede o caminho errado**.

**O conserto, escrito:** a fixture deve refletir o que o servidor manda de verdade —
`{message, regra}`, **sem** prefixo na mensagem. ⚠️ **NÃO mexer agora:** a contagem do gate é
`45,41`, ele **não é da wave 13**, e remedi-lo sem necessidade é expansão de território.

---

## 3. ⚠️ O rótulo legível de variação não existe no payload

**Dono: a fase que puder coordenar com o outro dev.**

**O que foi medido:** a chave do grupo é **CANÔNICA** (`COLOR=id:52049`, `cor=preto`), e o nome
legível só existe em `ResolvedorGruposImagem::rotuloDoGrupo()`, que é **`private static`** e vive em
**território VETADO** (`App\Support\Publicador`, da outra sessão).

**A decisão do usuário:** seguir **sem** o nome e **anotar a lacuna**.

⚠️⚠️ **O CUSTO FUNCIONAL REAL, escrito para ninguém o descobrir na tela:** revisando um produto com
**3 cores**, as fotos aparecem **juntas, sem dizer qual é de qual cor** — e o bloqueio de fotos cobre
**TODOS** os grupos (decisão 5 do usuário), então a pessoa **precisa** conferir todos, mas **não sabe
qual grupo é qual**.

⚠️ **E a wave 188-08 PROVOU que a chave canônica NÃO VAZA para a tela** (gate de ausência, com isca):
a lacuna é *"falta o nome"*, **não** *"aparece jargão"*.

**O conserto exige coordenação**, porque tornar `rotuloDoGrupo()` público (ou expor o rótulo no
payload) é edição em território da outra sessão. ➡️ **Mostrar o aviso de coordenação ao usuário e
perguntar antes.**

---

## 4. ⚠️ O `✓ {quem}` do protótipo não é desenhado

**Dono: Fase 190/191.**

O protótipo mostra **quem aprovou** cada foto. Isso exige `aprovada_por` / `aprovada_em`, e o
serviço de fotos da wave 188-03 **não os expõe**. ⚠️ **Não bloqueia nada nesta fase**: o **estado**
da foto aparece; só o **autor** não.

---

## 5. ⚠️⚠️ `lerSemComentarios` DESTRÓI 76% de um arquivo de teste

**Dono: orquestrador, quick dedicada.**

**MEDIDO:** o helper `lerSemComentarios` lê o `'date-fns/*'` do `external` de
`tests/js/publicador-fila-filtros-187.test.js` como **abertura de bloco de comentário**, e o arquivo
cai de **62.656 para 15.249 bytes** — **76% destruído**.

⚠️ **O helper NÃO foi consertado, e a razão é que DEZENAS de gates em produção dependem do
comportamento atual.** Há **três iscas invertidas** no repositório exigindo que ele **continue**
mastigando o glob (`'./resources/js/**/*.jsx'` chega como `'./resources/js*.jsx'`).

**A regra viva, que vale hoje:** **leitura CRUA para globs**; `lerSemComentarios` só para fonte sem
`/*` em string. ➡️ **O conserto é mudança de alcance**, com re-medição de todos os gates que o usam —
**não é trabalho de uma wave**.

---

## 6. ⚠️ AS QUATRO FALHAS QUE OSCILAM SEM MUDANÇA DE CÓDIGO

**Donos: quem mantiver a Fase 179 (2 casos) e quem mantiver a suíte do Portal (2 casos).**

| # | o teste, por SEMÂNTICA | arquivo | classe | conserto certo |
|---|---|---|---|---|
| 1 | *publicacoes criadas mede as duas janelas e a serie de trinta dias* (`:941`, asserção `:966`) | `tests/Feature/Publicador/CockpitDaContaTest.php` | **fronteira de dia** | `Carbon::setTestNow` no corpo do teste, **sem mexer no serviço** |
| 2 | *evento sem data vai para o ultimo grupo nunca para o topo* (`:1308`, asserção `:1318`) | idem | **fronteira de dia** | idem |
| 3 | um caso de `SincronizaPortalCompletoTest` | `tests/Feature/…/SincronizaPortalCompletoTest.php` | **ordem / corrida** | isolar a corrida de `storage/framework/testing` |
| 4 | outro caso de `SincronizaPortalCompletoTest` | idem | **ordem / corrida** | idem |

⚠️⚠️ **O caminho `tests/Feature/Publicador/Aprovacao/CockpitDaContaTest.php` NÃO EXISTE** — é o
**nono** caminho fantasma desta milestone, e **veio de briefing do orquestrador**. O real é **fora**
de `Aprovacao/`.

⚠️ **PROVADO que não são de código:** `git log --name-only` **vazio** em `tests/` nos intervalos em
que o veredicto mudou (vermelhas 16/10, verdes 17/10, vermelhas 18/10, verdes 19/10).
⚠️⚠️ **A hipótese do listener é HIPÓTESE** — três arquivos registram, só um chama
`flushEventListeners()`, **mas os dois experimentos que a testariam saíram VERDES**. ➡️ **NÃO a
afirme como diagnóstico.**
⚠️ **E `$this->setUp()` chamado no meio de um caso derruba o `RefreshDatabase`:** falha de ordem
**nem sempre** é sensibilidade de listener.
⚠️ **NÃO criar `REF_PHP_FALHAS`** — criar um limiar de falhas aceito para calar isto seria abrir a
porta dos fundos que o harness existe para fechar.

---

## 7. ⚠️ A ESCRITURAÇÃO DUPLA do diretório `aprovacao/`, deliberadamente não unificada

**Dono: orquestrador ou Fase 191.**

Hoje o diretório `aprovacao/` é escriturado em **DOIS** lugares: `ARQUIVOS_NOVOS` (em
`tests/js/publicador-vocabulario-novo.test.js`) e `JSX_DEPOIS_DA_187`.

⚠️ **O conserto da Fase 186 (`091340b6`) RECUSOU criar a TERCEIRA escrituração** e apontou para a
**invariante que já existia**. **A razão:** lista duplicada demais **recria o problema** — cada lugar
esquecido é **uma reprovação de trabalho legítimo**. ➡️ **A dupla ficou, de propósito**; unificá-la é
trabalho de quem for dono daquela camada, e exige re-medir os gates das duas.

---

## 8. ⚠️ A `188-FID01.md` tem DUAS seções rotuladas `§P`, com o MESMO ordinal

**Dono: Fase 189** (ela já vai estender aquele documento).

**MEDIDO neste fechamento:** a seção da wave **188-06** e a da wave **188-10** levam **a mesma letra
`P`** e **o mesmo ordinal "SÉTIMA VEZ"**. A sequência real é
**§K(03) · §L(04) · §M(05) · §N(08) · §O(09) · §P(06) · §P(10) · §Q(11) · §R(12)** — **NOVE**
confirmações da lição da §J.

⚠️ **Registrado, NÃO consertado:** o `188-FID01.md` não está nos `files` do plano 13, e mexer nele
mudaria a contagem do gate `fid01-remedida` (`1/13`), que **não é daquela wave**. ➡️ **A próxima
letra livre é `§S`.**

---

## 9. O custo da composição por linha — **mitigado**, não eliminado

**Dono: operação.**

A pergunta **P2** da Fase 187 foi respondida com **pré-aquecimento** da tarifa do ML por tarefa em
segundo plano. ⚠️ **O risco foi mitigado, não eliminado:** o painel continua resolvendo a composição
por oferta, e `aprovar()` **LÊ** a tarifa real no GET público (decisão 9, cache de 1 h).
⚠️ **Prometer "zero requisição" seria um gate que o próprio desenho contradiz.**

---

## 10. A dívida **DIV-01 da Fase 186** segue aberta — e **não é desta fase**

**Dono: orquestrador ou Fase 191.**

3 das 4 estruturas declaram a fase dona **só fora do docblock**. Registrada desde o fechamento da
Fase 186 e reconfirmada pelo da 187. **Nada nesta fase a resolve nem a piora.**

---

## 11. ⚠️ Divergências reproduzidas **DE PROPÓSITO** — não são lacunas

| divergência | por quê |
|---|---|
| **D7** (o `title` do quadrado em `regenerating` diz *"a conferir"*) | o protótipo é a **autoridade do rótulo** |
| **D9** (o checkbox do cabeçalho marca linhas em `preparando`) | a trava real é `aprovarEmLote` **no servidor**, e o modal mostra ✓/× **com o motivo** |
| **D-ANEL** (protótipo `.8s` × token `.7s`) | criar um `.8s` ao lado criaria **duas verdades**; a wave 188-08 usa a **classe** e põe a duração do protótipo **por `style`** |
| a legenda do rodapé não lista o anel de *falha* nem o quadrado de *regenerando* | **FIEL ao protótipo**; acrescentar seria **adaptar o layout** |

---

## 12. ⚠️ O que esta fase **NÃO** antecipou, com gate de ausência

| o que | fase dona | gate |
|---|---|---|
| **"⤢ Ampliar"** | **189** | gate de ausência (⚠️ a 189 vai **invertê-lo**, só a parte do Ampliar) |
| **"Abrir revisão detalhada ↗"** | **190/191** | gate de ausência (PERGUNTA-188-B3) |
| os destinos de `ajuste_em_aberto` / `titulo_longo` | **190/191** | `destino = null`, sem botão (PERGUNTA-188-B) |
| **"✦ Gerar novamente com IA"** | **192** | gate de ausência (⚠️ **continua** depois da 189) |
| a **escrita no Mercado Livre** | **193** | `Http::fake` + `preventStrayRequests()`, `MlPublicacaoService` com `numstat` ZERO |
| a **primeira oferta na fila** | **194** | a fila nasce vazia, e isso é **honesto** (decisão P3) |

⚠️ **Botão sem destino é D4 violado** — e *"desenhar desabilitado com explicação"* foi **OFERECIDO e
RECUSADO** pelo usuário em 18/10/2026.
