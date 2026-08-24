<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\CompanyGroup;
use App\Models\PortalUsuario;
use App\Services\Portal\PortalAuditoria;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * PortalUsuarioController — quem da EQUIPE gerencia os acessos dos clientes.
 *
 * Fica no sistema interno, sob as mesmas regras de acesso do resto do
 * Administrativo. É a resposta à decisão de produto de 24/08/2026: a ECF
 * cadastra e convida; não há auto-cadastro. Sem passar por esta tela, ninguém
 * entra no portal.
 *
 * ### O que "remover acesso" faz, e por que há dois botões
 *  - **Desvincular a empresa** tira o acesso ÀQUELA empresa e preserva as
 *    demais. É o caso do gestor que saiu de uma unidade.
 *  - **Desativar a pessoa** derruba o acesso a tudo, na próxima requisição, e
 *    mantém o cadastro para o histórico de auditoria.
 *
 * Nenhum dos dois apaga a pessoa. Apagar levaria junto a trilha de quem fez o
 * quê — e é justamente essa trilha que a auditoria existe para preservar.
 */
class PortalUsuarioController extends Controller
{
    public function __construct(private PortalAuditoria $auditoria)
    {
    }

    public function index(Request $request)
    {
        $usuarios = PortalUsuario::with(['empresas:id,name', 'convidadoPor:id,name'])
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

        // As empresas chegam com o grupo a que pertencem, e os grupos vêm
        // como ALVO próprio: dar acesso a alguém do Camillo Parts costuma
        // significar as 7 empresas do grupo, não uma. Sem isso, o operador
        // repetiria a mesma operação sete vezes — e esqueceria a sétima.
        $empresas = Company::where('active', true)
            ->with('grupo:id,name')
            ->orderBy('name')
            ->get(['id', 'name', 'company_group_id'])
            ->map(fn (Company $e) => [
                'id'         => $e->id,
                'nome'       => $e->name,
                'grupo_id'   => $e->company_group_id,
                'grupo_nome' => $e->grupo?->name,
            ]);

        return Inertia::render('Admin/PortalUsuarios', [
            'usuarios' => $usuarios,
            'empresas' => $empresas,
            // `whereHas`, não `having`: `withCount` gera SUBQUERY, não agregado,
            // e um HAVING sobre ela quebra ("HAVING clause on a non-aggregate
            // query"). Grupo sem empresa ativa fica de fora porque seria um alvo
            // vazio no seletor.
            'grupos'   => CompanyGroup::withCount(['companies' => fn ($q) => $q->where('active', true)])
                ->whereHas('companies', fn ($q) => $q->where('active', true))
                ->orderBy('name')
                ->get()
                ->map(fn (CompanyGroup $g) => [
                    'id'       => $g->id,
                    'nome'     => $g->name,
                    'empresas' => $g->companies_count,
                ]),
        ]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'nome'       => ['required', 'string', 'max:120'],
            'email'      => ['required', 'email', 'max:190', Rule::unique('portal_usuarios', 'email')],
            'telefone'   => ['nullable', 'string', 'max:30'],
            'cargo'      => ['nullable', 'string', 'max:60'],
            // Um dos dois, nunca os dois. `company_id` para uma empresa;
            // `company_group_id` para todas as ativas do grupo.
            'company_id'       => ['required_without:company_group_id', 'nullable', 'integer', 'exists:companies,id'],
            'company_group_id' => ['required_without:company_id', 'nullable', 'integer', 'exists:company_groups,id'],
        ]);

        $usuario = PortalUsuario::create([
            'nome'          => $dados['nome'],
            'email'         => $dados['email'],
            'telefone'      => $dados['telefone'] ?? null,
            'cargo'         => $dados['cargo'] ?? null,
            'ativo'         => true,
            'convidado_por' => $request->user()->id,
            'convidado_em'  => now(),
        ]);

        $empresas = $this->resolverEmpresas($dados);

        $this->vincularEmpresas($usuario, $empresas, marcarPrincipal: true);

        $quantas = $empresas->count();
        $onde = $quantas === 1
            ? $empresas->first()->name
            : "{$quantas} empresas";

