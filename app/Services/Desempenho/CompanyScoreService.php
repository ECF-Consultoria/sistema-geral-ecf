<?php

namespace App\Services\Desempenho;

use App\Models\BonusInvalidacao;
use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\User;
use App\Services\Metrics\FinancialSourceResolver;
use App\Services\Metrics\ManualMetricOverrideService;
use App\Services\Metrics\MetricDiffDispatcher;
use App\Services\Portfolio\CarteiraContextService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Agregador de LEITURA que devolve a linha de fato por empresa
 * `(user_id, company_id)` — Fase 119 (EMPS-01..07), consumindo o componente
 * de NPS já pronto da Fase 118 (`NpsPorEmpresaService`) e o dispatcher
 * financeiro (`MetricDiffDispatcher`) já existentes desde a Fase 109/117.
 *
 * Troca a UNIDADE do motor de bonificação: a régua de faturamento/margem
 * passa a rodar POR EMPRESA, antes de qualquer média — diferente do
 * `DesempenhoScoreService::compute()` atual, que tira a média da carteira e
 * só então aplica a régua uma única vez sobre essa média.
 *
 * ─── Regras travadas (119-CONTEXT.md) ─────────────────────────────────────
 *  - D-01 · a linha reporta DOIS números: `nota_empresa` (estrita — `null`
 *    se faltar qualquer um dos componentes ESPERADOS) e
 *    `nota_empresa_parcial` (média dos presentes), mais
 *    `componentes_presentes`, `componentes_esperados` e `quality.motivos`.
 *    REVISADO EM 2026-08-05: "estrita" era "exatamente 3"; virou "todos os
 *    esperados", para acomodar a loja Shopee, que só tem 2 dimensões
 *    mensuráveis (ver D-02).
 *  - D-02 · empresa Shopee entra como `status='complete'` com apenas 2
 *    componentes (`componentes_esperados=2`), `margem_pontos=null` e
 *    `quality.margin_source='sem_margem_shopee'` — nunca aplica a régua de
 *    margem sobre Shopee.
 *    REVISADO EM 2026-08-05: até então entrava com `margem_pontos=1.0` fixo
 *    (placeholder da Fase 109) e `margin_source='placeholder_shopee'`. O
 *    placeholder era a nota MÍNIMA, então a loja Shopee arrastava a média de
 *    margem do profissional para baixo por uma dimensão que a plataforma não
 *    fornece (a Shopee não expõe CMV). Decisão do usuário: ausência de dado
 *    da plataforma não é desempenho ruim — a loja sai do denominador da
 *    margem e segue contando no faturamento e no NPS.
 *  - D-03 · empresa sem fonte financeira elegível permanece listada, com
 *    todos os campos financeiros `null`, `status='sem_fonte'` e
 *    `quality.motivos=['sem_fonte_financeira']`.
 *  - D-04 · `DesempenhoScoreService::margemPontos()` (o blend ponderado por
 *    contagem da Fase 109) fica INTOCADO — é o caminho vivo enquanto a flag
 *    da Fase 120 estiver desligada. O caminho novo não o usa.
 *  - D-05 · `$periodo` chega SEMPRE já resolvido por quem chama (nunca
 *    resolvido internamente) — garante janela byte-idêntica à que
 *    `DesempenhoScoreService::compute()` usou. `$mesFechado` deriva de
 *    `$periodo['is_closed']`, nunca é parâmetro próprio.
 *  - C-01 · não existe gate de cobertura de margem ativo a respeitar aqui
 *    (`fallbackMargemPct()`/`coberturaMargem()` são código morto) — não
 *    inventar patamar de cobertura.
 *  - C-02 · os guards de dias-comuns/diff já operam POR EMPRESA dentro do
 *    `AdmanMetricDiffService` — herdados de graça. O único trecho agregado
 *    que NÃO é portado é `$vars->avg()`/o blend `margemPontos()`.
 *  - C-03 · `reguaFaturamento()`/`reguaMargem()` são duplicadas BYTE A BYTE
 *    do `DesempenhoScoreService` (privadas lá, gate de aditividade proíbe
 *    torná-las públicas) — teste de equivalência via Reflection cobre a
 *    divergência. Unificação real fica para a Fase 120, quando o gate sair.
 *  - C-04 · `MetricDiffDispatcher::compute()` lança `InvalidArgumentException`
 *    para fonte inválida — o guard de fonte não-nula vem ANTES da chamada,
 *    nunca dentro de um `catch`.
 *
 * ─── Aditividade ───────────────────────────────────────────────────────────
 * `DesempenhoScoreService` é ESPELHADO, não substituído — nenhum número de
 * produção muda nesta fase. Nenhum consumidor de produção referencia esta
 * classe; ela é exercitada só por testes até a Fase 120 ligar a flag.
 *
 * @see .planning/phases/119-score-por-empresa-v21-0/119-CONTEXT.md
 * @see app/Services/DesempenhoScoreService.php:1290,1311 (réguas duplicadas — NÃO reescritas)
 * @see app/Services/Desempenho/NpsPorEmpresaService.php (componente NPS consumido, não reimplementado)
 */
