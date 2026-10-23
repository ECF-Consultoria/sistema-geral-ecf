<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 23/10/2026 — Agente ECF Publicador, E1: o CALENDÁRIO da janela de preparo.
 *
 * A `janela_inicio`/`janela_fim` da AG-01 diz a HORA, e nada mais: uma loja que só quer preparo em dia de
 * semana não tinha como dizer isso, e o agente preparava no domingo. A E1 pede *"calendário"* e
 * *"Configuração/Ritmo: dias/horários, limites com unidades, tentativas, cooldown e fuso"*.
 *
 * ── A DECISÃO DE SCHEMA, antes do código ────────────────────────────────────────────────────────
 *
 * | coluna      | tipo      | regra                                                                      |
 * |-------------|-----------|----------------------------------------------------------------------------|
 * | dias_semana | json NULL | `[1..7]` em **ISO-8601**: 1 = segunda … 7 = domingo. `null`/`[]` = TODO dia |
 *
 * ⚠️ **ISO (1=segunda … 7=domingo), nunca o `dayOfWeek` do Carbon (0=domingo).** O 0-based é a armadilha
 * clássica de um-a-menos aqui: `0` é um valor "falsy" em PHP e em JS, então domingo marcado desapareceria em
 * qualquer `array_filter`, `if ($dia)` ou `.filter(Boolean)` da tela. Com ISO não há dia `0`. O lado PHP lê
 * `Carbon::isoWeekday()` e o lado JS manda os mesmos números.
 *
 * ⚠️ **`null` = todo dia, e isso é de PROPÓSITO**, pela mesma convenção que a janela de hora já usa
 * (`PoliticaDoAgente::dentroDaJanela`: *"sem janela = sempre"*). Toda loja que já existe tem a coluna `null`,
 * então **nada muda de comportamento no deploy** — é o que torna esta migration segura sem backfill.
 * `[]` recebe o mesmo tratamento: lista vazia é "não restringi", não "nenhum dia" (que travaria o agente em
 * silêncio e seria lido como defeito).
 *
 * ── Disciplina de migration deste projeto, item por item ────────────────────────────────────────
 *
 * · **`ALTER TABLE` numa tabela PEQUENA**: `pub_agente_configuracoes` tem no máximo UMA linha por conta do
 *   Publicador (unique em `conta_chave`), então é instantâneo — nada da classe de risco do `ml_acervo_itens`.
 * · **Idempotente** (`hasTable` + `hasColumn`): rodar duas vezes não quebra.
 * · **Sem `enum()`** — o CHECK é enforçado no SQLite dos testes.
 * · **Sem default em `json`** (MariaDB recusa).
 * · **Nullable**, logo **sem backfill** e sem erro 1830 (não há FK aqui).
 * · **Nenhum índice novo**: ninguém consulta por dia da semana — a política lê a configuração da conta que já
 *   veio pelo unique `pubagcfg_conta_uq`. Índice que nenhuma consulta usa é custo de escrita sem retorno, e
 *   nome de índice é onde o MariaDB recusa acima de 64 caracteres (erro 1059).
 * · **Sem `try/catch` em volta do DDL** — um 1553 já ficou engolido dois meses neste projeto.
 * · **`down()` existe e é idempotente**, para a prova migrate → rollback → migrate no MariaDB local.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('pub_agente_configuracoes')) {
            return;
        }

        if (Schema::hasColumn('pub_agente_configuracoes', 'dias_semana')) {
            return;
        }

        Schema::table('pub_agente_configuracoes', function (Blueprint $t) {
            // ⚠️ `after()` é ignorado em silêncio no SQLite e respeitado no MySQL/MariaDB — é só ordem de
            // coluna, não muda semântica, e deixa o calendário ao lado da janela de hora de que ele é par.
            $t->json('dias_semana')->nullable()->after('janela_fim');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('pub_agente_configuracoes')) {
            return;
        }

        if (! Schema::hasColumn('pub_agente_configuracoes', 'dias_semana')) {
            return;
        }

        Schema::table('pub_agente_configuracoes', function (Blueprint $t) {
            $t->dropColumn('dias_semana');
        });
    }
};
