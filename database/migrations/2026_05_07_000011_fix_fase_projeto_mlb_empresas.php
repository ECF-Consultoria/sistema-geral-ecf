<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Limpa fases que na verdade eram nomes de projeto (Implantação, Incubadora,
 * ASSESSORIA/Assessoria) — move pra coluna `projeto` e zera a fase, já que
 * essas empresas não têm Status real.
 *
 * Idempotente — re-rodar não altera nada se os dados já foram migrados.
 *
 * Também garante POLOS para qualquer empresa M0..M4 que ficou sem projeto
 * (defesa em profundidade caso 000010 tenha falhado em alguma linha).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Move "fases que eram projeto" para a coluna projeto e limpa o status (fase)
        $movePairs = [
            'Implantação' => 'Implantação',
            'Incubadora'  => 'Incubadora',
            'ASSESSORIA'  => 'Assessoria',
            'Assessoria'  => 'Assessoria',
        ];

        foreach ($movePairs as $faseOld => $projetoNew) {
            DB::table('mlb_empresas')
                ->where('fase', $faseOld)
                ->update([
                    'projeto' => $projetoNew,
                    'fase'    => null,
                ]);
        }

        // Garante POLOS para M0..M4 caso 000010 não tenha aplicado em alguma linha
        DB::table('mlb_empresas')
            ->whereIn('fase', ['M0', 'M1', 'M2', 'M3', 'M4'])
            ->whereNull('projeto')
            ->update(['projeto' => 'POLOS']);
    }

    public function down(): void
    {
        // Sem rollback automático — esta migration é uma limpeza de dados
        // e reverter pode causar perda. Se precisar reverter, restaure backup.
    }
};
