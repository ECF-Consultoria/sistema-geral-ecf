<?php

namespace App\Services\Fechamento;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\AdmanService;
use App\Services\Metrics\MetricPeriodResolver;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
 *
 * Quick 260911-eph — a soma diária deixa de ser a única fonte possível:
 * `porEmpresa()` ganha `$faturamentoDaApi` (OPT-IN, default `false`, quem
 * liga é SÓ `ConsolidarMesFechamento`). Motivo, medido em produção e não
 * presumido: cada linha de `adman_metrics` é escrita uma vez, na manhã
 * seguinte, e nunca mais revisitada — a Adman aplica ajustes retroativos
 * (devoluções, conciliação) que não voltam para o nosso banco. DESK DESIGN,
 * agosto/2026, com os 31 dias presentes (não é buraco de sync): SUM =
 * R$ 167.537,54 contra R$ 170.363,19 no `/performance`. Na população
 * Adman-driven inteira a subcontagem é sistemática (+3,4%, 34 de 48
 * empresas divergindo).
 *
 * ⚠️ O RECORTE que define o escopo: empresa `is_ml_driven` (token ML ativo)
 * NÃO usa a API. Para ela o `adman_metrics` é preenchido pelo sync do ML e
 * a conta Adman fica abandonada — o `/performance` não é régua. LAURA LAR
 * tem R$ 2,7 milhões em agosto na nossa base e a conta Adman dela devolve
 * R$ 12.966; aplicar a API nela destruiria o número certo. Empresa sem
 * `cust_id` também fica de fora (não há o que chamar). Shopee nunca muda —
 * continua vindo de `shopee_metrics`.
 */
class FechamentoRollupService
{
    /**
     * Pausa entre chamadas consecutivas ao `/performance` da Adman, em
     * microssegundos. São ~48 empresas por consolidação e a API já teve
     * incidente de rate-limit neste projeto (o gap de sync de 2026-06-12/13
     * atingiu 78% das empresas). Ritmo deliberado, não otimização pendente.
     */
    private const PAUSA_ENTRE_CHAMADAS_API = 200_000;

