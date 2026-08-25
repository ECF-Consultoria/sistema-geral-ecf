<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\PortalUsuario;

/**
 * AcessosDoPortalService — os dados da tela "Acessos do Portal do Cliente".
 *
 * A tela vive DENTRO da aba Onboarding de `/companies` (25/08/2026). Antes era
 * uma página própria em `/acessos-portal`, sem link em lugar nenhum: só quem
 * sabia a URL chegava lá. Dar acesso ao portal é parte de colocar o cliente
 * para dentro — o lugar dessa operação é junto do onboarding, não numa tela
 * órfã.
 *
 * O serviço existe para que o `CompanyController` — que já é grande — não
 * ganhe as três consultas do portal no meio dele.
 */
class AcessosDoPortalService
{
    /**
     * @return array{usuarios: \Illuminate\Support\Collection, empresas: \Illuminate\Support\Collection, grupos: \Illuminate\Support\Collection}
     */
    public function dados(): array
    {
        return [
            'usuarios' => $this->usuarios(),
            'empresas' => $this->empresas(),
            'grupos'   => $this->grupos(),
        ];
    }

    /**
     * Quantos acessos ATIVOS existem — o número do rótulo da sub-aba.
     *
     * Fica separado de {@see dados()} de propósito: o rótulo é montado em toda
     * abertura de `/companies`, e a lista só quando alguém abre a sub-aba.
     * Trazer a lista inteira para escrever um número seria pagar caro por ele.
     */
    public function totalAtivos(): int
    {
        return PortalUsuario::where('ativo', true)->count();
    }

    private function usuarios()
    {
        return PortalUsuario::with(['empresas:id,name', 'convidadoPor:id,name'])
            ->orderBy('nome')
            ->get()
            ->map(fn (PortalUsuario $u) => [
                'id'                 => $u->id,
                'nome'               => $u->nome,
                'email'              => $u->email,
                'telefone'           => $u->telefone,
                'cargo'              => $u->cargo,
                'ativo'              => $u->ativo,
                'empresas'           => $u->empresas->map(fn ($e) => ['id' => $e->id, 'nome' => $e->name])->values(),
                'convidado_por'      => $u->convidadoPor?->name,
                'convidado_em'       => $u->convidado_em?->format('d/m/Y'),
                // O que separa "não entrou ainda" de "não entra desde então".
                'primeiro_acesso_em' => $u->primeiro_acesso_em?->format('d/m/Y H:i'),
                'ultimo_acesso_em'   => $u->ultimo_acesso_em?->format('d/m/Y H:i'),
                'nunca_entrou'       => $u->primeiro_acesso_em === null,
            ]);
    }

    /**
     * As empresas chegam com o grupo a que pertencem — ver {@see grupos()}.
     */
    private function empresas()
    {
        return Company::where('active', true)
            ->with('grupo:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'company_group_id'])
            ->map(fn (Company $e) => [
                'id'         => $e->id,
                'nome'       => $e->name,
                'grupo_id'   => $e->company_group_id,
                'grupo_nome' => $e->grupo?->name,
            ]);
    }

    /**
     * Os grupos vêm como ALVO próprio: dar acesso a alguém do Camillo Parts
     * costuma significar as 7 empresas do grupo, não uma. Sem isso o operador
     * repetiria a mesma operação sete vezes — e esqueceria a sétima.
     *
     * `whereHas`, não `having`: `withCount` gera SUBQUERY, não agregado, e um
     * HAVING sobre ela quebra ("HAVING clause on a non-aggregate query"). Grupo
     * sem empresa ativa fica de fora porque seria um alvo vazio no seletor.
     */
    private function grupos()
    {
        return CompanyGroup::withCount(['companies' => fn ($q) => $q->where('active', true)])
            ->whereHas('companies', fn ($q) => $q->where('active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (CompanyGroup $g) => [
                'id'       => $g->id,
                'nome'     => $g->name,
                'empresas' => $g->companies_count,
            ]);
    }
}
