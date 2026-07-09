<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 74 — Seed idempotente das 4 faixas de bônus canônicas da régua definida
 * pela diretoria/gestão da ECF em 2026-07-09 (74-CONTEXT.md D-16 + 74-SPEC.md
 * DESEMP-07).
 *
 * Faixas (limites inclusivos):
 *   1. sem_bonus       [0.00, 3.99] — Nota final abaixo do piso da régua.
 *   2. basico          [4.00, 4.49] — Bônus básico da régua.
 *   3. intermediario   [4.50, 4.99] — 2 meses consecutivos promovem para máximo
 *                                      (regra DESEMP-08 — Service, não Model).
 *   4. maximo          [5.00, 5.00] — Nota = 5.00 OU 2 meses consecutivos int.
 *
 * IMPORTANTE — este seed é apenas o PONTO DE PARTIDA. O admin pode editar
 * `nota_min`/`nota_max`/`nome`/`descricao` livremente via UI de configuração
 * (Plan 74-05 backend + Plan 74-07 frontend), com auditoria via LogsActivity.
 *
 * Estratégia de idempotência: `updateOrInsert(['slug' => ...])` — rerun não
 * duplica rows nem sobrescreve edições posteriores do admin em campos de
 * "dados canônicos" (dropar down + rerun up recria os defaults; simples run
 * up após um edit da UI faz reset dos campos default, o que é aceitável em
 * ambiente de dev/staging).
 *
 * Uso puro de `DB::table` (não Model): migrations não devem depender de
 * autoload de Models — se `BonusFaixa` for renomeado ou movido, este seed
 * não quebra.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: só age se a tabela existir. Também protege contra rodada
        // fora-de-ordem — a migration `create bonus_faixas` (140002) precede.
        if (!Schema::hasTable('bonus_faixas')) {
            return;
        }

        $now = Carbon::now();

        $faixas = [
            [
                'slug'      => 'sem_bonus',
                'nome'      => 'Sem bônus',
                'descricao' => 'Nota final abaixo do piso da régua (< 4,00). Sem bônus mensal.',
                'nota_min'  => 0.00,
                'nota_max'  => 3.99,
                'ordem'     => 1,
                'ativo'     => true,
            ],
            [
                'slug'      => 'basico',
                'nome'      => 'Básico',
                'descricao' => 'Bônus básico da régua. Nota final entre 4,00 e 4,49.',
                'nota_min'  => 4.00,
                'nota_max'  => 4.49,
                'ordem'     => 2,
                'ativo'     => true,
            ],
            [
                'slug'      => 'intermediario',
                'nome'      => 'Intermediário',
                'descricao' => 'Bônus intermediário. Nota final entre 4,50 e 4,99. '
                             . '2 meses consecutivos nesta faixa promovem para bônus máximo (regra DESEMP-08).',
                'nota_min'  => 4.50,
                'nota_max'  => 4.99,
                'ordem'     => 3,
                'ativo'     => true,
            ],
            [
                'slug'      => 'maximo',
                'nome'      => 'Máximo',
                'descricao' => 'Bônus máximo. Nota final = 5,00 OU 2 meses consecutivos '
                             . 'na faixa intermediária (regra DESEMP-08).',
                'nota_min'  => 5.00,
                'nota_max'  => 5.00,
                'ordem'     => 4,
                'ativo'     => true,
            ],
        ];

        DB::transaction(function () use ($faixas, $now) {
            foreach ($faixas as $faixa) {
                DB::table('bonus_faixas')->updateOrInsert(
                    ['slug' => $faixa['slug']],
                    [
                        'nome'       => $faixa['nome'],
                        'descricao'  => $faixa['descricao'],
                        'nota_min'   => $faixa['nota_min'],
                        'nota_max'   => $faixa['nota_max'],
                        'ordem'      => $faixa['ordem'],
                        'ativo'      => $faixa['ativo'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]
                );
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('bonus_faixas')) {
            return;
        }

        // Reverte apenas os slugs canônicos — preserva faixas customizadas que
        // o admin possa ter adicionado depois via UI (Plan 74-07).
        DB::table('bonus_faixas')
            ->whereIn('slug', ['sem_bonus', 'basico', 'intermediario', 'maximo'])
            ->delete();
    }
};
