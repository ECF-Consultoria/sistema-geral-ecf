<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Move a definição do onboarding das tabelas para o código
 * ({@see \App\Support\Onboarding\DefinicaoOnboarding}).
 *
 * O ponto central: antes, `onboarding_passos` guardava só `template_passo_id` e
 * lia título/dono/SLA/dependências POR RELAÇÃO. O congelamento por onboarding
 * funcionava porque as linhas de `template_passos` nunca sofriam UPDATE. Sem as
 * tabelas de template, o passo precisa carregar a PRÓPRIA definição — é o que
 * estas colunas novas fazem, e é o que preserva a propriedade que importa:
 * onboarding em andamento não muda debaixo do cliente quando a definição muda.
 *
 * O backfill roda em PHP (não em UPDATE...JOIN) de propósito: `UPDATE <tab>
 * <alias> SET` é sintaxe que o MariaDB aceita e o SQLite dos testes recusa, e
 * já derrubou a suíte deste projeto antes. Em ambiente de teste as tabelas
 * nascem vazias e o laço simplesmente não itera.
 */
return new class extends Migration
{
    public function up(): void
    {
        $temTabelasDeTemplate = Schema::hasTable('template_passos')
            && Schema::hasTable('onboarding_templates');

        // ─── 1. Colunas de definição no próprio passo ────────────────────────
        // Guardas de `hasColumn`: esta migration falhou na primeira execução
        // (o unique composto abaixo bloqueava o dropColumn) e ficou `Pending`
        // com as colunas já criadas. Sem as guardas, o retry morre em coluna
        // duplicada e o estado fica preso.
        Schema::table('onboarding_passos', function (Blueprint $table) {
            if (! Schema::hasColumn('onboarding_passos', 'ordem')) {
                $table->unsignedSmallInteger('ordem')->default(0)->after('onboarding_id');
            }
            if (! Schema::hasColumn('onboarding_passos', 'titulo')) {
                $table->string('titulo', 160)->default('')->after('chave');
            }
            if (! Schema::hasColumn('onboarding_passos', 'dono')) {
                $table->string('dono', 12)->default('interno')->after('titulo');
            }
            if (! Schema::hasColumn('onboarding_passos', 'setor_id')) {
                $table->foreignId('setor_id')
                    ->nullable()
                    ->after('dono')
                    ->constrained('setores')
                    ->nullOnDelete();
            }
            if (! Schema::hasColumn('onboarding_passos', 'depende_de')) {
                $table->json('depende_de')->nullable()->after('setor_id');
            }
            if (! Schema::hasColumn('onboarding_passos', 'sla_dias')) {
                $table->unsignedSmallInteger('sla_dias')->nullable()->after('depende_de');
            }
            if (! Schema::hasColumn('onboarding_passos', 'auto_fonte')) {
                $table->string('auto_fonte', 40)->nullable()->after('sla_dias');
            }
            if (! Schema::hasColumn('onboarding_passos', 'condicao')) {
                $table->json('condicao')->nullable()->after('auto_fonte');
            }
        });

        // ─── 2. Backfill da definição a partir das linhas de template ────────
        if ($temTabelasDeTemplate) {
            DB::table('onboarding_passos')
                ->whereNotNull('template_passo_id')
                ->orderBy('id')
                ->chunkById(200, function ($passos) {
                    $ids = collect($passos)->pluck('template_passo_id')->filter()->unique()->all();

                    $definicoes = DB::table('template_passos')
                        ->whereIn('id', $ids)
                        ->get()
                        ->keyBy('id');

                    foreach ($passos as $passo) {
                        $def = $definicoes->get($passo->template_passo_id);

                        if (! $def) {
                            continue;
                        }

                        DB::table('onboarding_passos')
                            ->where('id', $passo->id)
                            ->update([
                                'ordem'      => $def->ordem ?? 0,
                                'titulo'     => $def->titulo ?? '',
                                'dono'       => $def->dono ?? 'interno',
                                'setor_id'   => $def->setor_id ?? null,
                                'depende_de' => $def->depende_de ?? null,
                                'sla_dias'   => $def->sla_dias ?? null,
                                'auto_fonte' => $def->auto_fonte ?? null,
                                'condicao'   => $def->condicao ?? null,
                            ]);
                    }
                });
        }

        // ─── 3. `definicao_versao` no onboarding ─────────────────────────────
        // Responde "sob qual receita esta empresa entrou?" sem depender do git.
        Schema::table('onboardings', function (Blueprint $table) {
            if (! Schema::hasColumn('onboardings', 'definicao_versao')) {
                $table->unsignedSmallInteger('definicao_versao')->default(1)->after('contrato_servico_id');
            }
        });

        // ─── 4. Substitui a trava de duplicidade ANTES de soltar a coluna ────
        // O schema antigo travava duplicidade por (onboarding_id,
        // template_passo_id). Sem template_passo_id, a trava equivalente — e
        // mais honesta, porque `chave` é o identificador estável do passo — é
        // (onboarding_id, chave). Criar a nova ANTES de dropar a antiga evita
        // uma janela sem proteção nenhuma.
        $driver = Schema::getConnection()->getDriverName();

        if (! $this->indiceExiste('onboarding_passos', 'onboarding_passos_onboarding_id_chave_unique')) {
            Schema::table('onboarding_passos', function (Blueprint $table) {
                $table->unique(['onboarding_id', 'chave']);
            });
        }

        // ─── 5. Solta os vínculos com as tabelas de template ─────────────────
        // Os dois bancos recusam a mesma coisa por motivos diferentes, e nenhum
        // aceita a receita do outro:
        //  - MySQL: erro 1072 ao dropar coluna que participa de índice, e
        //    `dropForeign` deixa o índice do FK para trás — então índices e FK
        //    saem em passos próprios, ANTES da coluna, referenciados por NOME.
        //  - SQLite: `ALTER TABLE ... DROP COLUMN` preserva a cláusula de FK e o
        //    banco recusa com "unknown column in foreign key definition". A
        //    saída é pedir `dropForeign` por COLUNAS (por nome ele lança
        //    RuntimeException), o que faz o Laravel RECONSTRUIR a tabela e
        //    resolver FK, índice e coluna de uma vez, no mesmo blueprint.
        if ($driver === 'sqlite') {
            Schema::table('onboarding_passos', function (Blueprint $table) {
                $table->dropUnique(['onboarding_id', 'template_passo_id']);
                $table->dropForeign(['template_passo_id']);
                $table->dropColumn('template_passo_id');
            });

            Schema::table('onboardings', function (Blueprint $table) {
                $table->dropForeign(['template_id']);
                $table->dropColumn('template_id');
            });
        } else {
            if ($this->indiceExiste('onboarding_passos', 'onboarding_passos_onboarding_id_template_passo_id_unique')) {
                Schema::table('onboarding_passos', function (Blueprint $table) {
                    $table->dropUnique('onboarding_passos_onboarding_id_template_passo_id_unique');
                });
            }

            if ($this->foreignKeyExiste('onboarding_passos', 'onboarding_passos_template_passo_id_foreign')) {
                Schema::table('onboarding_passos', function (Blueprint $table) {
                    $table->dropForeign('onboarding_passos_template_passo_id_foreign');
                });
            }

            if ($this->indiceExiste('onboarding_passos', 'onboarding_passos_template_passo_id_foreign')) {
                Schema::table('onboarding_passos', function (Blueprint $table) {
                    $table->dropIndex('onboarding_passos_template_passo_id_foreign');
                });
            }

            if ($this->foreignKeyExiste('onboardings', 'onboardings_template_id_foreign')) {
                Schema::table('onboardings', function (Blueprint $table) {
                    $table->dropForeign('onboardings_template_id_foreign');
                });
            }

            if ($this->indiceExiste('onboardings', 'onboardings_template_id_foreign')) {
                Schema::table('onboardings', function (Blueprint $table) {
                    $table->dropIndex('onboardings_template_id_foreign');
                });
            }

            if (Schema::hasColumn('onboarding_passos', 'template_passo_id')) {
                Schema::table('onboarding_passos', function (Blueprint $table) {
                    $table->dropColumn('template_passo_id');
                });
            }

            if (Schema::hasColumn('onboardings', 'template_id')) {
                Schema::table('onboardings', function (Blueprint $table) {
                    $table->dropColumn('template_id');
                });
            }
        }

        // ─── 6. Adeus às tabelas de definição ────────────────────────────────
        Schema::dropIfExists('template_passos');
        Schema::dropIfExists('onboarding_templates');
    }

    /**
     * `SHOW INDEX` em vez do schema manager: o SQLite dos testes não expõe os
     * mesmos metadados, e este caminho só roda sob MySQL/MariaDB.
     */
    private function indiceExiste(string $tabela, string $indice): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return false;
        }

        return collect(DB::select("SHOW INDEX FROM `{$tabela}`"))
            ->contains(fn ($linha) => $linha->Key_name === $indice);
    }

    private function foreignKeyExiste(string $tabela, string $constraint): bool
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return false;
        }

        return DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $tabela)
            ->where('CONSTRAINT_NAME', $constraint)
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->exists();
    }

    /**
     * O `down()` recria a ESTRUTURA, não o conteúdo: as linhas de template
     * originais não são reconstruíveis a partir dos passos (vários onboardings
     * apontavam para a mesma linha, e passos `nao_aplicavel` nem chegaram a
     * existir). Reverter aqui devolve o schema; repopular seria trabalho de
     * seeder, não de migration.
     */
    public function down(): void
    {
        Schema::create('onboarding_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('servico_id')->constrained('servicos')->cascadeOnDelete();
            $table->unsignedSmallInteger('versao')->default(1);
            $table->boolean('ativo')->default(true);
            $table->timestamp('publicado_em')->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('template_passos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('onboarding_templates')->cascadeOnDelete();
            $table->unsignedSmallInteger('ordem')->default(0);
            $table->string('chave', 60);
            $table->string('titulo', 160);
            $table->text('descricao')->nullable();
            $table->string('dono', 12);
            $table->foreignId('setor_id')->nullable()->constrained('setores')->nullOnDelete();
            $table->json('depende_de')->nullable();
            $table->unsignedSmallInteger('sla_dias')->nullable();
            $table->string('auto_fonte', 40)->nullable();
            $table->json('condicao')->nullable();
            $table->boolean('obrigatorio')->default(true);
            $table->timestamps();
        });

        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropColumn('definicao_versao');
            $table->foreignId('template_id')->nullable()->constrained('onboarding_templates')->nullOnDelete();
        });

        Schema::table('onboarding_passos', function (Blueprint $table) {
            $table->dropColumn([
                'ordem', 'titulo', 'dono', 'setor_id',
                'depende_de', 'sla_dias', 'auto_fonte', 'condicao',
            ]);
            $table->foreignId('template_passo_id')->nullable()->constrained('template_passos')->nullOnDelete();
        });
    }
};
