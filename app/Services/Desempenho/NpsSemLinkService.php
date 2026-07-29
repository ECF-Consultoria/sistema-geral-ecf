<?php

namespace App\Services\Desempenho;

use App\Models\User;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsJanelaResolver;
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
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-CONTEXT.md D1, D5
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-03-PLAN.md
 * @see app/Services/Nps/NpsElegibilidadeService.php (fonte única de "quem é elegível")
 * @see app/Services/Desempenho/NpsPorEmpresaService.php (o precedente exato generalizado aqui — D-04 da Fase 118)
 */
class NpsSemLinkService
{
    public function __construct(
        private NpsElegibilidadeService $elegibilidade,
        private NpsJanelaResolver $janela,
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
}
