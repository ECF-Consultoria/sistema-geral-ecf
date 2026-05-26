<?php

namespace App\Http\Controllers;

use App\Models\Servico;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * ServicoController — CRUD do catálogo de serviços (admin only).
 *
 * Frente A do Módulo Serviços. Não toca em campos legacy de companies.
 * Acesso gated por middleware role:admin em routes/web.php.
 */
class ServicoController extends Controller
{
    /**
     * Lista o catálogo com contagem de contratos ativos por serviço.
     */
    public function index()
    {
        $servicos = Servico::query()
            ->withCount([
                'contratos as contratos_ativos_count' => fn($q) => $q->where('ativo', true),
            ])
            ->orderBy('nome')
            ->get();

        return Inertia::render('Servicos/Index', [
            'servicos' => $servicos,
        ]);
    }

    /**
     * Cria novo serviço no catálogo.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'nome'          => 'required|string|max:120',
            'valor_padrao'  => 'required|numeric|min:0',
            'tipo_cobranca' => 'required|in:'.Servico::TIPO_MENSAL.','.Servico::TIPO_UNICA,
            'ativo'         => 'boolean',
        ]);

        // Default ativo=true caso o frontend não envie (criação padrão)
        $data['ativo'] = $data['ativo'] ?? true;

        Servico::create($data);

        return back()->with('success', 'Serviço criado.');
    }

    /**
     * Atualiza serviço existente.
     */
    public function update(Request $request, Servico $servico)
    {
        $data = $request->validate([
            'nome'          => 'required|string|max:120',
            'valor_padrao'  => 'required|numeric|min:0',
            'tipo_cobranca' => 'required|in:'.Servico::TIPO_MENSAL.','.Servico::TIPO_UNICA,
            'ativo'         => 'boolean',
        ]);

        $servico->update($data);

        return back()->with('success', 'Serviço atualizado.');
    }

    /**
     * Exclui ou desativa serviço.
     *
     * Regra: se houver contratos ativos vinculados, NÃO deleta — apenas
     * marca ativo=false (soft-deactivate) pra preservar integridade
     * referencial. Sem contratos ativos, delete físico.
     */
    public function destroy(Servico $servico)
    {
        if ($servico->contratos()->where('ativo', true)->exists()) {
            $servico->update(['ativo' => false]);
            return back()->with('success', 'Serviço desativado (mantido por ter contratos ativos).');
        }

        $servico->delete();
        return back()->with('success', 'Serviço excluído.');
    }
}
