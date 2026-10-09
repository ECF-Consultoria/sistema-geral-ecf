---
phase: 175-publicador-etapa-3-produto-fases
plan: 01
subsystem: database
tags: [migration, mariadb, sqlite, publicador, pub_produtos, fases, kit, foreign-key, unique]

# Grafo de dependências
requires:
  - phase: 164-publicador-interno
    provides: "tabela `pub_produtos` (âncora de produto) e o mold de ALTER idempotente com `emMysql`/`hasIndex`/`hasForeignKey`"
  - phase: 172-publicador-etapa-1-navegacao
    provides: "a lista de Produtos por conta, que no 175-02+ ganha a coluna Fases"
  - phase: 173-publicador-etapa-2-visao-geral
    provides: "o PainelVisaoGeral, que no 175-02+ ganha 'Produtos por fase'"
provides:
  - "Cinco colunas de fase em `pub_produtos`: `produto_base_id` (nullable), `quantidade_kit` (default 1), `fase` (default 1), `estoque_calculado` (default false), `kit_sugestao_recusada_em` (dateTime nullable)"
  - "Índice `pubprod_base_ix` (produto_base_id)"
  - "Unique `pubprod_base_qtd_uq` (produto_base_id, quantidade_kit) — recusa um segundo Kit N do MESMO base"
  - "FK auto-referente `pubprod_base_fk` -> pub_produtos com SET NULL (CR-B02)"
  - "Prova de schema em SQLite: default retroativo, unique com NULL repetido e nullOnDelete com histórico preservado"
affects: [175-02, 175-03, 175-04, CriarFaseService, SugestaoDeKitService, PubProduto, ProgramasPublicadorService, Produto.jsx]

# Rastreio técnico
tech-stack:
  added: []
  patterns:
    - "ALTER separado do código que o consome: a migration sozinha num commit, consumidores a partir do 175-02"
    - "Tabela de decisão de schema no docblock antes do corpo da migration (disciplina 2 do CLAUDE.md)"
    - "Colisão de nome de coluna entre tabelas documentada em destaque no docblock (`pub_produtos.fase` vs `estrutura_ofertas.fase`)"

key-files:
  created:
    - database/migrations/2026_10_08_120000_add_fases_to_pub_produtos.php
    - tests/Feature/Publicador/FasesDoProdutoSchemaTest.php
  modified:
    - tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php

key-decisions:
  - "O default É o backfill: nenhum UPDATE roda sobre os 12 produtos da conta #459 — eles passam a ler Fase 1 / 1 unidade / sem base pelo default das colunas"
  - "`kit_sugestao_recusada_em` é `dateTime` e não `timestamp` (convenção da 2026_10_01_200000_create_publicador_tables): a §2 da spec diz 'timestamp nullable', a convenção do projeto venceu e a diferença está registrada no docblock"
  - "`pubprod_base_fk` é SET NULL pela mesma razão do CR-B02: apagar o produto base deixa o kit solto COM rascunho, publicação, ml_item_id, payload e resposta crua do ML"
  - "Sem enum para `fase`/`quantidade_kit` — o CHECK do SQLite é enforçado nos testes; 'kit >= 2' e 'fase = max(família) + 1' são regras do serviço"
  - "Tasks 1 e 2 foram para um commit único: são o MESMO arquivo, e um commit intermediário publicaria uma migration com `up()` vazio"

patterns-established:
  - "Gate do MariaDB (`MigracoesDaFaseDetectamMariaDbTest`): toda migration nova que consulta o driver entra na varredura E ganha um teste de `emMysql()` por reflexão"
  - "Prova de schema sem relação Eloquent: `PubProduto::create([...])` + leitura crua por `DB::table`, porque os casts e as relações `base()`/`kits()` só chegam no 175-02"

requirements-completed: [FASE-01]

# Métricas
duration: ~35min
completed: 2026-10-08
---

# Fase 175 Plano 01: Migration de fases em `pub_produtos` — Resumo

