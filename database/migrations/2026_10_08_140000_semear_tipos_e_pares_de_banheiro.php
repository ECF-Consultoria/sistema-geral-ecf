<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Planejamento (08/10) — semente ADITIVA dos tipos e pares de banheiro (gabinete, espelho,
 * lixeira, toalheiro, acessórios). Só dados, nenhum schema. Os valores vêm de
 * `config/estrutura_geracao.php`, mas SÓ os slugs e pares listados aqui entram: a semente da
 * 168 (2026_10_07_100100) já rodou em produção e a ECF pode ter editado ou EXCLUÍDO tipos e
 * pares pela tela admin desde então. Reler o config inteiro recriaria o que ela tirou.
 *
 * IDEMPOTENTE por `insertOrIgnore` (únicos `etp_slug_uq` e `etpar_uq`): tipo que a ECF já
 * criou com o mesmo slug fica como ela deixou (nome, palavras e quantidades dela); par que já
 * existe fica com a direção dela. Banco novo: a semente da 168 já lê estes do config e esta
 * não muda nada.
 *
 * Par guardado NÃO ordenado (tipo_a_id <= tipo_b_id), como na 168. Todos os pares daqui são
 * só Kit (`combit_repete` nulo).
 */
return new class extends Migration
{
    private const TIPOS = ['gabinete', 'espelho', 'lixeira', 'toalheiro', 'acessorio-banheiro'];

    private const PARES = [
        ['gabinete', 'espelho'],
        ['gabinete', 'lixeira'],
        ['espelho', 'lixeira'],
        ['gabinete', 'toalheiro'],
        ['gabinete', 'acessorio-banheiro'],
    ];

    public function up(): void
    {
        $agora = now();
        $config = (array) config('estrutura_geracao.tipos', []);

        foreach (self::TIPOS as $slug) {
            $tipo = $config[$slug] ?? null;
            if ($tipo === null) {
                continue;
            }

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

        foreach (self::PARES as [$slugA, $slugB]) {
            if (! isset($ids[$slugA], $ids[$slugB])) {
                continue;
            }

            $idA = (int) $ids[$slugA];
            $idB = (int) $ids[$slugB];
            if ($idA > $idB) {
                [$idA, $idB] = [$idB, $idA];
            }

            DB::table('estrutura_tipo_pares')->insertOrIgnore([
                'tipo_a_id'     => $idA,
                'tipo_b_id'     => $idB,
                'combit_repete' => null,
                'created_at'    => $agora,
                'updated_at'    => $agora,
            ]);
        }
    }

    public function down(): void
    {
        // Vazio de propósito: depois da semente a ECF pode ter passado a usar estes tipos
        // (produtos com tipo escolhido, pares editados); apagar desfaria o trabalho dela.
    }
};
