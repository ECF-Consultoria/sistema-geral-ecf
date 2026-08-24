<?php

namespace App\Services;

use App\Models\AdmanMetric;
use App\Models\BonusFaixa;
use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoScoreSnapshot;
use App\Models\NpsResponse;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Models\User;
use App\Services\Metrics\FinancialSourceResolver;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Metrics\MetricPeriodResolver;
use App\Services\Metrics\MetricsProviderFactory;
use App\Services\Desempenho\CompanyScoreService;
use App\Services\Desempenho\NpsSemLinkService;
use App\Services\Nps\NpsImputationService;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Engine v2 de score do módulo Desempenho — Phase 74 (D-05, D-06, D-07, D-17).
 *
 * Substitui in-place o `PortfolioScoreService` v1 (6 métricas ponderadas com
 * cap ±20% em crescimento + normalização de faixas 1-5). A partir da decisão
 * da diretoria/gestão da ECF em 2026-07-09 (SPEC-01 a SPEC-14), a régua de
 * bonificação foi drasticamente simplificada:
 *
 *   • 4 parâmetros — NPS médio | var. faturamento vs mês anterior |
 *     var. margem de contribuição vs mês anterior | absenteísmo (standby).
 *   • Média direta em escalas naturais (DESEMP-02) — sem ponderação, sem
 *     redistribuição, sem normalização régua 1-5.
 *   • Consolidação mensal fechada — sem rolling 30d.
 *   • Faixa de bônus vem da tabela editável `bonus_faixas` (Plan 74-02).
 *   • Regra suplementar: 2 meses consecutivos em `intermediario` promove
 *     para `maximo` (DESEMP-08).
 *
 * Requirements endereçados por este service:
 *  - DESEMP-01 · Engine v2 de score
 *  - DESEMP-02 · Fórmula média direta em escalas naturais
 *  - DESEMP-03 · NPS = média das notas do mês; sem respostas → 0 (penaliza)
 *  - DESEMP-04 · % variação de faturamento (empresas novas / sem baseline excluídas)
 *  - DESEMP-05 · % variação de margem de contribuição via Adman canônico
 *  - DESEMP-06 · Absenteísmo standby (placeholder null)
 *  - DESEMP-07 · Faixa vem de `BonusFaixa::classificar()` (delegação, sem hardcode)
 *  - DESEMP-08 · Promoção por 2 meses consecutivos em `intermediario` → `maximo`
 *  - DESEMP-10 · Sem carteira → shape com flag `sem_carteira=true` + motivo pt-BR
 *  - DESEMP-11 · Fonte de dados: ML OAuth first (MetricsProviderFactory) + Adman fallback
 *
 * Fase 91 (v17.0 · DESEMP-01/04/05/06/07) — `computeUniverso` passou a derivar
 * o universo de empresas dos VÍNCULOS DE SERVIÇO ativos do profissional
 * (`CarteiraContextService::forUser()`), não mais de `$user->companies()`
 * (carteira consolidada por `company_id`). Corrige o bug medido em prod onde
 * um responsável só-Shopee "herdava" faturamento/margem ML de empresas que
 * não gerencia. Os componentes financeiros (`computeVarFaturamento`/
 * `computeVarMargem`) passam a receber só as empresas com pelo menos 1
 * vínculo `financial_metrics_eligible=true`. O shape de `compute()` ganha
 * `score_status` (`official`/`partial`/`blocked`) + 6 metadados de
 * auditoria. `computeNpsMedio` permanece INTOCADO — NPS soma todas as áreas
 * (performance E Shopee), independente de elegibilidade financeira.
 *
 * Consumidores previstos (não acopla, apenas rastreabilidade):
 *  - Plan 74-04 · comando `desempenho:consolidar-mes` (cron mensal dia 1)
 *  - Plan 74-04 · comando `desempenho:snapshot-scores` (cron diário 13:30, reescrito)
 *  - Plan 74-06 · `Performance/Dashboard.jsx` e `Show.jsx` (view individual)
 *  - Plans 74-09 / 74-10 · testes Feature com fixture Carlos como âncora
 *
 * Fase 102 (v18.0 · BON-01/02/03) — `computeVarFaturamento`/`computeVarMargem`
 * deixam de calcular janelas inline (`now()`/`startOfMonth()`/`subMonth()`) e
 * passam a consumir `MetricPeriodResolver::resolve()` via `resolvePeriodo()`
 * (privado) ou `$periodoOverride` explícito. `computeVarMargem` deixa de
 * duplicar os guards de dias-comuns/margem_dias — delega por empresa a
 * `AdmanMetricDiffService::compute()` (fonte única, Fase 101). Novo método
 * público `computeOficial(User)` resolve o último mês FECHADO
 * (`last_closed_month`) e é o caminho explícito pro "bônus oficial" (julho
 * paga a competência junho) — ver `102-RESEARCH.md` "Opção B" para a
 * justificativa de manter `compute(User, Carbon, ?array)` compatível com os
 * ~40 call-sites existentes em vez de trocar a assinatura inteira.
 *
 * Design:
 *  - Stateless entre chamadas exceto pelo cache in-memory de `BonusFaixa`
 *    (invalidado naturalmente entre requests / instâncias do container).
 *  - `MetricsProviderFactory` + `NpsScoreCalculator` injetados via DI padrão
 *    Laravel — o service NUNCA instancia providers/calculators direto.
 *  - Métodos privados retornam tipos escalares (`?float` / `array`) — nunca
 *    DTOs próprios. O shape de retorno público é um array (documentado abaixo
 *    e em `.planning/phases/74-.../74-03-PLAN.md` `<interfaces>`).
 *
 * @see .planning/phases/74-.../74-CONTEXT.md §D-05, D-06, D-07, D-17
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-01..08, DESEMP-10, DESEMP-11
 */
class DesempenhoScoreService
{
    /**
     * Fase 120 (AGRE-05/D-03) — patamar de cobertura de empresas `complete`
     * que decide `official` vs `partial` em `computeScoreStatusPorEmpresa()`,
     * o caminho novo de agregação por empresa (flag
     * `metrics.performance_company_first_score`).
     *
     * O valor é deliberadamente o MESMO de
     * `ConsolidarMesDesempenho::MARGEM_COBERTURA_MINIMA_CONGELAMENTO` (0.7,
     * `app/Console/Commands/ConsolidarMesDesempenho.php:76`), que já governa
     * a recusa de congelar snapshot degradado. Reusar o mesmo número evita
     * que o sistema passe a ter DOIS conceitos concorrentes de "cobertura
     * suficiente" — o mesmo problema que a C-01 da Fase 119 apontou ao
     * descobrir a constante órfã de 0,8. Aquela constante é `private` no
     * comando, então o valor aqui é DUPLICADO de propósito; é este docblock
     * que amarra os dois — não há import/reuso direto entre as classes.
     *
     * O usuário NÃO fixou este número — é decisão de planejamento (D-03 do
     * `120-CONTEXT.md`), fácil de sobrepor se a diretoria decidir outro
     * patamar no futuro.
     */
    private const COBERTURA_MINIMA_SCORE_STATUS = 0.7;

    /**
     * Divisor FIXO da nota final — decisão do Maycon em 2026-08-10
     * (quick 260810-mt8): "Pontuação Final = Soma das 3 pontuações ÷ 3".
     *
     * É constante, e não `count()` dos presentes, exatamente porque o pedido é
     * que o indicador ausente PESE: ele entra como zero e o divisor não
     * encolhe. Trocar por contagem dinâmica reverte a regra em silêncio — ver
     * `computeNotaFinalPorIndicador()` para o efeito na carteira só-Shopee.
     */
    private const DIVISOR_NOTA_FINAL = 3;

    /**
     * Cache in-memory das faixas ativas — evita re-query em loops de ranking
     * (ex: consolidação mensal itera 15-20 users e cada um chama classificar).
     * Preenche na primeira chamada de `classificarFaixa()`; invalidação natural
     * entre requests (o container instancia o service uma vez por request).
     *
     * @var EloquentCollection<int, BonusFaixa>|null
     */
    private ?EloquentCollection $faixasCache = null;

    /**
     * Toggle EXCLUSIVO do comando de backfill/relatório de impacto (Fase 116
     * · Plan 06, D1) — produz a coluna "antes" (sem a regra nova) do
     * antes/depois. Default `true`: nenhum consumidor existente (controller,
     * dashboard, cron) muda de comportamento. NENHUMA tela/controller pode
     * chamar `setIncluirImputadas(false)` — é exclusivamente para o relatório
     * de impacto do backfill enxergar o valor pré-regra.
     */
    private bool $incluirImputadas = true;

    public function __construct(
        private MetricsProviderFactory $metricsFactory,
        private NpsScoreCalculator $npsCalculator,
        private CarteiraContextService $carteiraContext,
        private MetricPeriodResolver $periodResolver,
        private MetricDiffDispatcher $diffDispatcher,
        private NpsImputationService $imputationService,
        private NpsSemLinkService $npsSemLinkService,
        private CompanyScoreService $companyScoreService,
        private FinancialSourceResolver $financialSourceResolver,
    ) {
    }

    /**
     * Liga/desliga o 3º ramo (`notasImputadas`) de `computeNpsMedio` —
     * EXCLUSIVO do comando `nps:materializar-nao-respondidos` (Fase 116 ·
     * Plan 06) para montar a coluna "antes" (sem a regra) do relatório de
     * impacto antes/depois. Nenhum controller, dashboard ou job de
     * consolidação/snapshot pode chamar isto com `false` — o comportamento
     * público (`compute()`/`computeCached()`) tem que refletir SEMPRE a regra
     * ativa. Retorna `static` para permitir encadeamento fluente no comando.
     */
    public function setIncluirImputadas(bool $incluir): static
    {
        $this->incluirImputadas = $incluir;

        return $this;
    }

