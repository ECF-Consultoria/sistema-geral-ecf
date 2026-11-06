<?php

namespace App\Http\Controllers;

use App\Jobs\SincronizarRenovacaoJob;
use App\Models\Company;
use App\Models\ContratoRenovacao;
use App\Models\Servico;
use App\Services\Renovacao\RenovacaoConfig;
use App\Services\Renovacao\RenovacaoDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 202 (REN-06) — ações da tela de contratos sobre a renovação.
 *
 * A tela NUNCA espera o HubSpot: "Sincronizar novamente" só enfileira a mesma
 * orquestração idempotente do agendador. Rotas dentro do grupo
 * `permission:admin.contratos` (nunca `role:admin`).
 */
class ContratoRenovacaoController extends Controller
{
    /** Chaves de `dados` que o histórico pode mostrar (nada além disto sai). */
    private const DADOS_PERMITIDOS = [
        'motivo', 'negocio_original_id', 'negocio_renovacao_id',
        'termino_antes', 'termino_depois', 'meses',
    ];

    public function sincronizar(Request $request, Company $company, Servico $servico, RenovacaoDetector $detector): RedirectResponse
    {
        $dados = $request->validate([
            'liberar_criacao' => ['sometimes', 'boolean'],
            'justificativa'   => ['required_if:liberar_criacao,1,true', 'nullable', 'string', 'min:10', 'max:1000'],
        ], [
            'justificativa.required_if' => 'Explique por que você está liberando a criação (mínimo de 10 caracteres).',
            'justificativa.min'         => 'A justificativa precisa ter pelo menos 10 caracteres.',
        ]);

        $liberar       = (bool) ($dados['liberar_criacao'] ?? false);
        $justificativa = $liberar ? ($dados['justificativa'] ?? null) : null;

        try {
            // D-07: contrato já vencido só abre pelo botão manual.
            $ciclo = $detector->garantirCiclo($company, (int) $servico->id, true, 'manual', $request->user()->id);
        } catch (\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        SincronizarRenovacaoJob::dispatch($ciclo->id, 'manual', $request->user()->id, null, $liberar, $justificativa);

        return back()->with(
            'success',
            RenovacaoConfig::simulacao()
                ? 'Modo simulação: a decisão foi registrada, nada foi enviado ao HubSpot.'
                : 'Sincronização enfileirada. Em instantes a situação da renovação é atualizada.'
        );
    }

    public function dispensar(Request $request, ContratoRenovacao $ciclo): RedirectResponse
    {
        $dados = $request->validate([
            'justificativa' => ['required', 'string', 'min:10', 'max:1000'],
        ], [
            'justificativa.required' => 'Explique por que esta renovação será dispensada.',
            'justificativa.min'      => 'A justificativa precisa ter pelo menos 10 caracteres.',
        ]);

        if (in_array($ciclo->status_comercial, ContratoRenovacao::STATUS_COMERCIAIS_FINAIS, true)) {
            return back()->with('error', 'Esta renovação já foi encerrada e não pode ser dispensada.');
        }

        DB::transaction(function () use ($ciclo, $dados, $request) {
            $ciclo->update([
                'status_comercial'       => ContratoRenovacao::STATUS_COMERCIAL_DISPENSADO,
                'dispensado_por'         => $request->user()->id,
                'dispensado_em'          => now(),
                'dispensa_justificativa' => $dados['justificativa'],
            ]);

            $ciclo->registrarEvento(
                'dispensado',
                'manual',
                null,
                ['motivo' => $dados['justificativa']],
                false,
                $request->user()->id,
            );
        });

        return back()->with('success', 'Renovação dispensada. Fica registrado quem dispensou e o motivo.');
    }

    public function historico(Company $company, Servico $servico): JsonResponse
    {
        $ciclos = ContratoRenovacao::where('company_id', $company->id)
            ->where('servico_id', $servico->id)
            ->orderByDesc('numero_ciclo')
            ->get();

        $eventos = \App\Models\ContratoRenovacaoEvento::whereIn('contrato_renovacao_id', $ciclos->pluck('id'))
            ->with('user:id,name')
            ->orderByDesc('id')
            ->limit(100)
            ->get();

        return response()->json([
            'ciclos' => $ciclos->map(fn (ContratoRenovacao $c) => [
                'numero'               => $c->numero_ciclo,
                'termino_capturado'    => $c->termino_capturado?->format('Y-m-d'),
                'termino_novo'         => $c->termino_novo?->format('Y-m-d'),
                'status_comercial'     => $c->status_comercial,
                'status_integracao'    => $c->status_integracao,
                'negocio_original_id'  => $c->negocio_original_id,
                'negocio_renovacao_id' => $c->negocio_renovacao_id,
            ])->values(),
            'eventos' => $eventos->map(fn ($e) => [
                'tipo'       => $e->tipo,
                'origem'     => $e->origem,
                'decisao'    => $e->decisao,
                'simulacao'  => (bool) $e->simulacao,
                'usuario'    => $e->user?->name,
                'created_at' => $e->created_at?->toIso8601String(),
                'dados'      => array_intersect_key($e->dados ?? [], array_flip(self::DADOS_PERMITIDOS)),
            ])->values(),
        ]);
    }
}
