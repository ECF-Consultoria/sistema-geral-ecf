<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 168 — semente dos tipos e pares da ECF (D-06), lida de `config/estrutura_geracao.php`
 * (lista aprovada pelo usuário no 168-02: D-21, D-22, D-23). Só dados, separada da criação das
 * tabelas. IDEMPOTENTE por `insertOrIgnore` (únicos `etp_slug_uq` e `etpar_uq`): rodar de
 * novo não muda contagem nem sobrescreve o que a ECF editou pela tela admin.
 *
 * O par é guardado NÃO ordenado (tipo_a_id <= tipo_b_id); a direção do Combit (`repete`, em
 * slug) é remapeada para 'a' (lado de menor id), 'b' (lado de maior id) ou 'ambos'.
 */
return new class extends Migration
{
    public function up(): void
    {
        $agora = now();

        foreach ((array) config('estrutura_geracao.tipos', []) as $slug => $tipo) {
            DB::table('estrutura_tipos_produto')->insertOrIgnore([
                'slug'       => $slug,
                'nome'       => $tipo['nome'],
                'plural'     => $tipo['plural'],
                'palavras'   => implode(', ', $tipo['palavras']),
                'qtd_combo'  => $tipo['qtd_combo'] ?? null,
                'qtd_combit' => $tipo['qtd_combit'] ?? null,
                'ordem'      => $tipo['ordem'] ?? 0,
                'created_at' => $agora,
                'updated_at' => $agora,
            ]);
        }

        $ids = DB::table('estrutura_tipos_produto')->pluck('id', 'slug');

        foreach ((array) config('estrutura_geracao.pares', []) as $par) {
            [$slugA, $slugB] = $par['tipos'];

            // Slug inexistente: pula (o CatalogoDaEcfTest barra isso no config).
            if (! isset($ids[$slugA], $ids[$slugB])) {
                continue;
            }

            $idA = (int) $ids[$slugA];
            $idB = (int) $ids[$slugB];
            $repete = $par['repete'] ?? null;

            if ($idA > $idB) {
                [$idA, $idB] = [$idB, $idA];
                [$slugA, $slugB] = [$slugB, $slugA];
            }

            if ($repete === null) {
                $lado = null;
            } elseif ($repete === 'ambos' || $slugA === $slugB) {
                $lado = 'ambos';
            } else {
                $lado = $repete === $slugA ? 'a' : 'b';
            }

            DB::table('estrutura_tipo_pares')->insertOrIgnore([
                'tipo_a_id'     => $idA,
                'tipo_b_id'     => $idB,
                'combit_repete' => $lado,
                'created_at'    => $agora,
                'updated_at'    => $agora,
            ]);
        }
    }

    public function down(): void
    {
        // Vazio de propósito: a ECF pode ter editado tipos e pares; desfazer a criação
        // apaga as tabelas.
    }
};
