<?php

namespace App\Jobs;

use App\Mail\RelatorioFechamentoMail;
use App\Models\Company;
use App\Models\Configuracao;
use App\Models\FechamentoGrupoSnapshot;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Services\Fechamento\FechamentoFaixaResolver;
use App\Services\Fechamento\FechamentoRollupService;
use App\Support\CobrancaCalculator;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Job assíncrono para envio do Relatório Geral de Fechamento por email.
 *
 * Fase 137 (Tarefa 08): este job LIA a própria cópia da tabela de progressão
 * hardcoded e a própria lógica de hierarquia pai/filha — a pesquisa mediu
 * que isso produzia dois números diferentes para a mesma empresa no mesmo
 * mês (a cópia nunca foi atualizada em sincronia com a do controller) e que
 * o job somava SÓ `adman_metrics`, nunca Shopee. Agora lê a MESMA fonte
 * central de `AdminController::gerarRelatorioGeral()` —
 * `FechamentoRollupService` (ML+Shopee, D-05, mês-calendário fechado, D-06)
 * e `FechamentoFaixaResolver` (tabela por serviço/exceção, D-01/D-02b) — e,
 * quando a competência já foi fechada por `fechamento:consolidar-mes`, lê o
 * congelado (`fechamento_snapshots`/`fechamento_grupo_snapshots`, D-11) em
 * vez de recalcular. O agrupamento usa `CompanyGroup` (D-08/D-09/D-10),
 * nunca a coluna de hierarquia legada.
 *
 * Disparado manualmente via AdminController::enviarRelatorioGeral() ou
 * automaticamente pelo scheduler (`routes/console.php`, dia/hora
 * configuráveis por `Configuracao::get('email_envio_auto_dia'/'hora')`).
 */
class EnviarRelatorioFechamentoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries   = 2;

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
        $json          = Configuracao::get('email_destinatarios_fechamento');
        $destinatarios = $json ? json_decode($json, true) : [];

        if (empty($destinatarios)) {
            Log::warning('[Fechamento] Tentativa de envio sem destinatários configurados');
            return;
        }

        // Resolvidos do container em vez de injetados no construtor — o job
        // é serializado na fila (ShouldQueue), e services não são
        // serializáveis.
        $rollupService = app(FechamentoRollupService::class);
        $faixaResolver = app(FechamentoFaixaResolver::class);

        // ── 2. Monta referência temporal do mês ───────────────────────────────
        try {
            $ref = Carbon::createFromFormat('Y-m', $this->mes)->startOfMonth();
        } catch (\Exception) {
            $ref = Carbon::now();
        }

        if ($ref->isAfter(Carbon::now())) {
            $ref = Carbon::now();
        }

        $mesSelecionado   = $ref->format('Y-m');
        $mesReferenciaStr = $ref->copy()->startOfMonth()->toDateString();
        $mesLabel         = ucfirst($ref->translatedFormat('F Y'));

        // D-06 — mês-calendário fechado (mesma implementação única de
        // AdminController::fechamento()/gerarRelatorio()/gerarRelatorioGeral()).
        $janela = $rollupService->janela($mesSelecionado);
        $inicio = $janela['inicio'];
        $fim    = $janela['fim'];

        // ── 3. Carrega TODAS as empresas ativas (D-08 — não mais só as
        // sem hierarquia de pai, filtro do job antigo) ────────────────────
        $rawCompanies = Company::where('active', true)
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'grupo:id,name,color',
            ])
            ->orderBy('name')
            ->get();

        $competenciaFechada = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->exists();

        // ── 4. Números por empresa: congelado (D-11) ou ao vivo (D-05) ────────
        $dadosPorId = $competenciaFechada
            ? $this->porEmpresaCongelados($mesReferenciaStr, $rawCompanies)
            : $this->porEmpresaAoVivo($rollupService, $faixaResolver, $mesSelecionado, $rawCompanies);

        // ── 5. Agregação por CompanyGroup (D-08/D-09/D-10) ────────────────────
        $entidades = $competenciaFechada
            ? $this->agruparGruposCongelados($mesReferenciaStr, $rawCompanies, $dadosPorId)
            : $this->agruparGruposAoVivo($faixaResolver, $rawCompanies, $dadosPorId);

        $periodoInicioFmt = $inicio->format('d/m/Y');
        $periodoFimFmt    = $fim->format('d/m/Y');

        // ── 6. Monta array de relatórios (uma entrada por empresa OU grupo) ───
        $relatorios = [];
        foreach ($entidades as $entidade) {
            $ancora = $entidade['ancora'];
            $linha  = $entidade['linha'];

            $linhaPrincipal = $this->linhaEmpresa($ancora, $linha, $periodoInicioFmt, $periodoFimFmt);

            $vinculadas = $entidade['membros']
                ->reject(fn (Company $m) => $m->id === $ancora->id)
                ->map(fn (Company $m) => $this->linhaEmpresa($m, $dadosPorId[$m->id] ?? [], $periodoInicioFmt, $periodoFimFmt))
                ->values()
                ->toArray();

            $titulo = $entidade['tipo'] === 'grupo'
                ? ($ancora->grupo->name ?? $ancora->name)
                : $ancora->name;

            $relatorios[] = [
                'company'           => $ancora,
                'titulo'            => $titulo,
                'faturamento'       => $linhaPrincipal['faturamento'],
                'periodo_inicio'    => $linhaPrincipal['periodo_inicio'],
                'periodo_fim'       => $linhaPrincipal['periodo_fim'],
                'faixa_label'       => $linhaPrincipal['faixa_label'],
                'valor_mensal'      => $linhaPrincipal['valor_mensal'],
                'valor_e_piso'      => $linhaPrincipal['valor_e_piso'],
                'cobranca_mensal'   => $linhaPrincipal['cobranca_mensal'],
                'vinculadas'        => $vinculadas,
                'total_mensalidade' => ($linhaPrincipal['cobranca_mensal'] ?? 0) + collect($vinculadas)->sum('cobranca_mensal'),
            ];
        }

        usort($relatorios, fn ($a, $b) => strcmp($a['titulo'], $b['titulo']));

        // ── 7. Calcula totais agregados ────────────────────────────────────────
        // Fase 139 Plano 07: total_recebido/total_pendente saíram — ninguém
        // alimentava a informação de pagamento (marcador usado uma única vez
        // em produção); o email não pode mais afirmar isso.
        $totalMensalidade = array_sum(array_column($relatorios, 'total_mensalidade'));

        $totais = [
            'total_mensalidade' => $totalMensalidade,
        ];

        // ── 8. Envia email para todos os destinatários ────────────────────────
        Mail::to($destinatarios)->send(new RelatorioFechamentoMail([
            'mesLabel'   => $mesLabel,
            'relatorios' => $relatorios,
            'totais'     => $totais,
        ]));

        // ── 9. Persiste metadados do último envio ────────────────────────────
        Configuracao::set('email_ultimo_envio_fechamento', now()->toIso8601String());
        Configuracao::set('email_ultimo_envio_fechamento_por', (string) $this->enviadoPorId);

        Log::info('[Fechamento] Relatório enviado para ' . count($destinatarios) . ' destinatários (mês ' . $this->mes . ')');
    }

    public function failed(\Throwable $e): void
    {
        Log::error('[Fechamento] Falha no envio do relatório: ' . $e->getMessage());
    }

    // ── Números por empresa ─────────────────────────────────────────────────

    /**
     * D-05 — faturamento ML+Shopee somado via `FechamentoRollupService`, e
     * classificado pela tabela de `FechamentoFaixaResolver`. Fecha o gap
     * pré-existente: o job antigo somava só `adman_metrics`.
     */
    private function porEmpresaAoVivo(
        FechamentoRollupService $rollupService,
        FechamentoFaixaResolver $faixaResolver,
        string $mesSelecionado,
        Collection $rawCompanies,
    ): array {
        $rollup = $rollupService->porEmpresa($mesSelecionado, $rawCompanies);

        $dados = [];

        foreach ($rawCompanies as $c) {
            $fat = $rollup[$c->id]['faturamento_total'] ?? null;

            $faixaData     = $faixaResolver->paraEmpresa($c);
            $classificacao = ($faixaData !== null && $fat !== null)
                ? $faixaResolver->classificar((float) $fat, $faixaData['faixas'])
                : null;

            $dados[$c->id] = $this->montarClassificacao($c, $fat, $classificacao);
        }

        return $dados;
    }

    /**
     * D-11 — lê `fechamento_snapshots`, nunca recalcula. Corrigir
     * `adman_metrics` depois do fechamento não muda o que já foi cobrado.
     */
    private function porEmpresaCongelados(string $mesReferenciaStr, Collection $rawCompanies): array
    {
        $snapshots = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->get()
            ->keyBy('company_id');

        $dados = [];

        foreach ($rawCompanies as $c) {
            $s = $snapshots->get($c->id);

            $dados[$c->id] = [
                'faturamento'           => $s?->faturamento_total !== null ? (float) $s->faturamento_total : null,
                'faixa_ordem'           => $s?->faixa_ordem,
                'faixa_limite_inferior' => $s?->faixa_limite_inferior !== null ? (float) $s->faixa_limite_inferior : null,
                'faixa_limite_superior' => $s?->faixa_limite_superior !== null ? (float) $s->faixa_limite_superior : null,
                'valor_mensal'          => $s?->valor_faixa !== null ? (float) $s->valor_faixa : null,
                'valor_e_piso'          => (bool) ($s?->valor_faixa_e_piso ?? false),
                'cobranca_mensal'       => $s?->cobranca_mensal !== null ? (float) $s->cobranca_mensal : null,
            ];
        }

        return $dados;
    }

    private function montarClassificacao(Company $c, ?float $fat, ?array $classificacao): array
    {
        $temContratoMensal = $c->contratosServico->contains(
            fn ($ct) => $ct->ativo === true && $ct->servico !== null && $ct->servico->tipo_cobranca === Servico::TIPO_MENSAL
        );

        $cobranca = ($classificacao !== null || $temContratoMensal)
            ? (CobrancaCalculator::novo($classificacao, $c->contratosServico) ?: null)
            : null;

        return [
            'faturamento'           => $fat,
            'faixa_ordem'           => $classificacao['ordem'] ?? null,
            'faixa_limite_inferior' => $classificacao['limite_inferior'] ?? null,
            'faixa_limite_superior' => $classificacao['limite_superior'] ?? null,
            'valor_mensal'          => $classificacao['valor'] ?? null,
            'valor_e_piso'          => $classificacao['valor_e_piso'] ?? false,
            'cobranca_mensal'       => $cobranca,
        ];
    }

    // ── Agregação por CompanyGroup (D-08/D-09/D-10) ────────────────────────

    /**
     * Uma "entidade" por `CompanyGroup` (âncora = empresa-membro de maior
     * faturamento, empate pelo menor id) + uma entidade por empresa ativa
     * sem grupo. A hierarquia legada de pai/filha NUNCA participa (D-08).
     *
     * @return list<array{tipo: string, ancora: Company, membros: Collection<int, Company>, linha: array}>
     */
    private function agruparGruposAoVivo(FechamentoFaixaResolver $faixaResolver, Collection $rawCompanies, array $dadosPorId): array
    {
        $entidades = [];

        foreach ($rawCompanies->filter(fn (Company $c) => $c->company_group_id === null) as $c) {
            $entidades[] = [
                'tipo'    => 'empresa',
                'ancora'  => $c,
                'membros' => collect([$c]),
                'linha'   => $dadosPorId[$c->id] ?? [],
            ];
        }

        $porGrupo = $rawCompanies
            ->filter(fn (Company $c) => $c->company_group_id !== null)
            ->groupBy('company_group_id');

        foreach ($porGrupo as $membros) {
            $ancora = $membros->sort(function (Company $a, Company $b) use ($dadosPorId) {
                $fatA = (float) ($dadosPorId[$a->id]['faturamento'] ?? -INF);
                $fatB = (float) ($dadosPorId[$b->id]['faturamento'] ?? -INF);

                if ($fatA === $fatB) {
                    return $a->id <=> $b->id;
                }

                return $fatB <=> $fatA;
            })->first();

            $faturamentoTotal = null;
            foreach ($membros as $m) {
                $fat = $dadosPorId[$m->id]['faturamento'] ?? null;
                if ($fat !== null) {
                    $faturamentoTotal = ($faturamentoTotal ?? 0.0) + (float) $fat;
                }
            }

            $faixaAncora   = $faixaResolver->paraEmpresa($ancora);
            $classificacao = ($faixaAncora !== null && $faturamentoTotal !== null)
                ? $faixaResolver->classificar($faturamentoTotal, $faixaAncora['faixas'])
                : null;

            // O cálculo de cobrança do grupo soma os contratos de TODOS os
            // membros, não só da âncora — por isso não reusa
            // montarClassificacao() aqui (ele só olha $c->contratosServico).
            $todosContratos    = $membros->flatMap(fn (Company $c) => $c->contratosServico);
            $temContratoMensal = $todosContratos->contains(
                fn ($ct) => $ct->ativo === true && $ct->servico !== null && $ct->servico->tipo_cobranca === Servico::TIPO_MENSAL
            );
            $cobrancaGrupo = ($classificacao !== null || $temContratoMensal)
                ? (CobrancaCalculator::novo($classificacao, $todosContratos) ?: null)
                : null;

            $entidades[] = [
                'tipo'    => 'grupo',
                'ancora'  => $ancora,
                'membros' => $membros,
                'linha'   => [
                    'faturamento'           => $faturamentoTotal,
                    'faixa_ordem'           => $classificacao['ordem'] ?? null,
                    'faixa_limite_inferior' => $classificacao['limite_inferior'] ?? null,
                    'faixa_limite_superior' => $classificacao['limite_superior'] ?? null,
                    'valor_mensal'          => $classificacao['valor'] ?? null,
                    'valor_e_piso'          => $classificacao['valor_e_piso'] ?? false,
                    'cobranca_mensal'       => $cobrancaGrupo,
                ],
            ];
        }

        return $entidades;
    }

    /**
     * D-11 — lê `fechamento_grupo_snapshots`, nunca recalcula a soma do grupo.
     */
    private function agruparGruposCongelados(string $mesReferenciaStr, Collection $rawCompanies, array $dadosPorId): array
    {
        $entidades = [];

        foreach ($rawCompanies->filter(fn (Company $c) => $c->company_group_id === null) as $c) {
            $entidades[] = [
                'tipo'    => 'empresa',
                'ancora'  => $c,
                'membros' => collect([$c]),
                'linha'   => $dadosPorId[$c->id] ?? [],
            ];
        }

        $porGrupo = $rawCompanies
            ->filter(fn (Company $c) => $c->company_group_id !== null)
            ->groupBy('company_group_id');

        $snapshotsGrupo = FechamentoGrupoSnapshot::query()
            ->whereDate('mes_referencia', $mesReferenciaStr)
            ->where('origem', FechamentoGrupoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->get()
            ->keyBy('company_group_id');

        foreach ($porGrupo as $groupId => $membros) {
            $s = $snapshotsGrupo->get($groupId);

            $ancoraId = $s?->empresa_ancora_id ?? $membros->first()->id;
            $ancora   = $membros->firstWhere('id', $ancoraId) ?? $membros->first();

            $entidades[] = [
                'tipo'    => 'grupo',
                'ancora'  => $ancora,
                'membros' => $membros,
                'linha'   => [
                    'faturamento'           => $s?->faturamento_total !== null ? (float) $s->faturamento_total : null,
                    'faixa_ordem'           => $s?->faixa_ordem,
                    'faixa_limite_inferior' => $s?->faixa_limite_inferior !== null ? (float) $s->faixa_limite_inferior : null,
                    'faixa_limite_superior' => $s?->faixa_limite_superior !== null ? (float) $s->faixa_limite_superior : null,
                    'valor_mensal'          => $s?->valor_faixa !== null ? (float) $s->valor_faixa : null,
                    'valor_e_piso'          => (bool) ($s?->valor_faixa_e_piso ?? false),
                    'cobranca_mensal'       => $s?->cobranca_mensal !== null ? (float) $s->cobranca_mensal : null,
                ],
            ];
        }

        return $entidades;
    }

    // ── Payload por linha (empresa própria, âncora de grupo ou vinculada) ──

    /**
     * Fase 137 (D-02b) — rótulo legível de faixa a partir dos campos já
     * calculados (`faixa_ordem`, `faixa_limite_inferior`,
     * `faixa_limite_superior`) — nunca reclassifica.
     */
    private function faixaLabel(?int $ordem, ?float $limiteInferior, ?float $limiteSuperior): ?string
    {
        if ($ordem === null) {
            return null;
        }

        if ($limiteSuperior === null) {
            return 'Faixa máxima (acima de R$ ' . number_format($limiteInferior ?? 0, 0, ',', '.') . ')';
        }

        return 'Faixa ' . $ordem . ' (R$ ' . number_format($limiteInferior ?? 0, 0, ',', '.')
            . ' – R$ ' . number_format($limiteSuperior, 0, ',', '.') . ')';
    }

    private function linhaEmpresa(Company $c, array $d, string $periodoInicioFmt, string $periodoFimFmt): array
    {
        $faturamento = $d['faturamento'] ?? null;

        return [
            'id'                   => $c->id,
            'name'                 => $c->name,
            'cnpj'                 => $c->cnpj,
            'adman_account_id'     => $c->cust_id,
            'adman_store_id'       => $c->adman_store_id ?? null,
            'ml_store_id'          => $c->ml_store_id,
            'segment'              => $c->segment,
            'servicos_contratados' => $c->contratosServico->where('ativo', true)
                ->map(fn ($ct) => ($ct->servico?->nome ?? '—') . ' (R$ ' . number_format((float) $ct->valor_contratado, 2, ',', '.') . ')')
                ->implode(', '),
            'faturamento'          => $faturamento,
            'periodo_inicio'       => $faturamento !== null ? $periodoInicioFmt : null,
            'periodo_fim'          => $faturamento !== null ? $periodoFimFmt    : null,
            'faixa_label'          => $this->faixaLabel($d['faixa_ordem'] ?? null, $d['faixa_limite_inferior'] ?? null, $d['faixa_limite_superior'] ?? null),
            'valor_mensal'         => $d['valor_mensal'] ?? null,
            'valor_e_piso'         => (bool) ($d['valor_e_piso'] ?? false),
            'cobranca_mensal'      => $d['cobranca_mensal'] ?? null,
        ];
    }
}
