<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 137 (plano 03, D-16 — Opção B) — histórico append-only de transição de
 * `companies.etapa`. Molde: `company_manager_history` (Fase 108).
 *
 * Por que tabela dedicada em vez de `spatie/laravel-activitylog`:
 * `config/activitylog.php` tem `delete_records_older_than_days => 365`, e este
 * histórico é o insumo que a Fase 143 usa para medir SLA ao longo do tempo — um
 * pipeline de retenção que apaga linhas antigas por padrão é risco desalinhado
 * com o propósito do dado. `motivo` e `retrocesso` (D-15) são conceitos de
 * primeira classe desta máquina de estados, não `properties` genérico de diff.
 *
 * `etapa_anterior` é NULLABLE de propósito: a primeira transição de uma
 * empresa legada parte de `NULL` (D-03 — o backfill nunca carimba etapa
 * intermediária). Log imutável: só `created_at`, sem `updated_at`.
 *
 * Fase 137 (plano 09, gap closure G2 / CR-02 do `137-REVIEW.md`) — `user_id`
 * é o AUTOR da transição, não o dono do registro: um único colaborador pode
 * ter movimentado dezenas de empresas. `UserController::forceDestroy()`
 * (linha 436) já é rota admin ativa e faz `$user->forceDelete()` — hard
 * delete real, não soft (`User` usa `SoftDeletes`, mas essa rota o
 * contorna). Uma FK em CASCATA aqui apagaria a linha de histórico
 * de TODAS as empresas que esse ator movimentou — não só as dele —,
 * reintroduzindo por outra porta exatamente a perda de retenção que o
 * parágrafo acima usa para justificar não ter escolhido
 * `spatie/laravel-activitylog`. Por isso `user_id` é `nullable()` com
 * `nullOnDelete()`: a linha inteira (`etapa_anterior`, `etapa_nova`,
 * `motivo`, `retrocesso`, `created_at`) sobrevive, só a referência ao ator
 * se perde — mesmo tratamento do precedente irmão `companies.pendencia_por`
 * (`..._130000_add_pendencia_to_companies_table`, criado na MESMA fase para
 * o mesmo conceito). `restrictOnDelete()` foi descartado: bloquearia
 * `forceDestroy()` de qualquer usuário que já tenha movimentado uma etapa,
 * trocando perda silenciosa por um impasse administrativo sem saída.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_etapa_transicoes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('etapa_anterior', 40)->nullable();
            $table->string('etapa_nova', 40);
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('motivo')->nullable();
            $table->boolean('retrocesso')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_etapa_transicoes');
    }
};
