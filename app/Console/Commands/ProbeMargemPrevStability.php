<?php

namespace App\Console\Commands;

use App\Models\AdmanProbeMargemPrevLeitura;
use App\Models\AdmanProbeMargemPrevVeredito;
use App\Models\Company;
use App\Models\User;
use App\Services\AdmanService;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Portfolio\CarteiraContextService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Probe de estabilidade de `percentageMargin.prev` da Adman (Fase 117,
 * Plano 02 — gate MPP-04).
 *
 * O QUE MEDE: a decisão D1 da milestone v21.0 amarra a nota de margem do
 * bônus a `value - prev` (pontos percentuais), lido de `percentageMargin`
 * do endpoint detalhado da Adman. Esse campo `prev` NUNCA foi validado
 * quanto a estabilidade — e o histórico do projeto já tem um precedente
 * concreto de erro: em 23/07/2026, 3 chamadas ao vivo devolveram valores
 * idênticos, isso foi lido como "o dado não flutua", e 4 dias depois essa
 * conclusão virou revert (memória do projeto:
 * `project_adman_margem_diff_instavel_bonus.md`). Este comando existe para
 * que uma conclusão desse tipo, desta vez, seja metodologicamente
 * defensável: várias leituras reais, espalhadas em 24-48h, persistidas como
 * fatos duráveis, agregadas por `--relatorio` num veredito reconsultável.
 *
 * POR QUE NÃO `AdmanMetricDiffService::compute()` (D-11b): esse serviço
 * cacheia o resultado por `(marketplace, custId, janela, DIA)` com TTL de
 * até 1440 min, e ainda tem memo por request. Chamar `compute()` nas 5+
 * leituras espalhadas no tempo devolveria A MESMA resposta cacheada em
 * todas elas — o gate viraria teatro, reproduzindo exatamente o erro
 * metodológico de 23/07 com aparência de rigor. Por isso este comando lê
 * DIRETO de `AdmanService::fetchAccountMetricsDetailedCached(...,
 * forceRefresh: true)`, que ignora a LEITURA do cache mas ainda reaquece o
 * cache compartilhado com o valor fresco (nunca usar `Cache::flush()` —
 * isso apagaria sessões de usuários logados e todos os outros caches do
 * app; é destrutivo e desnecessário, porque `forceRefresh` já resolve de
 * forma cirúrgica).
 *
 * ONDE RODA (D-11): este comando roda na VPS, contra a Adman de PRODUÇÃO,
 * ao longo de 24-48h (ver runbook em `117-02-SUMMARY.md`). Medir contra
 * fixture ou ambiente local não diria nada sobre o comportamento real da
 * API sob contenção.
 *
 * AMOSTRA (D-04): deliberadamente restrita às carteiras dos 2 profissionais
 * do incidente de 23/07 — Luiz (user id 3) e Danilo (user id 15) — com
 * `financial_metrics_eligible=true` e `financial_source='adman'`. Varrer
 * TODAS as empresas Adman foi rejeitado no CONTEXT: o próprio volume do
 * probe viraria fonte de rate-limit (429) e contaminaria a medição que ele
 * mesmo está tentando fazer.
 */
class ProbeMargemPrevStability extends Command
{
    /**
     * Users da amostra do probe (D-04) — as duas carteiras do incidente de
     * 23/07/2026 (memória do projeto: `project_adman_margem_diff_instavel_bonus.md`).
     * Amostra enviesada só nas empresas que oscilaram foi rejeitada no
     * CONTEXT — boa pra achar problema, inválida pra aprovar.
     */
    private const USER_IDS_AMOSTRA = [3, 15]; // Luiz, Danilo

    /**
     * Taxa máxima tolerada de falha de HTTP dentro de UMA rodada.
     *
     * Acrescentado em 2026-07-29 depois que a primeira coleta real expôs o
     * ponto cego: `http_falhou` não entrava no veredito em lugar nenhum.
     * Empresa cuja chamada falha não produz `nota_regua` e portanto NUNCA
     * aparece como "flip de faixa" — a falha se manifesta como
     * DESAPARECIMENTO. É exatamente a causa-raiz apurada na Fase 110
     * ("empresa que falha sai da média"), e o gate era cego para ela.
     *
     * Patamar de 10%: as quatro rodadas em condição folgada deram 0% de falha
     * em 212 leituras; a rodada sob contenção real deu 28,3% (15 de 53).
     */
    private const FALHA_HTTP_MAXIMA = 0.10;