    /**
     * Computa o score de desempenho completo do user para o mês de referência.
     *
     * Shape de retorno (locked em `74-03-PLAN.md` `<interfaces>`):
     *
     * ```
     * [
     *   'user_id'               => int,
     *   'user_name'             => string,
     *   'mes_referencia'        => string,  // YYYY-MM-01
     *   'sem_carteira'          => bool,
     *   'motivo'                => ?string, // "Sem carteira em julho/2026" quando sem_carteira
     *   'empresas_carteira'     => int,     // compat: recebe o valor de empresas_unicas (Fase 91)
     *   'empresas_com_baseline' => int,     // usadas em var_faturamento
     *   'componentes' => [
     *     'nps_medio'           => ?float,  // Fase 105 (NPSWIN-01/02): mês fechado lê a
     *                                       // janela M+1 (0.0 quando M+1 fechou sem notas,
     *                                       // penaliza); null quando excluído (mês em curso
     *                                       // OU M+1 ainda coletando com 0 respostas)
     *     'var_faturamento_pct' => ?float,  // null quando nenhuma empresa qualifica
     *     'var_margem_pct'      => ?float,
     *     'absenteismo_pct'     => null,    // sempre null nesta phase (DESEMP-06)
     *   ],
     *   'nota_final'            => ?float,  // 2 decimais; null se todos componentes null
     *                                       // OU quando score_status='blocked' (D-91-01)
     *   'faixa_bonus'           => ?string, // slug de BonusFaixa
     *   'faixa_promovida'       => bool,    // true se DESEMP-08 alterou a faixa
     *   // ── Metadados de período (Fase 102 · BON-04) ──────────────────────────
     *   'periodo' => [
     *     'current_start' => string, 'current_end' => string,       // YYYY-MM-DD
     *     'baseline_start' => string, 'baseline_end' => string,     // YYYY-MM-DD
     *     'mode' => string,             // 'operational'|'official_bonus'|'closed_period'
     *     'comparison_mode' => string,  // 'same_interval_previous_month'|'previous_equal_length_window'
     *   ],
     *   'bonus' => [
     *     'competence_month' => ?string, // 'YYYY-MM'; null quando mês em curso
     *     'payment_month'    => ?string, // 'YYYY-MM' (competência + 1 mês); null quando mês em curso
     *   ],
     *   // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) ─────────────────
     *   'empresas_unicas'               => int,    // de CarteiraContextService::contadores()
     *   'vinculos_servico'              => int,
     *   'vinculos_financeiros'          => int,
     *   'vinculos_sem_fonte_financeira' => int,
     *   'score_status'                  => string, // 'official'|'partial'|'blocked'
     *   'componentes_disponiveis' => [
     *     'nps_medio'           => bool, // Fase 105 (NPSWIN-02): true se != null
     *                                    // (false = mês em curso OU M+1 ainda coletando)
     *     'var_faturamento_pct' => bool, // true se != null
     *     'var_margem_pct'      => bool, // true se != null
     *   ],
     * ]
     * ```
     *
     * Fluxo:
     *  1. `computeUniverso` — deriva o universo dos vínculos de serviço
     *     (`CarteiraContextService::forUser`); se `sem_carteira=true`, retorna
     *     shape com nulls (DESEMP-07: só quando ZERO vínculos ativos).
     *  2. Calcula 4 componentes (NPS/faturamento/margem/absenteísmo) — os
     *     financeiros usam só as empresas `financial_metrics_eligible=true`.
     *  3. `computeNotaFinal` — média direta em escalas naturais.
     *  4. `computeScoreStatus` — classifica `official`/`partial`/`blocked`
     *     (D-91-02); `blocked` força `nota_final=null` (D-91-01).
     *  5. `classificarFaixa` + `promoverPor2MesesConsecutivos` — DESEMP-08.
     *  6. Monta shape final com os metadados de auditoria.
     */
    /**
     * Versão cacheada de `compute()` — mesma resposta, envolvida em cache
     * Redis com TTL adaptativo (mês fechado = 7 dias / mês em curso = 10 min).
     *
     * Ajuste 2026-07-10 (audit performance-lentidao): antes o `/dashboard`,
     * `/dashboard/mercadolivre` (admin) e `/performance` chamavam
     * `compute()` sequencialmente pros 11 analistas/estrategistas a cada
     * request, e cada compute() fazia até 4 HTTP calls síncronas por
     * empresa à API do Mercado Livre. Cold cache = 70s pra carregar uma
     * página. Com este cache, requests subsequentes lêem direto do Redis
     * (~1s pra o dashboard inteiro).
     *
     * Cache é INVALIDADO naturalmente pelo TTL curto (mês em curso) ou
     * pelo passar do tempo (mês fechado). Não precisa invalidar
     * explicitamente — dado se atualiza a cada 10min pro mês em curso.
     *
     * Não use dentro de jobs/commands de snapshot ou consolidação —
     * chame `compute()` direto pra garantir dado fresco.
     *
     * Fase 120 (AGRE-02): `$incluirEmpresasScore` é repassado tal-e-qual pra
     * `compute()` dentro do closure do `Cache::remember` — default `false`
     * preserva os ~40 call-sites existentes sem uma linha de edição (D-04).
     * Ver docblock do parâmetro em `compute()` pra a distinção C-01
     * (shadow × feature flag).
     */
    public function computeCached(User $user, Carbon $mesReferencia, ?array $periodoOverride = null, bool $incluirEmpresasScore = false): array
    {
        $mes         = $mesReferencia->copy()->startOfMonth();
        $mesCorrente = Carbon::now()->startOfMonth();
        // Bump v1→v2 em 2026-07-13: correção da dimensão do NPS por cargo
        // invalida os valores cacheados (estrategistas tinham a nota errada).
        // Bump v2→v3 em 2026-07-15 (Fase 80 · DEC-80-C): `computeNpsMedio` passou
        // a somar as atribuições congeladas (`nps_score_assignments`) da Fase 79.
        // Os valores gravados sob a v2 têm a nota ANTIGA — calculada só pelo
        // cruzamento read-time do modelo principal, portanto SEM a nota do NPS
        // Shopee. Sem este bump o `/performance`, os widgets e o PortfolioController
        // continuariam servindo o bônus errado do Redis por até 7 dias (mês fechado)
        // mesmo com o código novo em prod. As chaves v2 viram órfãs e expiram
        // sozinhas por TTL — não precisa (nem deve) rodar `cache:clear`.
        // Bump v3→v4 em 2026-07-16 (Fase 91 · DESEMP-01): `computeUniverso` passou
        // a derivar o universo dos vínculos de serviço (`CarteiraContextService`)
        // em vez da carteira consolidada por `company_id` — os valores gravados
        // sob a v3 têm a nota da carteira consolidada (responsável Shopee
        // "herdando" faturamento/margem de empresas ML que não gerencia). Sem
        // este bump o Redis continuaria servindo o bônus errado por até 7 dias
        // (mês fechado) mesmo com o código novo em prod. As chaves v3 viram
        // órfãs e expiram sozinhas por TTL — não precisa (nem deve) rodar
        // `cache:clear`.
        // Bump v4→v5 em 2026-07-20 (Fase 102 · BON-01/02/03): a régua de baseline
        // de mês fechado mudou de "mês calendário anterior completo" para
        // "janela-de-mesmo-tamanho imediatamente anterior" (`MetricPeriodResolver`),
        // e a fonte de `var_margem_pct` mudou de cálculo manual (R$ absoluto) para
        // `AdmanMetricDiffService` (percentageMargin da Adman) — os valores gravados
        // sob a v4 têm janelas e definição de margem DIFERENTES, invalidando todas
        // as chaves antigas. Sem este bump o Redis continuaria servindo o bônus
        // velho por até 7 dias (mês fechado) mesmo com o código novo em prod.
        // Além do dígito de versão, a chave passa a incluir `period_key` (D-02):
        // sem isso, o modo operacional (`current_month`) e o modo oficial
        // (`YYYY-MM` do mesmo mês) colidiriam na MESMA chave e um pisaria no
        // cache do outro (T-102-04). Helper único: `cacheKey()` — nunca hardcode
        // o formato da chave em outro lugar (ex.: `NpsController::bustarCacheDoBonus`).
        // Bump v5→v6 em 2026-07-21 (Fase 105 · v18.0 · NPSWIN-01/02): a RÉGUA
        // da janela de leitura do componente NPS mudou — competência FECHADA
        // passa a ler o NPS coletado em M+1 (antes lia M, sempre 0 respostas
        // → 0.0 → nota tankada, bug de prod do Felipe/105-CONTEXT.md); mês EM
        // CURSO passa a EXCLUIR o componente (null) em vez de forçar 0.0. Os
        // valores gravados sob a v5 têm a nota ERRADA (janela antiga) —
        // servi-los do Redis por até 7 dias (mês fechado) continuaria pagando
        // bônus errado mesmo com o código novo em prod. As chaves v5 viram
        // órfãs e expiram sozinhas por TTL — não precisa (nem deve) rodar
        // `cache:clear`.
        $cacheKey = $this->cacheKey($user->id, $mes);

        // Mês fechado (passado): dado estável, cache longo — invalida só quando
        // rodar o snapshot mensal ou passar do TTL.
        // Mês em curso: dado evolui hora-a-hora conforme vendas entram na Adman/ML,
        // 10min garante frescor razoável sem estourar as HTTP calls remotas.
        $ttl = $mes->lt($mesCorrente)
            ? now()->addDays(7)
            : now()->addMinutes(10);

        // ── Guard do payload SEM shadow (2026-08-24) ──────────────────────────
        // A chave de cache NÃO distingue `$incluirEmpresasScore` (de propósito:
        // o payload com shadow é superset do sem, e duas chaves dobrariam o
        // custo do compute). O efeito colateral é que qualquer leitura
        // interativa — `/performance/{user}`, o ranking, o Portfolio, que
        // chamam sem o flag — grava aqui um payload em que
        // `empresas_score` é `[]` (a linha ~851 de `compute()` SEMPRE define a
        // chave; sem o flag, vazia). Esse payload fica 7 dias no cache de
        // competência fechada e passa a ser servido a quem PEDIU o shadow.
        //
        // Isso não era só "detalhe faltando": `CompanyScoreSnapshotWriter::sync()`
        // com coleção vazia apaga todas as linhas do par (user, competência) —
        // contrato deliberado da D-122-03. Em produção, abrir a tela de um
        // profissional com o cache frio envenenava a chave e o warm seguinte
        // (≤ 8 min) DELETAVA o detalhe já gravado: 4 dos 9 profissionais
        // estavam sem "Empresas da carteira" em 2026-07 por esse caminho.
        //
        // Por isso: quem pede o shadow nunca aceita um payload que não o tem.
        // Cobre os dois formatos degradados — chave ausente (payload anterior
        // à Fase 120) e chave vazia com carteira não-vazia (leitura interativa).
        // `empresas_carteira > 0` é o que separa o payload degradado do
        // profissional legitimamente SEM carteira (DESEMP-10), cujo
        // `empresas_score` vazio é a resposta correta e não deve custar
        // recompute nenhum.
        if ($incluirEmpresasScore) {
            $cacheado = Cache::get($cacheKey);

            if (is_array($cacheado)
                && (int) ($cacheado['empresas_carteira'] ?? 0) > 0
                && ($cacheado['empresas_score'] ?? []) === []
            ) {
                Cache::forget($cacheKey);
            }
        }

        return Cache::remember(
            $cacheKey,
            $ttl,
            fn () => $this->compute($user, $mesReferencia, $periodoOverride, $incluirEmpresasScore),
        );
    }

    /**
     * Helper público ÚNICO de montagem da chave de cache de `computeCached()`
     * (Fase 102 · BON-04/T-102-03/T-102-04) — nenhum consumidor deve montar
     * a chave na mão. `NpsController::bustarCacheDoBonus` chama este método
     * para invalidar a chave certa após invalidar/revalidar uma resposta NPS.
     *
     * Mesma regra de `resolvePeriodo()`: `$mes` igual ao mês corrente →
     * `period_key='current_month'` (chave operacional); qualquer outro mês →
     * `period_key='YYYY-MM'` (chave oficial/fechada). Os dois modos do MESMO
     * mês calendário NUNCA colidem — chaves distintas (T-102-04).
     */
    public function cacheKey(int $userId, Carbon $mes): string
    {
        $mes         = $mes->copy()->startOfMonth();
        $mesCorrente = Carbon::now()->startOfMonth();
        $periodKey   = $mes->equalTo($mesCorrente) ? 'current_month' : $mes->format('Y-m');

        // v7 (2026-07-21): remoção do filtro created_at no faturamento (item 1)
        // + piso NPS 1.0 no mês em curso (item 2).
        // v8 (2026-07-21): trava de cobertura no fallback Adman do faturamento
        // (remove inflação do baseline parcial de maio). Cada bump força
        // recomputo — senão o Redis serve a versão anterior por até 7 dias.
        // v9 (2026-07-22): faturamento UNIFICADO com a carteira — var_faturamento
        // agora vem do AdmanMetricDiffService (revenue.diff_pct), mesma fonte da
        // carteira/transparência. Desempenho e carteira passam a bater.
        // v10 (2026-07-23, Fase 109 · SHOP-DES-01/02): vínculos Shopee entram no
        // universo/faturamento/score via MetricDiffDispatcher (roteamento por
        // financial_source); a dimensão margem das empresas Shopee vira
        // placeholder=1 (piso da régua) via helper margemPontos — profissional
        // só-Shopee deixa de ser blocked/partial. Os valores gravados sob a v9
        // não passavam pelo dispatcher (Shopee não entrava no score) — servi-los
        // do Redis por até 7 dias continuaria omitindo Shopee do bônus mesmo com
        // o código novo em prod. As chaves v9 viram órfãs e expiram por TTL.
        // v11 (2026-07, Fase 110 · FIXMARG-01/02): margem de contribuição passa
        // a preferir o calculated_fallback LOCAL determinístico sobre o .diff
        // nativo ao vivo quando a cobertura local (por dias-com-linha) é
        // suficiente — o valor da margem muda, então o bump força recomputo.
        // v12 (2026-07-27, Fase 116 · NPSFLOOR): NPS disparado e não respondido
        // passa a contar nota 1 na média do bônus (3º ramo `notasImputadas`).
        // O valor muda para quem tem surveys sem resposta na competência. Sem
        // este bump o Redis serviria o bônus antigo por até 7 dias (mês
        // fechado) mesmo com o código novo em prod. As chaves v11 viram
        // órfãs e expiram sozinhas por TTL — não precisa (nem deve) rodar
        // `cache:clear`.
        // v13 (2026-07-29, Fase 119.1 · D1/NPSMAN-06/07): empresa ELEGÍVEL sem
        // NENHUM link de NPS no mês passa a contar nota 1 na média do bônus
        // (4º ramo `notasSemLink`) — supersede deliberado do D3 da Fase 116.
        // O valor muda para toda empresa elegível sem disparo na competência.
        // Sem este bump o Redis serviria o bônus antigo (empresa sem link
        // ficando de fora) por até 7 dias mesmo com o código novo em prod. As
        // chaves v12 viram órfãs e expiram sozinhas por TTL — não precisa
        // (nem deve) rodar `cache:clear`.
        // v14 (2026-07-30, Fase 120 · AGRE-02/03/04): (1) o payload de
        // `compute()` passa a ganhar `empresas_score` na raiz e
        // `componentes.var_margem_pp` (aditivos, shadow via
        // `CompanyScoreService`), e a feature flag
        // `metrics.performance_company_first_score` (default `false`, Plano
        // 03 desta fase) passa a poder trocar a origem de `nota_final`/
        // `score_status`. (2) O valor cacheado sob v13 tem shape MENOR — sem
        // as duas chaves novas — que os consumidores das Fases 122/123 vão
        // esperar; servir esse payload antigo quebraria a leitura deles.
        // (3) Sem este bump, o Redis serviria o payload v13 (sem
        // `empresas_score`/`var_margem_pp`) por até 7 dias em mês fechado,
        // sob a MESMA chave que o código novo passaria a escrever —
        // inconsistência de shape entre requests concorrentes. O bump vem
        // ANTES da mudança de shape de propósito (Task 3), nunca depois —
        // nunca existe janela em que o código novo escreva shape novo sob a
        // chave antiga (T-120-02). (4) As chaves v13 viram órfãs e expiram
        // sozinhas por TTL — não precisa (nem deve) rodar `cache:clear`.
        // v15 (2026-07-31, quick 260731-pvk): `componentes.var_faturamento_pct`
        // deixa de ser MÉDIA e passa a ser MEDIANA das % de variação por
        // empresa (D-1 — nenhuma empresa é excluída, só o peso muda). O VALOR
        // muda para toda carteira com 3+ empresas e distribuição assimétrica
        // (ex.: um outlier de baseline residual não manda mais sozinho no
        // resultado — ver docblock de `computeVarFaturamento()`). Sem este
        // bump o Redis serviria a nota antiga (média) por até 7 dias em mês
        // fechado mesmo com o código novo em prod. As chaves v14 viram órfãs
        // e expiram sozinhas por TTL — não precisa (e não deve) rodar
        // `cache:clear` (incidente 2026-07-30: derrubou o site inteiro).
        // v16 (2026-08-03, Fase 122 · SNAP-05/D-122-04): `margem_amostra`
        // muda de SHAPE quando o shadow roda — passa a medir cobertura de
        // `margem_var_pp` (pontos percentuais, por empresa) em vez de
        // `contribution_margin_pct.diff_pct` (variação relativa agregada), e
        // ganha as chaves `base`/`legado`. Com o shadow desligado o shape
        // continua o de sempre (3 chaves, mesmos valores) — o bump é por
        // causa do ramo shadow, não do payload legado. Sem este bump o Redis
        // serviria o payload v15 (shape antigo de `margem_amostra` sob o
        // shadow) por até 7 dias em mês fechado, sob a MESMA chave que o
        // código novo passaria a escrever. As chaves v15 viram órfãs e
        // expiram sozinhas por TTL — `cache:clear` no VPS é DESNECESSÁRIO e
        // PROIBIDO (incidente 2026-07-30 derrubou o site inteiro).
        // v17 (2026-08-05): a NOTA muda de método. `nota_final` passa a vir de
        // `computeNotaFinalPorIndicador()` (régua por loja → média por
        // indicador, denominador independente), `pontos_componentes` passa a
        // ser fracionário e a loja Shopee sai da média de margem. É o bump
        // mais consequente da série: sem ele o dashboard serviria a nota
        // ANTIGA — que decide bônus — por até 7 dias em mês fechado, sob a
        // mesma chave que o código novo escreve. Chaves v16 viram órfãs e
        // expiram por TTL; `cache:clear` no VPS continua PROIBIDO.
        // v18 (2026-08-10, quick 260810-mbv): a margem volta a existir no MÊS
        // CORRENTE — `AdmanMetricDiffService` passa a comparar contra a janela
        // baseline do período em vez de ficar sem `diff_pp` fora da
        // janela-igual. Muda `margem_pontos` por empresa, a média do indicador
        // e, por consequência, `nota_final` do mês em curso (mês fechado é
        // intocado). Sem o bump o payload v17 do mês corrente — com a margem
        // vazia — continuaria sendo servido. Chaves v17 viram órfãs e expiram
        // por TTL; `cache:clear` no VPS continua PROIBIDO.
        // v19 (2026-08-10, quick 260810-mt8): a nota passa a dividir SEMPRE por
        // 3, com indicador ausente valendo zero (antes promediava só os
        // presentes). Muda `nota_final` e, por consequência, `faixa_bonus` de
        // toda carteira que não tem os três indicadores — o caso vivo é a
        // só-Shopee, que sai de média-de-dois para teto de 3,33. Sem o bump o
        // Redis serviria a nota do método anterior por até 7 dias em mês
        // fechado. Chaves v18 viram órfãs e expiram por TTL; `cache:clear` no
        // VPS continua PROIBIDO.
        // v20 (2026-08-11, Fase 136/D-10): o desempate de fonte financeira
        // (`FinancialSourceResolver`) passa a exigir `cust_id` — 'adman' não
        // vence mais 'shopee' só por estar presente. Muda `fonte_financeira`,
        // os campos de faturamento/margem e `nota_final` de qualquer carteira
        // que tenha empresa com vínculo performance+shopee sem conta Adman de
        // fato (ver `.planning/learnings/desempenho-bonificacao.md` §0.04). O
        // mesmo payload também passa a poder carregar o override de métrica
        // manual da Fase 136 (Plano 02+). UMA ÚNICA versão para a fase
        // inteira — o Plano 03 volta a mudar cálculo neste payload e NÃO
        // bumpa para v21 (nada foi deployado entre os planos). Sem o bump o
        // Redis serviria a nota do desempate antigo por até 7 dias em mês
        // fechado. Chaves v19 viram órfãs e expiram por TTL; `cache:clear` no
        // VPS continua PROIBIDO.
        return sprintf('desempenho.compute.v20.%d.%s', $userId, $periodKey);
    }

