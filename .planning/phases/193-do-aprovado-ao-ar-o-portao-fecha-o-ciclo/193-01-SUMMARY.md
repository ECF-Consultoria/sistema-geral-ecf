---
phase: 193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo
plan: 01
subsystem: publicador-aprovacao
tags: [schema, migration, baselines, gates, bijecao, territorio]
requires: [Fase 189 fechada, Fase 186 (as 3 colunas de ligacao em producao)]
provides:
  - "a prova por ASSERCAO sobre o schema VIVO de que a Fase 193 NAO tem migration"
  - "as baselines da Fase 193, medidas em isolamento com HEAD estavel nas duas pontas"
  - "o manifesto tests/gates/contagens-193-01.txt, com os 2 ids MEDIDOS"
affects: []
tech-stack:
  added: []
  patterns:
    - "leitor COM assercao para lista CONSTANTE × leitor SEM assercao para laco em massa"
    - "ancora no INVARIANTE (a cadeia exata da FK) em vez de contagem que cresce"
key-files:
  created:
    - tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php
    - tests/gates/contagens-193-01.txt
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-01-SUMMARY.md
  modified:
    - .planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-BASELINES.md
decisions:
  - "Nenhuma migration nesta fase: as 3 colunas de ligacao ja existem, e agora isso e assercao"
  - "1553 NAO entra como gate: seria sobre ZERO casos (esta fase nao dropa indice nenhum)"
metrics:
  duration: "~65 min"
  completed: "2026-10-21"
  tasks: 2
  commits: 3
---

# Fase 193 Plano 01: Abertura da fase — a ausência de migration virou asserção Summary

A Fase 193 **não tem migration** — e isso deixou de ser afirmação de planejador para ser
**asserção sobre o schema vivo**, com isca invertida em todas as negativas e contagens coletoras
e fixas. As baselines foram medidas **em isolamento**, e a **primeira medição teve de ser
descartada** porque outra sessão commitou no meio dela.

---

## §1 — A PARTIDA

```
PARTIDA: c4529cca   (capturada na PRIMEIRA ação, antes de qualquer leitura)
HEAD no fim do plano: a00faf23
```

⚠️⚠️ **O HEAD SE MOVEU DURANTE A WAVE, e isso não é detalhe.** Entre a minha PARTIDA e o fim do
plano, **outra sessão commitou `56ade5f0`** (`feat(quick-261020-s95): AG-07 do agente — a tela da
loja`). A PARTIDA continua sendo `c4529cca` como **referência de leitura**, e **em nenhum momento
ela foi usada como extremo de range para medir território** — o território saiu do **escopo do
assunto no `%s`** (§7).

**Commits desta wave:**

| commit | tarefa | assunto |
|---|---|---|
| `7a29677c` | 2 | `test(193-01)`: a Fase 193 NÃO tem migration — provado por asserção sobre o schema VIVO |
| `a00faf23` | 1 | `docs(193-01)`: as baselines MEDIDAS em isolamento — e a primeira medição foi DESCARTADA |
| (este) | — | `docs(193-01)`: a SUMMARY |

⚠️ **A tarefa 2 foi commitada ANTES da tarefa 1, e o motivo é medido:** a baseline PHP teve de ser
**remedida depois** do commit da tarefa 2 (a primeira medição foi invalidada pelo commit alheio, e
remedir **antes** de commitar deixaria o número sem os meus 4 testes dentro). Cada commit continua
**atômico por tarefa**.

---

## §2 — O PORTÃO DE ENTRADA DA FASE 189, CONFERIDO POR MIM

⚠️ **Conferido do zero, não aceito do briefing.**

```
OK      189-01-SUMMARY.md  (619 linhas)
OK      189-02-SUMMARY.md  (797 linhas)
OK      189-03-SUMMARY.md  (1036 linhas)
OK      189-04-SUMMARY.md  (1039 linhas)
OK      189-05-SUMMARY.md  (1047 linhas)
OK      189-06-SUMMARY.md  (937 linhas)
OK      189-07-SUMMARY.md  (1278 linhas)
OK      189-08-SUMMARY.md  (365 linhas)
OK      189-FECHAMENTO.md  (1432 linhas)
--- TOTAL: OK=9 FALTA=0 (esperado OK=9 FALTA=0) ---
ISCA OK: 189-99-SUMMARY.md ausente (exit=1)
```