**`pub_produtos` ganhou as cinco colunas de fase (base/kit) com índice, unique `(produto_base_id, quantidade_kit)` e FK auto-referente SET NULL, rodada e revertida no MariaDB local, sem uma linha de backfill — todo produto existente passa a ler "base da Fase 1" pelo default.**

## Performance

- **Duração:** ~35 min
- **Iniciado:** 2026-10-09T00:28Z (aprox., início da leitura do plano)
- **Concluído:** 2026-10-09T01:05Z
- **Tasks:** 3 de 3
- **Arquivos modificados:** 3 (2 criados, 1 editado)

## Realizações

- Migration `2026_10_08_120000_add_fases_to_pub_produtos` com as cinco colunas da §2 da ETAPA-3, todas com default, `up()`/`down()` idempotentes passo a passo e **nenhuma captura de exceção em volta de DDL**.
- Rodada **de fato** no MariaDB local (`ecf_admin`): `migrate` → conferência do schema → `migrate:rollback` → `migrate` de novo → `up()` chamado duas vezes seguidas. Nada ficou `Pending`.
- Docblock com a tabela de decisão de schema e, em destaque, a **colisão de nome** confirmada no código: `estrutura_ofertas.fase` é `string(10)` com `simples|combo|kit|combit` (o TIPO da oferta do Portal, `EstruturaOferta::FASES`), enquanto `pub_produtos.fase` é o NÚMERO da fase do Publicador — e as duas tabelas aparecem juntas no mesmo SELECT via `pub_produtos.oferta_id`.
- `FasesDoProdutoSchemaTest` com 7 testes provando os quatro `must_haves`: default retroativo, duas bases em `(NULL, 1)` convivendo, Kit 2 + Kit 3 do mesmo base convivendo, segundo Kit 2 recusado pelo banco, e `nullOnDelete` preservando todo o histórico do kit.
- A migration entrou na varredura do `MigracoesDaFaseDetectamMariaDbTest` (que barra a volta do `=== 'mysql'`) e ganhou o teste de `emMysql()` por reflexão, no padrão das outras seis migrations do arquivo.

## Commits por task

1. **Tasks 1 + 2: docblock com a tabela de decisão + `up()`/`down()` idempotentes** — `6f26ecac` (`feat`)
2. **Task 3: teste de schema + inscrição no gate do MariaDB** — `07d812e0` (`test`)

**Metadados do plano:** ver o commit de `docs(175-01)` com esta SUMMARY.

## Arquivos criados/modificados

- `database/migrations/2026_10_08_120000_add_fases_to_pub_produtos.php` — **criado.** ALTER de `pub_produtos`: `produto_base_id` (bigint unsigned nullable), `quantidade_kit` (smallint unsigned default 1), `fase` (tinyint unsigned default 1), `estoque_calculado` (boolean default false), `kit_sugestao_recusada_em` (datetime nullable); índice `pubprod_base_ix`, unique `pubprod_base_qtd_uq`, FK `pubprod_base_fk` SET NULL. Helpers `emMysql()`/`hasIndex()`/`hasForeignKey()` copiados do mold da 164.
- `tests/Feature/Publicador/FasesDoProdutoSchemaTest.php` — **criado.** 7 testes, sem relação Eloquent (leitura crua por `DB::table` + `fresh()`).
- `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` — **editado.** `'2026_10_08_120000_add_fases_to_pub_produtos.php'` no array `MIGRACOES` com o comentário "Fase 175", mais `test_a_migration_das_fases_do_produto_trata_mariadb_como_mysql()`.

## Resultado literal da validação no MariaDB local

`tasklist | grep mysqld` → `mysqld.exe  33380 Console` (de pé) antes de **cada** comando que tocou o banco.

```
$ C:/xampp/php/php.exe artisan migrate --path=database/migrations/2026_10_08_120000_add_fases_to_pub_produtos.php
INFO  Running migrations.
2026_10_08_120000_add_fases_to_pub_produtos .... 327.74ms DONE
```

Conferência do schema resultante (via `SHOW COLUMNS` / `SHOW INDEX` / `information_schema.REFERENTIAL_CONSTRAINTS`):

