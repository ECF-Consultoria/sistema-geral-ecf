<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 26/10/2026 — Agente ECF Publicador, E1: o RITMO — `cooldown_minutos` e `tentativas_max`.
 *
 * Fecha a linha *"Configuração/Ritmo: dias/horários, limites com unidades, **tentativas, cooldown** e
 * fuso"* da §7.1 do plano. Os dias e horários já entraram em 23/10 (`dias_semana`, `janela_*`).
 *
 * ── ⚠️⚠️ A LACUNA QUE ISTO FECHA, MEDIDA ANTES DE ESCREVER ──────────────────────────────────────
 *
 * `VarreduraDoAgente::candidatos()` já exclui produto com execução **viva** ou **terminada com
 * resultado**, e já tem um cooldown **de fato** de 24 h — mas **só para `BLOQUEADA`**
 * (`->where('estado', BLOQUEADA)->where('concluida_em', '>=', now()->subDay())`).
 *
 * ⚠️ **`FALHOU` não é excluído por nada.** Um produto cuja preparação falhou pode ser repescado na
 * **passada seguinte** do agendador — e cada passada gasta chamada de IA **paga**. É um laço de
 * gasto, e o plano é explícito: *"não trocar segurança contra velocidade aparente"*.
 *
 * ── A DECISÃO DE SCHEMA, antes do código ────────────────────────────────────────────────────────
 *
 * | coluna            | tipo                          | regra                                     |
 * |-------------------|-------------------------------|-------------------------------------------|
 * | cooldown_minutos  | unsignedSmallInteger NULL     | espera antes de repescar o MESMO produto  |
 * | tentativas_max    | unsignedTinyInteger NULL      | quantas vezes a cadeia de texto é repedida|
 *
 * ⚠️⚠️ **`NULL` = o padrão de hoje, e isso é o que torna a migration segura.** Toda loja existente
 * recebe as duas colunas em `null`, e o serviço resolve `null` para **exatamente** o que vale hoje:
 * **1440 minutos** (as 24 h da varredura) e **`PreparadorDaAprovacao::MAX_TENTATIVAS`** (2). Logo o
 * deploy **não muda o comportamento de nenhuma loja** — nem para mais, nem para menos.
 *
 * ⚠️ **`unsignedSmallInteger` para o cooldown:** cabe até 65.535 minutos (≈ 45 dias), com folga
 * sobre o máximo que a validação aceita (10.080 min = 7 dias). `unsignedTinyInteger` nas
 * tentativas cabe até 255, muito além do que faz sentido (a validação trava em 5) — e é o MESMO
 * tipo da coluna `tentativas` de `pub_agente_execucoes`, que é o contador que ele limita.
 *
 * ── Disciplina de migration deste projeto ───────────────────────────────────────────────────────
 *
 * · `ALTER TABLE` em tabela **pequena**: uma linha por conta (unique em `conta_chave`).
 * · Idempotente (`hasTable` + `hasColumn`); **nullable**, logo **sem backfill**.
 * · Sem `enum()` (o CHECK é enforçado no SQLite dos testes); sem default em json; sem FK nova
 *   (nenhum 1830); **nenhum índice novo** — ninguém consulta por ritmo, e nome de índice é onde o
 *   MariaDB recusa acima de 64 caracteres (erro 1059).
 * · Sem `try/catch` em volta do DDL. `down()` idempotente, para a prova migrate → rollback →
 *   migrate no MariaDB local.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_agente_configuracoes')) {
            return;
        }

        $faltando = array_values(array_filter(
            ['cooldown_minutos', 'tentativas_max'],
            fn (string $c) => ! Schema::hasColumn('pub_agente_configuracoes', $c),
        ));

        if ($faltando === []) {
            return;
        }

        Schema::table('pub_agente_configuracoes', function (Blueprint $t) use ($faltando) {
            // ⚠️ Uma por uma, pelo que FALTA: rodar com só uma das duas já criada (deploy parcial,
            // rollback pela metade) não pode quebrar.
            if (in_array('cooldown_minutos', $faltando, true)) {
                $t->unsignedSmallInteger('cooldown_minutos')->nullable()->after('teto_fila');
            }
            if (in_array('tentativas_max', $faltando, true)) {
                $t->unsignedTinyInteger('tentativas_max')->nullable()->after('cooldown_minutos');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pub_agente_configuracoes')) {
            return;
        }

        $existem = array_values(array_filter(
            ['cooldown_minutos', 'tentativas_max'],
            fn (string $c) => Schema::hasColumn('pub_agente_configuracoes', $c),
        ));

        if ($existem === []) {
            return;
        }

        Schema::table('pub_agente_configuracoes', function (Blueprint $t) use ($existem) {
            $t->dropColumn($existem);
        });
    }
};
