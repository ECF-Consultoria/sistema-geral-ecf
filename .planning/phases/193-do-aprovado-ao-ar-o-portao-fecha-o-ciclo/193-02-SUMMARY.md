---
phase: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
plan: 02
subsystem: publicador-aprovacao
tags: [enfileiramento, gancho, granularidade, decisao-23, gates, mutacao, territorio]
requires:
  - "193-01 (a ausencia de migration provada por assercao sobre o schema vivo)"
  - "Fase 186 (as 3 colunas de ligacao, a unica porta e o gancho aoAprovar vazio)"
  - "Fase 188 (a rota de aprovar e a de tentar de novo)"
provides:
  - "PublicacaoDoPortaoService::enfileirar() — anexa ao item vivo ou agenda, nunca duplica"
  - "o gancho aoAprovar() LIGADO: aprovar enfileira na hora, dentro da transacao da aprovacao"
  - "a GUARDA da modalidade irma (decisao 23): sem todos os alvos ativos aprovados, a oferta nao entra na fila"
  - "contagens-186-04.txt REMEDIDO nos ids unica-porta (41->43) e oferta-do-portao (65->67)"
affects:
  - "193-03 (o reconciliador: e ele que liga as irmas ja aprovadas pelo caminho do ANEXO)"
  - "193-04 (tentar de novo: enfileirar PRIMEIRO, mover depois)"
tech-stack:
  added: []
  patterns:
    - "assertar ESTADO, nunca flag sensivel a quantas vezes a funcao foi chamada"
    - "assercao COLETORA quando o laco e sobre lista de tamanho variavel"
    - "nivel de transacao medido contra REFERENCIA de fora, nunca contra zero"
    - "sonda de ausencia pela UNIAO das duas formas que ja existem no projeto"
key-files:
  created:
    - app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php
    - tests/Feature/Publicador/Aprovacao/EnfileirarAoAprovarTest.php
    - tests/gates/contagens-193-02.txt
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-02-SUMMARY.md
  modified:
    - app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
    - tests/gates/contagens-186-04.txt
decisions:
  - "Decisao 23 do usuario IMPLEMENTADA: sem todos os alvos ATIVOS aprovados, a oferta fica aprovado SEM fila_item_id, com o motivo real no historico"
  - "ciente=true no agendamento: o clique humano em Aprovar para publicacao E a ciencia (o portao nao tem caixa Estou ciente, e false criaria laco sem saida)"
  - "a recusa entra no historico como ator Sistema e tipo sys, nunca error (o rotulo de error e Falha na publicacao, e nada falhou ao publicar)"
  - "enfileirar() escreve fila_item_id SO na oferta que recebeu; a irma ja aprovada e ligada pelo ANEXO do reconciliador (193-03)"
metrics:
  duration: "~5 h"
  completed: "2026-10-21"
  tasks: 2
  commits: 4
---

# Fase 193 Plano 02: Aprovar enfileira — o gancho ligado, e a guarda que o usuário decidiu no meio da wave Summary

O gancho `aoAprovar()` que a Fase 186 deixou nomeado está **ligado**: aprovar enfileira na hora,
**dentro da mesma transação** que gravou a prova do que foi aprovado, reusando a fila que já existe.
A granularidade ficou resolvida — **N ofertas, UM item de fila por produto** — e, no meio da wave, o
usuário decidiu a **guarda da modalidade irmã** (decisão 23), que também está implementada e provada.

⚠️ **A campanha de mutação achou DOIS furos nas minhas próprias sondas**, e os dois foram consertados
**na raiz**, acrescentando prova — nenhum por elevação de número.

---

## §1 — A PARTIDA, E O HEAD QUE SE MOVEU TRÊS VEZES

```
PARTIDA: e341d3ef   (capturada na PRIMEIRA ação, antes de qualquer leitura — confere com o esperado)
HEAD no fim do plano: c4be8e9d
```

⚠️⚠️ **O HEAD SE MOVEU TRÊS VEZES durante esta wave**, e isso não é detalhe: foi a razão pela qual o
`193-01` perdeu 19 minutos de medição. A PARTIDA continua sendo **referência de leitura** e **em
nenhum momento foi usada como extremo de range para medir território** — o território saiu do
**escopo do assunto no `%s`** (§9).

| commit | de quem | o que | quando entrou |
|---|---|---|---|
| `ea203f66` | ⚠️ **ALHEIO** | `docs(quick-261020-ozm)`: a migration e a concorrência do agente no MariaDB local | antes da minha tarefa 1 |
| `05c26309` | ⚠️ **ALHEIO** | `feat(193)`: **a decisão 23 registrada** no `ROADMAP.md` e na `193-REGUA.md` | antes da minha tarefa 1 |
| **`5e9d242c`** | **MEU** | `feat(193-02)`: `enfileirar()` anexa ou agenda + a guarda da decisão 23 | tarefa 1 |
| `d0a37eb3` | ⚠️ **ALHEIO** | `fix(quick-261020-s95)`: a tela do agente conferida no navegador | entre as minhas tarefas 1 e 2 |
| **`923bf8a4`** | **MEU** | `feat(193-02)`: o gancho `aoAprovar()` ligado | tarefa 2 |
| **`c4be8e9d`** | **MEU** | `fix(193-02)`: os dois furos que a mutação achou, consertados na raiz | pós-mutação |
| (este) | **MEU** | `docs(193-02)`: a SUMMARY | — |

---

## §2 — ⚠️⚠️ A DECISÃO 23, QUE CHEGOU NO MEIO DA WAVE

**Sequência real, para o registro:** eu já tinha escrito o `enfileirar()` quando o orquestrador
levantou o risco de que enfileirar *"o produto inteiro"* publicaria a **modalidade IRMÃ que ninguém
aprovou*. A instrução inicial foi **medir e NÃO implementar** (a decisão era do usuário); eu medi,
deixei o **ponto de decisão explícito e nomeado** no código (`PERGUNTA-193-F`, com o predicado
isolado e o motivo já escrito), e **minutos depois o usuário decidiu: IMPLEMENTE**.

### Os três pontos do risco, **conferidos por mim** (não aceitos do briefing)

| # | o que | medido por mim |
|---|---|---|
| 1 | `pub_rascunho_alvos.ativo` é `boolean` **default true** | `2026_10_01_200000_create_publicador_tables.php:67` → `$t->boolean('ativo')->default(true);` ✅ |
| 2 | `alvosAtivos()` filtra por `Alvo::$ativo` | `RascunhoSnapshot.php:162-165` → `array_filter($this->alvos, fn (Alvo $a) => $a->ativo)` ✅ |
| 3 | o payload monta um anúncio por **alvo ATIVO** | `PayloadBuilderUserProducts.php:60` → `foreach ($r->alvosAtivos() as $indiceAlvo => $alvo)` × `variantesAtivas()` ✅ |

➡️ **Confirmado:** com Clássico e Premium os dois ativos, aprovar só o Clássico e enfileirar o
produto levaria o **Premium ao ar sem revisão**.

### O que foi implementado, e por que não inventa nada

A guarda é o **mesmo mecanismo da decisão 21**: a oferta fica em **`aprovado`**, **SEM
`fila_item_id`**, o **motivo real** vai ao histórico **nomeando a modalidade que falta**, e o
reconciliador reavalia. ⚠️ **Nenhum estado novo, nenhuma coluna nova, nenhum 7º toast** (os 6 de
VOC-08 são fechados) e **nenhum** dos quatro literais proibidos — o rótulo do protótipo para
`aprovado` é *"aguardando publicação"*, que **já é a verdade** deste caso.

⚠️ **Ancorada em `pub_rascunho_alvos.ativo`, nunca em contagem fixa de alvos.** Produto com **UM**
alvo ativo — o único que o agente encaminha hoje — **continua enfileirando**, e há caso de teste nas
duas pontas.

