<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260911-eph — `fechamento_snapshots.faturamento_fonte`: de onde veio o
 * número de faturamento que foi congelado nesta competência.
 *
 * Valores possíveis (constantes em `App\Models\FechamentoSnapshot`):
 *   - 'api'                  → `/performance` da Adman (grossBilling do intervalo)
 *   - 'soma_diaria'          → SUM(adman_metrics.revenue) dos dias do mês
 *   - 'soma_diaria_fallback' → a API foi tentada, falhou, e caiu no SUM
 *
 * Por que registrar isso: a soma diária é escrita uma vez, na manhã seguinte, e
 * nunca revisitada — a Adman aplica ajustes retroativos (devoluções,
 * conciliação) que não voltam para o nosso banco. Medido em produção na DESK
 * DESIGN, agosto/2026: SUM = R$ 167.537,54 contra R$ 170.363,19 no
 * `/performance`. Sem esta coluna, um mês inteiro consolidado em fallback fica
 * indistinguível de um mês consolidado com a fonte boa.
 *
 * **nullable de propósito**: as linhas que JÁ existem foram gravadas antes
 * desta coluna e não têm essa informação — `null` significa "não sei", nunca
 * "foi soma diária". Adivinhar retroativamente a fonte de um snapshot já
 * congelado seria inventar auditoria.
 *
 * Armadilhas de MariaDB já pagas neste projeto e evitadas aqui de propósito:
 *   - NÃO é `enum()` do MySQL — enum quebra o SQLite da suíte de testes; é
 *     `string(32)` + constantes `public const` no model, como o resto do
 *     projeto (mesmo padrão de `estado`).
 *   - NÃO cria índice — nome de índice acima de 64 caracteres é recusado pelo
 *     MariaDB (erro 1059) e esta coluna nunca é filtro de query quente. Sem
 *     índice, sem risco.
 *   - Sem FK nova: nada de erro 1830 (`nullOnDelete` sem `nullable`).
 *
 * `after()` é ignorado pelo SQLite dos testes — esperado, não é falha.
 *
 * Idempotente (`Schema::hasColumn` no up e no down): rodar duas vezes não
 * quebra nem duplica nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('fechamento_snapshots', 'faturamento_fonte')) {
            Schema::table('fechamento_snapshots', function (Blueprint $table) {
                $table->string('faturamento_fonte', 32)->nullable()->after('faturamento_total');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fechamento_snapshots', 'faturamento_fonte')) {
            Schema::table('fechamento_snapshots', function (Blueprint $table) {
                $table->dropColumn('faturamento_fonte');
            });
        }
    }
};
