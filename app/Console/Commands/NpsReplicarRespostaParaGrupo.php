<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\NpsGroupSurvey;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsScoreAssignment;
use App\Models\NpsSurvey;
use App\Services\DesempenhoScoreService;
use App\Services\Nps\NpsElegibilidadeService;
use App\Services\Nps\NpsScoreCalculator;
use App\Services\Nps\NpsSnapshotService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Replica MANUALMENTE uma resposta de NPS já dada para outras empresas do
 * mesmo grupo, produzindo o mesmo resultado que o link de GRUPO teria
 * produzido se tivesse sido usado — 2026-08-21.
 *
 * POR QUE ESTE COMANDO EXISTE: a replicação automática só acontece quando o
 * cliente responde um link de GRUPO (`NpsGrupoReplicacaoService::replicar()`).
 * Quando o link gerado foi INDIVIDUAL, a resposta vale só para a empresa que
 * respondeu — por desenho. Não há caminho de tela para corrigir isso depois, e
 * regerar o link de grupo não resolve: as empresas que já receberam link
 * individual saem da cobertura por `ja_tem_link`.
 *
 * Caso de origem: grupo `Camillo Parts`, modelo de Shopee. A
 * `GENUINEAUTOMOTIVE` respondeu por link individual em 19/08/2026 e as demais
 * empresas do grupo ficaram sem nota. Ver
 * `.planning/learnings/nps-visibilidade-e-elegibilidade.md` §6.
 *
 * O QUE ELE FAZ, por empresa alvo:
 *  1. reaproveita o survey PENDENTE do mesmo modelo na competência, se houver
 *     (não deixa link órfão pendurado); senão cria um novo;
 *  2. copia a `NpsResponse` da origem — mesmo rastro técnico, mesmo nome de
 *     respondente, mesmo comentário (é a MESMA resposta física do cliente,
 *     mesma decisão do `replicar()`);
 *  3. copia as `nps_response_answers` com os snapshots LITERAIS da origem
 *     (`option_label_snapshot`/`option_peso_snapshot`) — a nota replicada é
 *     idêntica à original por construção, e imune a edição posterior do
 *     modelo;
 *  4. chama `NpsSnapshotService::registrar()` — NÃO reimplementa nada da régua
 *     de score/atribuição, é a mesma chamada que o submit faz;
 *  5. marca o survey como `completed` e busta o cache do bônus dos usuários
 *     que ganharam atribuição.
 *
 * PROIBIDO neste comando (mesma trava do `NpsGrupoReplicacaoService`):
 * modificar `NpsSnapshotService`, `NpsScoreCalculator` ou qualquer consumidor
 * de bônus. Ele só ORQUESTRA chamadas existentes.
 *
 * GUARDS: empresa inativa, empresa sem contrato ativo em serviço coberto pelo
 * modelo e empresa que JÁ tem resposta completa do modelo na competência são
 * puladas com motivo explícito — nunca sobrescreve nota existente.
 *
 * Uso:
 *   php artisan nps:replicar-resposta-para-grupo --survey=458 --empresas=1,131,144,358 --dry-run
 *   php artisan nps:replicar-resposta-para-grupo --survey=458 --empresas=1,131,144,358
 *
 * @see app/Services/Nps/NpsGrupoReplicacaoService.php (o caminho automático)
 * @see app/Services/Nps/NpsSnapshotService.php (NÃO MODIFICAR)
 */
class NpsReplicarRespostaParaGrupo extends Command
{
    protected $signature = 'nps:replicar-resposta-para-grupo
        {--survey=          : ID do NpsSurvey de ORIGEM (precisa estar respondido)}
        {--empresas=        : IDs das empresas alvo, separados por vírgula}
        {--dry-run          : Só mostra o plano, sem gravar}
        {--force            : Pula a confirmação interativa}
        {--sem-group-survey : Não cria o link de grupo retroativo de auditoria}';

    protected $description = 'Replica uma resposta NPS já dada para outras empresas do grupo, como se tivesse sido um link de grupo';

