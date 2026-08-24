<?php

namespace App\Http\Middleware;

use App\Models\Sugador;
use App\Services\EcfDriveService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
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
                // MVP Cargo Dev — visibilidade de módulos no menu.
                // `is_admin_dev`: o cargo Dev (users.is_dev, Fase 97) vê TUDO no menu.
                // `modulos_ocultos`: route_prefixes marcados como ocultos (só Dev vê) —
                // consumido por AppLayout::itemVisivel(). Lista cacheada (ModuleRegistry).
                'is_admin_dev'    => $user ? $user->isAdminDev() : false,
                'modulos_ocultos' => $user ? app(\App\Services\ModuleRegistry::class)->hiddenRoutes() : [],
            ],
            'flash' => [
                'success'       => $request->session()->get('success'),
                'error'         => $request->session()->get('error'),
                'nps_link'      => $request->session()->get('nps_link'),
                // 2026-08-20 — o guard de duplicidade (individual em
                // `NpsController::generate()`, grupo em
                // `NpsGrupoController::generate()`) devolve o link que JÁ
                // existe nesta chave, e a tela já sabe abrir o modal com ele
                // (`Pages/Nps/Index.jsx`, efeito de `flash.nps_link_existente`).
                // A chave nunca foi compartilhada aqui: o operador via só o
                // aviso "este grupo já tem um link deste modelo neste mês" e
                // NENHUM link — sem como copiar o que já existia nem como
                // gerar outro (o guard barra). Bug reportado no grupo MaxiGold.
                'nps_link_existente' => $request->session()->get('nps_link_existente'),
                'workspace_url' => $request->session()->get('workspace_url'),
                // 2026-08-24 — o login do Portal do Cliente. `portal_codigo_enviado`
                // é o que faz a tela avançar do campo de e-mail para o de código;
                // `portal_email` reexibe o endereço para quem esqueceu qual digitou.
                //
                // Sem estas duas linhas o fluxo QUEBRA em silêncio: o código é
                // gerado e enviado, o servidor responde 302, e a tela volta ao
                // começo como se nada tivesse acontecido. Foi exatamente o que
                // aconteceu no primeiro teste — e é o mesmo defeito de
                // `nps_link_existente`, logo acima.
                'portal_codigo_enviado' => $request->session()->get('portal_codigo_enviado'),
                'portal_email'          => $request->session()->get('portal_email'),
                // Fase 131 Plano 131-05 (CLICK-07) — canal neutro/âmbar para
                // resposta ESPERADA que não é sucesso nem erro (o 429 do
                // reenvio de aviso da Clicksign).
                'aviso'         => $request->session()->get('aviso'),
            ],
            'asset_url'  => rtrim(asset(''), '/'),
            'csrf_token' => csrf_token(),
            'sugadores_pendentes' => fn() => $this->countSugadoresPendentes($request),
            // Contador de notifications não lidas — closure garante recálculo em toda navegação Inertia (POLL-01 + POLL-03).
            'notificacoes_nao_lidas' => fn() => $request->user()?->unreadNotifications()->count() ?? 0,
            // Phase 23 — Contador de alertas críticos não-ackeados para badge da sidebar.
            // Lazy closure + cache 5min + try/catch retorna null em erro (falha silenciosa).
            'alertas_criticos_count' => fn() => $this->countAlertasCriticos(),
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

    /**
     * Conta signals com severity='critical' e acked=false via ECF Drive.
     *
     * Cache: 5min (chave global compartilhada entre todos os usuários — o número
     * é o mesmo para qualquer um que tenha acesso à aba). Falha silenciosa: em
     * qualquer erro retorna null e o badge da sidebar simplesmente some.
     *
     * Phase 23 — D-05 do PLAN.
     */
    private function countAlertasCriticos(): ?int
    {
        try {
            return Cache::remember('alertas.criticos_nao_ackeados.count', 300, function () {
                $ecf = app(EcfDriveService::class);
                $res = $ecf->listSignals([
                    'severity' => 'critical',
                    'acked'    => false,
                    'limit'    => 1,
                ]);
                return (int) ($res['total'] ?? 0);
            });
        } catch (\Throwable) {
            // Falha silenciosa — badge desaparece da sidebar
            return null;
        }
    }
}
