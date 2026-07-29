<?php

namespace App\Services\Desempenho;

use App\Models\BonusInvalidacao;
use App\Models\User;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsJanelaResolver;
use App\Services\Nps\NpsScoreCalculator;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Agregador de LEITURA que devolve nota 1.0 para a empresa ELEGÍVEL que
 * passou o mês sem NENHUM link de NPS — Fase 119.1 (D1, NPSMAN-06/07/12).
 *
 * (a) É o 4º ramo (D) da união disjunta de `DesempenhoScoreService::
 *     computeNpsMedio()`, ao lado de (A) atribuições, (B) legado e (C)
 *     imputadas (Fase 116). Plugado em `DesempenhoScoreService::
 *     notasSemLink()` (Task 2 deste plano).
 *
 * (b) SUPERSEDE deliberadamente o invariante D3 da Fase 116 ("empresa sem
 *     disparo nunca entra"). Com o disparo automático desligado (Plan
 *     119.1-01), aquele invariante viraria a brecha "não enviar sai mais
 *     barato" — o contrapeso (NPSMAN-07: empresa NÃO elegível continua fora)
 *     é o motivo de todo método aqui delegar a `NpsElegibilidadeService`, em
 *     vez de tratar "carteira viva" como sinônimo de "elegível".
 *
 * (c) É fallback de LEITURA — NUNCA materializa linha em
 *     `nps_imputed_assignments` (mesmo padrão de `NpsPorEmpresaService`,
 *     Fase 118 D-04). Motivo: `nps_imputed_assignments.survey_id` é FK
 *     NOT NULL por construção (garantia estrutural do D3 original), e os
 *     ~9 consumidores da Fase 116 fazem `whereHas('survey', ...)` — tornar a
 *     coluna nullable quebraria todos eles. O "survey" deste ramo não existe
 *     (a empresa não recebeu link nenhum), então a única opção correta é
 *     nunca escrever, e sim recalcular a cada leitura (mesmo custo que os
 *     ramos (A)/(B)/(C) já pagam).
 *
 * (d) O ramo D-04 do `NpsPorEmpresaService` (consumidor de produção da
 *     Fase 118, `CompanyScoreService:167`) aplica a MESMA régua de
 *     elegibilidade desde a Task 3 deste plano — qualquer mudança de regra
 *     AQUI precisa ser espelhada lá, sob pena de o relatório por empresa e o
 *     bônus discordarem sobre quem é elegível (gate de coerência no plano
 *     08, teste local em `NpsPorEmpresaElegibilidadeTest`).
 *
 * (e) `notasDaEmpresaSemLink()` (Fase 119.1 Plan 04) é a MESMA regra, só que
 *     agregada por dimensão para a ÁREA NPS (`NpsController::index()`), em
 *     vez de por pessoa como `notasDoUsuario()`.
 *
 * (f) `notasDoUsuarioNaJanela()` e `notasDaEmpresaSemLink(..., $piso)` (Fase
 *     119.1 Plan 09) generalizam (a)/(e) para JANELA MULTI-MÊS, com PISO
 *     RETROATIVO opcional (`pisoRetroativo()`) — DEC-09-B: `empresasElegiveis()`
 *     lê o estado de HOJE e não reconstrói elegibilidade histórica, então
 *     leitura em janela ROLANTE (início derivado de `now()`) é limitada a
 *     mês anterior + corrente, a MESMA janela de
 *     `nps:materializar-nao-respondidos`. DEC-09-C: o piso é PROIBIDO em
 *     leitura de competência FIXA (bônus `notasDoUsuario()`, meta
 *     `CalculateGoalResults::computeNps()`) — um piso relativo a `now()`
 *     ali faria o resultado depender da hora do recompute.
 *
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-CONTEXT.md D1, D5
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-03-PLAN.md
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-09-PLAN.md (janela multi-mês + piso retroativo)
 * @see app/Services/Nps/NpsElegibilidadeService.php (fonte única de "quem é elegível")
 * @see app/Services/Desempenho/NpsPorEmpresaService.php (o precedente exato generalizado aqui — D-04 da Fase 118)
 */
class NpsSemLinkService
{
    public function __construct(
        private NpsElegibilidadeService $elegibilidade,
        private NpsJanelaResolver $janela,
        private NpsScoreCalculator $calculator,
    ) {
    }

