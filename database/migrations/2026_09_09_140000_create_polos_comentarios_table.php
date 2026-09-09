<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comentários de performance por empresa e mês — exclusivos da tela /polos/empresas.
 *
 * Por que existe: o painel já mostra faturamento, meta e ADS da empresa no mês, mas o
 * PORQUÊ do número não morava em lugar nenhum ("caiu porque ficou sem estoque na S3",
 * "ADS desligado a pedido do cliente"). Isso circulava em conversa e se perdia.
 *
 * Decisões de schema (documentadas ANTES de existir a tabela — CLAUDE.md):
 *
 *  - **Chave é `cust_id` + `mes`, não `mlb_empresa_id`.** A lista de /polos/empresas é
 *    montada por cust_id normalizado (`App\Support\CustId::normaliza`), e em mês FECHADO o
 *    roster é reconstruído do CSV/snapshot — não há MlbEmpresa garantida do outro lado.
 *    Amarrar no id do cadastro faria o comentário sumir exatamente nos meses históricos.
 *
 *  - **`mes` é obrigatório**: o comentário fala do desempenho DAQUELE mês (a tela toda é
 *    recortada por competência, e o painel semanal ao lado do qual ele aparece é do mês
 *    selecionado). Comentário de agosto não polui a leitura de setembro.
 *
 *  - **`autor_nome` é snapshot, além do `user_id`.** A tela precisa dizer quem anotou; se
 *    o usuário for desativado/removido, a autoria não pode virar "—". O `user_id` continua
 *    sendo a fonte de permissão (só o autor ou um admin edita/apaga).
 *
 *  - **`editado_em` explícito, não `updated_at != created_at`.** Qualquer `touch()` futuro
 *    marcaria o comentário como editado sem ninguém ter editado nada.
 *
 *  - Sem FK para mlb_empresas (a chave nem é ela); a FK de users é `nullable + nullOnDelete`
 *    — nullable é pré-requisito do nullOnDelete no MariaDB (erro 1830 dos learnings).
 *
 * Migration idempotente: re-rodar não recria a tabela existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('polos_comentarios')) {
            return;
        }

        Schema::create('polos_comentarios', function (Blueprint $table) {
            $table->id();

            // Normalizado por App\Support\CustId::normaliza — mesma chave de
            // polos_faturamento_snapshots e da lista de /polos/empresas.
            $table->string('cust_id', 50);

            // 'YYYYMM' — mesmo formato do $mesSel do controller.
            $table->string('mes', 6);

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('autor_nome', 120)->nullable();

            $table->text('texto');

            // NULL = nunca editado. Preenchido no update para a tela poder dizer "editado em".
            $table->timestamp('editado_em')->nullable();

            $table->timestamps();

            // Leitura da tela é sempre "comentários desta empresa neste mês".
            // Nome gerado: polos_comentarios_cust_id_mes_index (35 chars, sob o limite de 64).
            $table->index(['cust_id', 'mes']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('polos_comentarios');
    }
};