class CompanyScoreService
{
    public function __construct(
        private CarteiraContextService $carteiraContext,
        private MetricDiffDispatcher $diffDispatcher,
        private NpsPorEmpresaService $npsPorEmpresaService,
        private FinancialSourceResolver $financialSourceResolver,
        // Fase 136 (D-01/D-02/D-05/D-06/D-07/D-08) — decorator que substitui
        // o eixo `manual` sobre o resultado do dispatcher. Ver `aplicar()`
        // logo após a chamada ao `MetricDiffDispatcher::compute()` abaixo.
        private ManualMetricOverrideService $manualOverride,
    ) {
    }

    /**
     * Linha de fato por `(user_id, company_id)` — NPS, faturamento e margem
     * já pontuados pela régua (por empresa, D3 da milestone), mais
     * `nota_empresa`/`nota_empresa_parcial` (D-01).
     *
     * D-05 (assinatura): `$periodo` chega SEMPRE já resolvido por quem chama
     * (nunca resolvido internamente) — garante janela byte-idêntica à que
     * `DesempenhoScoreService::compute()` usou para a MESMA competência,
     * senão a Fase 121 compararia notas divergentes por causa da janela, não
     * da fórmula. `$mesFechado` NÃO é parâmetro próprio — deriva de
     * `(bool) ($periodo['is_closed'] ?? false)`, o mesmo sinal canônico que
     * `DesempenhoScoreService::compute()` usa ao chamar `computeNpsWindow()`.
     * `$invalidadas` nulo resolve via `BonusInvalidacao::companyIdsInvalidadas()`
     * (NUNCA `collect()` vazio, que silenciaria a invalidação) e o MESMO
     * objeto é repassado ao `NpsPorEmpresaService` — garante universos
     * idênticos entre os dois serviços.
     *
     * Ordem de execução (Resposta 7 do RESEARCH — invalidação ANTES de
     * qualquer resolução de fonte ou chamada HTTP): 1) resolve invalidadas;
     * 2) monta o universo via `CarteiraContextService::forUser()`, rejeitando
     * invalidadas; 3) resolve a fonte financeira vencedora por empresa
     * (universo COMPLETO, nunca pré-filtrado por elegibilidade); 4) UMA
     * chamada ao `NpsPorEmpresaService`; 5) carrega só os `Company` models
     * das empresas com fonte não-nula; 6) monta a linha por empresa, com o
     * guard C-04 (nunca chamar o dispatcher com fonte nula) e UMA única
     * chamada ao `MetricDiffDispatcher` por empresa (EMPS-05).
     *
     * `status` (Resposta 6 do RESEARCH — `blocked`/`invalidada`/
     * `sem_baseline` são deliberadamente inexistentes): `sem_fonte` quando
     * não há fonte financeira elegível (D-03); `complete` quando todos os
     * componentes ESPERADOS estão presentes (3 no caso geral, 2 para Shopee —
     * ver D-02); `sem_dados` quando nenhum componente está presente;
     * `partial` no meio-termo.
     *
     * @return Collection<int, object{
     *   company_id: int, company_name: string, fonte_financeira: ?string,
     *   nps_pontos: ?float,
     *   faturamento_atual: ?float, faturamento_anterior: ?float,
     *   faturamento_var_pct: ?float, faturamento_pontos: ?float,
     *   margem_pct_atual: ?float, margem_pct_anterior: ?float,
     *   margem_var_pp: ?float, margem_pontos: ?float,
     *   componentes_presentes: int, componentes_esperados: int,
     *   nota_empresa: ?float, nota_empresa_parcial: ?float,
     *   status: string,
     *   quality: array{revenue_diff_source: ?string, margin_diff_source: ?string, margin_source: ?string, motivos: array<int,string>, faturamento_fonte: string, margem_fonte: string},
     * }> chaveada por `company_id`.
     *
     * Fase 136 (D-03) — `quality.faturamento_fonte`/`quality.margem_fonte`
     * (`'auto'|'manual'`) sinalizam se o eixo veio de lançamento manual
     * (`ManualMetricOverrideService::aplicar()`). Sempre presentes, mesmo
     * quando a célula não tem nenhum lançamento (`'auto'` nos dois).
     */
    public function computeEmpresasScore(User $user, Carbon $mes, array $periodo, ?Collection $invalidadas = null): Collection
    {
        // 1. Empresas invalidadas na competência M — NUNCA collect() vazio
        //    por default (silenciaria a invalidação, T-119-03).
        $invalidadas = $invalidadas ?? BonusInvalidacao::companyIdsInvalidadas($mes);

        // Sinal canônico de mês fechado — o MESMO que DesempenhoScoreService
        // usa ao chamar computeNpsWindow(). Fonte única de verdade (D-05).
        $mesFechado = (bool) ($periodo['is_closed'] ?? false);

        // 2. Universo vivo — SEMPRE via forUser(), nunca join direto em
        //    company_users nem $user->companies() (T-119-02).
        $vinculos = $this->carteiraContext->forUser($user, ['active' => true])
            ->reject(fn (array $v) => $invalidadas->contains($v['company_id']));

        // forUser() NÃO colapsa vínculos — deduplicar aqui.
        $companiesUniverso = $vinculos->pluck('company_id')->unique()->values();

        if ($companiesUniverso->isEmpty()) {
            return collect();
        }

        // 4. Mapa company_id => company_name, a partir do primeiro vínculo
        //    de cada empresa (múltiplos vínculos da mesma empresa repetem o
        //    mesmo nome).
        $nomesPorEmpresa = [];
        foreach ($vinculos as $vinculo) {
            $nomesPorEmpresa[$vinculo['company_id']] ??= $vinculo['company_name'];
        }

        // 5. Vínculos elegíveis (financial_metrics_eligible=true) e as
        //    empresas correspondentes, carregadas ANTES do desempate — Fase
        //    136 (D-10): o critério de "tem Adman de fato" (`Company::cust_id`)
        //    precisa do model `Company` disponível DURANTE a resolução da
        //    fonte, não depois. Mesma quantidade de linhas que a query
        //    buscava antes (era o subconjunto já resolvido, ver `git blame`
        //    pré-136) — aqui é reordenada sobre o universo elegível completo,
        //    não é query nova.
        $vinculosElegiveis   = $vinculos->where('financial_metrics_eligible', true);
        $companyIdsElegiveis = $vinculosElegiveis->pluck('company_id')->unique();
        $companies           = Company::whereIn('id', $companyIdsElegiveis)->get()->keyBy('id');

        // Fonte financeira vencedora — Fase 136 (D-10): 'adman' vence sobre
        // 'shopee' quando a MESMA empresa tem os dois vínculos elegíveis
        // **e a empresa tem `cust_id`** (conta Adman de fato — antes desta
        // fase, 'adman' vencia sem checar isso, e a mesma empresa aparecia
        // com número para um profissional e em branco para outro, ver
        // `.planning/learnings/desempenho-bonificacao.md` §0.04). Empresa
        // sem NENHUM vínculo elegível fica FORA do mapa (D-03) — o universo
        // permanece o COMPLETO, nunca pré-filtrado. `FinancialSourceResolver`
        // é a fonte ÚNICA desta regra — os outros 2 call-sites
        // (`DesempenhoScoreService::computeUniverso()` e
        // `PortfolioController::fontesFinanceirasPorEmpresa()`) delegam ao
        // MESMO resolvedor, nunca duplicam esta lógica.
        $fontesPorEmpresa = $this->financialSourceResolver->resolverPorEmpresa($vinculosElegiveis, $companies);

        // Fase 136 — pré-carga em LOTE dos lançamentos manuais ativos da
        // competência (e do mês anterior, para a cascata de D-06), UMA única
        // query para a carteira inteira. Só as empresas com fonte financeira
        // resolvida chegam ao dispatcher — são as únicas que podem ter
        // célula manual (a linha `sem_fonte` nunca chega lá, D-91-01).
        $companyIdsComFonte = $fontesPorEmpresa->keys()->all();
        $lancamentosManuais = $this->manualOverride->carregarLancamentos($mes, $companyIdsComFonte);

        // 6. UMA chamada cobrindo toda a carteira — nunca em loop por
        //    empresa. O MESMO $invalidadas repassado garante universo
        //    idêntico ao deste método.
        $notasNps = $this->npsPorEmpresaService->notasNpsPorEmpresa($user, $mes, $mesFechado, $invalidadas);

        // 8. Monta a linha por empresa.
        return $companiesUniverso->mapWithKeys(function (int $companyId) use (
            $user,
            $mes,
            $periodo,
            $nomesPorEmpresa,
            $fontesPorEmpresa,
            $notasNps,
            $companies,
            $lancamentosManuais,
        ): array {
            $companyName     = $nomesPorEmpresa[$companyId] ?? '';
            $fonteFinanceira = $fontesPorEmpresa[$companyId] ?? null;

            // NPS — defensivo: a chave DEVERIA sempre existir (os dois
            // serviços partem do mesmo forUser()), mas nunca lança exceção
            // se faltar (T-119-01 — log só com IDs/competência).
            $npsMotivo = null;
            if ($notasNps->has($companyId)) {
                $linhaNps  = $notasNps->get($companyId);
                $npsPontos = $linhaNps->nota ?? null;

                // NPS não gerado e NPS não respondido valem 1,00 — regra
                // reafirmada pelo usuário em 2026-08-05. É deliberado que a
                // nota da carteira caia quando a pesquisa não sai: sem isso,
                // deixar de disparar o link sairia mais barato do que disparar
                // e receber nota baixa.
                //
                // Consequência conhecida e aceita: a nota do sistema fica
                // ABAIXO do fechamento manual em `Fechamento Junho _ Time de
                // performance.xlsx`, que não aplica essa penalidade. Na
                // competência 2026-06 isso atingia 92 das 286 lojas (32%) e
                // era a maior causa isolada da divergência entre os dois.
                // Não "corrigir" para bater com a planilha — a diferença é a
                // regra, não um defeito.
                if ($npsPontos === null) {
                    // Fase 119.1 (D1) — distinguir "não elegível" de "janela
                    // aberta" pela `origem` da linha. Leitura defensiva
                    // (`?? null`, mesmo espírito do guard T-119-01 acima):
                    // vários testes mockam a linha sem essa chave — o
                    // fallback cai no comportamento antigo (`nps_janela_aberta`).
                    $npsMotivo = ($linhaNps->origem ?? null) === 'nao_elegivel'
                        ? 'nps_nao_elegivel'
                        : 'nps_janela_aberta';
                }
            } else {
                $npsPontos = null;
                $npsMotivo = 'nps_indisponivel';
                Log::warning('[Score por Empresa] company_id ausente no retorno do NPS', [
                    'user_id'     => $user->id,
                    'company_id'  => $companyId,
                    'competencia' => $mes->format('Y-m'),
                ]);
            }

            // D-03 — sem fonte financeira elegível: linha SEM ir ao
            // dispatcher (guard C-04, aplicado ANTES de qualquer chamada).
            if ($fonteFinanceira === null) {
                $motivos = ['sem_fonte_financeira'];
                if ($npsMotivo !== null) {
                    $motivos[] = $npsMotivo;
                }

                return [$companyId => $this->linhaSemFonte($companyId, $companyName, $npsPontos, $motivos)];
            }

            // EMPS-05 — UMA única chamada ao dispatcher por empresa,
            // alimentando faturamento E margem.
            $company   = $companies->get($companyId);
            $resultado = $this->diffDispatcher->compute($company, $periodo, $fonteFinanceira);

            // Fase 136 — o override substitui o eixo marcado `manual` (D-01/
            // D-02/D-05/D-06/D-07/D-08). Empresa sem lançamento na célula sai
            // idêntica (caminho rápido do próprio serviço); todas as leituras
            // abaixo continuam vindo do mesmo shape.
            $resultado = $this->manualOverride->aplicar($company, $mes, $periodo, $fonteFinanceira, $resultado, $lancamentosManuais);

            $faturamentoAtual    = $resultado['metrics']['revenue']['value'] ?? null;
            $faturamentoAnterior = $resultado['metrics']['revenue']['prev_value'] ?? null;
            $faturamentoVarPct   = $resultado['metrics']['revenue']['diff_pct'] ?? null;
            $revenueDiffSource   = $resultado['metrics']['revenue']['diff_source'] ?? null;

            $margemPctAtual    = $resultado['metrics']['contribution_margin_pct']['value'] ?? null;
            $margemPctAnterior = $resultado['metrics']['contribution_margin_pct']['prev_value'] ?? null;
            // EMPS-03 — SEMPRE diff_pp (pontos percentuais), JAMAIS diff_pct.
            $margemVarPp      = $resultado['metrics']['contribution_margin_pct']['diff_pp'] ?? null;
            $margemDiffSource = $resultado['metrics']['contribution_margin_pct']['diff_source'] ?? null;

            $faturamentoPontos = $this->reguaFaturamento($faturamentoVarPct);

            // Fase 136 (D-07) — sinal de margem manual, lido do override.
            // A exceção abaixo (Shopee deixando de ser incondicionalmente
            // excluída da régua de margem) vale EXCLUSIVAMENTE para célula
            // marcada `manual`: é o mecanismo pelo qual um CMV lançado à mão
            // passa a pontuar, mesmo numa loja cuja plataforma não fornece
            // CMV nativo.
            $margemManual = ($resultado['quality']['margem_fonte'] ?? 'auto') === 'manual';

            // D-02 — Shopee nunca aplica a régua de margem, SALVO quando a
            // célula tem CMV manual (Fase 136, D-07) — `$margemManual` acima.
            //
            // Até 2026-08-05 a loja Shopee entrava com placeholder 1,0 (a nota
            // mínima). Passou a ficar FORA da média de margem, por decisão do
            // usuário: a Shopee não fornece CMV, então a ausência é falta de
            // dado da plataforma, não desempenho ruim do profissional —
            // penalizar por isso castigava quem tem carteira Shopee por algo
            // que não está sob seu controle. Com o denominador independente
            // por indicador (ver `computeNotaFinalPorIndicador`), a loja
            // continua contando no faturamento e no NPS; só não entra no
            // denominador da margem. ESSA REGRA CONTINUA VALENDO
            // INTEGRALMENTE para Shopee SEM CMV lançado — o que mudou na
            // Fase 136 é que agora existe um caminho pelo qual o CMV pode
            // existir (lançamento manual), e nesse caso a loja passa a
            // pontuar como qualquer outra fonte.
            //
            // Efeito medido na competência 2026-07: Matheus 2,86 → 4,73 e
            // Felipe 2,31 → 3,20 (51 das 288 lojas da base são Shopee).
            $margemPontos = match (true) {
                $fonteFinanceira === 'shopee' && ! $margemManual => null,
                $margemVarPp === null                            => null,
                default                                          => $this->reguaMargem($margemVarPp),
            };

            // Lançamento do tipo `ponto` (2026-08-31) — substitui a saída da
            // régua, e por isso é aplicado AQUI e não no override de métrica.
            // Vale inclusive onde a régua devolveu null: é exatamente o caso
            // que o usuário quer resolver à mão (loja sem baseline, Shopee sem
            // CMV). Quando entra, a célula conta como componente presente.
            $faturamentoPontoManual = $this->manualOverride->pontoManual(
                $company->id, $mes, $fonteFinanceira, DesempenhoMetricaManual::METRICA_FATURAMENTO, $lancamentosManuais
            );
            if ($faturamentoPontoManual !== null) {
                $faturamentoPontos = $faturamentoPontoManual;
                $resultado['quality']['faturamento_fonte'] = 'manual';
            }

            $margemPontoManual = $this->manualOverride->pontoManual(
                $company->id, $mes, $fonteFinanceira, DesempenhoMetricaManual::METRICA_MARGEM_CMV, $lancamentosManuais
            );
            if ($margemPontoManual !== null) {
                $margemPontos = $margemPontoManual;
                $resultado['quality']['margem_fonte'] = 'manual';
                $margemManual = true;
            }

            $motivos = [];
            if ($faturamentoPontos === null) {
                $motivos[] = 'faturamento_sem_baseline';
            }
            // Fase 136 (D-07) — `margem_pp_indisponivel` passa a valer também
            // para Shopee com margem manual sem `diff_pp` (ex.: CMV lançado
            // no mês, mas sem CMV manual no mês anterior para formar a base).
            // A exceção é exclusiva de célula `manual` — Shopee sem CMV
            // lançado continua reportando `margem_nao_fornecida_shopee`
            // abaixo, nunca este motivo.
            // `$margemPontos !== null` desde 2026-08-31: com PONTO lançado à
            // mão não falta nada para pontuar margem, mesmo sem `diff_pp` —
            // o ponto é justamente o atalho para a loja que não tem baseline.
            // Sem esta condição a célula pontuaria e ainda assim reportaria
            // indisponibilidade, contradizendo a própria linha.
            if (($fonteFinanceira === 'adman' || $margemManual) && $margemVarPp === null && $margemPontos === null) {
                $motivos[] = 'margem_pp_indisponivel';
            }
            // Regra de 2026-08-05 intacta para quem NÃO tem CMV manual —
            // Shopee segue fora da média de margem por falta de dado da
            // plataforma. `! $margemManual` é a única condição nova aqui.
            if ($fonteFinanceira === 'shopee' && ! $margemManual) {
                $motivos[] = 'margem_nao_fornecida_shopee';
            }
            // D-08 — o override sinaliza este caso quando há CMV manual mas
            // nenhum faturamento (nem manual nem via API) para derivar a
            // margem dele.
            if (in_array('margem_manual_sem_faturamento', $resultado['quality']['motivos_manual'] ?? [], true)) {
                $motivos[] = 'margem_manual_sem_faturamento';
            }
            if ($npsMotivo !== null) {
                $motivos[] = $npsMotivo;
            }

            // Componentes ESPERADOS da loja (2026-08-05) — a Shopee não fornece
            // CMV, então margem não é um buraco na medição dela: é uma dimensão
            // que a plataforma não entrega. Sem esta distinção, tirar o
            // placeholder 1,0 jogaria TODA loja Shopee em `partial`, e o
            // `computeScoreStatusPorEmpresa` (cobertura mínima de 0,7)
            // rebaixaria o profissional de carteira Shopee para `partial` ou
            // `blocked` — trocando uma punição na nota por outra no status.
            //
            // Mesmo princípio que `margem_amostra` já aplica ao denominador
            // desde a Fase 110: ausência legítima de expectativa não conta
            // como degradação. Fase 136 (D-07): com margem manual presente, a
            // loja Shopee passa a esperar os 3 componentes, como qualquer
            // outra fonte — `! $margemManual` é a única condição nova aqui.
            $componentesEsperados = $fonteFinanceira === 'shopee' && ! $margemManual ? 2 : 3;

            // D-01 — os DOIS números: nota_empresa (estrita) e
            // nota_empresa_parcial (média dos presentes). "Estrita" passou a
            // significar "todos os componentes ESPERADOS presentes", não
            // "exatamente 3" — do contrário loja Shopee jamais fecharia nota,
            // e o status diria `complete` com `nota_empresa` nula.
            $componentes = collect([$npsPontos, $faturamentoPontos, $margemPontos])
                ->reject(fn ($v) => $v === null);

            $componentesPresentes = $componentes->count();
            $notaEmpresaParcial    = $componentes->isEmpty() ? null : round($componentes->avg(), 2);
            $notaEmpresa           = $componentesPresentes === $componentesEsperados ? $notaEmpresaParcial : null;

            $status = match (true) {
                $componentesPresentes === $componentesEsperados => 'complete',
                $componentesPresentes === 0                     => 'sem_dados',
                default                                         => 'partial',
            };

            return [$companyId => (object) [
                'company_id'            => $companyId,
                'company_name'          => $companyName,
                'fonte_financeira'      => $fonteFinanceira,
                'nps_pontos'            => $npsPontos,
                'faturamento_atual'     => $faturamentoAtual,
                'faturamento_anterior'  => $faturamentoAnterior,
                'faturamento_var_pct'   => $faturamentoVarPct,
                'faturamento_pontos'    => $faturamentoPontos,
                'margem_pct_atual'      => $margemPctAtual,
                'margem_pct_anterior'   => $margemPctAnterior,
                'margem_var_pp'         => $margemVarPp,
                'margem_pontos'         => $margemPontos,
                'componentes_presentes' => $componentesPresentes,
                'componentes_esperados' => $componentesEsperados,
                'nota_empresa'          => $notaEmpresa,
                'nota_empresa_parcial'  => $notaEmpresaParcial,
                'status'                => $status,
                'quality'               => [
                    'revenue_diff_source' => $revenueDiffSource,
                    'margin_diff_source'  => $margemDiffSource,
                    'margin_source'       => $fonteFinanceira === 'shopee' ? 'sem_margem_shopee' : null,
                    'motivos'             => $motivos,
                    // Fase 136 (D-03) — sinal de origem por eixo, sempre
                    // presente ('auto' quando a célula não tem lançamento).
                    'faturamento_fonte'   => $resultado['quality']['faturamento_fonte'] ?? 'auto',
                    'margem_fonte'        => $resultado['quality']['margem_fonte'] ?? 'auto',
                ],
            ]];
        });
    }

