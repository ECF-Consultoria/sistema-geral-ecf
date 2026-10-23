# Tombamento da revisão (v27.0) — o que cai, o que FICA DE PÉ, e a pergunta que o plano novo tem de responder

**Decisão do usuário em 23/10/2026**, nas palavras dele:

> *"Os planos que tínhamos referente à parte de revisão serão completamente descontinuados porque o
> outro dev já [fez] a parte de revisão de outro jeito mais simples, por isso não terá mais a
> necessidade de darmos continuidade no plano que estávamos executando. O que iremos focar agora é o
> agente. Irei trazer outro plano de implementação e outro handoff."*

➡️ **A milestone v27.0 ("Aprovação humana antes de publicar") é TOMBADA na parte de revisão.** O foco
passa a ser o **Agente ECF Publicador**, que o usuário vai replanejar.

⚠️ **Este documento NÃO apaga nada.** Tombar é registrar e parar — não remover. Nada foi deletado, e
a razão está na §2: **o Agente está de pé SOBRE o portão.**

---

## §1 O que foi construído, e onde está

| fase | o quê | estado |
|---|---|---|
| **186** | a espinha: 8 estados, bloqueios, composição financeira, a **única porta** do andamento | ✅ **em produção** (`a4c4187f`) |
| **187** | a fila "Aguardando aprovação" + **a aba na tela de Publicações** | ✅ **em produção** (`7791d7aa`, 18/10) |
| **188** | o painel de revisão de 1140px + 4 modais | ✅ **em produção** (`e250f9fb`, 20/10) |
| **189** | o lightbox com zoom 2,5× | ⚠️ **no código, NÃO publicado** (44 commits) |
| **193** | aprovar → **publica de verdade** | ⚠️ **no código, NÃO publicado** (22 commits) |
| 190 / 191 | revisão detalhada | ⛔ **já estavam fora de escopo** (decisão 20, 20/10) |
| 192 / 194 | gerar com IA · ligar a automação | ⛔ **nunca executadas** |

**Inventário medido do portão:** **23** serviços · **42** componentes `.jsx` · **11** modelos ·
**5** migrations · **45** testes PHP · **28** gates JS.

---

## §2 ⚠️⚠️ O QUE **NÃO PODE** SER TOMBADO — porque o AGENTE ESTÁ DE PÉ SOBRE ELE

**Medido em `app/Services/Publicador/Agente/` (9 arquivos):**

```
use App\Models\PubAprovacaoOferta;
use App\Models\PubAprovacaoConjunto;
use App\Models\PubAprovacaoImagem;
use App\Services\Publicador\Aprovacao\AndamentoDaOfertaService;
```

| o que o Agente usa | onde | como |
|---|---|---|
| **`PubAprovacaoOferta`** | `PreparadorDaAprovacao:148` | **CRIA** a oferta (`::create`) — é o primeiro código de produção que cria |
| **`PubAprovacaoConjunto`** e **`PubAprovacaoImagem`** | idem | cria os conjuntos e as fotos `pending` |
| **`AndamentoDaOfertaService`** | `LeituraDoAgente:31` (**injeção de construtor**) e `ExecucoesDoAgente:139` | lê o andamento e **move** a oferta pela **única porta** |
| a **tabela** `pub_aprovacao_ofertas` | — | é onde o Agente grava o resultado do preparo |

➡️⚠️ **Conclusão: a ESPINHA da Fase 186 e o `AndamentoDaOfertaService` ficam.** Removê-los quebraria
o Agente — exatamente o que o usuário quer manter. **Tombar a revisão ≠ apagar o portão.**

---

## §3 ⚠️⚠️ A PERGUNTA QUE O PLANO NOVO TEM DE RESPONDER — e ela é estrutural

**Medido, e é o achado mais importante deste documento:**

```
PreparadorDaAprovacao:102   ->  $this->andamento->mover($oferta, AGUARDANDO_APROVACAO, null);
PreparadorDaAprovacao       ->  NUNCA aprova (sem chamada de aprovação)
AndamentoDaOfertaService    ->  tem o PublicacaoDoPortaoService ligado: a publicação dispara NA APROVAÇÃO
```

➡️ A cadeia de hoje é:

```
Agente prepara  →  oferta em `aguardando_aprovacao`  →  ALGUÉM APROVA  →  Fase 193 publica
                                                         ^^^^^^^^^^^^^
                                                   e é ISTO que foi tombado
```

⚠️⚠️ **Se a revisão sai e nada toma o lugar dela, a oferta do Agente fica parada em
`aguardando_aprovacao` para sempre.** O Agente prepara e **nada publica**.

**As três saídas possíveis, e a escolha é do usuário no plano novo:**

