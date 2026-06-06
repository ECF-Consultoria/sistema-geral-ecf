<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\EcfDriveService;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Ficha 360° de uma empresa via API ECF Drive (Phase 25).
 *
 * Consome 4 endpoints do EcfDriveService (Phase 22 wrapper):
 *   seller()               — snapshot atual do lojista
 *   sellerMetricasMensal() — histórico mensal de métricas (últimas 12 posições)
 *   sellerMedalhas()       — histórico de medalhas (cache 6h)
 *   sellerSignals()        — sinais/alertas ativos do lojista (cache 5min, limitados a 20)
 *
 * Latência em cold cache: ~5-8s (4 chamadas sequenciais — D-05 do PLAN).
 * Em warm cache: <500ms. Cache absorve custo nas cargas subsequentes.
 *
 * Auth fina (D-02 do PLAN):
 *   - Admin sempre acessa (visão global)
 *   - Consultor/mentor: só veem empresas da própria carteira (pivot company_users)
 *   - Middleware `role:admin,consultor,mentor` já filtra publicador/analista/gestor
 */
class EmpresaAnaliseEcfController extends Controller
{
    public function __construct(private EcfDriveService $ecf) {}

    /**
     * Exibe a ficha 360° de uma empresa via ECF Drive.
     *
     * Props retornadas ao Inertia (sempre todas — front nunca recebe shape incompleto):
     *   empresa       — { id, name, cust_id, segmento_local }
     *   seller        — snapshot do /sellers/{custId} ou null em erro/semCustId/naoEncontrada
     *   metricas      — array das 12 últimas métricas mensais
     *   medalhas      — array do histórico de medalhas
     *   signals       — array dos 20 signals mais recentes
     *   semCustId     — true quando empresa não tem cust_id ML cadastrado
     *   naoEncontrada — true quando cust_id existe mas ECF Drive retorna 404
     *   erro          — string pt-BR amigável em erro genérico, null caso contrário
     */
    public function show(Request $request, Company $company)
    {
        $user = $request->user();

        // ─── Auth fina por carteira (D-02 do PLAN) ───────────────────────────
        // Admin vê todas. Consultor/mentor restrito à própria carteira.
        // 'companies.id' qualificado para evitar ambiguous column no join via pivot (R-03).
        if (!$user->isAdmin()) {
            abort_unless(
                $user->companies()->where('companies.id', $company->id)->exists(),
                403,
                'Esta empresa não está na sua carteira.'
            );
        }

        // ─── Lookup cust_id via accessor (D-03 do PLAN) ──────────────────────
        // Prioriza ml_store_id ?? adman_account_id. Retorna null se ambos ausentes.
        $custId = $company->cust_id;

        // ─── Carrega grant ativo para segmento_local (D-05 header) ───────────
        $company->loadMissing('grants');
        $grantAtivo    = $company->grants->firstWhere('status', 'active');
        $segmentoLocal = $grantAtivo?->segmento;

        // Objeto empresa presente em TODOS os estados condicionais do front
        $empresa = [
            'id'             => $company->id,
            'name'           => $company->name,
            'cust_id'        => $custId,
            'segmento_local' => $segmentoLocal,
        ];

        // ─── Empty state: empresa sem cust_id ML (D-10 do PLAN) ─────────────
        // Típico de empresas Shopee/Amazon cadastradas só pelo Comercial.
        // NÃO chama nenhum endpoint do wrapper (zero overhead).
        if ($custId === null) {
            return Inertia::render('EmpresaAnaliseEcf/Show', [
                'empresa'       => $empresa,
                'seller'        => null,
                'metricas'      => [],
                'medalhas'      => [],
                'signals'       => [],
                'semCustId'     => true,
                'naoEncontrada' => false,
                'erro'          => null,
            ]);
        }

        // ─── 4 chamadas ao ECF Drive em try/catch global (D-04 do PLAN) ──────
        // Catch específico para HTTP 404 (cust_id não existe na base ECF Drive —
        // típico de cust_id de conta Shopee no Adman sem correspondente ML).
        // Catch genérico para qualquer outro erro — página NUNCA quebra o pageload.
        try {
            // (1) Snapshot do lojista — sem cache (proxy on-demand)
            $sellerResp = $this->ecf->seller($custId);

            // (2) Histórico mensal — cache 1h; últimas 12 posições (mensal vem ordenado asc)
            $metricasResp = $this->ecf->sellerMetricasMensal($custId);
            $metricas     = array_slice($metricasResp['data'] ?? [], -12);

            // (3) Histórico de medalhas — cache 6h
            $medalhasResp = $this->ecf->sellerMedalhas($custId);
            $medalhas     = $medalhasResp['data'] ?? [];

            // (4) Signals/alertas ativos — cache 5min; limitados a 20 (D-06 do PLAN)
            // Seller crítico pode ter centenas de signals; slicear aqui não no wrapper.
            $signalsResp = $this->ecf->sellerSignals($custId);
            $signals     = array_slice($signalsResp['data'] ?? [], 0, 20);

            return Inertia::render('EmpresaAnaliseEcf/Show', [
                'empresa'       => $empresa,
                'seller'        => $sellerResp,
                'metricas'      => $metricas,
                'medalhas'      => $medalhas,
                'signals'       => $signals,
                'semCustId'     => false,
                'naoEncontrada' => false,
                'erro'          => null,
            ]);

        } catch (\Throwable $e) {
            // ─── 404 separado de erro genérico (D-04 / R-04 do PLAN) ─────────
            // O wrapper lança: "ECF Drive {$path} falhou: HTTP 404 - ..."
            // Indica que cust_id existe mas não está na base ECF Drive.
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return Inertia::render('EmpresaAnaliseEcf/Show', [
                    'empresa'       => $empresa,
                    'seller'        => null,
                    'metricas'      => [],
                    'medalhas'      => [],
                    'signals'       => [],
                    'semCustId'     => false,
                    'naoEncontrada' => true,
                    'erro'          => null,
                ]);
            }

            // ─── Erro genérico: ECF Drive offline ou erro inesperado ──────────
            // report() envia detalhes ao log do servidor — UI recebe mensagem amigável.
            report($e);

            return Inertia::render('EmpresaAnaliseEcf/Show', [
                'empresa'       => $empresa,
                'seller'        => null,
                'metricas'      => [],
                'medalhas'      => [],
                'signals'       => [],
                'semCustId'     => false,
                'naoEncontrada' => false,
                'erro'          => 'Não foi possível buscar dados da análise agora. Tente em alguns segundos.',
            ]);
        }
    }
}