    /**
     * Wrapper fino da Fase 106 (SC2 — gate isCached): responde "está em
     * cache?" para (user, mês) SEM nunca computar. Reusa a MESMA chave que
     * `computeCached()` escreve/lê (`cacheKey()`) — nunca dispara `compute()`
     * nem `computeCached()`, portanto zero chamada HTTP à Adman/ML. É o
     * gancho que `PerformanceController` (Plan 106-02) usa pra decidir
     * "computo ou degrado" sem pagar o custo do compute() caro.
     */
    public function isCached(User $user, Carbon $mes): bool
    {
        return Cache::has($this->cacheKey($user->id, $mes));
    }

    /**
     * Resolve o `$periodo` (shape do `MetricPeriodResolver`) usado por
     * `compute()` quando nenhum `$periodoOverride` é passado (Fase 102 ·
     * BON-01/BON-02, decisão travada Opção B — ver 102-RESEARCH.md
     * "Onde entra o modo bônus vs operacional").
     *
     * `$mes` é o MÊS CORRENTE (hoje) → `period_key='current_month'`
     * (operacional, baseline alinhada por dia). Qualquer outro mês →
     * `period_key='YYYY-MM'` (`closed_period`, baseline janela-de-mesmo-
     * tamanho). Preserva 100% dos ~40 call-sites existentes de
     * `compute($user, $mes)` — ninguém precisa passar `$periodoOverride`.
     */
    private function resolvePeriodo(Carbon $mes): array
    {
        $mesCorrente = now()->startOfMonth();

        return $mes->copy()->startOfMonth()->equalTo($mesCorrente)
            ? $this->periodResolver->resolve(['period_key' => 'current_month'])
            : $this->periodResolver->resolve(['period_key' => $mes->format('Y-m')]);
    }

    /**
     * Modo OFICIAL de bônus (Fase 102 · BON-02): resolve o último mês
     * FECHADO via `MetricPeriodResolver` (`last_closed_month`) e delega para
     * `compute()` com o `$periodo` já resolvido — julho/2026 paga a
     * competência junho/2026 (janela 01/06..30/06 vs baseline
     * janela-de-mesmo-tamanho 02/05..31/05).
     *
     * Diferença vs `compute($user, $mesJunho)` "genérico" (sem override):
     * as janelas current/baseline batem EXATAMENTE (mesmo resolver, mesmo
     * mês), mas só este método popula os metadados `bonus_payment_month`/
     * `bonus_competence_month` dentro do `$periodo` resolvido — o shape
     * público de `compute()` ainda não expõe esses metadados nesta fase
     * (BON-04 fica para o Plan 102-02).
     */
    public function computeOficial(User $user): array
    {
        $periodo = $this->periodResolver->resolve(['period_key' => 'last_closed_month']);
        $mes     = Carbon::parse($periodo['current_start'])->startOfMonth();

        return $this->compute($user, $mes, $periodo);
    }

    /**
     * @param  bool  $incluirEmpresasScore  Fase 120 (AGRE-02, C-01) — SHADOW.
     *   Decide SE `CompanyScoreService::computeEmpresasScore()` roda (custo:
     *   dispatcher por empresa + NPS por empresa, com HTTP síncrono à Adman
     *   quando aplicável). Default `false` preserva os ~40 call-sites
     *   existentes (incluindo `computeOficial()` e os 3 controllers de
     *   leitura interativa) sem uma linha de edição — D-04, o shadow só roda
     *   nos comandos (`desempenho:warm-cache`/`desempenho:consolidar-mes`).
     *   É um sinal INDEPENDENTE da feature flag
     *   `config('metrics.performance_company_first_score')`, que decide QUAL
     *   resultado vira `nota_final`/`score_status` — confundir os dois faz o
     *   custo vazar pra tela mesmo com a flag desligada. Nenhum método legado
     *   (`computeNotaFinal`, `computeScoreStatus`, `computeVarFaturamento`,
     *   `computeVarMargem`, `computeNpsWindow`, `margemPontos`) recebe este
     *   parâmetro nem lê a flag internamente — só este orquestrador decide.
     */
    public function compute(User $user, Carbon $mesReferencia, ?array $periodoOverride = null, bool $incluirEmpresasScore = false): array
    {
        $mes     = $mesReferencia->copy()->startOfMonth();
        $periodo = $periodoOverride ?? $this->resolvePeriodo($mes);

        // ── Universo (carteira ativa no mês) ─────────────────────────────────
        $universo = $this->computeUniverso($user, $mes);

        if ($universo['sem_carteira']) {
            return $this->shapeSemCarteira($user, $mes, $universo['motivo']);
        }

        /** @var EloquentCollection<int, \App\Models\Company> $companies */
        $companies  = $universo['companies_elegiveis'];
        $contadores = $universo['contadores'];
        $fontes     = $universo['fontes'];

        // ── Invalidação por competência (item 3/4 · 2026-07-21) ──────────────
        // Empresas que o admin tirou do bônus DESTE mês (empresa inteira:
        // financeiro E NPS). Competência = $mes (mês financeiro M); o NPS de M
        // coletado em M+1 usa a MESMA competência M (não o mês deslocado) —
        // por isso o set é resolvido aqui, 1×, e repassado ao computeNpsWindow.
        $invalidadas = BonusInvalidacao::companyIdsInvalidadas($mes);
        if ($invalidadas->isNotEmpty()) {
            $companies = $companies
                ->reject(fn ($c) => $invalidadas->contains($c->id))
                ->values();
        }

        // ── Score por empresa (Fase 119/120 · AGRE-02) ────────────────────────
        // Nasceu como shadow opcional (rodava só sob `$incluirEmpresasScore`
        // ou com a feature flag ligada). Desde 2026-08-05 roda SEMPRE: a nota
        // oficial é a agregação POR INDICADOR sobre estas linhas
        // (`computeNotaFinalPorIndicador`), então elas viraram insumo
        // obrigatório de todo `compute()`. `$incluirEmpresasScore` sobrevive
        // apenas para decidir se as linhas vão EXPOSTAS no payload — são uma
        // por loja da carteira, e embuti-las em todo payload incharia cache e
        // snapshots à toa.
        //
        // `$mes`, `$periodo` e `$invalidadas` já prontos — exatamente os
        // insumos que `computeEmpresasScore()` exige. `$invalidadas` SEMPRE
        // explícito (nunca null): deixar o serviço reresolver duplicaria a
        // query, e `collect()` vazio silenciaria a invalidação por competência
        // já resolvida acima.
        $empresasScore = $this->companyScoreService->computeEmpresasScore($user, $mes, $periodo, $invalidadas);

        // ── 4 componentes independentes ──────────────────────────────────────
        // Faturamento/margem usam SÓ as empresas elegíveis financeiramente
        // (`financial_metrics_eligible=true` — DESEMP-04), roteadas por fonte
        // (`$fontes`, Fase 109 · SHOP-DES-01) via MetricDiffDispatcher. NPS
        // continua somando todas as áreas do profissional, sem filtro de
        // elegibilidade financeira (DESEMP-03/D-91-02 — computeNpsMedio
        // INTOCADO).
        //
        // Fase 105 (v18.0 · NPSWIN-01/02, D1 do 105-CONTEXT.md) — a janela de
        // bônus dura 2 meses: M coleta o FINANCEIRO, M+1 coleta o NPS (o
        // cliente só avalia DEPOIS do trabalho feito). `$periodo['is_closed']`
        // já resolvido acima (linha ~314) é o sinal CANÔNICO de "mês fechado"
        // — nunca recalcular via now()/between aqui.
        $nps           = $this->computeNpsWindow($user, $mes, $periodo['is_closed'], $invalidadas);
        $varFatData    = $this->computeVarFaturamento($user, $mes, $companies, $periodo, $fontes);
        $varMargemData = $this->computeVarMargem($user, $mes, $companies, $periodo, $fontes);
        $absent        = $this->computeAbsenteismo($user, $mes);

        $varFat           = $varFatData['pct'];
        $empresasBaseline = $varFatData['empresas_com_baseline'];

        $varMargem      = $varMargemData['pct'];
        $nComMargemReal = $varMargemData['n_com_margem_real'];
        $nElegivelAdman = $varMargemData['n_elegivel'];

        // ── Margem placeholder=1 para empresas Shopee (Fase 109 · SHOP-DES-02,
        // DECISÃO TRAVADA — ver 109-03-PLAN.md <design_margem_placeholder>) ──
        // `n_shopee_placeholder` é recomputado a partir do `$companies` JÁ
        // FILTRADO por `$invalidadas` (acima) + mapa `fontes` — NUNCA reaproveita
        // o contador cru pré-filtro de `computeUniverso` (empresa Shopee
        // invalidada na competência não pode inflar o denominador do blend,
        // mesmo tratamento de `computeVarFaturamento`/`computeVarMargem`, que já
        // recebem `$companies` filtrado).
        $nShopeePlaceholder = $companies
            ->filter(fn ($c) => ($fontes[$c->id] ?? null) === 'shopee')
            ->count();
        $margemPontos = $this->margemPontos($varMargem, $nComMargemReal, $nShopeePlaceholder);

        // ── Métricas de comparação entre modelos de agregação ─────────────────
        // Registrado aqui porque é o ponto exato onde a distinção importa:
        // régua-da-média NÃO é média-das-réguas. `margemPontos()` (legado)
        // aplica a régua de margem UMA VEZ sobre a variação agregada da
        // carteira inteira; `computeNotaFinalPorEmpresa`/
        // `computeNotaFinalPorIndicador` aplicam a régua POR EMPRESA dentro do
        // `CompanyScoreService` e promediam DEPOIS. O invariante que o docblock
        // de `margemPontos()` declara — "só-performance devolve exatamente
        // `reguaMargem($varMargemReal)`, regressão zero" — NÃO vale nos
        // caminhos por empresa. Isso é esperado: é o ponto central da
        // milestone v21.0.
        //
        // `nota_final_por_empresa`/`score_status_por_empresa` seguem
        // calculados e expostos no payload como metadado de auditoria, para
        // comparar os modelos numa mesma competência sem recomputar nada.
        $statusPorEmpresa = $this->computeScoreStatusPorEmpresa($empresasScore);
        $notaPorEmpresa   = $this->computeNotaFinalPorEmpresa($empresasScore);

        // D-91-01 aplicada também ao par exposto: o valor que a Fase 121
        // compara tem que ser byte-idêntico ao que `nota_final` seria
        // com a flag ligada, inclusive esta regra.
        if ($statusPorEmpresa === 'blocked') {
            $notaPorEmpresa = null;
        }

        // ── Nota oficial: agregação POR INDICADOR (2026-08-05) ───────────────
        // Substituiu a bifurcação por feature flag entre o legado
        // (`computeNotaFinal`, régua UMA vez sobre a média da carteira) e o
        // company-first da Fase 120 (`computeNotaFinalPorEmpresa`, que exige a
        // loja completa). Nenhum dos dois é mais o caminho oficial:
        //
        //  - o legado invertia a ordem das operações — "régua da média" não é
        //    "média das réguas", e era a causa de a nota do sistema divergir
        //    do fechamento manual do time;
        //  - o company-first acertava a ordem, mas descartava a loja inteira
        //    por falta de UM indicador (47 lojas sem faturamento e 75 sem
        //    margem na competência 2026-06 medida em produção).
        //
        // `computeNotaFinalPorIndicador` mantém a régua por loja e usa
        // denominador independente por indicador. Os dois métodos anteriores
        // continuam sendo calculados como metadado de auditoria/comparação —
        // `nota_final_por_empresa` no payload e o legado no relatório de
        // impacto — mas NÃO decidem mais bônus.
        $porIndicador = $this->computeNotaFinalPorIndicador($empresasScore);

        $nota        = $porIndicador['nota'];
        $scoreStatus = $statusPorEmpresa;

        // Nota pelo método LEGADO (régua aplicada uma vez sobre a % agregada da
        // carteira), preservada como metadado de auditoria. Não decide nada —
        // é o "antes" que o `desempenho:comparar-score-empresa` usa para
        // decompor o delta contra a nota oficial, e o que permite medir o
        // impacto da mudança numa competência sem recomputar duas vezes.
        $notaLegado = $this->computeNotaFinal($nps, $varFat, $margemPontos);

        // D-91-01: blocked (zero vínculos financeiros elegíveis — ex.:
        // só-Shopee) força nota_final=null/faixa_bonus=null. Decisão do
        // usuário 2026-07-16 — sem nota oficial até a diretoria aprovar régua
        // de bônus para carteira sem financeiro. Com nota null, a ordenação
        // existente (`sortByDesc(nota ?? -1)`) já manda pro fim do ranking.
        if ($scoreStatus === 'blocked') {
            $nota = null;
        }

        // ── Classificação + promoção DESEMP-08 ───────────────────────────────
        $faixaInicial   = $nota !== null ? $this->classificarFaixa($nota) : null;
        $faixaPromovida = false;
        $faixaFinal     = $faixaInicial;

        if ($faixaInicial !== null) {
            $promocao       = $this->promoverPor2MesesConsecutivos($user, $mes, $faixaInicial, $nota);
            $faixaFinal     = $promocao['faixa'];
            $faixaPromovida = $promocao['promovida'];
        }

        // ── Metadados do período (UI mostra aviso "mês em curso") ─────────────
        $hoje          = now();
        $mesCorrente   = $mes->copy()->startOfMonth();
        $ehMesEmCurso  = $hoje->between($mesCorrente, $mesCorrente->copy()->endOfMonth());
        $diasDecorridos = $ehMesEmCurso ? $hoje->day : $mesCorrente->daysInMonth;
        $diasNoMes      = $mesCorrente->daysInMonth;

        // ── Amostra da margem (Fase 110 · Plan 02 · FIXMARG-03; Fase 122 ·
        // SNAP-05 · D-122-04) ──────────────────────────────────────────────
        // Legado: cobertura de `contribution_margin_pct.diff_pct` (variação
        // RELATIVA, agregada da carteira) — alimenta o gate de qualidade do
        // congelamento mensal (`ConsolidarMesDesempenho`); cobertura < limiar
        // RECUSA persistir o snapshot. Preservado INTOCADO — nenhum valor
        // muda, nem com o shadow ligado.
        $margemAmostraLegado = [
            'n_real'     => $nComMargemReal,
            'n_elegivel' => $nElegivelAdman,
            'cobertura'  => $nElegivelAdman > 0 ? round($nComMargemReal / $nElegivelAdman, 4) : 1.0,
        ];
        $margemAmostra = $margemAmostraLegado;

        // SNAP-05 — `margem_amostra` mede cobertura de `margem_var_pp`
        // (PONTOS PERCENTUAIS, por empresa), a grandeza que a milestone v21.0
        // usa para pagar (o `.diff` nativo é instável na fronteira — ver
        // 122-CONTEXT.md item 4).
        // Denominador: linhas de `$empresasScore` com `fonte_financeira`
        // não-nula e diferente de `shopee` (Shopee não fornece margem;
        // `sem_fonte` não tem expectativa de margem) — mesma semântica de
        // "ausência não é degradação" do denominador legado. `legado` preserva
        // os 3 números de sempre: o gate FIXMARG-03 mediu
        // `cobertura_prev=0.6415` em produção (probe MPP-04) e trocar a base
        // do gate junto com a base do relatório recusaria a persistência
        // mensal para quase todo mundo — quem escolhe a base do gate é o
        // Plano 03 (D-122-05), nunca esta troca implícita.
        // `?? null` defensivo: dublês de teste de outras suítes (Fase
        // 120/121, anteriores a este plano) montam a linha só com os
        // campos que seus próprios cenários exercitam — nunca lançar por
        // propriedade ausente num objeto de teste alheio a este plano.
        // 2026-08-05: deixou de ser condicional ao shadow — `$empresasScore`
        // agora é sempre computado (é insumo da nota oficial).
        $elegiveisPp = $empresasScore->filter(
            fn ($e) => ($e->fonte_financeira ?? null) !== null && ($e->fonte_financeira ?? null) !== 'shopee'
        );
        $nElegivelPp = $elegiveisPp->count();
        $nRealPp     = $elegiveisPp->filter(fn ($e) => ($e->margem_var_pp ?? null) !== null)->count();

        $margemAmostra = [
            'n_real'     => $nRealPp,
            'n_elegivel' => $nElegivelPp,
            'cobertura'  => $nElegivelPp > 0 ? round($nRealPp / $nElegivelPp, 4) : 1.0,
            'base'       => 'margem_var_pp',
            'legado'     => $margemAmostraLegado,
        ];

        $payload = [
            'user_id'               => $user->id,
            'user_name'             => $user->name,
            'mes_referencia'        => $mes->toDateString(),
            'sem_carteira'          => false,
            'motivo'                => null,
            // Compat DESEMP-05: empresas_carteira passa a receber o valor de
            // empresas_unicas (Fase 91) — mesmo valor prático pra profissional
            // só-performance; consumidores existentes (Fase 92) não recomputam.
            'empresas_carteira'     => $contadores['empresas_unicas'],
            'empresas_com_baseline' => $empresasBaseline,
            // ── Amostra da margem — ver bloco `$margemAmostra` calculado
            // acima (Fase 110 · FIXMARG-03; Fase 122 · SNAP-05 · D-122-04).
            'margem_amostra' => $margemAmostra,
            'componentes' => [
                'nps_medio'           => $nps,
                'var_faturamento_pct' => $varFat,
                'var_margem_pct'      => $varMargem,
                'absenteismo_pct'     => $absent,
                // Fase 120 (AGRE-04) — metadado ADITIVO de auditoria, NUNCA
                // insumo da nota: `null` quando o shadow não rodou (C-03).
                'var_margem_pp'       => $this->varMargemPpAgregado($empresasScore),
            ],
            // Ajuste 2026-07-10 · pontos 1-5 por componente (após régua), pra
            // UI expor a conta que gerou a nota (ex: "(3+5+4)/3 = 4,00") em
            // vez do denominador fixo "/5,00". Nulls preservados — só entram
            // na média os componentes disponíveis.
            //
            // 2026-08-05: passa a vir de `computeNotaFinalPorIndicador()` —
            // média dos pontos POR LOJA, com denominador independente. Antes
            // eram os pontos da régua aplicada sobre a % agregada da carteira,
            // que é justamente a conta que deixou de valer. Como a UI monta a
            // string da nota a partir daqui, manter a fonte antiga exibiria
            // uma conta que não fecha com a `nota_final` mostrada ao lado.
            //
            // Os valores agora são fracionários (ex: 3,8261), não mais
            // inteiros de 1 a 5 — é a média de N lojas, não uma régua única.
            'pontos_componentes' => [
                'nps'         => $porIndicador['nps'],
                'faturamento' => $porIndicador['faturamento'],
                'margem'      => $porIndicador['margem'],
            ],
            'nota_final'      => $nota,
            'faixa_bonus'     => $faixaFinal,
            'faixa_promovida' => $faixaPromovida,
            // Metadata para UI mostrar aviso e ajudar analistas a entender por
            // que variações podem parecer baixas no início do mês. Comparação
            // já é justa (dia 1..hoje vs mesmo range mês anterior), mas número
            // de dias na amostra afeta significância estatística.
            'periodo_meta' => [
                'em_curso'        => $ehMesEmCurso,
                'dias_decorridos' => $diasDecorridos,
                'dias_no_mes'     => $diasNoMes,
            ],
            // ── Metadados de período (Fase 102 · BON-04) ──────────────────────
            // Espelha o `$periodo` já resolvido (via `resolvePeriodo()` ou
            // `computeOficial()`) — UI consome pra rotular a janela comparada
            // sem recalcular nada. `periodo_meta` acima é preservado por
            // transição (remoção fica pra Fase 104).
            'periodo' => [
                'current_start'   => $periodo['current_start'],
                'current_end'     => $periodo['current_end'],
                'baseline_start'  => $periodo['baseline_start'],
                'baseline_end'    => $periodo['baseline_end'],
                'mode'            => $periodo['mode'],
                'comparison_mode' => $periodo['comparison_mode'],
            ],
            // ── Metadados de competência de bônus (Fase 102 · BON-02) ─────────
            // Derivados de `$mes` (não do `$periodo` resolvido) — qualquer mês
            // FECHADO (inclusive `compute($u, $mesJunho)` sem override) rotula
            // a competência/pagamento igual. Mês EM CURSO não tem competência
            // (o bônus só paga mês fechado) — nulls coerentes com o resolver
            // (`bonus_competence_month`/`bonus_payment_month` também nulls fora
            // de `last_closed_month`).
            'bonus' => [
                'competence_month' => $ehMesEmCurso ? null : $mes->format('Y-m'),
                'payment_month'    => $ehMesEmCurso ? null : $mes->copy()->addMonthNoOverflow()->format('Y-m'),
            ],
            // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) ──────────────
            'empresas_unicas'               => $contadores['empresas_unicas'],
            'vinculos_servico'              => $contadores['vinculos_servico'],
            'vinculos_financeiros'          => $contadores['vinculos_financeiros'],
            'vinculos_sem_fonte_financeira' => $contadores['vinculos_sem_fonte_financeira'],
            'score_status'                  => $scoreStatus,
            'componentes_disponiveis' => [
                // Fase 105 (v18.0 · NPSWIN-02): deixa de ser hardcoded `true`
                // (DESEMP-03 forçava 0.0 sempre "disponível") — agora reflete
                // a exclusão do componente (mês em curso OU M+1 ainda em
                // coleta com 0 respostas). A UI "Em curso"/janela-em-coleta
                // usa esta flag pra esconder o card de NPS sem tankar a nota.
                'nps_medio'           => $nps !== null,
                'var_faturamento_pct' => $varFat !== null,
                'var_margem_pct'      => $varMargem !== null,
            ],
            // Linha por empresa do `CompanyScoreService` (Fase 119). Desde
            // 2026-08-05 ela SEMPRE é calculada (alimenta a nota oficial),
            // mas só vai EXPOSTA no payload quando `$incluirEmpresasScore`
            // pede — são N linhas por profissional, e enfiá-las em todo
            // payload incharia o cache e os snapshots à toa.
            'empresas_score' => $incluirEmpresasScore ? $empresasScore->values()->all() : [],
        ];

        // ── Fase 121 (D-05) — comparação entre os métodos de agregação ────────
        // `nota_final_por_empresa` (company-first, exige loja completa) segue
        // exposta como METADADO de auditoria, ao lado da nota oficial que hoje
        // vem de `computeNotaFinalPorIndicador()`. Serve para comparar os dois
        // modelos numa mesma competência sem recomputar nada.
        // 2026-08-05: deixou de ser condicional ao shadow — `$empresasScore`
        // agora é sempre computado.
        $payload['nota_final_por_empresa']   = $notaPorEmpresa;
        $payload['score_status_por_empresa'] = $statusPorEmpresa;
        $payload['nota_final_legado']        = $notaLegado;

        return $payload;
    }