### ⚠️ O teste mínimo que o usuário pediu, com a saída colada

```
test_modalidade_irma_ativa_nao_aprovada_barra_a_entrada_na_fila_193_02
  Clássico aprovado + Premium ATIVO e não aprovado  -> itens de fila do produto = 0   ✅
  depois aprova o Premium                           -> itens de fila do produto = 1   ✅  (ISCA +)
  o histórico nomeia a modalidade: "...falta aprovar a outra modalidade deste produto,
      que está ativa e iria ao ar junto — Premium (Aguardando aprovação)."              ✅
  nenhum texto de evento contém `gold_pro` (jargão não vai para a tela)                 ✅

test_um_alvo_ativo_so_e_alvo_inativo_nao_barram_o_enfileiramento_193_02
  produto com UM alvo ativo               -> 1 item (o caminho do agente NÃO quebrou)   ✅
  Premium existente mas INATIVO           -> 1 item (alvo inativo não vai ao ar)        ✅
  ATIVANDO o Premium, o MESMO cenário     -> 0 itens  (ISCA −: a guarda LÊ `ativo`)     ✅
```

⚠️ **E a mutação prova que a guarda não é enfeite:** desligá-la (`if (false && $pendentes !== [])`)
**MATA** o gate — `Tests: 2 failed (12 assertions)` (§8, m2).

### ⚠️ O que a guarda **NÃO** fecha, declarado

Ela protege o instante de **ENTRAR** na fila. Se a segunda modalidade for ativada no editor **depois**
de o item já estar vivo, o item em voo publica os dois — e aí não há o que recusar, porque a
publicação já começou. Fechar essa janela é assunto da rotina de mudanças relevantes (plano
**186-08**, que incrementa a `versao` e **reabre** a aprovação), **não desta fase**. Está escrito no
docblock do serviço.

---

## §3 — ⚠️⚠️ A PROVA DO GLOB: O SERVIÇO MORA NA RAIZ, E OS DOIS IDS DA 186-04 SUBIRAM

O serviço novo é `app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php` — **na RAIZ**,
porque a camada B do gate `unica-porta` percorre `glob('app/Services/Publicador/Aprovacao/*.php')`,
que **não é recursivo**: um serviço em `Aprovacao/Publicacao/` **não seria varrido** e aquele gate
**passaria por omissão**.

### A REMEDIÇÃO, com as DUAS saídas coladas

**ANTES (manifesto ainda em 41/65, o arquivo novo já no disco):**

```
═══════ ANTES — 186-04 unica-porta ═══════
EXIT_REAL_DO_HARNESS=1
gate 'unica-porta' (php): …, esperado 1 passed / 41 assertions
  REPROVOU: nao casou 'Tests: 1 passed (41 assertions)'.
  Tests:    1 passed (43 assertions)

═══════ ANTES — 186-04 oferta-do-portao ═══════
EXIT_REAL_DO_HARNESS=1
gate 'oferta-do-portao' (php): …, esperado 3 passed / 65 assertions
  REPROVOU: nao casou 'Tests: 3 passed (65 assertions)'.
  Tests:    3 passed (67 assertions)
```

**DEPOIS (manifesto remedido, valores antigos REMOVIDOS):**

```
═══════ DEPOIS — 186-04 unica-porta ═══════
EXIT_REAL_DO_HARNESS=0
gate 'unica-porta' (php): …, esperado 1 passed / 43 assertions
  Tests:    1 passed (43 assertions)
PASSOU: gate 'unica-porta'

═══════ DEPOIS — 186-04 oferta-do-portao ═══════
EXIT_REAL_DO_HARNESS=0
gate 'oferta-do-portao' (php): …, esperado 3 passed / 67 assertions
  Tests:    3 passed (67 assertions)
PASSOU: gate 'oferta-do-portao'
```

➡️ **Os dois subiram EXATAMENTE +2** (41→43 e 65→67), que é a assinatura da camada B: **2 asserções
por arquivo**. ✅ **Se não tivessem subido, era PARADA** — significaria que o arquivo caiu fora do
glob. O `oferta-do-portao` carrega o mesmo +2 porque o filtro dele **inclui** o método de
`unica-porta`.

⚠️ **O diff de `contagens-186-04.txt` é de exatamente 2 linhas** (`git diff --numstat` → `2 2`), e a
razão da remedição está escrita **em linha** nas duas, com as palavras *"REMEDIDO, nao ajustado"* e a
frase *"Se este numero NAO tivesse subido, era PARADA"*.

---

## §4 — A PROVA DE QUE O SERVIÇO NOVO NUNCA ESCREVE `andamento`

Medido com os dois leitores (texto **CRU** × código **SEM COMENTÁRIO** por `token_get_all`), contando
**ocorrências** por `substr_count`/`preg_match_all` — nunca por `grep -c`, que conta **LINHAS**:

```
=== app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php ===
  'andamento' => (limpo)       : 0
  sonda larga andamento (limpo): 0      <- /'andamento'\s*=>|->andamento\s*=(?!=)/
  sonda larga andamento (CRU)  : 0
  dispatch( (limpo)            : 0
  PubAprovacaoOferta (limpo)   : 9      <- o 2o filtro do gate 186-09 CASA, e por isso a 1a sonda importa
  Http:: (limpo)               : 0

=== app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php ===
  'andamento' => (limpo)       : 1      <- ele É a porta: tem direito
```

➡️ O serviço **chama `mover()`** em vez de escrever o estado, e **não contém o literal** que o poria
na lista de expositores do `andamento-um-lugar-so`. ⚠️ **Não entrei em lista de expositor e não
renomeei chave para fugir de gate** — a saída certa era simplesmente **não expor andamento**, e é o
que está feito: as chaves do retorno são `enfileirada`, `fila_item_id`, `anexada`, `motivo` e
`irmas_ativas_nao_aprovadas`.

⚠️ **E o `andamento-um-lugar-so` ficou com a contagem IDÊNTICA (21):** o arquivo novo é varrido por
ele (ele varre `app/` INTEIRO) e **não foi acusado**, exatamente porque a sonda larga dá zero.

---

## §5 — A LIGAÇÃO DO `aoAprovar()`, COM O DIFF REDUZIDO AO GANCHO

`AndamentoDaOfertaService::aoAprovar()` estava em **`:333`** e era `protected` — **conferido por mim**
antes de tocar. O diff do arquivo, por `git diff e341d3ef..HEAD`, é **exatamente** o construtor mais o
corpo/docblock do gancho:

```
@@ -80,8 +80,14 @@ class AndamentoDaOfertaService
-    /** ⚠️ LIGAÇÃO DO PLANO 186-05 — a dependência do gancho `registrar()`. Ver aquele método. */
-    public function __construct(private HistoricoDaOfertaService $historico) {}
+    public function __construct(
+        private HistoricoDaOfertaService $historico,
+        private PublicacaoDoPortaoService $portao,
+    ) {}

@@ -329,9 +335,47 @@ protected function registrar(…)
     protected function aoAprovar(PubAprovacaoOferta $oferta, ?User $quem): void
     {
-        // Vazio de propósito — ver o docblock. A publicação é ligada na Fase 193.
+        try {
+            $this->portao->enfileirar($oferta, $quem);
+        } catch (\Throwable $e) {
+            Log::error(self::TAG . ' falha ao enfileirar a oferta aprovada (a aprovação FICOU de pé)', […]);
+        }
     }
```

`git diff --numstat` → `47 3`. ⚠️ **`mover()`, a lista fechada `TRANSICOES` e a `DB::transaction`
única ficaram INTACTOS** — nenhuma outra linha do arquivo mudou, e o diff acima é a prova.
⚠️ **Nenhuma transição nova.** ⚠️ `method_exists(…, 'aoAprovar')` continua verdadeiro (é asserção
viva da 186-04), e o gate `oferta-do-portao` a exercita.