    /**
     * Notas 1.0 (D1) do usuário na janela [$inicio, $fim] — $inicio define o
     * mês de COLETA (M+1), a MESMA convenção travada que
     * `NpsPorEmpresaService::notasNpsPorEmpresa()` usa via `$mesNps`
     * (`<interfaces>` do plano 119.1-03). Quem chama este método já deve ter
     * deslocado a competência financeira M para M+1 — este método NUNCA
     * desloca nada internamente.
     *
     * @return Collection<int, object{
     *   survey_id: null, company_id: int, servico_id: int, role: string,
     *   service_setor: null, competencia_nps: Carbon, nota: float, origem: string,
     * }>
     */
    public function notasDoUsuario(User $user, Carbon $inicio, Carbon $fim, ?Collection $invalidadas = null): Collection
    {
        $mes = $inicio->copy()->startOfMonth();

        // 1. Mês de coleta AINDA ABERTO ⇒ ninguém é penalizado (D1 só vale
        //    depois que a janela fecha — mesma régua de `NpsJanelaResolver`
        //    consultada por `NpsPorEmpresaService`).
        if (! $this->janela->fechada($mes)) {
            return collect();
        }

        $invalidadas = $invalidadas ?? collect();

        // 2. Universo elegível do mês (fonte única — nunca reimplementar
        //    contrato ativo/modelo aplicável/estrategista aqui).
        $elegiveis = $this->elegibilidade->empresasElegiveis($mes)
            // 3. Empresa invalidada para bônus na competência sai (NPSMAN-07).
            ->reject(fn ($item) => $invalidadas->contains($item->company_id))
            // 4. Disjunção com os ramos (A)/(B)/(C): se já existe survey da
            //    competência (mesmo pendente, mesmo month_reference NULL),
            //    quem cobre o caso é o ramo (C) — nunca duplicar aqui.
            ->reject(fn ($item) => $this->elegibilidade->surveyExistenteNaCompetencia(
                $item->company_id,
                $item->template_id,
                $mes,
            ) !== null);

        $notas  = collect();
        $vistos = [];

        foreach ($elegiveis as $item) {
            foreach (['estrategista', 'consultor'] as $role) {
                $souResponsavel = false;

                // 5. Resolver o responsável POR SERVIÇO coberto pelo modelo,
                //    com fallback consolidado — NUNCA os métodos service-aware
                //    puros sem esse fallback (reintroduziria o gap do
                //    responsável CONSOLIDADO, memória
                //    `project_nps_assignment_consolidado_gap`).
                foreach ($item->servico_ids as $servicoId) {
                    $responsaveis = $item->company->responsavelDoServicoOuConsolidado($role, $servicoId);

                    if ($responsaveis->contains('id', $user->id)) {
                        $souResponsavel = true;
                        break;
                    }
                }

                if (! $souResponsavel) {
                    continue;
                }

                // 6. Dedupe por (empresa, modelo, papel) — a chave substituta
                //    do `survey_id|role` do ramo (C), já que aqui não existe
                //    survey. Empresa elegível para 2 modelos, ambos sem link,
                //    gera 1 nota POR MODELO (não 2 pelo mesmo modelo, mesmo
                //    que 2 serviços cobertos apontem pro mesmo responsável).
                $chave = $item->company_id . '|' . $item->template_id . '|' . $role;

                if (isset($vistos[$chave])) {
                    continue;
                }
                $vistos[$chave] = true;

                // 7. Mesmo shape de `NpsImputationService::notasDoUsuario()`.
                //    `service_setor` fica sempre null aqui — este ramo nunca
                //    materializa `nps_imputed_assignments` (o único consumidor
                //    daquela coluna), mantido só por espelhamento de shape.
                $notas->push((object) [
                    'survey_id'       => null,
                    'company_id'      => $item->company_id,
                    'servico_id'      => $item->servico_ids[0] ?? null,
                    'role'            => $role,
                    'service_setor'   => null,
                    'competencia_nps' => $mes->copy(),
                    'nota'            => 1.0,
                    'origem'          => 'sem_link',
                ]);
            }
        }

        return $notas;
    }

    /**
     * Limite retroativo das leituras de D1 em JANELA ROLANTE — Fase 119.1
     * Plan 09 (DEC-09-B). `NpsElegibilidadeService::empresasElegiveis()` lê
     * o estado de HOJE (contrato ativo, modelo com `envio_automatico_mensal`,
     * estrategista atribuído) e NÃO reconstrói quem era elegível em meses
     * antigos. Aplicar D1 a uma janela histórica ampla fabricaria nota 1 em
     * competências onde o contrato nem existia, e reescreveria o histórico
     * como efeito colateral de uma leitura — exatamente o que o backfill
     * retroativo da Fase 116 recusa fazer sem o gate humano do usuário
     * (C6 do 119.1-CONTEXT.md).
     *
     * Por isso: mesma janela da rotina diária `nps:materializar-nao-
     * respondidos` (mês anterior + corrente, commit `23c809c0`), pelo
     * mesmo motivo.
     *
     * ⚠ DEC-09-C — PROIBIDO aplicar este piso a leitura de competência
     * FIXA (`notasDoUsuario()` do bônus, `CalculateGoalResults::
     * computeNps()` da meta). Um piso relativo a `now()` ali faria o
     * resultado de uma competência FECHADA depender da HORA em que o
     * recompute roda — a mesma classe de bug já registrada no projeto em
     * `project_adman_margem_diff_instavel_bonus`. Só quem lê JANELA
     * ROLANTE (início derivado de `now()`) passa este piso.
     */
    public function pisoRetroativo(): Carbon
    {
        return now()->subMonthNoOverflow()->startOfMonth();
    }