    protected $signature = 'adman:probe-margem-prev
        {--mes= : competência fechada FIXA no formato YYYY-MM (OBRIGATÓRIO — nunca last_closed_month, ver docblock da classe)}
        {--janela= : rótulo humano da janela desta leitura (madrugada|contencao_11h|pico_tarde|repeticao_24h|manual)}
        {--relatorio : agrega as leituras já persistidas e emite o veredito, sem tocar a Adman}';

    protected $description = 'Probe de estabilidade de percentageMargin.prev da Adman (gate MPP-04, Fase 117) — leitura sem cache ou agregação via --relatorio.';

    public function __construct(
        private AdmanService $admanService,
        private MetricPeriodResolver $resolver,
        private CarteiraContextService $carteiraContext,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('relatorio')) {
            return $this->emitirRelatorio();
        }

        $mes = $this->option('mes');

        // Validação explícita aqui, mesmo sabendo que o MetricPeriodResolver
        // já lançaria InvalidArgumentException pra formato errado — a
        // mensagem clara em pt-BR é parte da mitigação de T-117-08 e evita
        // que alguém rode o probe sem entender por que a competência tem
        // que ser fixa (D-05: as leituras se espalham por 24-48h e podem
        // cruzar a virada de mês, invalidando a comparação "mesma empresa,
        // mesma janela, valores diferentes" — objeto de estudo do probe).
        if (! is_string($mes) || preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
            $this->error('[AdmanProbeMargemPrev] --mes é obrigatório e precisa ser uma competência FIXA no formato YYYY-MM (ex.: --mes=2026-06). Nunca use last_closed_month: as leituras deste probe se espalham por 24-48h e podem cruzar a virada de mês, apontando pra competências diferentes em leituras diferentes.');

            return self::FAILURE;
        }

        $periodo = $this->resolver->resolve(['period_key' => $mes]);

        // Asserção em runtime (D-05): o modo YYYY-MM do MetricPeriodResolver
        // sempre produz previous_equal_length_window, mas travamos aqui
        // explicitamente — se um dia o resolver mudar de comportamento,
        // o probe precisa falhar ruidosamente, não silenciosamente medir
        // a janela errada.
        if ($periodo['comparison_mode'] !== 'previous_equal_length_window') {
            $this->error("[AdmanProbeMargemPrev] comparison_mode inesperado ('{$periodo['comparison_mode']}') para --mes={$mes}. Esperado previous_equal_length_window — abortando para não medir a janela errada.");

            return self::FAILURE;
        }

        $companies = $this->resolverAmostra();

        if ($companies->isEmpty()) {
            $this->error('[AdmanProbeMargemPrev] amostra vazia — nenhuma empresa elegível encontrada para os users da amostra (D-04). Abortando.');

            return self::FAILURE;
        }

        $ok        = 0;
        $fail      = 0;
        $semCustId = 0;
        $comPrev   = 0;
        $janela    = $this->option('janela');