### ⚠️ PROVADO DE DENTRO, não por leitura de código

Um dublê do serviço do portão registra, **no instante em que é chamado**, o `DB::transactionLevel()` e
o que **já estava gravado na linha** (relido por `DB::table`, não do objeto em memória):

```
nível de transação do gancho > nível de REFERÊNCIA medido fora de mover()   ✅
chamadas ao enfileiramento por aprovação = 1   (UM CAMINHO SÓ, PUB-09)      ✅
já gravado quando o gancho roda: andamento=aprovado, aprovado_por=<admin>,
   aprovado_em≠null, versao_aprovada=1, fila_item_id=null                   ✅
```

### O limite do `try/catch`, escrito com o custo declarado

Ele cobre falha de **INFRAESTRUTURA** e **não engole recusa de NEGÓCIO** (recusa volta pelo retorno e
**já foi** ao histórico com o motivo real). Provado: forçando o gancho a lançar, a aprovação **não
fica meio-gravada** — os três campos **e** o evento `Aprovou para publicação (versão 1)` ficam de pé,
e o que falta é só a ligação da fila. ⚠️ **ZERO job despachado** (`Queue::assertNothingPushed()`),
**com isca** provando que o espião vê um job quando há um.

---

## §6 — ⚠️ OS GATES DO `188-05` CONTINUAM VERDES — E ISSO MEDIU SE `agendar()` FAZ REQUISIÇÃO

```
EXIT_REAL_DO_HARNESS=0   gate '188-05 aprovar-e-enfileirar'  Tests: 3 passed (85 assertions)   PASSOU
EXIT_REAL_DO_HARNESS=0   gate '188-05 tentar-de-novo'        Tests: 2 passed (32 assertions)   PASSOU
```

**Contagem IDÊNTICA (85 e 32)**, com o gancho **ligado**. ⚠️⚠️ **E é isso que responde a pergunta
que a régua mandava medir:** o caso `aprovar_enfileira_e_nao_escreve_no_mercado_livre_com_isca_188_05`
assevera `assertSame(['/sites/MLB/listing_prices'], array_unique($lidas))` — **igualdade de
conjunto**, não piso. Se `FilaPublicacaoService::agendar()` fizesse **qualquer** requisição, aquela
igualdade quebraria. Ela **não quebrou**.

➡️ **MEDIDO: `agendar()` faz ZERO requisição.** Logo **nenhuma leitura precisou ser acrescentada à
lista CONSTANTE nomeada** daqueles gates, e **nada foi afrouxado**: a régua continua *"zero
escrita"*, não *"zero requisição"*. ⚠️ O docblock de `ResumoRapidoService` **afirmava** isso
(*"NENHUMA chamada ao Mercado Livre"*); aqui deixou de ser afirmação e passou a ser medição, porque
`Http::preventStrayRequests()` está de pé em **todos** os meus casos e `Http::recorded()` sai
**vazio** nos que só enfileiram.

### Os três do `186-09`, também idênticos

```
EXIT_REAL_DO_HARNESS=0   186-09 transversais           Tests: 12 passed (259 assertions)   PASSOU
EXIT_REAL_DO_HARNESS=0   186-09 sem-segundo-passo      Tests:  1 passed (53 assertions)    PASSOU
EXIT_REAL_DO_HARNESS=0   186-09 andamento-um-lugar-so  Tests:  1 passed (21 assertions)    PASSOU
```

⚠️ **E os números `21`/`259` são os que o `56ade5f0` (alheio) remediu** — eu **não** os tratei como
regressão e **não** mexi naquele manifesto. É exatamente a disciplina que a régua §5 passou a
prescrever: *"a pergunta não é «o número é o mesmo?», e sim «o número mudou por causa do MEU
trabalho?»"*. ✅ Pelo meu trabalho, **nada** mudou lá: `sem-segundo-passo` usa lista **CONSTANTE** de
25 arquivos (a Fase 186 já decidiu que arquivo novo no portão **não entra ali sozinho**) e
`andamento-um-lugar-so` varre `app/` inteiro mas **não acusa** o arquivo novo.

---

## §7 — O GATE DE AUSÊNCIA DOS LITERAIS PROIBIDOS

`ausencia-de-segundo-passo-193` → `EXIT_REAL=0`, `Tests: 1 passed (23 assertions)`.

- **lista CONSTANTE** dos dois arquivos de produção deste plano, com `assertFileExists` **anti-fantasma
  em cada caminho antes de medir**;
- lido **SEM COMENTÁRIO** (`token_get_all`), nunca regex sobre o texto cru;
- **borda à ESQUERDA só** em `desfazer`/`undo`;
- **isca (+)** com **dez** formas sintéticas, uma por variante, cada uma tendo de casar;
- **isca (−)** com o rótulo legítimo *"Aprovar para publicação"* e com *"o segundo passo da conta"*,
  *"o fundo do card"*, *"de forma mais profunda"*;
- ⚠️ **a isca do leitor medida nas DUAS pontas:** no texto **CRU** a sonda acha (a prosa dos dois
  arquivos cita as nove formas de propósito) e no **limpo** acha **zero** — sem essa ponta, *"zero
  infratores"* seria indistinguível de *"a sonda não lê nada"*;
- **UM CAMINHO SÓ** por `substr_count` (= 1 chamada a `->enfileirar(` na única porta) + **IGUALDADE
  DE CONJUNTO** das **16** rotas de `aprovacao` (piso `>= N` **não** prova presença).

### ⚠️⚠️ UM FURO QUE EU ACHEI NA MINHA PRÓPRIA SONDA, ANTES DE ELA VALER

A primeira redação do meu docblock citava *"confirmar publicação"* na forma de prosa acentuada, e eu
usei a `sondaPub09` do 186-09 para medir a isca do leitor. **Medido: ela deu ZERO.**

➡️ **A `sondaPub09` do gate 186-09 NÃO casa a forma pt-BR acentuada com espaço** — ela só pega
`confirmar_publicacao` e `confirmarPublicacao`. E a sonda do **188-05** faz o inverso: pega a
acentuada e **não** pega `undo` nem `publicar_aprovado`. ⚠️ **Nenhuma das duas, isolada, cobre as
nove formas.**

➡️ **O meu gate usa a UNIÃO das duas**, que é estritamente mais forte que qualquer uma — e eu
reescrevi o docblock para citar as nove na forma que a união pega, de modo que **a isca do leitor
tem sujeito no próprio arquivo que este plano cria** (CRU = 18 ocorrências, limpo = 0).

⚠️ **Isto é um achado sobre os gates ALHEIOS, e fica registrado para o usuário/orquestrador:** se um
dia alguém cunhar *"Confirmar publicação"* como rótulo de tela num dos 25 arquivos do
`PRODUCAO_PHP_DO_PORTAO`, **o `186-09 sem-segundo-passo` não vai pegar**. **Eu não alterei aquele
gate** (é da Fase 186, em produção). **Dono: usuário/orquestrador.**

---

## §8 — A CAMPANHA DE MUTAÇÃO: CINCO MUTAÇÕES, DOIS SOBREVIVENTES, DOIS CONSERTOS NA RAIZ

**Forma cumprida ponto por ponto:** commit **ANTES** (`5e9d242c`, `923bf8a4`); `cp -p` para **fora**
do repo; script com nome que carrega o plano (**`mutar-193-02.sh`**, `sha256 c1b15808ce12dd00`, com o
`sha256` do próprio script e do trocador **impressos antes do veredicto** — o scratchpad é
COMPARTILHADO e um `mutar.sh` já foi sobrescrito no meio de uma campanha); ocorrência **travada em 1
ANTES** por string **exata** (nunca regex); **qual linha mudou** conferida depois; aplicação provada
por **divergência de `sha256`**; restauração **DA CÓPIA** em `trap EXIT`; `sha256` contra
`git show HEAD:` com `tr -d '\r'` nos dois lados. ⚠️ **`git checkout` NUNCA.**