```
COL produto_base_id            bigint(20) unsigned  null=YES default=NULL
COL quantidade_kit             smallint(5) unsigned null=NO  default='1'
COL fase                       tinyint(3) unsigned  null=NO  default='1'
COL estoque_calculado          tinyint(1)           null=NO  default='0'
COL kit_sugestao_recusada_em   datetime             null=YES default=NULL
IDX pubprod_base_qtd_uq      uniq=1 col=produto_base_id seq=1
IDX pubprod_base_qtd_uq      uniq=1 col=quantidade_kit seq=2
IDX pubprod_base_ix          uniq=0 col=produto_base_id seq=1
FK  pubprod_base_fk          on_delete=SET NULL -> pub_produtos
```

As cinco colunas entraram na posição pedida (depois de `origem`, antes de `created_at`), e as FKs antigas (`pubprod_empresa_fk`, `pubprod_company_fk`, `pubprod_oferta_fk`) continuam `SET NULL`, intactas.

```
$ ... artisan migrate:rollback --path=...
2026_10_08_120000_add_fases_to_pub_produtos .... 46.19ms DONE
# pós-rollback: as cinco colunas = "removida"; sobraram só PRIMARY, pubprod_oferta_uq,
# pubprod_empresa_sku_ix e pubprod_company_ix (nenhum resíduo de pubprod_base_*)

$ ... artisan migrate --path=...
2026_10_08_120000_add_fases_to_pub_produtos .... 94.63ms DONE

$ ... artisan tinker  # $m->up(); $m->up();
up() repetido: OK

$ ... artisan migrate:status | grep -ci Pending
0
```

⚠️ **O que a rodada local NÃO cobre:** o banco local tem **0 linhas** em `pub_produtos` (`PRODUTOS=0`). Os 12 produtos da conta #459 existem só em produção, então a prova de que "o default é o backfill" sobre dado real é indireta: o `DEFAULT` das colunas no MariaDB (literal acima, `default='1'` / `default='0'`) mais o teste em SQLite que cria produto sem campo de fase e relê Fase 1. Nenhum `UPDATE` existe na migration — não há como ela tocar nas 12 linhas.

## Testes

| Momento | Comando | Resultado |
|---|---|---|
| Antes | `artisan test tests/Feature/Publicador tests/Unit/Publicador` | **871 passed** (4324 asserções), 289s |
| Depois | mesmo comando | **879 passed** (4358 asserções), 191s |

+8 testes, +34 asserções, **zero falhas**. As falhas pré-existentes que o contexto avisava (2 na suíte JS, 2 em `Phase38Publicador/MeuPainelControllerTest`, as de app token HTTP 400) **não apareceram nesta suíte** nem na baseline nem depois — a baseline de 871 já estava inteira verde. A suíte JS não foi executada (nenhum arquivo `.jsx`/`.js` foi tocado neste plano).

Testes novos focados: `artisan test tests/Feature/Publicador/FasesDoProdutoSchemaTest.php tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` → **15 passed** (63 asserções).

## Decisões tomadas

- **`dateTime` em vez de `timestamp`** para `kit_sugestao_recusada_em`: a §2 da spec escreve "timestamp nullable", mas a convenção da `2026_10_01_200000_create_publicador_tables.php` é "nenhum `timestamp()` fora do `timestamps()`". A convenção venceu; a divergência está escrita no docblock.
- **`unsignedBigInteger` + `foreign()` separado**, nunca `foreignId()->constrained()` no ALTER: só assim o passo da FK fica guardável por `hasForeignKey()` e a migration é retomável.
- **FK depois do índice**: `pubprod_base_ix` é criado antes de `pubprod_base_fk` porque o MariaDB usa um índice de apoio para a FK. Confirmado que, no SQLite, a reconstrução de tabela que o Laravel faz ao acrescentar a FK **preserva** o unique criado no passo anterior (o teste do segundo Kit 2 recusado passa).
- **Tasks 1 e 2 em um commit só** (ver Desvios).

## Desvios do plano

### Ajustes automáticos

