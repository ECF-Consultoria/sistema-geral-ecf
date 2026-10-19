<?php

namespace App\Services\Portal;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\PortalUsuario;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * AcessosDoPortalService — os dados da tela "Acessos do Portal do Cliente".
 *
 * A tela vive na aba "Portal do cliente" de `/companies` (19/10/2026). Antes
 * era uma sub-aba do Onboarding (25/08/2026) e, antes disso, uma página própria
 * em `/acessos-portal` sem link em lugar nenhum. Saiu do Onboarding quando o
 * portal passou a servir sobretudo ao Mapeamento Estrutural: entrar no portal
 * e dar login ao cliente deixaram de ser assunto só de quem está entrando.
 *
 * O serviço existe para que o `CompanyController` — que já é grande — não
 * ganhe as consultas do portal no meio dele.
 */
class AcessosDoPortalService
{
    /**
     * Os logins de cliente de cada empresa — a coluna da aba "Portal do
     * cliente".
     *
     * Conta só login ATIVO: desativado não entra, e contá-lo faria a linha
     * dizer que o cliente tem acesso quando não tem.
     *
     * `ultimo_login` é o último login de QUALQUER dessas pessoas, e login é da
     * pessoa, não da empresa: quem responde por um grupo inteiro carimba a
     * mesma data em todas as empresas dele. Por isso a tela diz "último login",
     * e não "último acesso à empresa" — esse registro não existe.
     *
     * Uma consulta só, agrupada — a aba lista todas as empresas da página.
     *
     * @param  array<int>  $empresaIds
     * @return array<int, array{logins: int, nunca_entrou: int, ultimo_login: ?string}>
     */
    public function loginsPorEmpresa(array $empresaIds): array
    {
        if ($empresaIds === []) {
            return [];
        }

        return DB::table('portal_usuario_empresa as pue')
            ->join('portal_usuarios as pu', 'pu.id', '=', 'pue.portal_usuario_id')
            ->whereIn('pue.company_id', $empresaIds)
            ->where('pu.ativo', true)
            ->groupBy('pue.company_id')
            ->selectRaw('pue.company_id, COUNT(*) as logins')
            ->selectRaw('SUM(CASE WHEN pu.primeiro_acesso_em IS NULL THEN 1 ELSE 0 END) as nunca_entrou')
            ->selectRaw('MAX(pu.ultimo_acesso_em) as ultimo_login')
            ->get()
            ->mapWithKeys(fn ($l) => [(int) $l->company_id => [
                'logins'       => (int) $l->logins,
                'nunca_entrou' => (int) $l->nunca_entrou,
                'ultimo_login' => $l->ultimo_login
                    ? Carbon::parse($l->ultimo_login)->format('d/m/Y H:i')
                    : null,
            ]])
            ->all();
    }

    /**
     * A lista da sub-aba "Acessos do portal" — só admin, e só sob demanda.
     *
     * @return array{usuarios: \Illuminate\Support\Collection, empresas: \Illuminate\Support\Collection, grupos: \Illuminate\Support\Collection}
     */
    public function dados(): array
    {
        return [
            'usuarios' => $this->usuarios(),
            'empresas' => $this->empresas(),
            'grupos'   => $this->grupos(),
            'login_url' => \App\Support\Portal\UrlDoPortal::para('portal.entrada'),
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
            ->get(['id', 'name', 'company_group_id', 'nome_contato', 'email_cliente', 'telefone', 'cargo_contato'])
            ->map(fn (Company $e) => [
                'id'         => $e->id,
                'nome'       => $e->name,
                'grupo_id'   => $e->company_group_id,
                'grupo_nome' => $e->grupo?->name,
                // Só o contato do cliente; nunca o e-mail do colaborador ECF.
                // São sugestões para revisão, não uma concessão automática.
                'contato' => [
                    'nome' => $e->nome_contato ?? '',
                    'email' => filter_var(trim($e->email_cliente ?? ''), FILTER_VALIDATE_EMAIL)
                        ? mb_strtolower(trim($e->email_cliente)) : '',
                    'telefone' => $e->telefone ?? '',
                    'cargo' => $e->cargo_contato ?? '',
                ],
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