```
─── RESTAURACAO (DA COPIA, nunca por git checkout) ───
  OK   app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php  (92b3d1fe9b6e2d9b == git show HEAD:)
  OK   app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php  (bb313dc45f84d13d == git show HEAD:)
```

| # | mutação | 1ª rodada | depois do conserto |
|---|---|---|---|
| **m1** | item terminado passa a contar como **VIVO** (`whereIn('status', VIVOS)` → `VIVOS ∪ SEM_PUBLICAR`) | ⚠️ **SOBREVIVEU** | **MORRE** (exit 1) |
| **m2** | a **guarda da decisão 23 desligada** (`if (false && …)`) | **MORREU** `2 failed (12 assertions)` | — |
| **m3** | o gancho roda **FORA da transação** (a `DB::transaction` vira função anônima) | ⚠️ **SOBREVIVEU** | **MORRE** (exit 1) |
| **m4** | um **literal proibido no CÓDIGO** (`const SEGUNDO_PASSO = 'confirmar_publicacao'`) | **MORREU** `1 failed (18 assertions)` | — |
| **m5** | o arquivo **sai** de `app/Services/Publicador/Aprovacao/` | **MORREU**, mas ⚠️ **por outra razão** — ver abaixo | — |

### ⚠️ A conferência do `# tests` do mutado contra o pristino — e o FALSO ALARME da minha régua

Nas duas re-rodadas o meu script avisou *"a contagem de TESTES caiu"*. **É falso alarme da minha
própria medição:** o `sed` dele captura o **primeiro** número de `Tests: 1 failed, 2 passed`, que é
o de **falhas**, não o total. O total é `1 + 2 = 3` = **pristino 3** ⇒ **o arquivo CARREGOU** e a
morte **é da asserção**. Fica registrado como terceiro furo meu (§11).

### ⚠️⚠️ SOBREVIVENTE É INVESTIGADA — e as duas eram furo REAL

**m1 — a minha sonda provava `produto_ativo`, não `status`.** Nas fixtures dos dois laços o item
terminado tem `produto_ativo` **NULO**, então quem o descartava era o `where('produto_ativo')` e o
filtro de `status` era, para o teste, **invisível**. ➡️ **Conserto de raiz:** entrou o caso do estado
**INCONSISTENTE** (item terminado que ainda **OCUPA** o produto), o único em que `status` discrimina,
asseverando o invariante de verdade — **a oferta NUNCA é ligada a item fora de `VIVOS`**, senão ela
aponta para um item que nunca vai publicar, o painel lê as 4 colunas de um item morto e o
reconciliador acha que há publicação em voo. A mutação agora morre por essa asserção:
`Failed asserting that 11 is not identical to 11`.

**m3 — `assertGreaterThan(0, $transactionLevel)` era VAZIA POR CONSTRUÇÃO.** O `RefreshDatabase` abre
transação própria em volta de **cada caso**, logo `transactionLevel()` já vem **1** sem `mover()`
abrir nada — a asserção era satisfeita pelo **próprio arcabouço de teste**. ➡️ **Conserto de raiz:**
reancorada em **estritamente maior que a referência medida FORA de `mover()`**. A mutação agora morre
com a mensagem que nomeia os dois números: *"nível 1 contra referência 1"*.

⚠️ **Nenhum dos dois foi consertado por elevação de número: os dois ACRESCENTARAM prova**, e o
manifesto registra isso em linha (`enfileirar-anexa-ou-agenda` 67 → 71, com a razão escrita).

### ⚠️ m5 morreu, mas NÃO isola o glob — e isso é declarado

Sem o arquivo, o container não resolve a dependência do construtor e a suíte **nem roda** (`(nenhuma
linha 'Tests:' — o filtro nao casou nada?)`). ➡️ O gate morre por **falha de boot**, não por
contagem. **Quem isola o glob é o par ANTES/DEPOIS da §3** (+2 exato nos **dois** ids, que é a
assinatura das 2 asserções por arquivo da camada B).

---

## §9 — O TERRITÓRIO: `PI(193-02)` POR ESCOPO DO ASSUNTO

⚠️ Duas passadas independentes, **nunca por range aberto a partir da PARTIDA**, **nunca pelo autor**,
`test -e` em **cada** alvo, isca nas duas direções. `sha256` do script: `738f13bbc77d35c9`.

```
PARTIDA=e341d3ef   HEAD=c4be8e9d
PASSADA A (git log --grep, le a MENSAGEM INTEIRA): c4be8e9d 923bf8a4 5e9d242c
PASSADA B (pelo ASSUNTO %s apenas):                c4be8e9d 923bf8a4 5e9d242c
TOTAL_A=3   TOTAL_B=3
FACE 1 — SOBRE-CAPTURA (A menos B): NENHUMA (os dois conjuntos sao IGUAIS)
FANTASMAS=0   VIOLACOES=0
ISCA (+) contagens-193-02.txt: 161  0        <- o range NAO esta cego
ISCA (-) app/NaoExiste9z7.php: []
Arquivos RASTREADOS modificados na arvore: []   (por git diff HEAD, nunca git status)
27 stashes: APENAS CONTADOS
```

**Os únicos 5 arquivos que os meus commits tocam** — e os cinco estão no `files_modified` do plano:

```
app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php
tests/Feature/Publicador/Aprovacao/EnfileirarAoAprovarTest.php
tests/gates/contagens-186-04.txt
tests/gates/contagens-193-02.txt
```

### AS TRÊS FACES

**FACE 1 — sobre-captura.** `git log --grep` lê a **mensagem inteira**, então um commit de outra wave
que **cite** `(193-02)` no corpo entraria sem ter tocado nada meu. **Não se concretizou** (A = B = 3),
mas foi **medido**, não suposto.

**FACE 2 — sub-captura. CONCRETIZOU-SE (de novo).** A varredura por assunto **não vê** os três
vizinhos alheios, e **um deles mexeu em dois arquivos que a régua da 193 marca como sensíveis**:

```
ALHEIO: 05c26309  feat(193): decisao 23 — aprovar UMA modalidade nao enfileira o produto
        .planning/ROADMAP.md                                   <- GATE-07
        .planning/phases/193-…/193-REGUA.md                    <- a regua da fase
ALHEIO: d0a37eb3  fix(quick-261020-s95): a tela do agente conferida no navegador
        .planning/quick/261020-s95-…/SUMMARY.md
        resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx
        tests/js/publicador-agente-render.test.js
ALHEIO: ea203f66  docs(quick-261020-ozm): a migration e a concorrencia do agente no MariaDB local
        .planning/quick/261020-ozm-…/SUMMARY.md
```

➡️ Medidos **À MÃO, por diferença** (`e341d3ef..HEAD` menos os meus) — o único jeito de ver vizinho
que não carrega o meu escopo no assunto. ⚠️ Os dois arquivos que **mudaram no range** e que eu tenho
proibido de tocar (`ROADMAP.md` `1 0` e `193-REGUA.md` `21 1`) estão **atribuídos ao `05c26309`**,
que é **ALHEIO**. **Não fui eu** — e os meus 5 arquivos não os incluem.

**FACE 3 — o autor. A premissa do briefing estava certa no número, desta vez.** Medido no histórico
inteiro, **CINCO** identidades: `MB.ECF-100376` ×**3712**, `ECF Dev` ×2008, `Dev ECF` ×57, `root` ×5,
`ecfmaycon` ×4. ➡️ O autor **não discrimina** (os três commits alheios desta wave e os meus três têm
o **mesmo** `%an`), e é por isso que o território saiu do **assunto**, nunca do autor.

### Os vizinhos intocáveis, medidos por RANGE (nunca pela árvore)

