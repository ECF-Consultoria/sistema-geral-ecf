<?php

use App\Models\Servico;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 37 / Plan 37-01 (REQ-37-03) — atualiza setor dos servicos canônicos.
 *
 * Pares (LIKE → setor) aplicados:
 *  - '%Gestão%'    → 'performance'
 *  - '%Mentoria%'  → 'performance'  (silencioso se nome não existir no catálogo)
 *  - '%Publicação%' → 'publicacao'
 *
 * Demais nomes do catálogo (Polos, Assessoria, Incubadora, Publicidade)
 * permanecem 'outros' (default da migration 2026_06_18_100001).
 *
 * Idempotente: re-rodar não altera dados já corretos (UPDATE com o mesmo
 * valor é no-op de efeito; LIKE case-sensitive Title Case alinhado com
 * D-02 da Phase 14 — catálogo já está em Title Case).
 *
 * Threat T-37-01 (Tampering): LIKE específico Title Case + dados internos
 * (catálogo de serviços, não payload de usuário) → risco aceito (accept).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            // Performance — Gestão + Mentoria (Mentoria pode não existir no
            // catálogo Phase 14; UPDATE simplesmente afeta 0 rows e segue.)
            DB::table('servicos')
                ->where('nome', 'LIKE', '%Gestão%')
                ->update(['setor' => 'performance']);

            DB::table('servicos')
                ->where('nome', 'LIKE', '%Mentoria%')
                ->update(['setor' => 'performance']);

            // Publicação — bate apenas o nome canônico 'Publicação'
            // (Title Case, com cedilha + til; D-02 Phase 14).
            DB::table('servicos')
                ->where('nome', 'LIKE', '%Publicação%')
                ->update(['setor' => 'publicacao']);
        });
    }

    public function down(): void
    {
        // Reverter para o default da coluna ('outros') em todos os rows.
        // Em prod, isso reseta a categorização mas não destrói dados (a
        // categorização pode ser re-rodada via re-run da up()).
        DB::table('servicos')->update(['setor' => 'outros']);
    }
};
