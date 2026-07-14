<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Servico;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Aba "Empresas" da Shopee — Phase 75 Plan 75-04 (DEC-2 / DEC-4).
 *
 * Espelha CompanyController@index numa versão ENXUTA, deliberadamente ML-free:
 * o gatilho é o contrato de serviço de setor 'shopee' (Servico::SETOR_SHOPEE),
 * NÃO há injeção de AdmanService/MetricsProviderFactory/EcfDriveService, e o
 * payload não carrega cust_id/adman/ml/token/grants — empresas atendidas só na
 * Shopee ainda não têm API/métrica. As únicas ações são atribuir responsável
 * (pivot company_users) e gerar NPS (motor existente, ver NpsController).
 *
 * RBAC dedicado: todas as rotas ficam sob permission:shopee.empresas — nunca
 * core.empresas (DEC-4 / T-75-09 Elevation of Privilege).
 */
class ShopeeEmpresasController extends Controller
{
    // Construtor VAZIO por design (contraste com CompanyController :23-27, que
    // injeta AdmanService/EcfDriveService/MetricsProviderFactory). A aba Shopee
    // é ML-free — injetar essas deps despacharia jobs/HTTP inúteis.

    /**
     * Builder base das empresas Shopee — contrato ATIVO de setor 'shopee'.
     *
     * Reusado tanto pela listagem (index) quanto pelo guard de escopo do
     * bulkAssign (W1/T-75-10), garantindo que "estar na aba Shopee" e "poder
     * ser atribuída via bulk-assign" compartilham exatamente o mesmo critério.
     * SEM whereDoesntHave('mlbEmpresa'): empresa multi-marketplace (contrato ML
     * E Shopee) aparece nas DUAS abas — comportamento correto (DEC-4).
     */
    private function empresasShopeeBaseQuery(): Builder
    {
        return Company::whereHas('contratosServico', fn($q) =>
            $q->where('contratos_servico.ativo', true)
              ->whereHas('servico', fn($qs) =>
                  $qs->where('setor', Servico::SETOR_SHOPEE)
              )
        );
    }