        return back()->with('success', "{$usuario->nome} já pode entrar no portal ({$onde}) com o e-mail {$usuario->email}.");
    }

    public function update(Request $request, PortalUsuario $portalUsuario)
    {
        $dados = $request->validate([
            'nome'     => ['nullable', 'string', 'max:120'],
            'telefone' => ['nullable', 'string', 'max:30'],
            'cargo'    => ['nullable', 'string', 'max:60'],
            'ativo'    => ['nullable', 'boolean'],
        ]);

        $estavaAtivo = $portalUsuario->ativo;
        $portalUsuario->update(array_filter($dados, fn ($v) => $v !== null));

        if ($estavaAtivo && $portalUsuario->ativo === false) {
            $this->auditoria->acessoRevogado($portalUsuario, 'usuário desativado');
        }

        return back()->with('success', 'Acesso atualizado.');
    }

    /** Dá acesso a mais uma empresa — ou a um grupo inteiro. */
    public function vincular(Request $request, PortalUsuario $portalUsuario)
    {
        $dados = $request->validate([
            'company_id'       => ['required_without:company_group_id', 'nullable', 'integer', 'exists:companies,id'],
            'company_group_id' => ['required_without:company_id', 'nullable', 'integer', 'exists:company_groups,id'],
        ]);

        $empresas = $this->resolverEmpresas($dados);
        $this->vincularEmpresas($portalUsuario, $empresas);

        $quantas = $empresas->count();
        $onde = $quantas === 1 ? $empresas->first()->name : "{$quantas} empresas";

        return back()->with('success', "{$portalUsuario->nome} agora também acessa {$onde}.");
    }

    /**
     * Uma empresa, ou todas as ATIVAS do grupo.
     *
     * Só as ativas: empresa inativa do grupo não deve aparecer no portal de
     * ninguém, e incluí-la aqui daria acesso a algo que a própria ECF já
     * encerrou. Empresa que voltar a ser ativa depois precisa ser vinculada à
     * mão — é o comportamento seguro.
     *
     * @return \Illuminate\Support\Collection<int, Company>
     */
    private function resolverEmpresas(array $dados)
    {
        if (! empty($dados['company_group_id'])) {
            return Company::where('company_group_id', $dados['company_group_id'])
                ->where('active', true)
                ->orderBy('name')
                ->get();
        }

        return collect([Company::findOrFail($dados['company_id'])]);
    }

    /**
     * `syncWithoutDetaching` e não `attach`: clicar duas vezes, ou vincular um
     * grupo do qual a pessoa já tem uma empresa, não pode estourar por violação
     * do índice único.
     */
    private function vincularEmpresas(PortalUsuario $usuario, $empresas, bool $marcarPrincipal = false): void
    {
        foreach ($empresas as $i => $empresa) {
            $usuario->empresas()->syncWithoutDetaching([
                $empresa->id => ['principal' => $marcarPrincipal && $i === 0],
            ]);

            $this->auditoria->convidado($usuario, $empresa);
        }
    }

    /**
     * Apaga a pessoa de vez.
     *
     * Diferente de desativar: aqui o cadastro some, junto com os vínculos e
     * os códigos (cascade nas duas tabelas). É para o cadastro feito por
     * engano, ou para quem nunca deveria ter entrado na lista.
     *
     * A auditoria é gravada ANTES, com nome, e-mail e empresas nas
     * propriedades: sem isso a resposta a "quem tinha acesso a esta empresa
     * em agosto?" sumiria junto com a linha. Os registros anteriores da
     * pessoa (login, tarefa movida) continuam no `activity_log` — o texto
     * deles já carrega o nome.
     *
     * Para tirar o acesso PRESERVANDO o histórico ligado à pessoa, o caminho
     * é desativar, não excluir. A tela diz isso na confirmação.
     */
    public function destroy(PortalUsuario $portalUsuario)
    {
        $portalUsuario->loadMissing('empresas');
        $nome = $portalUsuario->nome;

        $this->auditoria->excluido($portalUsuario);

        $portalUsuario->delete();

        return back()->with('success', "{$nome} foi removido do portal.");
    }

    /** Tira o acesso a UMA empresa; as demais continuam. */
    public function desvincular(PortalUsuario $portalUsuario, Company $company)
    {
        $portalUsuario->empresas()->detach($company->id);

        $this->auditoria->acessoRevogado($portalUsuario, "desvinculado de {$company->name}");

        return back()->with('success', "{$portalUsuario->nome} não acessa mais {$company->name}.");
    }
}
