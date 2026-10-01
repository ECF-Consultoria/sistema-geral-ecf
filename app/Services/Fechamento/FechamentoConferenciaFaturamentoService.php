<?php

namespace App\Services\Fechamento;

use App\Models\AdmanMetric;
use App\Models\Company;
use App\Models\FechamentoSnapshot;
use App\Models\ShopeeMetric;
use Illuminate\Support\Carbon;

/**
 * Quick 261001-gi1 — responde a UMA pergunta: "o fechamento que está
 * gravado nesta competência ainda bate com o faturamento de agora?"
 *
 * POR QUE ESTE SERVIÇO EXISTE (incidente real de 2026-10-01, produção)
 * ────────────────────────────────────────────────────────────────────
 * Setembro foi consolidado às 10:23. O sync da Shopee rodou às 10:42 e
 * reescreveu o mês inteiro de 19 empresas. A competência ficou gravada com
 * faturamento de Shopee incompleto e NINGUÉM foi avisado — quem descobriu
 * foi o usuário, à mão, estranhando uma empresa:
 *
 *   | empresa                | gravado      | soma real depois do sync |
 *   |------------------------|--------------|--------------------------|
 *   | Gabs Folheados (#395)  | 6.378,91     | 40.154,54   (6x)         |
 *   | GENUINEAUTOMOTIVE      | 1.231.685,86 | 1.278.335,84             |
 *   | MPozenato              | 1.568.239,37 | 1.612.024,72             |
 *
 * Refeito às 11:38 tudo bateu e nenhuma faixa mudou — DESTA VEZ. A Gabs
 * saltou 6x e continuou na mesma faixa por sorte; com outro valor a
 * cobrança sairia errada e ninguém saberia. O mesmo risco vale para a
 * Adman (`adman:sync` 11:00, `adman:warm-fechamento` 11:50,
 * `adman:reler-dias` 19:00): fechar antes disso grava número velho.
 *
 * ⛔ READ-ONLY, e isto é regra, não detalhe: o serviço RELATA, nunca
 * conserta. Refazer uma competência é ato humano com motivo registrado —
 * reconsolidar sozinho em cima de uma divergência tiraria a decisão de
 * quem responde pela cobrança.
 *
 * ⚠️ O faturamento "de agora" vem do MESMO `FechamentoRollupService::porEmpresa()`
 * que a consolidação usa, com as MESMAS flags. Uma segunda fórmula de
 * faturamento aqui divergiria da primeira com o tempo e a conferência
 * passaria a mentir — exatamente o oposto do que ela existe para fazer.
 *
 * ── O que é divergência e o que é urgência ──────────────────────────
 *
 * A cobrança é o `valor` da FAIXA (`CobrancaCalculator::mensalidade()` com
 * classificação devolve `$classificacao['valor']`, nada proporcional ao
 * faturamento). Então:
 *
 * - faturamento diferente, MESMA faixa  → informação (dinheiro não muda)
 * - faturamento diferente, faixa MUDARIA → urgência (a cobrança está errada)
 *
 * A faixa é conferida pelos LIMITES CONGELADOS na própria linha
 * (`faixa_limite_inferior`/`faixa_limite_superior`), nunca reclassificando
 * pela tabela de hoje: a tabela pode ter sido editada depois do fechamento,
 * e aí a resposta seria sobre outra pergunta. `FechamentoFaixaResolver`
 * fica intocado.
 *
 * ── Por que existe `nao_comparaveis` ────────────────────────────────
 *
 * Com a chave `fechamento_faturamento_da_api_ativo` LIGADA, o lado ML de
 * uma linha pode ter sido gravado com o total do `/performance` da Adman.
 * Recalcular aqui sem chamar a Adman devolveria a soma diária — comparar os
 * dois é comparar réguas diferentes e acusaria divergência de ~3,5% em toda
 * empresa, todo dia. Essas linhas saem como NÃO CONFERIDAS (com o motivo),
 * nunca como divergentes. Quem quiser a conferência exata do lado ML passa
 * `$permitirChamadasApi` — e aí sim há chamada HTTP, de propósito e a pedido.
 *
 * @see App\Console\Commands\VerificarConsolidacaoFechamento
 * @see App\Console\Commands\ConsolidarMesFechamento
 */
class FechamentoConferenciaFaturamentoService
{
    /**
     * Mesma tolerância da conferência de soma de grupo que já existe em
     * `VerificarConsolidacaoFechamento` — um centavo.
     */
    public const TOLERANCIA = 0.01;

    public function __construct(
        private FechamentoRollupService $rollupService,
        private FechamentoRegraTabela $regra,
        private FechamentoFonteFaturamento $fonteFaturamento,
    ) {
    }

