<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Fase 131 Plano 01 (D-13 do 131-CONTEXT.md, CLICK-10).
//
// Acrescenta 3 colunas a `contrato_assinaturas` para o "cancelamento
// solicitado" (D-13): a Clicksign v3 não permite cancelar envelope em
// andamento pela API (medido em 2026-08-14, CLICKSIGN-SANDBOX-EMPIRICO.md
// §15.2 — DELETE devolve 403 em `running`, POST /cancel devolve 404, PATCH
// status:"canceled" devolve 400 "status deve estar em: draft, running").
// Cancelar é operação de PAINEL, igual assinar — o sistema não cancela, ele
// FICA SABENDO quando o webhook `cancel` chega. Estas 3 colunas guardam o
// registro de quem pediu o cancelamento, por quê e quando, até esse webhook
// fechar o estado (`STATUS_CANCELADO`).
//
// ⚠️ "Cancelamento solicitado" NÃO é um 8º valor de `status` — é DERIVADO da
// presença de `cancelamento_solicitado_em`. Um 8º status quebraria as 7
// contagens do resumo da D-04 (131-UI-SPEC.md) e mudaria a semântica do
// índice único de "em andamento" (`ca_empresa_servico_andamento_uniq`,
// baseado em `STATUS_EM_ANDAMENTO`, que continua com 2 valores).
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver CLAUDE.md/
// memória do projeto e `131-CONTEXT.md`) — as três se aplicam aqui:
//   1. `enum` + SQLite (CHECK derruba a suíte) — não usado. `cancelamento_motivo`
//      é `text()` LIVRE: o 131-UI-SPEC.md confirma texto livre para o motivo
//      do cancelamento, ao contrário do `motivo_slug` da liberação manual
//      (Fase 130), que tem lista fechada (`MOTIVOS_MANUAIS`).
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) —
//      `cancelamento_solicitado_por_user_id` é `foreignId()->nullable()`
//      ANTES de `->constrained()->nullOnDelete()`: preserva o motivo e a
//      data mesmo se o usuário autor for apagado (T-131-01-05).
//   3. Nome de índice/FK acima de 64 caracteres (erro 1059) — o nome que o
//      Laravel geraria para esta FK
//      (`contrato_assinaturas_cancelamento_solicitado_por_user_id_foreign`)
//      tem 65 caracteres, estourando o limite. FK nomeada explicitamente e
//      curta: `ca_cancel_solic_user_fk`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'cancelamento_motivo')) {
                $table->text('cancelamento_motivo')->nullable()->after('lembrete_dias');
            }

            if (! Schema::hasColumn('contrato_assinaturas', 'cancelamento_solicitado_por_user_id')) {
                $table->foreignId('cancelamento_solicitado_por_user_id')
                    ->nullable()
                    ->after('cancelamento_motivo')
                    ->constrained('users', 'id', 'ca_cancel_solic_user_fk')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('contrato_assinaturas', 'cancelamento_solicitado_em')) {
                $table->timestamp('cancelamento_solicitado_em')->nullable()->after('cancelamento_solicitado_por_user_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'cancelamento_solicitado_por_user_id')) {
                $table->dropForeign('ca_cancel_solic_user_fk');
            }

            if (Schema::hasColumn('contrato_assinaturas', 'cancelamento_solicitado_em')) {
                $table->dropColumn('cancelamento_solicitado_em');
            }

            if (Schema::hasColumn('contrato_assinaturas', 'cancelamento_solicitado_por_user_id')) {
                $table->dropColumn('cancelamento_solicitado_por_user_id');
            }

            if (Schema::hasColumn('contrato_assinaturas', 'cancelamento_motivo')) {
                $table->dropColumn('cancelamento_motivo');
            }
        });
    }
};
