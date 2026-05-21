<?php

namespace App\Http\Middleware;

use App\Models\Sugador;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                'user'        => $user ? $this->buildUserPayload($user) : null,
                'permissions' => $user ? $user->effectivePermissions() : [],
                'setores'     => $user ? $this->buildSetoresPayload($user) : [],
                'lideranca'   => $user ? $user->setoresLiderados()->get(['setores.id', 'nome', 'slug'])->all() : [],
            ],
            'flash' => [
                'success'       => $request->session()->get('success'),
                'error'         => $request->session()->get('error'),
                'nps_link'      => $request->session()->get('nps_link'),
                'workspace_url' => $request->session()->get('workspace_url'),
            ],
            'asset_url'  => rtrim(asset(''), '/'),
            'csrf_token' => csrf_token(),
            'sugadores_pendentes' => fn() => $this->countSugadoresPendentes($request),
            // Contador de notifications não lidas — closure garante recálculo em toda navegação Inertia (POLL-01 + POLL-03).
            'notificacoes_nao_lidas' => fn() => $request->user()?->unreadNotifications()->count() ?? 0,
        ];
    }

    /**
     * Payload básico do user — quem usa esses campos no frontend continua
     * funcionando (Dashboard, header, etc).
     */
    private function buildUserPayload(\App\Models\User $user): array
    {
        $principal = $user->setorPrincipal();

        return [
            'id'    => $user->id,
            'name'  => $user->name,
            'email' => $user->email,
            'role'  => $user->role,
            'is_admin'  => $user->isAdmin(),
            'is_lider'  => $user->isLider(),
            // Snapshot do setor principal pra display no perfil/header
            'setor_principal' => $principal ? [
                'id'    => $principal->id,
                'nome'  => $principal->nome,
                'slug'  => $principal->slug,
                'cargo' => $principal->pivot->cargo_id
                    ? \App\Models\Cargo::find($principal->pivot->cargo_id)?->nome
                    : null,
            ] : null,
        ];
    }

    /**
     * Todos os setores em que o user é membro (com nome do cargo já resolvido).
     *
     * @return array<int, array{id:int,nome:string,slug:string,cargo:?string,is_principal:bool}>
     */
    private function buildSetoresPayload(\App\Models\User $user): array
    {
        return $user->setores()
            ->with('cargos:id,setor_id,nome,slug')
            ->get(['setores.id', 'nome', 'slug'])
            ->map(function ($setor) {
                $cargoId = $setor->pivot->cargo_id;
                $cargo = $cargoId ? $setor->cargos->firstWhere('id', $cargoId) : null;
                return [
                    'id'           => $setor->id,
                    'nome'         => $setor->nome,
                    'slug'         => $setor->slug,
                    'cargo'        => $cargo?->nome,
                    'cargo_slug'   => $cargo?->slug,
                    'is_principal' => (bool) $setor->pivot->is_principal,
                ];
            })
            ->all();
    }

    /**
     * Conta sugadores pendentes visíveis para o usuário atual.
     * Lazy (só executa quando o componente lê a prop, evitando query em rotas que não usam).
     */
    private function countSugadoresPendentes(Request $request): int
    {
        $user = $request->user();
        if (!$user) return 0;

        if (!$user->hasPermission(Permissions::CORE_SUGADORES)
            && !$user->hasPermission(Permissions::CORE_SUGADORES_GLOBAL)) {
            return 0;
        }

        $hasGlobalView = $user->isAdmin() || $user->hasPermission(Permissions::CORE_SUGADORES_GLOBAL);

        $query = Sugador::pendentes();
        if (!$hasGlobalView) {
            $query->daCarteira($user);
        }
        return $query->count();
    }
}
