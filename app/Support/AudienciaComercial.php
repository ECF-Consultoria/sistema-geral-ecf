<?php

namespace App\Support;

use App\Models\Setor;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Phase 35 Plan 35-03 (D-06) — Helper de resolução da audiência Comercial.
 *
 * União de 2 fontes distintas (id-distinct):
 *   (a) Líderes do setor Comercial — registrados no pivot `setor_lideres` para
 *       `setores.slug = 'comercial'`. Reflete o conceito real do MVP: liderança
 *       é uma atribuição via `Setor::lideres()`, não um cargo. (Não existe
 *       cargo 'lider-comercial' no seed atual — ver migration
 *       2026_05_25_100003_seed_setor_comercial_and_retro_migrate.php.)
 *
 *   (b) Users com a permission `comercial.cadastrar_empresa` (canônica em
 *       `App\Support\Permissions::COMERCIAL_CADASTRAR_EMPRESA`). Como o setor
 *       Comercial tem essa permission via `setor_permissoes`, todos os membros
 *       do setor caem nesta busca — robustez extra caso a permission seja
 *       concedida via outro setor no futuro. Admins entram pelo short-circuit
 *       de `User::hasPermission` (linha 106 do User.php).
 *
 * Reutilizável fora do contexto HTTP (sem dependência de auth/request) — só
 * queries Eloquent puras. Pode ser chamado de Job, Command ou Controller.
 *
 * Performance: o filtro `effectivePermissions` em (b) é cacheado por instância
 * de User em uma única request — o caller paga 1 SELECT por user. Pra ~50 users
 * ativos isso é ~50ms, aceitável para um disparo sincrono de webhook. Se a
 * audiência crescer >500, considerar tornar a query (b) em JOIN direto com
 * `setor_permissoes`.
 */
class AudienciaComercial
{
    /**
     * Retorna users ativos que devem ser notificados em eventos do Comercial.
     *
     * @return Collection<int, User>
     */
    public static function lideresEPermissionados(): Collection
    {
        // (a) Líderes do setor Comercial (via pivot setor_lideres).
        $lideres = collect();
        $setorComercial = Setor::where('slug', 'comercial')->first();
        if ($setorComercial) {
            $lideres = $setorComercial->lideres()
                ->where('active', true)
                ->get();
        }

        // (b) Users ativos com permission canônica (cobre membros do setor
        //     Comercial + admins via short-circuit + qualquer setor futuro que
        //     conceda a mesma permission).
        $permissionados = User::query()
            ->where('active', true)
            ->get()
            ->filter(fn (User $u) => $u->hasPermission(Permissions::COMERCIAL_CADASTRAR_EMPRESA));

        // União distinct por id, preserva ordem (líderes primeiro).
        return $lideres
            ->concat($permissionados)
            ->unique('id')
            ->values();
    }
}