    /**
     * Confere a competência `$mes` ('YYYY-MM') por RECONSULTA. Nenhuma
     * escrita, nenhum dispatch de job.
     *
     * `$permitirChamadasApi = false` (default) significa ZERO HTTP: o
     * recálculo lê a Adman só do cache já aquecido. Com `true`, o lado ML é
     * conferido ao vivo — mais exato, mais lento, e sujeito ao limite de
     * 10 rpm da Adman.
     *
     * @return array{
     *     mes: string,
     *     mes_referencia: string,
     *     mes_fechado: bool,
     *     congelado: bool,
     *     fechado_em: ?string,
     *     com_api: bool,
     *     linhas_gravadas: int,
     *     linhas_conferidas: int,
     *     divergentes: array<int, array<string, mixed>>,
     *     nao_comparaveis: array<int, array<string, mixed>>,
     *     total_divergentes: int,
     *     total_faixas_mudariam: int,
     *     dado_mudou_depois: bool,
     *     dado_atualizado_em: ?string,
     *     dado_atualizado_em_por_plataforma: array{ml: ?string, shopee: ?string}
     * }
     */
    public function conferir(string $mes, bool $permitirChamadasApi = false): array
    {
        $mesReferencia = Carbon::createFromFormat('Y-m-d', $mes.'-01')->startOfMonth();
        $mesStr        = $mesReferencia->toDateString();
        $mesFechado    = $mes !== Carbon::now()->format('Y-m');

        $gravadas = FechamentoSnapshot::query()
            ->whereDate('mes_referencia', $mesStr)
            ->where('origem', FechamentoSnapshot::ORIGEM_CONSOLIDAR_MES)
            ->get();

        $fechadoEm = $gravadas
            ->pluck('gerado_em')
            ->filter()
            ->map(fn ($d) => Carbon::parse($d))
            ->sort()
            ->last();

        $dadoRecente = $this->dadoMaisRecenteDoMes($mes);

        $base = [
            'mes'                   => $mes,
            'mes_referencia'        => $mesStr,
            'mes_fechado'           => $mesFechado,
            'congelado'             => $gravadas->isNotEmpty(),
            'fechado_em'            => $fechadoEm?->toIso8601String(),
            'com_api'               => false,
            'linhas_gravadas'       => $gravadas->count(),
            'linhas_conferidas'     => 0,
            'divergentes'           => [],
            'nao_comparaveis'       => [],
            'total_divergentes'     => 0,
            'total_faixas_mudariam' => 0,
            // "Mudou depois" é só CONTEXTO (a hora dos dois lados), nunca o
            // critério do aviso — ver `dadoMaisRecenteDoMes()`.
            'dado_mudou_depois'                 => $fechadoEm !== null
                && $dadoRecente['quando'] !== null
                && $dadoRecente['quando']->greaterThan($fechadoEm),
            'dado_atualizado_em'                => $dadoRecente['quando']?->toIso8601String(),
            'dado_atualizado_em_por_plataforma' => [
                'ml'     => $dadoRecente['ml']?->toIso8601String(),
                'shopee' => $dadoRecente['shopee']?->toIso8601String(),
            ],
        ];

        if ($gravadas->isEmpty()) {
            return $base;
        }

        // Empresas das linhas GRAVADAS (não as "do mês" de hoje): a pergunta
        // é sobre o que está gravado. Eager loading igual ao da consolidação —
        // `contratosServico.servico` alimenta `plataformasElegiveis()` e
        // `mlToken` o corte de quem pode ler a Adman.
        $companies = Company::query()
            ->whereIn('id', $gravadas->pluck('company_id')->unique()->values())
            ->with([
                'contratosServico' => fn ($q) => $q->where('ativo', true)->with('servico'),
                'mlToken',
            ])
            ->get();

        // As MESMAS flags de `ConsolidarMesFechamento::handle()` — recalcular
        // com régua diferente da que gravou produziria divergência inventada.
        $regraNova        = $this->regra->ativa();
        $faturamentoDaApi = $mesFechado && $this->fonteFaturamento->ativa();

        $atual = $this->rollupService->porEmpresa(
            $mes,
            $companies,
            somenteContratadas: $regraNova,
            faturamentoDaApi: $faturamentoDaApi,
            // Sem permissão explícita, só o cache: um verificador que dispara
            // dezenas de chamadas HTTP sem avisar é o mesmo erro que derrubou
            // a produção em 2026-07-30.
            apiSomenteDoCache: ! $permitirChamadasApi,
        );

        $base['com_api'] = $faturamentoDaApi && $permitirChamadasApi;

        $divergentes    = [];
        $naoComparaveis = [];
        $conferidas     = 0;

        foreach ($gravadas as $linha) {
            $calculado = $atual[$linha->company_id] ?? null;

            $gravadoTotal = $linha->faturamento_total !== null ? (float) $linha->faturamento_total : null;

            if ($calculado === null || ($gravadoTotal !== null && $calculado['faturamento_total'] === null)) {
                // Linha gravada com número e sem nenhuma métrica agora: não
                // há com o que comparar. Não é divergência — é falta de dado
                // para conferir, e dizer isso é diferente de acusar erro.
                $naoComparaveis[] = [
                    'company_id'   => (int) $linha->company_id,
                    'company_name' => $linha->company_name,
                    'motivo'       => 'sem_metrica_agora',
                ];

                continue;
            }

            $conferidas++;

            $gravadoMl     = $linha->faturamento_ml !== null ? (float) $linha->faturamento_ml : null;
            $gravadoShopee = $linha->faturamento_shopee !== null ? (float) $linha->faturamento_shopee : null;
            $atualMl       = $calculado['faturamento_ml'] !== null ? (float) $calculado['faturamento_ml'] : null;
            $atualShopee   = $calculado['faturamento_shopee'] !== null ? (float) $calculado['faturamento_shopee'] : null;

            // Fonte `null` (linhas anteriores à coluna) conta como "não veio
            // da API": a coluna nasceu JUNTO com o caminho da API, então uma
            // linha sem fonte é de quando esse caminho não existia.
            $gravadoVeioDaApi = $linha->faturamento_fonte === FechamentoSnapshot::FONTE_API;
            $atualVeioDaApi   = ($calculado['faturamento_fonte'] ?? null) === FechamentoSnapshot::FONTE_API;
            $mlConferido      = $gravadoVeioDaApi === $atualVeioDaApi;

            if (! $mlConferido) {
                // Régua diferente dos dois lados: o lado ML fica de fora da
                // comparação e o lado Shopee continua valendo (foi justamente
                // a Shopee que causou o incidente).
                $naoComparaveis[] = [
                    'company_id'   => (int) $linha->company_id,
                    'company_name' => $linha->company_name,
                    'motivo'       => $gravadoVeioDaApi ? 'gravado_com_api_sem_conferir' : 'atual_com_api_sem_conferir',
                ];

                $atualMl = $gravadoMl;
            }

            $diferencaMl     = $this->diferenca($gravadoMl, $atualMl);
            $diferencaShopee = $this->diferenca($gravadoShopee, $atualShopee);

            if ($diferencaMl === null && $diferencaShopee === null) {
                continue;
            }

            // Total "de agora" recomposto dos dois lados já conferidos — nunca
            // o total do rollup quando o lado ML ficou de fora (seria somar
            // réguas diferentes).
            $atualTotal = ($atualMl !== null || $atualShopee !== null)
                ? ($atualMl ?? 0.0) + ($atualShopee ?? 0.0)
                : null;

            $causa = match (true) {
                $diferencaMl !== null && $diferencaShopee !== null => 'ambas',
                $diferencaShopee !== null                         => 'shopee',
                default                                           => 'ml',
            };

            $divergentes[] = [
                'company_id'     => (int) $linha->company_id,
                'company_name'   => $linha->company_name,
                'gravado'        => $gravadoTotal,
                'atual'          => $atualTotal,
                'diferenca'      => $atualTotal !== null && $gravadoTotal !== null ? $atualTotal - $gravadoTotal : null,
                'gravado_ml'     => $gravadoMl,
                'atual_ml'       => $atualMl,
                'gravado_shopee' => $gravadoShopee,
                'atual_shopee'   => $atualShopee,
                'causa'          => $causa,
                'ml_conferido'   => $mlConferido,
                'faixa_gravada'  => $linha->faixa_aplicada,
                'faixa_ordem'    => $linha->faixa_ordem !== null ? (int) $linha->faixa_ordem : null,
                'faixa_mudaria'  => $this->faixaMudaria($linha, $atualTotal),
            ];
        }

        // Quem muda de dinheiro primeiro; depois a maior diferença em módulo.
        usort($divergentes, function (array $a, array $b) {
            $peso = ((int) ($b['faixa_mudaria'] === true)) <=> ((int) ($a['faixa_mudaria'] === true));

            if ($peso !== 0) {
                return $peso;
            }

            return abs((float) ($b['diferenca'] ?? 0)) <=> abs((float) ($a['diferenca'] ?? 0));
        });

        $base['linhas_conferidas']     = $conferidas;
        $base['divergentes']           = $divergentes;
        $base['nao_comparaveis']       = $naoComparaveis;
        $base['total_divergentes']     = count($divergentes);
        $base['total_faixas_mudariam'] = count(array_filter($divergentes, fn ($d) => $d['faixa_mudaria'] === true));

        return $base;
    }

