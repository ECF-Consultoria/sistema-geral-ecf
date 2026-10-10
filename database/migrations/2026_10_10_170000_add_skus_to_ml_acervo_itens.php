<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 261010-rie — `ml_acervo_itens.skus`: os SKUs do anúncio, para a busca
 * de Publicações achar o anúncio pelo SKU que o usuário tem na planilha.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2):
 *
 * | Decisão  | Valor                        | Por quê |
 * |----------|------------------------------|---------|
 * | coluna   | `skus`                       | lista dos SKUs DISTINTOS do anúncio (pai + variações), não "o" SKU — `ml_acervo_itens` tem uma linha por ANÚNCIO (D-17) e em anúncio com variações o SKU é por variação; escolher um mentiria sobre os outros (D-RIE-01) |
 * | tipo     | `longText` nullable, sem default | o conteúdo é JSON serializado pelo cast `array` do model; `longText` nullable é o mesmo `LONGTEXT NULL` nos TRÊS motores em jogo (MySQL 8 em produção, MariaDB 10.4 no local, SQLite nos testes). `json()` não é portável do mesmo jeito: no MariaDB local ele vira `longtext` + CHECK `json_valid(...)`, no MySQL 8 vira tipo JSON nativo |
 * | posição  | **última** (sem `after()`)   | escolha portável, não otimização: no MySQL 8.0.46 de produção o instant add funciona em qualquer posição (desde o 8.0.29), mas no MariaDB 10.4 local só quando a coluna entra no fim. Entrar no fim vale nos dois, sem depender de versão (D-RIE-03) |
 * | NULL     | "ainda não coletado"         | `[]` é "coletado e o anúncio não tem SKU". Dois estados diferentes, nunca colapsados — é o que torna o aviso da tela exato em vez de chutado (D-RIE-02) |
 * | índice   | nenhum                       | a busca é `LIKE '%termo%'`, que índice nenhum atende; o que recorta a query é `company_id`, já indexado em `mai_company_item_unq`/`mai_company_status_idx` (D-RIE-06) |
 * | backfill | nenhum                       | `mlb:sync-acervo` roda todo dia às 11:35 e varre o acervo INTEIRO da conta, e `skus` está no 3º argumento do `upsert()` — então a linha existente é ATUALIZADA. Um backfill seria a própria varredura de novo, porque o SKU só vem do multiget (D-RIE-04) |
 *
 * Idempotente (`hasColumn`), sem tratamento de exceção em volta de DDL, sem
 * `after()`, sem `json()`, sem enum e sem índice — portanto nenhum nome de
 * índice para estourar o limite de 64 caracteres (erro 1059).
 *
 * ─── NOTA DE DEPLOY — operação em tabela GRANDE ─────────────────────────────
 *
 * A sentença que roda em produção é:
 *
 *     ALTER TABLE ml_acervo_itens ADD COLUMN skus LONGTEXT NULL
 *
 * `ml_acervo_itens` tinha, medida na VPS em 10/10/2026, **1.080.206 linhas,
 * 1.776 MB de dados e 196 MB de índices** (InnoDB), e o worker do acervo
 * escreve nela todo dia.
 *
 * ⚠️ **Produção é MySQL 8.0.46** (`8.0.46-0ubuntu0.24.04.4`), NÃO MariaDB — o
 * MariaDB 10.4.32 é o banco LOCAL do XAMPP (ver
 * `.planning/learnings/banco-producao-e-mysql8-local-e-mariadb.md`). Lá, uma
 * coluna nullable sem default, sem índice e sem CHECK é acrescentada **por
 * metadado (INSTANT)**: não reescreve as 1.080.206 linhas, não copia os 1,7 GB
 * e não trava escrita por tempo perceptível.
 *
 * ⚠️ **Provar esta migration no MariaDB local NÃO prova DDL no MySQL 8.** Para
 * ESTE caso a diferença é irrelevante, e por um motivo nomeável: `ADD COLUMN`
 * de coluna nullable, sem default, sem índice e sem CHECK é equivalente nos
 * dois motores — é exatamente essa equivalência que torna o teste local
 * suficiente aqui. Não generalizar: qualquer DDL com índice, CHECK, enum, FK
 * ou `change()` precisa ser reconferido contra o MySQL 8 antes de ir. E nenhum
 * tempo medido no local (tabela com 0 linha, motor diferente) vale como
 * previsão de deploy.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ml_acervo_itens', 'skus')) {
            Schema::table('ml_acervo_itens', function (Blueprint $t) {
                $t->longText('skus')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('ml_acervo_itens', 'skus')) {
            Schema::table('ml_acervo_itens', function (Blueprint $t) {
                $t->dropColumn('skus');
            });
        }
    }
};