| saída | o que implica |
|---|---|
| **(a)** a aprovação passa a acontecer **na revisão simples do outro dev** (o aceite no Planejamento) | ⚠️ alguém tem de **ligar** aquele aceite ao `mover(APROVADO)` do portão — é uma ponte nova, pequena, mas tem de existir |
| **(b)** o **Agente aprova sozinho** | ⚠️⚠️ **contraria a decisão da gestão de 14/10** (*"que tenha uma aprovação antes de ir para o ar"*), que é a razão de a milestone ter existido. **Se for isso, é decisão de gestão, não técnica** |
| **(c)** o Agente **deixa de usar o portão** e publica por outro caminho | ⚠️ então os 3 modelos e a única porta saem do Agente — e aí **sim** o portão inteiro pode ser tombado. **Mas é reescrever o AG-03, que já está pronto e commitado** |

➡️ **Nada aqui é decidível por mim.** ⚠️ **Mas o plano novo precisa dizer qual é**, porque as três
levam a códigos diferentes.

---

## §3b ✅ A T-1 FOI RESPONDIDA PELO USUÁRIO — e ela traz DOIS fatos que bloqueiam o plano novo

**Palavras dele, em 23/10:**

> *"Correto, o agente depende da tela de revisão, mas agora a tela de revisão será a que já está em
> produção construída pelo outro dev, onde tem a prévia do anúncio. No plano que irei mandar tem
> melhorias nessa tela também."*

➡️ **É a saída (a) da §3:** a aprovação sai do portão e passa para a **tela da Prévia**. ⚠️ **E segue
valendo o que a §3 mediu: alguém tem de LIGAR aquele aceite ao `mover(APROVADO)` do portão**, senão a
oferta que o Agente prepara fica parada — **a ponte é pequena, mas tem de existir, e o plano novo
precisa nomeá-la.**

### ⚠️⚠️ FATO 1 — **A TELA NÃO ESTÁ NA NOSSA ÁRVORE**

