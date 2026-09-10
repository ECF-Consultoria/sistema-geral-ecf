<?php

namespace App\Services\Fechamento;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\Metrics\MetricPeriodResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * FechamentoRollupService — responde "quanto uma empresa faturou nesta
 * competência?" (Fase 137, D-05, D-06, D-07).
 *
 * D-06 mata o acumulativo: toda competência é mês-calendário FECHADO — mês
 * corrente vai do dia 1 até hoje, mês passado é o mês inteiro. Nunca a
 * janela móvel retroativa de 30 dias (a que este serviço substitui em
 * `AdminController::fechamento()`).
 *
 * D-05 soma ML (`adman_metrics`) + Shopee (`shopee_metrics`) numa faixa
 * única. D-07 garante que Shopee entra no fechamento mesmo sem ML.
 *
 * Fase 141 Plano 01 (D-02): o D-05 acima passa a ter um RECORTE — só entra
 * na soma o faturamento da plataforma em que a empresa tem serviço
 * CONTRATADO e ATIVO cobrado por tabela progressiva (`plataformasElegiveis()`).
 * Mentoria, por exemplo, não tem tabela — faturamento ligado só a ela não
 * conta. O recorte é OPT-IN via `$somenteContratadas` em `porEmpresa()`; o
 * modo padrão (`false`) continua sendo o D-05 original, sem olhar contrato
 * nenhum. Quem liga o recorte na prática é o chamador atrás da flag de
 * corte (plano 141-03) — este plano só entrega a capacidade.
 *
 * Não usa o serviço de diff HTTP-first do módulo de bônus (orientado a
 * variação percentual — chamada de rede desnecessária todo dia 1) nem a
 * tabela de faturamento mensal legada de ML (fica com o valor rolling do
 * momento em que o mês virou, nunca re-sincronizado — ver
 * 137-RESEARCH.md §1). Fonte é a soma direta do faturamento diário de
 * `adman_metrics`, mesmo padrão que `AdminController::fechamento()` já usa
 * para mês passado.
 */
class FechamentoRollupService
{
    public function __construct(private MetricPeriodResolver $periodResolver)
    {
    }

    /**
     * Janela de mês-calendário fechado para `$mes` ('YYYY-MM'). Delega a
     * `MetricPeriodResolver` — ÚNICA implementação de D-06, nunca reescrever
     * Carbon à mão.
     *
     * @return array{inicio: Carbon, fim: Carbon}
     */
    public function janela(string $mes): array
    {
        // Mês corrente usa 'current_month' (current_end = hoje, clampado —
        // nunca 30 dias para trás). Mês fechado usa o próprio 'YYYY-MM'
        // (current_end = fim do mês calendário completo).
        $periodKey = $mes === Carbon::now()->format('Y-m') ? 'current_month' : $mes;

        $periodo = $this->periodResolver->resolve(['period_key' => $periodKey]);

        return [
            // NUNCA Carbon::createFromFormat('Y-m', ...) sem o dia — sem o
            // dia explícito o PHP preenche com o dia de hoje e estoura para
            // o mês seguinte quando o mês alvo tem menos dias (armadilha
            // documentada em ConsolidarMesDesempenho.php linhas 118-127).
            // Aqui não se aplica o risco: current_start/current_end já vêm
            // como string 'Y-m-d' completa do resolver.
            'inicio' => Carbon::createFromFormat('Y-m-d', $periodo['current_start'])->startOfDay(),
            'fim'    => Carbon::createFromFormat('Y-m-d', $periodo['current_end'])->startOfDay(),
        ];
    }

    /**
     * Fase 141 Plano 01 (D-02): quais plataformas ('ml' | 'shopee') esta
     * empresa tem HABILITADAS para entrar na soma que define a faixa —
     * segundo os contratos ATIVOS de serviço com `usa_tabela_progressiva =
     * true` (Mentoria, por exemplo, não tem tabela — não habilita nada).
     *
     * O critério por serviço é em OU entre duas fontes, porque nenhuma
     * sozinha é garantida: `plataforma` é texto preenchido À MÃO na tela
     * administrativa (pode nascer nulo ou trazer as duas plataformas no
     * mesmo campo, ex. "Mercado Livre e Shopee") e `setor` é a rede de
     * segurança (sempre um dos valores do enum). Mesmo espírito do
     * comentário de `escolherServicoCandidato()` em
     * `FechamentoFaixaResolver` — não confiar cegamente numa fonte só. Por
     * isso o MESMO serviço pode habilitar as DUAS plataformas ao mesmo
     * tempo; não é `elseif`.
     *
     * `loadMissing()` é defesa contra N+1 para quem não fez eager loading —
     * os chamadores atuais do rollup (`ConsolidarMesFechamento`,
     * `AdminController::fechamento()`, `EnviarRelatorioFechamentoJob`) já
     * carregam `contratosServico.servico`, então nenhuma query nova entra no
     * laço de ~201 empresas em produção.
     *
     * @return array<int, string> subconjunto de ['ml', 'shopee'], sem repetição
     */
    public function plataformasElegiveis(Company $company): array
    {
        $company->loadMissing('contratosServico.servico');

        $plataformas = [];

        foreach ($company->contratosServico as $contrato) {
            if (! $contrato->ativo) {
                continue;
            }

            $servico = $contrato->servico;

            if ($servico === null || ! $servico->usa_tabela_progressiva) {
                continue;
            }

            $plataformaTexto = mb_strtolower((string) ($servico->plataforma ?? ''));

            if (str_contains($plataformaTexto, 'shopee') || $servico->setor === Servico::SETOR_SHOPEE) {
                $plataformas['shopee'] = 'shopee';
            }

            if (str_contains($plataformaTexto, 'mercado livre') || $servico->setor === Servico::SETOR_PERFORMANCE) {
                $plataformas['ml'] = 'ml';
            }
        }

        return array_values($plataformas);
    }