    /**
     * Monta a linha de retorno para empresa SEM fonte financeira elegível
     * (D-03) — nunca chega ao `MetricDiffDispatcher` (guard C-04). Único
     * componente possível é o NPS; os 3 campos financeiros ficam `null` e
     * `nota_empresa` também (D-01 — só fecha com os 3 componentes).
     *
     * Fase 136 — o `ManualMetricOverrideService` NUNCA alcança esta linha,
     * de propósito: ela retorna ANTES do dispatcher (guard C-04 acima), e o
     * override só roda sobre o resultado do dispatcher. Isso protege a trava
     * D-91-01 (`.planning/learnings/desempenho-bonificacao.md` §0.1) —
     * carteira sem NENHUM vínculo financeiro elegível não recebe nota
     * oficial, e um lançamento manual não pode ser a porta dos fundos para
     * ela receber uma. `faturamento_fonte`/`margem_fonte` ficam fixos em
     * `'auto'` aqui só por simetria de shape com a linha normal — nunca
     * podem valer `'manual'` nesta linha.
     *
     * @param  array<int, string>  $motivos  já montados na ordem determinística
     *                                        (sem_fonte_financeira primeiro).
     */
    private function linhaSemFonte(int $companyId, string $companyName, ?float $npsPontos, array $motivos): object
    {
        return (object) [
            'company_id'            => $companyId,
            'company_name'          => $companyName,
            'fonte_financeira'      => null,
            'nps_pontos'            => $npsPontos,
            'faturamento_atual'     => null,
            'faturamento_anterior'  => null,
            'faturamento_var_pct'   => null,
            'faturamento_pontos'    => null,
            'margem_pct_atual'      => null,
            'margem_pct_anterior'   => null,
            'margem_var_pp'         => null,
            'margem_pontos'         => null,
            'componentes_presentes' => $npsPontos !== null ? 1 : 0,
            // Sem fonte financeira, o único componente possível é o NPS — mas
            // `sem_fonte` continua sendo um status próprio (nunca `complete`),
            // então o esperado permanece 3 para não mascarar a lacuna real.
            'componentes_esperados' => 3,
            'nota_empresa'          => null,
            'nota_empresa_parcial'  => $npsPontos,
            'status'                => 'sem_fonte',
            'quality'               => [
                'revenue_diff_source' => null,
                'margin_diff_source'  => null,
                'margin_source'       => null,
                'motivos'             => $motivos,
                'faturamento_fonte'   => 'auto',
                'margem_fonte'        => 'auto',
            ],
        ];
    }