**1. [Rule 3 - Blocking] Tasks 1 e 2 foram para um commit único**
- **Encontrado em:** Task 1 (docblock)
- **Problema:** O plano pede commit por task, mas as Tasks 1 e 2 editam o **mesmo arquivo**: o docblock e o corpo da migration. Um commit intermediário publicaria uma migration válida em sintaxe mas com `up()` e `down()` vazios — exatamente o tipo de estado que a disciplina de migration deste projeto quer evitar (um `php artisan migrate` disparado nesse commit registraria a migration como `Ran` sem alterar nada, e o commit seguinte nunca mais rodaria).
- **Correção:** O arquivo foi escrito começando pelo docblock (a ordem que a disciplina 2 pede), os dois verifies rodaram na ordem — primeiro o de sintaxe + ausência de captura de exceção da Task 1, depois o `migrate` da Task 2 — e um único commit `feat(175-01)` fechou as duas.
- **Verificação:** `php -l` + `grep -v '^ \*' … | grep -c 'try'` = 0 (verify da Task 1); `migrate`/`rollback`/`migrate` limpos (verify da Task 2).
- **Commitado em:** `6f26ecac`

**2. [Rule 2 - Missing Critical] Teste de `emMysql()` por reflexão, além da inscrição no array**
- **Encontrado em:** Task 3
- **Problema:** A ação da Task 3 só pedia acrescentar o nome do arquivo ao array `MIGRACOES`. Mas o `MigracoesDaFaseDetectamMariaDbTest` tem DOIS mecanismos, e as seis migrations que possuem o helper `emMysql()` têm **cada uma** o seu teste de reflexão (`test_a_migration_do_backfill…`, `…da_variacao…`, `…da_criacao_dos_produtos…` etc.). Só a varredura por regex prova que a string `'mysql'` sozinha não voltou; ela não prova que o helper desta migration **de fato** devolve `true` para o driver `mariadb`.
- **Correção:** Acrescentado `test_a_migration_das_fases_do_produto_trata_mariadb_como_mysql()` no padrão exato dos vizinhos.
- **Arquivos modificados:** `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php`
- **Verificação:** passa (3 asserções: `mariadb` → true, `mysql` → true, SQLite → false).
- **Commitado em:** `07d812e0`

**3. [Rule 1 - Bug] Asserções de `estoque_calculado`/`fase`/`quantidade_kit` com conversão explícita**
- **Encontrado em:** Task 3
- **Problema:** O `<behavior>` da Task 3 pede `estoque_calculado === false` e `fase === 1`. `PubProduto` tem apenas `protected $guarded = ['id']` e **nenhum `$casts`** — os casts só chegam no 175-02, junto com as relações. Um `assertSame(false, …)` falharia hoje por tipo (o banco devolve `0`/`'0'`), e o teste teria de ser reescrito no plano seguinte.
- **Correção:** as asserções leem o valor cru e convertem (`assertSame(0, (int) $linha->estoque_calculado)` + `assertFalse((bool) …)`, `assertSame(1, (int) $linha->fase)`), com comentário dizendo que o booleano de verdade chega com o cast do 175-02. A intenção do `must_have` ("nasce base da Fase 1, sem backfill") está provada; só o tipo PHP ficou de fora, e ele não é responsabilidade desta migration.
- **Arquivos modificados:** `tests/Feature/Publicador/FasesDoProdutoSchemaTest.php`
- **Verificação:** 7 testes passam; o valor cru conferido também no MariaDB por `SHOW COLUMNS` (`default='0'`).
- **Commitado em:** `07d812e0`

---

**Total de desvios:** 3 ajustados automaticamente (1 blocking, 1 missing critical, 1 bug)
**Impacto no plano:** nenhum alargamento de escopo. O objetivo (só a migration + prova de schema) e as quatro verdades dos `must_haves` saíram inteiros. Nenhum arquivo de `app/` foi tocado.

## Conformidade do gate TDD

