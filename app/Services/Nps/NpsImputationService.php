<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\NpsImputedAssignment;
use App\Models\NpsSurvey;
use App\Models\NpsTemplateQuestion;
use App\Models\Servico;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Materializador da regra "NPS não respondido conta como nota mínima (1)" —
 * Fase 116 Plan 01 (fundação de dados).
 *
 * ÚNICA fonte de verdade de "quem entra na regra, quem não entra": contrato
 * ativo, responsável correto (com fallback consolidado), competência do
 * survey (com fallback manual), e transição provisório → definitivo. Nenhum
 * consumidor (Desempenho/NPS/Carteira) pode reimplementar esta lógica — todos
 * devem ler via `notasDoUsuario()`/`notasDaEmpresa()`/`surveyIdsComNotaDefinitiva()`.
 *
 * ─── Regras travadas (116-CONTEXT.md) ────────────────────────────────────
 *  - Gate de "não respondido" = status DIFERENTE de completed — NUNCA o
 *    status de vencido isolado (a transição pra esse status é lazy: só
 *    acontece quando alguém clica no link vencido).
 *  - `competencia_nps` = `month_reference` do survey, com fallback
 *    `created_at` quando NULL (D6 · disparo manual sem mês semântico).
 *  - `definitivo` é ESTADO GRAVADO, nunca cálculo ao vivo (D2/NPSFLOOR-07):
 *    uma resposta que chegue depois do fechamento da competência NÃO apaga
 *    nem reescreve a linha já definitiva.
 *  - Fechamento por DATA, nunca timestamp cru: `now()->startOfDay()->gt(...)`
 *    — e aqui é `gt` (não `gte`, diferente de `computeNpsWindow`) porque o
 *    link do NPS vale até 23:59:59 do último dia (`expires_at = endOfMonth`);
 *    marcar definitivo às 00:00 do último dia congelaria um 1 enquanto o
 *    cliente ainda tem 24h para responder.
 *  - Provisório TAMBÉM conta na leitura (D2 "vale desde o disparo") — é o
 *    que faz o snapshot mensal do bônus (que congela no ÚLTIMO DIA de M+1)
 *    enxergar os não respondidos daquele mesmo mês.
 *  - "Contrato ativo" é avaliado no momento da MATERIALIZAÇÃO, não no
 *    momento do disparo — limitação aceita do backfill retroativo.
 *
 * @see .planning/phases/116-.../116-01-PLAN.md
 * @see .planning/phases/116-.../116-CONTEXT.md
 * @see app/Services/Nps/NpsSnapshotService.php (analog de resolução de responsável)
 */
class NpsImputationService
{
    /**
     * Dimensões que podem gerar linha imputada — mesma lista de
     * `NpsSnapshotService::DIMENSOES` (sem 'geral', que nunca gera score de
     * pessoa/empresa).
     */
    private const DIMENSOES = [
        NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA,
        NpsTemplateQuestion::DIMENSAO_ANALISTA,
        NpsTemplateQuestion::DIMENSAO_EMPRESA,
    ];

    /**
     * Mapa dimensão → role da pivot `company_users` (mesma régua de
     * `NpsSnapshotService::DIMENSAO_ROLE`). A dimensão EMPRESA não está aqui
     * — nasce sem role/user_id (D7, linha própria).
     */
    private const DIMENSAO_ROLE = [
        NpsTemplateQuestion::DIMENSAO_ANALISTA     => 'consultor',
        NpsTemplateQuestion::DIMENSAO_ESTRATEGISTA => 'estrategista',
    ];

    public function __construct(private NpsScoreCalculator $calculator)
    {
    }

