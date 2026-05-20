<?php

namespace App\Policies;

use App\Models\Sugador;
use App\Models\User;
use App\Support\Permissions;

/**
 * Regras de acesso ao módulo Sugadores (§9 do MODULO_SUGADORES.md).
 *
 * | Quem                          | Lê        | Muda status | Configura |
 * |-------------------------------|-----------|-------------|-----------|
 * | admin                         | Todos     | Todos       | ✓         |
 * | gestor / lider (publication)  | Todos     | Todos       | ✗         |
 * | analista (publication)        | Carteira  | Carteira    | ✗         |
 * | consultor / mentor (sistema)  | Carteira  | Carteira    | ✗         |
 * | publicador (publication)      | —         | —           | —         |
 *
 * Auto-descoberta pelo Laravel: App\Models\Sugador → App\Policies\SugadorPolicy.
 */
class SugadorPolicy
{
    /**
     * Acesso à listagem (`/sugadores`).
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission(Permissions::CORE_SUGADORES)
            || $user->hasPermission(Permissions::CORE_SUGADORES_GLOBAL);
    }

    /**
     * Visualizar um sugador específico.
     * Quem tem visão "global" (admin/gestor/lider) vê tudo.
     * Demais (consultor/mentor/analista) só veem da própria carteira.
     */
    public function view(User $user, Sugador $sugador): bool
    {
        if (!$this->viewAny($user)) return false;

        if ($this->hasGlobalView($user)) return true;

        return $this->isInCarteira($user, $sugador->company_id);
    }

    /**
     * Mudar status (em_acao / resolvido / ignorado / voltar a pendente).
     * Mesmas regras de view.
     */
    public function update(User $user, Sugador $sugador): bool
    {
        return $this->view($user, $sugador);
    }

    /**
     * Ações administrativas (configurar thresholds por empresa).
     * Apenas admin.
     */
    public function manage(User $user): bool
    {
        return $user->isAdmin();
    }

    /**
     * Disparar análise on-demand (botão "Rodar análise" no Index).
     * Permitido a admin e a quem tem qualquer permissão de Sugadores.
     */
    public function analyze(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission(Permissions::CORE_SUGADORES);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * "Visão global" = vê sugadores de todas empresas, não só da carteira.
     * Concedido a setores com a permissão CORE_SUGADORES_GLOBAL.
     */
    private function hasGlobalView(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission(Permissions::CORE_SUGADORES_GLOBAL);
    }

    private function isInCarteira(User $user, int $companyId): bool
    {
        return $user->companies()->where('companies.id', $companyId)->exists();
    }
}
