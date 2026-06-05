<?php

// Phase 23 — Alertas Estratégicos (caixa de entrada do comercial).
// Consome /signals da API ECF Drive via EcfDriveService (Phase 22 wrapper).

namespace App\Http\Controllers;

use App\Models\Company;
use App\Services\EcfDriveService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class AlertasController extends Controller
{
    /**
     * Tradução dos event_type da API ECF Drive para labels pt-BR.
     * Fonte: CONTEXT.md D-07 + API-GUIDE.md §7.
     * Mirror em resources/js/Pages/AlertasEstrategicos/components/AlertaCard.jsx.
     */
    public const TYPE_LABELS = [
        'seller.gmv_queda_mom'     => 'Queda de faturamento',
        'seller.queda_visitas'     => 'Queda de visitas',
        'seller.medalha_rebaixada' => 'Medalha rebaixada',
        'seller.score_critico'     => 'Score crítico',
        'seller.oportunidade_pads' => 'Oportunidade de ADS',
    ];

    public const SEVERITY_LABELS = [
        'critical' => 'Crítico',
        'warning'  => 'Atenção',
        'info'     => 'Oportunidade',
    ];

    public const SEVERITY_COLORS = [
        'critical' => 'red',
        'warning'  => 'yellow',
        'info'     => 'blue',
    ];

    public function __construct(private EcfDriveService $ecf) {}

    /**
     * Lista signals com filtros — caixa de entrada do comercial.
     *
     * Estratégia defensiva (D-02): try/catch Throwable global. Se ECF Drive
     * estiver offline, a aba abre normalmente com lista vazia + flash error
     * pt-BR — NUNCA quebra o pageload do usuário.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'severity'   => 'nullable|in:info,warning,critical',
            'event_type' => 'nullable|in:' . implode(',', array_keys(self::TYPE_LABELS)),
            'acked'      => 'nullable|boolean',
            'page'       => 'nullable|integer|min:1',
        ]);

        $filters = [
            'severity'   => $validated['severity']   ?? null,
            'event_type' => $validated['event_type'] ?? null,
            'acked'      => isset($validated['acked']) ? (bool) $validated['acked'] : false,
            'page'       => (int) ($validated['page'] ?? 1),
        ];

        try {
            // 1) Lista paginada de signals
            $apiFilters = array_filter([
                'severity'   => $filters['severity'],
                'event_type' => $filters['event_type'],
                'acked'      => $filters['acked'],
                'page'       => $filters['page'],
                'limit'      => 50,
            ], fn ($v) => $v !== null);

            $signals = $this->ecf->listSignals($apiFilters);

            // 2) Lookup batch de companies por cust_id (1 query só — D-03)
            $custIds = collect($signals['data'] ?? [])
                ->pluck('custId')
                ->filter()
                ->map(fn ($v) => (string) $v)
                ->unique()
                ->values()
                ->all();

            $companiesMap = [];
            if (! empty($custIds)) {
                $companies = Company::where('active', true)
                    ->where(function ($q) use ($custIds) {
                        $q->whereIn('adman_account_id', $custIds)
                          ->orWhereIn('ml_store_id', $custIds);
                    })
                    ->get(['id', 'name', 'slug', 'adman_account_id', 'ml_store_id']);

                foreach ($companies as $c) {
                    $entry = ['id' => $c->id, 'name' => $c->name, 'slug' => $c->slug];
                    if ($c->adman_account_id) {
                        $companiesMap[(string) $c->adman_account_id] = $entry;
                    }
                    if ($c->ml_store_id) {
                        $companiesMap[(string) $c->ml_store_id] = $entry;
                    }
                }
            }

            // 3) Stats por severidade (3 chamadas leves, cache 1min no wrapper — D-04)
            $stats = [
                'critical' => (int) ($this->ecf->listSignals(['severity' => 'critical', 'acked' => false, 'limit' => 1])['total'] ?? 0),
                'warning'  => (int) ($this->ecf->listSignals(['severity' => 'warning',  'acked' => false, 'limit' => 1])['total'] ?? 0),
                'info'     => (int) ($this->ecf->listSignals(['severity' => 'info',     'acked' => false, 'limit' => 1])['total'] ?? 0),
            ];

            return Inertia::render('AlertasEstrategicos/Index', [
                'signals'         => $signals,
                'companies_map'   => $companiesMap,
                'stats'           => $stats,
                'filters'         => $filters,
                'type_labels'     => self::TYPE_LABELS,
                'severity_labels' => self::SEVERITY_LABELS,
                'severity_colors' => self::SEVERITY_COLORS,
                'erro'            => null,
            ]);
        } catch (\Throwable $e) {
            // Defensiva (D-02): ECF Drive offline NÃO quebra a aba
            report($e);
            return Inertia::render('AlertasEstrategicos/Index', [
                'signals'         => ['data' => [], 'total' => 0, 'page' => 1, 'limit' => 50],
                'companies_map'   => [],
                'stats'           => ['critical' => 0, 'warning' => 0, 'info' => 0],
                'filters'         => $filters,
                'type_labels'     => self::TYPE_LABELS,
                'severity_labels' => self::SEVERITY_LABELS,
                'severity_colors' => self::SEVERITY_COLORS,
                'erro'            => 'API ECF Drive indisponível agora. Tente em alguns segundos.',
            ]);
        }
    }

    /**
     * Marca um signal como visto via POST /signals/{id}/ack do ECF Drive.
     * Wrapper invalida automaticamente a chave de cache da inbox padrão.
     */
    public function ack(int $id)
    {
        try {
            $this->ecf->ackSignal($id);
            return back()->with('success', 'Alerta marcado como visto.');
        } catch (\Throwable $e) {
            report($e);
            return back()->with('error', 'Falha ao marcar como visto. Tente novamente.');
        }
    }
}