    /**
     * Diferença entre dois lados do mesmo faturamento, ou `null` quando são
     * iguais dentro da tolerância. Ausência dos dois lados é igualdade;
     * ausência de UM lado é diferença (sumiu ou apareceu faturamento).
     */
    private function diferenca(?float $gravado, ?float $atual): ?float
    {
        if ($gravado === null && $atual === null) {
            return null;
        }

        $delta = ($atual ?? 0.0) - ($gravado ?? 0.0);

        return abs($delta) > self::TOLERANCIA ? $delta : null;
    }

    /**
     * O valor de hoje ainda cai na faixa CONGELADA nesta linha?
     *
     * Usa os limites gravados, não a tabela de hoje — a tabela pode ter sido
     * editada depois do fechamento, e aí a resposta seria sobre outra
     * pergunta. A régua de intervalo é a mesma de
     * `FechamentoFaixaResolver::classificar()`: a faixa cobre
     * `(limite_inferior, limite_superior]`, com `limite_superior = null` na
     * máxima e o piso da primeira faixa incluindo o zero.
     *
     * `null` = esta linha não tem faixa para conferir (valor fixo, sem
     * tabela, ou limites não gravados). Não é "não mudaria" — é "não sei",
     * e por isso não entra na contagem de urgência.
     */
    private function faixaMudaria(FechamentoSnapshot $linha, ?float $atualTotal): ?bool
    {
        if ($atualTotal === null || $linha->faixa_ordem === null) {
            return null;
        }

        $inferior = $linha->faixa_limite_inferior !== null ? (float) $linha->faixa_limite_inferior : null;
        $superior = $linha->faixa_limite_superior !== null ? (float) $linha->faixa_limite_superior : null;

        if ($inferior === null && $superior === null) {
            return null;
        }

        $inferior ??= 0.0;

        $acimaDoPiso = $inferior <= 0.0
            ? $atualTotal >= $inferior
            : $atualTotal > $inferior;

        $dentroDoTeto = $superior === null || $atualTotal <= $superior;

        return ! ($acimaDoPiso && $dentroDoTeto);
    }