        foreach ($companies as $company) {
            $custId = $company->adman_account_id ?: $company->ml_store_id;

            if (empty($custId)) {
                $semCustId++;

                continue;
            }

            try {
                // ── A LINHA MAIS IMPORTANTE DESTE PLANO (invariante 1 / D-11b) ──
                // forceRefresh=true pula a LEITURA do Cache::get() interno do
                // AdmanService, mas o Cache::put() final ainda roda — ou seja,
                // o probe reaquece o cache compartilhado com o valor fresco em
                // vez de invalidá-lo. Por isso NÃO usamos Cache::flush() (que
                // apagaria sessões de usuários logados e todos os outros caches
                // do app — destrutivo e desnecessário) nem
                // AdmanMetricDiffService::compute() (que tem cache diário +
                // memo por request e devolveria a MESMA resposta nas 5 leituras).
                $detalhado = $this->admanService->fetchAccountMetricsDetailedCached(
                    custId: $custId,
                    dateFrom: $periodo['current_start'],
                    dateTo: $periodo['current_end'],
                    cacheMinutes: 1440,
                    forceRefresh: true,
                    marketplace: $company->marketplace ?? 'meli',
                );

                $pm = $detalhado['percentageMargin'] ?? null;

                // Payload externo — nunca assumir chave presente (T-117-10).
                $value      = isset($pm['value']) ? (float) $pm['value'] : null;
                $prev       = isset($pm['prev']) ? (float) $pm['prev'] : null;
                $diffNativo = isset($pm['diff']) ? (float) $pm['diff'] : null;

                $margemVarPp = ($value !== null && $prev !== null)
                    ? round($value - $prev, 6)
                    : null;

                $notaRegua = $this->reguaMargem($margemVarPp);

                $leituraHash = $pm !== null ? md5(json_encode($pm)) : null;
                $httpFalhou  = $pm === null;

                AdmanProbeMargemPrevLeitura::create([
                    'company_id'      => $company->id,
                    'periodo_key'     => $mes,
                    'lida_em'         => now(),
                    'janela_esperada' => $janela,
                    'value'           => $value,
                    'prev'            => $prev,
                    'diff_nativo'     => $diffNativo,
                    'margem_var_pp'   => $margemVarPp,
                    'nota_regua'      => $notaRegua !== null ? (int) $notaRegua : null,
                    'leitura_hash'    => $leituraHash,
                    'http_falhou'     => $httpFalhou,
                ]);

                if ($httpFalhou) {
                    $fail++;
                } else {
                    $ok++;
                    if ($prev !== null) {
                        $comPrev++;
                    }
                }
            } catch (\Throwable $e) {
                // Fail-open por item (padrão de WarmAdmanDiffCache): uma
                // exceção numa empresa NUNCA aborta a leitura das demais.
                // Uma exceção é, ela mesma, uma leitura falha — e leitura
                // falha é dado, não erro (Pitfall 2 do RESEARCH).
                $fail++;

                Log::warning('[AdmanProbeMargemPrev] falhou', [
                    'company_id' => $company->id,
                    'periodo'    => "{$periodo['current_start']}..{$periodo['current_end']}",
                    'error'      => $e->getMessage(),
                ]);

                AdmanProbeMargemPrevLeitura::create([
                    'company_id'      => $company->id,
                    'periodo_key'     => $mes,
                    'lida_em'         => now(),
                    'janela_esperada' => $janela,
                    'http_falhou'     => true,
                ]);
            }
        }

