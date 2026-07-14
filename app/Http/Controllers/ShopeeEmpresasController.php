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
                // Contratos ATIVOS com serviço embedado — alimenta a coluna "Serviço"
                // e a resolução do servico_id Shopee por empresa (responsáveis por-serviço).
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            // SEM ->withCount(grants), SEM 'mlToken', SEM ->whereDoesntHave('mlbEmpresa').
            ->orderBy('name')
            ->get();

        // Phase 78 (DEC-78-1/2): responsáveis exibidos e a pendência sem_responsavel
        // são POR-SERVIÇO Shopee — NÃO os consolidados (senão empresa ML+Shopee com
        // analista ML mas sem analista Shopee não apareceria pendente). Resolve o
        // servico_id Shopee de cada empresa e carrega os responsáveis daquele serviço
        // em 1 query.
        $servicoShopeePorEmpresa = [];
        foreach ($companies as $c) {
            $ct = $c->contratosServico->first(
                fn($x) => $x->servico && $x->servico->setor === Servico::SETOR_SHOPEE
            );
            $servicoShopeePorEmpresa[$c->id] = $ct?->servico_id;
        }

        $respShopee = []; // [company_id][role] => ['id'=>..,'name'=>..]
        $rows = DB::table('company_users as cu')
            ->join('users as u', 'u.id', '=', 'cu.user_id')
            ->whereIn('cu.company_id', $companies->pluck('id'))
            ->whereNotNull('cu.servico_id')
            ->whereIn('cu.role', ['consultor', 'estrategista'])
            ->get(['cu.company_id', 'cu.role', 'cu.servico_id', 'u.id as user_id', 'u.name']);
        foreach ($rows as $r) {
            // Só o responsável cujo servico_id casa com o serviço Shopee daquela empresa.
            if (($servicoShopeePorEmpresa[$r->company_id] ?? null) === $r->servico_id) {
                $respShopee[$r->company_id][$r->role] = ['id' => $r->user_id, 'name' => $r->name];
            }
        }

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
            // Responsáveis POR-SERVIÇO Shopee (Analista = role consultor).
            'consultor'     => $respShopee[$c->id]['consultor'] ?? null,
            'estrategista'  => $respShopee[$c->id]['estrategista'] ?? null,
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
                // sem_responsavel POR-SERVIÇO Shopee (não consolidado).
                (empty($respShopee[$c->id]['consultor']) && empty($respShopee[$c->id]['estrategista'])) ? 'sem_responsavel' : null,
                // "sem_contato" = email_cliente vazio E digisac_group_contact_id vazio
                // (as duas condições do disparo mensal — NpsDispararMensal.php:146-162).
                (empty($c->email_cliente) && empty($c->digisac_group_contact_id)) ? 'sem_contato' : null,
                $c->empresa_nova ? 'empresa_nova' : null,
            ])),
        ]);

        // Phase 78 (DEC-78-1): selects ESCOPADOS ao Setor Shopee — lista só quem tem
        // o cargo analista/estrategista NO setor 'shopee' (não os cargos globais).
        // Fonte: setores(slug=shopee) → cargos(setor_id,slug) → user_setores(setor_id,cargo_id).
        $shopeeSetorId = DB::table('setores')->where('slug', 'shopee')->value('id');
        $usersPorCargoShopee = function (string $slug) use ($shopeeSetorId) {
            if (! $shopeeSetorId) {
                return collect(); // Setor Shopee ainda não criado (Phase 77) → lista vazia.
            }
            $cargoIds = DB::table('cargos')
                ->where('setor_id', $shopeeSetorId)
                ->where('slug', $slug)
                ->pluck('id');
            if ($cargoIds->isEmpty()) {
                return collect();
            }
            return User::where('active', true)
                ->whereIn('id', DB::table('user_setores')
                    ->where('setor_id', $shopeeSetorId)
                    ->whereIn('cargo_id', $cargoIds)
                    ->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->values();
        };

        $estrategistas = $usersPorCargoShopee('estrategista');
        $analistas     = $usersPorCargoShopee('analista');

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
            // Phase 76 (DEC-A3): escrita ESCOPADA por servico_id. Resolve o
            // servico_id do contrato Shopee ATIVO da empresa (o guard IDOR
            // :168-172 já garante que existe pelo menos um). Se por qualquer
            // razão não resolver, pula a empresa — nunca grava linha órfã.
            $servicoShopeeId = DB::table('contratos_servico as ct')
                ->join('servicos as s', 's.id', '=', 'ct.servico_id')
                ->where('ct.company_id', $c->id)
                ->where('ct.ativo', true)
                ->where('s.setor', Servico::SETOR_SHOPEE)
                ->value('ct.servico_id');

            if ($servicoShopeeId === null) {
                continue; // sem contrato shopee ativo → não gravar linha órfã
            }

            // Apaga SÓ o slot Shopee daquele papel (company_id, role, servico_id
            // shopee) — NUNCA por (company_id, role) apenas, para não tocar a
            // linha ML/consolidada do outro canal (Pattern 4 / T-76-02).
            DB::table('company_users')
                ->where('company_id', $c->id)
                ->where('role', $data['role'])
                ->where('servico_id', $servicoShopeeId)
                ->delete();

            // Grava o novo responsável já com o servico_id no array de pivot
            // (persiste a coluna independente de withPivot).
            $c->users()->attach($data['user_id'], [
                'role'        => $data['role'],
                'servico_id'  => $servicoShopeeId,
                'assigned_at' => now()->toDateString(),
            ]);
        }

        $label = $data['role'] === 'consultor' ? 'Analista' : 'Estrategista';
        return back()->with('success', count($data['ids']) . " empresa(s) — {$label} atribuído.");
    }

    /**
     * Resolver pendência (DEC-78-2): atribui Analista/Estrategista Shopee (por
     * servico_id) e atualiza o contato (email do cliente) de UMA empresa. Gated
     * por permission:shopee.empresas + guard de escopo (empresa fora do escopo
     * Shopee → validação falha). Responsáveis são opcionais (parcial resolve
     * parte da pendência).
     */
    public function resolver(Request $request)
    {
        $data = $request->validate([
            'company_id'      => ['required', 'integer', $this->guardEscopoShopee()],
            'analista_id'     => 'nullable|integer|exists:users,id',
            'estrategista_id' => 'nullable|integer|exists:users,id',
            'email_cliente'   => 'nullable|email',
        ]);

        $company         = Company::findOrFail($data['company_id']);
        $servicoShopeeId = $this->servicoShopeeAtivoId($company->id);

        if ($servicoShopeeId !== null) {
            if (! empty($data['analista_id'])) {
                $this->gravarResponsavelShopee($company, 'consultor', (int) $data['analista_id'], $servicoShopeeId);
            }
            if (! empty($data['estrategista_id'])) {
                $this->gravarResponsavelShopee($company, 'estrategista', (int) $data['estrategista_id'], $servicoShopeeId);
            }
        }

        if (! empty($data['email_cliente'])) {
            $company->update(['email_cliente' => $data['email_cliente']]);
        }

        return back()->with('success', 'Pendência da empresa atualizada.');
    }

    /**
     * Excluir na aba Shopee = CANCELAR só o contrato de serviço Shopee (DEC-78-4):
     * `ativo=false` apenas no(s) contrato(s) de setor 'shopee' da empresa. NÃO
     * deleta a empresa nem toca o contrato ML — a empresa some da aba Shopee mas
     * continua existindo (e na aba ML, se tiver). Gated shopee.empresas + escopo.
     */
    public function cancelarServico(Request $request)
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', $this->guardEscopoShopee()],
        ]);

        // Resolve os IDs dos contratos shopee ATIVOS e desativa por id (evita
        // UPDATE com JOIN, que é driver-específico).
        $contratoIds = DB::table('contratos_servico as ct')
            ->join('servicos as s', 's.id', '=', 'ct.servico_id')
            ->where('ct.company_id', $data['company_id'])
            ->where('ct.ativo', true)
            ->where('s.setor', Servico::SETOR_SHOPEE)
            ->pluck('ct.id');

        if ($contratoIds->isNotEmpty()) {
            DB::table('contratos_servico')
                ->whereIn('id', $contratoIds)
                ->update(['ativo' => false, 'updated_at' => now()]);
        }

        return back()->with('success', 'Serviço Shopee cancelado (a empresa permanece).');
    }

    /**
     * Guard de escopo Shopee reusável para validação de `company_id` (anti-IDOR):
     * o id só passa se a empresa estiver no builder base da aba Shopee.
     */
    private function guardEscopoShopee(): callable
    {
        return function ($attribute, $value, $fail) {
            if (! $this->empresasShopeeBaseQuery()->whereKey($value)->exists()) {
                $fail('Empresa fora do escopo Shopee — ação não permitida.');
            }
        };
    }

    /**
     * servico_id do contrato Shopee ATIVO da empresa (ou null).
     */
    private function servicoShopeeAtivoId(int $companyId): ?int
    {
        $id = DB::table('contratos_servico as ct')
            ->join('servicos as s', 's.id', '=', 'ct.servico_id')
            ->where('ct.company_id', $companyId)
            ->where('ct.ativo', true)
            ->where('s.setor', Servico::SETOR_SHOPEE)
            ->value('ct.servico_id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Grava o responsável POR-SERVIÇO Shopee: apaga só o slot
     * (company_id, role, servico_id shopee) e re-atribui — nunca toca a linha ML
     * (Phase 76 / DEC-A3).
     */
    private function gravarResponsavelShopee(Company $company, string $role, int $userId, int $servicoShopeeId): void
    {
        DB::table('company_users')
            ->where('company_id', $company->id)
            ->where('role', $role)
            ->where('servico_id', $servicoShopeeId)
            ->delete();

        $company->users()->attach($userId, [
            'role'        => $role,
            'servico_id'  => $servicoShopeeId,
            'assigned_at' => now()->toDateString(),
        ]);
    }
}
