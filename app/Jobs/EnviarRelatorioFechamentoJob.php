<?php

namespace App\Jobs;

use App\Mail\RelatorioFechamentoMail;
use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoRecebido;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job assíncrono para envio do Relatório Geral de Fechamento por email.
 * Replica a lógica de montagem de dados de AdminController::gerarRelatorioGeral()
 * (espelho intencional — mantido self-contained no Job para não acoplar ao controller).
 * Disparado manualmente via AdminController::enviarRelatorioGeral() ou automaticamente
 * pelo scheduler no dia 5 de cada mês às 09:00.
 */
class EnviarRelatorioFechamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

    // Tabela de progressão de faixas — espelho de AdminController::FAIXAS
    private const FAIXAS = [
        ['limite' => 499_999.99,   'label' => 'faixa_1', 'valor' => 3_000.00],
        ['limite' => 999_999.99,   'label' => 'faixa_2', 'valor' => 4_500.00],
        ['limite' => 1_999_999.99, 'label' => 'faixa_3', 'valor' => 6_000.00],
        ['limite' => 2_999_999.99, 'label' => 'faixa_4', 'valor' => 7_500.00],
        ['limite' => 3_999_999.99, 'label' => 'faixa_5', 'valor' => 9_000.00],
        ['limite' => 4_999_999.99, 'label' => 'faixa_6', 'valor' => 10_500.00],
    ];

    /**
     * @param string   $mes          Mês de referência no formato 'Y-m' (ex: '2026-05')
     * @param int|null $enviadoPorId ID do usuário que disparou o envio (null = automático)
     */
    public function __construct(
        public string $mes,
        public ?int $enviadoPorId = null,
    ) {
    }

    public function handle(): void
    {
        // ── 1. Busca destinatários configurados ───────────────────────────────
        $json         = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];

        if (empty($destinatarios)) {
            Log::warning('[Fechamento] Tentativa de envio sem destinatários configurados');
            return;
        }

        // ── 2. Monta referência temporal do mês ───────────────────────────────
        try {
            $ref = Carbon::createFromFormat('Y-m', $this->mes)->startOfMonth();
        } catch (\Exception) {
            $ref = Carbon::now();
        }

        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado = $ref->format('Y-m');
        $mesLabel       = ucfirst($ref->translatedFormat('F Y'));
        $inicio         = $ref->copy()->startOfMonth();
        $fim            = $ref->isSameMonth(Carbon::now()) ? Carbon::now() : $ref->copy()->endOfMonth();

        // ── 3. Carrega empresas principais ativas com filhas ──────────────────
        // Phase 14 (Frente B): eager loading de contratosServico.servico (pai + filhas)
        // para o payload de email incluir `servicos_contratados` formatado sem N+1.
        $rawCompanies = Company::where('active', true)
            ->whereNull('parent_company_id')
            ->with([
                'filhas' => fn($q) => $q->where('active', true)->orderBy('name'),
                'contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
                'filhas.contratosServico' => fn($q) => $q->where('ativo', true)->with('servico'),
            ])
            ->orderBy('name')
            ->get();

        // ── 4. IDs de todas as empresas (pais + filhas) para a query de métricas
        $todosIds = $rawCompanies->flatMap(
            fn($c) => collect([$c->id])->merge($c->filhas->pluck('id'))
        )->unique();

        // ── 5. Agrega métricas do mês ─────────────────────────────────────────
        $metricas = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->whereIn('company_id', $todosIds)
            ->selectRaw('company_id, SUM(revenue) as faturamento, MIN(reference_date) as periodo_inicio, MAX(reference_date) as periodo_fim')
            ->groupBy('company_id')
            ->get()
            ->keyBy('company_id');

        // ── 6. Recebidos do mês ───────────────────────────────────────────────
        $recebidos = FechamentoRecebido::where('mes', $mesSelecionado)
            ->pluck('company_id')
            ->flip();

        // ── 7. Monta array de relatórios (uma entrada por empresa principal) ──
        $relatorios = [];
        foreach ($rawCompanies as $company) {
            $recebido   = isset($recebidos[$company->id]);
            $metricaPai = $metricas->get($company->id);

            $faturamentoPai = $metricaPai ? (float) $metricaPai->faturamento : null;
            $faixaPai       = $faturamentoPai !== null ? $this->calcularFaixa($faturamentoPai) : null;

            // Vinculadas com todos os campos (espelho de AdminController::gerarRelatorioGeral)
            // Phase 14 (Frente B): chave nova `servicos_contratados` no formato
            // "Nome (R$ X,XX), Outro (R$ Y,YY)" — derivada do modelo N:N. As 6 chaves
            // legacy (service_type, contract_type, contract_start, contract_end,
            // additional_service, additional_service_price) permanecem em
            // COEXISTÊNCIA. Serão removidas no Plan 14-06. A view Blade do email
            // (resources/views/emails/relatorio-fechamento.blade.php) será
            // refatorada no Plan 14-05.
            $vinculadas = $company->filhas->map(function (Company $f) use ($metricas) {
                $m   = $metricas->get($f->id);
                $fat = $m ? (float) $m->faturamento : null;
                $fx  = $fat !== null ? $this->calcularFaixa($fat) : null;
                return [
                    'id'                       => $f->id,
                    'name'                     => $f->name,
                    'cnpj'                     => $f->cnpj,
                    'adman_account_id'         => $f->adman_account_id,
                    'adman_store_id'           => $f->adman_store_id,
                    'ml_store_id'              => $f->ml_store_id,
                    'segment'                  => $f->segment,
                    // ─── Chaves legacy — TODO Plan 14-06: remover após drop ───
                    'service_type'             => $f->service_type,
                    'contract_type'            => $f->contract_type,
                    'contract_start'           => $f->contract_start ? Carbon::parse($f->contract_start)->format('d/m/Y') : null,
                    'contract_end'             => $f->contract_end  ? Carbon::parse($f->contract_end)->format('d/m/Y')  : null,
                    'additional_service'       => $f->additional_service,
                    'additional_service_price' => $f->additional_service_price ? (float) $f->additional_service_price : null,
                    // ─── Chave nova (string formatada — "Nome (R$ X,XX), ...") ─
                    'servicos_contratados'     => $f->contratosServico->where('ativo', true)
                        ->map(fn($c) => ($c->servico?->nome ?? '—') . ' (R$ ' . number_format((float) $c->valor_contratado, 2, ',', '.') . ')')
                        ->implode(', '),
                    'faturamento'              => $fat,
                    'periodo_inicio'           => $m ? Carbon::parse($m->periodo_inicio)->format('d/m/Y') : null,
                    'periodo_fim'              => $m ? Carbon::parse($m->periodo_fim)->format('d/m/Y')  : null,
                    'faixa_label'              => $fx ? $this->faixaLabel($fx['faixa']) : null,
                    'valor_mensal'             => $fx ? $fx['valor'] : null,
                ];
            })->values()->toArray();

            $totalMensalidade = ($faixaPai ? $faixaPai['valor'] : 0) + collect($vinculadas)->sum('valor_mensal');

            $relatorios[] = [
                'company'          => $company,
                'recebido'         => $recebido,
                'faturamento'      => $faturamentoPai,
                'periodo_inicio'   => $metricaPai ? Carbon::parse($metricaPai->periodo_inicio)->format('d/m/Y') : null,
                'periodo_fim'      => $metricaPai ? Carbon::parse($metricaPai->periodo_fim)->format('d/m/Y')  : null,
                'faixa_label'      => $faixaPai ? $this->faixaLabel($faixaPai['faixa']) : null,
                'valor_mensal'     => $faixaPai ? $faixaPai['valor'] : null,
                'vinculadas'       => $vinculadas,
                'total_mensalidade'=> $totalMensalidade,
            ];
        }

        // ── 8. Calcula totais agregados ────────────────────────────────────────
        $totalMensalidade = array_sum(array_column($relatorios, 'total_mensalidade'));
        $totalRecebido    = array_sum(
            array_map(
                fn($r) => $r['recebido'] ? ($r['total_mensalidade'] ?? 0) : 0,
                $relatorios
            )
        );
        $totalPendente = $totalMensalidade - $totalRecebido;

        $totais = [
            'total_mensalidade' => $totalMensalidade,
            'total_recebido'    => $totalRecebido,
            'total_pendente'    => $totalPendente,
        ];

        // ── 9. Envia email para todos os destinatários ────────────────────────
        Mail::to($destinatarios)->send(new RelatorioFechamentoMail([
            'mesLabel'   => $mesLabel,
            'relatorios' => $relatorios,
            'totais'     => $totais,
        ]));

        // ── 10. Persiste metadados do último envio ────────────────────────────
        Configuracao::set('email_ultimo_envio_fechamento', now()->toIso8601String());
        Configuracao::set('email_ultimo_envio_fechamento_por', (string) $this->enviadoPorId);

        Log::info('[Fechamento] Relatório enviado para ' . count($destinatarios) . ' destinatários (mês ' . $this->mes . ')');
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Fechamento] Falha no envio do relatório: ' . $e->getMessage());
    }

    // ── Helpers privados (espelho de AdminController) ─────────────────────────

    private function calcularFaixa(float $faturamento): array
    {
        foreach (self::FAIXAS as $faixa) {
            if ($faturamento <= $faixa['limite']) {
                return ['faixa' => $faixa['label'], 'valor' => (float) $faixa['valor']];
            }
        }
        return ['faixa' => 'maxima', 'valor' => 12_000.00];
    }

    private function faixaLabel(string $faixa): string
    {
        return match ($faixa) {
            'faixa_1' => 'Faixa 1 (até R$ 499.999)',
            'faixa_2' => 'Faixa 2 (R$ 500k – R$ 999k)',
            'faixa_3' => 'Faixa 3 (R$ 1M – R$ 1,9M)',
            'faixa_4' => 'Faixa 4 (R$ 2M – R$ 2,9M)',
            'faixa_5' => 'Faixa 5 (R$ 3M – R$ 3,9M)',
            'faixa_6' => 'Faixa 6 (R$ 4M – R$ 4,9M)',
            default   => 'Faixa Máxima (acima de R$ 5M)',
        };
    }
}