    public function __construct(
        private NpsSnapshotService $snapshot,
        private NpsElegibilidadeService $elegibilidade,
        private NpsScoreCalculator $calculator,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $origem = $this->carregarOrigem();

        if (! $origem) {
            return self::FAILURE;
        }

        $empresaIds = collect(explode(',', (string) $this->option('empresas')))
            ->map(fn ($id) => (int) trim($id))
            ->filter()
            ->unique()
            ->values();

        if ($empresaIds->isEmpty()) {
            $this->error('Informe --empresas=1,131,144 (ids separados por vírgula).');

            return self::FAILURE;
        }

        // Competência = mês do survey de origem. `month_reference` é NULL em
        // survey manual (D-12), e aí o mês vem do `created_at` — mesma régua
        // de `surveyExistenteNaCompetencia()`.
        $competencia = ($origem->month_reference ?? $origem->created_at)->copy()->startOfMonth();
        $respostaOrigem = NpsResponse::where('survey_id', $origem->id)->with('answers')->latest('id')->first();

        $this->info('[NPS Replicar] Origem: survey #' . $origem->id
            . ' | empresa ' . $origem->company->name
            . ' | modelo "' . $origem->template->nome . '"'
            . ' | respondido em ' . $origem->completed_at?->format('d/m/Y H:i')
            . ' | competência ' . $competencia->format('m/Y'));

        [$plano, $bloqueios] = $this->montarPlano($origem, $respostaOrigem, $empresaIds);

        $this->exibirBloqueios($bloqueios);
        $this->exibirPlano($plano);

        if (empty($plano)) {
            $this->warn('[NPS Replicar] Nenhuma empresa elegível — nada a fazer.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[NPS Replicar] DRY-RUN — nada foi gravado.');

            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            'Gravar ' . count($plano) . ' resposta(s) replicada(s) na competência ' . $competencia->format('m/Y') . '?',
            false
        )) {
            $this->warn('[NPS Replicar] Cancelado pelo operador — nada foi gravado.');

            return self::SUCCESS;
        }

        $resultado = $this->aplicar($origem, $respostaOrigem, $plano, $competencia);

        $this->info('[NPS Replicar] Concluído.');
        $this->table(
            ['Métrica', 'Valor'],
            collect($resultado)->map(fn ($v, $k) => [$k, $v])->values()->all()
        );

        Log::info('[NPS Replicar] replicação manual concluída', [
            'survey_origem' => $origem->id,
            'competencia'   => $competencia->toDateString(),
            'resultado'     => $resultado,
        ]);