    /**
     * Materializa (ou limpa) as linhas imputadas de UM survey.
     *
     * @return array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}
     */
    public function materializar(NpsSurvey $survey, bool $dryRun = false): array
    {
        $stats = $this->statsVazio();

        // ─── Survey respondido: nunca imputar, e limpar provisório órfão ──
        // Se a resposta chegou (mesmo que tarde), qualquer linha provisória
        // deste survey deixou de fazer sentido — some na próxima
        // materialização. Linha DEFINITIVO nunca é tocada aqui (D2).
        if ($survey->status === 'completed') {
            $provisorias = NpsImputedAssignment::where('survey_id', $survey->id)
                ->where('status', NpsImputedAssignment::STATUS_PROVISORIO);

            $count = (clone $provisorias)->count();

            if ($count > 0) {
                if (! $dryRun) {
                    $provisorias->delete();
                }
                $stats['removidos'] += $count;
            }

            return $stats;
        }

        // Sem template não há como saber o que o modelo mede — pulado.
        if (! $survey->template_id) {
            Log::info('[NPS Imputação] survey sem template — pulado', ['survey_id' => $survey->id]);

            return $stats;
        }

        $company = $survey->company;
        if (! $company) {
            Log::warning('[NPS Imputação] survey sem empresa — pulado', ['survey_id' => $survey->id]);

            return $stats;
        }

        $competencia = ($survey->month_reference ?? $survey->created_at)->copy()->startOfMonth();

        // Comparação por DATA (nunca timestamp cru — armadilha corrigida na
        // Fase 105). Divergência DELIBERADA do padrão irmão
        // `DesempenhoScoreService::computeNpsWindow` (que usa `gte`): aqui o
        // link do NPS vale até 23:59:59 do último dia do mês
        // (`expires_at = endOfMonth`), então usar `gte` marcaria definitivo
        // às 00:00 do último dia enquanto o cliente ainda tem 24h de prazo.
        $competenciaFechada = now()->startOfDay()->gt($competencia->copy()->endOfMonth()->startOfDay());

        // ─── Promoção: linhas já existentes que ainda estavam provisórias
        // viram definitivo quando a competência fecha. Nunca mexe em linha
        // já definitivo (D2/NPSFLOOR-07).
        if ($competenciaFechada) {
            $paraPromover = NpsImputedAssignment::where('survey_id', $survey->id)
                ->where('status', NpsImputedAssignment::STATUS_PROVISORIO);

            $countPromover = (clone $paraPromover)->count();

            if ($countPromover > 0) {
                if (! $dryRun) {
                    $paraPromover->update([
                        'status'     => NpsImputedAssignment::STATUS_DEFINITIVO,
                        'locked_at'  => now(),
                        'updated_at' => now(),
                    ]);
                }
                $stats['promovidos_definitivo'] += $countPromover;
            }
        }

        // ─── Dimensões aplicáveis: só as que o TEMPLATE realmente mede ────
        $dimensoesAplicaveis = collect(self::DIMENSOES)
            ->filter(fn ($dimensao) => $this->calculator->contarPerguntasComPeso($survey->template_id, $dimensao) > 0);

        if ($dimensoesAplicaveis->isEmpty()) {
            return $stats;
        }

        $ativos = $company->contratosServico()->active()->pluck('servico_id')->all();
        $cobertos = $survey->template->serviceScopes()->get();
        $servicosAplicaveis = $cobertos->filter(fn (Servico $s) => in_array($s->id, $ativos, true));

        foreach ($dimensoesAplicaveis as $dimensao) {
            if ($dimensao === NpsTemplateQuestion::DIMENSAO_EMPRESA) {
                // D7 — 1 linha por survey, sem serviço/responsável.
                $this->materializarLinha(
                    $survey, $company, $dimensao, null, null, null,
                    $competencia, $competenciaFechada, $dryRun, $stats,
                );

                continue;
            }

            $role = self::DIMENSAO_ROLE[$dimensao] ?? null;
            if (! $role) {
                continue;
            }

            foreach ($servicosAplicaveis as $servico) {
                // Reusa SEMPRE responsavelDoServicoOuConsolidado — nunca os
                // métodos legados por-serviço sem fallback consolidado (regra
                // de ouro herdada da Fase 79/nps-assignment-consolidado).
                $responsaveis = $company->responsavelDoServicoOuConsolidado($role, $servico->id);

                if ($responsaveis->isEmpty()) {
                    Log::warning('[NPS Imputação] responsável faltante — nota 1 não gerada', [
                        'company_id' => $company->id,
                        'servico_id' => $servico->id,
                        'role'       => $role,
                        'dimensao'   => $dimensao,
                        'survey_id'  => $survey->id,
                    ]);
                    $stats['pulos_sem_responsavel']++;

                    continue;
                }

                foreach ($responsaveis as $user) {
                    $this->materializarLinha(
                        $survey, $company, $dimensao, $servico, $role, $user,
                        $competencia, $competenciaFechada, $dryRun, $stats,
                    );
                }
            }
        }

        return $stats;
    }