| alvo | prova |
|---|---|
| `app/Services/Mlb/Publicacao/MlPublicacaoService.php` (**PUB-03**) | `test -e` **OK** (175 linhas) e `numstat` **VAZIO** |
| `app/Services/Publicador/PublicacaoService.php` | `numstat` **VAZIO** |
| `FilaPublicacaoService` · `AgendadorDaFila` · `ResumoRapidoService` | `numstat` **VAZIO** |
| `AprovacaoDaOfertaService` · `BloqueiosDaOfertaService` · `VocabularioDoPortao` · `HistoricoDaOfertaService` · `RevisaoDeImagemService` · `AjustesDaOfertaService` · `ComposicaoFinanceiraService` | `numstat` **VAZIO** |
| `PreparadorDaAprovacao` (⚠️ de outra sessão) | `numstat` **VAZIO** |
| `PublicarRascunhoJob` · `PubAprovacaoOferta` · `PubFilaPublicacao{,Item}` | `numstat` **VAZIO** |
| `Aprovacao.jsx` · `AppLayout.jsx` · **todo `resources/js/`** | `numstat` **VAZIO** no que é meu (o único `resources/js/` do range é alheio) |
| `tests/js/estrutura-meus-anuncios.test.js` (**D-11/D-22**) | `numstat` **VAZIO** |
| `tests/js/publicador-header-zindex-ordem.test.js` | `numstat` **VAZIO** |
| `database/migrations/` · `routes/` | `numstat` **VAZIO** (⚠️ **nenhuma migration, e `artisan migrate` NÃO foi rodado**) |
| `package.json` · `package-lock.json` · `composer.json` · `composer.lock` | `numstat` **VAZIO** (nenhum install) |
| `.planning/{ROADMAP,REQUIREMENTS-v27,STATE}.md` (**GATE-07**) · `193-REGUA.md` · `193-COBERTURA.md` · `193-NN-PLAN.md` | **não fui eu** (o que mudou é do `05c26309`, alheio) |

⚠️ `state.advance-plan` **não** foi rodado. `pint` **não** foi rodado. Nenhum worktree. Nenhum
`git stash`/`reset`/`checkout`/`clean`/`--amend`.

---

## §10 — ⚠️ A FRONTEIRA D-11/D-22, DECLARADA

O **D-11** diz que a tela de **Publicações** (`Meus Anúncios`) **nunca escreve**. ⚠️ **Esta fase
escreve no Mercado Livre** — logo a fronteira tem de ficar explícita, e ela fica assim:

| lado | quem | o que escreve |
|---|---|---|
| **a fila / o portão** (meu) | `PublicacaoDoPortaoService` → `FilaPublicacaoService` → `AgendadorDaFila` → `PublicacaoService` → `PublicarRascunhoJob` | ⚠️ **só BANCO** nesta wave; o único `POST /items` é em `PublicacaoService::executarFatia()` (`:442`), **reusado INTOCADO** |
| **a lista de Publicadas** (D-11/D-22) | `tests/js/estrutura-meus-anuncios.test.js` | **nada** — `numstat` **VAZIO** por range, e o gate **não foi estendido** à área nova |

➡️ **O gate do D-11 continua intacto e NÃO foi estendido.** A área nova tem **gate de escrita
próprio** (`ausencia-de-segundo-passo-193`, sobre a **minha** lista constante). A igualdade de
conjunto das asserções daquele arquivo e o `numstat` por range do critério 7 são do **193-05**.

---

## §11 — ⚠️⚠️ OS FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS

**Todos achados por RODAR, não por ler. Nenhum consertado por elevação de régua.**

### FURO 1 — asserção **refém da contagem de eventos** (medido: 26 → 27 **sem defeito nenhum**)

Eu fazia **uma asserção POR EVENTO** do histórico, dentro de um `foreach`. O número de eventos
depende de **quantas vezes o enfileiramento foi tentado**, e a tarefa 2 (que liga o gancho)
acrescenta uma tentativa. ➡️ **Medido subindo de 26 para 27 asserções sem nenhum defeito ter
aparecido.** É a família *"âncora em número que muda legitimamente"*, e o conserto errado seria
elevar o número. ➡️ **Trocada por asserção COLETORA** (coletar os textos com jargão e asseverar `[]`
**uma vez**): prova o mesmo e não depende da contagem. `26 → 25`, e **estável**.

### FURO 2 — a sonda de item vivo provava `produto_ativo`, não `status`

Ver §8, m1. Achada **pela mutação**.

### FURO 3 — `assertGreaterThan(0, $transactionLevel)` vazia por construção

Ver §8, m3. Achada **pela mutação**.

### FURO 4 — **asserção vazia** que eu escrevi e peguei relendo

```php
$this->assertSame([], preg_match_all('/test_…/', 'test_(a|b|c)', $cegos) ? [] : [], 'guarda da isca');
```

⚠️ `[] === []` **sempre**. Era uma asserção que **não media nada**, exatamente o que a régua chama de
*"asserção vazia quando escrita, falso positivo depois"*. ➡️ Substituída por **duas** medições reais,
nas duas pontas: a regex ingênua casa **`[]`** em `test_(a|b|c)` e casa **`['test_abc']`** em
`test_abc`. **Medido**, não citado de briefing — e com isso o expansor de alternância deixou de ser
zelo sem sujeito.

### FURO 5 — **linha morta** no caso do `Queue::fake()`

Eu deixei um `Bus::fake()` depois da asserção, sem uso. ➡️ Removido e **trocado por isca de verdade**:
empurrar um job e asseverar que o espião o **vê** — sem ela, `assertNothingPushed()` é indistinguível
de *"o fake não registra nada"*.

### FURO 6 — a régua de medição do meu **próprio** script de mutação

Ver §8: o `sed` captura o número de **falhas** em `Tests: 1 failed, 2 passed` e reporta queda de
testes que não existe. **Falso alarme declarado**, com a leitura correta escrita ao lado.

### FURO 7 — `/tmp/h1` **é um diretório**

Eu redirecionei saída de gate para `/tmp/h1` e o shell respondeu `Is a directory` — **colisão no
`/tmp` compartilhado**, que é a mesma família do *"scratchpad compartilhado"*. ➡️ Toda saída passou a
ir para o **meu** scratchpad, com nome que carrega o plano (`out-193-02-*`).

---

## §12 — ⚠️ CRITÉRIOS DE ACEITAÇÃO MEUS QUE EU RODEI E ACHEI FRACOS OU FALSOS POR CONSTRUÇÃO

| item do `<acceptance_criteria>` | veredicto |
|---|---|
| `grep -c "'andamento' =>" <o serviço>` lido sem comentário é **0** | ⚠️ **FRACO POR CONSTRUÇÃO:** `grep -c` conta **LINHAS**, não ocorrências — eu mesmo bati nisso ontem, devolvendo 1 onde havia 2 na mesma linha. ➡️ Substituído por `substr_count`/`preg_match_all` sobre o código **sem comentário** por `token_get_all` |
| `grep -c "dispatch(" <o serviço>` é **0** | ⚠️ mesma correção; medido **0** por `substr_count` |
| `test -e <o serviço>` sai 0 | ✅ roda, mas ⚠️ **sozinho não prova a RAIZ**: `test -e` passaria igual se o arquivo estivesse em `Aprovacao/Publicacao/`. ➡️ Quem prova a raiz é o **+2 nos dois ids da 186-04** (§3) |
| *"o caso do ANEXO prova `pub_fila_publicacao_itens` do produto = 1 com DUAS ofertas"* | ✅ provado — **mas** o caso teve de ser **reescrito**: com a guarda da decisão 23, é a **última** aprovação que agenda, e a primeira só ganha a ligação pelo anexo |
| `assertGreaterThan(0, …)` para "está na transação" | ⚠️ **VAZIO POR CONSTRUÇÃO** (§11, furo 3) |
| **gate de ordem `dropForeign → dropIndex`** (armadilha 1553) | ⚠️ **NÃO ENTROU, e é declarado:** esta fase não dropa índice nenhum — seria gate sobre **ZERO casos**. O `193-01` já havia medido isso; eu **não** o recriei |
| *"o componente novo está no manifest"* | ⚠️ **FALSO como invariante neste projeto.** Esta fase não cria `.jsx`; usei o manifest **só** como não-regressão — e ele **subiu por trabalho alheio** (§13) |

