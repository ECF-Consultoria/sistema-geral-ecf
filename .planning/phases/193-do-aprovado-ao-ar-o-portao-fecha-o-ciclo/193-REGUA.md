# A régua da Fase 193 — comum aos 5 planos

**Escrita no planejamento, em 20/10/2026, a partir de MEDIÇÃO do código — não de leitura do
briefing.** Os cinco planos referenciam este arquivo em `<context>` em vez de repetir as regras.

⚠️ **Tudo o que está aqui foi medido no HEAD `0607f5a9`.** Onde a medição contradiz o briefing do
orquestrador, a medição está marcada com ⚠️⚠️ **DIVERGÊNCIA MEDIDA** e ganha o comando que a produziu.

---

## §0 — AS DECISÕES DO USUÁRIO QUE MANDAM NESTA FASE — TRAVADAS

| # | decisão | consequência verificável nesta fase |
|---|---|---|
| **1** (14/10) | **APROVAR JÁ PUBLICA**, sem segundo clique e **sem desfazer** | o gancho `aoAprovar()` enfileira **dentro da transação** de `aprovar()`; não nasce rota, action, estado, coluna nem rótulo de confirmação |
| **1b** | ⚠️ **O RÓTULO NÃO MUDA:** continua *"Aprovar para publicação"* | **nenhum plano toca** o literal; ele é da Fase 188 e está no ar |
| **5** (14/10) | a exceção só **REGISTRA quem liberou** | nenhuma peça desta fase lê, cria ou espera "exceção autorizada" |
| **13** (EM ABERTO) | **editar foto de anúncio JÁ NO AR é desta fase decidir** | ⚠️ **MEDIDO e DEVOLVIDO ao usuário** — ver §6, `PERGUNTA-193-B`. **Nenhum plano o implementa.** |
| **20** (20/10) | a revisão detalhada **saiu do escopo** | ⚠️ **nenhum plano cita as Fases 190/191 como fornecedoras de nada**; o caminho é `189 → 193 → 194` |

⚠️ **As alternativas RECUSADAS, para ninguém reabrir:** publicar em dois atos · publicar com janela
para desfazer · botão separado de publicar · aprovação cor por cor · *"desabilitado com explicação"*.

⚠️ **Vocabulário travado:** *"Resultado estimado por venda"* / *"Margem de contribuição estimada"* —
**nunca "lucro líquido"**. *"Em revisão"* **nunca sem qualificar**. Cor **nunca** é o único sinal.
**Os 6 toasts de VOC-08 são FECHADOS** — nenhum plano cunha o sétimo.

---

## §1 — ⚠️⚠️ AS SEIS MEDIÇÕES QUE MUDAM O TAMANHO DESTA FASE

> Todas feitas no HEAD `0607f5a9`, 20/10/2026, com o comando ao lado. **Cada uma delas tira trabalho
> do plano** — e por isso cada uma tem de ser **reconferida pelo executor do `193-01`** antes de o
> resto rodar.

### ⚠️⚠️ M1 — NÃO HÁ MIGRATION. As três colunas de ligação JÁ EXISTEM

```
sed -n '270,312p' database/migrations/2026_10_15_100200_create_pub_aprovacao_ofertas_table.php
```

`pub_aprovacao_ofertas` **já nasceu** (Fase 186, migration de 15/10, **aplicada em produção**) com:

| coluna | definição medida | para que serve na 193 |
|---|---|---|
| `fila_item_id` | `unsignedBigInteger` **nullable**, índice `pubapof_fila_item_ix`, FK `pubapof_fila_item_fk` → `pub_fila_publicacao_itens` **`nullOnDelete`** | a ligação oferta → fila de publicação (PUB-02 / DADO-03) |
| `ml_item_id` | `string(20)` **nullable**, índice `pubapof_mlb_ix` | o MLB confirmado (AND-04) |
| `erro_publicacao` | `string(1000)` **nullable** | o erro REAL (PUB-05) |

➡️ ⚠️⚠️ **A "migration provável (1 coluna de ligação)" do ROADMAP é DESNECESSÁRIA: a coluna existe,
com índice e FK.** Ver a tabela de decisão de schema completa em §2.

### ⚠️⚠️ M2 — `MlPublicacaoService` **NÃO** é quem publica o Publicador

```
grep -n "MlAnuncioRascunho" app/Services/Mlb/Publicacao/MlPublicacaoService.php
```