    /**
     * Régua de FATURAMENTO — aplica pontuação 1-5 pts à % de variação de
     * faturamento vs mês anterior POR EMPRESA (Fase 119 — diferente do
     * original, que aplica sobre a média da carteira).
     *
     * DUPLICAÇÃO INTENCIONAL E TEMPORÁRIA (C-03/119-CONTEXT.md): o gate de
     * aditividade proíbe tornar `DesempenhoScoreService::reguaFaturamento()`
     * `protected`/pública nesta fase. Corpo copiado BYTE A BYTE de
     * `DesempenhoScoreService.php:1290-1298` — não "melhorar", não reordenar
     * comparações, não trocar `<=` por `<`. A unificação real (extrair para
     * uma classe compartilhada) fica para a Fase 120, quando o gate sair. A
     * equivalência com o original é provada por
     * `CompanyScoreServiceReguasTest` via Reflection, em todos os boundaries.
     *
     * Ancorada no SPEC-04 "Régua de Faturamento" da diretoria, adaptada à
     * interpretação vs-mês-anterior escolhida em spec-phase Q1:
     *   ≤ -6%  → 1 pt (queda severa)
     *   ≤ -1%  → 2 pts (queda leve)
     *   <  1%  → 3 pts (estável / meta)
     *   ≤  5%  → 4 pts (crescimento saudável)
     *   >  5%  → 5 pts (crescimento excelente)
     */
    private function reguaFaturamento(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -6)    return 1.0;
        if ($pct <= -1)    return 2.0;
        if ($pct <   1)    return 3.0;
        if ($pct <=  5)    return 4.0;
        return 5.0;
    }

    /**
     * Régua de MARGEM DE CONTRIBUIÇÃO — aplica pontuação 1-5 pts à variação
     * de margem POR EMPRESA.
     *
     * DUPLICAÇÃO INTENCIONAL E TEMPORÁRIA (C-03/119-CONTEXT.md) — mesmos
     * termos da nota de `reguaFaturamento()` acima. Corpo copiado BYTE A
     * BYTE de `DesempenhoScoreService.php:1311-1319` — cortes numéricos
     * IDÊNTICOS.
     *
     * NOTA (Fase 119, D2 da milestone v21.0): no `DesempenhoScoreService`
     * original, esta função é chamada sobre uma % de variação RELATIVA
     * (`diff_pct`, agregada da carteira). Aqui, a MESMA função (cortes
     * idênticos) deve receber `margem_var_pp` (pontos percentuais, `diff_pp`,
     * por empresa) — NUNCA `diff_pct`. Ver EMPS-03.
     *
     * Ancorada no SPEC-05 "Régua de Margem" da diretoria:
     *   ≤ -5%  → 1 pt
     *   ≤ -2%  → 2 pts
     *   ≤  1%  → 3 pts
     *   ≤  4%  → 4 pts
     *   >  4%  → 5 pts
     */
    private function reguaMargem(?float $pct): ?float
    {
        if ($pct === null) return null;
        if ($pct <= -5)    return 1.0;
        if ($pct <= -2)    return 2.0;
        if ($pct <=  1)    return 3.0;
        if ($pct <=  4)    return 4.0;
        return 5.0;
    }
}
