<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 175 Plano 175-01 (§2 da ETAPA-3) — `pub_produtos` passa a conhecer fases e kits.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration existir.
 * Só ALTER, e NADA de código consome estas colunas neste plano (os consumidores vêm do
 * 175-02 em diante): commit isolado de propósito, para que uma falha no meio não deixe
 * "tabela alterada + migration Pending" junto com o código que já contava com ela.
 *
 * | coluna                   | tipo                      | regra                                             |
 * |--------------------------|---------------------------|---------------------------------------------------|
 * | produto_base_id          | bigint unsigned NULLABLE  | FK `pubprod_base_fk` -> pub_produtos, SET NULL; índice `pubprod_base_ix` |
 * | quantidade_kit           | smallint unsigned         | default 1; kit é >= 2 (regra do serviço, sem CHECK) |
 * | fase                     | tinyint unsigned          | default 1; é o NÚMERO da fase do Publicador        |
 * | estoque_calculado        | boolean                   | default false; true nos kits criados pelo painel   |
 * | kit_sugestao_recusada_em | dateTime NULLABLE         | carimbo do "Não é kit"                             |
 *
 * Índices: `pubprod_base_ix` (produto_base_id) e unique `pubprod_base_qtd_uq`
 * (produto_base_id, quantidade_kit).
 *
 * ⚠️⚠️ COLISÃO DE NOME — LEIA ANTES DE ESCREVER QUALQUER SELECT ⚠️⚠️
 * `estrutura_ofertas.fase` NÃO É NÚMERO DE FASE: é `string(10)` e guarda o TIPO da
 * oferta do Portal (`EstruturaOferta::FASES` = `simples` | `combo` | `kit` | `combit`),
 * está no `$fillable` do `EstruturaOferta` e é gravada pelo Mapeamento. A coluna nova
 * `pub_produtos.fase` é OUTRA COISA: o número da fase do Publicador (1 = unidade,
 * 2 = primeiro kit, ...). As duas tabelas aparecem juntas no mesmo SELECT (pub_produtos
 * tem `oferta_id` -> estrutura_ofertas): quem ler as duas TEM de qualificar a tabela
 * (`pub_produtos.fase` vs `estrutura_ofertas.fase`), senão o MariaDB resolve `fase`
 * para a primeira tabela do FROM e o valor vem calado e errado.
 *
 * `dateTime` e não `timestamp` em `kit_sugestao_recusada_em`: convenção da
 * `2026_10_01_200000_create_publicador_tables.php` — "nenhum `timestamp()` fora do
 * `timestamps()`; datas avulsas são `dateTime`". A §2 da spec escreve "timestamp
 * nullable"; a convenção do projeto vence e a diferença fica registrada aqui.
 *
 * TODA coluna nova tem default porque há dado real em produção (conta #459, 12 produtos)
 * e NÃO EXISTE BACKFILL — o default É o backfill. Depois desta migration todo produto
 * que já existe lê `produto_base_id NULL`, `fase 1`, `quantidade_kit 1`,
 * `estoque_calculado false`, `kit_sugestao_recusada_em NULL`, ou seja: base da Fase 1,
 * exatamente o comportamento de hoje. Nenhum UPDATE roda sobre as 12 linhas.
 *
 * `nullOnDelete` EXIGE `->nullable()`: SET NULL em coluna NOT NULL é o erro 1830 do
 * MariaDB (learnings `desempenho-bonificacao.md` §6), e o SQLite dos testes NÃO pega.
 * `produto_base_id` é `->nullable()` explícito. A escolha de SET NULL é a mesma razão do
 * CR-B02 que já rege `pubprod_empresa_fk`/`pubprod_company_fk`: apagar o produto BASE
 * não apaga o histórico do kit — o kit fica solto (`produto_base_id` NULL), com o
 * rascunho, as publicações, o `ml_item_id`, o payload enviado e a resposta crua do ML
 * preservados, porque para um anúncio que segue no ar esse é o ÚNICO registro do que a
 * ECF publicou. Em CASCADE a exclusão do base levaria o histórico do kit embora.
 *
 * SEM ENUM: o CHECK que o Laravel gera para `enum()` é ENFORÇADO no SQLite dos testes
 * (memória `project_enum_setor_sqlite_check`: migration que acrescenta valor a enum
 * quebra a suíte). `fase` e `quantidade_kit` são inteiros sem limite de domínio no banco;
 * "kit é >= 2" e "fase = max(fase da família) + 1" são regras do serviço.
 *
 * Nomes `pubprod_*` curtos e explícitos: o MariaDB recusa nome de índice acima de 64
 * caracteres (erro 1059) e a migration ficaria `Pending` com a tabela JÁ alterada
 * (`project_mariadb_nome_indice_64`). Os três nomes aqui têm 15, 19 e 15 caracteres.
 *
 * O unique `pubprod_base_qtd_uq (produto_base_id, quantidade_kit)` funciona com TODAS as
 * bases em `(NULL, 1)`: NULL se repete em `unique` tanto no MariaDB quanto no SQLite —
 * já documentado na migration do `pub_produtos` e provado em
 * `tests/Feature/Publicador/FasesDoProdutoSchemaTest.php`. O unique serve para UMA coisa
 * só: recusar um segundo "Kit N" do MESMO base. Kit 2 e Kit 3 do mesmo base convivem.
 *
 * `up()` idempotente passo a passo (`hasColumn` / `hasIndex` / `hasForeignKey`), e
 * PROIBIDO envolver DDL em captura de exceção: um erro 1553 foi engolido assim neste
 * repositório por 2 meses (`2026_07_09_140001`). Se um passo falhar, ele estoura, e a
 * re-execução retoma do passo que faltou. `down()` na ordem inversa: FK, unique,
 * índice, colunas.
 *
 * Rodar no MariaDB local com `--path` deste arquivo (nunca a suíte inteira), conferindo
 * `tasklist | grep mysqld` antes — o MariaDB local já esteve corrompido neste projeto
 * (`project_mariadb_local_corrompido`) e comando que depende do banco pendura a sessão.
 * O SQLite dos testes não pega 1553/1059/1830.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1) As cinco colunas de uma vez, todas com default (o default é o backfill).
        //    `unsignedBigInteger` + `foreign()` separado, nunca `foreignId()->constrained()`:
        //    assim o passo da FK fica guardável por `hasForeignKey()`.
        if (! Schema::hasColumn('pub_produtos', 'produto_base_id')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->unsignedBigInteger('produto_base_id')->nullable()->after('origem');
                $t->unsignedSmallInteger('quantidade_kit')->default(1)->after('produto_base_id');
                $t->unsignedTinyInteger('fase')->default(1)->after('quantidade_kit');
                $t->boolean('estoque_calculado')->default(false)->after('fase');
                $t->dateTime('kit_sugestao_recusada_em')->nullable()->after('estoque_calculado');
            });
        }

        // 2) Índice da família (produto_base_id), antes da FK.
        if (! $this->hasIndex('pub_produtos', 'pubprod_base_ix')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->index('produto_base_id', 'pubprod_base_ix');
            });
        }

        // 3) Um "Kit N" por base. As bases ficam todas em (NULL, 1) e passam: NULL repete em unique.
        if (! $this->hasIndex('pub_produtos', 'pubprod_base_qtd_uq')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->unique(['produto_base_id', 'quantidade_kit'], 'pubprod_base_qtd_uq');
            });
        }

        // 4) FK auto-referente, DEPOIS do índice (o MariaDB usa um índice de apoio para a FK).
        if (! $this->hasForeignKey('pub_produtos', 'pubprod_base_fk')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->foreign('produto_base_id', 'pubprod_base_fk')->references('id')->on('pub_produtos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('pub_produtos', 'produto_base_id')) {
            return;
        }

        // Sem backfill nos dois sentidos: a informação de fase/kit NÃO existe fora destas
        // cinco colunas (não há coluna legada nem espelho em outra tabela). Então o down()
        // PERDE os vínculos de kit de propósito — o kit volta a ser um produto solto, com o
        // próprio rascunho e as próprias publicações intactos. Rollback aqui é operação
        // manual de desenvolvedor, nunca automática (T-175-03, aceito).

        // FK primeiro (ela se apoia nos índices), depois unique, depois índice, depois colunas.
        // No SQLite a FK não tem nome: cai pela forma de coluna (reconstrói a tabela).
        if ($this->hasForeignKey('pub_produtos', 'pubprod_base_fk')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->dropForeign($this->emMysql() ? 'pubprod_base_fk' : ['produto_base_id']);
            });
        }
        if ($this->hasIndex('pub_produtos', 'pubprod_base_qtd_uq')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->dropUnique('pubprod_base_qtd_uq');
            });
        }
        if ($this->hasIndex('pub_produtos', 'pubprod_base_ix')) {
            Schema::table('pub_produtos', function (Blueprint $t) {
                $t->dropIndex('pubprod_base_ix');
            });
        }

        Schema::table('pub_produtos', function (Blueprint $t) {
            $t->dropColumn(['produto_base_id', 'quantidade_kit', 'fase', 'estoque_calculado', 'kit_sugestao_recusada_em']);
        });
    }

    /**
     * MySQL OU MariaDB (WR-B06). O Laravel 11+ tem o driver `mariadb` próprio: comparar só
     * com `'mysql'` manda o MariaDB para o `PRAGMA` do SQLite — erro de sintaxe no meio do
     * `up()`, DEPOIS do ALTER das colunas (DDL não é transacional), e a migration fica
     * `Pending` para sempre com a tabela já alterada.
     */
    private function emMysql(): bool
    {
        return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
    }

    /** Índice existe? Cross-driver (information_schema no MySQL/MariaDB; PRAGMA no SQLite). */
    private function hasIndex(string $table, string $index): bool
    {
        if ($this->emMysql()) {
            return DB::table('information_schema.statistics')
                ->where('table_schema', DB::getDatabaseName())
                ->where('table_name', $table)
                ->where('index_name', $index)
                ->exists();
        }

        foreach (DB::select('PRAGMA index_list('.DB::getPdo()->quote($table).')') as $row) {
            if (($row->name ?? null) === $index) {
                return true;
            }
        }

        return false;
    }

    /** FK existe? information_schema no MySQL/MariaDB; no SQLite, pela coluna de origem (as FKs não têm nome lá). */
    private function hasForeignKey(string $table, string $fk): bool
    {
        if ($this->emMysql()) {
            return DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $fk)
                ->exists();
        }

        foreach (DB::select('PRAGMA foreign_key_list('.DB::getPdo()->quote($table).')') as $row) {
            if (($row->from ?? null) === 'produto_base_id') {
                return true;
            }
        }

        return false;
    }
};