    public function __construct(
        private MetricPeriodResolver $periodResolver,
        private AdmanService $admanService,
    ) {
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
     * Quick 260911-eph: `$faturamentoDaApi` (default `false`, modo atual
     * intocado — nenhuma chamada HTTP) troca a fonte do `faturamento_ml`
     * pelo `/performance` da Adman nas empresas que NÃO são `is_ml_driven`
     * e têm `cust_id`. Exige `$companies` pela mesma razão que
     * `$somenteContratadas`: é de lá que vêm `cust_id` e o token ML.
     *
     * ⚠️ **Só mês FECHADO.** Com a chave ligada numa competência que é o mês
     * corrente, a API é ignorada e vale a soma diária: a janela do mês
     * corrente vai do dia 1 até HOJE, e pedir `/performance` de mês
     * incompleto compara coisa diferente a cada hora do dia.
     *
     * ⚠️ **Fallback nunca silencioso.** API que falha ou devolve `null` cai
     * para o `SUM(revenue)` com fonte `soma_diaria_fallback` e um
     * `Log::warning` nomeando empresa e competência — um mês inteiro em
     * fallback não pode ter a cara de um mês normal.
     *
     * `faturamento_fonte` vai em TODO resultado ('api' | 'soma_diaria' |
     * 'soma_diaria_fallback'); com a chave desligada é sempre
     * `soma_diaria`.
     *
     * @param  Collection<int, Company>|null  $companies
     * @return array<int, array{faturamento_ml: float|null, faturamento_shopee: float|null, faturamento_total: float|null, plataformas_consideradas: array<int, string>, faturamento_fonte: string}>
     */
    public function porEmpresa(string $mes, ?Collection $companies = null, bool $somenteContratadas = false, bool $faturamentoDaApi = false): array
    {
        if ($somenteContratadas && $companies === null) {
            throw new InvalidArgumentException(
                'porEmpresa(): somenteContratadas=true exige $companies informado — é de lá que vêm os contratos que decidem a elegibilidade por plataforma.'
            );
        }

        if ($faturamentoDaApi && $companies === null) {
            throw new InvalidArgumentException(
                'porEmpresa(): faturamentoDaApi=true exige $companies informado — é de lá que vêm o cust_id e o token ML que decidem quem pode usar a API da Adman.'
            );
        }

        $janela = $this->janela($mes);
        $inicio = $janela['inicio'];
        $fim    = $janela['fim'];

        // ⚠️ Mês corrente NUNCA usa a API, mesmo com a chave ligada — a
        // janela vai até hoje e o `/performance` de mês incompleto muda de
        // resposta a cada hora. A checagem é sobre `$mes`, não sobre a
        // janela, para espelhar exatamente a regra de `janela()` acima.
        $usarApi = $faturamentoDaApi && $mes !== Carbon::now()->format('Y-m');

        // Uma query para todos os tokens — o acessor `is_ml_driven` lê
        // `mlToken` e faria N+1 no laço de ~201 empresas sem isto. Guard de
        // tipo porque `$companies` é tipado como coleção genérica; só a
        // Eloquent Collection tem `loadMissing()`.
        if ($usarApi && $companies instanceof EloquentCollection) {
            $companies->loadMissing('mlToken');
        }

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

        // Conta só as chamadas EFETIVAMENTE feitas — o ritmo é entre
        // chamadas, não entre empresas (empresa ML-driven não gasta pausa).
        $chamadasApiFeitas = 0;

        foreach ($idsParaMontar as $companyId) {
            $faturamentoMl     = $porEmpresaMl->has($companyId) ? (float) $porEmpresaMl[$companyId]->faturamento : null;
            $faturamentoShopee = $porEmpresaShopee->has($companyId) ? (float) $porEmpresaShopee[$companyId]->faturamento : null;
            $faturamentoFonte  = FechamentoSnapshot::FONTE_SOMA_DIARIA;

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

            // ── Quick 260911-eph — a fonte do lado ML ─────────────────────
            // Só entra aqui se a chave está ligada, a competência é mês
            // fechado E o lado ML conta para esta empresa (com o recorte por
            // contrato ligado, não adianta chamar a API de quem não tem
            // plataforma ML elegível — o valor seria zerado logo abaixo).
            if ($usarApi && in_array('ml', $plataformasConsideradas, true)) {
                $company = $companiesPorId[$companyId] ?? null;

                if ($company !== null && $this->podeUsarApiDaAdman($company)) {
                    if ($chamadasApiFeitas > 0) {
                        usleep(self::PAUSA_ENTRE_CHAMADAS_API);
                    }
                    $chamadasApiFeitas++;

                    $valorApi = $this->admanService->fetchGrossBilling(
                        (string) $company->cust_id,
                        $inicio->toDateString(),
                        $fim->toDateString(),
                    );

                    if ($valorApi === null) {
                        // Fallback JAMAIS silencioso: o número continua
                        // saindo (a soma diária), mas sabendo-se que o certo
                        // era outro. O gate do comando conta estes casos.
                        $faturamentoFonte = FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK;

                        Log::warning(
                            "[Fechamento] Faturamento da API indisponível para a empresa {$company->id} ({$company->name}) "
                            ."na competência {$mes} — caindo para a soma diária de adman_metrics.",
                            [
                                'company_id'  => (int) $company->id,
                                'cust_id'     => (string) $company->cust_id,
                                'competencia' => $mes,
                                'janela'      => $inicio->toDateString().'..'.$fim->toDateString(),
                                'fonte'       => FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
                            ]
                        );
                    } else {
                        // A API é a régua para esta empresa, inclusive quando
                        // devolve MENOS que a soma diária (é justamente o que
                        // ajuste retroativo de devolução faz) e inclusive
                        // quando a nossa soma não existe (sync que pulou
                        // dias). Zero explícito vindo da Adman é resposta,
                        // não ausência — mas zero contra soma positiva é
                        // suspeita de conta abandonada sem token ML, então
                        // fica registrado sem alterar o número (mudar a
                        // política aqui seria inventar régua nova num valor
                        // que vira cobrança).
                        if ($valorApi == 0.0 && $faturamentoMl !== null && $faturamentoMl > 0.0) {
                            Log::warning(
                                "[Fechamento] API da Adman devolveu faturamento ZERO para a empresa {$company->id} ({$company->name}) "
                                ."na competência {$mes}, mas a soma diária é {$faturamentoMl} — conferir se a conta Adman foi abandonada.",
                                [
                                    'company_id'   => (int) $company->id,
                                    'cust_id'      => (string) $company->cust_id,
                                    'competencia'  => $mes,
                                    'soma_diaria'  => $faturamentoMl,
                                ]
                            );
                        }

                        $faturamentoMl    = (float) $valorApi;
                        $faturamentoFonte = FechamentoSnapshot::FONTE_API;
                    }
                }
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
                'faturamento_fonte'         => $faturamentoFonte,
            ];
        }

        return $resultado;
    }

    /**
     * Quick 260911-jpx — esta empresa pode ter o faturamento lido do
     * `/performance` da Adman?
     *
     * O corte NÃO é o token ML. É a conta Adman apontar para a MESMA loja do
     * ML. O quick anterior (260911-eph) inferiu a regra de UM caso só — a
     * LAURA LAR — e concluiu "empresa `ml_driven` tem conta Adman
     * abandonada". Errado: o que a LAURA LAR tem de especial não é o token
     * ML, é a conta Adman apontar para outra loja.
     *
     *   | empresa     | adman_account_id | ml_store_id |                 |
     *   |-------------|------------------|-------------|-----------------|
     *   | DESK DESIGN | 51493328         | 51493328    | mesma loja      |
     *   | LAURA LAR   | 273196837        | 433720509   | contas distintas|
     *
     * Medição em produção (2026-09-11) das 60 empresas `ml_driven` que têm
     * conta Adman:
     *
     *   | grupo          | empresas | o que a API devolve                   |
     *   |----------------|----------|---------------------------------------|
     *   | ids IGUAIS     | 58       | 53 entre -1% e +15% (ajuste retroativo)|
     *   | ids DIFERENTES | 2        | MAXIGOLD +2118%, LAURA LAR -99,5%     |
     *
     * As duas únicas anomalias são exatamente as de id trocado — o corte por
     * `is_ml_driven` jogava fora 58 empresas boas para se proteger de 2, e
     * deixava de fora justamente a DESK DESIGN, o caso que originou o
     * trabalho (R$ 167.537,54 na soma diária contra R$ 170.363,19 na Adman).
     *
     * Os três cortes de hoje:
     *
     * 1. Sem `cust_id` → **não**, não há o que chamar (são quase todas
     *    empresas de teste).
     * 2. Não `ml_driven` → **sim**, caminho Adman puro, inalterado. São 53
     *    empresas em cobrança viva; este ramo não se toca.
     * 3. `ml_driven` → **só** quando `adman_account_id` e `ml_store_id`
     *    estão os dois preenchidos e são IGUAIS.
     *
     * Duas armadilhas travadas de propósito:
     *
     * - Comparação como STRING com `===`. Os dois campos são texto; `==`
     *   faria coerção numérica e `'051' == '51'` daria true. Id com zero à
     *   esquerda (ou espaço) é outra loja, não a mesma.
     * - `filled()` nos dois lados. São 16 empresas `ml_driven` em produção
     *   com token ML e sem conta Adman própria; sem o `filled()`,
     *   `null === null` viraria "pode usar" e elas chamariam a API à toa.
     *
     * Efeito medido em agosto/2026, autorizado pelo usuário em 2026-09-11:
     * 51 → ~109 empresas pela API, +R$ 1.016.802,46 de faturamento somado e
     * 2 empresas mudando de faixa (CAMILLO PARTS MATRIZ e LUCCAUTO.COM,
     * R$ 3.000 → R$ 4.500). A régua de classificação em si
     * (`FechamentoFaixaResolver`, `CobrancaCalculator`) não foi tocada —
     * muda só QUEM pode ler o faturamento da API.
     */
    private function podeUsarApiDaAdman(Company $company): bool
    {
        if ($company->cust_id === null) {
            return false;                       // nada a chamar
        }

        if (! $company->is_ml_driven) {
            return true;                        // caminho Adman puro, inalterado
        }

        // ml_driven: só quando a conta Adman acompanha A MESMA loja do ML.
        return filled($company->adman_account_id)
            && filled($company->ml_store_id)
            && (string) $company->adman_account_id === (string) $company->ml_store_id;
    }
}