    /**
     * Varre surveys (com template) e materializa em lote. Sem `$mes`, varre
     * o histórico inteiro (backfill retroativo de graça). Inclui surveys
     * `completed` no lote — não para imputar, mas para limpar linhas
     * provisórias órfãs (survey que virou completed depois da última rodada).
     *
     * @return array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}
     */
    public function materializarLote(?Carbon $mes = null, bool $dryRun = false): array
    {
        $stats = $this->statsVazio();

        $query = NpsSurvey::query()->whereNotNull('template_id')->orderBy('id');

        if ($mes) {
            $inicio = $mes->copy()->startOfMonth();
            $fim    = $mes->copy()->endOfMonth();

            // Mesmo padrão de fallback usado em NpsController::index — mês
            // por month_reference, com fallback created_at para surveys
            // manuais (D6) sem mês semântico.
            $query->where(function ($q) use ($inicio, $fim) {
                $q->whereBetween('month_reference', [$inicio->toDateString(), $fim->toDateString()])
                    ->orWhere(function ($qq) use ($inicio, $fim) {
                        $qq->whereNull('month_reference')
                            ->whereBetween('created_at', [$inicio, $fim]);
                    });
            });
        }

        $query->chunkById(200, function ($surveys) use (&$stats, $dryRun) {
            foreach ($surveys as $survey) {
                $resultado = $this->materializar($survey, $dryRun);

                foreach (['criados', 'pulos_ja_existentes', 'pulos_sem_responsavel', 'promovidos_definitivo', 'removidos'] as $chave) {
                    $stats[$chave] += $resultado[$chave];
                }

                $stats['detalhe'] = array_merge($stats['detalhe'], $resultado['detalhe']);
            }
        });

        return $stats;
    }

    /**
     * Notas imputadas (nota 1) de UM usuário na janela [$de, $ate] — leitura
     * usada por TODOS os consumidores das waves seguintes (Desempenho,
     * Carteira, dashboards). Nenhum call-site pode reimplementar esta regra.
     *
     * Dedupe por (survey_id, role): a mesma pessoa responsável por 2
     * serviços cobertos do MESMO survey pesa 1× só (mesma régua de
     * `DesempenhoScoreService::notasPorAtribuicao`).
     */
    public function notasDoUsuario(User $user, Carbon $de, Carbon $ate, ?Collection $invalidadas = null): Collection
    {
        $query = NpsImputedAssignment::vigentes()
            ->where('user_id', $user->id)
            ->whereBetween('competencia_nps', [
                $de->copy()->startOfMonth()->toDateString(),
                $ate->copy()->endOfMonth()->toDateString(),
            ]);

        if ($invalidadas && $invalidadas->isNotEmpty()) {
            $query->whereNotIn('company_id', $invalidadas->all());
        }

        return $query->get()
            ->unique(fn (NpsImputedAssignment $linha) => $linha->survey_id . '|' . $linha->role)
            ->values()
            ->map(fn (NpsImputedAssignment $linha) => (object) [
                'survey_id'       => $linha->survey_id,
                'company_id'      => $linha->company_id,
                'role'            => $linha->role,
                'service_setor'   => $linha->service_setor,
                'competencia_nps' => $linha->competencia_nps,
                'nota'            => (float) $linha->nota,
            ]);
    }

