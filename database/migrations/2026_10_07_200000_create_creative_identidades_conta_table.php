<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Identidade visual por CONTA de marketplace (Fase 170, D2, IDENT-02..05).
 *
 * Âncora dual `company_id`/`mlb_empresa_id` — mesmo padrão de
 * `ml_anuncio_criativos` (migration `2026_10_02_120000_...`): a identidade é
 * da CONTA, nunca da empresa cliente nem do produto. UM campo de texto livre
 * só (`texto`) — "como se fosse um prompt mesmo", decisão do usuário de não
 * ter campos estruturados de cor/fonte/forma/filtro/estilo separados.
 *
 * ⚠️ D3 (marca aplicada como arquivo/upload sobre a imagem) foi eliminado
 * deste planejamento de propósito (decisão do usuário em 2026-10-07, ver
 * objetivo do 170-01-PLAN.md) — NENHUMA coluna desse tipo nesta tabela.
 *
 * `status`/`etapa` de outras tabelas são STRING, nunca `enum` — aqui nem
 * sequer há enum candidato, mas a convenção se mantém (`text` para o
 * conteúdo livre). Toda FK `nullOnDelete()` tem `->nullable()` EXPLÍCITO
 * (erro 1830 do MariaDB, invisível no SQLite dos testes). Índices NOMEADOS à
 * mão (erro 1059 do MariaDB acima de 64 caracteres).
 *
 * Únicos parciais: em MySQL/MariaDB, múltiplas linhas com a coluna NULL não
 * colidem num índice único — por isso dá para ter unique em `company_id` E
 * em `mlb_empresa_id` ao mesmo tempo, garantindo no máximo UMA identidade por
 * conta sem impedir que a âncora oposta fique nula em todas as linhas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('creative_identidades_conta')) {
            return;
        }

        Schema::create('creative_identidades_conta', function (Blueprint $table) {
            $table->id();

            $table->foreignId('company_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('mlb_empresa_id')->nullable()->constrained('mlb_empresas')->nullOnDelete();

            // A identidade visual, texto livre (D2) — cores, fonte, forma,
            // filtro, acabamento, tudo dentro deste único campo.
            $table->text('texto')->nullable();

            $table->timestamps();

            $table->unique('company_id', 'creative_ident_conta_company_unq');
            $table->unique('mlb_empresa_id', 'creative_ident_conta_empresa_unq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_identidades_conta');
    }
};
