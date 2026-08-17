<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// pt-BR: Migration ADITIVA — Fase 130 Plano 01 (D-04 do CONTEXT).
//
// Acrescenta `ultimo_alerta_em` a `contrato_assinaturas`: o registro de
// "quando avisei sobre este contrato pela última vez". Existe para o alerta
// de contrato preso (REDE-03) poder REPETIR o aviso sem repetir todo dia —
// a rotina de alerta lê esta coluna e só notifica de novo se o intervalo de
// repetição configurado já passou.
//
// ⚠️ NÃO é o `remind_interval` da Clicksign (`lembrete_dias` /
// `lembreteDiasEfetivo()`, já existentes no model). Aquele é o lembrete
// nativo da Clicksign para o SIGNATÁRIO CLIENTE assinar — público e
// propósito diferentes. `ultimo_alerta_em` é o carimbo da notificação
// interna para a EQUIPE ECF (admin/comercial) sobre um contrato parado.
// Confundir os dois é o pitfall já registrado no RESEARCH desta fase.
//
// Sem índice novo: o volume de `contrato_assinaturas` é de dezenas, o
// filtro do alerta roda em memória (Collection::filter()), não é critério
// de busca no banco.
//
// Armadilhas de MariaDB que o SQLite dos testes NÃO pega (ver <pitfalls> do
// 130-CONTEXT.md) — nenhuma se aplica nesta migration, motivo registrado:
//   1. `enum` + SQLite (CHECK derruba a suíte) — não usado; coluna
//      `timestamp` nullable, não é vocabulário fechado.
//   2. FK `nullOnDelete()` sem `->nullable()` (erro 1830) — esta migration
//      não declara nenhuma FK.
//   3. Nome de índice acima de 64 caracteres (erro 1059) — esta migration
//      não declara nenhum índice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (! Schema::hasColumn('contrato_assinaturas', 'ultimo_alerta_em')) {
                $table->timestamp('ultimo_alerta_em')->nullable()->after('pdf_assinado_erro');
            }
        });
    }

    public function down(): void
    {
        Schema::table('contrato_assinaturas', function (Blueprint $table) {
            if (Schema::hasColumn('contrato_assinaturas', 'ultimo_alerta_em')) {
                $table->dropColumn('ultimo_alerta_em');
            }
        });
    }
};