    /**
     * Generalização de `notasDoUsuario()` para JANELA MULTI-MÊS — Fase
     * 119.1 Plan 09. Consumida pelos consumidores por PESSOA que precisam
     * de série histórica (`PerformanceController::notasNpsDoUsuarioPorResposta()`,
     * `PortfolioController` histórico mensal), ao invés de 1 único mês.
     *
     * ZERO regra de negócio nova: itera mês a mês (mesmo laço de
     * `notasDaEmpresaSemLink()`) delegando cada mês a `notasDoUsuario()` —
     * a dedupe por (empresa, modelo, papel) já acontece lá dentro; entre
     * meses diferentes não há dedupe a fazer, porque as competências são
     * disjuntas por construção.
     *
     * Quando `$piso` é informado, meses anteriores a ele são pulados ANTES
     * de chamar `notasDoUsuario()` — é o guard que evita a chamada
     * caríssima a `empresasElegiveis()` para meses fora da janela permitida
     * (DEC-09-B).
     *
     * Quando `$invalidadas` é null, a invalidação é resolvida POR MÊS,
     * dentro do laço, com o mesmo deslocamento M+1→M do precedente literal
     * `NpsController.php:807` (`companyIdsInvalidadas($mes->
     * subMonthNoOverflow()->startOfMonth())`). Quando informado
     * explicitamente, é repassado como veio para TODOS os meses (DEC-09-E).
     *
     * @return Collection<int, object{
     *   survey_id: null, company_id: int, servico_id: int, role: string,
     *   service_setor: null, competencia_nps: Carbon, nota: float, origem: string,
     * }>
     */
    public function notasDoUsuarioNaJanela(User $user, Carbon $de, Carbon $ate, ?Collection $invalidadas = null, ?Carbon $piso = null): Collection
    {
        $invalidadasExplicitas = $invalidadas;
        $notas                 = collect();

        $mesAtual  = $de->copy()->startOfMonth();
        $fimJanela = $ate->copy()->endOfMonth();

        while ($mesAtual->lte($fimJanela)) {
            // Piso PRIMEIRO — evita a chamada caríssima a empresasElegiveis()
            // (dentro de notasDoUsuario()) para meses fora da janela permitida.
            if ($piso !== null && $mesAtual->lt($piso)) {
                $mesAtual = $mesAtual->copy()->addMonthNoOverflow()->startOfMonth();

                continue;
            }

            $invalidadasDoMes = $invalidadasExplicitas
                ?? BonusInvalidacao::companyIdsInvalidadas($mesAtual->copy()->subMonthNoOverflow()->startOfMonth());

            $notas = $notas->merge(
                $this->notasDoUsuario($user, $mesAtual->copy(), $mesAtual->copy(), $invalidadasDoMes)
            );

            $mesAtual = $mesAtual->copy()->addMonthNoOverflow()->startOfMonth();
        }

        return $notas;
    }

