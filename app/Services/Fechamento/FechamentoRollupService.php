<?php

namespace App\Services\Fechamento;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\Servico;
use App\Models\ShopeeMetric;
use App\Services\AdmanService;
use App\Services\Metrics\MetricPeriodResolver;
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
 * `porEmpresa()` ganha `$faturamentoDaApi` (OPT-IN, default `false`). Quem
 * liga, desde o quick 260930-njd, são DOIS chamadores: o
 * `ConsolidarMesFechamento` (mês fechado, chamada ao vivo) e a tela do
 * fechamento via `AdminController::fechamentoDadosPorEmpresaAoVivo()` (mês
 * corrente, SÓ do cache). Motivo, medido em produção e não
 * presumido: cada linha de `adman_metrics` é escrita uma vez, na manhã
 * seguinte, e nunca mais revisitada — a Adman aplica ajustes retroativos
 * (devoluções, conciliação) que não voltam para o nosso banco. DESK DESIGN,
 * agosto/2026, com os 31 dias presentes (não é buraco de sync): SUM =
 * R$ 167.537,54 contra R$ 170.363,19 no `/performance`. Na população
 * Adman-driven inteira a subcontagem é sistemática (+3,4%, 34 de 48
 * empresas divergindo).
 *
 * Quick 261005-sm1 — o ESCOPO virou o mais simples possível: **toda empresa
 * com `cust_id` lê o faturamento da Adman**. Caíram os recortes por token ML
 * e por "as duas contas são o mesmo id" (ver o histórico completo no docblock
 * de `podeUsarApiDaAdman()` — eles nasceram de um defeito de CADASTRO, não da
 * API). Empresa sem `cust_id` continua de fora: não há o que chamar. Shopee
 * nunca muda — continua vindo de `shopee_metrics`.
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
     * Quick 260930-njd — a janela que se PEDE à Adman para `$mes`, que não é
     * sempre a mesma de `janela()`.
     *
     * A Adman é D-1: publica o consolidado do dia anterior às ~10h BRT. Num
     * mês FECHADO a janela inteira já é passado e as duas coincidem. No mês
     * CORRENTE `janela()` vai até HOJE — e pedir `/performance` incluindo hoje
     * devolve um dia pela metade, que muda de valor a cada hora e faria a
     * mesma tela mostrar números diferentes de manhã e de tarde. Aqui o fim é
     * clampado em ONTEM.
     *
     * Este método existe para ser a ÚNICA definição dessa janela: o
     * `fetchGrossBilling()` cacheia por `custId:dateFrom:dateTo:dia`, então o
     * comando que aquece (`adman:warm-fechamento`) e a leitura da tela
     * precisam pedir o intervalo EXATAMENTE igual — um dia de diferença e a
     * chave não casa, o cache fica eternamente frio e a tela cai no fallback
     * para sempre.
     *
     * `null` quando não existe nenhum dia fechado dentro da janela — é o dia
     * 1º do mês corrente, em que ontem ainda pertence ao mês passado. Nesse
     * caso não há o que perguntar à Adman.
     *
     * @return array{inicio: Carbon, fim: Carbon}|null
     */
    public function janelaDaAdman(string $mes): ?array
    {
        $janela = $this->janela($mes);
        $ontem  = Carbon::now()->subDay()->startOfDay();

        $fim = $janela['fim']->greaterThan($ontem) ? $ontem->copy() : $janela['fim']->copy();

        if ($fim->lessThan($janela['inicio'])) {
            return null;
        }

        return [
            'inicio' => $janela['inicio']->copy(),
            'fim'    => $fim,
        ];
    }

    /**
     * Quick 260930-njd — a MESMA regra de `podeUsarApiDaAdman()`, exposta
     * para quem está fora deste serviço (hoje só o `adman:warm-fechamento`).
     *
     * É um invólucro de propósito, não uma segunda implementação: quem aquece
     * o cache tem de aquecer exatamente as empresas que a tela vai consultar,
     * e duplicar o critério seria criar uma segunda régua que envelhece
     * sozinha. Quick 261005-sm1: o critério virou um só (ter `cust_id`), e o
     * invólucro continua existindo pelo mesmo motivo — o dia em que ele
     * voltar a ter condição, o aquecimento acompanha de graça.
     */
    public function podeLerFaturamentoDaAdman(Company $company): bool
    {
        return $this->podeUsarApiDaAdman($company);
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
     * pelo `/performance` da Adman. Quick 261005-sm1: em TODA empresa com
     * `cust_id` (o recorte por token ML / contas iguais caiu — ver
     * `podeUsarApiDaAdman()`). Exige `$companies` pela mesma razão que
     * `$somenteContratadas`: é de lá que vem o `cust_id`.
     *
     * Quick 260930-njd: o mês CORRENTE passou a usar a API também — mas SÓ do
     * cache (`getCachedGrossBillingsMany()`), nunca ao vivo, e pedindo a
     * janela de `janelaDaAdman()` (dia 1º até ONTEM, porque a Adman é D-1).
     * Cache frio = soma diária com fonte `soma_diaria_fallback`.
     *
     * `$apiSomenteDoCache` (default `false`) estende esse modo a QUALQUER
     * competência. Quem liga é quem roda dentro de um request — a tela do
     * fechamento, que calcula o mês corrente E o mês anterior (fechado) num
     * carregamento só; sem isto, o mês anterior faria dezenas de chamadas
     * HTTP sequenciais no meio da requisição. Fora do request
     * (`fechamento:consolidar-mes`) fica `false` e o mês fechado segue
     * chamando a Adman ao vivo, com a pausa de sempre — é ali que a
     * competência vira cobrança e o número não pode depender de aquecimento.
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
    public function porEmpresa(string $mes, ?Collection $companies = null, bool $somenteContratadas = false, bool $faturamentoDaApi = false, bool $apiSomenteDoCache = false): array
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

        // Quick 260930-njd — o mês corrente DEIXOU de ser proibido de usar a
        // API, mas entra por uma porta diferente: a leitura SÓ DO CACHE.
        //
        // O que mudou e por quê: a conferência de setembro/2026 contra a
        // Adman (mês ainda aberto) divergiu em quase toda empresa — amostra
        // de 12 empresas, 01/09–29/09, R$ 12.468.923,94 na nossa soma contra
        // R$ 12.910.546,59 na Adman (+3,5%, a Adman maior em 9 e menor em 2).
        // Dia a dia, mesma empresa: 01/09 guardado 24.770,86 contra 26.506,96
        // hoje; 28/09 guardado 15.933,13 contra 17.904,08 (+12,4%). A Adman
        // revisa dias já passados para os dois lados depois da nossa coleta, e
        // `adman:sync` grava D-1 uma vez e nunca volta. O total do período
        // pedido à Adman já vem com essas revisões aplicadas.
        //
        // ⚠️ Mas NUNCA ao vivo dentro do request: 84 chamadas HTTP sequenciais
        // num carregamento de tela é exatamente a forma como o `cache:clear`
        // de 2026-07-30 derrubou a produção (o dashboard passou a esperar a
        // Adman, as requisições lentas ocuparam os workers do php-fpm e até o
        // login parou). Cache frio cai para a soma diária com fonte
        // `soma_diaria_fallback`.
        //
        // Duas coisas ligam o modo cache-only, e a diferença importa:
        //
        // 1. `$apiSomenteDoCache` — pedido EXPLÍCITO do chamador. Quem pede é
        //    a tela (`AdminController::fechamentoDadosPorEmpresaAoVivo()`), e
        //    ela pede para OS DOIS meses que calcula: o mês anterior é
        //    competência FECHADA, e sem este pedido o ramo de mês fechado
        //    abaixo faria dezenas de chamadas HTTP no meio do carregamento —
        //    o problema que este modo existe para evitar.
        // 2. Mês corrente, sempre. Aqui o motivo é outro: `/performance` do
        //    mês em curso é o único intervalo cujo cache é aquecido de fora
        //    (`adman:warm-fechamento`), e chamá-lo ao vivo de qualquer
        //    lugar — inclusive do CLI — voltaria a comparar um intervalo que
        //    muda de valor a cada hora.
        $lerApiSomenteDoCache = $apiSomenteDoCache || $mes === Carbon::now()->format('Y-m');

        // A janela pedida à Adman NÃO é `$inicio..$fim`: no mês corrente ela
        // para em ontem (a Adman é D-1). `null` = nenhum dia fechado na
        // janela (dia 1º do mês) → não há o que perguntar.
        $janelaApi = $faturamentoDaApi ? $this->janelaDaAdman($mes) : null;
        $usarApi   = $faturamentoDaApi && $janelaApi !== null;

        // Quick 261005-sm1 — aqui havia um `loadMissing('mlToken')` para o
        // acessor `is_ml_driven` não fazer N+1 no laço de ~201 empresas. A
        // decisão deixou de olhar o token (`podeUsarApiDaAdman()` só pergunta
        // por `cust_id`, que vem das colunas da própria empresa), então a
        // consulta extra saiu junto. Se algum critério voltar a depender de
        // relação, o eager loading volta AQUI — nunca dentro do laço.

        // Modo cache-only: UMA leitura em lote (um round-trip, zero HTTP)
        // antes do laço, em vez de um `Cache::get` por empresa.
        $cacheDaApi = [];

        if ($usarApi && $lerApiSomenteDoCache && $companies !== null) {
            $custIdsParaLer = $companies
                ->filter(fn (Company $company) => $this->podeUsarApiDaAdman($company))
                ->map(fn (Company $company) => (string) $company->cust_id)
                ->filter(fn (string $custId) => $custId !== '')
                ->unique()
                ->values()
                ->all();

            if ($custIdsParaLer !== []) {
                // Marketplace fica no default 'meli', igual à chamada ao vivo
                // logo abaixo e igual ao comando de aquecimento — a chave de
                // cache inclui o marketplace, e divergir aqui deixaria o
                // cache eternamente frio.
                $cacheDaApi = $this->admanService->getCachedGrossBillingsMany(
                    $custIdsParaLer,
                    $janelaApi['inicio']->toDateString(),
                    $janelaApi['fim']->toDateString(),
                );
            }
        }

        // Cache frio: as empresas que ficaram sem o número da Adman entram
        // aqui e saem num ÚNICO aviso depois do laço. Um
        // `Log::warning` por empresa dentro de um carregamento de tela seriam
        // 84 linhas por visita — o aviso que aparece sempre deixa de ser aviso.
        $semNumeroDaAdman = [];

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

            // ── Quick 260911-eph / 260930-njd — a fonte do lado ML ───────
            // Só entra aqui se a chave está ligada, existe janela fechada para
            // pedir à Adman E o lado ML conta para esta empresa (com o recorte
            // por contrato ligado, não adianta chamar a API de quem não tem
            // plataforma ML elegível — o valor seria zerado logo abaixo).
            if ($usarApi && in_array('ml', $plataformasConsideradas, true)) {
                $company = $companiesPorId[$companyId] ?? null;

                if ($company !== null && $this->podeUsarApiDaAdman($company)) {
                    if ($lerApiSomenteDoCache) {
                        // Modo cache-only — SÓ o que o aquecimento já deixou
                        // pronto. Zero chamada HTTP dentro do request.
                        $valorApi = $cacheDaApi[(string) $company->cust_id]['value'] ?? null;
                    } else {
                        if ($chamadasApiFeitas > 0) {
                            usleep(self::PAUSA_ENTRE_CHAMADAS_API);
                        }
                        $chamadasApiFeitas++;

                        $valorApi = $this->admanService->fetchGrossBilling(
                            (string) $company->cust_id,
                            $janelaApi['inicio']->toDateString(),
                            $janelaApi['fim']->toDateString(),
                        );
                    }

                    if ($valorApi === null) {
                        // Fallback JAMAIS silencioso: o número continua
                        // saindo (a soma diária), mas sabendo-se que o certo
                        // era outro. O gate do comando conta estes casos.
                        $faturamentoFonte = FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK;

                        if ($lerApiSomenteDoCache) {
                            // Vai num aviso só, depois do laço.
                            $semNumeroDaAdman[] = "{$company->id} ({$company->name})";
                        } else {
                            Log::warning(
                                "[Fechamento] Faturamento da API indisponível para a empresa {$company->id} ({$company->name}) "
                                ."na competência {$mes} — caindo para a soma diária de adman_metrics.",
                                [
                                    'company_id'  => (int) $company->id,
                                    'cust_id'     => (string) $company->cust_id,
                                    'competencia' => $mes,
                                    'janela'      => $janelaApi['inicio']->toDateString().'..'.$janelaApi['fim']->toDateString(),
                                    'fonte'       => FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
                                ]
                            );
                        }
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

        // Quick 260930-njd — um aviso por CARREGAMENTO, com a lista de nomes.
        // Uma ou duas empresas aqui é a Adman tendo dado erro naquela conta; a
        // lista inteira é sinal de que `adman:warm-fechamento` não rodou hoje,
        // e é isso que precisa aparecer no log de uma vez só.
        if ($semNumeroDaAdman !== []) {
            Log::warning(
                '[Fechamento] O número da Adman ainda não estava disponível para '
                .count($semNumeroDaAdman)." empresa(s) na competência {$mes} — caindo para a soma diária de adman_metrics. "
                .'Conferir se o aquecimento (adman:warm-fechamento) rodou hoje.',
                [
                    'competencia' => $mes,
                    'janela_api'  => $janelaApi !== null
                        ? $janelaApi['inicio']->toDateString().'..'.$janelaApi['fim']->toDateString()
                        : null,
                    'empresas'    => $semNumeroDaAdman,
                    'fonte'       => FechamentoSnapshot::FONTE_SOMA_DIARIA_FALLBACK,
                ]
            );
        }

        return $resultado;
    }

    /**
     * Esta empresa pode ter o faturamento lido do `/performance` da Adman?
     *
     * ⚠️ **É A ÚNICA PORTA DESSA DECISÃO.** Quem está fora do serviço entra
     * por `podeLerFaturamentoDaAdman()`, que é só um invólucro desta. Nunca
     * replicar o critério em outro lugar.
     *
     * **Regra de hoje (quick 261005-sm1, 2026-10-05):**
     *
     * 1. Sem `cust_id` → **não**, não há o que chamar. São 62 das 187
     *    empresas ativas; seguem na soma diária de `adman_metrics`.
     * 2. Com `cust_id` → **sim**. Sem mais nenhuma condição.
     *
     * ─── Por que uma regra que foi criada de propósito foi revertida ────
     *
     * Até 2026-10-05 havia um terceiro corte: empresa `is_ml_driven` (token
     * ML ativo) só lia da API quando `adman_account_id` e `ml_store_id`
     * estavam os dois preenchidos e eram IGUAIS. Histórico, na ordem:
     *
     * - Quick 260911-eph: corte por `is_ml_driven` (nenhuma empresa com token
     *   ML lia da API), inferido de UM caso — a LAURA LAR, cuja conta Adman
     *   devolvia 99,5% menos que a nossa base.
     * - Quick 260911-jpx: o corte passou a ser "a conta Adman aponta para a
     *   MESMA loja do ML", porque o que a LAURA LAR tinha de especial não era
     *   o token (DESK DESIGN também tem) e sim os ids distintos
     *   (273196837 contra 433720509). Isso resgatou 58 empresas.
     * - **2026-09-15: descobriu-se que o defeito da LAURA LAR era o TOKEN do
     *   Mercado Livre**, que apontava para a conta da GRAN BELO — não a API
     *   da Adman (registrado em `.planning/learnings/fechamento-tabela-por-empresa.md`).
     *   A empresa foi desativada em 16/09. O recorte `ids-iguais` protegia de
     *   um defeito de **cadastro**, e cobrava por isso o número errado de
     *   outras empresas.
     *
     * Medição em produção em 2026-10-05, entre as 187 empresas ativas: 108 já
     * usavam a Adman, 62 não têm `cust_id` nenhum e **17 tinham conta e eram
     * recusadas**. Das 17, **16 não têm `adman_account_id`** — só
     * `ml_store_id`, que é justamente por onde `cust_id` consulta a Adman, e
     * a API responde normalmente (OUZOR TIME: R$ 654.533,87 numa chamada
     * real). Só a MAXIGOLD SUPLEMENTOS tem as duas contas, diferentes. O
     * custo do recorte em setembro/2026, com os números gravados:
     * MAXIGOLD 3.324,98 contra 119.411,57 da Adman; OUZOR TIME 583.611,24
     * contra 654.533,87.
     *
     * Decisão do usuário (2026-10-05): usar o número da Adman para toda
     * empresa que tenha conta lá, e tratar divergência de contas como
     * **aviso visível** (`contasDivergem()`), nunca como troca silenciosa por
     * um número pior.
     *
     * ⚠️ O que NÃO mudou e segue valendo:
     *
     * - Nada disso acontece com a chave `fechamento_faturamento_da_api_ativo`
     *   desligada — quem liga `faturamentoDaApi` é o chamador, e quem lê a
     *   chave é `ConsolidarMesFechamento` e `AdminController::fechamento()`.
     * - O fallback nunca silencioso: `cust_id` que a Adman não reconhece cai
     *   para a soma diária com fonte `soma_diaria_fallback`. Empresa nenhuma
     *   fica sem número.
     * - A régua de classificação (`FechamentoFaixaResolver::classificar()`,
     *   `CobrancaCalculator`) não foi tocada em nenhum dos três quicks —
     *   muda só QUEM pode ler o faturamento da API.
     */
    private function podeUsarApiDaAdman(Company $company): bool
    {
        // Única condição: existir conta a consultar. `cust_id` é o acessor de
        // `Company` (adman_account_id ?: ml_store_id) — não reimplementar a
        // prioridade aqui.
        return $company->cust_id !== null;
    }

    /**
     * Quick 261005-sm1 — esta empresa tem as DUAS contas cadastradas e elas
     * são diferentes?
     *
     * Este é o aviso que substituiu a trava `ids-iguais`: uma das duas contas
     * provavelmente está errada, e foi exatamente isso que aconteceu com a
     * LAURA LAR (token ML apontando para a conta da GRAN BELO). Em produção
     * em 2026-10-05, entre as empresas que a trava recusava, só a MAXIGOLD
     * SUPLEMENTOS cai aqui.
     *
     * ⚠️ **Não muda número nenhum.** O faturamento continua vindo da Adman;
     * isto existe para alguém CONFERIR o cadastro. Transformar de novo o
     * aviso em trava é voltar a cobrar o número errado de 17 empresas.
     *
     * ⚠️ O token ML NÃO entra no critério, de propósito: cadastro com duas
     * contas diferentes merece conferência com ou sem token, e amarrar o
     * aviso ao token recriaria a segunda régua que este quick acabou de
     * remover. Consequência aceita: empresas cuja divergência já é conhecida
     * e intencional (ADHARAPRINTSHOP e AVF_2K — ver
     * `Company::getCustIdAttribute()`) podem aparecer na lista. O aviso é
     * discreto e não bloqueia nada.
     *
     * Comparação como STRING com `!==`, mesma armadilha travada no quick
     * 260911-jpx ao contrário: `==` faria coerção numérica e `'051' == '51'`
     * diria "mesma conta". Id com zero à esquerda (ou espaço) é outra conta —
     * e é justamente o tipo de cadastro que se quer ver.
     */
    public function contasDivergem(Company $company): bool
    {
        return filled($company->adman_account_id)
            && filled($company->ml_store_id)
            && (string) $company->adman_account_id !== (string) $company->ml_store_id;
    }
}
