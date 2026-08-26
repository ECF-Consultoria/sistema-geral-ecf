<?php

namespace App\Services\Nps;

use App\Models\Company;
use App\Models\NpsGroupSurvey;
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
 * ─── Link de GRUPO (2026-08-26) ──────────────────────────────────────────
 * Uma linha imputada pode ter como âncora um `NpsSurvey` individual OU um
 * `NpsGroupSurvey` ainda pendente — nunca os dois. O link de grupo só vira
 * `nps_surveys` quando o cliente RESPONDE
 * (`NpsGrupoReplicacaoService::replicar()`), então, antes desta mudança, as
 * empresas cobertas por um link de grupo pendente ficavam sem o piso de 1
 * que qualquer link individual já produzia no instante do disparo (medido em
 * produção em 26/08/2026: 4 links pendentes, 9 empresas sem o piso).
 *
 *  - quem entra no piso do grupo é decidido por `NpsGrupoCoberturaService`,
 *    a MESMA fonte que decide quem recebe a nota no envio — nunca uma
 *    segunda régua de "quem está no link";
 *  - grupo respondido APAGA suas provisórias: os espelhos reais assumem, e
 *    manter a linha do grupo faria a empresa contar o piso 1 E a nota real
 *    na mesma competência;
 *  - a competência vem de `month_reference`, que no link de grupo é SEMPRE
 *    preenchido (guard de duplicidade em `NpsGrupoController::generate()`) —
 *    aqui não existe o fallback `?? created_at` do individual.
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

    public function __construct(
        private NpsScoreCalculator $calculator,
        private NpsGrupoCoberturaService $cobertura,
    ) {
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
                    $survey, null, $company, $dimensao, null, null, null,
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
                        $survey, null, $company, $dimensao, $servico, $role, $user,
                        $competencia, $competenciaFechada, $dryRun, $stats,
                    );
                }
            }
        }

        return $stats;
    }

    /**
     * Materializa (ou limpa) as linhas imputadas de UM link de GRUPO pendente.
     *
     * Espelha `materializar()` passo a passo — mesma promoção provisório →
     * definitivo, mesmas dimensões aplicáveis, mesmo resolvedor de
     * responsável com fallback consolidado. As duas diferenças são a âncora
     * (`group_survey_id` no lugar de `survey_id`) e o universo de empresas,
     * que aqui vem da cobertura do link em vez da empresa única do survey.
     *
     * @return array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}
     */
    public function materializarGrupo(NpsGroupSurvey $groupSurvey, bool $dryRun = false): array
    {
        $stats = $this->statsVazio();

        // ─── Grupo respondido: os espelhos REAIS assumem ──────────────────
        // `replicar()` cria um `nps_surveys` completed por empresa coberta,
        // cada um com sua resposta e sua atribuição. Se a linha do grupo
        // sobrevivesse, a empresa contaria o piso 1 E a nota real na mesma
        // competência. Linha DEFINITIVO nunca é tocada aqui (D2).
        if ($groupSurvey->status === 'completed') {
            $provisorias = NpsImputedAssignment::where('group_survey_id', $groupSurvey->id)
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

        if (! $groupSurvey->template_id) {
            Log::info('[NPS Imputação] link de grupo sem modelo — pulado', ['group_survey_id' => $groupSurvey->id]);

            return $stats;
        }

        $template = $groupSurvey->template;
        $grupo    = $groupSurvey->grupo;

        if (! $template || ! $grupo) {
            Log::warning('[NPS Imputação] link de grupo sem modelo/grupo — pulado', ['group_survey_id' => $groupSurvey->id]);

            return $stats;
        }

        $competencia = $groupSurvey->month_reference->copy()->startOfMonth();

        // Mesma régua `gt` do individual (ver comentário lá): o link vale até
        // 23:59:59 do último dia, então `gte` congelaria o 1 com 24h de prazo
        // ainda correndo.
        $competenciaFechada = now()->startOfDay()->gt($competencia->copy()->endOfMonth()->startOfDay());

        if ($competenciaFechada) {
            $paraPromover = NpsImputedAssignment::where('group_survey_id', $groupSurvey->id)
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

        $dimensoesAplicaveis = collect(self::DIMENSOES)
            ->filter(fn ($dimensao) => $this->calculator->contarPerguntasComPeso($groupSurvey->template_id, $dimensao) > 0);

        if ($dimensoesAplicaveis->isEmpty()) {
            return $stats;
        }

        // Fonte ÚNICA de quem está coberto — a mesma que o envio usa. Empresa
        // que ganhou link individual depois da geração sai por conta própria
        // (motivo `ja_tem_link`) e passa a ter o piso pelo caminho individual,
        // sem nenhum código extra aqui.
        $cobertura = $this->cobertura->calcular($grupo, $template, $competencia);

        foreach ($cobertura['incluidas'] as $incluida) {
            $company = Company::find($incluida['company_id']);

            if (! $company) {
                continue;
            }

            // `servico_ids` da cobertura JÁ é a interseção "contratos ativos ∩
            // escopo do modelo" — a mesma que o ramo individual refaz à mão.
            $servicosAplicaveis = Servico::whereIn('id', $incluida['servico_ids'])->get();

            foreach ($dimensoesAplicaveis as $dimensao) {
                if ($dimensao === NpsTemplateQuestion::DIMENSAO_EMPRESA) {
                    // D7 — aqui é 1 linha por EMPRESA coberta, não 1 por link:
                    // o grão da nota continua sendo a empresa.
                    $this->materializarLinha(
                        null, $groupSurvey, $company, $dimensao, null, null, null,
                        $competencia, $competenciaFechada, $dryRun, $stats,
                    );

                    continue;
                }

                $role = self::DIMENSAO_ROLE[$dimensao] ?? null;
                if (! $role) {
                    continue;
                }

                foreach ($servicosAplicaveis as $servico) {
                    $responsaveis = $company->responsavelDoServicoOuConsolidado($role, $servico->id);

                    if ($responsaveis->isEmpty()) {
                        Log::warning('[NPS Imputação] responsável faltante — nota 1 não gerada', [
                            'company_id'       => $company->id,
                            'servico_id'       => $servico->id,
                            'role'             => $role,
                            'dimensao'         => $dimensao,
                            'group_survey_id'  => $groupSurvey->id,
                        ]);
                        $stats['pulos_sem_responsavel']++;

                        continue;
                    }

                    foreach ($responsaveis as $user) {
                        $this->materializarLinha(
                            null, $groupSurvey, $company, $dimensao, $servico, $role, $user,
                            $competencia, $competenciaFechada, $dryRun, $stats,
                        );
                    }
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

                $this->somarStats($stats, $resultado);
            }
        });

        // Links de GRUPO do mesmo recorte. Ficam no MESMO lote de propósito:
        // é o que faz o cron diário, o backfill e o `--dry-run` do comando
        // enxergarem o piso do grupo sem nenhuma mudança nos chamadores.
        $queryGrupo = NpsGroupSurvey::query()->whereNotNull('template_id')->orderBy('id');

        if ($mes) {
            // Sem fallback `created_at` aqui: `month_reference` do link de
            // grupo é sempre preenchido na origem.
            $queryGrupo->whereBetween('month_reference', [
                $mes->copy()->startOfMonth()->toDateString(),
                $mes->copy()->endOfMonth()->toDateString(),
            ]);
        }

        $queryGrupo->chunkById(200, function ($links) use (&$stats, $dryRun) {
            foreach ($links as $link) {
                $resultado = $this->materializarGrupo($link, $dryRun);

                $this->somarStats($stats, $resultado);
            }
        });

        return $stats;
    }

    /**
     * @param  array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}  $stats
     * @param  array{criados:int, pulos_ja_existentes:int, pulos_sem_responsavel:int, promovidos_definitivo:int, removidos:int, detalhe:array}  $resultado
     */
    private function somarStats(array &$stats, array $resultado): void
    {
        foreach (['criados', 'pulos_ja_existentes', 'pulos_sem_responsavel', 'promovidos_definitivo', 'removidos'] as $chave) {
            $stats[$chave] += $resultado[$chave];
        }

        $stats['detalhe'] = array_merge($stats['detalhe'], $resultado['detalhe']);
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
            // `chaveDeDedupe()` em vez de `survey_id` cru: linha de grupo tem
            // `survey_id` NULL e um link cobre N empresas — a chave antiga
            // colapsaria o grupo inteiro numa nota só.
            ->unique(fn (NpsImputedAssignment $linha) => $linha->chaveDeDedupe() . '|' . $linha->role)
            ->values()
            ->map(fn (NpsImputedAssignment $linha) => (object) [
                'survey_id'       => $linha->survey_id,
                'company_id'      => $linha->company_id,
                // Fase 118 (D-03) — precisa saber de qual SERVIÇO é a nota
                // para resolver "survey do serviço do vínculo". A coluna já
                // é nativa da tabela (C-03 do 118-CONTEXT.md); o map só não
                // a expunha. Aditivo — nenhum consumidor existente quebra.
                'servico_id'      => $linha->servico_id,
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
            // O modelo mora no survey OU no link de grupo — filtrar só pelo
            // survey descartaria toda linha de grupo (`survey_id` NULL faz
            // `whereHas` ser sempre falso).
            $query->where(function ($q) use ($templateIds) {
                $q->whereHas('survey', fn ($s) => $s->whereIn('template_id', $templateIds->all()))
                    ->orWhereHas('groupSurvey', fn ($s) => $s->whereIn('template_id', $templateIds->all()));
            });
        }

        return $query->get()
            ->unique(fn (NpsImputedAssignment $linha) => $linha->chaveDeDedupe())
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
            // Linha de grupo não tem survey — o consumidor usa esta lista para
            // pular surveys, e um NULL na lista não pularia nada além de sujar
            // o `whereNotIn`.
            ->filter()
            ->unique()
            ->values();
    }

    /**
     * Cria (ou não, em dry-run) UMA linha imputada, com guard de idempotência
     * em PHP além do unique do banco (Pitfall — MySQL trata NULLs como
     * distintos entre si no unique composto).
     */
    private function materializarLinha(
        ?NpsSurvey $survey,
        ?NpsGroupSurvey $groupSurvey,
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

        // Âncoras mutuamente exclusivas — exatamente uma das duas.
        $existe = NpsImputedAssignment::query()
            ->when($survey !== null, fn ($q) => $q->where('survey_id', $survey->id))
            // No grão de grupo o `company_id` é OBRIGATÓRIO no guard: um único
            // link cobre N empresas, então (grupo, dimensão, papel, pessoa)
            // sem a empresa casaria a linha de uma empresa com a de outra e
            // só a primeira do grupo seria criada.
            ->when($groupSurvey !== null, fn ($q) => $q
                ->where('group_survey_id', $groupSurvey->id)
                ->where('company_id', $company->id))
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
            'survey_id'       => $survey?->id,
            'group_survey_id' => $groupSurvey?->id,
            'company_id'      => $company->id,
            'servico_id'      => $servicoId,
            'dimensao'        => $dimensao,
            'role'            => $role,
            'user_id'         => $userId,
        ];
        $stats['criados']++;

        if ($dryRun) {
            return;
        }

        NpsImputedAssignment::create([
            'survey_id'       => $survey?->id,
            'group_survey_id' => $groupSurvey?->id,
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
