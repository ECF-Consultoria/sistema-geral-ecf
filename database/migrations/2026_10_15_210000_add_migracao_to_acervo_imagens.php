<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * v28.0 Fase 200 (MIG-02/MIG-03) — rastro da migração local → R2 no próprio ativo:
 * - `migrado_em`: quando o objeto passou para o disco das imagens (a janela de 30 dias conta daqui);
 * - `caminhos_legados`: os caminhos locais que o ativo e seus usos tinham antes — é a lista exata que
 *   `acervo:limpar-local-migrado` pode apagar depois da janela (e só se nada mais apontar para eles).
 * Só na tabela nova da v28.0; nenhuma tabela de outra milestone muda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('acervo_imagens', function (Blueprint $t) {
            if (! Schema::hasColumn('acervo_imagens', 'migrado_em')) {
                $t->dateTime('migrado_em')->nullable()->after('status');
            }
            if (! Schema::hasColumn('acervo_imagens', 'caminhos_legados')) {
                $t->json('caminhos_legados')->nullable()->after('migrado_em');
            }
        });
    }

    public function down(): void
    {
        Schema::table('acervo_imagens', function (Blueprint $t) {
            foreach (['caminhos_legados', 'migrado_em'] as $coluna) {
                if (Schema::hasColumn('acervo_imagens', $coluna)) {
                    $t->dropColumn($coluna);
                }
            }
        });
    }
};