        // Relatório final — SÓ contadores agregados, nunca valores de margem
        // por empresa (T-117-06) nem qualquer credencial. Os valores
        // individuais existem na tabela, que é o lugar deles.
        $msg = sprintf(
            '[AdmanProbeMargemPrev] leitura concluída — mes=%s janela=%s empresas=%d OK=%d FAIL=%d sem_custid=%d com_prev=%d',
            $mes, $janela ?? 'null', $companies->count(), $ok, $fail, $semCustId, $comPrev
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * Resolve a amostra do probe (D-04): union das empresas elegíveis
     * (`financial_metrics_eligible=true` e `financial_source='adman'`) das
     * carteiras dos users da amostra, via `CarteiraContextService::forUser()`
     * — NUNCA join direto em `company_users` (contrato da classe).
     *
     * Fail-open no nível de user: se um dos ids da amostra não existir,
     * loga aviso e segue com o que existir; só aborta (amostra vazia) se
     * NENHUM company_id sobrar.
     */
    private function resolverAmostra(): \Illuminate\Support\Collection
    {
        $companyIds = collect();

        foreach (self::USER_IDS_AMOSTRA as $userId) {
            $user = User::find($userId);

            if ($user === null) {
                Log::warning('[AdmanProbeMargemPrev] user da amostra nao encontrado', ['user_id' => $userId]);

                continue;
            }

            $vinculos = $this->carteiraContext->forUser($user)
                ->where('financial_metrics_eligible', true)
                ->where('financial_source', 'adman');

            $companyIds = $companyIds->concat($vinculos->pluck('company_id'));
        }

        $companyIds = $companyIds->unique()->values();

        return Company::whereIn('id', $companyIds)
            ->get(['id', 'name', 'adman_account_id', 'ml_store_id', 'marketplace']);
    }

    /**
     * Régua de MARGEM DE CONTRIBUIÇÃO em pontos percentuais — cópia BYTE A
     * BYTE de `DesempenhoScoreService::reguaMargem()`
     * (`app/Services/DesempenhoScoreService.php:1311-1319`), incluindo as
     * fronteiras exatas -5 / -2 / +1 / +4.
     *
     * DUPLICAÇÃO TEMPORÁRIA E INTENCIONAL (mesmo disclaimer já usado no
     * docblock de classe de `AdmanMetricDiffService` para
     * `calculated_fallback`): D-12 do CONTEXT proíbe tocar
     * `DesempenhoScoreService` nesta fase — a régua NÃO pode virar
     * public/static aqui. A extração de um helper compartilhado é da
     * Fase 119, que já vai tocar essa classe para criar o
     * `CompanyScoreService`.
     *
     * Aqui a régua é lida em PONTOS PERCENTUAIS (`margem_var_pp` = value -
     * prev) — a decisão D2 da milestone reusa os mesmos cortes numéricos
     * sem recalibrar.
     */
    private function reguaMargem(?float $pp): ?float
    {
        if ($pp === null) return null;
        if ($pp <= -5)    return 1.0;
        if ($pp <= -2)    return 2.0;
        if ($pp <=  1)    return 3.0;
        if ($pp <=  4)    return 4.0;
        return 5.0;
    }

    /**
     * Modo --relatorio: agrega as leituras já persistidas de UMA competência
     * (`periodo_key`) num veredito reconsultável, sem tocar a Adman (D-10).
     *
     * Ordem de precedência do veredito, EXPLÍCITA e nesta ordem:
     *   instrumentacao_suspeita  →  reprovado  →  aprovado
     * "Estabilidade perfeita" (payload idêntico bit-a-bit) é o SINTOMA
     * esperado de um probe medindo cache, não um sucesso — por isso vem
     * ANTES até de `reprovado`: uma leitura mal instrumentada não decide
     * nada, nem pra aprovar nem pra reprovar de verdade (invariante 2).
     */
    private function emitirRelatorio(): int
    {
        $mes = $this->option('mes');

        // Mesma validação do modo de leitura — --relatorio também exige a
        // competência fixa, pra agregar só as leituras daquele periodo_key.
        if (! is_string($mes) || preg_match('/^\d{4}-\d{2}$/', $mes) !== 1) {
            $this->error('[AdmanProbeMargemPrev] --relatorio também exige --mes no formato YYYY-MM (a mesma competência fixa usada nas leituras a agregar).');

            return self::FAILURE;
        }

        $leituras = AdmanProbeMargemPrevLeitura::with('company:id,name')
            ->where('periodo_key', $mes)
            ->orderBy('company_id')
            ->orderBy('lida_em')
            ->get();

        if ($leituras->isEmpty()) {
            $this->error("[AdmanProbeMargemPrev] nenhuma leitura encontrada para periodo_key={$mes} — rode o modo de leitura antes de agregar. Nenhum veredito foi gravado.");

            return self::FAILURE;
        }

        $porEmpresa = $leituras->groupBy('company_id');

        $motivos         = [];
        $empresasComFlip = [];

        // ── D-01: flip de nota entre DUAS LEITURAS QUAISQUER da mesma
        //    empresa (não só consecutivas — comparamos o CONJUNTO de notas
        //    distintas, então um par não-adjacente que difira também conta). ──
        foreach ($porEmpresa as $companyId => $grupo) {
            $notasDistintas = $grupo->pluck('nota_regua')
                ->filter(fn ($n) => $n !== null)
                ->unique()
                ->sort()
                ->values();

            if ($notasDistintas->count() > 1) {
                $empresasComFlip[] = [
                    'company_id'   => $companyId,
                    'company_name' => optional($grupo->first()->company)->name,
                    'notas'        => $notasDistintas->all(),
                ];
            }
        }

        $empresasComFlipCount = count($empresasComFlip);
        if ($empresasComFlipCount > 0) {
            $motivos[] = sprintf(
                '%d empresa(s) com nota_regua diferente entre leituras (flip de faixa da régua) — D-01 exige zero flip para aprovar, mesmo que as notas isoladamente pareçam plausíveis.',
                $empresasComFlipCount
            );
        }

        // ── D-03: cobertura mínima de prev não-nulo — reusa o patamar de
        //    AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA (ver
        //    coberturaMinima() abaixo), nunca hardcodeado aqui, pra não criar
        //    dois conceitos concorrentes de "cobertura suficiente" no mesmo
        //    domínio. ──
        // ⚠️ CORRIGIDO em 2026-07-29 — o cálculo anterior era AGREGADO e
        //    invertia o propósito do gate.
        //
        //    Antes: cobertura = comPrev / totalLeituras sobre TODAS as
        //    leituras. Na coleta real de 2026-07-29 isso produziu `aprovado`
        //    indevidamente: quatro rodadas em condição folgada (92,5% cada)
        //    DILUÍRAM a rodada sob contenção (64,2%) até um agregado de 86,8%,
        //    acima do piso de 80%. Ou seja: as condições boas escondiam
        //    exatamente a condição ruim que este gate existe para detectar.
        //
        //    Agora: cobertura é avaliada POR RODADA e o veredito usa a PIOR.
        //    Uma única rodada abaixo do piso reprova, independentemente de
        //    quantas rodadas boas existam.
        $totalLeituras   = $leituras->count();
        $coberturaMinima = $this->coberturaMinima();

        $porRodada = $leituras->groupBy(fn ($l) => $this->chaveDaRodada($l));

        $coberturaPorRodada = $porRodada->map(function ($grupo) {
            $n = $grupo->count();

            return $n > 0 ? round($grupo->whereNotNull('prev')->count() / $n, 4) : 0.0;
        });

        // A cobertura REPORTADA e persistida passa a ser a da pior rodada —
        // é ela que decide o veredito.
        $coberturaPrev = $coberturaPorRodada->isEmpty() ? 0.0 : $coberturaPorRodada->min();

        $rodadasAbaixoDoPiso = $coberturaPorRodada->filter(fn ($c) => $c < $coberturaMinima);

        if ($coberturaPrev < $coberturaMinima) {
            $motivos[] = sprintf(
                'cobertura de prev não-nulo abaixo do mínimo em %d de %d rodada(s) — PIOR rodada: %.2f%%, mínimo exigido %.0f%% (D-03). Rodadas abaixo do piso: %s. A cobertura é avaliada POR RODADA: uma rodada ruim reprova mesmo que a média de todas passe.',
                $rodadasAbaixoDoPiso->count(),
                $coberturaPorRodada->count(),
                $coberturaPrev * 100,
                $coberturaMinima * 100,
                $rodadasAbaixoDoPiso->map(fn ($c, $k) => sprintf('%s (%.1f%%)', $k, $c * 100))->implode(', ')
            );
        }

        // ── Falha de HTTP como sinal PRÓPRIO (acrescentado em 2026-07-29) ──
        //    Antes, `http_falhou` não entrava no veredito em lugar nenhum, e
        //    isso era um ponto cego grave: empresa cuja chamada FALHA não
        //    produz `nota_regua`, logo não pode "flipar" de faixa. A falha se
        //    manifesta como DESAPARECIMENTO, não como número errado — que é
        //    exatamente a causa-raiz apurada na Fase 110 ("empresa que falha
        //    sai da média"). Um gate que só olha flips é cego para ela.
        $falhaPorRodada = $porRodada->map(function ($grupo) {
            $n = $grupo->count();

            return $n > 0 ? round($grupo->where('http_falhou', true)->count() / $n, 4) : 0.0;
        });

        $piorFalha           = $falhaPorRodada->isEmpty() ? 0.0 : $falhaPorRodada->max();
        $rodadasComFalhaAlta = $falhaPorRodada->filter(fn ($f) => $f > self::FALHA_HTTP_MAXIMA);

        if ($rodadasComFalhaAlta->isNotEmpty()) {
            $motivos[] = sprintf(
                'taxa de falha de HTTP acima do tolerado em %d rodada(s) — PIOR: %.1f%%, máximo tolerado %.0f%%. Rodadas: %s. Chamada que falha remove a empresa da conta, e isso NÃO aparece como flip de nota.',
                $rodadasComFalhaAlta->count(),
                $piorFalha * 100,
                self::FALHA_HTTP_MAXIMA * 100,
                $rodadasComFalhaAlta->map(fn ($f, $k) => sprintf('%s (%.1f%%)', $k, $f * 100))->implode(', ')
            );
        }

        // ── Invariante 2 (obrigatória) — sanidade anti-cache. Por empresa
        //    com 2+ leituras BEM-SUCEDIDAS (http_falhou=false, leitura_hash
        //    presente), conta hashes distintos. Se TODA empresa comparável
        //    tiver exatamente 1 hash distinto, o veredito vira
        //    instrumentacao_suspeita — precedência sobre aprovado. ──
        $todasIdenticas    = true;
        $algumaComparavel  = false;

        foreach ($porEmpresa as $grupo) {
            $bemSucedidas = $grupo->where('http_falhou', false)->whereNotNull('leitura_hash');

            if ($bemSucedidas->count() < 2) {
                continue; // nada a comparar nesta empresa
            }

            $algumaComparavel = true;

            if ($bemSucedidas->pluck('leitura_hash')->unique()->count() > 1) {
                $todasIdenticas = false;
            }
        }

        $instrumentacaoSuspeita = $algumaComparavel && $todasIdenticas;

        if ($instrumentacaoSuspeita) {
            $motivos[] = 'todas as leituras comparáveis devolveram payload idêntico bit-a-bit (mesmo leitura_hash) — possível problema de instrumentação (probe lendo cache), NÃO conclua estabilidade a partir disto; conferir se forceRefresh: true está mesmo sendo propagado antes de qualquer conclusão. É este exato padrão que, em 23/07, foi lido como "o dado não flutua" e virou revert 4 dias depois.';
        }

        // ── D-02: desenho amostral — >=5 rodadas (lida_em distintos) E ao
        //    menos 1 leitura na janela de contenção real (11:00-12:00 BRT).
        //    Faltando qualquer um dos dois, nunca aprovado. ──
        // ⚠️ CORRIGIDO em 2026-07-29 — a contagem anterior usava `lida_em`
        //    DISTINTO, mas cada empresa é gravada com o seu próprio timestamp
        //    dentro da MESMA execução. Com 53 empresas por rodada, 5 rodadas
        //    reais viravam "262 rodadas" no relatório, e a guarda de "mínimo 5"
        //    passava trivialmente sem nunca ter sido exercida.
        //    Agora conta rodadas de verdade, pela mesma chave usada na
        //    cobertura (ver `chaveDaRodada()`).
        $totalRodadas       = $porRodada->count();
        $temJanelaContencao = $leituras->contains('janela_esperada', 'contencao_11h');
        $desenhoIncompleto  = $totalRodadas < 5 || ! $temJanelaContencao;

        if ($desenhoIncompleto) {
            $motivos[] = sprintf(
                'desenho amostral incompleto (D-02): %d rodada(s) distinta(s) registradas (mínimo 5) e %s leitura na janela de contenção real (janela_esperada=contencao_11h, 11:00-12:00 BRT — [MLB SyncTodasVendas]/[MLB SyncPub] NÃO são agendados por cron, então essa janela precisa ser garantida manualmente).',
                $totalRodadas,
                $temJanelaContencao ? 'há' : 'não há'
            );
        }

        // ── Veredito final — ordem de precedência explícita ──
        $veredito = match (true) {
            $instrumentacaoSuspeita => AdmanProbeMargemPrevVeredito::VEREDITO_INSTRUMENTACAO_SUSPEITA,
            $empresasComFlipCount > 0
            || $coberturaPrev < $coberturaMinima
            || $rodadasComFalhaAlta->isNotEmpty()
            || $desenhoIncompleto
                => AdmanProbeMargemPrevVeredito::VEREDITO_REPROVADO,
            default => AdmanProbeMargemPrevVeredito::VEREDITO_APROVADO,
        };

        // Insert-only — cada execução de --relatorio grava uma linha NOVA
        // (nunca updateOrCreate). O histórico de vereditos é parte da
        // auditoria do gate (D-10).
        $registro = AdmanProbeMargemPrevVeredito::create([
            'periodo_key'             => $mes,
            'gerado_em'               => now(),
            'total_leituras'          => $totalLeituras,
            'total_empresas'          => $porEmpresa->count(),
            'total_rodadas'           => $totalRodadas,
            'cobertura_prev'          => $coberturaPrev,
            'empresas_com_flip_count' => $empresasComFlipCount,
            'empresas_com_flip'       => $empresasComFlip,
            'veredito'                => $veredito,
            'motivos'                 => $motivos,
        ]);

        $this->imprimirRelatorioConsole($registro, $leituras, $porEmpresa);

        // Relatório final via log — SÓ contadores agregados (T-117-06),
        // nunca valores de margem por empresa nem credencial. Os valores
        // individuais existem na tabela impressa acima e no banco.
        $msg = sprintf(
            '[AdmanProbeMargemPrev] relatorio gerado — mes=%s veredito=%s empresas=%d leituras=%d rodadas=%d cobertura_prev=%.2f flips=%d',
            $mes, $veredito, $porEmpresa->count(), $totalLeituras, $totalRodadas, $coberturaPrev, $empresasComFlipCount
        );
        Log::info($msg);
        $this->info($msg);

        return self::SUCCESS;
    }

    /**
     * Lê `AdmanMetricDiffService::MARGEM_COBERTURA_MINIMA` via Reflection —
     * DELIBERADAMENTE sem `use App\Services\Metrics\AdmanMetricDiffService;`
     * e sem chamar `::compute()` (nenhum dos dois pode aparecer neste
     * arquivo — invariante 1/D-11b, gate automatizado da Task 2 deste
     * plano). Necessário porque, na implementação REAL (verificada em
     * `app/Services/Metrics/AdmanMetricDiffService.php:70`), a constante é
     * `private const` — o `<interfaces>` deste plano a descrevia como
     * `public const`, uma divergência entre plano e realidade documentada
     * no SUMMARY. A verificação 6 deste mesmo plano PROÍBE editar
     * `app/Services/Metrics/*` (independência do Plano 117-01), então subir
     * a visibilidade da constante está fora de escopo. Reflection lê o
     * valor real sem tocar o arquivo-fonte e sem hardcodear `0.8` aqui —
     * cumpre a letra do `<action>` ("ler a constante, nunca escrever 0.8")
     * pelo único caminho compatível com as duas restrições.
     */
    private function coberturaMinima(): float
    {
        return (float) (new \ReflectionClass(\App\Services\Metrics\AdmanMetricDiffService::class))
            ->getConstant('MARGEM_COBERTURA_MINIMA');
    }

    /**
     * Chave que identifica UMA rodada do probe.
     *
     * Uma rodada é uma execução do comando: ~53 empresas lidas em sequência,
     * cada uma com o seu próprio `lida_em`, tudo dentro de poucos minutos.
     * Agrupar por `lida_em` distinto — como o cálculo fazia até 2026-07-29 —
     * contava CADA EMPRESA como uma rodada.
     *
     * A chave é `janela_esperada` + hora de `lida_em`, o que separa
     * corretamente as execuções observadas (duas rodadas `madrugada` em dias
     * e horas diferentes viram duas chaves, como deve ser).
     *
     * Limitação conhecida: uma rodada que atravesse a virada da hora seria
     * contada como duas. Como uma rodada leva ~6 min e as janelas são
     * disparadas manualmente, isso não ocorreu na coleta real — e contar
     * rodadas a MAIS é conservador aqui (só torna o gate mais exigente no
     * mínimo de 5, nunca menos).
     */
    private function chaveDaRodada(object $leitura): string
    {
        $janela = $leitura->janela_esperada ?? 'sem_janela';

        return $janela . '@' . $leitura->lida_em->format('Y-m-d H');
    }

    /**
     * Relatório legível impresso no console — NUNCA vai para Log::info
     * (T-117-06: só contadores agregados são logados). Inclui a comparação
     * de referência exigida pelo CONTEXT `<specifics>` e o aviso de que a
     * conferência oficial é por reconsulta ao banco (D-10, mesma disciplina
     * do gate FIXMARG-03).
     *
     * @param  \Illuminate\Support\Collection<int, AdmanProbeMargemPrevLeitura>  $leituras
     * @param  \Illuminate\Support\Collection<int|string, \Illuminate\Support\Collection<int, AdmanProbeMargemPrevLeitura>>  $porEmpresa
     */
    private function imprimirRelatorioConsole(
        AdmanProbeMargemPrevVeredito $registro,
        \Illuminate\Support\Collection $leituras,
        \Illuminate\Support\Collection $porEmpresa
    ): void {
        $this->info(sprintf(
            '[AdmanProbeMargemPrev] periodo=%s rodadas=%d empresas=%d leituras=%d http_falhou=%d cobertura_prev=%.2f%%',
            $registro->periodo_key,
            $registro->total_rodadas,
            $registro->total_empresas,
            $registro->total_leituras,
            $leituras->where('http_falhou', true)->count(),
            $registro->cobertura_prev * 100
        ));

        $linhas = [];
        foreach ($porEmpresa as $companyId => $grupo) {
            $notas   = $grupo->pluck('nota_regua')->filter(fn ($n) => $n !== null)->unique()->sort()->values()->implode(',');
            $margens = $grupo->pluck('margem_var_pp')->filter(fn ($v) => $v !== null);
            $hashes  = $grupo->where('http_falhou', false)->pluck('leitura_hash')->filter()->unique()->count();

            $linhas[] = [
                optional($grupo->first()->company)->name ?? "empresa #{$companyId}",
                $grupo->count(),
                $notas !== '' ? $notas : '—',
                $margens->isNotEmpty() ? number_format($margens->min(), 2) : '—',
                $margens->isNotEmpty() ? number_format($margens->max(), 2) : '—',
                $hashes,
            ];
        }

        $this->table(['Empresa', 'Leituras', 'Notas distintas', 'pp mín', 'pp máx', 'Hashes distintos'], $linhas);

        // Comparação de referência exigida pelo CONTEXT <specifics>: a
        // média de margem_var_pp de TODA a amostra, a nota que ela produz
        // na régua reusada, e o contraste com os 3 números do incidente de
        // 23/07 (carteira do Luiz) — é essa comparação que torna o gate
        // discutível pelo usuário em vez de um aprovado/reprovado opaco.
        $margensGerais = $leituras->pluck('margem_var_pp')->filter(fn ($v) => $v !== null);

        if ($margensGerais->isNotEmpty()) {
            $media     = round($margensGerais->avg(), 4);
            $notaMedia = $this->reguaMargem($media);

            $this->info(sprintf(
                '[AdmanProbeMargemPrev] media da amostra: %.2f pp -> nota %s na regua reusada. Referencia do incidente (carteira do Luiz, 23/07): ~-0,59 pp -> nota 3 no calculo pp, contra nota 5 no snapshot congelado atual e nota 1 no calculo local ja revertido.',
                $media,
                $notaMedia !== null ? (string) (int) $notaMedia : 'null'
            ));
        }

        $this->info(sprintf('[AdmanProbeMargemPrev] VEREDITO: %s', $registro->veredito));
        foreach (($registro->motivos ?? []) as $motivo) {
            $this->line("  - {$motivo}");
        }

        $this->warn('[AdmanProbeMargemPrev] AVISO: este relatorio impresso no console e um espelho. A conferencia OFICIAL do gate MPP-04 e por reconsulta ao banco (adman_probe_margem_prev_vereditos / adman_probe_margem_prev_leituras) — nunca por este stdout (mesma disciplina que o gate FIXMARG-03 ja exige neste projeto).');
    }
}