---

## §13 — AS BASELINES, RE-MEDIDAS, COM O HEAD CONFERIDO NAS DUAS PONTAS

```
PARTIDA:   e341d3ef
COMMIT:    c4be8e9d   (o de docs vem depois)
BUILD:     exit 0 · 47 s · "built in 44.28s"     HEAD c4be8e9d nas DUAS pontas
MANIFEST:  500 entradas / 204 paginas   (controle Aprovacao.jsx PRESENTE, isca NaoExiste9z7.jsx AUSENTE)
JS:        6031 / 6030 pass / 1 fail / 0 skipped  (exit 0)   HEAD c4be8e9d nas DUAS pontas
PHP:       2660 / 24471 / 0                      (exit real 0)   HEAD c4be8e9d nas DUAS pontas · 970 s
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

### ⚠️ O isolamento, PROVADO POR LINHA DE COMANDO (não por nome de processo)

Contar `node.exe` por nome daria **11** e pareceria contaminado. ➡️ Inspecionei a **linha de comando**
de cada um (`Get-CimInstance Win32_Process`): os 4 que casaram o meu filtro são **servidores MCP**
(`mcp-remote`), casados **só** porque a minha regex incluía `npm` (eles sobem por `npx-cli.js`).
**ZERO** era `node --test` ou `vite`. E `php.exe` estava em **0** imediatamente antes de cada rodada
de PHP. ➡️ **DECLARADO: não houve build nem outra suíte concorrente em nenhuma das medições.**
⚠️ `0 skipped` no JS nas duas pontas, porque o `build` rodou **antes** e **sozinho**.

### ⚠️⚠️ A SUÍTE PHP **COMPLETA** NÃO É MEDÍVEL NESTA MÁQUINA

`artisan test` sem filtro **aborta com Fatal de memória** a ~6% de 10.653 testes, e
`-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**). ➡️ O número `PHP:` acima é o
**RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e **digo isso**
em vez de deixar o leitor supor que a suíte inteira passou.

### A aritmética do Δ — cada parcela atribuída pelo commit que CRIOU/ALTEROU o arquivo

⚠️ **Por `git log --diff-filter=A` / `git show --name-only`, NUNCA por range** (o range engloba os
meus próprios commits, e a 189-07 caiu exatamente nisso).

| linha | baseline do `193-01` | medido agora | Δ | atribuição |
|---|---|---|---|---|
| **MANIFEST entradas** | 498 | **500** | **+2** | ⚠️ **ALHEIO.** `resources/js/Pages/Mlb/Publicador/Agente.jsx` foi **CRIADO por `56ade5f0`** (`git log --diff-filter=A`), e é uma PÁGINA nova ⇒ +1 entrada e +1 página. ⚠️ **A 2ª entrada eu NÃO consegui isolar** (seria preciso o `manifest.json` antigo, e obtê-lo exigiria `checkout`, que é proibido) — o que **está** provado é que **não é minha**: os meus 3 commits tocam **ZERO** `resources/js/`, **ZERO** `.jsx` e **ZERO** `package*`/`vite.config.js` |
| **MANIFEST páginas** | 203 | **204** | **+1** | ⚠️ **ALHEIO** — `Agente.jsx`, nomeada acima |
| **JS testes** | 6012 | **6031** | **+19** | ⚠️ **ALHEIO** — `tests/js/publicador-agente-render.test.js` (criado/alterado por `56ade5f0` e `d0a37eb3`), `publicador-header-nas-paginas.test.js` e `publicador-vocabulario-novo.test.js` (por `56ade5f0`). **Os meus commits tocam ZERO teste JS** |
| **JS falhas** | 1 | **1** | 0 | **pré-existente, Fase 87**: *"Características secundárias nasce recolhido (é o grupo que mais infla)"* — mesma **semântica**, nunca por número de linha. ⚠️ **NÃO corrigida** |
| **PHP testes** (o RECORTE) | 2650 | **2660** | **+10** | **MEU, e a aritmética fecha na vírgula:** os **10** métodos de `EnfileirarAoAprovarTest.php`, criado pelos meus `5e9d242c`/`923bf8a4`/`c4be8e9d`. ⚠️ **Nenhum teste PHP alheio entrou no meu range** — o trabalho do agente desta janela (`d0a37eb3`) só mexeu em `.jsx` e `tests/js` |
| **PHP falhas** | 0 | **0** | 0 | ⚠️ **ZERO falhas: não há NADA a atribuir por execução isolada, e por isso nenhum `REF_PHP_FALHAS` foi criado** — um limiar de falhas aceito seria a porta dos fundos que o harness existe para fechar |
| **PHP asserções** | 24.278 | **24.471** | +193 | ⚠️ **INFORMATIVO**, não comparável: o harness tem `REF_PHP_TESTS` e **não** tem referência de asserções, porque casos de fronteira de dia percorrem ramos diferentes conforme a hora |
| **PROTÓTIPO** | 1432 l. / `68624883ce35547e` | **idem** | 0 | ✅ |

### ⚠️ A medição longa, com o HEAD conferido nas DUAS PONTAS

```
php.exe antes: 0
HEAD_NO_INICIO=c4be8e9d
INICIO=2026-10-21T01:36:46Z
EXIT_REAL_DO_HARNESS=0
FIM=2026-10-21T01:52:56Z
HEAD_NO_FIM=c4be8e9d          <- a arvore NAO se moveu durante os 970 s
PHP: 2660 / 24471 / 0
```

⚠️⚠️ **Isto é exatamente o que o `193-01` perdeu 19 minutos por não fazer**, e nesta árvore não é
zelo: o HEAD se moveu **três vezes** durante esta wave (§1). Desta vez ele ficou parado nas duas
pontas, logo a medição é válida. ⚠️ **E o `exit` foi lido de `$?`**, não de notificação de background
nem depois de um `tail` — que é como o `193-01` quase registrou uma baseline com 4 falhas como verde.

⚠️ **Nunca li `${TMPDIR:-/tmp}/ultima-suite-php.log`** (caminho **fixo e compartilhado**; uma wave
reportou `0 falhas` quando eram 11). As linhas acima vieram da **saída do processo**, em arquivo do
meu scratchpad com nome que carrega o plano. ⚠️ E, de passagem, bati na mesma família: um
redirecionamento meu para `/tmp/h1` respondeu **`Is a directory`** (§11, furo 7).

⚠️ **A régua do `193-BASELINES.md` dizia que o manifesto tinha de ficar IGUAL** porque *"esta fase não
edita `.jsx` nenhum"*. ➡️ **Ela ficou igual PELO MEU TRABALHO**; o Δ é alheio e está nomeado. É
exatamente o princípio que a régua §5 passou a prescrever (*"a contagem não mudada POR VOCÊ"*), agora
aplicado ao manifesto em vez de a um manifesto de gate. ⚠️ **Não editei o `193-BASELINES.md`** — ele
é artefato do `193-01`; **dono: usuário/orquestrador**.

---

## §14 — `--autoteste`: 20 CASOS, 0 DIVERGÊNCIA (colado integralmente)