**O placar, lido na fonte:** `189-FECHAMENTO.md:575` → `PLACAR-189: OK=5 RESSALVA=3 REPROVA=0`.
Registrado também em `.planning/STATE.md:4511` (*"✅ FASE 189 FECHADA — 20/10/2026 ·
PLACAR-189: OK=5 · RESSALVA=3 · REPROVA=0"*) e em `.planning/ROADMAP.md:5201`.

➡️ **PORTÃO SATISFEITO.** O plano **não parou**.

### ⚠️ Duas inconsistências de registro achadas de passagem (nenhuma bloqueia; nenhuma é minha)

1. `STATE.md:4513` diz *"`189-FECHAMENTO.md` (**1407** linhas)"*, e o arquivo tem **1432**. A
   diferença é explicada por medição: três commits de `docs(189-08)` (`000808a0`, `7e79865b`,
   `d22e094b`) **cresceram o arquivo depois** daquela linha ter sido escrita. ⚠️ **Não corrigi** —
   `STATE.md` é artefato do **GATE-07**.
2. O ROADMAP tem **duas** tabelas de requisitos e elas **discordam**: `:5129` diz
   `AMPL-01..AMPL-07 (7) | Phase 189 | Pending`, enquanto `:5201` a declara entregue com o placar.
   ⚠️ **Não toquei** no ROADMAP. **Dono: usuário/orquestrador.**

---

## §3 — AS BASELINES, MEDIDAS EM ISOLAMENTO

```
PARTIDA:   c4529cca
COMMIT:    7a29677c   (o de docs vem depois)
BUILD:     exit 0 · 121 s · "built in 1m 53s"
MANIFEST:  498 entradas / 203 paginas
JS:        6012 / 6011 pass / 1 fail / 0 skipped   (exit 0)
PHP:       2650 / 24278 / 0                        (exit 0 — o RECORTE, pelo --suite php)
RECORTE:   353 / 6035 / 0                          (exit 0 — tests/Feature/Publicador/Aprovacao)
PROTOTIPO: design_handoff_publicador/Aprovacao.dc.html · 1432 linhas · sha256 68624883ce35547e
```

### ⚠️ O isolamento foi PROVADO POR LINHA DE COMANDO, não por nome de processo

Contar `node.exe` por nome daria **22** e pareceria contaminado. ➡️ Inspecionei a **linha de
comando** de cada um (`Get-CimInstance Win32_Process`): são **Adobe Creative Cloud**, **runtimes
do Codex** (outra ferramenta, sandbox própria) e **servidores MCP** (`digisac-ecf.mjs`,
`mcp-remote` da Adman). **Zero** era `node --test`, `vite` ou `npm`. E `php.exe` estava em **0**
imediatamente antes de **cada** rodada de PHP.

➡️ **DECLARADO: não houve build nem outra suíte concorrente em nenhuma das medições válidas.**
O `build` rodou **primeiro e sozinho** — e é por isso que o JS deu **`0 skipped`** nas duas
pontas (os três blocos que degradam para `skip` quando `public/build/` não está pronto não
degradaram).

### ⚠️⚠️ A SUÍTE PHP COMPLETA NÃO É MEDÍVEL NESTA MÁQUINA — e eu medi pelo RECORTE

`artisan test` sem filtro **aborta com Fatal de memória** em
`Illuminate\Http\Testing\FileFactory.php:87`, a ~6% de 10.653 testes, **exit 255**, e
`-d memory_limit=-1` **não resolve** (o estouro é em processo **FILHO**). ➡️ **O número `PHP:`
acima é o RECORTE** (`tests/Unit/Publicador` + `tests/Feature/Publicador`, o `--suite php`), e
digo isso em vez de deixar o leitor supor que a suíte inteira passou.

### ⚠️⚠️ A PRIMEIRA MEDIÇÃO DO `--suite php` FOI DESCARTADA — e a causa é medida, não suposta

A primeira rodada (19 min, 23:26:41 → 23:45:48 UTC) devolveu **`2640 / 24105 / 4`**, e as 4
falhas **não eram as previstas** na §4 do `193-BASELINES.md`. A investigação fechou assim:

1. O gate `andamento-um-lugar-so` acusou `app/Services/Publicador/Agente/LeituraDoAgente.php`.
2. Medido na hora: aquele arquivo era **untracked** (`git status --porcelain` → `??`), sem commit
   criador (`git log --diff-filter=A` **vazio**) — **WIP de outra sessão**.
3. Rodado **sozinho**, o gate **PASSAVA** (`1 passed / 21 assertions`, **duas** rodadas).
4. ⚠️ A contradição (*"o predicado acusa, mas o gate passa"*) só fechou ao comparar **linhas**: o
   *stack trace* da falha aponta `assertSame` em **`:903`**, e o arquivo em HEAD tem a mesma
   `assertSame` em **`:913`** — **10 linhas de diferença**.
5. ➡️ **`git log c4529cca..HEAD` revelou a causa:** o commit **`56ade5f0`** (outra sessão) *criou*
   `LeituraDoAgente.php` e *acrescentou 10 linhas* a `FechamentoDaFase186Test.php`, pondo o
   arquivo na lista de **expositores** — **durante a minha rodada**.

➡️ **A suíte mediu uma árvore que se moveu por baixo dela.** É a lição *"suíte longa em árvore
compartilhada mede um alvo que se move"*, agora com o alvo sendo **um commit alheio no meio da
rodada** — não só concorrência de `storage/framework/testing`.

➡️ **A medição válida é a SEGUNDA**, com `HEAD` conferido **nas duas pontas**:

```
HEAD_NO_INICIO_DA_REMEDICAO=7a29677c
INICIO=2026-10-21T00:04:40Z
php.exe antes: 0
EXIT_REAL_DO_HARNESS=0
FIM=2026-10-21T00:19:01Z
HEAD_NO_FIM=7a29677c          <- a arvore NAO se moveu
PHP: 2650 / 24278 / 0
```

### ⚠️ O `exit` do harness, lido de `$?` IMEDIATAMENTE — e por que isso salvou a medição

Na primeira rodada o harness saiu **1** (`REGRESSAO: 4 teste(s)`), mas **a notificação do processo
em background reportou `exit code 0`** — porque o último comando do composto era um `tail`, que
sai sempre 0. ⚠️ Peguei isso porque capturei `EX=$?` **na linha seguinte ao harness**, e não do
pipe. **Se eu tivesse confiado na notificação, teria registrado uma baseline com 4 falhas como se
fosse verde.**

### A aritmética do Δ, fechada — e cada parcela atribuída pelo commit que **CRIOU** o arquivo

⚠️ **Por `git log --diff-filter=A`, NUNCA por range** (a wave 189-07 caiu exatamente nisso: o
range englobava os commits dela e listou os arquivos dela como alheios).

| parcela | testes | atribuição |
|---|---|---|
| fechamento da Fase 189 | **2640** | a medição dele |
| `tests/Feature/Publicador/Agente/TelaDoAgenteTest.php` (6 métodos) | **+6** | ⚠️ **ALHEIO** — `CRIADO POR: 56ade5f0` |
| `tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php` (4 métodos) | **+4** | **MEU** — `7a29677c` |
| **esperado** | **2650** | **MEDIDO: `2650 passed (24278 assertions)`** ✅ |

### Toda falha atribuída por execução ISOLADA — e nenhuma é desta fase

| falha (por SEMÂNTICA) | isolado | atribuição |
|---|---|---|
| `FechamentoDaFase186Test > a escrita de andamento mora num lugar so em app inteiro com isca` | **PASSA** (`1/21`, 2×) | ALHEIA (WIP untracked de outra sessão; resolvida pelo commit **dela**) |
| `PortalFotosTest > arquivo sumido e contado com o motivo` | **PASSA** | ordem/corrida |
| `PortalFotosTest > arquivo que nao e imagem e contado como formato` | **PASSA** (os 3 juntos: `13 passed / 36 assertions`) | idem |
| `PortalFotosTest > publicacao que comeca durante a copia impede a atribuicao da foto` | **PASSA** | idem |
| JS: `estrutura-grade-glide.test.js` → *"Características secundárias nasce recolhido"* | **19 / 18 pass / 1 fail / 0 skipped** | **pré-existente, Fase 87** (código tem `RECOLHIDOS_INICIAIS = []`, teste espera `[G_SECUND]`) — **NÃO corrigida** |

➡️ **Nenhum `REF_PHP_FALHAS` foi criado.** Na medição **válida** o recorte deu **0 falhas**, então
não há nada a tolerar. ⚠️ **Comparei TESTES e FALHAS; as asserções são informativas** (e de fato
oscilaram: 24105 na rodada inválida × 24278 na válida).

### ⚠️ Nunca li `/tmp/ultima-suite-php.log` durante execução

As linhas de forma fixa vieram da **saída do processo**, em arquivo próprio do meu scratchpad.
O log compartilhado foi lido **uma vez só**, já com a suíte **terminada** e `php.exe = 0`, e
**só depois de provar a propriedade por `mtime`** (23:45:48, dentro da minha janela de 19 min).
Mesmo assim, o conteúdo dele foi **corroborado por execução isolada** de cada arquivo acusado.

---

## §4 — ⚠️⚠️ A ASSERÇÃO SOBRE O SCHEMA VIVO: A FASE 193 NÃO TEM MIGRATION

**Artefato:** `tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php` (891 linhas,
4 métodos, todos com sufixo `_193_01`).

### As três colunas, medidas no schema VIVO (`Schema::getColumns/getIndexes/getForeignKeys`)

| coluna | existe | nullable | default | índice | FK |
|---|---|---|---|---|---|
| `fila_item_id` | ✅ | ✅ | nenhum | `pubapof_fila_item_ix` | → `pub_fila_publicacao_itens` (`id`), **`on delete set null`** |
| `ml_item_id` | ✅ | ✅ | nenhum | `pubapof_mlb_ix` | — |
| `erro_publicacao` | ✅ | ✅ | nenhum | — | — |

➡️ **A trava do plano NÃO disparou:** nada falta, logo **não há migration**, e eu **não escrevi
nenhuma**. (Se faltasse, o próprio teste manda *"PARE E DEVOLVA A PERGUNTA"* na mensagem.)

### As armadilhas, UMA POR UMA

| armadilha | como está endereçada **nesta fase** |
|---|---|
| **1059** (MariaDB recusa índice > 64) | asserção **COLETORA** sobre o schema vivo, reportando o **MAIOR** nome medido. ⚠️ Medido no `vendor/`: o SQLite **expõe** nome de índice (`SQLiteProcessor::processIndexes` → `strtolower($result->name)`), então o 1059 é medível aqui |
| **1830** (FK `nullOnDelete` exige `->nullable()`) | o **PAR** é asserção: coluna `nullable` (schema vivo) **E** `on_delete = 'set null'` (schema vivo) **E** a **CADEIA EXATA** da FK no texto da migration |
| **1553** (dropar índice usado por FK) | ⚠️⚠️ **NÃO ENTRA — e isso é DECLARADO, não omitido.** Esta fase **não dropa índice nenhum**, logo um gate de ordem `dropForeign → dropIndex` seria **sobre ZERO casos**: verde para sempre, o oposto de prova. Rodei a varredura e **confirmei zero casos** |
| **enum + CHECK do SQLite** | asserção: o `type` de `andamento` **não contém `enum`** (medido: `varchar`) |
| **`up()` idempotente** | asserção: `substr_count(codigo_sem_comentario, "Schema::hasTable('pub_aprovacao_ofertas')") === 1` |
| **default do banco × Eloquent** | as três **sem** default no banco **e** fora de `$attributes`; ⚠️ **com isca invertida**: `andamento`/`origem`/`versao`/`tocado` **têm** default e `andamento` **está** em `$attributes` (o Eloquent não lê default do banco — defeito real medido em 14/10/2026) |
| **dado em produção** | é **por isso** que as três são `nullable`; **nenhuma linha é alterada**, porque não há DDL |
| **MySQL 8.0.46 (prod) × MariaDB 10.4.32 (local) × SQLite (testes)** | ⚠️ **sem DDL, a divergência dos motores NÃO É EXERCIDA nesta fase** — e isso é **declarado** (§5) |

### DADO-03: as 4 colunas de conferência **não foram copiadas**

`validacao_id`, `plano_hash`, `revisao`, `ciente` → **ausentes** de `pub_aprovacao_ofertas` no
schema vivo; **presentes** em `pub_fila_publicacao_itens` (a **isca invertida**, sem a qual
*"não foram copiadas"* seria verde numa varredura cega). E `precisa_revisar` **não é coluna em
nenhuma das duas** — é **VALOR** do `status` (`PubFilaPublicacaoItem::PRECISA_REVISAR`, membro de
`SEM_PUBLICAR`).

### ⚠️⚠️ A ARMADILHA DO DOCBLOCK, VIRADA EM ASSERÇÃO EM VEZ DE SUPOSTA

`grep -rln pub_aprovacao_ofertas database/migrations/` devolve **CINCO** arquivos — e um é
`2026_10_20_100000_create_pub_agente_tables.php`, de **outra sessão**. ⚠️ **Ele NÃO toca a
tabela:** as duas menções (`:11`, `:41`) estão no **docblock**, em prosa. Uma sonda por `grep`
concluiria que há migration nova mexendo no portão — **e estaria errada**.

➡️ O caso mede as **DUAS pontas**: o texto **cru** tem a menção (`> 0`) e o código **sem
comentário** tem **ZERO**. É o que separa *"o programa faz"* de *"a prosa diz"*, e é por isso que
toda sonda deste arquivo lê por `token_get_all` e conta por `substr_count`.

⚠️ **Bati na armadilha do `grep -c` na mão, de passagem:** a linha `:37` da migration da oferta
cita `ciente` **e** `precisa_revisar` **na mesma linha** — `grep -c` devolveria 1 onde há 2.

### Iscas invertidas — a lista completa

| negativa | isca que prova não-cegueira |
|---|---|
| "as três são nullable" | `id`/`alvo_id`/`rascunho_id` vêm **NOT NULL** |
| "as três não têm default" | `andamento`/`origem`/`versao`/`tocado` **têm** |
| "o model não as semeia" | `andamento` **está** em `$attributes` |
| "as 4 da fila não foram copiadas" | elas **existem** na tabela da fila |
| "nenhuma migration faz `Schema::table(`" | a sonda **ACUSA** texto sintético que faz; e **NÃO acusa** outra tabela |
| "não há migration nova de `pub_aprovacao`" | o `glob` vem **NÃO-vazio** (5) |
| "o índice existe" | um nome sintético de índice **não** é "encontrado" |
| "a FK é `set null`" | a mesma cadeia com `cascadeOnDelete` dá **ZERO** (isca do quase-igual) |
| "o docblock alheio não é código" | o texto **cru** dele **tem** a menção |

### ⚠️ A limitação do motor, DECLARADA COMO ASSERÇÃO (nunca como `if` que se desliga)

Medido no `vendor/`: `SQLiteProcessor::processForeignKeys()` devolve **`'name' => null`** — o
`pragma_foreign_key_list` **não traz o nome da FK**. ➡️ O caso **assevera** a limitação
(`assertSame('sqlite', driver)` + `assertNull($fk['name'])`, com a mensagem dizendo que o conserto
futuro é **fortalecer**, nunca apagar) e prova o **nome** `pubapof_fila_item_fk` no **texto** da
migration, sem comentário. ⚠️ Também declarado: `decimal(p,s)` sai como `numeric` sem precisão no
SQLite — **não morde aqui**, porque nenhuma das três colunas é decimal.

---

## §5 — ⚠️ O RITUAL DO BANCO: DECLARADO, NÃO OMITIDO

- ⚠️⚠️ **Não há DDL nesta fase, logo `migrate → rollback → migrate` NÃO SE APLICA.**
- ⚠️ **`tasklist | grep mysqld` → VAZIO: o MariaDB local está PARADO** (e já esteve corrompido
  neste projeto).
- ⚠️ **Produção é MySQL 8.0.46, o local é MariaDB 10.4.32, os testes são SQLite — e sem DDL a
  divergência dos motores NÃO É EXERCIDA nesta fase.** Digo isso em vez de deixar o leitor supor
  que foi testada nos três.
- ⚠️ **Se alguém reabrir a decisão e escrever migration, o ritual volta a ser obrigatório**, com
  `migrate:rollback --batch=<N> --path=<arquivo>` — **nunca `--step=1`** (o banco local é
  compartilhado).
- ⚠️ **Não rodei `artisan migrate`** em nada, inclusive na migration do Agente
  (`2026_10_20_100000_create_pub_agente_tables.php`), que está **RASTREADA** (commit `1253cc86`,
  outra sessão) e **não é minha**.

---

## §6 — OS GATES: MANIFESTO, `exit` REAL, BIJEÇÃO E `--autoteste`

**Manifesto `tests/gates/contagens-193-01.txt`** (125 linhas) nasceu **na mesma tarefa**, **MEDIDO**,
**antes** do `<verify>`. Cabeçalho no modelo do `contagens-188-05.txt`, com o aviso do 1º argumento
e a declaração de que **este plano NÃO roda `vocabulario-tamanhos`** (GATE-03) porque **não tem
`.jsx` nenhum** — a regra é *quem roda, declara*.

### O `exit` REAL do harness em cada id

```
═══════ GATE: schema-sem-migration ═══════
EXIT_REAL_DO_HARNESS=0
gate 'schema-sem-migration' (php): --filter=test_(as_tres_colunas_de_ligacao_existem_nullable_e_sem_default_193_01|o_indice_e_a_fk_de_fila_item_id_estao_de_pe_e_nenhum_indice_passa_de_64_193_01|nenhuma_migration_nova_mexe_na_tabela_do_portao_193_01), esperado 3 passed / 50 assertions
  Tests:    3 passed (50 assertions)
PASSOU: gate 'schema-sem-migration'

═══════ GATE: bijecao-manifesto-193-01 ═══════
EXIT_REAL_DO_HARNESS=0
gate 'bijecao-manifesto-193-01' (php): --filter=test_o_manifesto_tem_bijecao_com_os_metodos_e_nenhum_id_orfao_193_01, esperado 1 passed / 20 assertions
  Tests:    1 passed (20 assertions)
PASSOU: gate 'bijecao-manifesto-193-01'
```

### ⚠️ A isca do 1º argumento, medida AO VIVO (não citada de briefing)

```
--- com '193-01' (CERTO) ---
PASSOU: gate 'schema-sem-migration'
exit=0
--- com '01' nu (ERRADO) ---
EXIT_REAL=2
ABORTOU: id 'schema-sem-migration' nao esta em .../tests/gates/contagens-186-01.txt (manifesto incompleto)
SIM: tests/gates/contagens-186-01.txt existe
```

⚠️ No meu caso ele abortou **alto** porque o id não existe lá. **Se eu tivesse batizado um id com
nome que a 186 usa, ele rodaria o gate da fase ERRADA em silêncio.** Confirmado no código:
`verificar-testes.sh:120-129` resolve por `sed -nE 's/^([0-9]+)-([0-9]+)$/\1-\2/p'` — **dois
grupos de DÍGITOS**; qualquer outra forma cai no `else` e monta `contagens-186-<arg>.txt`.

### A bijeção, nos TRÊS sentidos, com EXPANSOR DE ALTERNÂNCIA

Implementada **como teste durável** (`bijecao-manifesto-193-01`), não como script de scratchpad:

- **método → id:** `SEM_ID=0` (método sem id **nunca roda**)
- **id → método:** `ORFAOS=0` (id órfão **não falha: simplesmente nunca roda**)
- **id → `<verify>`:** os 2 ids aparecem em `<verify>` do plano, e o `<verify>` não invoca id fora
  do manifesto
- `IDS_REPETIDOS=0` · `HOMONIMOS=0` sobre **1208** `*Test.php` da suíte
- **nenhum id com mais de TRÊS métodos**; `tests=N` conferido contra o nº de métodos
- **sufixo `_193_01` em TODOS os 4 métodos**, por asserção coletora

⚠️⚠️ **O EXPANSOR é iscado nas DUAS direções ANTES de valer:** `test_(a|b|c)` → 3 nomes (a regex
ingênua `/test_[A-Za-z0-9_]+/` casa **ZERO** nessa forma — o furo que deu `SEM_ID=14` **acusando
trabalho correto**), e filtro sem alternância → 1 nome. O **varredor de homônimo** também: um nome
**superstring** do meu **TEM** de ser detectado, e `test_aprovar_e_enfileirar_188_05` (real, de
outra fase) **NÃO** pode ser acusado.

### `--autoteste` — 20 casos, 0 divergência (colado integralmente)

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

## §7 — O TERRITÓRIO: `PI(193-01)` POR ESCOPO DO ASSUNTO NO `%s`

⚠️ **Nunca por range aberto a partir da PARTIDA** (ela foi só referência de leitura), **nunca pelo
autor**, **duas passadas independentes**, **`test -e` em CADA alvo**, **isca nas duas direções**.
⚠️ Confirmei o `sha256` **do próprio script** antes e depois (`930438357c213b57`, inalterado) —
o scratchpad é **COMPARTILHADO** e um `mutar.sh` já foi sobrescrito no meio de uma campanha.

```
PARTIDA=c4529cca      HEAD=a00faf23
PASSADA A (git log --grep, le a MENSAGEM INTEIRA):  a00faf23 7a29677c   TOTAL_A=2
PASSADA B (pelo ASSUNTO %s apenas):                 a00faf23 7a29677c   TOTAL_B=2
FACE 1 — SOBRE-CAPTURA (A menos B): NENHUMA (os dois conjuntos sao IGUAIS)
test -e em 17 arquivos + 4 diretorios proibidos:    FANTASMAS=0
INTERSECAO com o territorio PROIBIDO:               VIOLACOES=0
ISCA_POSITIVA=OK   (a varredura ACUSA contagens-193-01.txt, que eu toquei)
ISCA_NEGATIVA=OK   (app/NaoExiste9z7.php nao e acusado)
```

**Os únicos 3 arquivos que os meus commits tocam** — e os três estão no `files_modified` do plano:

```
.planning/phases/193-do-aprovado-ao-ar-o-portao-fecha-o-ciclo/193-BASELINES.md
tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php
tests/gates/contagens-193-01.txt
```

### ⚠️⚠️ AS TRÊS FACES DO LIMITE — e DUAS se concretizaram

**FACE 1 — sobre-captura.** `git log --grep` lê a **mensagem inteira**, então um commit de outra
wave que **cite** `(193-01)` no corpo entraria sem ter tocado nada meu. ⚠️ **Nesta wave não se
concretizou** (A = B = 2), mas o limite é real e foi **medido**, não suposto.

**FACE 2 — sub-captura. CONCRETIZOU-SE, e importa.** A varredura por assunto **não vê**
`56ade5f0`, que mexeu em **dois arquivos que a régua da 193 marca como sensíveis**:

```
ALHEIO: 56ade5f0  MB.ECF-100376 | feat(quick-261020-s95): AG-07 do agente — a tela da loja
        .planning/STATE.md
        .planning/quick/261020-s95-ag-07-pagina-do-agente/{PLAN,SUMMARY}.md
        app/Http/Controllers/MlbPublicadorAgenteController.php
        app/Services/Publicador/Agente/LeituraDoAgente.php
        resources/js/Components/Mlb/Publicador/agente/ConteudoDoAgente.jsx
        resources/js/Pages/Mlb/Publicador/Agente.jsx
        routes/mlb_anuncios.php
        tests/Feature/Publicador/Agente/TelaDoAgenteTest.php
        tests/Feature/Publicador/Aprovacao/FechamentoDaFase186Test.php   <- gate da 186
        tests/gates/contagens-186-09.txt                                  <- manifesto da 186
        tests/js/publicador-agente-render.test.js
        tests/js/publicador-header-nas-paginas.test.js
        tests/js/publicador-vocabulario-novo.test.js
```

➡️ **Medido À MÃO, por diferença** (`c4529cca..HEAD` menos os meus), que é o único jeito de ver
um vizinho que não carrega o meu escopo no assunto.

**FACE 3 — o autor. ⚠️ A PREMISSA DO BRIEFING ESTAVA MAIS INCOMPLETA DO QUE ELE PRÓPRIO DIZIA.**
O briefing corrigiu-se para *"há DUAS identidades: `MB.ECF-100376` ×118 e `ECF Dev` ×2"`*.
**Medido no histórico inteiro, são CINCO:**

```
   3703 MB.ECF-100376
   2008 ECF Dev
     57 Dev ECF
      5 root
      4 ecfmaycon
```

➡️ A conclusão operacional **não muda** (o autor **não** discrimina: o commit alheio desta wave e
os meus têm o **mesmo** `%an = MB.ECF-100376`), mas **os números do briefing não se sustentam na
medição**, e a divergência fica registrada.

### Os vizinhos medidos À MÃO, e o que eles NÃO violaram

| alvo intocável | prova (⚠️ por **RANGE** `c4529cca..HEAD`, nunca pela árvore) |
|---|---|
| `tests/js/estrutura-meus-anuncios.test.js` (D-11/D-22) | `numstat` **VAZIO** |
| `tests/js/publicador-header-zindex-ordem.test.js` | `numstat` **VAZIO** |
| `.planning/ROADMAP.md` (GATE-07) | `numstat` **VAZIO** |
| `.planning/REQUIREMENTS-v27.md` (GATE-07) | `numstat` **VAZIO** |
| `.planning/STATE.md` (GATE-07) | ⚠️ **MUDOU: `1 0`** — atribuído, commit por commit, a **`56ade5f0`** (ALHEIO). **Não fui eu** |
| arquivos rastreados modificados na árvore | **0** (por `git diff HEAD --name-only`, não por `git status`, que aborta pelo `??` alheio) |

⚠️ **A isca do range:** `contagens-193-01.txt` dá `125 0` no mesmo range — **o range não está
cego**. Sem essa ponta, os `numstat` vazios acima seriam indistinguíveis de medição quebrada
(`git diff --stat -- <dir>` depois de commitar compara a **ÁRVORE** e sai vazio **sempre**).

⚠️ **27 stashes na árvore: apenas CONTADOS.** Não rodei `git stash` em forma nenhuma.

---

## §8 — ⚠️ OS FUROS QUE EU ACHEI NAS MINHAS PRÓPRIAS SONDAS (e consertei na RAIZ)

⚠️⚠️ **Os dois foram achados por RODAR o gate, não por lê-lo. Nenhum foi consertado por elevação
de número.**

### FURO 1 — âncora em número que cresce legitimamente

Escrevi `assertSame(1, substr_count($codigo, 'nullOnDelete'))`. **Rodei e REPROVOU: o medido é 4**
(`produto_fk`, `responsavel_fk`, `aprovado_por_fk`, `fila_item_fk`, linhas `:307`-`:310`; mais 2
em comentário, que o `token_get_all` já tirava).

⚠️ Era a armadilha *"âncora em número que muda legitimamente"*: **toda FK `nullOnDelete` nova na
tabela faria o número subir**, e o conserto "fácil" seria **elevar a régua para 4** — o hábito que
esta milestone já pagou quinze vezes.

➡️ **Reancorado no INVARIANTE**: a **cadeia exata** da FK de `fila_item_id` (qual coluna, qual
nome, qual tabela, qual regra de delete), que **não** se move quando outra FK nasce e **se move**
exatamente quando o invariante é violado. **Mais a isca do quase-igual**: a mesma cadeia com
`cascadeOnDelete` tem de dar **ZERO** — sem ela a asserção seria tautologia de literal.

### FURO 2 — contagem de asserções REFÉM DO TAMANHO DO REPOSITÓRIO

O meu helper de leitura carregava um `assertFileExists` e eu o chamava **no laço em massa**.
**Medido: 413 asserções para 3 casos, das quais 363 eram as migrations do repo** — e o caso da
bijeção somaria **mais 1208** (os `*Test.php`).

⚠️ **Consequência: toda migration ou teste novo de QUALQUER sessão mudaria o número e o manifesto
reprovaria trabalho correto** — e esta árvore tem outra sessão criando migrations e testes **agora
mesmo** (foi exatamente o que `56ade5f0` fez). Era a armadilha §4b.2 **escondida dentro de um
helper**, onde ninguém olha.

➡️ **Conserto de raiz:** dois leitores com contratos distintos — **COM** asserção, só para
caminhos de **lista CONSTANTE** (onde fantasma é risco real e a contagem é fixa por construção), e
**SEM** asserção para **laço em massa** (onde o arquivo vem do `glob`/iterator e **existe por
construção**, logo a asserção anti-fantasma não prova nada). **MEDIDO depois do conserto:
413 → 50.**

---

## §9 — ⚠️ CRITÉRIOS DE ACEITAÇÃO QUE EU RODEI E ACHEI VAZIOS OU FALSOS POR CONSTRUÇÃO

| item | veredicto |
|---|---|
| **gate de ordem `dropForeign → dropIndex` (armadilha 1553)** | ⚠️ **VAZIO POR CONSTRUÇÃO — e por isso NÃO ENTROU.** Varri: esta fase não dropa índice nenhum. Um gate aqui seria sobre **ZERO casos** e passaria **verde para sempre**. A régua já previa; **confirmei medindo** em vez de aceitar |
| **`grep -c "_193_01" ... ≥ 1 por método`** (do `<acceptance_criteria>`) | ⚠️ **FRACO POR CONSTRUÇÃO.** `grep -c` conta **LINHAS**, e os 4 métodos não estão cada um em sua linha de forma garantida. ➡️ Substituí por **asserção dentro do gate**: coletora sobre os nomes de método lidos por `token_get_all`, exigindo `str_ends_with($m, '_193_01')` em **todos** |
| **`assertFileExists` dentro do laço em massa** | ⚠️ **PROVA NADA** (o arquivo vem do `glob`) **e CUSTA** (a contagem fica refém do repo). Removido — ver §8, FURO 2 |
| **"o componente novo está no manifest"** | ⚠️ **FALSO como invariante neste projeto** (§5 do `193-BASELINES.md`: 19 `.jsx` novos → ZERO entradas novas). Esta fase não cria `.jsx`, então usei o manifest **só** como não-regressão: **498/203, IGUAL** |

---

## §10 — ⚠️⚠️ ONDE O BRIEFING DIVERGIU DA MINHA MEDIÇÃO (a medição ganha, e eu declaro)

| o briefing disse | eu medi | consequência |
|---|---|---|
| *"PHP recorte 2631 / 24.041 / 0 … o fechamento re-mediu 2640 / 24.126 / 0"* | **2650 / 24278 / 0** | ✅ **ACIMA é esperado.** O Δ fecha na vírgula: +6 alheio, +4 meu |
| *"há DUAS identidades: `MB.ECF-100376` ×118 e `ECF Dev` ×2"* | **CINCO**: `MB.ECF-100376` ×3703 · `ECF Dev` ×2008 · `Dev ECF` ×57 · `root` ×5 · `ecfmaycon` ×4 | a conclusão (autor não discrimina) **não muda**; os números, sim |
| *"o `MlPublicacaoService` sai com `numstat` ZERO por não estar no caminho"* + *"foi um dos SEIS caminhos fantasma"* | ⚠️ **`app/Services/Mlb/Publicacao/MlPublicacaoService.php` EXISTE (175 linhas) — NÃO é fantasma** | **boa notícia para o `193-05`:** o gate de `numstat` do PUB-03 tem **alvo real**, e **não** é um gate sobre zero casos. O que era fantasma era a *afirmação* de que o `POST /items` mora lá — ele está em `app/Services/Publicador/PublicacaoService.php:442`, **exatamente na linha que o briefing citou** ✅ |
| *"a migration do agente está RASTREADA (`1253cc86`)"* | ✅ **confirmado** por `git log --diff-filter=A` | nada a fazer |
| *"as 5 migrations de `*pub_aprovacao*`"* (no `<interfaces>` do plano) | ✅ **5** pelo glob. ⚠️ Mas `MIGRATIONS_DO_PORTAO` da Fase 186 tem **SEIS** entradas | a 6ª é `create_pub_imagem_referencias_table`, que **não casa** `*pub_aprovacao*`. **Não há contradição** — são recortes diferentes, e isso fica dito |
| *"os dois de `CockpitDaContaTest` oscilam com a hora"* / *"`SincronizaPortalCompletoTest`"* | ⚠️ **não apareceram.** As falhas foram outras (`FechamentoDaFase186Test` ×1 + `PortalFotosTest` ×3) | a §4 do `193-BASELINES.md` foi **complementada**, não "ajustada" |
| *"`contagens-186-09.txt` tem de continuar IDÊNTICO — mudou, PARE e devolva"* (régua §5) | ⚠️ **MUDOU, por trabalho ALHEIO e LEGÍTIMO** (`56ade5f0`): `andamento-um-lugar-so` 20→**21**, `transversais` 258→**259** | ⚠️ **registrado em §7 do `193-BASELINES.md` como aviso para as waves 02-05.** Não é regressão; o que está velho é a **referência da régua**. **Dono: usuário/orquestrador** |
| *"os gates `aprovar-e-enfileirar`/`tentar-de-novo` (188-05) têm de continuar verdes"* | não aplicável a esta wave | ⚠️ **confirmado de passagem:** na medição válida o recorte deu **0 falhas**, logo os dois estão verdes |

---

## §11 — O QUE ESTE PLANO NÃO FEZ (e isso é a régua, não omissão)

- ❌ **nenhuma migration** (é o ponto da fase) · ❌ **nenhum `.jsx`** · ❌ **nada em `app/`**,
  `resources/`, `routes/`, `database/` · ❌ **nenhuma rota, coluna, estado, rótulo ou toast**
- ❌ **não toquei** `PublicacaoService`, `PreparadorDaAprovacao`, `Aprovacao.jsx`, `AppLayout.jsx`
  nem serviço/`.jsx` das Fases 186-189 · ❌ **não toquei** `contagens-186-04/09.txt` nem
  `contagens-188-05.txt`
- ❌ **não rodei** `state.advance-plan` · ❌ **não editei** `193-REGUA.md`, `193-COBERTURA.md` nem
  os `193-NN-PLAN.md` · ❌ **não rodei** `pint`, `git stash`, `git reset`, `git checkout`,
  `git clean`, `--amend`, worktree
- ❌ **nenhum deploy, push, VPS ou chamada à API real do ML** · ❌ **nenhum `npm install` /
  `composer require`** (`package*.json` e `composer*` com `numstat` **VAZIO** no range)
- ⚠️ **NÃO AFIRMO ter visto tela renderizada** nem publicação real: não há navegador, o `mysqld`
  está parado, nenhuma empresa tem `ml_token` e `pub_aprovacao_ofertas` está **vazia**. Este plano
  é **só schema e gates**.

---

## §12 — PENDÊNCIAS E DEVOLUÇÕES (nada bloqueia a wave `193-02`)

| item | dono |
|---|---|
| ⚠️ Os números da régua para `contagens-186-09.txt` ficaram **obsoletos** (`21`/`259`) por commit alheio legítimo. A régua é artefato de planejamento e **não pertence a plano nenhum** | **usuário/orquestrador** |
| ⚠️ `STATE.md:4513` diz `1407` linhas do `189-FECHAMENTO.md`; o medido é **1432** (GATE-07, não toquei) | **usuário/orquestrador** |
| ⚠️ O ROADMAP tem **duas** tabelas que discordam sobre `AMPL-01..07` (`:5129` *Pending* × `:5201` entregue com placar) | **usuário/orquestrador** |
| ⚠️ **Outra sessão está commitando nesta árvore agora** (`56ade5f0` caiu no meio da minha suíte). Para as waves 02-05: conferir `HEAD` **no início e no fim** de toda suíte longa, e `git diff HEAD -- <arquivo>` antes de **cada** edição | **executor de cada wave** |
| ⚠️ **PERGUNTA-193-A** (a oferta aprovada que a fila recusa) — a decisão **21** do briefing diz que é a opção **(a)**, que os planos já implementam. ⚠️ **Eu não vi essa decisão em artefato versionado**: ela chegou **só pelo briefing**. Registrar na régua/ROADMAP antes de o `193-03` fechar | **usuário/orquestrador** |
| ⚠️ **PERGUNTA-193-B** (editar foto de anúncio já no ar) — diferida por decisão **22**; o `193-05` a registra em `deferred-items.md` | **Fase própria** |
| ⚠️ **Fases 190/191 FORA DE ESCOPO** (decisão 20, 20/10/2026) ⇒ o que dependia delas fica **sem dono (190/191 fora de escopo em 20/10/2026)**, nunca *"fica para a Fase 190/191"* | — |

---

## §13 — Deviações do plano

### Auto-corrigidas (Regra 1 — defeito no meu próprio artefato, achado por medir)

**1. [Regra 1 - Bug] Âncora em número que cresce (`nullOnDelete`)**
- **Achado em:** tarefa 2, ao **rodar** o gate (não ao lê-lo)
- **Problema:** `assertSame(1, substr_count(..., 'nullOnDelete'))` — o medido é **4**, e o número
  sobe a cada FK nova
- **Conserto:** reancorado na **cadeia exata** da FK de `fila_item_id` + isca do quase-igual
  (`cascadeOnDelete` → ZERO)
- **Commit:** `7a29677c`

**2. [Regra 1 - Bug] Contagem de asserções refém do tamanho do repositório**
- **Achado em:** tarefa 2, ao medir (413 asserções, 363 delas do laço em massa)
- **Conserto:** leitor **COM** asserção para lista constante × leitor **SEM** asserção para laço em
  massa. **413 → 50**
- **Commit:** `7a29677c`

### Deviação de ordem (declarada)

**3. A tarefa 2 foi commitada antes da tarefa 1.** A baseline PHP teve de ser **remedida depois**
do commit da tarefa 2 — a primeira medição foi invalidada por commit alheio no meio dela, e
remedir antes de commitar deixaria o número **sem os meus 4 testes**. Cada commit continua
**atômico por tarefa**.

### Nada mais

Nenhuma Regra 2, 3 ou 4 foi acionada. **Nenhum gate alheio foi alterado por mim.**

---

## §14 — Known Stubs

**Nenhum.** Este plano não cria código de produção, componente, rota nem coluna — só um arquivo de
teste, um manifesto de gate e um artefato de planejamento.

## §15 — Self-Check: PASSED

**Arquivos afirmados, conferidos por `test -f`:**

```
FOUND:   tests/Feature/Publicador/Aprovacao/SchemaDoPortaoDaPublicacaoTest.php (891 linhas · min_lines 120)
FOUND:   tests/gates/contagens-193-01.txt                                      (125 linhas · min_lines 40)
FOUND:   .planning/phases/193-.../193-BASELINES.md                             (191 linhas)
FOUND:   .planning/phases/193-.../193-01-SUMMARY.md
MISSING: (nenhum)
```

**Commits afirmados, conferidos por `git log --oneline --all | grep`:**

```
FOUND:   c4529cca  docs(189): a Fase 189 fechada no ROADMAP e no STATE          <- a PARTIDA
FOUND:   56ade5f0  feat(quick-261020-s95): AG-07 do agente — a tela da loja     <- ALHEIO, caiu no meio da minha suite
FOUND:   7a29677c  test(193-01): a Fase 193 NAO tem migration                   <- tarefa 2
FOUND:   a00faf23  docs(193-01): as baselines MEDIDAS em isolamento             <- tarefa 1
FOUND:   1253cc86  feat(quick-261020-nsz): AG-01 do agente                      <- ALHEIO (a migration do agente, RASTREADA)
FOUND:   c810a8ac  feat(quick-261020-oeg): AG-03 do agente                       <- ALHEIO (o produtor de ofertas)
MISSING: (nenhum)
```

**`key_links` do plano:** o teste referencia `fila_item_id` **17×** e a tabela do portão **25×**.

**Confirmação final dos gates, depois de todas as edições:**

```
schema-sem-migration     -> EXIT_REAL=0   Tests: 3 passed (50 assertions)
bijecao-manifesto-193-01 -> EXIT_REAL=0   Tests: 1 passed (20 assertions)
arquivos RASTREADOS modificados na arvore: 0   (commits atomicos, nada pendente)
```

## §16 — Threat Flags

**Nenhuma.** `T-193-SC` (npm/composer installs) conferido por `numstat` **VAZIO** no range
`c4529cca..HEAD` em `package.json`, `package-lock.json`, `composer.json` e `composer.lock`.
`T-193-01` (migration desnecessária em tabela com dado em produção) **mitigada**: a ausência de
migration é **asserção**, e a trava de *"PARE E DEVOLVA"* está escrita na mensagem das duas
asserções que a detectariam.
