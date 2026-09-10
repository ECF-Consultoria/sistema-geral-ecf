<?php

// pt-BR: Migration da tabela de auditoria de RECONSOLIDAÇÃO do fechamento
// mensal (Fase 137, D-12 revisado). 1 linha por reconsolidação de
// competência, gravada ANTES de o writer (plano 05) sobrescrever qualquer
// linha de `fechamento_snapshots` / `fechamento_grupo_snapshots`.
//
// Divergência DELIBERADA em relação ao molde do Desempenho: lá,
// `CompanyScoreSnapshotWriter` deixa a origem oficial ('consolidar_mes')
// ignorar a trava de congelamento silenciosamente e só registra em `Log`
// (ver .planning/learnings/desempenho-bonificacao.md §0.05/§10.1). Aqui o
// registro é dado de BANCO, não log — porque o valor gravado entra em
// cobrança e precisa ser auditável por quem, quando e por quê (D-12).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fechamento_reconsolidacoes')) {
            return;
        }

        Schema::create('fechamento_reconsolidacoes', function (Blueprint $table) {
            $table->id();

            $table->date('mes_referencia');

            // null = rodada por CLI sem usuário (ex.: cron de reconsolidação
            // automática). ->nullable() ANTES de ->nullOnDelete() na mesma
            // cadeia — erro 1830 em MariaDB se a ordem for trocada.
            $table->foreignId('reconsolidado_por')->nullable()->constrained('users')->nullOnDelete();

            // D-12 exige o "por quê" — NOT NULL de propósito. O dialog
            // RefazerFechamentoDialog do UI-SPEC já modela o campo como
            // obrigatório.
            $table->text('motivo');

            // Payload completo das linhas de fechamento_snapshots e
            // fechamento_grupo_snapshots daquela competência, como estavam
            // ANTES da sobrescrita — chaves 'empresas' e 'grupos'.
            $table->json('snapshot_anterior');

            $table->string('origem', 32);

            $table->timestamps();

            $table->index(['mes_referencia'], 'fecha_recons_mes_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fechamento_reconsolidacoes');
    }
};
