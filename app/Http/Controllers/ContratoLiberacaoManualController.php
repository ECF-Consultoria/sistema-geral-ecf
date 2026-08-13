<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoAssinatura;
use App\Models\ContratoLiberacao;
use App\Models\Servico;
use App\Services\Contratos\ContratosPresosService;
use App\Services\Operacional\EmpresaOperacionalRouter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * ContratoLiberacaoManualController — Fase 130 Plano 04 (REDE-03, DADOS-05,
 * D-10/D-11/D-12).
 *
 * O "destravar" da rede de segurança: quando a Clicksign falha (webhook que
 * não chegou, envelope 404, rascunho apagado, contrato recusado/expirado) e
 * a reconciliação automática (plano 130-03) também não resolve, o
 * Administrativo libera a empresa manualmente, informando motivo.
 *
 * `role:admin` é a trava CORRETA hoje, igual à de `ContratoPdfAssinadoController`
 * — a permissão dedicada `admin.contratos` (UI-05) nasce na Fase 131, que
 * REESCREVE esta tela e troca a trava. Por isso este controller e a rota
 * ficam ISOLADOS (sem entrada no menu, sem componente compartilhado novo):
 * o BACKEND (este arquivo) é definitivo, a TELA é deliberadamente descartável.
 *
 * D-11 — este controller NÃO chama `GateLiberacaoOperacionalService::avaliar()`.
 * A liberação manual existe PORQUE o gate automático (e a reconciliação) não
 * liberaram; passar pelo mesmo gate aqui tornaria a tela inútil no exato
 * cenário em que ela precisa funcionar (Clicksign fora do ar, contrato
 * recusado pelo cliente, envelope apagado). O admin libera em QUALQUER
 * estado — a tela mostra o estado real em destaque antes de confirmar, e a
 * prestação de contas é o motivo obrigatório + o autor registrado.
 */
class ContratoLiberacaoManualController extends Controller
{
    /**
     * Lista as empresas presas (mesmo recorte largo do alerta, D-05) para o
     * admin escolher qual liberar.
     *
     * Usa `ContratosPresosService::listar()` — o MESMO recorte de 7 estados
     * do alerta (plano 130-05), não o recorte estreito da reconciliação
     * (plano 130-03). Se usasse o recorte estreito, a tela esconderia
     * justamente `recusado`/`expirado`/`erro`/`cancelado`, os casos em que o
     * admin mais precisa agir manualmente.
     */
    public function index(ContratosPresosService $presos): \Inertia\Response
    {
        $contratos = $presos->listar()->map(function (ContratoAssinatura $c) use ($presos) {
            // Array ACHATADO — nunca o model inteiro nem dados de
            // signatário (e-mail/CPF) atravessam para o browser (T-130-04-05).
            return [
                'id'            => $c->id,
                'company_id'    => $c->company_id,
                'company_nome'  => $c->company?->name,
                'servico_id'    => $c->servico_id,
                'servico_nome'  => $c->servico?->nome,
                'status'        => $c->status,
                'causa'         => $presos->causa($c),
                'dias_parado'   => $presos->diasParado($c),
                'enviado_em'    => $c->enviado_em?->toIso8601String(),
                'assinado_em'   => $c->assinado_em?->toIso8601String(),
            ];
        })->values();

        return Inertia::render('Admin/ContratosLiberacaoManual', [
            'contratos' => $contratos,
            // Mapa slug => rótulo pt-BR — a tela nunca escreve a lista à mão.
            'motivos'   => ContratoLiberacao::MOTIVOS_MANUAIS_LABELS,
        ]);
    }

    /**
     * Libera uma empresa para o operacional, ignorando o gate automático de
     * propósito (D-11 — ver docblock da classe).
     */
    public function store(Request $request, EmpresaOperacionalRouter $router): RedirectResponse
    {
        $data = $request->validate([
            'company_id'             => ['required', 'integer', 'exists:companies,id'],
            'servico_id'             => ['required', 'integer', 'exists:servicos,id'],
            'contrato_assinatura_id' => ['nullable', 'integer', 'exists:contrato_assinaturas,id'],
            // D-12 — slug é lista fechada, nunca string livre.
            'motivo_slug'            => ['required', Rule::in(ContratoLiberacao::MOTIVOS_MANUAIS)],
            // D-12 — o detalhe é obrigatório MESMO com o slug preenchido.
            'motivo_detalhe'         => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        $company = Company::findOrFail($data['company_id']);
        $servico = Servico::findOrFail($data['servico_id']);

        $contrato = null;
        if (! empty($data['contrato_assinatura_id'])) {
            $contrato = ContratoAssinatura::findOrFail($data['contrato_assinatura_id']);

            // T-130-04-03 (IDOR) — o contrato informado tem que pertencer à
            // MESMA empresa/serviço do POST, senão a liberação de uma
            // empresa ficaria amarrada à evidência de outra.
            if ($contrato->company_id !== $company->id || $contrato->servico_id !== $servico->id) {
                abort(422, 'O contrato informado não pertence a esta empresa/serviço.');
            }
        }

        // D-11 — NÃO chamar GateLiberacaoOperacionalService::avaliar() aqui.
        // A liberação manual existe porque o gate automático (webhook +
        // reconciliação) NÃO liberou; passar pelo mesmo gate tornaria esta
        // tela inútil no exato cenário em que ela é necessária.
        $router->liberarEmpresa(
            $company,
            $servico,
            ContratoLiberacao::VIA_MANUAL,
            contrato: $contrato,
            liberadoPorUserId: $request->user()->id,
            motivo: $data['motivo_detalhe'],
            motivoSlug: $data['motivo_slug'],
        );

        return back()->with('success', 'Empresa liberada para o operacional.');
    }
}