**Medido:** o commit que a criou, **`78b6d7ae`** (*"conta só com Produtos e etapa 5 'Prévia' como no
Mercado Livre"*), **NÃO é ancestral do nosso HEAD** — está só no `origin/main`, entre os **54 commits
que nos faltam**. Os arquivos dela:

| arquivo | tamanho |
|---|---|
| `app/Services/Publicador/PreviaDoAnuncioService.php` | **330 linhas, novo** |
| `resources/js/Components/Publicador/Mesa/AnuncioNoMl.jsx` | **259 linhas, novo** |
| `resources/js/Components/Publicador/Mesa/EtapaPrevia.jsx` | **133 linhas, novo** |
| `Mesa/Publicar.jsx` · `header/AbasDoHeader.jsx` · `config/publicador.php` · `CabecalhoPublicadorService` · `EditorRascunhoService` | alterados |

➡️⚠️⚠️ **CONSEQUÊNCIA: o MERGE deixou de ser só pré-requisito de DEPLOY e passou a ser pré-requisito do
PRÓPRIO PLANO.** O plano novo propõe **melhorias numa tela que não existe aqui** — qualquer medição,
ponteiro de linha ou gate escrito contra a nossa árvore de hoje mediria **o vazio**.

⚠️ **E a tela está sendo trabalhada AGORA:** há **mais cinco** commits de conserto da Prévia no
`origin/main`, um deles marcado **"demo 23/10"** — **hoje**. ➡️ **Medir contra cópia velha é medir alvo
em movimento**, e esta milestone já pagou esse preço várias vezes.

### ⚠️⚠️ FATO 2 — **A TELA MORA EM TERRITÓRIO QUE ERA VETADO PARA NÓS**

`EtapaPrevia.jsx` e `AnuncioNoMl.jsx` ficam em **`resources/js/Components/Publicador/Mesa/`** — o
**editor**, que **todos** os briefings desta milestone listaram como **NÃO TOCAR** (*"o editor:
`Pages/Mlb/Publicador/Editor.jsx` e `resources/js/Components/Publicador/Mesa/` — SEM o `Mlb`"*),
porque era **território do outro dev, ativo**.

➡️⚠️ **O plano novo MUDA essa fronteira**, e isso tem de ser dito com todas as letras, porque:

1. **os briefings futuros não podem carregar o veto antigo** — ele faria o executor **PARAR** diante do
   próprio trabalho que o plano manda fazer;
2. ⚠️ **é território COMPARTILHADO e ATIVO**: o outro dev commitou ali **hoje**. ➡️ **A fronteira nova
   precisa ser combinada com ele**, arquivo por arquivo, **antes** de a primeira wave começar — e o
   canal já existe (`.planning/coordenacao/`), com o protocolo de aviso de suíte longa já acordado.

---

## §4 ⚠️ A ABA VISÍVEL EM PRODUÇÃO — a premissa mudou

**Medido: a aba "Aguardando aprovação" está em `origin/main`**, logo **no ar desde 18/10**, com
contador real — os três pedaços: `AbasDaPagina.jsx`, o contador em `MlbAnuncioController:1232` e o
`hrefAprovacao` em `MeusAnuncios.jsx:1190`.

⚠️ **Em 21/10 o usuário decidiu MANTÊ-LA visível** — mas naquele momento ela apontava para uma área
**em construção**. ➡️ **Com o tombamento, ela aponta para uma área DESCONTINUADA.** A premissa da
decisão mudou, então ela merece ser reperguntada.

⚠️ **E ocultar não é passar o destino como nulo:** medido, com `destino = null` o componente renderiza
um **`<span>` morto** no lugar do link — a aba **continua aparecendo**, só não clicável. Isso é o
padrão "decorativo" que a régua do projeto proíbe. **Ocultar de verdade é não renderizar.**

---

## §5 Os 66 commits não publicados: o que ainda serve

| fase | serve ao Agente? | por quê |
|---|---|---|
| **189** (lightbox) | ❌ **não** | é UI de revisão humana: só se alcança pelo painel, que só se alcança pela fila |
| **193** (aprovar → publica) | ⚠️⚠️ **PROVAVELMENTE SIM** | é o **único caminho que leva a oferta ao ar**. Se a saída da §3 for **(a)**, a 193 é **carga** — sem ela, aprovar não publica |

➡️⚠️ **Recomendação: NÃO descartar a 193 antes de a §3 estar respondida.** Ela é servidor puro (zero
`.jsx`), não acrescenta nada visível, e é o que transforma "aprovado" em "no ar".

---

## §6 O que fica REGISTRADO (e não apagado)

- **O código fica.** Nada removido, nada revertido. A espinha é carga do Agente (§2); a 193 pode ser
  carga (§5); e a UI de revisão não atrapalha nada enquanto a fila está vazia.
- **Os gates ficam verdes.** 45 testes PHP e 28 gates JS seguem protegendo código que **existe em
  produção**. ⚠️ **Apagar teste de código vivo é pior que teste ausente** — a régua do projeto.
- **Os artefatos de planejamento ficam**, marcados como tombados: é o registro do que foi medido, e
  várias medições valem para o Agente (as armadilhas do `PublicacaoService`, a fila `high`, o R2, os
  erros de briefing).
- ⚠️ **O que PARA:** nenhuma wave nova de revisão, nenhum plano de 192/194 como estavam, e a
  conferência visual das Fases 187/188/189 deixa de ser pendência de entrega — ⚠️ **mas as quatro
  seguem como registro de que ninguém viu aquelas telas renderizadas.**

---

## §7 ⚠️ Decisões em aberto, todas do usuário

| # | decisão | onde morde |
|---|---|---|
| ~~**T-1**~~ | ✅ **RESPONDIDA em 23/10: a aprovação passa para a TELA DA PRÉVIA**, a que o outro dev já pôs em produção (saída **(a)** da §3). ⚠️ **Mas a PONTE até o `mover(APROVADO)` do portão ainda tem de existir** — sem ela a oferta do Agente fica parada. **O plano novo tem de nomeá-la.** ⚠️ **E ela traz DOIS bloqueios: a tela NÃO está na nossa árvore, e mora em território que era VETADO — ver §3b** | **já define o plano novo** |
| **T-2** | A **aba visível** em produção: fica, sai, ou nasce desligada? | a premissa de 21/10 mudou (§4) |
| **T-3** | A **Fase 193** (aprovar → publica) **vai ao ar** ou é descartada? | depende de T-1 (§5) |
| **T-4** | A **Fase 189** (lightbox, 44 commits) vai ao ar junto no próximo deploy, ou fica fora? | é inofensiva, mas é código de feature tombada |
| **T-5** | A **Fase 194** sai do ROADMAP ou é absorvida pelo plano novo do Agente? | ela era *"ligar a automação atrás do portão"* — o Agente **é** essa automação |

---

## §8 ⚠️ O que o plano novo do Agente herda de MEDIDO — aproveite, não meça de novo

**Caminho da publicação, medido e corrigido (eu errei isto duas vezes):**
`PubAprovacaoOferta(aprovado)` → `FilaPublicacaoService::agendar()` → `publicador:fila-publicacao`
(`AgendadorDaFila`) → `PublicacaoService::iniciar()` → `PublicarRascunhoJob` (fila **`high`**) →
`executarFatia()`, **o único `POST /items`**, em **`app/Services/Publicador/PublicacaoService.php:442`**.
⚠️ **O `MlPublicacaoService` NÃO é esse caminho** — ele recebe `MlAnuncioRascunho`, do assistente
**antigo**. ⚠️ **E o arquivo `PublicacaoService.php` NÃO fica em `app/Services/Mlb/Publicacao/`.**

**Disciplina que já está implementada e não deve ser reescrita** (`PublicacaoService`):
`ContasLiberadas::exigir()` **antes de qualquer gravação** (fail-closed, lista vazia = ninguém),
item **`SENT` antes do POST**, `4xx → FAILED`, `5xx`/rede **→ `UNKNOWN`**, **RN-93** por SKU,
`retryUntil(30min)` no **job** (`:57`, **não** no serviço). ⚠️ **Nenhum feature-flag novo: a trava é
`ContasLiberadas`.**

**A fila é REDIS em produção** — a tabela `jobs` dá **0** e **engana**. Job interativo/de webhook vai
para **`high`**: em 16/09, **179 jobs de acervo na `default` seguraram um webhook por HORAS**.
⚠️ **Depois do `deploy.sh` (que só reinicia `ecf-worker:*`), é `php artisan queue:restart` que pega
`ecf-worker-high` e `ecf-worker-creative` sem matar job em andamento.**

**Armadilhas de produto que o portão já resolveu, e que o Agente herda:**
- ⚠️ **Um produto está em UMA fila por vez** (unique `pubfilai_produto_ativo_uq`), e a fila publica
  **TODOS os alvos ativos** (`PayloadBuilderUserProducts:60` itera `alvosAtivos() × variantesAtivas()`)
  → ⚠️⚠️ **aprovar uma modalidade publicaria a irmã não revisada** (decisão 23). **Se o plano novo
  mexer no enfileiramento, este buraco volta.**
- ⚠️ **`PUBLICADO => []` é terminal por estrutura**, e **não existe nenhum `PUT` que COMECE em
  `/items`** no Publicador → **editar anúncio no ar não é capacidade que o sistema tenha** (decisão 22).
  ⚠️ **Existem TRÊS `PUT`**, e um usa `/seller-promotions/items/`, que **contém** `/items/` — **sonda
  por substring acusa trabalho certo**.
- ⚠️ **`ciente = true` significa "houve aviso do ML e alguém o aceitou"** (decisão 24). O `RN-90`
  recusa publicar com avisos sem isso, **e a caixa "Estou ciente" só existe na tela de LOTE**.

**Armadilhas de medição nesta árvore** (custaram horas):
- ⚠️ **A suíte PHP completa NÃO é medível nesta máquina** (Fatal de memória em processo **FILHO**;
  `-d memory_limit=-1` não resolve) → meça o **recorte** e **diga isso**.
- ⚠️ **`grep -c` conta LINHAS, não ocorrências** · **`cmd | tail` devolve o exit do `tail`** (cinco
  waves caíram nisso) · **heredoc come a barra invertida** · **o `STATE.md` é CRLF** · **o Python do
  Windows não entende `/c/…`**.
- ⚠️ **Gate ancorado em número que muda legitimamente é frágil: DEZESSETE consertados na raiz nesta
  milestone, ZERO por elevação de número.** O 17º era um gate que **se auto-invalidava** por janela de
  `git log -80`.
- ⚠️⚠️ **Id declarado em manifesto que nenhum plano invoca NUNCA RODA** — foram **quatro** na Fase 193,
  e um deles protegia uma **decisão do usuário**. ➡️ **A bijeção `id ↔ <verify>` tem de ser prova do
  plano**, não só `id ↔ método`.
- ⚠️ **"Não commitou" ≠ "não está trabalhando":** o território por commit não vê outra sessão **rodando
  suíte**. Combinado com a outra sessão: **quem vai rodar suíte longa avisa antes.**

---

## §9 Estado da árvore em 23/10/2026

```
origin/main   e20d7541      HEAD local   1e7d0111
100 commits nossos NÃO publicados   ·   54 commits deles que NOS FALTAM
```

⚠️⚠️ **MERGE É PRÉ-REQUISITO DE QUALQUER DEPLOY:** um deploy do HEAD de hoje **sem** integrar o
`origin/main` **reverteria 326 arquivos** em produção, incluindo o trabalho do outro dev.
⚠️ **O usuário autorizou o merge aqui, mas a outra sessão — corretamente — exige que a autorização
venha NA SESSÃO DELA.** Autorização de usuário **não se repassa entre sessões**.

**Nada do usuário nem do outro dev está só na árvore local:** os dois commits que não pertenciam a
nenhuma fase têm **gêmeos em produção**, e num deles o `patch-id` é **idêntico**
(`2a90ccecfd84a2b8`) — conteúdo byte a byte.
