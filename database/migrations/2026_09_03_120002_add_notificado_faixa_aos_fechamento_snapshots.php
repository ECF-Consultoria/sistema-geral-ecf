<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 138 (D-02 + D-03) — trava de idempotência do aviso de mudança de
 * faixa em `fechamento_snapshots` e `fechamento_grupo_snapshots`.
 *
 * Duas colunas, não uma só — esta é a decisão de D-03:
 *   - `notificado_em` (timestamp nullable) responde "já avisei?".
 *   - `notificado_faixa_ordem` (unsignedSmallInteger nullable) responde
 *     "avisei ISTO?", guardando a faixa que estava valendo no momento do
 *     aviso.
 *
 * Por que as duas juntas: `fechamento:consolidar-mes` roda de novo a cada
 * "Refazer fechamento" (em 2026-09-03 o usuário clicou "Refazer" três vezes
 * em poucos segundos). Com `notificado_em` sozinho, um "Refazer" que não
 * muda nada dispararia um aviso duplicado sempre que alguém limpasse o
 * timestamp para forçar reenvio. Com as duas colunas, o notificador do
 * plano 05 compara `notificado_faixa_ordem` com `faixa_ordem` atual: iguais
 * → silêncio (nada mudou); diferentes → aviso novo (a faixa nova é
 * informação nova sobre quanto cobrar, mesmo vindo de uma correção).
 *
 * Estas colunas são escritas SÓ pelo notificador (plano 05) — nunca fazem
 * parte do array `$dados` montado por `ConsolidarMesFechamento` e gravado
 * via `FechamentoSnapshotWriter::sync()` (`$existente->fill($dados)->save()`
 * nas linhas ~151 e ~204). Por não estarem no payload, sobrevivem ao
 * `fill()` de uma reconsolidação — é esse detalhe que faz a marca de "já
 * avisei" não ser apagada quando o usuário refaz o fechamento.
 *
 * Cuidado de MariaDB (não se aplica aqui, documentado por disciplina): sem
 * FK nova e sem índice novo, não há risco de erro 1059 (nome de índice
 * >64 chars) nem 1830 (`nullOnDelete` sem `nullable`). `after()` é ignorado
 * pelo SQLite dos testes — esperado, não é falha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fechamento_snapshots', function (Blueprint $t) {
            if (! Schema::hasColumn('fechamento_snapshots', 'notificado_em')) {
                $t->timestamp('notificado_em')->nullable()->after('gerado_em');
            }
            if (! Schema::hasColumn('fechamento_snapshots', 'notificado_faixa_ordem')) {
                $t->unsignedSmallInteger('notificado_faixa_ordem')->nullable()->after('notificado_em');
            }
        });

        Schema::table('fechamento_grupo_snapshots', function (Blueprint $t) {
            if (! Schema::hasColumn('fechamento_grupo_snapshots', 'notificado_em')) {
                $t->timestamp('notificado_em')->nullable()->after('gerado_em');
            }
            if (! Schema::hasColumn('fechamento_grupo_snapshots', 'notificado_faixa_ordem')) {
                $t->unsignedSmallInteger('notificado_faixa_ordem')->nullable()->after('notificado_em');
            }
        });
    }

    public function down(): void
    {
        Schema::table('fechamento_snapshots', function (Blueprint $t) {
            if (Schema::hasColumn('fechamento_snapshots', 'notificado_faixa_ordem')) {
                $t->dropColumn('notificado_faixa_ordem');
            }
            if (Schema::hasColumn('fechamento_snapshots', 'notificado_em')) {
                $t->dropColumn('notificado_em');
            }
        });

        Schema::table('fechamento_grupo_snapshots', function (Blueprint $t) {
            if (Schema::hasColumn('fechamento_grupo_snapshots', 'notificado_faixa_ordem')) {
                $t->dropColumn('notificado_faixa_ordem');
            }
            if (Schema::hasColumn('fechamento_grupo_snapshots', 'notificado_em')) {
                $t->dropColumn('notificado_em');
            }
        });
    }
};
