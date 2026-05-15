<?php

namespace App\Http\Middleware;

use App\Models\Sugador;
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
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id'               => $request->user()->id,
                    'name'             => $request->user()->name,
                    'email'            => $request->user()->email,
                    'role'             => $request->user()->role,
                    'setor'            => $request->user()->setor,
                    'publication_role'        => $request->user()->publication_role,
                    'publication_permissions' => $request->user()->publication_permissions,
                ] : null,
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
        ];
    }

    /**
     * Conta sugadores pendentes visíveis para o usuário atual.
     * Lazy (só executa quando o componente lê a prop, evitando query em rotas que não usam).
     */
    private function countSugadoresPendentes(Request $request): int
    {
        $user = $request->user();
        if (!$user) return 0;

        // Quem não tem permissão de ver sugadores não recebe contador
        $hasGlobalView = $user->isAdmin()
            || (method_exists($user, 'isGestor') && $user->isGestor())
            || (method_exists($user, 'isLiderPub') && $user->isLiderPub());
        $hasCarteiraView = $user->isConsultor()
            || $user->isMentor()
            || (method_exists($user, 'hasPubPermission') && $user->hasPubPermission('sugadores'));

        if (!$hasGlobalView && !$hasCarteiraView) return 0;

        $query = Sugador::pendentes();
        if (!$hasGlobalView) {
            $query->daCarteira($user);
        }
        return $query->count();
    }
}
