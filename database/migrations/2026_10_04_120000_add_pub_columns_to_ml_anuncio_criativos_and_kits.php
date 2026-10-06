<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 165 (Creative Engine no Publicador novo) — ponte de dados entre o
 * Creative Engine (`ml_anuncio_criativos`/`ml_anuncio_criativo_kits`, Fases
 * 160-161) e o rascunho do Publicador (`pub_rascunhos`/`pub_imagens`,
 * Fase 164). Decisão de schema travada em D-02/D-10 do 165-CONTEXT.md e
 * §6 do 165-RESEARCH.md, **escrita por escrito antes de existir**.
 *
 * **As duas tabelas têm dado em produção** (criativos e kits de uso real
 * desde 03/10/2026): nenhuma coluna aqui é NOT NULL sem default, e nenhuma
 * linha existente é reescrita. Um criativo/kit do assistente antigo
 * continua com `rascunho_id` preenchido e as três colunas novas NULAS; um
 * criativo/kit do Publicador nasce com `rascunho_id = NULL` e
 * `pub_rascunho_id` preenchido — os dois caminhos coexistem na mesma linha
 * (ver `MlAnuncioCriativo::pubRascunhoIdEfetivo()`).
 *
 * Cinco blocos, cada um guardado por `Schema::hasColumn` (convenção do
 * módulo, molde `2026_10_03_090100_...`): se o deploy morrer no meio, a
 * re-execução completa só o que faltou, sem erro de coluna duplicada.
 *
 * `pub_grupo` (string 600, nas duas tabelas) é **de propósito SEM índice**:
 * é o mesmo tamanho de `pub_imagem_atribuicoes.grupo_chave`
 * (`2026_10_01_200000_create_publicador_tables.php:196`), e um índice
 * normal sobre 600 chars em utf8mb4 (até 2400 bytes) estouraria o limite de
 * 3072 bytes de chave do InnoDB somado às demais colunas do índice — a
 * mesma razão pela qual `pub_imagem_atribuicoes` indexa o HASH
 * (`grupo_hash`), nunca o texto. Como a consulta "kit ativo do rascunho"
 * (`retomavelDoPublicador`) já filtra por `pub_rascunho_id` (indexado) antes
 * de comparar `pub_grupo`, o conjunto de linhas por rascunho é pequeno e o
 * filtro em PHP/`where` sem índice não pesa.
 *
 * Nomes de índice e de FK, todos ≤ 64 caracteres (erro 1059 do MariaDB,
 * `project_mariadb_nome_indice_64` nos learnings):
 *   - `ml_anuncio_criativos_pub_rascunho_id_foreign` (nome padrão do
 *     Laravel para a FK) = 44 chars; índice explícito `ml_criativos_pubrasc_idx`.
 *   - `ml_anuncio_criativos_pub_imagem_id_foreign` = 42 chars; índice
 *     `ml_criativos_pubimg_idx`.
 *   - `ml_anuncio_criativo_kits_pub_rascunho_id_foreign` = 48 chars; índice
 *     `ml_criativo_kits_pubrasc_idx`.
 *
 * Toda FK com `nullOnDelete()` tem `->nullable()` EXPLÍCITO (erro 1830 do
 * MariaDB, invisível no SQLite — guardado por `PubColunasMigrationGuardaTest`).
 * Nenhuma coluna aqui é enum, e nenhum bloco altera coluna existente (o
 * guarda de conteúdo confere os dois pontos pelo nome do método do Schema
 * Builder que NÃO deveria aparecer neste arquivo).
 *
 * No `down()`, cada FK é derrubada com `dropForeign(['coluna'])` (forma de
 * ARRAY — por nome o SQLite recusa, aprendido nos learnings §9) ANTES do
 * `dropIndex` correspondente: no MariaDB o índice explícito passa a ser o
 * que a própria FK usa internamente para localizar a linha referenciada, e
 * dropá-lo antes da FK dá erro 1553 ("cannot drop index needed in a foreign
 * key constraint"). `pub_grupo` não tem FK nem índice — só `dropColumn`.
 */
return new class extends Migration
{
    public function up(): void
    {
        // (a) kits.pub_rascunho_id
        if (! Schema::hasColumn('ml_anuncio_criativo_kits', 'pub_rascunho_id')) {
            Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
                $table->foreignId('pub_rascunho_id')->nullable()->after('rascunho_id')
                    ->constrained('pub_rascunhos')->nullOnDelete();

                $table->index('pub_rascunho_id', 'ml_criativo_kits_pubrasc_idx');
            });
        }

        // (b) kits.pub_grupo — sem índice (ver docblock acima)
        if (! Schema::hasColumn('ml_anuncio_criativo_kits', 'pub_grupo')) {
            Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
                $table->string('pub_grupo', 600)->nullable()->after('pub_rascunho_id');
            });
        }

        // (c) criativos.pub_rascunho_id
        if (! Schema::hasColumn('ml_anuncio_criativos', 'pub_rascunho_id')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->foreignId('pub_rascunho_id')->nullable()->after('rascunho_id')
                    ->constrained('pub_rascunhos')->nullOnDelete();

                $table->index('pub_rascunho_id', 'ml_criativos_pubrasc_idx');
            });
        }

        // (d) criativos.pub_grupo — sem índice (ver docblock acima)
        if (! Schema::hasColumn('ml_anuncio_criativos', 'pub_grupo')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->string('pub_grupo', 600)->nullable()->after('pub_rascunho_id');
            });
        }

        // (e) criativos.pub_imagem_id — a imagem aprovada que entrou no rascunho do Publicador
        if (! Schema::hasColumn('ml_anuncio_criativos', 'pub_imagem_id')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->foreignId('pub_imagem_id')->nullable()->after('pub_grupo')
                    ->constrained('pub_imagens')->nullOnDelete();

                $table->index('pub_imagem_id', 'ml_criativos_pubimg_idx');
            });
        }
    }

    /**
     * Reverte na ordem inversa da criação. Cada bloco guardado por
     * `Schema::hasColumn` — reexecutar um `down()` parcial não falha.
     */
    public function down(): void
    {
        if (Schema::hasColumn('ml_anuncio_criativos', 'pub_imagem_id')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->dropForeign(['pub_imagem_id']);
                $table->dropIndex('ml_criativos_pubimg_idx');
                $table->dropColumn('pub_imagem_id');
            });
        }

        if (Schema::hasColumn('ml_anuncio_criativos', 'pub_grupo')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->dropColumn('pub_grupo');
            });
        }

        if (Schema::hasColumn('ml_anuncio_criativos', 'pub_rascunho_id')) {
            Schema::table('ml_anuncio_criativos', function (Blueprint $table) {
                $table->dropForeign(['pub_rascunho_id']);
                $table->dropIndex('ml_criativos_pubrasc_idx');
                $table->dropColumn('pub_rascunho_id');
            });
        }

        if (Schema::hasColumn('ml_anuncio_criativo_kits', 'pub_grupo')) {
            Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
                $table->dropColumn('pub_grupo');
            });
        }

        if (Schema::hasColumn('ml_anuncio_criativo_kits', 'pub_rascunho_id')) {
            Schema::table('ml_anuncio_criativo_kits', function (Blueprint $table) {
                $table->dropForeign(['pub_rascunho_id']);
                $table->dropIndex('ml_criativo_kits_pubrasc_idx');
                $table->dropColumn('pub_rascunho_id');
            });
        }
    }
};