    /**
     * Quando o dado deste mês foi gravado por último, por plataforma.
     *
     * ⚠️ POR QUE ISTO NÃO É O CRITÉRIO DO AVISO: `adman_metrics` recebe
     * escrita TODO DIA (o `adman:sync` das 11:00 e a releitura das 19:00 do
     * quick 260930-njd reescrevem dias já passados de propósito). Avisar pela
     * simples existência de escrita posterior faria o aviso aparecer todo
     * santo dia, e aviso que aparece sempre ensina a ignorar — o mesmo
     * raciocínio que deixou `ProcedenciaFaturamentoNota` calada no estado
     * normal. O aviso sai pela DIVERGÊNCIA DE VALOR; a hora serve só para
     * dizer QUANDO o dado mudou, depois que já se sabe que ele mudou.
     *
     * Lê `updated_at` e `synced_at` e usa o maior dos dois: `updated_at` é a
     * hora da escrita (o que interessa), e `synced_at` cobre quem grava sem
     * timestamps do Eloquent.
     *
     * @return array{quando: ?Carbon, ml: ?Carbon, shopee: ?Carbon}
     */
    private function dadoMaisRecenteDoMes(string $mes): array
    {
        $janela = $this->rollupService->janela($mes);
        $inicio = $janela['inicio']->toDateString();
        $fim    = $janela['fim']->toDateString();

        $ml = AdmanMetric::query()
            ->whereDate('reference_date', '>=', $inicio)
            ->whereDate('reference_date', '<=', $fim)
            ->selectRaw('MAX(updated_at) as u, MAX(synced_at) as s')
            ->first();

        $shopee = ShopeeMetric::query()
            ->whereDate('reference_date', '>=', $inicio)
            ->whereDate('reference_date', '<=', $fim)
            ->selectRaw('MAX(updated_at) as u, MAX(synced_at) as s')
            ->first();

        $maiorDe = function ($row): ?Carbon {
            $datas = collect([$row?->u, $row?->s])
                ->filter()
                ->map(fn ($d) => Carbon::parse($d))
                ->sort();

            return $datas->last();
        };

        $mlQuando     = $maiorDe($ml);
        $shopeeQuando = $maiorDe($shopee);

        $quando = collect([$mlQuando, $shopeeQuando])->filter()->sort()->last();

        return [
            'quando' => $quando,
            'ml'     => $mlQuando,
            'shopee' => $shopeeQuando,
        ];
    }
}
