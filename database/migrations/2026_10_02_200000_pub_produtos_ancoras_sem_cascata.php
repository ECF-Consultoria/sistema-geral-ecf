<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 164, CR-B02 do code review — as âncoras de `pub_produtos` deixam de ser
 * CASCADE e passam a SET NULL. Decisão do usuário (02/10/2026): excluir a
 * `MlbEmpresa` ou a `Company` NÃO apaga o histórico de publicação.
 *
 * Por quê: com `pubprod_empresa_fk`/`pubprod_company_fk` em CASCADE, um `delete()`
 * na empresa (o `DELETE /mlb/empresas/{empresa}` é de gestor/líder de Polos, não
 * exige admin) levava `pub_produtos` → `pub_rascunhos` → `pub_publicacoes` →
 * `pub_publicacao_itens`, com o `ml_item_id`, o payload enviado e a resposta crua
 * do ML de anúncios que CONTINUAM no ar.
 *
 * DECISÃO DE SCHEMA (CLAUDE.md, disciplina 2), escrita antes da migration:
 * - A criação (`2026_10_02_100000_create_pub_produtos_table`) já foi corrigida para
 *   `nullOnDelete` — onde ela ainda não rodou (produção), nasce certa e esta aqui não
 *   faz nada. Esta migration só conserta banco onde a criação JÁ rodou com CASCADE
 *   (o MariaDB local compartilhado).
 * - As duas colunas são anuláveis: SET NULL é válido (o erro 1830 é só para coluna
 *   NOT NULL — learnings `desempenho-bonificacao.md` §6).
 * - Só MySQL/MariaDB (drivers `mysql` E `mariadb` — WR-B06). Lê
 *   `information_schema.REFERENTIAL_CONSTRAINTS`: CASCADE → derruba a FK e recria com o
 *   MESMO nome curto em SET NULL; já SET NULL → nada; ausente (uma execução anterior
 *   caiu entre o drop e o add — DDL não é transacional no MariaDB) → só recria.
 *   Idempotente: rodar de novo não muda nada.
 * - Os índices `pubprod_empresa_sku_ix` e `pubprod_company_ix` NÃO são tocados: são
 *   eles que apoiam as FKs (dropá-los dá o erro 1553). Drop e add em `Schema::table`
 *   separados, sem try/catch em volta de DDL.
 * - SQLite (testes): no-op — a migration de criação já cria SET NULL.
 *
 * down(): NÃO volta a CASCADE, de propósito. Desfazer esta migration recolocaria a
 * cascata que apaga histórico de publicação — exatamente o que o usuário decidiu
 * proibir — e deixaria o banco diferente do que a migration de criação declara.
 * Para voltar a CASCADE é preciso decisão nova e migration nova.
 *
 * Rodar no MariaDB local SÓ com `--path` deste arquivo.
 */
return new class extends Migration
{
    /** nome da FK → [coluna, tabela referenciada] */
    private const ANCORAS = [
        'pubprod_empresa_fk' => ['mlb_empresa_id', 'mlb_empresas'],
        'pubprod_company_fk' => ['company_id', 'companies'],
    ];

    public function up(): void
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) || ! Schema::hasTable('pub_produtos')) {
            return;
        }

        foreach (self::ANCORAS as $nome => [$coluna, $referenciada]) {
            $regra = $this->regraDeExclusao($nome);
            if ($regra === 'SET NULL') {
                continue;
            }

            if ($regra !== null) {
                Schema::table('pub_produtos', function (Blueprint $t) use ($nome) {
                    $t->dropForeign($nome);
                });
            }

            Schema::table('pub_produtos', function (Blueprint $t) use ($nome, $coluna, $referenciada) {
                $t->foreign($coluna, $nome)->references('id')->on($referenciada)->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        // De propósito, nada: ver a docblock (voltar a CASCADE apagaria histórico de publicação).
    }

    /** `DELETE_RULE` da FK em `pub_produtos` (CASCADE, SET NULL…) ou null se ela não existe. */
    private function regraDeExclusao(string $fk): ?string
    {
        $regra = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
            ->where('CONSTRAINT_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'pub_produtos')
            ->where('CONSTRAINT_NAME', $fk)
            ->value('DELETE_RULE');

        return $regra === null ? null : strtoupper((string) $regra);
    }
};
