<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Quick fix 2026-07-03 — operador criou o setor "Polos" na tabela `setores`
 * (id=7, slug=polos) via UI de admin. O enum `servicos.setor` só aceitava
 * 'performance' | 'publicacao' | 'outros', então o Servico #2 "Polos" tinha
 * ficado com setor='outros' (histórico).
 *
 * Consequência: a tela `/sistema/hubspot-line-items` mostrava "servico_setor:
 * outros" para os mappings HubSpot de Polo/Polo Pleno/Polo Pré-Pleno/Polo
 * Iniciante — visualmente confuso, embora o roteamento HubSpot→onboarding
 * polos NÃO dependa desse campo (usa `str_contains($nome, 'Polos')` em
 * `ComercialController::servicoDisparaImplementacao`).
 *
 * Este fix corrige a inconsistência de dados:
 *   1) Expande o enum para incluir 'polos'
 *   2) Atualiza Servico #2 de setor='outros' para setor='polos'
 *
 * Comportamento operacional NÃO muda — todos os leads Polos vindos do HubSpot
 * já criavam MlbEmpresa com projeto=POLOS corretamente. Fix é cosmético +
 * habilita usar Servico::SETOR_POLOS como filtro de primeira classe.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Guard: ALTER COLUMN ENUM eh MySQL-only. Em SQLite (usado nos testes)
        // o driver nao aceita MODIFY COLUMN + a coluna original tem CHECK
        // constraint que rejeita 'polos'. Skip inteiro em SQLite — testes
        // que precisam do servico Polos criam via factory diretamente.
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','polos','outros') NOT NULL DEFAULT 'outros'");

        // Atualiza o Servico #2 "Polos" que estava incorretamente como setor='outros'.
        // Idempotente: só afeta linhas onde setor='outros' AND nome LIKE '%Polo%'.
        DB::table('servicos')
            ->where('setor', 'outros')
            ->where('nome', 'LIKE', '%Polo%')
            ->update(['setor' => 'polos']);
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        // Desfaz o update primeiro (senão o rollback do enum viola a constraint).
        DB::table('servicos')->where('setor', 'polos')->update(['setor' => 'outros']);

        DB::statement("ALTER TABLE servicos MODIFY COLUMN setor ENUM('performance','publicacao','outros') NOT NULL DEFAULT 'outros'");
    }
};
