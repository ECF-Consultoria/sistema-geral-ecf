<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick 260825-fn0 (Tarefa 1) — de onde vem a plataforma do contrato.
 *
 * Relato do usuário (2026-08-25): "nem todos contratos cliente fecham o
 * serviço de gerirmos as duas plataformas" — o modelo `.docx` v5 trocou as
 * 11 ocorrências fixas de "Mercado Livre e Shopee" por `{{plataformas}}`, e
 * essa variável precisa saber, POR SERVIÇO, qual plataforma ele cobre
 * (Gestão de Ads → Mercado Livre; Gestão Shopee → Shopee).
 *
 * `string` **nullable**: serviço sem plataforma configurada é o caso
 * ESPERADO logo após esta migration (nenhum serviço é preenchido aqui — ver
 * abaixo). Quem lê tolera a ausência e mostra `A DEFINIR` (Tarefa 3), nunca
 * quebra.
 *
 * ⚠️ Esta migration NÃO preenche `plataforma` de nenhum serviço. É passo de
 * PRODUÇÃO, pós-deploy, conferido por reconsulta ao banco — mesma disciplina
 * de `add_clicksign_assinatura_posicionada...` e `add_exige_contrato...`.
 *
 * `string` simples, sem índice, sem FK — nenhuma armadilha de MariaDB deste
 * projeto se aplica. Idempotente via `hasColumn`, mesmo padrão das
 * migrations irmãs desta tabela.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('servicos', 'plataforma')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->string('plataforma')->nullable()->after('clicksign_assinatura_posicionada');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('servicos', 'plataforma')) {
            Schema::table('servicos', function (Blueprint $table) {
                $table->dropColumn('plataforma');
            });
        }
    }
};