        return self::SUCCESS;
    }

    /**
     * Carrega e valida o survey de origem: precisa existir, ter modelo (v15) e
     * ter resposta gravada — replicar de survey pendente não faz sentido.
     */
    private function carregarOrigem(): ?NpsSurvey
    {
        $id = (int) $this->option('survey');

        if (! $id) {
            $this->error('Informe --survey=<id> do NPS já respondido que serve de origem.');

            return null;
        }

        $survey = NpsSurvey::with(['company', 'template.serviceScopes'])->find($id);

        if (! $survey) {
            $this->error("Survey #{$id} não existe.");

            return null;
        }

        if (! $survey->template_id) {
            $this->error("Survey #{$id} é legado (sem modelo) — não há snapshot para replicar.");

            return null;
        }

        if ($survey->response()->doesntExist()) {
            $this->error("Survey #{$id} não tem resposta — só se replica NPS já respondido.");

            return null;
        }

        return $survey;
    }

    /**
     * Monta o plano por empresa aplicando os MESMOS guards do caminho
     * automático, e prevê quem receberia a nota — a conferência que importa
     * antes de gravar.
     *
     * @return array{0: array<int, array>, 1: array<int, array>}
     */
    private function montarPlano(NpsSurvey $origem, NpsResponse $respostaOrigem, Collection $empresaIds): array
    {
        $plano = [];
        $bloqueios = [];

        $cobertos = $origem->template->serviceScopes;
        $competencia = ($origem->month_reference ?? $origem->created_at)->copy()->startOfMonth();

        foreach ($empresaIds as $companyId) {
            $empresa = Company::find($companyId);

            if (! $empresa) {
                $bloqueios[] = ['company_id' => $companyId, 'name' => '—', 'motivo' => 'empresa_inexistente'];

                continue;
            }

            if ($companyId === $origem->company_id) {
                $bloqueios[] = $this->bloqueio($empresa, 'e_a_propria_origem');

                continue;
            }

            if (! $empresa->active) {
                $bloqueios[] = $this->bloqueio($empresa, 'empresa_inativa');

                continue;
            }

            $ativos = $empresa->contratosServico()->active()->pluck('servico_id')->all();
            $intersecao = $cobertos->filter(fn ($s) => in_array($s->id, $ativos, true));

            if ($cobertos->isNotEmpty() && $intersecao->isEmpty()) {
                $bloqueios[] = $this->bloqueio($empresa, 'sem_servico_contratado');

                continue;
            }

            // Mesma régua do guard de duplicidade do link de grupo — nunca
            // sobrescreve nota existente, mas REAPROVEITA o link pendente.
            $existente = $this->elegibilidade->surveyExistenteNaCompetencia(
                $companyId,
                $origem->template_id,
                $competencia,
            );

            if ($existente && $existente->status === 'completed') {
                $bloqueios[] = $this->bloqueio($empresa, 'ja_respondido (survey #' . $existente->id . ')');

                continue;
            }

            $plano[] = [
                'empresa'     => $empresa,
                'survey'      => $existente,
                'acao'        => $existente ? 'reaproveita link pendente #' . $existente->id : 'cria link novo',
                'atribuicoes' => $this->preverAtribuicoes($empresa, $intersecao, $respostaOrigem),
            ];
        }

        return [$plano, $bloqueios];
    }

    /**
     * Prevê os `nps_score_assignments` que `NpsSnapshotService::registrar()`
     * vai criar, lendo a MESMA fonte de responsável
     * (`responsavelDoServicoOuConsolidado`). Serve para o operador conferir
     * ANTES quem vai receber a nota, e para expor serviço sem responsável —
     * caso em que a nota fica na empresa mas não é atribuída a ninguém.
     */
    private function preverAtribuicoes(Company $empresa, Collection $intersecao, NpsResponse $respostaOrigem): array
    {
        $linhas = [];

        foreach ($intersecao as $servico) {
            foreach (['analista' => 'consultor', 'estrategista' => 'estrategista'] as $dimensao => $role) {
                $media = $this->calculator->compute($respostaOrigem, $dimensao);

                if ($media === null) {
                    continue; // modelo não pergunta essa dimensão
                }

                $responsaveis = $empresa->responsavelDoServicoOuConsolidado($role, $servico->id);

                if ($responsaveis->isEmpty()) {
                    $linhas[] = [
                        'servico' => $servico->nome,
                        'role'    => $role,
                        'pessoa'  => 'SEM RESPONSAVEL - nota nao sera atribuida',
                        'nota'    => number_format($media, 2),
                    ];

                    continue;
                }

                foreach ($responsaveis as $user) {
                    $linhas[] = [
                        'servico' => $servico->nome,
                        'role'    => $role,
                        'pessoa'  => $user->name,
                        'nota'    => number_format($media, 2),
                    ];
                }
            }
        }

        return $linhas;
    }

    /**
     * Grava. Uma transação por empresa — se uma falhar (ex.: 23000 do guard de
     * duplicidade da Fase 68, numa corrida com alguém respondendo agora), as
     * demais seguem e a falha sai no relatório.
     */
    private function aplicar(NpsSurvey $origem, NpsResponse $respostaOrigem, array $plano, $competencia): array
    {
        $stats = ['empresas' => 0, 'assignments' => 0, 'caches_bustados' => 0, 'falhas' => 0];

        $groupSurvey = $this->option('sem-group-survey')
            ? null
            : $this->criarGroupSurveyRetroativo($origem, $respostaOrigem, $competencia);

        $scoreService = app(DesempenhoScoreService::class);

        foreach ($plano as $item) {
            $empresa = $item['empresa'];

            try {
                $response = DB::transaction(function () use ($origem, $respostaOrigem, $item, $competencia, $groupSurvey) {
                    $survey = $item['survey'];

                    if ($survey) {
                        $survey->update(['group_survey_id' => $groupSurvey?->id]);
                    } else {
                        $survey = NpsSurvey::create([
                            'token'           => Str::uuid()->toString(),
                            'company_id'      => $item['empresa']->id,
                            'generated_by'    => $origem->generated_by,
                            'expires_at'      => $competencia->copy()->endOfMonth(),
                            'status'          => 'pending',
                            'auto_generated'  => false,
                            'template_id'     => $origem->template_id,
                            'month_reference' => $origem->month_reference,
                            'group_survey_id' => $groupSurvey?->id,
                        ]);
                    }

                    $response = NpsResponse::create([
                        'survey_id'                 => $survey->id,
                        'respondent_name'           => $respostaOrigem->respondent_name,
                        'comment'                   => $respostaOrigem->comment,
                        'response_ip_address'       => $respostaOrigem->response_ip_address,
                        'response_user_agent'       => $respostaOrigem->response_user_agent,
                        'response_duration_seconds' => $respostaOrigem->response_duration_seconds,
                        'is_suspicious'             => $respostaOrigem->is_suspicious,
                        'suspicion_reasons'         => $respostaOrigem->suspicion_reasons,
                    ]);

                    // Cópia LITERAL dos snapshots — a nota replicada é igual à
                    // original por construção (ver docblock da classe).
                    foreach ($respostaOrigem->answers as $answer) {
                        NpsResponseAnswer::create([
                            'response_id'                => $response->id,
                            'template_question_id'       => $answer->template_question_id,
                            'template_option_id'         => $answer->template_option_id,
                            'question_texto_snapshot'    => $answer->question_texto_snapshot,
                            'question_dimensao_snapshot' => $answer->question_dimensao_snapshot,
                            'option_label_snapshot'      => $answer->option_label_snapshot,
                            'option_peso_snapshot'       => $answer->option_peso_snapshot,
                            'comentario'                 => $answer->comentario,
                        ]);
                    }

                    $response->load('survey.company', 'survey.template');
                    $this->snapshot->registrar($response);

                    $survey->update([
                        'status'       => 'completed',
                        'completed_at' => $origem->completed_at ?? now(),
                    ]);

                    return $response;
                });

                $stats['empresas']++;

                $atribuidos = NpsScoreAssignment::where('nps_response_id', $response->id)
                    ->pluck('user_id')
                    ->unique();

                $stats['assignments'] += $atribuidos->count();
                $stats['caches_bustados'] += $this->bustarCacheDoBonus($response, $scoreService, $competencia);

                $this->line('  OK ' . $empresa->name . ' - response #' . $response->id
                    . ' | ' . $atribuidos->count() . ' atribuicao(oes)');
            } catch (\Throwable $e) {
                $stats['falhas']++;
                $this->error('  FALHA ' . $empresa->name . ' - ' . $e->getMessage());

                Log::error('[NPS Replicar] falha ao replicar', [
                    'company_id' => $empresa->id,
                    'erro'       => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /**
     * Cria o `nps_group_surveys` retroativo que amarra os espelhos — é o
     * mecanismo de auditoria que a Fase 119.1 já desenhou para "1 resposta
     * física → N empresas" (`group_survey_id`, SÓ UI/audit, nenhuma agregação
     * filtra por ele). Nasce `completed`, então não aparece como link pendente
     * em `NpsController::linksDeGrupoDoMes()`, que lê só `status != completed`.
     *
     * Sem ele, essas respostas ficam indistinguíveis de N clientes distintos
     * tendo respondido N links — que é justamente o que dificultou o
     * diagnóstico do caso Camillo Parts.
     */
    private function criarGroupSurveyRetroativo(NpsSurvey $origem, NpsResponse $respostaOrigem, $competencia): ?NpsGroupSurvey
    {
        $grupoId = $origem->company->company_group_id;

        if (! $grupoId) {
            $this->warn('[NPS Replicar] Empresa de origem não pertence a grupo — espelhos ficam sem vínculo de auditoria.');

            return null;
        }

        $grupo = NpsGroupSurvey::create([
            'token'            => Str::uuid()->toString(),
            'company_group_id' => $grupoId,
            'template_id'      => $origem->template_id,
            'generated_by'     => $origem->generated_by,
            'month_reference'  => $competencia->toDateString(),
            'status'           => 'completed',
            'expires_at'       => $competencia->copy()->endOfMonth(),
            'completed_at'     => $origem->completed_at ?? now(),
            'respondent_name'  => $respostaOrigem->respondent_name,
            'comment'          => $respostaOrigem->comment,
        ]);

        // A origem também passa a apontar para o vínculo — a resposta física é
        // dela, e sem isso o link de grupo listaria os espelhos sem a empresa
        // que de fato respondeu.
        $origem->update(['group_survey_id' => $grupo->id]);

        $this->info('[NPS Replicar] Link de grupo retroativo #' . $grupo->id . ' criado para auditoria.');

        return $grupo;
    }

    /**
     * Mesma régua de `NpsController::bustarCacheDoBonus()` (Fase 96/105):
     * competência do bônus = mês da coleta MENOS 1 (NPSWIN-03 — a competência
     * fechada M lê o NPS coletado em M+1).
     */
    private function bustarCacheDoBonus(NpsResponse $response, DesempenhoScoreService $scoreService, $competencia): int
    {
        $mesCompetencia = $competencia->copy()->subMonthNoOverflow()->startOfMonth();

        $userIds = NpsScoreAssignment::where('nps_response_id', $response->id)
            ->pluck('user_id')
            ->unique();

        foreach ($userIds as $userId) {
            Cache::forget($scoreService->cacheKey($userId, $mesCompetencia));
        }

        return $userIds->count();
    }

    private function bloqueio(Company $empresa, string $motivo): array
    {
        return ['company_id' => $empresa->id, 'name' => $empresa->name, 'motivo' => $motivo];
    }

    private function exibirBloqueios(array $bloqueios): void
    {
        if (empty($bloqueios)) {
            return;
        }

        $this->warn('[NPS Replicar] Empresas PULADAS:');
        $this->table(
            ['company_id', 'empresa', 'motivo'],
            collect($bloqueios)->map(fn ($b) => array_values($b))->all()
        );
    }

    private function exibirPlano(array $plano): void
    {
        foreach ($plano as $item) {
            $this->line('');
            $this->info('> ' . $item['empresa']->name . ' (#' . $item['empresa']->id . ') - ' . $item['acao']);

            if (empty($item['atribuicoes'])) {
                $this->warn('   nenhuma atribuicao prevista - a nota ficara so na empresa');

                continue;
            }

            $this->table(
                ['servico', 'papel', 'quem recebe a nota', 'nota'],
                collect($item['atribuicoes'])->map(fn ($l) => array_values($l))->all()
            );
        }
    }
}