    /**
     * Faturamento ML + Shopee por empresa, na janela de mês-calendário de
     * `$mes`. Duas queries agregadas (nunca N+1).
     *
     * Quando `$companies` é informado, o resultado tem UMA entrada por
     * empresa da coleção — mesmo as sem nenhuma métrica no mês (com as três
     * chaves de faturamento nulas: ausência é estado distinto de "faturou
     * zero", nunca 0.0). Quando `$companies` é omitido, o resultado só
     * contém empresas com pelo menos uma linha de métrica no mês.
     *
     * Fase 141 Plano 01 (D-02): `$somenteContratadas` (default `false`, modo
     * atual intocado) liga o recorte por plataforma contratada —
     * `plataformasElegiveis()`. Quando ligado, o lado de plataforma NÃO
     * elegível é zerado para `null` ANTES de compor o total (nunca somar e
     * depois subtrair) e `faturamento_total` é recalculado pela mesma regra
     * de sempre. Exige `$companies` — é de lá que vêm os contratos; sem
     * empresa não há como saber o que está contratado.
     *
     * `plataformas_consideradas` vai em TODO resultado: no modo atual vale
     * `['ml', 'shopee']` (o que o método de fato considera hoje), no modo
     * novo vale o retorno de `plataformasElegiveis()` para aquela empresa.
     *
     * @param  Collection<int, Company>|null  $companies
     * @return array<int, array{faturamento_ml: float|null, faturamento_shopee: float|null, faturamento_total: float|null, plataformas_consideradas: array<int, string>}>
     */
    public function porEmpresa(string $mes, ?Collection $companies = null, bool $somenteContratadas = false): array
    {
        if ($somenteContratadas && $companies === null) {
            throw new InvalidArgumentException(
                'porEmpresa(): somenteContratadas=true exige $companies informado — é de lá que vêm os contratos que decidem a elegibilidade por plataforma.'
            );
        }

        $janela = $this->janela($mes);
        $inicio = $janela['inicio'];
        $fim    = $janela['fim'];

        $mlQuery = AdmanMetric::whereBetween('reference_date', [$inicio, $fim])
            ->whereNotNull('revenue')
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id');

        // whereDate (não whereBetween): reference_date é persistido como
        // datetime ('Y-m-d 00:00:00'); whereBetween puro compararia como
        // STRING contra a borda 'Y-m-d' e excluiria o último dia do mês.
        $shopeeQuery = ShopeeMetric::whereDate('reference_date', '>=', $inicio->toDateString())
            ->whereDate('reference_date', '<=', $fim->toDateString())
            ->selectRaw('company_id, SUM(revenue) as faturamento')
            ->groupBy('company_id');

        if ($companies !== null) {
            $companyIds = $companies->pluck('id')->map(fn ($id) => (int) $id)->values();
            $mlQuery->whereIn('company_id', $companyIds);
            $shopeeQuery->whereIn('company_id', $companyIds);
        }

        $porEmpresaMl     = $mlQuery->get()->keyBy('company_id');
        $porEmpresaShopee = $shopeeQuery->get()->keyBy('company_id');

        $idsParaMontar = $companies !== null
            ? $companies->pluck('id')->map(fn ($id) => (int) $id)->unique()->values()
            : $porEmpresaMl->keys()->merge($porEmpresaShopee->keys())->map(fn ($id) => (int) $id)->unique()->values();

        // Só existe quando $companies foi informado (guard acima já garante
        // isso para $somenteContratadas=true) — mapa id → Company para
        // resolver plataformasElegiveis() sem re-consultar o banco.
        $companiesPorId = $companies?->keyBy(fn ($company) => (int) $company->id);

        $resultado = [];

        foreach ($idsParaMontar as $companyId) {
            $faturamentoMl     = $porEmpresaMl->has($companyId) ? (float) $porEmpresaMl[$companyId]->faturamento : null;
            $faturamentoShopee = $porEmpresaShopee->has($companyId) ? (float) $porEmpresaShopee[$companyId]->faturamento : null;

            if ($somenteContratadas) {
                $plataformasConsideradas = $this->plataformasElegiveis($companiesPorId[$companyId]);

                // Zera para null ANTES de compor o total — nunca somar tudo
                // e depois subtrair a plataforma não elegível.
                if (! in_array('ml', $plataformasConsideradas, true)) {
                    $faturamentoMl = null;
                }

                if (! in_array('shopee', $plataformasConsideradas, true)) {
                    $faturamentoShopee = null;
                }
            } else {
                // Modo atual: o rollup considera as duas plataformas sem
                // olhar contrato nenhum (D-05 original).
                $plataformasConsideradas = ['ml', 'shopee'];
            }

            // "Sem faturamento" e "faturou zero" são estados diferentes —
            // total só existe quando pelo menos um dos dois lados existe.
            $faturamentoTotal = ($faturamentoMl !== null || $faturamentoShopee !== null)
                ? ($faturamentoMl ?? 0.0) + ($faturamentoShopee ?? 0.0)
                : null;

            $resultado[$companyId] = [
                'faturamento_ml'            => $faturamentoMl,
                'faturamento_shopee'        => $faturamentoShopee,
                'faturamento_total'         => $faturamentoTotal,
                'plataformas_consideradas'  => $plataformasConsideradas,
            ];
        }

        return $resultado;
    }
}