    /**
     * Notas imputadas (nota 1) de uma DIMENSÃO, para um conjunto de empresas,
     * na janela [$de, $ate]. Dedupe por survey_id (1 nota por survey por
     * dimensão). `$templateIds` filtra opcionalmente pelo(s) modelo(s) do
     * survey — usado pelos widgets que só olham o modelo principal.
     */
    public function notasDaEmpresa(
        Collection $companyIds,
        string $dimensao,
        Carbon $de,
        Carbon $ate,
        ?Collection $invalidadas = null,
        ?Collection $templateIds = null,
    ): Collection {
        $query = NpsImputedAssignment::vigentes()
            ->whereIn('company_id', $companyIds->all())
            ->where('dimensao', $dimensao)
            ->whereBetween('competencia_nps', [
                $de->copy()->startOfMonth()->toDateString(),
                $ate->copy()->endOfMonth()->toDateString(),
            ]);

        if ($invalidadas && $invalidadas->isNotEmpty()) {
            $query->whereNotIn('company_id', $invalidadas->all());
        }

        if ($templateIds && $templateIds->isNotEmpty()) {
            $query->whereHas('survey', fn ($q) => $q->whereIn('template_id', $templateIds->all()));
        }

        return $query->get()
            ->unique('survey_id')
            ->values()
            ->map(fn (NpsImputedAssignment $linha) => (object) [
                'survey_id'       => $linha->survey_id,
                'company_id'      => $linha->company_id,
                'role'            => $linha->role,
                'service_setor'   => $linha->service_setor,
                'competencia_nps' => $linha->competencia_nps,
                'nota'            => (float) $linha->nota,
            ]);
    }

    /**
     * IDs de survey com linha DEFINITIVO na janela — permite a um consumidor
     * honrar D2 (a nota definitiva ganha da resposta tardia) sem recalcular
     * nada.
     */
    public function surveyIdsComNotaDefinitiva(Carbon $de, Carbon $ate): Collection
    {
        return NpsImputedAssignment::where('status', NpsImputedAssignment::STATUS_DEFINITIVO)
            ->whereBetween('competencia_nps', [
                $de->copy()->startOfMonth()->toDateString(),
                $ate->copy()->endOfMonth()->toDateString(),
            ])
            ->pluck('survey_id')
            ->unique()
            ->values();
    }

    /**
     * Cria (ou não, em dry-run) UMA linha imputada, com guard de idempotência
     * em PHP além do unique do banco (Pitfall — MySQL trata NULLs como
     * distintos entre si no unique composto).
     */
    private function materializarLinha(
        NpsSurvey $survey,
        Company $company,
        string $dimensao,
        ?Servico $servico,
        ?string $role,
        ?User $user,
        Carbon $competencia,
        bool $competenciaFechada,
        bool $dryRun,
        array &$stats,
    ): void {
        $servicoId = $servico?->id;
        $userId    = $user?->id;

        $existe = NpsImputedAssignment::where('survey_id', $survey->id)
            ->where('dimensao', $dimensao)
            ->where('role', $role)
            ->where('user_id', $userId)
            ->where('servico_id', $servicoId)
            ->exists();

        if ($existe) {
            $stats['pulos_ja_existentes']++;

            return;
        }

        $stats['detalhe'][] = [
            'survey_id'  => $survey->id,
            'company_id' => $company->id,
            'servico_id' => $servicoId,
            'dimensao'   => $dimensao,
            'role'       => $role,
            'user_id'    => $userId,
        ];
        $stats['criados']++;

        if ($dryRun) {
            return;
        }

        NpsImputedAssignment::create([
            'survey_id'       => $survey->id,
            'company_id'      => $company->id,
            'servico_id'      => $servicoId,
            'service_setor'   => $servico?->setor,
            'dimensao'        => $dimensao,
            'role'            => $role,
            'user_id'         => $userId,
            'competencia_nps' => $competencia->toDateString(),
            'nota'            => 1.00,
            'status'          => $competenciaFechada ? NpsImputedAssignment::STATUS_DEFINITIVO : NpsImputedAssignment::STATUS_PROVISORIO,
            'locked_at'       => $competenciaFechada ? now() : null,
        ]);
    }

    /**
     * @return array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}
     */
    private function statsVazio(): array
    {
        return [
            'criados'               => 0,
            'pulos_ja_existentes'   => 0,
            'pulos_sem_responsavel' => 0,
            'promovidos_definitivo' => 0,
            'removidos'             => 0,
            'detalhe'               => [],
        ];
    }
}