    /**
     * Listagem enxuta das empresas Shopee (aba "Empresas").
     *
     * Payload sem qualquer chave de métrica/cust_id/grant; pendências mínimas
     * voltadas ao NPS (DEC-2). Estrategistas/analistas por cargo alimentam os
     * selects de atribuição.
     */
    public function index(Request $request)
    {
        $companies = $this->empresasShopeeBaseQuery()
            ->with([
                'consultor',
                'estrategista',
                // Contratos ATIVOS com serviço embedado — alimenta a coluna "Serviço".
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            // SEM ->withCount(grants), SEM 'mlToken', SEM ->whereDoesntHave('mlbEmpresa').
            ->orderBy('name')
            ->get();

        $companies = $companies->map(fn($c) => [
            'id'            => $c->id,
            'name'          => $c->name,
            'cnpj'          => $c->cnpj,
            'segment'       => $c->segment,
            'active'        => $c->active,
            'status'        => $c->status,
            'notes'         => $c->notes,
            // Contato do cliente (usado pelo NPS + preenche o modal de edição).
            'email_cliente' => $c->email_cliente,
            'telefone'      => $c->telefone,
            // Tag "Empresa nova" (D-06) — badge na linha.
            'empresa_nova'  => (bool) $c->empresa_nova,
            'consultor'     => $c->consultor->first()?->only(['id', 'name']),
            'estrategista'  => $c->estrategista->first()?->only(['id', 'name']),
            // Contratos ativos: payload mínimo para a coluna Serviço (badges + tooltip).
            'contratos_servico' => $c->contratosServico->map(fn($ct) => [
                'id'               => $ct->id,
                'valor_contratado' => (float) $ct->valor_contratado,
                'data_contratacao' => optional($ct->data_contratacao)->toDateString(),
                'data_vencimento'  => optional($ct->data_vencimento)?->toDateString(),
                'servico'          => $ct->servico ? [
                    'id'            => $ct->servico->id,
                    'nome'          => $ct->servico->nome,
                    'tipo_cobranca' => $ct->servico->tipo_cobranca,
                ] : null,
            ])->values(),
            // Grupo nomeado (tipo carteira) — null se a empresa não está em nenhum.
            'grupo'            => $c->grupo ? [
                'id'    => $c->grupo->id,
                'name'  => $c->grupo->name,
                'color' => $c->grupo->color,
            ] : null,
            'company_group_id' => $c->company_group_id,
            // Pendências mínimas pro NPS (DEC-2) — sem sem_cust_id/sem_grant_ativo
            // (não se aplicam à Shopee) nem qualquer pendência de métrica.
            'pendencias'       => array_values(array_filter([
                ($c->consultor->isEmpty() && $c->estrategista->isEmpty()) ? 'sem_responsavel' : null,
                // "sem_contato" = email_cliente vazio E digisac_group_contact_id vazio
                // (as duas condições do disparo mensal — NpsDispararMensal.php:146-162).
                (empty($c->email_cliente) && empty($c->digisac_group_contact_id)) ? 'sem_contato' : null,
                $c->empresa_nova ? 'empresa_nova' : null,
            ])),
        ]);

        // Users por CARGO no pivot user_setores. Helper local: pluck dos ids do
        // cargo por slug (há slugs duplicados em prod, ex: 2x "analista" — por isso
        // pluck/whereIn em vez de value('id'), que pegaria só um e perderia users).
        $usersPorCargo = function (string $slug) {
            $cargoIds = \App\Models\Cargo::where('slug', $slug)->pluck('id');
            if ($cargoIds->isEmpty()) {
                return collect();
            }
            return User::where('active', true)
                ->whereIn('id', DB::table('user_setores')->whereIn('cargo_id', $cargoIds)->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values();
        };

        $estrategistas = $usersPorCargo('estrategista');
        $analistas     = $usersPorCargo('analista');

        // Grupos nomeados (tipo carteira) — reaproveitados pelo modal de atribuição.
        $grupos = \App\Models\CompanyGroup::withCount('companies')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('Shopee/Empresas', [
            'companies'     => $companies,
            'estrategistas' => $estrategistas,
            'analistas'     => $analistas,
            'grupos'        => $grupos,
        ]);
    }

    /**
     * Atribuição em massa de Analista (role=consultor) ou Estrategista a várias
     * empresas Shopee. Substitui apenas o papel informado, preservando o outro.
     *
     * Guard de escopo (W1/T-75-10 — IDOR/Tampering): cada `ids.*` só é aceito se
     * a empresa tiver contrato ativo de setor 'shopee' (mesmo builder do index).
     * ID de empresa fora do escopo (ex.: ML-only) é rejeitado com 422 mesmo que
     * o usuário tenha a key shopee.empresas — fecha o IDOR sem reabrir
     * core.empresas. Semântica fail-closed: qualquer ID fora do escopo derruba
     * o request inteiro (validação → nada é sincronizado).
     */
    public function bulkAssign(Request $request)
    {
        $data = $request->validate([
            'ids'     => 'required|array|min:1',
            'ids.*'   => [
                'integer',
                Rule::exists('companies', 'id'),
                // Guard de escopo Shopee: reusa o builder do index para consistência.
                function ($attribute, $value, $fail) {
                    if (! $this->empresasShopeeBaseQuery()->whereKey($value)->exists()) {
                        $fail('Empresa fora do escopo Shopee — atribuição não permitida.');
                    }
                },
            ],
            'role'    => 'required|in:consultor,estrategista',
            'user_id' => 'required|integer|exists:users,id',
        ]);

        foreach (Company::whereIn('id', $data['ids'])->get() as $c) {
            // Remove só o papel alvo (mantém o outro) e atribui o novo responsável.
            DB::table('company_users')->where('company_id', $c->id)->where('role', $data['role'])->delete();
            $c->users()->attach($data['user_id'], ['role' => $data['role'], 'assigned_at' => now()->toDateString()]);
        }

        $label = $data['role'] === 'consultor' ? 'Analista' : 'Estrategista';
        return back()->with('success', count($data['ids']) . " empresa(s) — {$label} atribuído.");
    }
}
