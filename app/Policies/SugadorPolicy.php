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
     *
     * 2026-05-22: relaxado de admin-only para admin + gestor + lider (publication)
     * — o time operacional precisava acessar a config dos Sugadores sem depender
     * de admin abrir, e a aba "Empresas" não é visível a quem não é admin.
     * Analistas comuns continuam fora (config é macro, e eles só veem a carteira).
     */
    public function manage(User $user): bool
    {
        if ($user->isAdmin()) return true;

        // hasGlobalView replica isGestor / isLiderPub (publication roles com
        // visão global). Reaproveita o helper privado pra manter coerência.
        return $this->hasGlobalView($user);
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
