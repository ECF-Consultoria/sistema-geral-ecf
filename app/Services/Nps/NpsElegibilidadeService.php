<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\NpsSurvey;
use App\Models\NpsTemplate;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fonte única de "quais empresas deveriam ter recebido NPS neste mês, e de
 * qual modelo" — Fase 119.1 Plan 01.
 *
 * Extraído LITERALMENTE de `NpsDispararMensal::handle()` (Phase 79 Plan 03,
 * DEC-79-A), sem mudar nenhuma regra. É consumido por:
 *  - `NpsDispararMensal` (o próprio disparo mensal, manual a partir desta fase);
 *  - o guard de duplicidade do disparo manual (`NpsController::generate()`,
 *    Plan 119.1-02);
 *  - o ramo D1 do bônus — empresa elegível sem nenhum link no mês também
 *    conta nota 1 (Plans 119.1-03/04);
 *  - a cobertura do NPS de grupo (Plans 119.1-05/06).
 *
 * Função pura: nenhum método loga, conta ou escreve no banco. Quem decide o
 * que fazer com o resultado (logar, contar, disparar email) é o consumidor —
 * a extração preserva 100% do comportamento observável de `NpsDispararMensal`.
 *
 * @see app/Console/Commands/NpsDispararMensal.php
 * @see app/Services/Nps/NpsImputationService.php (mesma régua de competência)
 * @see .planning/phases/119.1-nps-manual-sem-duplicidade-e-por-grupo-de-empresas/119.1-01-PLAN.md
 */
class NpsElegibilidadeService
{
    /**
     * Estrategista responsável pela empresa (slot consolidado) — guard
     * obrigatório da elegibilidade (D-07 Phase 31): empresa sem estrategista
     * nunca é elegível.
     */
    public function estrategistaDaEmpresa(Company $empresa): ?User
    {
        return $empresa->estrategista()->first();
    }

    /**
     * Modelos NPS aplicáveis à empresa — reproduz LITERALMENTE o bloco
     * DEC-79-A (Phase 79 Plan 03): serviços ATIVOS contratados pela empresa
     * e, a partir deles, todos os modelos `active + envio_automatico_mensal`
     * cujos "Serviços cobertos" (pivot `nps_template_service_scopes`)
     * intersectam esses serviços.
     *
     * ESTRITO por construção — sem fallback `is_default`. Empresa sem
     * cobertura devolve coleção vazia (nenhum NPS, DEC-79-A).
     */
    public function modelosAplicaveis(Company $empresa): Collection
    {
        $servicoIds = $empresa->contratosServico()->active()->pluck('servico_id');

        return NpsTemplate::query()
            ->where('active', true)
            ->where('envio_automatico_mensal', true)
            ->whereHas('serviceScopes', fn ($q) => $q->whereIn('nps_template_service_scopes.servico_id', $servicoIds))
            ->get();
    }

    /**
     * Empresas elegíveis a receber NPS no mês — 1 item por par
     * (empresa, modelo aplicável). Aplica APENAS os dois critérios que
     * `NpsDispararMensal` usa para decidir SE deveria ter sido enviado:
     * `active = true` + estrategista atribuído + ao menos 1 modelo aplicável.
     *
     * NÃO aplica (de propósito — D5/119.1-CONTEXT.md):
     *  - filtro de canal de contato (email/Digisac) — é requisito de DISPARO
     *    (COMO enviar), não de elegibilidade (SE deveria ter sido enviado).
     *    Empresa sem canal continua elegível, com `tem_canal = false` só como
     *    informação para a tela explicar o motivo depois;
     *  - filtro de dia do aniversário do cadastro — também é requisito de
     *    DISPARO (QUANDO o automático dispara), não de elegibilidade.
     *
     * @return Collection<int, object{company_id:int, company:Company, template_id:int, template:NpsTemplate, servico_ids:array, tem_canal:bool}>
     */
    public function empresasElegiveis(Carbon $mes): Collection
    {
        $itens = collect();

        Company::where('active', true)
            ->chunkById(50, function ($empresas) use ($itens) {
                foreach ($empresas as $empresa) {
                    if (! $this->estrategistaDaEmpresa($empresa)) {
                        continue;
                    }

                    $modelosAplicaveis = $this->modelosAplicaveis($empresa);

                    if ($modelosAplicaveis->isEmpty()) {
                        continue;
                    }

                    $servicoIdsAtivos = $empresa->contratosServico()->active()->pluck('servico_id');

                    // D5 — ausência de canal de contato NÃO filtra a elegibilidade.
                    // `tem_canal` é exposto só como informação (a tela explica o
                    // motivo da nota 1 quando faltar cadastrar o contato).
                    $temCanal = ! empty($empresa->email_cliente) || ! empty($empresa->digisac_group_contact_id);

                    foreach ($modelosAplicaveis as $modelo) {
                        $cobertos = $modelo->serviceScopes()->pluck('servicos.id');
                        $servicoIds = $cobertos->intersect($servicoIdsAtivos)->values()->all();

                        $itens->push((object) [
                            'company_id'  => $empresa->id,
                            'company'     => $empresa,
                            'template_id' => $modelo->id,
                            'template'    => $modelo,
                            'servico_ids' => $servicoIds,
                            'tem_canal'   => $temCanal,
                        ]);
                    }
                }
            });

        return $itens;
    }

    /**
     * Competência canônica de um survey — MESMA régua de
     * `NpsImputationService::materializar()`: `month_reference`, com fallback
     * `created_at` quando NULL (surveys manuais, D6 Fase 116).
     */
    public function competenciaDoSurvey(NpsSurvey $survey): Carbon
    {
        return ($survey->month_reference ?? $survey->created_at)->copy()->startOfMonth();
    }

    /**
     * Guard de duplicidade reusável — mesmo molde do `$jaExiste` de
     * `NpsDispararMensal`, mas cobrindo o caso `month_reference IS NULL`
     * (surveys manuais, C1/119.1-CONTEXT.md).
     *
     * Retorna o objeto (não `exists()`) — quem consome precisa poder devolver
     * o link já existente ao operador (Plan 119.1-02).
     */
    public function surveyExistenteNaCompetencia(int $companyId, int $templateId, Carbon $mes): ?NpsSurvey
    {
        $inicio = $mes->copy()->startOfMonth();
        $fim = $mes->copy()->endOfMonth();

        return NpsSurvey::where('company_id', $companyId)
            ->where('template_id', $templateId)
            ->where(function ($q) use ($inicio, $fim) {
                $q->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()])
                    ->orWhere(function ($qq) use ($inicio, $fim) {
                        $qq->whereNull('month_reference')
                            ->whereBetween('created_at', [$inicio, $fim]);
                    });
            })
            ->orderByDesc('id')
            ->first();
    }
}
