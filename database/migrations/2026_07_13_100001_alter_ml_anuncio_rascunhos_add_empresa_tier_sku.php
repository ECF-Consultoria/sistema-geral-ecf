<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 75 Plan 01 — Adiciona colunas de escopo e metadados em ml_anuncio_rascunhos.
 *
 * Motivacao:
 *  - mlb_empresa_id: ancora imutavel que liga o rascunho a empresa MLB de destino.
 *    E a coluna usada no double-check de 403 (SEL-04) e no escopo por responsavel_id
 *    (SEL-03). A imutabilidade e imposta pelo controller na Wave 2; o model apenas
 *    guarda a referencia.
 *  - sku_origem: rastreia o produto do cliente que originou o rascunho. Pre-preenchido
 *    automaticamente na Phase 76 (painel de cards com busca de produtos). Nasce nulo.
 *  - listing_tier: tier do anuncio — classico (gold_special) ou premium (gold_pro).
 *    Usado na Phase 79 para diferenciar regras de publicacao. Nasce nulo.
 *
 * Indice composto (mlb_empresa_id, status): acelera a contagem de rascunhos por
 * empresa agrupados por status (consulta central do painel de cards na Phase 75).
 *
 * FK strategy: nullOnDelete (NAO cascadeOnDelete).
 *  - Se a MlbEmpresa for deletada, o rascunho sobrevive com mlb_empresa_id = NULL
 *    e permanece auditavel. O rascunho nao deve ser apagado cascata junto com a
 *    empresa (historico de trabalho do publicador deve ser preservado).
 *
 * Idempotencia: guard Schema::hasColumn antes do alter — pode rodar duas vezes
 * sem erro, tanto no local (XAMPP/MariaDB) quanto na VPS (MySQL).
 *
 * @see .planning/phases/75-funda-o-de-seguran-a-painel-de-cards/75-01-PLAN.md
 * @see app/Models/MlAnuncioRascunho.php (model atualizado neste plan)
 * @see app/Models/MlbEmpresa.php (tabela mlb_empresas — destino da FK)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('ml_anuncio_rascunhos', 'mlb_empresa_id')) {
            Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
                // FK para mlb_empresas — ancora imutavel do rascunho na empresa de destino.
                // nullable() porque:
                //   (a) rascunhos antigos (criados antes desta migration) ficam NULL ate
                //       serem associados manualmente ou via backfill;
                //   (b) rollback intermediario nao quebra rascunhos existentes.
                // nullOnDelete() preserva o rascunho se a empresa for hard-deletada.
                $table->foreignId('mlb_empresa_id')
                    ->nullable()
                    ->after('company_id')
                    ->constrained('mlb_empresas')
                    ->nullOnDelete()
                    ->comment('Empresa MLB de destino — fixada na criação, imutável pelo publicador (SEL-03)');

                // SKU do produto do cliente que originou o rascunho.
                // Pre-preenchido na Phase 76; nasce nulo para nao quebrar rascunhos existentes.
                $table->string('sku_origem')
                    ->nullable()
                    ->after('mlb_empresa_id')
                    ->comment('SKU do produto do cliente que originou o rascunho — pré-preenchido na Phase 76');

                // Tier do anuncio: classico (gold_special) ou premium (gold_pro).
                // Comprimento 20 cobre os valores atuais da API ML com margem.
                // Usado na Phase 79 para regras de publicacao diferenciadas.
                $table->string('listing_tier', 20)
                    ->nullable()
                    ->after('sku_origem')
                    ->comment('Tier do anúncio: classico (gold_special) ou premium (gold_pro) — usado na Phase 79');

                // Indice composto para acelerar contagem de rascunhos por empresa+status
                // (consulta central do painel de cards na Phase 75).
                $table->index(['mlb_empresa_id', 'status']);
            });
        }
    }

    /**
     * Reverte apenas o ALTER. Idempotente: so dropa se as colunas existirem.
     *
     * Ordem: dropIndex ANTES de dropConstrainedForeignId (MySQL exige que a FK
     * seja removida antes do indice que a referencia; e o indice composto deve
     * ser removido antes da coluna que o compoe).
     */
    public function down(): void
    {
        if (Schema::hasColumn('ml_anuncio_rascunhos', 'mlb_empresa_id')) {
            Schema::table('ml_anuncio_rascunhos', function (Blueprint $table) {
                // Remove indice composto antes de remover as colunas.
                $table->dropIndex(['mlb_empresa_id', 'status']);

                // dropConstrainedForeignId cuida tanto do FK quanto da coluna
                // (Laravel 12 — equivalente a dropForeign + dropColumn).
                $table->dropConstrainedForeignId('mlb_empresa_id');

                // Remove as demais colunas adicionadas nesta migration.
                $table->dropColumn(['sku_origem', 'listing_tier']);
            });
        }
    }
};