```
EXIT_REAL_DO_HARNESS=0
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

### O `exit` REAL do harness, em cada id — capturado por `$?` **na linha seguinte**

⚠️ **Nunca depois de um pipe:** o `193-01` pegou o harness saindo **1** enquanto a notificação do
background dizia *"exit code 0"*, porque o último comando do composto era um `tail`.

```
enfileirar-anexa-ou-agenda       EXIT_REAL=0   Tests: 3 passed (71 assertions)
guarda-modalidade-irma           EXIT_REAL=0   Tests: 2 passed (25 assertions)
aprovar-enfileira-atomico        EXIT_REAL=0   Tests: 3 passed (36 assertions)
ausencia-de-segundo-passo-193    EXIT_REAL=0   Tests: 1 passed (23 assertions)
bijecao-manifesto-193-02         EXIT_REAL=0   Tests: 1 passed (32 assertions)
186-04 unica-porta               EXIT_REAL=0   Tests: 1 passed (43 assertions)   [REMEDIDO]
186-04 oferta-do-portao          EXIT_REAL=0   Tests: 3 passed (67 assertions)   [REMEDIDO]
186-09 transversais              EXIT_REAL=0   Tests: 12 passed (259 assertions) [IDENTICO]
186-09 sem-segundo-passo         EXIT_REAL=0   Tests: 1 passed (53 assertions)   [IDENTICO]
186-09 andamento-um-lugar-so     EXIT_REAL=0   Tests: 1 passed (21 assertions)   [IDENTICO]
188-05 aprovar-e-enfileirar      EXIT_REAL=0   Tests: 3 passed (85 assertions)   [IDENTICO]
188-05 tentar-de-novo            EXIT_REAL=0   Tests: 2 passed (32 assertions)   [IDENTICO]
```

### A bijeção, nos TRÊS sentidos, como teste **durável**

`SEM_ID=0` · `ORFAOS=0` · `IDS_REPETIDOS=0` · `HOMONIMOS=0` (varrendo toda a suíte), **sufixo
`_193_02` em TODOS os 10 métodos** por asserção coletora, **nenhum id com mais de TRÊS métodos**, e
`tests=` conferido contra o número de métodos do filtro. ⚠️ A bijeção **funcionou de verdade**: ela
me **acusou** os 5 métodos da tarefa 2 antes de eu pôr os ids no manifesto.

⚠️ **A isca do 1º argumento** está escrita no cabeçalho do manifesto, com a medição do código
(`verificar-testes.sh:120-129`, `sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'`): **`193-02`**, nunca `02`.

---

## §15 — ⚠️⚠️ ONDE O BRIEFING DIVERGIU DA MINHA MEDIÇÃO (a medição ganha, e eu declaro)

| o briefing disse | eu medi | consequência |
|---|---|---|
| *"`PreparadorDaAprovacao` — novo, de outra sessão"* (sem caminho) | ⚠️ ele vive em **`app/Services/Publicador/Agente/`**, **NÃO** em `Aprovacao/` | **importa:** ele **não** cai no glob da camada B, apesar de **criar** `PubAprovacaoOferta`. É a lição *"um NOME não é um CAMINHO"* outra vez |
| *"nada em `app/` cria oferta"* (régua §7, `193-05:201`, critério 10) | ⚠️ **FALSO desde a quick `261020-oeg`**: `PreparadorDaAprovacao` cria. ✅ O orquestrador já corrigiu a régua no `05c26309`; **confirmei por grep** (`PubAprovacaoOferta::create` em `app/` → `PubAprovacaoOferta.php` + `PreparadorDaAprovacao.php`) | o **critério 10** passa a ser executável **depois do piloto do agente**, não só depois da 194. ⚠️ O `193-05-PLAN.md` **repete a afirmação antiga** — quem executar o 193-05 precisa do aviso |
| *"`mysqld` local PARADO"* | ⚠️ **DE PÉ**: `mysqld.exe` PID **30496**, conferido por mim | **não afeta nada aqui:** `phpunit.xml:27-28` fixa `DB_CONNECTION=sqlite` e `DB_DATABASE=:memory:`, então **a suíte não toca o MariaDB**. ⚠️ **Não rodei `artisan migrate`** — esta fase não tem migration, e há migration alheia rastreada na árvore |
| *"a `sondaPub09` do 186-09 cobre os nove literais"* (implícito na régua) | ⚠️ **NÃO cobre a forma pt-BR acentuada com espaço** | o meu gate usa a **UNIÃO** com a do 188-05; e fica o **achado sobre o gate alheio** (§7) |
| *"MANIFEST 498/203, e nesta fase tem de ficar IGUAL"* | **500/204** | **ACIMA, e ALHEIO** — `Agente.jsx` de `56ade5f0`. Pelo meu trabalho, igual |
| *"JS 6012/6011/1/0"* | **6031/6030/1/0** | **ACIMA é esperado**; +19 alheio, nomeado |
| *"há CINCO identidades: `MB.ECF-100376` ×3705…"* | **×3712** (cresceu com os commits desta sessão), as outras quatro iguais | a conclusão (o autor não discrimina) **não muda** |
| *"`aoAprovar()` está em `AndamentoDaOfertaService.php:333` e é `protected`"* | ✅ **confirmado** | nada a fazer |
| *"a decisão 21 é a opção (a)"* | ✅ confirmada no `193-REGUA.md` (reescrita pelo `05c26309`) e no `ROADMAP.md` | **a trava do plano NÃO disparou** — o usuário não respondeu (b), logo eu **não** parei e **não** toquei `VocabularioDoPortao`/`BloqueiosDaOfertaService` |

---

## §16 — AS PERGUNTAS DESTA WAVE

| # | pergunta | estado | dono |
|---|---|---|---|
| **193-A** | a oferta que a fila recusa | ✅ **RESPONDIDA** (decisão 21, opção **a**) e implementada: fica em `aprovado`, motivo real no histórico, reconciliador repete | — |
| **193-F** | a modalidade irmã não aprovada | ✅ **RESPONDIDA no meio da wave** (decisão **23**) e **implementada + provada** | — |
| ⚠️ **193-E** | **NOVA, minha:** `ciente = true` no agendamento | ⚠️ **DEVOLVIDA.** Em `agendar()`, `ciente` significa *"quem agendou está ciente dos AVISOS do ML"*. **Passar `false` criaria laço sem saída:** o portão **não tem** caixa *"Estou ciente"* (ela é da tela de lote), então oferta de produto com avisos ficaria parada **para sempre** com um motivo que **ninguém pode satisfazer pela tela do portão**, e o reconciliador tentaria a cada minuto. ➡️ Fica `true`, com o significado honesto de que **o clique humano em "Aprovar para publicação" É a ciência** (e o item só grava `ciente` verdadeiro **onde houve aviso**). ⚠️ Se o usuário quiser que aviso do ML **barre** a aprovação, isso é um **9º bloqueio no portão** — a opção (b) que ele já recusou uma vez, e que mexe em serviço da Fase 186 | **USUÁRIO** |
| ⚠️ **193-G** | **NOVA, minha:** a irmã já aprovada fica **sem `fila_item_id`** até o reconciliador rodar | ⚠️ **DECLARADA.** Com a guarda da decisão 23, quem agenda é a **última** aprovação do produto; as anteriores ganham a ligação pelo caminho do **ANEXO**, na passada do minuto do **193-03**. Eu **não** escrevi `fila_item_id` nas irmãs daqui porque o plano diz *"gravar `fila_item_id` na oferta — e NADA MAIS"*, e escrever em linha que não me foi passada é expandir o território do serviço. ➡️ **O `193-03` tem de cobrir isso**, e o caso do ANEXO já está provado funcionando | **193-03** |
| ⚠️ | o `186-09 sem-segundo-passo` **não pega** a forma acentuada com espaço | ⚠️ **ACHADO, não consertado** (Fase 186 em produção) | **USUÁRIO / orquestrador** |
| ⚠️ | a 2ª entrada nova do `manifest.json` não foi isolada | ⚠️ **DECLARADA** — provado que **não é minha**; isolá-la exigiria `checkout`, proibido | **usuário / orquestrador** |

