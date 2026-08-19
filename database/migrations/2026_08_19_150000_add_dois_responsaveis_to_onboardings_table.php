<?php

use App\Models\Onboarding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * O onboarding passa a ter DOIS responsáveis: um estrategista e um analista.
 *
 * Decisão de negócio e desenho escritos antes desta migration em
 * `.planning/seeds/onboarding-dois-responsaveis-decisao-schema.md` (R-01/R-02).
 *
 * ─── Por que aditivo, e nunca renomear ─────────────────────────────────────
 * `onboardings` TEM dado em produção desde o deploy da Fase 135 (2026-08-19).
 * `responsavel_id` permanece: é ela que o `LogsActivity` do model já registrou
 * no histórico (`logOnly(['status', 'responsavel_id'])`), e renomeá-la para
 * dar-lhe um significado que os valores atuais não têm (ninguém gravou o PAPEL
 * de quem está lá) é o caminho curto para dado errado silencioso.
 *
 * `responsavel_id` vira o **responsável principal**, com invariante mantida em
 * código pelo engine: se algum dos dois slots está preenchido, ela aponta para
 * um deles.
 *
 * ─── Por que duas colunas, e não tabela pivot ──────────────────────────────
 * São exatamente dois papéis fixos com significado diferente entre si, não uma
 * lista aberta. Pivot só se pagaria com N papéis ou com histórico de troca —
 * ninguém pediu histórico — e cobraria um join em toda leitura da listagem de
 * `/companies`, que já carrega 5 relações por empresa.
 *
 * ─── O backfill e o seu default ────────────────────────────────────────────
 * O papel de quem já está em `responsavel_id` é decidido pelo vínculo daquele
 * usuário com aquela empresa em `company_users`: `estrategista` (e não
 * `consultor`) vai para o slot de estrategista; todo o resto vai para o de
 * analista. O default não é arbitrário —
 * {@see Onboarding::ROLES_RESPONSAVEL_SUGERIDO} é `['consultor', 'estrategista']`
 * NESTA ordem, então quem está lá hoje chegou majoritariamente pelo papel
 * `consultor`, que é o analista de Performance. Classificação errada é
 * corrigível pela tela, sem migration.
 *
 * PHP puro linha a linha, nunca `UPDATE <tabela> <alias> SET` — sintaxe que o
 * MariaDB aceita, o SQLite dos testes recusa, e que já derrubou esta suíte
 * (learnings §6). Em teste as tabelas nascem vazias e o laço não itera.
 *
 * `nullOnDelete` exige `nullable()` (erro 1830 em MariaDB — learnings §6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            // Nome de índice mais longo gerado:
            // `onboardings_responsavel_estrategista_id_foreign` = 47 chars,
            // abaixo do limite de 64 (learnings §6).
            $table->foreignId('responsavel_estrategista_id')
                ->nullable()
                ->after('responsavel_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->foreignId('responsavel_analista_id')
                ->nullable()
                ->after('responsavel_estrategista_id')
                ->constrained('users')
                ->nullOnDelete();
        });

        $this->backfill();
    }

    /**
     * Distribui o `responsavel_id` existente para o slot do papel certo.
     *
     * `responsavel_id` NÃO é alterado: o valor vai para um dos dois slots, o
     * que já satisfaz a invariante do responsável principal por construção.
     */
    private function backfill(): void
    {
        $onboardings = DB::table('onboardings')
            ->whereNotNull('responsavel_id')
            ->get(['id', 'company_id', 'responsavel_id']);

        foreach ($onboardings as $onboarding) {
            $papeis = DB::table('company_users')
                ->where('company_id', $onboarding->company_id)
                ->where('user_id', $onboarding->responsavel_id)
                ->pluck('role')
                ->all();

            $eEstrategista = in_array('estrategista', $papeis, true)
                && ! in_array('consultor', $papeis, true);

            $coluna = $eEstrategista
                ? 'responsavel_estrategista_id'
                : 'responsavel_analista_id';

            DB::table('onboardings')
                ->where('id', $onboarding->id)
                ->update([$coluna => $onboarding->responsavel_id]);
        }
    }

    public function down(): void
    {
        Schema::table('onboardings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('responsavel_estrategista_id');
            $table->dropConstrainedForeignId('responsavel_analista_id');
        });
    }
};