⚠️⚠️ **DIVERGÊNCIA MEDIDA contra o briefing** (*"é quem publica no ML hoje … a sua fase REUSA esse
serviço"*): `MlPublicacaoService::publicar()` recebe **`MlAnuncioRascunho`** — o rascunho do
**assistente antigo** (`/mlb/anuncios/wizard/{company}`). O Publicador trabalha sobre
**`PubRascunho`** e publica por outro caminho inteiro:

```
PubAprovacaoOferta (aprovado)
  → FilaPublicacaoService::agendar()      (pub_filas_publicacao + pub_fila_publicacao_itens)
  → publicador:fila-publicacao            (AgendadorDaFila, todo minuto)
  → PublicacaoService::iniciar()          (só banco: conferência, trava de conta, uma por rascunho)
  → PublicarRascunhoJob                   (fila `high`)
  → PublicacaoService::executarFatia()    (ÚNICO lugar que POSTa /items)
```

➡️ **O que a 193 reusa é esse caminho** (é exatamente o que PUB-02 manda). **PUB-03 continua valendo
inteiro e fica mais fácil:** `MlPublicacaoService` sai com **`numstat` ZERO** porque ele **não está
no caminho**. ⚠️ **Quem o alterar está errado por dois motivos, não um.**

### ⚠️⚠️ M3 — Toda a disciplina do PUB-06 **JÁ ESTÁ IMPLEMENTADA** em `PublicacaoService`

```
sed -n '36,60p'  app/Services/Publicador/PublicacaoService.php      # o docblock
sed -n '76,122p' app/Services/Publicador/PublicacaoService.php      # iniciar()
```

| o que PUB-06 exige | onde já está |
|---|---|
| trava de conta | `ContasLiberadas::exigir($ancora)` **antes de qualquer gravação** (`iniciar()`), e `ContasLiberadas` é **fail-closed** (lista vazia = ninguém) |
| registro **ANTES** do HTTP | `PubPublicacaoItem` gravado **`SENT` antes do POST** (docblock `:49`) |
| `5xx`/rede = **incerto** | `UNKNOWN` |
| sem reenvio por conta própria | `retryUntil(30 min)`, trava por publicação, e **nunca** POST novo para `SENT` |
| `UNKNOWN` só depois de reconciliar pelo SKU | **RN-93**, `reconciliar_apos_segundos` |

➡️ ⚠️ **A 193 NÃO reimplementa nada disso. Ela PROVA por NÃO-REGRESSÃO** e garante que o
reconciliador **jamais** toca item `UNKNOWN`/`SENT`/`PENDING`.

### ⚠️ M4 — a GRANULARIDADE já está resolvida pelo schema, e o docblock diz como

```
grep -n "filaItem\|as duas ofertas do produto apontam para o MESMO item" app/Models/PubAprovacaoOferta.php
```

`PubAprovacaoOferta::filaItem()` (BelongsTo) existe, e o docblock dela diz textualmente: *"Um produto
está em UMA fila por vez (o unique `pubfilai_produto_ativo_uq`), então **as duas ofertas do produto
apontam para o MESMO item** — e isso é correto: a fila publica o produto inteiro."*

➡️ **A resolução de DADO-03/PUB-02 é:** `N` ofertas (Clássico, Premium) → **1** `pub_fila_publicacao_itens`
por **produto**; `validacao_id`/`plano_hash`/`revisao`/`ciente`/`precisa_revisar` são **LIDOS** por
`fila_item_id` e **nunca copiados** (`precisa_revisar` não é coluna em lugar nenhum — é VALOR do
`status` da fila). ⚠️ **Nenhum plano cria coluna com esses nomes, nem muda a semântica deles.**

### ⚠️ M5 — o MLB por oferta sai do `listing_type_id`, e há PRECEDENTE EXATO

```
sed -n '62,72p'   database/migrations/2026_10_01_200000_create_publicador_tables.php   # pub_rascunho_alvos.listing_type_id
sed -n '691,716p' app/Services/Publicador/PublicacaoService.php                        # cadastrarNaRegua()
```

`PubPublicacaoItem` tem `listing_type_id` + `variante_chave`; a oferta tem `alvo_id` →
`alvo.listing_type_id`. O precedente de "qual MLB é o desta modalidade" já está escrito:
`$criados->groupBy('listing_type_id')` + `$doTipo->first()` sobre `sortBy('indice')`.
➡️ **A 193 usa a MESMA regra, nunca uma segunda.**

### ⚠️ M6 — o gancho, a rota do retry e os 6 toasts **já existem e estão esperando**

```
grep -n "aoAprovar" app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php tests/Unit/Publicador/Aprovacao/AndamentoDaOfertaServiceTest.php
grep -n "tentar-de-novo" routes/mlb_anuncios.php
grep -n "publicado:" resources/js/Components/Mlb/Publicador/aprovacao/vocabularioDoPortao.jsx
```

- `AndamentoDaOfertaService::aoAprovar()` — **vazio, documentado como "É LIGADO PELA FASE 193"**, e há
  asserção viva (`method_exists(..., 'aoAprovar')`, *"o ponto de extensão da Fase 193 desapareceu"*);
- `POST …/aprovacao/{oferta}/tentar-de-novo` + `MlbPublicadorAprovacaoController::tentarDeNovo` —
  **existem** (Fase 188) e o comentário da rota diz *"A escrita no Mercado Livre segue sendo da Fase
  193"*;
- a **caixa de falha** do painel (`PainelDeRevisaoService::falha()`) já lê `erro_publicacao` e já
  mostra *"Tentar publicar novamente"*;
- os **6 toasts** de VOC-08 existem em `vocabularioDoPortao.jsx`, inclusive
  `publicado: 'Publicado e confirmado no Mercado Livre · MLB…'`.

➡️ ⚠️⚠️ **CONSEQUÊNCIA: esta fase é SÓ SERVIDOR.** Nenhum plano edita `.jsx`, nenhum plano roda
`npm run build` por necessidade própria (o `193-05` o roda **só** para provar não-regressão do
manifest), e **o "UI hint: yes" do ROADMAP é uma superestimativa MEDIDA** — ver §7.

---

## §2 — A TABELA DE DECISÃO DE SCHEMA — e o veredicto é **NENHUMA MIGRATION**

⚠️ O briefing exige a tabela "com as armadilhas 1059/1830/1553/enum endereçadas uma por uma". Ela
está abaixo, e a conclusão é que **as três colunas de que a fase precisa já existem e já obedecem a
todas elas** — porque foi a Fase 186 que as escreveu, sob a mesma disciplina MIG-01..09.

| coluna que a 193 precisa | existe? | tipo / nulabilidade | índice | FK | por quê |
|---|---|---|---|---|---|
| ligação oferta → fila | ✅ `fila_item_id` | `unsignedBigInteger` **nullable** | `pubapof_fila_item_ix` (**26 chars**) | `pubapof_fila_item_fk` → `pub_fila_publicacao_itens` **`nullOnDelete`** | a fila é por PRODUTO; a oferta aponta para o item vivo dela |
| MLB confirmado | ✅ `ml_item_id` | `string(20)` **nullable** | `pubapof_mlb_ix` (**14 chars**) | — | AND-04: `publicado` exige MLB |
| erro real | ✅ `erro_publicacao` | `string(1000)` **nullable** | — | — | PUB-05: o erro real, nunca "algo deu errado" |

| armadilha | como está endereçada **sem escrever migration nova** |
|---|---|
| **1059** (MariaDB recusa nome de índice > 64) | os índices foram **nomeados à mão** pela 186; o maior do arquivo é `pub_aprovacao_ofertas_responsavel_id_foreign` com **44** chars (contado no docblock da própria migration). ⚠️ **Nada a fazer** |
| **1830** (FK `nullOnDelete` exige `->nullable()`) | `fila_item_id` é `nullable()` **e** `nullOnDelete` — o par correto. ⚠️ O SQLite dos testes não pegaria; quem pegou foi a 186 |
| **1553** (dropar índice usado por FK) | **nenhum índice é dropado** nesta fase. ⚠️ Gate de ordem `dropForeign → dropIndex` aqui seria **gate sobre ZERO casos** — e por isso **não entra** |
| **enum + CHECK do SQLite** | `andamento` e `status` já são `string()` + constantes no model. **Nada a fazer** |
| **`up()` idempotente / sem `try/catch` em DDL** | não há DDL nova |
| **dado em produção** | há, desde a 186 — e é **justamente por isso** que as três colunas são nullable. **Nenhuma linha é alterada em massa** |
| **MySQL 8.0.46 (prod) × MariaDB 10.4.32 (local) × SQLite (testes)** | sem DDL, a divergência dos motores **não é exercida** nesta fase. ⚠️ **Declarar isso é obrigatório**, em vez de deixar o leitor supor que foi testado |
| **`float` de valor inteiro sobrevivendo ao `json_encode`** | nenhuma coluna nova de valor; o payload do painel é da 188 |

### ⚠️⚠️ A PROVA, e ela é uma ASSERÇÃO, não uma afirmação (plano `193-01`, tarefa 2)

O `193-01` grava um teste que lê o **schema vivo** e exige as três colunas com a nulabilidade certa,
**mais a ausência de migration nova desta fase** — com **ISCA INVERTIDA**: a lista de migrations de
`*pub_aprovacao*` tem de ser **NÃO-vazia** (senão a varredura está cega) e a contagem é **FIXA e
coletora** (nunca "uma asserção por arquivo" — é a armadilha §4b.2, que já subiu uma régua de 5 para
8 nesta milestone).

⚠️⚠️ **E A TRAVA:** se o executor concluir que **falta** alguma coluna, ele **PARA e devolve a
pergunta** — porque isso contradiz M1, e inventar migration numa tabela com dado em produção, no
meio de uma fase que mediu que não precisa, é exatamente o risco que esta régua existe para fechar.

### ⚠️ A prova no banco local — e ela **NÃO PODE SER FEITA NESTA MÁQUINA**

```
tasklist | grep mysqld   →   (vazio)
```

⚠️⚠️ **O MariaDB local está PARADO** (medido em 20/10/2026), e ele já esteve **CORROMPIDO** neste
projeto. ➡️ Como **não há migration**, o ritual `migrate → rollback → migrate` **não se aplica** —
e isso é **declarado**, não omitido. ⚠️ **Se alguém reabrir a decisão e escrever migration, o ritual
volta a ser obrigatório**, com `migrate:rollback --batch=<N> --path=<o seu arquivo>` (⚠️ **nunca
`--step=1`**: o banco local é compartilhado e `--step` derruba a migration da sessão paralela — §4b.4
do learnings).

---

## §3 — A ARQUITETURA DESTA FASE, EM UMA PÁGINA

```
APROVAR (Fase 188, no ar)
  AprovacaoDaOfertaService::aprovar()      ── recusa por bloqueio / fora do live / versão divergente
    DB::transaction
      AndamentoDaOfertaService::mover(APROVADO)   ← A ÚNICA PORTA
        grava andamento + aprovado_por/aprovado_em/versao_aprovada
        registrar()   → histórico                            (ligado pela 186-05)
        aoAprovar()   → ⚠️ A FASE 193 LIGA AQUI               (vazio hoje)
             └── PublicacaoDoPortaoService::enfileirar()   [NOVO, plano 193-02]
                   · produto já tem item VIVO na fila? → ANEXA (fila_item_id = aquele item)
                   · senão → FilaPublicacaoService::agendar()  → fila_item_id = o item criado
                   · recusado? → registra o MOTIVO no histórico e DEIXA em `aprovado`
                                 ("aguardando publicação" — é a verdade), p/ o reconciliador tentar
      tocado = true; composicao->congelar()

PUBLICAR (o que já existe, REUSADO sem alteração)
  publicador:fila-publicacao (todo minuto) → AgendadorDaFila → PublicacaoService::iniciar()
    → PublicarRascunhoJob (fila `high`) → executarFatia(): SENT antes do POST, 4xx=FAILED,
      5xx/rede=UNKNOWN, RN-93 por SKU, sem reenvio por conta própria

ESPELHAR o que aconteceu  [NOVO, plano 193-03/04]
  publicador:portao-publicacao (todo minuto, logo depois) → PublicacaoDoPortaoService::reconciliar()
    · aprovado + sem item vivo            → enfileirar() de novo (a 2ª chance)
    · item `publicando`                   → mover(PUBLICANDO)      ← bloqueia edição
    · item terminado, CREATED do MEU tipo → grava ml_item_id e DEPOIS mover(PUBLICADO)
    · item terminado, nada criado do meu  → mover(FALHA, <erro REAL>)
    · item com PENDING/SENT/UNKNOWN       → NÃO FAZ NADA (RN-93)

TENTAR DE NOVO  [plano 193-04]
  POST …/aprovacao/{oferta}/tentar-de-novo  (rota da 188, existe)
    → enfileirar() PRIMEIRO, e só então tentarDeNovo() → mover(PUBLICANDO)
```

### ⚠️⚠️ As cinco invariantes que a arquitetura compra, e por que cada uma importa

1. **A escrita de `andamento` continua num lugar só.** O serviço novo **nunca** escreve `andamento`;
   ele chama `mover()`. ⚠️ E **nunca** contém o literal `'andamento' =>` (a sonda larga do gate
   `andamento-um-lugar-so` casa isso em **qualquer** array, inclusive de saída — foi assim que dois
   expositores legítimos precisaram de exceção PROVADA).
2. **`publicado` só com MLB** não depende do serviço novo: a **porta recusa**
   (`REGRA_SEM_MLB`). O reconciliador grava `ml_item_id` **antes** e a porta é quem autoriza. ⚠️ Se
   alguém inverter a ordem, a própria porta reprova — o gate existe no código, não só no teste.
3. **A falha preserva a revisão** porque `mover(FALHA)` **não toca** `versao`,
   `versao_aprovada`, `aprovado_por` nem `aprovado_em` (medido em `mover()`), e
   `mover(PUBLICANDO)` **limpa `erro_publicacao`** — o erro da tentativa velha não aparece na nova.
4. **`publicando` bloqueia edição** sem código novo: `podeEditar()` é `ehLive()`, e `publicando`
   não está em `LIVE`. ⚠️ A 193 **prova**, não implementa.
5. **Nenhum job novo, nenhuma fila nova.** Ver §4.

---

## §4 — ⚠️⚠️ EM QUE FILA ISSO RODA, E POR QUÊ (resposta exigida, medida)

| peça | onde roda | por quê |
|---|---|---|
| `enfileirar()` (no clique de aprovar) | **no request**, dentro da transação | é **só banco** (cria/anexa linha de fila). ⚠️ **Nenhum `dispatch()` dentro de transação** — job despachado antes do commit roda sem ver os dados |
| `publicador:portao-publicacao` | **scheduler**, `everyMinute()->withoutOverlapping(10)->onOneServer()` | mesma forma do `publicador:fila-publicacao`, logo **depois** dele. **Não é fila**: é um passo de minuto, idempotente, que só LÊ o banco e move andamento |
| a publicação em si | **`PublicarRascunhoJob`, fila `high`** — **já é assim, e não muda** | ⚠️⚠️ **A razão está escrita no projeto, não é minha:** a fila de produção é **Redis** (a tabela `jobs` dá **0** e engana), e em **16/09/2026** **179 jobs de acervo do ML na `default`** seguraram um webhook da Clicksign **por HORAS**. Publicação disparada por gente que aprovou **não espera atrás do sync do acervo**. ⚠️ **Não é `creative`** (fila do outro dev) e **não é `default`** |

⚠️ **Depois do `deploy.sh` (que só reinicia `ecf-worker:*`), é obrigatório
`sudo -u www-data php artisan queue:restart`** para pegar `ecf-worker-high` **sem matar job em
andamento** (`supervisorctl restart` mataria uma publicação no meio). ⚠️ **Esta fase NÃO deploya** —
a instrução fica registrada para quem for deployar.

### ⚠️ A trava operacional já existe: `ContasLiberadas` (fail-closed)

`config('publicador.contas_liberadas.*')`; **lista vazia = NINGUÉM**. ➡️ **Nenhum plano cria
feature-flag nova** para "ligar/desligar publicar": a chave de freio é a lista de contas liberadas,
que é a mesma trava que PUB-06 exige. ⚠️ Criar um segundo interruptor faria **duas verdades** sobre
quem pode publicar.

---

## §5 — GATES: o que vale nesta fase

⚠️ **Leitura obrigatória antes de escrever qualquer gate:**
`.planning/learnings/gates-do-redesign-e-o-medidor-que-mente.md` **INTEIRO** (13 casos de asserção
vazia, as 9 normalizações do CSS, §4b, §4c — incluindo **§4c.3, o scratchpad compartilhado** — e
§4d.1-§4d.4).

### A forma

- ⚠️⚠️ **TODO gate passa por `sh tests/gates/verificar-testes.sh 193-NN <id>`.** O 1º argumento é
  **`193-NN`**, **NUNCA `NN` nu** — `07` sozinho resolve `contagens-186-07.txt`, a fase **errada**,
  **em silêncio** (compatibilidade deliberada: os 163 ids da 186 são chamados pelo número nu, e a 186
  está em produção).
- **Cada plano cria `tests/gates/contagens-193-NN.txt`**; manifesto incompleto **ABORTA**. Cabeçalho
  no modelo do `contagens-188-05.txt`.
- ⚠️ **Nunca** `node --test`/`artisan test` direto num gate (o reporter do Node v24 emite **`ℹ`**;
  `grep -E "Tests:|OK"` **passa com teste falhando**).
- ⚠️⚠️ **NUNCA ler `${TMPDIR:-/tmp}/ultima-suite-php.log` enquanto uma suíte roda** — caminho
  **fixo e compartilhado**; a wave 188-07 leu e reportou **`0 falhas` quando eram 11**.
- **`--autoteste` tem de passar 20/0**, e cada plano **cola a saída**.
- ⚠️ `--filter` do PHPUnit é regex **NÃO ANCORADO** e as Fases 186/187/188 estão **em produção** →
  **nenhum id com mais de TRÊS métodos**, e **sufixo `_193_NN` em TODO método novo**.
- ⚠️ **Bijeção por script, nos DOIS sentidos, com EXPANSOR DE ALTERNÂNCIA** (`/test_[A-Za-z0-9_]+/`
  casa **ZERO** em `test_(a|b|c)`, e esse furo deu `SEM_ID=14` **acusando trabalho correto**), mais a
  **terceira bijeção** id ↔ `<verify>` (id órfão **não falha: nunca roda**).
- ⚠️ `php` não está no PATH: **`C:/xampp/php/php.exe`**. `artisan test --filter=<Classe>` passa dos
  120 s → **por método**. `artisan tinker` precisa de `< /dev/null`.

### As armadilhas que esta fase vai encostar

| armadilha | como desviar aqui |
|---|---|
| **palavra no DOCBLOCK reprova pelo próprio conserto** | ler o código **SEM COMENTÁRIO** (`token_get_all`, nunca regex). ⚠️ A prosa desta fase **cita** *"confirmar publicação"*, *"desfazer"*, *"segundo passo"* e *"cancelar aprovação"* de propósito — a **isca do leitor** |
| **`preg_match(...) === 1` passa com a linha de código APAGADA** quando o texto também existe em comentário | **contagem de ocorrências** (`substr_count`), lida sem comentário — **nunca** `grep -c` (conta LINHAS: já deu 12 e 724 para uma âncora de uma ocorrência) |
| **âncora em número que muda legitimamente** (13+ casos nesta milestone) | lista **CONSTANTE** de nomes, derivação de **UMA** fonte, ou reaponte para a **invariante**. ⚠️ **NUNCA subir o número** |
| **piso `>= N` não prova PRESENÇA** | igualdade de **CONJUNTO** (com 13 perguntas, perder uma deixava 12 e o piso passava) |
| **ancorar por LINHA** | **por NOME/semântica** — um gate em `'Servico:131'` quebrou quando outro dev deslocou a linha |
| **asserção VAZIA quando escrita, FALSO POSITIVO depois** | de toda negativa, perguntar *"o desenho PROÍBE isso, ou eu só não vi ainda?"*; **ISCA INVERTIDA** quando a lista legítima não pode estar vazia |
| **gate mais largo que o invariante** (14 casos, um **barrou um deploy**) | ancorar no **COMPORTAMENTO** |
| **`git diff --stat -- <dir>` depois de commitar sai VAZIO SEMPRE** | **`<partida>..HEAD`** |
| **`json_encode` ESCAPA a barra** | sonda de ausência de caminho com `/` é **vazia por construção** |
| **tautologia de literal** (6 outros literais da mesma tela, por omissão) | expectativa **CONGELADA** no gate, por **igualdade de STRING**, com isca do quase-igual |

### ⚠️⚠️ Os gates ALHEIOS que esta fase MEXE — e os que ela NÃO mexe

**MEXE (remedição obrigatória, declarada, MEDINDO as duas pontas):**

| manifesto / id | por que mexe | o que fazer |
|---|---|---|
| `contagens-186-04.txt` → **`unica-porta`** e **`oferta-do-portao`** | ⚠️ a **camada B é POR GLOB**: `glob('app/Services/Publicador/Aprovacao/*.php')`, **2 asserções POR ARQUIVO**. O próprio manifesto declara: *"CONTAGEM FRÁGIL POR CONSTRUÇÃO, declarada: todo arquivo novo no portão move este número"* (41 e 65 hoje; subiram 33→41 e 57→65 quando a 186-08 criou 4 arquivos) | **REMEDIR**, nunca ajustar: medir **antes** e **depois**, remover o valor antigo, colar as duas saídas. ⚠️ **Se o número NÃO subir, PARE** — significa que o arquivo novo caiu fora do glob (ver abaixo) |

⚠️⚠️ **E É POR ISSO QUE O SERVIÇO NOVO MORA EM `app/Services/Publicador/Aprovacao/` — NA RAIZ.** O
glob **não é recursivo**: um serviço em `Aprovacao/Publicacao/` **não seria varrido** e o gate da
única porta **passaria por omissão** para ele. O lugar não é estilo; é cobertura.

**NÃO MEXE — ⚠️ e o invariante NÃO é "contagem idêntica", é "contagem não mudada POR VOCÊ":**

> ⚠️⚠️ **CORRIGIDO EM 20/10/2026 pelo orquestrador, e a correção veio de um achado do executor do
> `193-01`.** A redação original dizia *"tem de continuar com a contagem IDÊNTICA — mudou, PARE e
> devolva"*. **Ela já estava obsoleta quando foi escrita**, e um executor que a seguisse ao pé da letra
> **PARARIA POR NADA.**
>
> **MEDIDO:** o commit **`56ade5f0`** (AG-07, de **outra sessão**) acrescentou um expositor só-leitura
> (`LeituraDoAgente`) à lista do `andamento-um-lugar-so`, **com a prova que a estrutura do gate cobra**,
> e remediu o manifesto: **`assertions=20 → 21`** e, no composto que inclui aquele filtro,
> **`assertions=258 → 259`**. ⚠️ **O próprio `contagens-186-09.txt` declara a razão EM LINHA**, com as
> palavras *"Remedido, nao ajustado"* — que é exatamente a disciplina certa.
>
> ➡️ **A pergunta que o executor faz NÃO é *"o número é o mesmo?"*, e sim *"o número mudou por causa
> do MEU trabalho?"*.** Se mudou por commit alheio **com a razão escrita no manifesto**, isso **não é
> regressão da 193** e **não é motivo de parada**: meça, atribua por **`git show --name-only` do commit
> que criou/alterou o arquivo** (⚠️ **nunca por range** — o range engloba os seus próprios commits, e a
> 189-07 caiu nisso), **registre na SUMMARY** e siga. ⚠️ **Se mudou por causa do SEU trabalho, aí sim:
> PARE e devolva**, porque a Fase 186 está **em produção** e a decisão dela foi *"arquivo novo no portão
> NÃO entra aqui sozinho"*.
>
> ⚠️ **E não fixe os números aqui de novo.** Escrever `21`/`259` nesta régua recriaria o mesmo defeito
> daqui a um commit alheio — é a família *"âncora em número que muda legitimamente"*, com **QUINZE**
> ocorrências consertadas na raiz nesta milestone e **zero** por elevação de número. **A fonte do número
> é o manifesto; esta régua só diz de quem ele pode mudar.**


- `contagens-186-09.txt` (`transversais`, `sem-segundo-passo`, `andamento-um-lugar-so`, …) —
  ⚠️ o docblock de `PRODUCAO_PHP_DO_PORTAO` diz, textualmente: *"**Arquivo novo no portão NÃO entra
  aqui sozinho**: entrar é ato deliberado de quem o criou, e é esse o preço de não ter contagem
  frágil. O gate `unica-porta` do plano 186-04 (que **é** por glob, de propósito) continua pegando o
  arquivo novo pela camada dele."* ➡️ **A Fase 186 JÁ DECIDIU: não entrar.** A 193 faz o seu
  **próprio** gate de ausência sobre a **sua** lista constante — igual ao `ausencia-de-segundo-passo`
  do 188-05.
- `contagens-188-05.txt` (`tentar-de-novo`, `aprovar-e-enfileirar`) — ⚠️⚠️ **os dois têm de
  CONTINUAR VERDES depois de a 193 ligar a publicação**, e isso é medição, não torcida: as asserções
  deles são *"zero ESCRITA no Mercado Livre"*, e enfileirar é **só banco**. ⚠️ **MAS: se o executor
  medir que `FilaPublicacaoService::agendar()` faz ALGUMA requisição** (a prontidão passa por
  `ResumoRapidoService`), a saída é **acrescentar a LEITURA à lista CONSTANTE nomeada daqueles
  gates** — **nunca** afrouxar a sonda, nunca trocar "zero escrita" por "zero requisição".
- `tests/js/estrutura-meus-anuncios.test.js` (**D-11 e D-22, INTOCÁVEIS**) e
  `tests/js/publicador-header-zindex-ordem.test.js` (**7/7/0, INTOCÁVEL**).

### ⚠️ Mutação — a forma, e ela é obrigatória

**COMMITE ANTES.** `cp -p` para fora do repo. Script com **nome que carrega o plano**
(`mutar-193-NN.sh`, `copia-193-NN-<caminho>`, `out-193-NN-<rotulo>.txt`) — ⚠️⚠️ **o scratchpad é
COMPARTILHADO entre subagentes paralelos e um `mutar.sh` já foi SOBRESCRITO no meio da campanha,
custando a campanha inteira**; confira o `sha256` **do próprio script** antes de confiar no veredicto.
Ocorrência **travada em 1 ANTES** por string exata; **qual linha mudou** conferida depois;
restauração **DA CÓPIA** em `trap EXIT`; `sha256` contra `git show HEAD:` **com `tr -d '\r'` nos dois
lados**. ⚠️⚠️ **`git checkout` NUNCA.** ⚠️⚠️ **SOBREVIVENTE É INVESTIGADA**, nunca presumida
equivalente — nesta milestone isso rendeu uma que matava o gate **pela asserção errada** e outra que
**acusou furo real**.

### ⚠️ Sem paralelismo nesta fase — por decisão

As cinco waves são **sequenciais** (`01 → 02 → 03 → 04 → 05`), e isso é escolha: os planos 02-04
editam **o mesmo arquivo novo**, **três agentes caíram na Fase 187** (um deixou 2/3 de um plano sem
commit), **duas suítes PHPUnit simultâneas disputam `storage/framework/testing`** e produzem falhas
que **passam em isolamento**, e o **scratchpad é compartilhado**. ➡️ **Commit atômico por tarefa**, e
**se o turno pesar: PARE, commite o provado e diga em que tarefa parou.**

---

## §6 — AS PERGUNTAS DESTA FASE (forma fixa; **"a definir" NÃO é dono**)

### ⚠️⚠️ PERGUNTA-193-A — o que acontece com a oferta aprovada que a fila RECUSA?

**Dono: USUÁRIO. Não bloqueia o início da fase; decide antes do `193-03` fechar.**

**MEDIDO:** `FilaPublicacaoService::agendar()` só aceita produto **`pronto`**, e
`ResumoRapidoService::prontidaoDoLote()` tem **11 motivos** de recusa, cada um com frase pt-BR
pronta. ⚠️⚠️ **E nenhum dos 8 bloqueios do portão** (`fotos_a_conferir`, `sem_fotos`,
`frete_ausente`, `resultado_negativo`, `margem_abaixo_do_minimo`, `alteracao_nao_conferida`,
`ajuste_em_aberto`, `titulo_longo`) **cobre a conferência L3 com o Mercado Livre.** Logo existe uma
janela: aprovar pode suceder e o enfileiramento ser recusado por *"O produto mudou depois da
conferência: confira de novo"*.

⚠️ **O SISTEMA ESTÁ SEGURO NAS DUAS SAÍDAS** — nada vai ao ar sem conferência válida; o que está em
jogo é **a UX da oferta que fica parada**.

| saída | o que acontece | custo |
|---|---|---|
| **(a) — PLANEJADA POR DEFAULT** | a oferta fica em **`aprovado`** (cujo rótulo do próprio protótipo é *"aguardando publicação"* — **é a verdade**), o **motivo real** é registrado no histórico da oferta (DADO-10, já visível no painel), e o reconciliador **tenta de novo a cada minuto** | zero território, zero migration, zero tela. ⚠️ Enquanto o motivo não for resolvido no editor, a oferta fica parada **com o motivo escrito**, não em silêncio |
| (b) | **9º bloqueio no portão** ("conferência do Mercado Livre pendente"), impedindo aprovar | ⚠️ exige editar `VocabularioDoPortao` e `BloqueiosDaOfertaService` — **serviços da Fase 186, em produção**, com gates de total fixo. **Precisa de autorização explícita do usuário** |
| (c) | `aprovado → publicando → falha` imediato | ⚠️ **recusada no planejamento**: passaria por `publicando` **sem nada em voo** — um estado que mente, numa máquina em que `publicando` bloqueia edição |

➡️ **A pergunta ao usuário:** fica em (a), ou ele quer (b) — que é mais cedo e mais explícito, e
custa tocar dois serviços da 186?

### ⚠️⚠️ PERGUNTA-193-B — editar a FOTO de um anúncio JÁ NO AR (a decisão 13, que é desta fase)

**Dono: USUÁRIO. ⚠️ Medida e DEVOLVIDA — nenhum plano a implementa.**

**MEDIDO:**
- `PubAprovacaoOferta::TRANSICOES` tem **`PUBLICADO => []`** — terminal por **estrutura**; nenhuma
  transição sai dele;
- ⚠️⚠️ **não existe NENHUM `PUT /items/{id}` em todo o Publicador**
  (`grep -rn "PUT" app/Services/Publicador/` devolve só `/seller-promotions/*`). Trocar foto de
  anúncio no ar é uma **capacidade de escrita no ML que o sistema não tem**;
- **nenhum dos 10 critérios de sucesso da fase, e nenhum de PUB-01..10, a pede.**

➡️ **Recomendação do planejamento: DIFERIR**, registrando em `deferred-items.md` com dono. ⚠️ **Mas
a decisão é do usuário**, porque foi a ele que a decisão 13 do ROADMAP a atribuiu. ⚠️ **Se ele
quiser agora, isto NÃO cabe nesta fase** — é um fluxo novo (transição nova saindo de um estado
terminal + escrita `PUT` inexistente + reconciliação de quem é o dono da foto no ar), e a saída certa
é **fase própria**, não um plano a mais aqui.

### PERGUNTA-193-C — o toast *"Publicado e confirmado"* nunca vai disparar sozinho

**Dono: Fase 194 ou fase futura de tela.**

**MEDIDO:** a confirmação do MLB chega **minutos depois**, pelo scheduler. O toast existe
(`vocabularioDoPortao.jsx`, `publicado:`) e **só dispararia** se a tela recarregasse ou houvesse
polling — e **não há polling**. ⚠️ **Isto NÃO é defeito desta fase**: o critério 4 exige justamente
que ele **NÃO dispare sem MLB**, e é uma asserção de **AUSÊNCIA**. ➡️ Registrado para a fase que
decidir se a fila atualiza sozinha.

### PERGUNTA-193-D — herdada e **NÃO** desta fase

**PERGUNTA-17** (`mlb_empresa_id` anulável deixando a oferta invisível na fila) é da **Fase 194**.
⚠️ **Nenhum plano daqui a conserta**, e nenhum plano daqui **cria oferta** — então nenhum a agrava.

---

## §7 — ⚠️ COMO O USUÁRIO CONFERE — e o que NENHUM EXECUTOR pode afirmar

⚠️⚠️ **ESTA FASE NÃO PODE SER VALIDADA DE PONTA A PONTA NESTA MÁQUINA, e isto é MEDIDO:**

| o que falta | medição |
|---|---|
| navegador | não há |
| banco local | ⚠️ `tasklist \| grep mysqld` → **vazio** (MariaDB **parado**; já esteve corrompido) |
| empresa com `ml_token` | nenhuma → a rota dá **404** |
| `pub_aprovacao_ofertas` | **VAZIA** |
| quem cria oferta | ⚠️⚠️ **CORRIGIDO EM 21/10/2026 — esta linha ficou FALSA.** **`app/Services/Publicador/Agente/PreparadorDaAprovacao.php:148` chama `PubAprovacaoOferta::create([…])`** (quick `261020-oeg`, de **outra sessão**) — é o **primeiro código de produção que CRIA oferta**, com `origem` automática e andamento **só via `AndamentoDaOfertaService::mover()`**. ⚠️ **Nenhuma loja tem o agente ligado, então `pub_aprovacao_ofertas` SEGUE VAZIA em produção.** ➡️ **Consequência para o critério 10: ele passa a ser executável depois do PILOTO DO AGENTE, não só depois da Fase 194** — e isso **antecipa** a prova de ponta a ponta. ⚠️ **O `193-05-PLAN.md:201-202` repete a afirmação antiga e NÃO foi editado** (plano é artefato do planejador): **esta linha o SUPERSEDE**, e quem executar o 193-05 recebe o aviso no briefing. |

➡️ **A prova desta fase é TESTE com `Http::fake` + `preventStrayRequests()`**, nunca tela.

### ⚠️⚠️ AS DECISÕES DO USUÁRIO QUE MANDAM NESTA FASE — e ONDE elas moram

> ⚠️ **Posto aqui porque o executor do `193-01` procurou a decisão 21 e NÃO a achou em artefato da fase** —
> ela está no `ROADMAP.md`, na tabela de decisões, e a régua não apontava para lá.
>
> | decisão | o quê | onde ler |
> |---|---|---|
> | **1** | **aprovar JÁ PUBLICA**, sem segundo clique, e o rótulo **não muda** (*"Aprovar para publicação"*) | `ROADMAP.md`, tabela de decisões |
> | **5** | o bloqueio de fotos cobre **TODOS** os grupos; **aprovação cor por cor foi RECUSADA** | idem |
> | **20** | ⛔ a **revisão detalhada saiu do escopo** (Fases 190/191 **canceladas**); ordem **189→193→194→192** | idem (commit `0607f5a9`) |
> | **21** | a oferta que a **fila recusa** fica `aprovado`, motivo no histórico, reconciliador repete. **O 9º bloqueio foi OFERECIDO e RECUSADO** | idem (commit `838d1d57`) |
> | **22** | editar foto de anúncio **já no ar** fica **diferida** para fase própria (medido: `PUBLICADO => []` terminal, e **nenhum `PUT /items/{id}` em todo o Publicador**) | idem |
> | **23** | ⚠️ **aprovar UMA modalidade NÃO enfileira o produto**: sem **todos** os alvos ativos aprovados, a oferta fica `aprovado` **sem `fila_item_id`**, com o motivo no histórico — **mesmo mecanismo da 21**. A guarda vive no `enfileirar()` do **193-02** | idem |
>
> ⚠️ **A decisão 23 nasceu de um risco que OUTRA SESSÃO levantou e o orquestrador CONFIRMOU por medição:**
> a fila publica **todos os alvos ativos de uma vez** (`PayloadBuilderUserProducts.php:60`), e um produto está
> em **uma fila por vez** com **as duas ofertas apontando para o MESMO item** — logo enfileirar "o produto"
> na aprovação do Clássico **publicaria o Premium sem revisão**. ⚠️ **Produto com UM alvo ativo segue
> enfileirando normalmente** — é o caminho do agente hoje. **Ancorar em `pub_rascunho_alvos.ativo`.**
⚠️⚠️ **NENHUM EXECUTOR AFIRMA TER VISTO TELA RENDERIZADA** nem publicação real.
➡️ O **critério 10** (medir o caminho completo numa oferta real, em produção, conta 459) é
**`checkpoint:human-verify` no `193-05`**, e ele só é executável **depois da Fase 194** (que cria a
primeira oferta). ⚠️ **O plano declara isso em vez de prometer o que não pode entregar.**

---

## §8 — TERRITÓRIO DESTA FASE

### ⚠️ O que esta fase EDITA (a lista é fechada; interseção com qualquer outra coisa = violação)

| arquivo | plano | natureza |
|---|---|---|
| `app/Services/Publicador/Aprovacao/PublicacaoDoPortaoService.php` | 193-02/03 | **NOVO** (na RAIZ de `Aprovacao/`, ver §5) |
| `app/Services/Publicador/Aprovacao/AndamentoDaOfertaService.php` | 193-02 | ⚠️ **SÓ o corpo de `aoAprovar()` + a dependência no construtor** — igual ao que a 186-05 fez com `registrar()`. `mover()`, `TRANSICOES` e a transação única **INTACTOS**, provado por diff |
| `app/Console/Commands/PublicadorPortaoPublicacao.php` | 193-04 | **NOVO**, ⚠️ **zero lógica própria** (modelo: `PublicadorFilaPublicacao.php`) |
| `routes/console.php` | 193-04 | ⚠️ **só o bloco novo**, logo depois de `publicador:fila-publicacao` |
| `app/Http/Controllers/MlbPublicadorAprovacaoController.php` | 193-04 | ⚠️ **só a action `tentarDeNovo`** — a rota e o comentário dela, da Fase 188, **declaram** que a escrita no ML é da 193 |
| `tests/Unit/Publicador/Aprovacao/*193*`, `tests/Feature/Publicador/Aprovacao/*193*` | 02-05 | **NOVOS** |
| `tests/gates/contagens-193-0{1..5}.txt` | cada plano | **NOVOS** |
| `tests/gates/contagens-186-04.txt` | 193-02 | ⚠️ **só os ids `unica-porta` e `oferta-do-portao`**, **REMEDIDOS** |
| `.planning/phases/193-…/*.md` | cada plano | **NOVOS** |

### ⚠️⚠️ NÃO ALTERAR — só REUSAR

`app/Services/Mlb/Publicacao/MlPublicacaoService.php` (⚠️ **`numstat` ZERO, provado por range**) ·
`App\Support\Publicador` (inclusive `RegraViolada` e `ContasLiberadas` — **usadas**, nunca alteradas) ·
`AprovacaoDaOfertaService` · `BloqueiosDaOfertaService` · `VocabularioDoPortao` ·
`ComposicaoFinanceiraService` · `HistoricoDaOfertaService` (**chamado**, não alterado) ·
`RevisaoDeImagemService` · `AjustesDaOfertaService` · todo `Aprovacao/Fila/` e `Aprovacao/Painel/` ·
`FilaPublicacaoService` · `AgendadorDaFila` · `PublicacaoService` · `PublicarRascunhoJob` ·
`ResumoRapidoService` · **todo `.jsx`** de 186/187/188/189 (`CascaDeModal.jsx`,
`vocabularioDoPortao.jsx`, `andamentoDoPortao.jsx`, `SeloDeAndamentoInterno.jsx`, `ToastDaFila.jsx`,
`PainelDeRevisao.jsx`, `Aprovacao.jsx`, …).

### ⚠️ NÃO TOCAR

`app/Services/Creative/` · endpoints `criativo*` · `MlbPublicadorCriativoController` ·
`AnunciarML.jsx` · `PainelCriativosIa.jsx` · `KitCriativosGrade.jsx` · `PublicadorCriativo*Service` ·
`LimparReferenciasCriativos` e a retenção de 48 h · o editor (`Pages/Mlb/Publicador/Editor.jsx`,
`resources/js/Components/Publicador/Mesa/` — **SEM** o `Mlb`) · `useCriativosDoPublicador.js` ·
`useAcervoDaConta.js` · `AppLayout.jsx` · `header/PublicadorHeader.jsx` · `Bling*` ·
`app/Services/Portal/` · `app/Mcp/` · `Acervo*` / `ImagemAssetService` / credenciais do R2 ·
`tests/js/estrutura-meus-anuncios.test.js` · `tests/js/publicador-header-zindex-ordem.test.js` ·
⚠️ `.planning/ROADMAP.md`, `.planning/REQUIREMENTS-v27.md`, `.planning/STATE.md` (**GATE-07** — não
pertencem a plano nenhum; o orquestrador os escreve à mão no fechamento).

### ⚠️ A PROVA DE TERRITÓRIO — `PI(193-NN)`

**Por ESCOPO DO ASSUNTO, duas passadas independentes, nunca por range aberto e NUNCA pelo autor:**
as sessões desta árvore rodam com o **mesmo** `user.name` (`MB.ECF-100376`), então `%an` é **vazio
por construção** como discriminador. ➡️ `git log --grep='(193-NN)'` → `git show --stat` por commit →
interseção com a lista de proibidos → **`test -e` em CADA alvo** (⚠️ **DEZ caminhos fantasma nesta
milestone, TRÊS vindos de briefing do orquestrador**; o pior: `ResumoRapidoService` vive em
`app/Services/Publicador/Fila/`, **não** em `.../Aprovacao/Fila/`, e **não** é o
`FilaDeAprovacaoService`) → isca nas duas direções → saída **colada** na SUMMARY.

### ⚠️ Git nesta árvore

Árvore **COMPARTILHADA** com outro dev ativo e com outras abas. `git add -- <caminho>` e **pathspec
no `git commit -- <caminho>`**; `-m` **ANTES** do `--`; ⚠️ **`-F <arquivo>` para mensagem acentuada**
(heredoc com `<< 'FIM'` falha por CRLF nesta máquina). ⚠️ **NUNCA** `git add -A`, `git stash`
(**há 27 stashes — apenas liste**), `git reset`, `git checkout`, `git commit --amend`, `git clean`.
⚠️ **NÃO** rodar `pint`. ⚠️ **NÃO** criar worktree (a junction do Windows **destruiu o `vendor/`
duas vezes**). ⚠️ **NÃO** usar Write em arquivo existente. ⚠️ **"Árvore limpa" por `git status`
ABORTA** pelo `??` de outra sessão → use `git diff HEAD` nos **seus** arquivos.
⚠️ **NÃO deployar, NÃO dar push, NÃO tocar no VPS, NÃO chamar a API real do ML.**
⚠️ **Leitura de produção pode estar BLOQUEADA** — se precisar, **PARE e devolva a pergunta**; **não
replique controller por script** (já produziu falso negativo).

Artefatos, comentários e commits em **pt-BR**.