---

## §17 — O QUE ESTE PLANO NÃO FEZ (é a régua, não omissão)

- ❌ **nenhuma migration**, ❌ **`artisan migrate` não foi rodado**, ❌ **nenhuma coluna nova**
- ❌ **nenhum `.jsx`**, ❌ **nenhuma rota nova** (provado por igualdade de conjunto das 16),
  ❌ **nenhum estado novo**, ❌ **nenhum rótulo novo**, ❌ **nenhum 7º toast**
- ❌ **nenhum feature-flag** de "ligar publicar" (a trava é `ContasLiberadas`, fail-closed — um
  segundo interruptor faria **duas verdades**)
- ❌ **não alterei** `PublicacaoService`, `FilaPublicacaoService`, `MlPublicacaoService`,
  `AgendadorDaFila`, `ResumoRapidoService`, `PreparadorDaAprovacao`, nem qualquer serviço/`.jsx` das
  Fases 186-189 (⚠️ a **única** exceção autorizada por nome é o corpo de `aoAprovar()` + o construtor)
- ❌ **não toquei** `contagens-186-09.txt` nem `contagens-188-05.txt`; do `contagens-186-04.txt`,
  **só os dois ids**, **remedidos**
- ❌ **nenhum deploy, push, VPS ou chamada à API real do ML** · ❌ nenhum `npm install`/
  `composer require` · ❌ nenhum `pint`, `stash`, `reset`, `checkout`, `clean`, `--amend`, worktree
- ⚠️ **NÃO AFIRMO ter visto tela renderizada** nem publicação real: não há navegador, nenhuma empresa
  tem `ml_token` de verdade e `pub_aprovacao_ofertas` está vazia em produção. **A prova desta wave é
  teste com `Http::fake` + `preventStrayRequests()`.**
- ⚠️ **`aprovarEmLote` foi provado pela ROTA do lote**, não por derivação — e a que a fila recusa fica
  em `aprovado` com o motivo real, como manda a decisão 21

---

## §18 — Deviações do plano

### Auto-corrigidas (Regra 1 — defeito no meu próprio artefato, achado por MEDIR)

1. **[Regra 1] Asserção refém da contagem de eventos** — 26 → 27 sem defeito; trocada por coletora
   (`26 → 25`, estável). Commit `923bf8a4`.
2. **[Regra 1] A sonda de item vivo provava `produto_ativo`, não `status`** — achada pela mutação m1;
   conserto de raiz com o caso do estado inconsistente. Commit `c4be8e9d`.
3. **[Regra 1] `assertGreaterThan(0, $transactionLevel)` vazia por construção** — achada pela mutação
   m3; reancorada na referência medida fora. Commit `c4be8e9d`.
4. **[Regra 1] Asserção vazia (`[] === []`) na isca do expansor** — substituída por duas medições
   reais. Commit `923bf8a4`.
5. **[Regra 1] Linha morta (`Bus::fake()` sem uso)** — trocada por isca de verdade do espião da fila.
   Commit `923bf8a4`.
6. **[Regra 1] A fixture criava a conferência com `revisao = 0`** porque o Eloquent **não lê o
   default do banco** (`PubRascunho::create()` sem `revisao` devolve `null` em memória e a linha nasce
   `1`). A fila recusava com *"O produto mudou depois da conferência"* e o caso mediria a recusa
   **errada**. ➡️ `fresh()` obrigatório, com a razão escrita no código. Commit `5e9d242c`.

### Deviação de escopo (declarada, por instrução do usuário no meio da wave)

7. **A guarda da modalidade irmã (decisão 23) NÃO estava no plano** — ela chegou como decisão do
   usuário durante a execução. Implementada, provada nas duas direções e documentada. ⚠️ **Nenhum
   serviço das Fases 186-189 foi alterado para isso**, e por isso **não houve parada**.

### Nenhuma Regra 2, 3 ou 4 foi acionada

Nada foi instalado. Nenhuma decisão arquitetural tomada por conta própria — as duas que apareceram
(`193-E`, `193-G`) estão **devolvidas**, com o custo de cada saída escrito.

---

## §19 — Known Stubs

**Nenhum.** Não há valor vazio hardcoded, texto de espera reservado nem componente sem fonte de
dados. ⚠️ O que existe e **está declarado como fronteira, não como stub**, é a ligação da irmã já
aprovada, que é do **193-03** (`PERGUNTA-193-G`, §16) — e o caminho que a resolve (o **ANEXO**) já
está implementado e provado funcionando nesta wave.

## §20 — Self-Check: PASSED

Arquivos afirmados, conferidos por `test -f`:

```
FOUND:   app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php   (min_lines 200)
FOUND:   tests/Feature/Publicador/Aprovacao/EnfileirarAoAprovarTest.php   (min_lines 250)
FOUND:   tests/gates/contagens-193-02.txt
FOUND:   app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php
FOUND:   tests/gates/contagens-186-04.txt
FOUND:   .planning/phases/193-…/193-02-SUMMARY.md
MISSING: (nenhum)
```

Commits afirmados, conferidos por `git log --oneline --all | grep`:

```
FOUND:   e341d3ef  fix(193): a regua pedia "contagem IDENTICA"          <- a PARTIDA
FOUND:   ea203f66  docs(quick-261020-ozm)                                <- ALHEIO
FOUND:   05c26309  feat(193): decisao 23                                 <- ALHEIO (a decisao registrada)
FOUND:   5e9d242c  feat(193-02): enfileirar() anexa ou agenda            <- tarefa 1
FOUND:   d0a37eb3  fix(quick-261020-s95)                                 <- ALHEIO
FOUND:   923bf8a4  feat(193-02): o gancho aoAprovar() ligado             <- tarefa 2
FOUND:   c4be8e9d  fix(193-02): os dois furos da mutacao                 <- pos-mutacao
MISSING: (nenhum)
```

`key_links` do plano, conferidos:

```
AndamentoDaOfertaService -> PublicacaoDoPortaoService  via `aoAprovar`       : 1 chamada (substr_count)
PublicacaoDoPortaoService -> FilaPublicacaoService     via `agendar()`       : PRESENTE, reusado
```

## §21 — Threat Flags

**Nenhuma superfície nova fora do `<threat_model>`.** Nenhum endpoint de rede novo (igualdade de
conjunto das 16 rotas), nenhum caminho de autenticação novo, nenhum acesso a arquivo novo e **nenhuma
mudança de schema** (`database/migrations/` com `numstat` vazio por range).

`T-193-SC` (npm/composer installs) conferido por `numstat` **VAZIO** no range `e341d3ef..HEAD` em
`package.json`, `package-lock.json`, `composer.json` e `composer.lock`.

As mitigações do registro desta wave, cada uma com a prova:

| ameaça | mitigada por |
|---|---|
| `T-193-11` escrita de `andamento` fora da única porta | §4 (sonda larga = 0) + `186-04` remedido (+2) + `186-09 andamento-um-lugar-so` idêntico |
| `T-193-12` aprovação sem enfileirar / enfileirada sem prova | §5 (nível de transação > referência; os três campos já gravados) |
| `T-193-13` item de fila duplicado | §2/§3 (um item com DUAS ofertas; mutação m1 mata) |
| `T-193-14` as 4 colunas copiadas | caso 3 com **isca invertida** (elas existem na tabela da fila) |
| `T-193-15` recusa engolida em silêncio | motivo REAL no histórico, ator Sistema, tipo `sys` |
| `T-193-16` job despachado na transação | `Queue::assertNothingPushed()` **com isca**; `dispatch(` = 0 no serviço |
| `T-193-17` segundo passo nascendo "por segurança" | §7, com a **união** das duas sondas e isca nas duas direções |