    // ═══ Métodos privados por componente ═══════════════════════════════════

    /**
     * Verifica se o user tem carteira ativa no mês. Retorna:
     *  - `['sem_carteira' => true, 'motivo' => '...']` quando vazia
     *  - `['sem_carteira' => false, 'contadores' => array, 'companies_elegiveis' => EloquentCollection]`
     *    caso contrário
     *
     * Fase 91 (DESEMP-01/04/07): o universo deriva dos VÍNCULOS DE SERVIÇO
     * ativos do profissional via `CarteiraContextService::forUser()`, não
     * mais de `$user->companies()` (carteira consolidada por `company_id`).
     * `sem_carteira=true` só dispara com ZERO vínculos de QUALQUER setor —
     * um profissional só-Shopee TEM vínculo (Shopee), então permanece com
     * `sem_carteira=false` mesmo sem nenhum vínculo elegível financeiramente
     * (esse caso vira `score_status='blocked'` em `compute()`, não `sem_carteira`).
     *
     * `companies_elegiveis` é a `EloquentCollection<Company>` deduplicada por
     * `company_id` (Pitfall 4 do 91-RESEARCH.md — evita somar a MESMA empresa
     * 2× no SUM financeiro caso ela tenha 2 vínculos elegíveis) contendo só as
     * empresas com pelo menos 1 vínculo `financial_metrics_eligible=true` —
     * é o que alimenta `computeVarFaturamento`/`computeVarMargem` (recebem
     * também o mapa `fontes`, Fase 109).
     *
     * Fase 109 (SHOP-DES-01/02) — além de `companies_elegiveis`, monta o mapa
     * `fontes` (company_id → financial_source vencedora) e o contador
     * `n_shopee_placeholder` (empresas elegíveis com fonte 'shopee').
     * REGRA DE DESEMPATE (TRAVADA, idêntica ao Plano 02/Carteira —
     * `PortfolioController::fontesFinanceirasPorEmpresa()`): quando a MESMA
     * empresa tem vínculo elegível 'adman' E 'shopee', a fonte vencedora é
     * 'adman' (performance vence). `n_shopee_placeholder` aqui é PRÉ-filtro
     * de invalidação por competência — `compute()` recomputa a contagem
     * definitiva a partir do `$companies` já filtrado por `$invalidadas`
     * antes de alimentar o blend `margemPontos` (ver bloco de invalidação em
     * `compute()`), nunca reaproveita este valor cru.
     *
     * DESEMP-10: "Sem carteira em julho/2026" (motivo pt-BR).
     */
    private function computeUniverso(User $user, Carbon $mes): array
    {
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true]);

        if ($vinculos->isEmpty()) {
            return [
                'sem_carteira' => true,
                'motivo'       => "Sem carteira em {$this->mesExtenso($mes)}",
            ];
        }

        $contadores = $this->carteiraContext->contadores($vinculos);

        $elegiveis = $vinculos->where('financial_metrics_eligible', true);

        $companyIdsElegiveis = $elegiveis->pluck('company_id')->unique();

        $companiesElegiveis = Company::whereIn('id', $companyIdsElegiveis)->get();

        // Mapa company_id → fonte financeira vencedora ('adman'|'shopee').
        // Fase 136 (D-10): 'adman' vence quando a MESMA empresa tem os dois
        // vínculos elegíveis **e a empresa tem `cust_id`** (conta Adman de
        // fato) — antes desta fase, 'adman' vencia sem checar isso. Delegado
        // ao `FinancialSourceResolver`, a fonte ÚNICA desta regra (também
        // usada por `CompanyScoreService::computeEmpresasScore()` e
        // `PortfolioController::fontesFinanceirasPorEmpresa()`). Nenhuma
        // query nova: `$companiesElegiveis` já é carregado ACIMA, antes do
        // desempate.
        $fontes = $this->financialSourceResolver->resolverPorEmpresa($elegiveis, $companiesElegiveis->keyBy('id'));

        $nShopeePlaceholder = $fontes->filter(fn ($f) => $f === 'shopee')->count();

        return [
            'sem_carteira'         => false,
            'contadores'           => $contadores,
            'companies_elegiveis'  => $companiesElegiveis,
            'fontes'               => $fontes,
            'n_shopee_placeholder' => $nShopeePlaceholder,
        ];
    }

    /**
     * Classifica o `score_status` do profissional no mês (Fase 91 · DESEMP-06,
     * semântica D-91-02 — resolvida pelo orquestrador a partir das decisões
     * do usuário, ver `91-CONTEXT.md`):
     *
     *  - `blocked`  — ZERO vínculos financeiros elegíveis. Fase 109
     *    (SHOP-DES-01/02): Shopee agora É fonte financeira elegível
     *    (`financial_metrics_eligible=true`, ver `CarteiraContextService`),
     *    então `vinculos_financeiros` conta Shopee — só-Shopee NÃO fica mais
     *    `blocked` (era o comportamento pré-Fase 109). `blocked` sobra pra
     *    setores sem fonte financeira nenhuma (`polos`/`publicacao`/`outros`).
     *  - `partial`  — tem vínculo financeiro elegível, mas algum componente
     *    financeiro está indisponível no período (sem baseline de faturamento
     *    OU margem/margem-placeholder indisponível).
     *  - `official` — todos os componentes disponíveis. Profissional MISTO
     *    (Performance+Shopee) é OFFICIAL — o financeiro vem só do subconjunto
     *    elegível dele; isto CORRIGE a proposta original do 91-RESEARCH.md,
     *    que sugeria `partial` para misto. Profissional só-Shopee com
     *    faturamento com baseline TAMBÉM é OFFICIAL (margem placeholder=1
     *    conta como componente disponível — Fase 109).
     *
     * @param  ?float  $margemPontos  já é o blend real+placeholder Shopee
     *   (`margemPontos()`, Fase 109) — não mais a % bruta de `var_margem_pct`.
     */
    private function computeScoreStatus(array $contadores, ?float $varFat, ?float $margemPontos): string
    {
        // APOSENTADO EM 2026-08-05 — `compute()` usa
        // `computeScoreStatusPorEmpresa()`, que deriva o status da
        // DISTRIBUIÇÃO por empresa em vez da presença binária de dois sinais
        // agregados. Preservado como referência histórica e para o relatório
        // de impacto; não reintroduzir sem decisão explícita.
        if ($contadores['vinculos_financeiros'] === 0) {
            return 'blocked';
        }

        if ($varFat === null || $margemPontos === null) {
            return 'partial';
        }

        return 'official';
    }

    /**
     * Resolve o componente NPS do bônus a partir do sinal `is_closed` do
     * período (Fase 105 · v18.0 · NPSWIN-01/02, D1 do 105-CONTEXT.md).
     *
     * Modelo de negócio: a janela de bônus dura 2 meses — mês M coleta o
     * FINANCEIRO, mês M+1 coleta o NPS (o cliente só avalia DEPOIS do
     * trabalho feito; o NPS de M é enviado/respondido em M+1).
     *
     *  - Mês EM CURSO (`is_closed=false`): NPS = **piso 1.0** (decisão diretoria
     *    2026-07-21). O NPS do mês em curso só é coletado no mês seguinte, então
     *    ainda não existe — mas EXCLUIR o componente (retornar null) inflava a
     *    nota, deixando a pessoa "começar o mês bem" só com margem+faturamento.
     *    Usar 1.0 como piso mantém o NPS na média desde o dia 1; quando a coleta
     *    de M+1 começar (no mês fechado), a nota real substitui o piso. Supera a
     *    D1 original da Fase 105 (que excluía o NPS em curso).
     *  - Mês FECHADO (`is_closed=true`): a janela de NPS desloca +1
     *    (`addMonthNoOverflow` — evita o edge do dia 31) e delega a
     *    `computeNpsMedio($user, $mesNps)`. O financeiro (chamado
     *    separadamente em `compute()`) CONTINUA em `$mes`, sem deslocamento.
     *
     *    Mecânica exclui-vs-penaliza (usa a sentinela 0.0 de
     *    `computeNpsMedio` — média não-vazia é sempre >= 1.0, DESEMP-03):
     *      · `computeNpsMedio($mesNps) == 0.0` (vazio) E a janela M+1 AINDA
     *        EM COLETA → `null` (EXCLUÍDO — não tanka; a competência ainda
     *        vai receber NPS).
     *      · `computeNpsMedio($mesNps) == 0.0` E a janela M+1 JÁ FECHOU →
     *        mantém `0.0` (PENALIZA — 0 respostas de verdade, decisão da
     *        diretoria).
     *      · Qualquer valor >= 1.0 → usa como está (não passa pela
     *        mecânica exclui-vs-penaliza).
     *
     *    ⚠ BLOCKER 1 (plan-checker) — "a janela M+1 já fechou?" é comparada
     *    por DATA, NUNCA por timestamp. O cron `desempenho:consolidar-mes`
     *    (105-02) congela no ÚLTIMO DIA de M+1 às 14h — `endOfMonth($mesNps)`
     *    é 23:59:59 do MESMO dia, então `endOfMonth($mesNps) < now()` seria
     *    SEMPRE FALSO nesse instante e toda competência com 0 NPS real cairia
     *    em "ainda coletando" (null) em vez de "fechada com 0" (0.0) — o
     *    OPOSTO da regra. `now()->startOfDay()->gte(endOfMonth($mesNps)->
     *    startOfDay())` (hoje é >= o último dia calendário de M+1) é imune a
     *    esse boundary — ver `JanelaNpsBonusTest::test_boundary_...`.
     *
     * @return ?float null quando excluído (em curso OU M+1 ainda coletando
     *                com 0 respostas); float (>= 0.0) quando disponível.
     */
    private function computeNpsWindow(User $user, Carbon $mes, bool $mesFechado, ?Collection $invalidadas = null): ?float
    {
        if (! $mesFechado) {
            // Mês em curso ainda não tem NPS (vem em M+1). Piso 1.0 mantém o
            // componente na média desde o dia 1 — não infla a nota (decisão
            // diretoria 2026-07-21). A nota real entra quando o mês fecha.
            return 1.0;
        }

        // O NPS da competência M é coletado em M+1, mas a invalidação de empresa
        // é chaveada pela competência M — o set $invalidadas (resolvido em
        // compute() com $mes) é repassado como está, SEM deslocar (item 3/4).
        $mesNps = $mes->copy()->addMonthNoOverflow();
        $nps    = $this->computeNpsMedio($user, $mesNps, $invalidadas);

        if ($nps > 0.0) {
            return $nps;
        }

        // $nps === 0.0 (sentinela de "vazio" — DESEMP-03) — decide entre
        // excluir (M+1 ainda em coleta) ou penalizar (M+1 já fechou).
        $janelaNpsFechada = now()->startOfDay()->gte($mesNps->copy()->endOfMonth()->startOfDay());

        return $janelaNpsFechada ? 0.0 : null;
    }

    /**
     * NPS médio do user no mês — UNIÃO DISJUNTA de QUATRO caminhos POR
     * RESPOSTA/EMPRESA (Phase 80 v16.0 · DEC-80-A / DEC-80-B0 / DEC-80-B1 /
     * DEC-80-B / DEC-80-D; Fase 116 · NPSFLOOR acrescenta o 3º ramo; Fase
     * 119.1 · D1 acrescenta o 4º).
     *
     * ┌─ (A) ATRIBUIÇÕES (Fase 79) ── `nps_score_assignments`, a lista congelada
     * │      de quem era responsável por qual serviço no dia da resposta. Soma
     * │      TODAS as áreas da pessoa (performance/ML **e** shopee) — Ajuste 3 do
     * │      usuário (DEC-80-A). Deduped 1× por (resposta, papel). Mês vem de
     * │      `nps_surveys.completed_at` (survey `status = 'completed'`).
     * ├─ (B) LEGADO ── o cruzamento read-time histórico (carteira × dimensão do
     * │      cargo × modelo principal), preservado INTACTO para as respostas que
     * │      o snapshot da Fase 79 não cobriu. Também exige `status = 'completed'`.
     * ├─ (C) IMPUTADAS (Fase 116 · NPSFLOOR) ── `nps_imputed_assignments`, a
     * │      nota 1 (piso da escala) de todo NPS efetivamente disparado e NÃO
     * │      respondido. Mês vem de `competencia_nps` (mês do DISPARO), não de
     * │      `completed_at` — só existe linha aqui quando o survey NÃO é
     * │      `completed` (`NpsImputedAssignment::scopeVigentes()`).
     * └─ (D) SEM LINK (Fase 119.1 · D1) ── empresa ELEGÍVEL (`NpsElegibilidadeService`)
     *        que não recebeu NENHUM link de NPS na competência conta nota 1,
     *        DESDE QUE o mês de coleta (M+1) já tenha fechado. É a inversão
     *        CONSCIENTE do invariante D3 da Fase 116 ("empresa sem disparo
     *        nunca entra"): com o disparo automático desligado (Plan
     *        119.1-01), aquele invariante viraria a brecha "não enviar sai
     *        mais barato". Disjunto por construção dos 3 ramos acima — só
     *        entra empresa+modelo sem NENHUM survey na competência
     *        (`NpsElegibilidadeService::surveyExistenteNaCompetencia()` nulo);
     *        ver `App\Services\Desempenho\NpsSemLinkService`.
     *
     * Os QUATRO ramos são DISJUNTOS por construção: (A)/(B) só enxergam survey
     * `completed`; (C) só enxerga survey que NUNCA chegou a `completed`
     * (`NpsImputationService::materializar()` limpa/nunca cria linha de survey
     * respondido); (D) só enxerga empresa+modelo SEM NENHUM survey na
     * competência (nem completed, nem pendente). Nenhuma resposta contribui
     * por dois ramos ao mesmo tempo. A média é `$notas->avg()` — uma resposta
     * contada 2× infla o bônus em silêncio (sem exception, sem log). Ver
     * `notasLegado()` para o predicado de (A)/(B); `notasImputadas()` para o
     * predicado de (C); `notasSemLink()` para o predicado de (D).
     *
     * ⚠ O ramo legado é FALLBACK PERMANENTE, **não** ponte temporária: empresas
     * sem contrato performance ativo ficaram com `company_users.servico_id = NULL`
     * no backfill (a migration nunca inventa serviço) → `consultorDoServico()`
     * volta vazio → `[NPS Snapshot] responsável faltante` → a resposta NUNCA gera
     * atribuição e SEMPRE cai aqui. Não remover nem afrouxar sem antes reconciliar
     * essas pendências no dado.
     *
     * Regras preservadas (DESEMP-03): média aritmética das notas coletadas; sem
     * respostas → `0.0` (PENALIZA por decisão da diretoria — nunca `null`).
     *
     * ⚠ CORREÇÃO (Fase 119.1 · D1) da nota deste docblock antes desta fase: o
     * texto aqui afirmava que "uma empresa sem NENHUM survey disparado no mês
     * continua caindo aqui [no fallback 0.0] (D3)". Isso deixou de ser
     * verdade para empresa ELEGÍVEL — ela agora entra pelo ramo (D) com nota
     * 1.0, ANTES de chegar neste fallback. O fallback `0.0` abaixo só é
     * atingido quando NENHUM dos 4 ramos produziu nota nenhuma — o que hoje só
     * acontece quando a empresa NÃO é elegível (sem contrato ativo, sem
     * modelo aplicável, sem estrategista, invalidada na competência —
     * NPSMAN-07) e também não tem nenhuma nota real dos ramos (A)/(B)/(C).
     *
     * Assinatura `(User, Carbon): float` é contrato de fato — testes a invocam
     * por reflection com `assertSame`, que recusa `int`.
     *
     * @return float sempre >= 0.0
     */
    private function computeNpsMedio(User $user, Carbon $mes, ?Collection $invalidadas = null): float
    {
        $inicio = $mes->copy()->startOfMonth();
        $fim    = $mes->copy()->endOfMonth();

        // Empresas invalidadas para bônus (item 3/4) — suas respostas de NPS
        // saem da média. `null` = chamada legada (nenhuma exclusão).
        $invalidadas = $invalidadas ?? collect();

        $notas = collect();

        // ── (A) Atribuições congeladas da Fase 79 — todas as áreas ───────────
        $notas = $notas->merge(
            $this->notasPorAtribuicao($user, $inicio, $fim, $invalidadas)->pluck('average_score')
        );

        // ── (B) Caminho legado — só as respostas que o snapshot não cobriu ───
        $notas = $notas->merge($this->notasLegado($user, $inicio, $fim, $invalidadas));

        // ── (C) Imputadas (Fase 116 · NPSFLOOR) — não respondido = nota 1 ────
        // Toggle exclusivo do relatório de impacto do backfill (D1) — nenhum
        // consumidor público desliga isto.
        $notasImputadas = $this->incluirImputadas
            ? $this->notasImputadas($user, $inicio, $fim, $invalidadas)
            : collect();
        $notas = $notas->merge($notasImputadas);

        // ── (D) Sem link (Fase 119.1 · D1) — empresa elegível sem nenhum ────
        // link no mês conta 1. MESMO toggle do ramo (C): o relatório de
        // impacto do backfill (`nps:materializar-nao-respondidos`) precisa
        // poder desligar os dois ramos juntos para montar a coluna "antes"
        // (sem NENHUMA das duas regras de piso).
        $notasSemLink = $this->incluirImputadas
            ? $this->notasSemLink($user, $inicio, $fim, $invalidadas)
            : collect();
        $notas = $notas->merge($notasSemLink);

        if ($notas->isEmpty()) {
            // DESEMP-03 · Sem respostas no mês FORÇA nps = 0 (penaliza) por
            // decisão da diretoria. Não retornar null aqui.
            return 0.0;
        }

        return round($notas->avg(), 2);
    }

    /**
     * (A) Notas ATRIBUÍDAS ao user no mês — 1× por (`nps_response_id`, `role`).
     *
     * O mês vem de `nps_surveys.completed_at` via JOIN — a MESMA coluna que o
     * ramo legado usa (DEC-80-B0). `nps_score_assignments` **não tem coluna de
     * mês**, e as duas alternativas óbvias são armadilhas:
     *  - `assigned_at` é a data da GRAVAÇÃO. Hoje equivale ao `completed_at`
     *    (mesma transação do submit), mas qualquer backfill futuro gravaria a
     *    data do backfill → a resposta de junho migraria para o mês do backfill,
     *    sumindo do bônus de junho e zerando o mês de um analista real — sem
     *    nenhum erro no log.
     *  - `month_reference` é o mês do DISPARO, não o da resposta (um NPS de junho
     *    respondido em julho tem os dois divergentes), e é NULL em muitas linhas
     *    — inclusive no fixture Carlos, de propósito.
     * Fonte única ⇒ os dois ramos concordam por construção sobre o mês da resposta.
     *
     * Dedup (DEC-80-A): a mesma pessoa responsável por 2 serviços cobertos da
     * MESMA resposta não pode pesar 2× na média. As N linhas de um mesmo par
     * (resposta, papel) vêm do MESMO `nps_response_score` — o `NpsSnapshotService`
     * itera serviços DENTRO da dimensão — logo têm `average_score` idêntico:
     * `MAX()` é determinístico e satisfaz o `ONLY_FULL_GROUP_BY` do MariaDB.
     *
     * NÃO filtrar por `service_setor` (zeraria o Ajuste 3 — o ML da pessoa sumiria
     * da média dela) nem pela carteira viva `company_users` (desfaria o
     * congelamento da Fase 79: trocar o responsável hoje reescreveria o bônus de
     * ontem). O índice `nps_score_assign_user_role_idx (user_id, role)` cobre o WHERE.
     *
     * @return Collection<int, object{response_id:int, role:string, average_score:float}>
     */
    private function notasPorAtribuicao(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
    {
        return NpsScoreAssignment::query()
            ->join('nps_responses as r', 'r.id', '=', 'nps_score_assignments.nps_response_id')
            ->join('nps_surveys as s', 's.id', '=', 'r.survey_id')
            ->where('nps_score_assignments.user_id', $user->id)
            ->where('s.status', 'completed')
            ->whereBetween('s.completed_at', [$inicio, $fim])
            // Phase 96 Plan 04 (AB-96-3 · call-site #1) — resposta invalidada
            // pelo admin some do bônus. Ver NpsResponse::scopeValida().
            ->whereNull('r.invalidated_at')
            // Item 3/4 — empresa invalidada para bônus na competência some do NPS.
            ->when($invalidadas && $invalidadas->isNotEmpty(),
                fn ($q) => $q->whereNotIn('s.company_id', $invalidadas->all()))
            ->groupBy('nps_score_assignments.nps_response_id', 'nps_score_assignments.role')
            // selectRaw só com nomes de coluna literais — zero interpolação de
            // variável; todos os valores entram por bind (where/whereBetween).
            ->selectRaw(
                'nps_score_assignments.nps_response_id as response_id,'
                .' nps_score_assignments.role as role,'
                .' MAX(nps_score_assignments.average_score) as average_score'
            )
            ->get()
            ->map(fn ($row) => (object) [
                'response_id'   => (int) $row->response_id,
                'role'          => (string) $row->role,
                'average_score' => (float) $row->average_score,
            ]);
    }

    /**
     * (B) Notas do caminho LEGADO — cruzamento read-time histórico, preservado.
     *
     * Cópia fiel do `computeNpsMedio` pré-Fase 80 (dimensão por cargo, carteira
     * ativa, `->principal()`, `NpsScoreCalculator::compute` e o fallback das
     * colunas legacy `score_*`), com uma única adição: o skip que garante a
     * disjunção da união.
     *
     * ⚠⚠ O `->principal()` PERMANECE AQUI — NÃO "limpar" este scope. ⚠⚠
     * O DEC-80-D ("aposentar 'só o principal conta'") vale APENAS para o ramo das
     * atribuições, que já é model-agnostic por construção. Aqui o `->principal()`
     * É o isolamento: sem ele, a resposta de um NPS Shopee entra no escopo legado
     * do analista de ML da MESMA empresa (é da carteira dele, é `completed`, é do
     * mês), ele não tem atribuição nessa resposta, cai no fallback e **recebe uma
     * nota de um trabalho que não é dele**. Isso é exatamente a super-atribuição
     * que o congelamento da Fase 79 existe para impedir. Coberto pelo teste
     * `BonusAtribuicoesNpsTest::test_analista_shopee_nao_recebe_nota_ml_da_mesma_empresa`.
     *
     * PREDICADO DO SKIP (DEC-80-B1 — refinamento do DEC-80-B, confirmado pelo
     * usuário em 2026-07-14): pular a resposta quando ela já tem atribuição **no
     * papel correspondente à dimensão do cargo deste user** — independentemente de
     * quem foi o atribuído. Racional: se o snapshot da Fase 79 nomeou os
     * responsáveis daquele papel naquela resposta, a lista dele é COMPLETA e
     * AUTORITATIVA — quem não está nela não é responsável e não recebe nada dessa
     * resposta.
     *
     * Por que NÃO o predicado literal "(este user, esta resposta)": `User::companies()`
     * não filtra por `servico_id`, então a empresa onde a pessoa é analista APENAS
     * de Shopee está na carteira dela; o NPS Padrão (principal) dessa empresa entra
     * na query legada dela; ela não tem atribuição nessa resposta → cairia no legado
     * e receberia a nota de ML da empresa. Seria a super-atribuição no sentido
     * inverso. O predicado por papel subsume o literal (se o user tem atribuição na
     * resposta, existe atribuição naquele papel → skip) e preserva 100% do histórico
     * (resposta com zero atribuições → zero skip → legado normal).
     *
     * O guard de carteira vazia vive AQUI (e não em `computeNpsMedio`): um user com
     * atribuições e carteira vazia deve receber a média das atribuições, não 0.0.
     *
     * @return Collection<int, float>
     */
    private function notasLegado(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
    {
        // 2026-07-13 — dimensão POR CARGO (estrategista/analista), fonte
        // canônica user_setores→cargos. Antes usava isMentor() (role do
        // sistema), o que fazia estrategistas caírem na dimensão 'analista' —
        // estrategista e analista da mesma empresa recebiam a MESMA nota NPS.
        $dim = $user->dimensaoNpsDesempenho();

        $companyIds = $user->companies()
            ->where('active', true)
            ->pluck('companies.id');

        if ($companyIds->isEmpty()) {
            return collect();
        }

        // 2026-07-13 — só o modelo PRINCIPAL conta neste ramo (ver docblock:
        // é o isolamento por serviço, não uma regra de conveniência).
        // scopePrincipal filtra template_id = principal (força vazio se nenhum
        // principal estiver marcado).
        // Phase 96 Plan 04 (AB-96-3 · call-site #2) — eager-load só carrega a
        // response quando ela NÃO está invalidada; o foreach abaixo já faz
        // `if ($response === null) continue;`, então passa a pular sozinho.
        $surveys = NpsSurvey::with(['response' => fn ($q) => $q->valida()])
            ->principal()
            ->whereIn('company_id', $companyIds)
            // Item 3/4 — empresa invalidada para bônus na competência some do NPS.
            ->when($invalidadas && $invalidadas->isNotEmpty(),
                fn ($q) => $q->whereNotIn('company_id', $invalidadas->all()))
            ->where('status', 'completed')
            ->whereBetween('completed_at', [$inicio, $fim])
            ->get();

        // Set de skip da união disjunta — derivado DOS SURVEYS JÁ CARREGADOS
        // (nunca de uma 2ª query com filtro de mês próprio: dois filtros
        // divergem e a resposta escapa, sendo contada pelos dois ramos).
        // Mapa dimensão → papel: espelha NpsSnapshotService::DIMENSAO_ROLE.
        $papelDaDimensao = $dim === 'estrategista' ? 'estrategista' : 'consultor';
        $responseIds     = $surveys->pluck('response.id')->filter()->values();

        // ->flip() + ->has() = lookup O(1) por hash (nunca ->contains() no loop).
        $cobertasNoPapel = $responseIds->isEmpty()
            ? collect()
            : NpsScoreAssignment::whereIn('nps_response_id', $responseIds)
                ->where('role', $papelDaDimensao)
                ->pluck('nps_response_id')
                ->flip();

        $notas = collect();

        foreach ($surveys as $survey) {
            /** @var NpsResponse|null $response */
            $response = $survey->response;
            if ($response === null) {
                continue;
            }

            // ÚNICA adição ao ramo legado — garante a disjunção da união.
            if ($cobertasNoPapel->has($response->id)) {
                continue;
            }

            // ── v15 path (canonical) ────────────────────────────────────────
            $nota = $this->npsCalculator->compute($response, $dim);

            // ── legacy fallback (Phase 72/73 dual-path) ──────────────────────
            // Quando o template não tem perguntas na dimensão pedida ou o
            // survey é anterior à v15.0 (sem template_id), `compute` retorna
            // null. Cai para as colunas legacy `score_estrategista/analista`
            // do próprio NpsResponse (populadas antes da Phase 68).
            if ($nota === null) {
                $legacyField = $dim === 'estrategista' ? 'score_estrategista' : 'score_analista';
                $legacyScore = $response->{$legacyField} ?? null;
                if ($legacyScore !== null && $legacyScore > 0) {
                    $nota = (float) $legacyScore;
                }
            }

            if ($nota !== null) {
                $notas->push($nota);
            }
        }

        return $notas;
    }

    /**
     * (C) Notas IMPUTADAS (Fase 116 · NPSFLOOR) — NPS efetivamente disparado e
     * NÃO respondido conta como nota 1 (piso da escala real 1..5, D4).
     *
     * Delega 100% para `NpsImputationService::notasDoUsuario()` — a ÚNICA
     * fonte de verdade de quem entra na regra (contrato ativo, responsável
     * correto com fallback consolidado, competência do survey, transição
     * provisório→definitivo). Este método NUNCA reimplementa essa lógica,
     * apenas adapta o shape (`->pluck('nota')`) pro formato que
     * `computeNpsMedio` já mescla dos outros dois ramos.
     *
     * `$invalidadas` é OBRIGATÓRIO e repassado exatamente como em
     * `notasPorAtribuicao()`/`notasLegado()` (Pitfall 5 · T-116-02-02) — sem
     * isso, uma empresa invalidada na competência voltaria a puxar nota 1
     * pelo ramo novo mesmo excluída dos outros dois.
     *
     * @return Collection<int, float>
     */
    private function notasImputadas(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
    {
        return $this->imputationService
            ->notasDoUsuario($user, $inicio, $fim, $invalidadas)
            ->pluck('nota');
    }

    /**
     * (D) Notas SEM LINK (Fase 119.1 · D1) — empresa ELEGÍVEL que passou o
     * mês sem NENHUM link de NPS conta nota 1 (inversão consciente do
     * invariante D3 da Fase 116 — ver docblock de `computeNpsMedio()`).
     *
     * Delega 100% para `NpsSemLinkService::notasDoUsuario()` — a ÚNICA fonte
     * de verdade de "quem é elegível e não recebeu link" (contrato ativo,
     * modelo aplicável, estrategista atribuído, janela de coleta M+1 já
     * fechada). Este método NUNCA reimplementa essa lógica, apenas adapta o
     * shape (`->pluck('nota')`) pro formato que `computeNpsMedio` já mescla
     * dos outros 3 ramos — mesmo molde de `notasImputadas()` acima.
     *
     * `$invalidadas` é OBRIGATÓRIO e repassado exatamente como nos outros 3
     * ramos — sem isso, uma empresa invalidada na competência voltaria a
     * puxar nota 1 pelo ramo novo mesmo excluída dos outros três.
     *
     * @return Collection<int, float>
     */
    private function notasSemLink(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
    {
        return $this->npsSemLinkService
            ->notasDoUsuario($user, $inicio, $fim, $invalidadas)
            ->pluck('nota');
    }

    /**
     * % variação de faturamento vs mês anterior — mediana das % por empresa
     * (quick 260731-pvk, 2026-07-31 — ver docblock completo mais abaixo,
     * junto ao `return`, para o motivo da troca de média para mediana).
     *
     * Regras (DESEMP-04, DESEMP-11):
     *  - Descartar empresas NOVAS (associadas ao user via `company_users`
     *    há menos de 2 meses — proxy: `pivot->created_at`).
     *  - Descartar empresas com `caseFor === 'none'` (sem provider aplicável).
     *  - Fonte primária: ML via `MlMetricsProvider::readForCompany()` quando
     *    `caseFor in ['ambos', 'so-ml']`. Fallback ao `AdmanMetric` local.
     *  - Descartar empresas com `rev_anterior <= 0` (sem baseline).
     *  - Retornar média das variações ou `null` se nenhuma qualifica.
     *
     * TODO Plan 74-09: cobrir edge case "empresa nova" via factory que
     * sobreescreva `company_users.created_at` para exercitar o filtro.
     *
     * Fase 102 (BON-01): as janelas current/baseline deixam de ser calculadas
     * inline (`now()`/`startOfMonth()`/`subMonth()`) e passam a vir do
     * `$periodo` já resolvido por `MetricPeriodResolver` (via
     * `resolvePeriodo()`/`computeOficial()` em `compute()`). Mês em curso:
     * janela idêntica à anterior (mesmo alinhamento por dia, mesmo clamp —
     * troca 1:1, sem mudança de número). Mês fechado: baseline deixa de ser
     * "mês calendário anterior completo" e passa a ser a janela-de-mesmo-
     * tamanho imediatamente anterior (`previous_equal_length_window`).
     *
     * Fase 109 (SHOP-DES-01): roteia por empresa via `MetricDiffDispatcher`
     * usando o mapa `$fontes` (company_id → 'adman'|'shopee') resolvido em
     * `computeUniverso()` — empresa Shopee devolve `revenue.diff_pct` real
     * (Shopee TEM faturamento, sem placeholder) e entra na conta normalmente.
     *
     * Quick 260731-pvk (2026-07-31): agregação passa de MÉDIA para MEDIANA.
     * Motivo (debug `baseline-quase-zero-producao`): a empresa 332 "Lojão do
     * Bras" faturou R$ 79,98 em maio e R$ 16.666 em junho — a Adman devolve
     * `.diff` de +20.738% sobre um baseline residual (não zero, então o guard
     * `anterior <= 0` do `calculated_fallback` não pega), e a MÉDIA repassava
     * esse número sozinho pra carteira inteira: a do Douglas foi de -2,3%
     * real (ela encolheu ~R$131 mil) para +766,25% no snapshot congelado de
     * 2026-06, garantindo 5/5 pontos em crescimento. A mediana neutraliza o
     * outlier sem excluir ninguém — D-1 (travada): PROIBIDO qualquer piso,
     * filtro por valor mínimo, winsorização ou cap; toda empresa com
     * `diff_pct` presente continua entrando em `$vars` e em
     * `empresas_com_baseline`, só o peso no resultado muda.
     * `computeVarMargem()` (abaixo) fica com MÉDIA de propósito (D-2) — o
     * impacto da margem em faixa de bônus não foi simulado; não "consertar
     * por simetria" sem essa simulação própria.
     *
     * @param  EloquentCollection<int, \App\Models\Company>  $companies  carteira ativa
     * @param  array  $periodo  shape do `MetricPeriodResolver::resolve()`
     * @param  Collection<int, string>  $fontes  company_id → financial_source vencedora
     * @return array{pct: ?float, empresas_com_baseline: int}
     */
    private function computeVarFaturamento(User $user, Carbon $mes, EloquentCollection $companies, array $periodo, Collection $fontes): array
    {
        // ── Filtro "provider aplicável" ──────────────────────────────────────
        // NOTA (2026-07-21): o antigo filtro "empresa nova" por
        // `companies.created_at < mês-1` foi REMOVIDO. A base de empresas foi
        // reimportada em massa (quase todas com `created_at` = 2026-05-25), o
        // que fazia 100% virarem "novas" e o baseline de faturamento zerar —
        // todo profissional caía em `partial` (só Ana Julia escapava, por ter 2
        // empresas cadastradas em abril). `created_at` (na company OU no pivot)
        // reflete evento de importação, não realidade de negócio; e o histórico
        // de métricas só começa em ~21/05, então nenhum teste de "2 meses
        // operando" é viável hoje sem re-zerar todo mundo. A proteção real
        // contra "sem baseline" é o guard `revAnterior <= 0` logo abaixo:
        // empresa sem faturamento no mês anterior é descartada naturalmente;
        // empresa com baseline real (maio via ML, que entrega o mês cheio)
        // conta. Decisão do usuário "contar quem tem baseline real"
        // (2026-07-21). O teste de "2 meses" volta a fazer sentido sozinho
        // quando o histórico acumular — reavaliar então.
        // UNIFICADO com a carteira (2026-07-22, decisão do usuário): delega por
        // empresa ao `AdmanMetricDiffService::compute()` e lê `revenue.diff_pct`
        // — a MESMA fonte de faturamento da carteira/transparência. Simétrico a
        // `computeVarMargem`. Empresa conta pra baseline quando o diff service
        // tem variação (diff_pct != null) — mesmo critério do status da carteira,
        // então `empresas_com_baseline` do desempenho passa a bater com o que a
        // carteira mostra. Removeu ML-first + trava de cobertura próprios (que
        // faziam as duas telas divergirem); o diff service (nativo Adman, já
        // validado contra a UI) é a fonte única.
        $vars             = collect();
        $empresasBaseline = 0;

        foreach ($companies as $company) {
            $source  = $fontes[$company->id] ?? 'adman';
            $diffPct = $this->diffDispatcher->compute($company, $periodo, $source)['metrics']['revenue']['diff_pct'] ?? null;
            if ($diffPct !== null) {
                $vars->push($diffPct);
                $empresasBaseline++;
            }
        }

        if ($vars->isEmpty()) {
            return ['pct' => null, 'empresas_com_baseline' => 0];
        }

        return [
            'pct'                   => round($vars->median(), 2),
            'empresas_com_baseline' => $empresasBaseline,
        ];
    }

    /**
     * % variação de margem de contribuição vs mês anterior (Fase 102 · BON-03).
     *
     * Delega por empresa a `AdmanMetricDiffService::compute(company, periodo)`
     * — fonte única de guards de dias-comuns/margem_dias (antes duplicados
     * aqui, ver `AdmanMetricDiffService` docblock "calculated_fallback —
     * duplicação TEMPORÁRIA e INTENCIONAL", agora removida). Lê
     * `metrics['contribution_margin_pct']['diff_pct']` — NOTA DE
     * NOMENCLATURA (ADM-05): `contribution_margin_pct` é a chave interna do
     * diff service para o `percentageMargin` da Adman (margem como % da
     * receita), DIFERENTE de `AdmanMetric.contribution_margin` (campo R$
     * usado no `calculated_fallback` interno do diff service). O gate
     * `diff_source` (`adman_diff` vs `calculated_fallback`) é decidido
     * inteiramente dentro do diff service — este método só agrega o
     * `diff_pct` já resolvido, nunca recalcula guard nenhum.
     *
     * Fase 109 (SHOP-DES-02): roteia por empresa via `MetricDiffDispatcher`
     * usando `$fontes`. `ShopeeMetricDiffService` sempre devolve
     * `contribution_margin_pct.diff_pct=null` (Shopee não fornece CMV —
     * arquitetura future-ready), então empresas Shopee NUNCA contribuem pra
     * `$vars`/`n_com_margem_real` aqui — a dimensão margem delas é resolvida
     * como placeholder pelo helper `margemPontos()` em `compute()`, não aqui.
     * `n_com_margem_real` (nº de empresas cujo diff de margem REAL entrou na
     * média) é devolvido pra alimentar o denominador do blend.
     *
     * Fase 110 (Plan 02 · FIXMARG-03): também devolve `n_elegivel` — nº de
     * empresas com fonte financeira 'adman' (elegíveis a margem real; Shopee
     * NUNCA retorna diff de margem, então não entra no denominador). É o
     * numerador/denominador que alimenta `margem_amostra.cobertura` em
     * `compute()`, consumido pelo gate de qualidade do congelamento mensal
     * (`ConsolidarMesDesempenho`) — não confundir com `n_shopee_placeholder`
     * (contagem feita em `compute()` sobre `$companies` já filtrado por
     * invalidação de competência).
     *
     * @param  EloquentCollection<int, \App\Models\Company>  $companies
     * @param  array  $periodo  shape do `MetricPeriodResolver::resolve()`
     * @param  Collection<int, string>  $fontes  company_id → financial_source vencedora
     * @return array{pct: ?float, n_com_margem_real: int, n_elegivel: int}
     */
    private function computeVarMargem(User $user, Carbon $mes, EloquentCollection $companies, array $periodo, Collection $fontes): array
    {
        // Denominador da cobertura: empresas cuja fonte financeira vencedora é
        // 'adman' (Shopee nunca fornece diff de margem real — não é "buraco",
        // é ausência legítima de expectativa, ver 110-CONTEXT.md).
        $nElegivel = $companies
            ->filter(fn ($c) => ($fontes[$c->id] ?? 'adman') !== 'shopee')
            ->count();

        if ($companies->isEmpty()) {
            return ['pct' => null, 'n_com_margem_real' => 0, 'n_elegivel' => $nElegivel];
        }

        $vars           = collect();
        $nComMargemReal = 0;

        foreach ($companies as $company) {
            $source    = $fontes[$company->id] ?? 'adman';
            $resultado = $this->diffDispatcher->compute($company, $periodo, $source);
            $diffPct   = $resultado['metrics']['contribution_margin_pct']['diff_pct'] ?? null;

            if ($diffPct !== null) {
                $vars->push($diffPct);
                $nComMargemReal++;
            }
        }

        if ($vars->isEmpty()) {
            return ['pct' => null, 'n_com_margem_real' => 0, 'n_elegivel' => $nElegivel];
        }

        return [
            'pct'               => round($vars->avg(), 2),
            'n_com_margem_real' => $nComMargemReal,
            'n_elegivel'        => $nElegivel,
        ];
    }

    /**
     * Absenteísmo — placeholder retorna null sempre.
     *
     * DESEMP-06 · standby — fonte de dados em definição pela diretoria
     * (biometria facial da porta OU login-based). Método existe para futuro
     * plumbing sem quebrar o shape do `compute()`. Fica placeholder até phase
     * futura decidir a fonte.
     */
    private function computeAbsenteismo(User $user, Carbon $mes): ?float
    {
        return null;
    }

    /**
     * Nota final = média direta em escalas naturais dos componentes não-null.
     *
     * DESEMP-02: sem normalização régua 1-5, sem redistribuição de pesos.
     * Absenteísmo NUNCA entra no cálculo — é excluído por spec (DESEMP-06).
     *
     * Ajuste 2026-07-09 (pós-deploy): variação bruta em % permitia notas fora
     * do range [1, 5] (ex: analista com var_fat=-15% + var_margem=-20% ficava
     * com nota ~-10) e distorcia toda a régua de bônus. Fix: as variações
     * passam pelas réguas 1-5 antes de entrar na média — todos os 3 componentes
     * ficam na mesma escala 1-5, e a nota final SEMPRE fica em [1.0, 5.0].
     *
     * Fase 109 (SHOP-DES-02): o 3º parâmetro passa a ser `$margemPontos` —
     * o valor JÁ RÉGUA'D (1-5), calculado por `margemPontos()` em `compute()`
     * (blend real+placeholder Shopee). Este método NÃO chama mais
     * `reguaMargem()` internamente — quem quer aplicar a régua pura sobre uma
     * % real continua usando `reguaMargem()` direto (ex.: dentro de
     * `margemPontos()`).
     *
     * @return ?float 2 decimais em [1.0, 5.0]; null quando TODOS os componentes são null
     */
    private function computeNotaFinal(?float $nps, ?float $varFat, ?float $margemPontos): ?float
    {
        // APOSENTADO EM 2026-08-05 — não é mais chamado por `compute()`. A nota
        // oficial vem de `computeNotaFinalPorIndicador()`. Preservado porque
        // encapsula a fórmula histórica (régua aplicada UMA vez sobre a média
        // da carteira) e é o "antes" do relatório de impacto do
        // `desempenho:comparar-score-empresa`. Não reintroduzir em caminho de
        // produção sem decisão explícita: inverte a ordem das operações.
        // NPS já é 1-5 (escala do formulário) — clamp defensivo.
        $npsPts = $nps !== null ? max(1.0, min(5.0, $nps)) : null;

        // Faturamento passa pela régua 1-5 (SPEC-04) para caber na mesma
        // escala do NPS. Margem já chega régua'd (`$margemPontos`, Fase 109).
        $fatPts    = $this->reguaFaturamento($varFat);
        $margemPts = $margemPontos;

        $componentes = collect([$npsPts, $fatPts, $margemPts])
            ->reject(fn ($v) => $v === null);

        if ($componentes->isEmpty()) {
            return null;
        }

        return round($componentes->sum() / $componentes->count(), 2);
    }

    /**
     * Régua de FATURAMENTO — aplica pontuação 1-5 pts à % de variação de faturamento
     * vs mês anterior por empresa (média da carteira).
     *
     * Ancorada no SPEC-04 "Régua de Faturamento" da diretoria, adaptada à
     * interpretação vs-mês-anterior escolhida em spec-phase Q1:
     *   ≤ -6%  → 1 pt (queda severa)
     *   ≤ -1%  → 2 pts (queda leve)
     *   <  1%  → 3 pts (estável / meta)
     *   ≤  5%  → 4 pts (crescimento saudável)
     *   >  5%  → 5 pts (crescimento excelente)
     *
     * PÚBLICA desde a Fase 121 Plano 03 (D-07/`121-03-PLAN.md`): o comando
     * `desempenho:comparar-score-empresa` precisa desta régua pura para
     * recalcular contrafactuais na decomposição do delta — expor é aditivo
     * (só visibilidade, corpo e assinatura intocados) e paga parte do débito
     * da C-03 da Fase 119, que previa unificar as réguas quando o gate de
     * aditividade saísse (saiu na Fase 120).
     */
    public function reguaFaturamento(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -6)    return 1.0;
        if ($pct <= -1)    return 2.0;
        if ($pct <   1)    return 3.0;
        if ($pct <=  5)    return 4.0;
        return 5.0;
    }

    /**
     * Régua de MARGEM DE CONTRIBUIÇÃO — aplica pontuação 1-5 pts à % de variação
     * de margem vs mês anterior por empresa (média da carteira).
     *
     * Ancorada no SPEC-05 "Régua de Margem" da diretoria:
     *   ≤ -5%  → 1 pt
     *   ≤ -2%  → 2 pts
     *   ≤  1%  → 3 pts
     *   ≤  4%  → 4 pts
     *   >  4%  → 5 pts
     *
     * PÚBLICA desde a Fase 121 Plano 03 (D-07/`121-03-PLAN.md`) — mesmos
     * termos de `reguaFaturamento()` acima.
     */
    public function reguaMargem(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -5)    return 1.0;
        if ($pct <= -2)    return 2.0;
        if ($pct <=  1)    return 3.0;
        if ($pct <=  4)    return 4.0;
        return 5.0;
    }

    /**
     * Fase 120 (AGRE-04) — média agregada de `margem_var_pp` (pontos
     * percentuais, por empresa) das linhas do shadow, arredondada a 2 casas.
     * Alimenta `componentes.var_margem_pp`, um metadado ADITIVO de auditoria
     * — a NOTA nunca lê este campo (lê `margem_pontos` por empresa, dentro de
     * `$empresasScore`, quando a flag estiver ligada — Plano 03). Reaproveitar
     * `var_margem_pct` para este fim confundiria as duas unidades (% relativa
     * vs pontos percentuais), que é justamente o que a milestone existe para
     * separar (C-03/120-CONTEXT.md).
     *
     * Devolve `null` quando o shadow NÃO rodou (`$empresasScore === null`) —
     * reportar `null` é honesto, inventar um número agregado seria pior.
     * `Collection::avg()` já descarta os `null` sozinho e devolve `null` se
     * nenhum sobrar — cobre a carteira só-Shopee, onde o placeholder de
     * margem não tem `margem_var_pp` real.
     */
    private function varMargemPpAgregado(?Collection $empresasScore): ?float
    {
        if ($empresasScore === null) {
            return null;
        }

        $media = $empresasScore->avg('margem_var_pp');

        return $media !== null ? round($media, 2) : null;
    }

    /**
     * Fase 120 (AGRE-01) — `nota_final` do caminho novo: média das
     * `nota_empresa` (nota ESTRITA, D-01 do `CompanyScoreService`) das
     * empresas com `status === 'complete'`, arredondada a 2 casas.
     *
     * Método NOVO e SEPARADO — nunca uma extensão de `computeNotaFinal()`
     * legado, que continua intocado e é quem roda com a flag desligada.
     *
     * D-01 (`120-CONTEXT.md`) — por que empresa `partial`/`sem_fonte`/
     * `sem_dados` fica FORA do denominador, mesmo tendo
     * `nota_empresa_parcial` disponível: entrar com a parcial misturaria
     * empresa medida por 3 dimensões com empresa medida por 1, e a
     * incompleta poderia até puxar a nota para CIMA (caso concreto do
     * `120-CONTEXT.md`: 4,80 de parcial contra 4,53 da completa no caso
     * âncora). A leitura literal do plano canônico §3.4 — qualquer
     * incompleta derruba o profissional para `partial` — foi descartada
     * porque empresa sem baseline é caso COMUM neste projeto
     * (`adman_metrics` começa por volta de 21/05; Shopee não tem baseline
     * antes de 01/06): quase toda carteira cairia em `partial` e o status
     * pararia de distinguir qualquer coisa. A guarda de cobertura de
     * `computeScoreStatusPorEmpresa()` é o que impede o abuso de um
     * profissional com 10 empresas e 2 completas ser julgado por 2 com
     * aparência de nota oficial.
     *
     * @param  Collection<int, object>  $empresasScore  linhas de
     *   `CompanyScoreService::computeEmpresasScore()` — já filtradas por
     *   carteira/invalidação por competência (T-120-06/T-120-07), nunca
     *   reabertas aqui.
     * @return ?float 2 decimais; `null` quando nenhuma empresa é `complete`.
     */
    private function computeNotaFinalPorEmpresa(Collection $empresasScore): ?float
    {
        $completas = $empresasScore->filter(fn ($e) => $e->status === 'complete');

        if ($completas->isEmpty()) {
            return null;
        }

        return round($completas->avg('nota_empresa'), 2);
    }

    /**
     * Nota final POR INDICADOR — o modelo do fechamento manual do time
     * (`Fechamento Junho _ Time de performance.xlsx`), adotado em 2026-08-05
     * por decisão do usuário. É o caminho OFICIAL desde então.
     *
     * A régua já foi aplicada LOJA A LOJA dentro do `CompanyScoreService`;
     * aqui só se promedia. Três médias independentes — faturamento, margem e
     * NPS — e a nota é a média dessas três.
     *
     * ─── Denominador INDEPENDENTE por indicador ───────────────────────────
     * Cada indicador tem seu próprio denominador, exatamente como o `AVERAGE`
     * do Excel ignora célula vazia: uma loja sem margem continua contando no
     * faturamento e no NPS, em vez de sair da conta inteira. É o que separa
     * este método de `computeNotaFinalPorEmpresa()` (que exige a loja
     * `complete` e descarta a linha toda) — os dois coincidem quando nenhuma
     * loja tem lacuna, e divergem exatamente onde há lacuna.
     *
     * ─── A NOTA divide sempre por 3 (2026-08-10, quick 260810-mt8) ─────────
     * Decisão do Maycon: "Pontuação Final = Soma das 3 pontuações ÷ 3".
     * O indicador que a carteira não tem entra como ZERO, e o divisor é fixo.
     *
     * Antes a nota promediava só os indicadores presentes, então uma carteira
     * sem margem era medida por dois. O caso concreto é a carteira só-Shopee:
     * a plataforma não fornece CMV, a margem sai do denominador e a nota vinha
     * da média de faturamento e NPS. Foi perguntado a ele com esse caso na
     * mesa, e a opção escolhida foi a mais severa das três (a alternativa era
     * o ausente entrar com 1,0, o piso da régua).
     *
     * CONSEQUÊNCIA que precisa estar visível para quem mexer aqui: carteira
     * só-Shopee passa a ter TETO de (5 + 0 + 5) / 3 = 3,33 — nunca alcança os
     * 4,00 da primeira faixa de bônus. Não é efeito colateral, é a regra.
     *
     * O zero entra no nível do INDICADOR, nunca por loja. Loja sem margem
     * continua apenas fora da média de margem (o parágrafo acima), não vira
     * zero dentro dela — eram 75 das 286 lojas em 2026-06, e zerar por loja
     * seria outra regra, muito mais severa, que o pedido não descreve.
     *
     * Carteira sem NENHUM indicador continua `null`, jamais 0: ausência total
     * de dado não é desempenho zero, e a trava D-91-01 (`blocked`) depende
     * disso.
     *
     * Na competência 2026-06 medida em produção: 47 das 286 lojas sem
     * faturamento, 75 sem margem. Descartar essas linhas inteiras jogaria
     * fora medição boa dos outros dois indicadores.
     *
     * ─── Sem arredondamento intermediário ─────────────────────────────────
     * Nenhum `round()` aqui — nem nas médias por indicador, nem na nota.
     * Arredondar 3,9130 para 3,91 antes de somar desloca o resultado, e a
     * régua de bônus tem fronteiras duras (4,00 separa `sem_bonus` de
     * `basico`). Quem arredonda é a exibição, com 2 casas fixas.
     *
     * @param  Collection<int, object>  $empresasScore  linhas de
     *   `CompanyScoreService::computeEmpresasScore()` — já filtradas por
     *   carteira/invalidação por competência, nunca reabertas aqui.
     * @return array{faturamento: ?float, margem: ?float, nps: ?float, nota: ?float}
     *   `null` em cada posição sem nenhum valor presente.
     */
    private function computeNotaFinalPorIndicador(Collection $empresasScore): array
    {
        $medir = static function (Collection $valores): ?float {
            $presentes = $valores->reject(fn ($v) => $v === null);

            return $presentes->isEmpty() ? null : $presentes->sum() / $presentes->count();
        };

        $faturamento = $medir($empresasScore->map(fn ($e) => $e->faturamento_pontos ?? null));
        $margem      = $medir($empresasScore->map(fn ($e) => $e->margem_pontos ?? null));
        $nps         = $medir($empresasScore->map(fn ($e) => $e->nps_pontos ?? null));

        // Os três indicadores continuam expostos com `null` quando ausentes —
        // é o que distingue "a carteira não tem esse indicador" de "tirou
        // zero". Quem transforma o ausente em 0 é a NOTA, logo abaixo, e a
        // exibição da conta na tela; o payload nunca fabrica o zero.
        $algumPresente = collect([$faturamento, $margem, $nps])
            ->contains(fn ($v) => $v !== null);

        return [
            'faturamento' => $faturamento,
            'margem'      => $margem,
            'nps'         => $nps,
            'nota'        => $algumPresente
                ? (($faturamento ?? 0.0) + ($margem ?? 0.0) + ($nps ?? 0.0)) / self::DIVISOR_NOTA_FINAL
                : null,
        ];
    }

    /**
     * Fase 120 (AGRE-05/AGRE-06) — `score_status` do caminho novo: deriva da
     * DISTRIBUIÇÃO de status por empresa, não da presença binária de dois
     * sinais agregados como o `computeScoreStatus()` legado (que olha
     * `$varFat`/`$margemPontos`, cada um presente ou nulo para a carteira
     * inteira). Método NOVO e SEPARADO — o legado continua intocado.
     *
     * Regras, na ordem:
     *  1. `blocked` — nenhuma empresa com nota alguma, ou seja,
     *     `nota_empresa_parcial` é `null` em TODAS. Caso extremo.
     *  2. Cobertura = empresas com `status === 'complete'` / total de linhas
     *     do `$empresasScore` — o total é o universo elegível INTEIRO,
     *     incluindo as `sem_fonte` (D-03 da Fase 119 as lista de propósito).
     *  3. `official` quando cobertura >= `self::COBERTURA_MINIMA_SCORE_STATUS`
     *     (0.7), senão `partial`.
     *
     * Caso concreto (`120-CONTEXT.md`): 26 empresas com 20 `complete` dá
     * cobertura 77% e `official`; com 15 `complete` dá 58% e `partial`.
     *
     * AGRE-06 — profissional só-Shopee tem TODAS as empresas `complete`
     * (`componentes_esperados = 2`, já que a Shopee não fornece CMV), logo
     * cobertura 100% e `official` — SEM nenhum `if` especial para Shopee.
     *
     * @param  Collection<int, object>  $empresasScore
     */
    private function computeScoreStatusPorEmpresa(Collection $empresasScore): string
    {
        if ($empresasScore->every(fn ($e) => $e->nota_empresa_parcial === null)) {
            return 'blocked';
        }

        // D-91-01 (decisão do usuário em 2026-07-16) — carteira com ZERO
        // vínculos financeiros elegíveis não recebe nota oficial enquanto a
        // diretoria não aprovar uma régua de bônus para esse caso. É o
        // profissional só-Polos / só-Publicação: os setores sem fonte
        // financeira caem no branch default de
        // `CarteiraContextService::flagsFinanceirasPorSetor()`.
        //
        // Sem esta regra, a agregação por indicador daria nota a essa carteira
        // usando SÓ o NPS — faturamento e margem ausentes simplesmente sairiam
        // do denominador, e a pessoa entraria no ranking (podendo até alcançar
        // faixa de bônus) medida por uma única dimensão. O cálculo legado
        // barrava isso em `computeScoreStatus()`, que olhava
        // `vinculos_financeiros`; aqui a trava precisa ser explícita, porque o
        // status passou a derivar da distribuição por empresa.
        if ($empresasScore->every(fn ($e) => ($e->fonte_financeira ?? null) === null)) {
            return 'blocked';
        }

        $total     = $empresasScore->count();
        $completas = $empresasScore->filter(fn ($e) => $e->status === 'complete')->count();
        $cobertura = $total > 0 ? $completas / $total : 0.0;

        return $cobertura >= self::COBERTURA_MINIMA_SCORE_STATUS ? 'official' : 'partial';
    }

    /**
     * Blend ponderado por contagem da dimensão MARGEM (Fase 109 · SHOP-DES-02
     * — DECISÃO TRAVADA, implementar EXATAMENTE assim, ver `109-03-PLAN.md`
     * `<design_margem_placeholder>`).
     *
     * A Shopee ainda não fornece margem de contribuição (CMV) — em vez de
     * excluir a dimensão margem para quem tem empresas Shopee (o que geraria
     * `blocked`/`partial` injustamente), usa-se um PLACEHOLDER = 1.0 (piso da
     * régua 1-5) ponderado pela contagem de empresas Shopee elegíveis
     * (`$nShopeePlaceholder`, JÁ pós-invalidação de competência — ver
     * `compute()`) contra a contagem de empresas com margem REAL que entrou
     * em `$varMargemReal` (`$nComMargemReal`).
     *
     * Invariantes (testados em `DesempenhoShopeeScoreTest`):
     *  - Só-performance (`$nShopeePlaceholder=0`) → devolve exatamente
     *    `reguaMargem($varMargemReal)` — IDÊNTICO ao comportamento pré-Fase
     *    109 (regressão zero).
     *  - Só-Shopee (`$nComMargemReal=0`, `$pReal=null`) → devolve `1.0`.
     *  - Misto → média ponderada pelas contagens (empresas Shopee "puxam" a
     *    média pro piso proporcionalmente ao seu peso na carteira financeira).
     *
     * Arquitetura *future-ready*: quando a Shopee passar a fornecer margem
     * real, basta `ShopeeMetricDiffService` parar de devolver
     * `contribution_margin_pct.diff_pct=null` — essas empresas passam a
     * contar em `$nComMargemReal`/`$varMargemReal` (via `computeVarMargem`) e
     * `$nShopeePlaceholder` cai naturalmente, sem mudar esta fórmula.
     */
    private function margemPontos(?float $varMargemReal, int $nComMargemReal, int $nShopeePlaceholder): ?float
    {
        $pReal = $this->reguaMargem($varMargemReal);

        $numerador   = ($pReal !== null ? $pReal * $nComMargemReal : 0.0) + 1.0 * $nShopeePlaceholder;
        $denominador = ($pReal !== null ? $nComMargemReal : 0) + $nShopeePlaceholder;

        return $denominador > 0 ? round($numerador / $denominador, 2) : null;
    }

    /**
     * Classifica a nota na régua ATIVA de `bonus_faixas`.
     *
     * DESEMP-07: sem hardcode — delega para `BonusFaixa::classificar()`, que
     * consulta a régua editável pelo admin. Cache in-memory garante que loops
     * de ranking (15-20 users) só bata no DB uma vez por request.
     *
     * IMPORTANTE: NÃO aplica DESEMP-08 aqui — a regra de promoção depende de
     * histórico do snapshot mensal e é responsabilidade do
     * `promoverPor2MesesConsecutivos`.
     *
     * @return ?string slug da faixa (`sem_bonus`, `basico`, `intermediario`,
     *                 `maximo`) ou null se nenhuma cobre.
     */
    private function classificarFaixa(float $nota): ?string
    {
        if ($this->faixasCache === null) {
            $this->faixasCache = BonusFaixa::ativas()->ordenadas()->get();
        }

        foreach ($this->faixasCache as $faixa) {
            $min = (float) $faixa->nota_min;
            $max = (float) $faixa->nota_max;
            if ($nota >= $min && $nota <= $max) {
                return $faixa->slug;
            }
        }

        return null;
    }

    /**
     * Aplica a regra DESEMP-08: se a faixa atual é `intermediario` E:
     *   (a) a nota do mês corrente é >= 5.00 exato, OU
     *   (b) o snapshot mensal do MESMO user do mês M-1 também foi `intermediario`,
     * promove para `maximo`.
     *
     * Consulta o snapshot mensal via scope `mensal()` do Model + filtro por
     * `mes_referencia` = mês anterior (`YYYY-MM-01`). Não usa `ref_date`
     * porque em snapshots mensais `ref_date = mes_referencia` mas o índice
     * canonical é sobre `mes_referencia` (Plan 74-01 D-03).
     *
     * @param  string  $faixaAtual  slug da faixa classificada por `classificarFaixa`
     * @param  ?float  $nota        nota corrente (opcional; permite regra suplementar)
     * @return array{faixa: string, promovida: bool}
     */
    private function promoverPor2MesesConsecutivos(
        User $user,
        Carbon $mes,
        string $faixaAtual,
        ?float $nota = null,
    ): array {
        if ($faixaAtual !== 'intermediario') {
            return ['faixa' => $faixaAtual, 'promovida' => false];
        }

        // Regra suplementar: nota corrente já >= 5.00 sobe direto para máximo.
        if ($nota !== null && $nota >= 5.00) {
            return ['faixa' => 'maximo', 'promovida' => true];
        }

        $mesAnterior = $mes->copy()->subMonth()->startOfMonth();

        $prev = DesempenhoScoreSnapshot::mensal()
            ->where('user_id', $user->id)
            ->whereDate('mes_referencia', $mesAnterior->toDateString())
            ->first();

        if ($prev?->classificacao === 'intermediario') {
            return ['faixa' => 'maximo', 'promovida' => true];
        }

        return ['faixa' => 'intermediario', 'promovida' => false];
    }

    /**
     * Formata o mês em pt-BR: "julho/2026".
     *
     * Usa `translatedFormat` com locale `pt_BR` — depende do
     * `carbon/laravel-lang` já configurado no projeto (padrão do painel).
     */
    private function mesExtenso(Carbon $mes): string
    {
        return $mes->copy()->locale('pt_BR')->translatedFormat('F/Y');
    }

    /**
     * Shape padronizado quando o user NÃO tem carteira no mês (DESEMP-10) —
     * ZERO vínculos de qualquer setor (Fase 91 · DESEMP-07).
     */
    private function shapeSemCarteira(User $user, Carbon $mes, string $motivo): array
    {
        return [
            'user_id'               => $user->id,
            'user_name'             => $user->name,
            'mes_referencia'        => $mes->toDateString(),
            'sem_carteira'          => true,
            'motivo'                => $motivo,
            'empresas_carteira'     => 0,
            'empresas_com_baseline' => 0,
            'componentes' => [
                'nps_medio'           => null,
                'var_faturamento_pct' => null,
                'var_margem_pct'      => null,
                'absenteismo_pct'     => null,
                // Fase 120 (AGRE-04) — simetria: sem carteira, shadow nunca roda.
                'var_margem_pp'       => null,
            ],
            'pontos_componentes' => [
                'nps'         => null,
                'faturamento' => null,
                'margem'      => null,
            ],
            'nota_final'      => null,
            'faixa_bonus'     => null,
            'faixa_promovida' => false,
            // ── Metadados de elegibilidade (Fase 91 · DESEMP-05) — zerados ────
            'empresas_unicas'               => 0,
            'vinculos_servico'              => 0,
            'vinculos_financeiros'          => 0,
            'vinculos_sem_fonte_financeira' => 0,
            // Coerente com a fórmula de computeScoreStatus: zero vínculos
            // financeiros → blocked.
            'score_status'                  => 'blocked',
            'componentes_disponiveis' => [
                'nps_medio'           => false,
                'var_faturamento_pct' => false,
                'var_margem_pct'      => false,
            ],
            // Fase 120 (AGRE-04) — simetria com o shape normal.
            'empresas_score' => [],
        ];
    }
}
