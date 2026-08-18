<?php

namespace App\Http\Controllers;

use App\Models\CompanyGroup;
use App\Models\ContratoServico;
use App\Observers\ContratoServicoGatilhoObserver;
use Illuminate\Http\Request;

/**
 * CRUD de grupos nomeados de empresas (tipo carteira) exibidos em /companies.
 *
 * Acesso restrito a admin via grupo de rotas role:admin (routes/web.php).
 * Atribuição de empresa a grupo é feita via CompanyController::update
 * (campo company_group_id) — aqui só gerimos os grupos em si.
 */
class CompanyGroupController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
        ]);

        CompanyGroup::create([
            'name'  => $data['name'],
            'color' => $data['color'] ?: '#ffe600',
        ]);

        return back()->with('success', 'Grupo criado.');
    }

    public function update(Request $request, CompanyGroup $group)
    {
        $data = $request->validate([
            'name'  => 'required|string|max:120',
            'color' => 'nullable|string|max:9',
        ]);

        $group->update([
            'name'  => $data['name'],
            'color' => $data['color'] ?: $group->color,
        ]);

        return back()->with('success', 'Grupo atualizado.');
    }

    public function destroy(CompanyGroup $group)
    {
        // FK nullOnDelete desvincula as empresas automaticamente (não as exclui).
        $nome = $group->name;
        $group->delete();

        return back()->with('success', "Grupo \"{$nome}\" excluído.");
    }

    /**
     * Atribui um serviço (cria contrato ativo) a TODAS as empresas do grupo.
     * Pula empresas que já têm aquele serviço ativo (evita duplicar).
     *
     * Fase 128 (D-05, decisão acrescentada no plan-check): esta operação em
     * lote NUNCA pode disparar o gate administrativo de contrato. O
     * `ContratoServicoGatilhoObserver` (#[ObservedBy] no model
     * `ContratoServico`) capturaria automaticamente cada `create()` deste
     * laço e chamaria `GatilhoContratoAdministrativoService::dispararSeElegivel()`
     * uma vez por empresa — um clique de atribuição em grupo geraria N
     * contratos reais indo para assinatura de N clientes de uma vez, a
     * partir de uma ação que hoje só deveria criar N linhas de
     * `contratos_servico`. `withoutEvents()` suprime o Observer só dentro
     * deste laço, sem afetar o comportamento normal do model em nenhum
     * outro call site. Contrato para essas empresas continua nascendo só
     * pelos dois pontos de entrada de EMPRESA (webhook HubSpot e cadastro
     * manual) ou pelo botão individual da Fase 131.
     */
    public function atribuirServico(Request $request, CompanyGroup $group)
    {
        $data = $request->validate([
            'servico_id'       => 'required|exists:servicos,id',
            'valor_contratado' => 'required|numeric|min:0',
            'data_contratacao' => 'required|date',
            'data_vencimento'  => 'nullable|date|after_or_equal:data_contratacao',
            'observacoes'      => 'nullable|string|max:1000',
        ]);

        $criados = 0;
        $pulados = 0;
        // Supressão DIRIGIDA (merge 135×128): antes era
        // `ContratoServico::withoutEvents()`, que silencia TODOS os observers
        // do model. A Fase 135 acrescentou um segundo (`ContratoServicoObserver`,
        // onboarding em rascunho) que precisa disparar aqui — ele não tem o
        // risco que motivou esta guarda, porque rascunho não vai para cliente
        // nenhum. A garantia original segue intacta: o gatilho de contrato
        // administrativo continua desligado dentro do laço.
        ContratoServicoGatilhoObserver::semDisparo(function () use ($group, $data, &$criados, &$pulados) {
            foreach ($group->companies()->get() as $company) {
                $jaTem = ContratoServico::where('company_id', $company->id)
                    ->where('servico_id', $data['servico_id'])
                    ->where('ativo', true)
                    ->exists();
                if ($jaTem) {
                    $pulados++;
                    continue;
                }

                ContratoServico::create([
                    'company_id'       => $company->id,
                    'servico_id'       => $data['servico_id'],
                    'valor_contratado' => $data['valor_contratado'],
                    'data_contratacao' => $data['data_contratacao'],
                    'data_vencimento'  => $data['data_vencimento'] ?? null,
                    'observacoes'      => $data['observacoes'] ?? null,
                    'ativo'            => true,
                ]);
                $criados++;
            }
        });

        $msg = "Serviço atribuído a {$criados} empresa(s) do grupo \"{$group->name}\".";
        if ($pulados > 0) {
            $msg .= " {$pulados} já tinham o serviço (puladas).";
        }

        return back()->with('success', $msg);
    }
}
