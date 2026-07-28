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
     * Modo --relatorio: agrega as leituras persistidas de uma competência
     * num veredito reconsultável. Implementado na Task 3 do plano 117-02.
     */
    private function emitirRelatorio(): int
    {
        return self::SUCCESS;
    }
}
