<?php

namespace App\Http\Controllers;

use App\Models\Company;
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

        return Inertia::render('Admin/PortalUsuarios', [
            'usuarios'  => $usuarios,
            'empresas'  => Company::where('active', true)->orderBy('name')->get(['id', 'name'])
                ->map(fn ($e) => ['id' => $e->id, 'nome' => $e->name]),
        ]);
    }

    public function store(Request $request)
    {
        $dados = $request->validate([
            'nome'       => ['required', 'string', 'max:120'],
            'email'      => ['required', 'email', 'max:190', Rule::unique('portal_usuarios', 'email')],
            'telefone'   => ['nullable', 'string', 'max:30'],
            'cargo'      => ['nullable', 'string', 'max:60'],
            'company_id' => ['required', 'integer', 'exists:companies,id'],
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

        $empresa = Company::findOrFail($dados['company_id']);
        $usuario->empresas()->attach($empresa->id, ['principal' => true]);

        $this->auditoria->convidado($usuario, $empresa);

        return back()->with('success', "{$usuario->nome} já pode entrar no portal com o e-mail {$usuario->email}.");
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

    /** Dá acesso a mais uma empresa (grupos empresariais). */
    public function vincular(Request $request, PortalUsuario $portalUsuario)
    {
        $dados = $request->validate([
            'company_id' => ['required', 'integer', 'exists:companies,id'],
        ]);

        $empresa = Company::findOrFail($dados['company_id']);

        // `syncWithoutDetaching` e não `attach`: clicar duas vezes não pode
        // estourar por violação de índice único.
        $portalUsuario->empresas()->syncWithoutDetaching([$empresa->id]);

        $this->auditoria->convidado($portalUsuario, $empresa);

        return back()->with('success', "{$portalUsuario->nome} agora também acessa {$empresa->name}.");
    }

    /** Tira o acesso a UMA empresa; as demais continuam. */
    public function desvincular(PortalUsuario $portalUsuario, Company $company)
    {
        $portalUsuario->empresas()->detach($company->id);

        $this->auditoria->acessoRevogado($portalUsuario, "desvinculado de {$company->name}");

        return back()->with('success', "{$portalUsuario->nome} não acessa mais {$company->name}.");
    }
}