A Task 3 está marcada `tdd="true"`, mas **o gate RED não se aplica aqui e isso é desenho do plano**, não atalho: o plano ordena a migration (Tasks 1-2) ANTES do teste (Task 3), de propósito, porque a disciplina deste projeto exige rodar o ALTER no MariaDB local com `--path` antes de qualquer outra coisa. Quando o `FasesDoProdutoSchemaTest` foi escrito, o schema já estava no banco — não havia como ele falhar primeiro. Sequência real: `feat` (`6f26ecac`, a migration) → `test` (`07d812e0`, a prova). Não houve commit de REFACTOR. Registrado aqui para que a auditoria do gate não leia isto como RED omitido.

## Problemas encontrados

- **Heredoc do Bash falhou ao criar a migration** (`unexpected EOF while looking for matching '`), mesmo com delimitador entre quotes — provavelmente o volume de caracteres acentuados/emoji do docblock no Git Bash do Windows. Nenhum arquivo parcial ficou no disco (conferido com `ls`). Resolvido criando os arquivos novos com a ferramenta de escrita direta; o arquivo existente (`MigracoesDaFaseDetectamMariaDbTest.php`) foi alterado só por edição pontual, conforme a restrição da árvore compartilhada.
- `tests/Feature/CompanyPortfolioAccessTest.php` aparece como untracked na árvore e **não é deste plano** (outra sessão/outro dev). Deixado intocado.

## Configuração manual necessária

Nenhuma. Nenhuma variável de ambiente, nenhum serviço externo. Em produção basta o `php artisan migrate --force` do deploy normal — **que não foi executado neste plano** (sem deploy, sem VPS, sem push, conforme as restrições).

## Prontidão para o próximo plano

**Pronto.** O 175-02 em diante pode contar com:
- as cinco colunas e as três constraints no schema, com os defaults já carimbados;
- `PubProduto` **sem nenhuma mudança** — as relações `base()`/`kits()`, os casts (`estoque_calculado` → bool, `kit_sugestao_recusada_em` → datetime) e as constantes de fase são trabalho do 175-02;
- o unique `pubprod_base_qtd_uq` como a trava de banco por trás da mensagem "Já existe Kit N deste produto" do painel Criar Fase 2 — o serviço deve validar antes, mas o banco é a última linha.

**Ressalvas para quem continuar:**
1. A validação em MariaDB rodou com a tabela **vazia**. O primeiro deploy em produção aplica o ALTER sobre 12 linhas reais; o ALTER é `ADD COLUMN` com default + `ADD INDEX` + `ADD FOREIGN KEY`, sem `UPDATE`, então é rápido, mas vale conferir `migrate:status` depois.
2. ⚠️ **Toda query que juntar `pub_produtos` e `estrutura_ofertas` tem de qualificar `fase`.** São duas colunas homônimas de significados diferentes (número da fase vs tipo da oferta). Está no docblock da migration, em destaque.
3. `quantidade_kit` é `unsignedSmallInteger` (teto 65.535) — a §4 da spec diz "inteiro ≥ 2, sem teto". O teto do banco é alto o bastante para o caso de uso, mas não é literalmente "sem teto"; a validação do painel é quem decide o limite prático.

## Autoconferência: APROVADA

Arquivos declarados conferidos no disco:
- `database/migrations/2026_10_08_120000_add_fases_to_pub_produtos.php` — existe; contém `pubprod_base_qtd_uq` e `pubprod_base_fk`
- `tests/Feature/Publicador/FasesDoProdutoSchemaTest.php` — existe
- `tests/Feature/Publicador/MigracoesDaFaseDetectamMariaDbTest.php` — existe (editado)

Commits declarados conferidos em `git log`:
- `6f26ecac` — `feat(175-01): pub_produtos passa a conhecer fases e kits`
- `07d812e0` — `test(175-01): prova do schema de fases e inscricao no gate do MariaDB`

Nenhum arquivo foi apagado por estes commits (`git diff --diff-filter=D HEAD~1 HEAD` vazio nos dois).

## Stubs conhecidos

Nenhum. Este plano é só schema; não há componente, prop nem endpoint que possa devolver dado vazio.

---
*Fase: 175-publicador-etapa-3-produto-fases*
*Concluído: 2026-10-08*
