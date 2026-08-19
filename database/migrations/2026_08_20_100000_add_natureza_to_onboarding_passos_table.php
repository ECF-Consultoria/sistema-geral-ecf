<?php

use App\Models\OnboardingPasso;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `natureza` — COMO o item se preenche. Terceiro eixo do passo, ao lado de
 * `dono` ("de quem é a bola") e `auto_fonte` ("como o sistema sabe").
 *
 * Pedido do negócio (2026-08-19): o checklist passa a ter itens de naturezas
 * diferentes — afazer do cliente, item conduzido NA REUNIÃO, e pergunta com
 * resposta preenchida pela equipe (a maioria).
 *
 * ─── Por que eixo novo, e não um valor a mais em `dono` ou `etapa` ─────────
 * `dono` responde QUEM age e tem catálogo fechado de três (D-14 recusou o
 * quarto por escrito). O ponto que decide: "conduzir na reunião" e "responder
 * uma pergunta" são AMBOS `dono=interno` — mesmo dono, comportamento de tela
 * completamente diferente. Colapsar os dois num eixo só repete exatamente o
 * erro que D-19 evitou ao separar `dono` de `auto_fonte`.
 *
 * `etapa` responde EM QUE BLOCO o passo aparece. Uma pergunta de investimento
 * e um afazer de acesso podem cair no mesmo bloco, e um bloco mistura
 * naturezas. São eixos ortogonais.
 *
 * O eixo NÃO reencoda "cliente": o afazer do cliente já é `dono=cliente` +
 * `natureza=acao`, que é o que o portal filtra hoje.
 *
 * ─── Estrutural, logo COPIADA no nascimento ────────────────────────────────
 * Mesma regra de `etapa` (migration 2026_08_17_120000): a natureza decide como
 * o item se apresenta, então é copiada da definição por `montarPassos()` e
 * congelada. Deployar receita nova não reorganiza a tela de quem já está no
 * meio do processo.
 *
 * `NULL` e não `NOT NULL`: as linhas que já existem nasceram sem o conceito. O
 * backfill preenche todas, mas a coluna fica nullable para que uma linha de
 * versão futura nunca derrube um INSERT — o front trata `null` como `acao`.
 *
 * varchar(20) + constante em PHP, nunca enum (learnings §6). O valor mais
 * longo do catálogo é `pergunta`, 8 chars — folga de sobra.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('onboarding_passos', 'natureza')) {
            Schema::table('onboarding_passos', function (Blueprint $table) {
                $table->string('natureza', 20)->nullable()->after('etapa');
            });
        }

        // Backfill: tudo que existe hoje é ação — os 9 passos da v10 e as
        // chaves mortas das versões anteriores. `DB::table()->update()` puro,
        // nunca `UPDATE <tabela> <alias> SET`, que o MariaDB aceita e o SQLite
        // dos testes recusa (learnings §6). Em teste a tabela nasce vazia e o
        // update não afeta linha nenhuma.
        DB::table('onboarding_passos')
            ->whereNull('natureza')
            ->update(['natureza' => OnboardingPasso::NATUREZA_ACAO]);
    }

    public function down(): void
    {
        Schema::table('onboarding_passos', function (Blueprint $table) {
            $table->dropColumn('natureza');
        });
    }
};