    /**
     * Notas 1.0 (D1) por EMPRESA/dimensão — Fase 119.1 Plan 04. Consumido
     * pela ÁREA NPS (`NpsController::index()`), que agrega por dimensão
     * (estrategista/analista/empresa — D7), não por pessoa como
     * `notasDoUsuario()` acima. Espelha DELIBERADAMENTE a assinatura de
     * `NpsImputationService::notasDaEmpresa()` (mesma janela [$de, $ate],
     * mesmo `$templateIds` opcional) — mas é leitura pura, nunca
     * materializa nada (mesmo contrato de `notasDoUsuario()`).
     *
     * `$companyIds` já reflete o escopo de carteira/filtros da tela — quem
     * chama decide o universo; este método só aplica elegibilidade
     * (`NpsElegibilidadeService`), dimensão aplicável ao template
     * (`NpsScoreCalculator::contarPerguntasComPeso()`, MESMA régua de
     * `NpsImputationService::materializar()`) e a disjunção com surveys já
     * existentes na competência.
     *
     * Dedupe por (company_id, template_id) — chave substituta do
     * `survey_id` do ramo (C)/`notasDaEmpresa()`, já que aqui não existe
     * survey algum.
     *
     * `$piso` (Fase 119.1 Plan 09, DEC-09-B) — quando informado, meses
     * anteriores a ele são pulados: leitura em janela ROLANTE (início
     * derivado de `now()`) NUNCA alcança mais que mês anterior + corrente.
     * Consumidores de competência FIXA (meta) não passam este parâmetro
     * (DEC-09-C).
     *
     * `$invalidadas = null` (Fase 119.1 Plan 09, DEC-09-E) — quando o
     * chamador NÃO informa, a invalidação é resolvida POR MÊS aqui dentro,
     * com o mesmo deslocamento M+1→M do precedente literal
     * `NpsController.php:807`. Antes desta mudança, `null` significava
     * "sem filtro nenhum" — a armadilha que esta Task fecha. Quando
     * informado explicitamente, o valor é repassado IDÊNTICO para todos os
     * meses (comportamento do plano 04, área NPS, intocado).
     *
     * @return Collection<int, object{
     *   survey_id: null, company_id: int, role: null, service_setor: null,
     *   competencia_nps: Carbon, nota: float, origem: string,
     * }>
     */
    public function notasDaEmpresaSemLink(
        Collection $companyIds,
        string $dimensao,
        Carbon $de,
        Carbon $ate,
        ?Collection $invalidadas = null,
        ?Collection $templateIds = null,
        ?Carbon $piso = null,
    ): Collection {
        if ($companyIds->isEmpty()) {
            return collect();
        }

        $invalidadasExplicitas = $invalidadas;
        $notas                 = collect();

        // Itera mês a mês a janela [$de, $ate] — na prática a área NPS
        // sempre chama com 1 único mês (card do mês filtrado, ou 1 mês por
        // iteração da série de 12 meses), mas a assinatura aceita janela
        // igual à de `notasDaEmpresa()`.
        $mesAtual  = $de->copy()->startOfMonth();
        $fimJanela = $ate->copy()->endOfMonth();

        while ($mesAtual->lte($fimJanela)) {
            // 0. Piso retroativo (DEC-09-B) — PRIMEIRO, porque é o único
            //    guard que evita a chamada caríssima a empresasElegiveis()
            //    para meses fora da janela permitida.
            if ($piso !== null && $mesAtual->lt($piso)) {
                $mesAtual = $mesAtual->copy()->addMonthNoOverflow()->startOfMonth();

                continue;
            }

            // 1. Mês de coleta AINDA ABERTO ⇒ ninguém pesa (mesma régua de
            //    `notasDoUsuario()` acima).
            if (! $this->janela->fechada($mesAtual)) {
                $mesAtual = $mesAtual->copy()->addMonthNoOverflow()->startOfMonth();

                continue;
            }

            // DEC-09-E — invalidação resolvida POR MÊS quando não informada.
            $invalidadasDoMes = $invalidadasExplicitas
                ?? BonusInvalidacao::companyIdsInvalidadas($mesAtual->copy()->subMonthNoOverflow()->startOfMonth());

            $elegiveis = $this->elegibilidade->empresasElegiveis($mesAtual)
                // 2. Só as empresas do universo que a tela decidiu mostrar.
                ->filter(fn ($item) => $companyIds->contains($item->company_id))
                // 3. Empresa invalidada para bônus na competência sai (NPSMAN-07).
                ->reject(fn ($item) => $invalidadasDoMes->contains($item->company_id))
                // 4. Só entra se o TEMPLATE realmente mede esta dimensão —
                //    mesma régua de `NpsImputationService::materializar()`
                //    (linha 152), nunca reinventada aqui.
                ->filter(fn ($item) => $this->calculator->contarPerguntasComPeso($item->template_id, $dimensao) > 0)
                // 5. Disjunção com os ramos (A)/(B)/(C): já existe survey da
                //    competência (mesmo pendente, mesmo month_reference NULL)
                //    ⇒ quem cobre é o ramo (C), nunca duplicar aqui.
                ->reject(fn ($item) => $this->elegibilidade->surveyExistenteNaCompetencia(
                    $item->company_id,
                    $item->template_id,
                    $mesAtual,
                ) !== null);

            if ($templateIds && $templateIds->isNotEmpty()) {
                $elegiveis = $elegiveis->filter(fn ($item) => $templateIds->contains($item->template_id));
            }

            // 6. Dedupe por (empresa, modelo) — chave substituta do
            //    `survey_id` do ramo (C).
            $vistos = [];
            foreach ($elegiveis as $item) {
                $chave = $item->company_id . '|' . $item->template_id;

                if (isset($vistos[$chave])) {
                    continue;
                }
                $vistos[$chave] = true;

                $notas->push((object) [
                    'survey_id'       => null,
                    'company_id'      => $item->company_id,
                    'role'            => null,
                    'service_setor'   => null,
                    'competencia_nps' => $mesAtual->copy(),
                    'nota'            => 1.0,
                    'origem'          => 'sem_link',
                ]);
            }

            $mesAtual = $mesAtual->copy()->addMonthNoOverflow()->startOfMonth();
        }

        return $notas;
    }
}
