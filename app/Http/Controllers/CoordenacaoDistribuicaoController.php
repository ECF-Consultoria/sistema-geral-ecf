<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\ContratoServico;
use App\Services\FluxoEntrada\DistribuicaoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * CoordenacaoDistribuicaoController — a fila de distribuição (Fase 154).
 *
 * Gated por `coordenacao.distribuir`, chave PRÓPRIA (D-G): distribuir é ato de
 * Coordenação, e reusar `admin.contratos` ou `comercial.entrada` daria à Entrada
 * o poder de escolher o time — a separação que o §10 do PDF estabelece.
 */
class CoordenacaoDistribuicaoController extends Controller
{
    public function index(Request $request, DistribuicaoService $distribuicao): \Inertia\Response
    {
        $fila = $distribuicao->fila();

        return Inertia::render('Coordenacao/Distribuicao', [
            'empresas' => $fila->map(function (Company $c) use ($distribuicao) {
                $elegiveis = $distribuicao->elegiveis($c);

                return [
                    'id'   => $c->id,
                    'name' => $c->name,
                    'cnpj' => $c->cnpj,
                    'servicos' => $c->contratosServico
                        ->where('ativo', true)
                        ->map(fn (ContratoServico $cs) => $cs->servico?->nome)
                        ->filter()
                        ->values()
                        ->all(),
                    // Elegíveis são por EMPRESA porque dependem do setor dos
                    // serviços dela — não dá para servir uma lista só da tela.
                    'analistas'       => $elegiveis['analistas'],
                    'estrategistas'   => $elegiveis['estrategistas'],
                    // Não-nulo exatamente quando a lista foi ABERTA para todos os
                    // setores. A tela exibe: select que muda de universo em
                    // silêncio faz o coordenador achar que aquele é o time
                    // daquele serviço.
                    'motivo_abertura' => $elegiveis['motivo_abertura'],
                ];
            })->values(),
        ]);
    }

    /**
     * Confirma a distribuição (DISTRIB-03/04).
     *
     * O ator é **sempre** `$request->user()` — nenhum `coordenador_id` é lido do
     * corpo. Autoria vinda do cliente é autoria forjável, e é ela que a Fase 156
     * vai medir. Mesma disciplina da T-137-02.
     */
    public function distribuir(Request $request, Company $company, DistribuicaoService $distribuicao): RedirectResponse
    {
        // Fase 157 — a autorização vive no SERVICE porque agora há DUAS portas
        // para o mesmo ato (esta tela e a aba de `/companies`). Sem a fonte
        // única, o líder via a fila e tomava 403 no botão — foi o que o teste
        // pegou. O middleware da rota continua como primeira barreira.
        abort_unless(
            $distribuicao->podeDistribuir($request->user()),
            403,
            'Você não tem permissão para distribuir empresas.'
        );

        $dados = $request->validate([
            // `exists` com o filtro de ativo: usuário desligado não pode ser
            // escolhido nem por POST direto, mesmo que o select da tela o
            // escondesse (DISTRIB-02 vale no servidor, não só na UI).
            'analista_id'     => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
            'estrategista_id' => ['required', 'integer', Rule::exists('users', 'id')->where('active', true)],
        ]);

        $resultado = $distribuicao->distribuir(
            $company,
            (int) $dados['analista_id'],
            (int) $dados['estrategista_id'],
            $request->user()
        );

        if ($resultado['status'] === 'distribuido') {
            return back()->with(
                'success',
                "Empresa distribuída e movida para Aguardando Onboarding ({$resultado['servicos_vinculados']} serviço(s) vinculado(s))."
            );
        }

        if ($resultado['status'] === 'recusado') {
            return back()->with('error', $resultado['requisito_faltante']);
        }

        Log::error('[Distribuicao] falha ao distribuir empresa', [
            'company_id' => $company->id,
            'resultado'  => $resultado,
        ]);

        return back()->with('error', 'Não foi possível distribuir agora. Tente novamente.');
    }
}
