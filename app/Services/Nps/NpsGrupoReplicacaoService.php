<?php

namespace App\Services\Nps;

use App\Models\NpsGroupSurvey;
use App\Models\NpsImputedAssignment;
use App\Models\NpsResponse;
use App\Models\NpsResponseAnswer;
use App\Models\NpsSurvey;
use App\Models\NpsTemplateQuestion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * NpsGrupoReplicacaoService — o NÚCLEO arquitetural do NPS de GRUPO (Fase
 * 119.1 Plan 06): transforma 1 resposta do link de grupo em N surveys-
 * espelho REAIS, um por empresa coberta, cada um com sua PRÓPRIA
 * `NpsResponse` e suas próprias `NpsResponseAnswer`.
 *
 * POR QUE ESPELHOS REAIS (e NÃO "1 resposta, N empresas" via alguma tabela
 * de junção): as duas réguas de dedupe que já existem no codebase só dão o
 * número CERTO por construção quando cada empresa coberta tem seu PRÓPRIO
 * `survey_id`/`nps_response_id`:
 *  - área NPS (`NpsController::notasImputadasPorDimensao()`/cards):
 *    dedupe por `unique('survey_id')` — N surveys distintos = N notas.
 *  - bônus (`DesempenhoScoreService::notasPorAtribuicao()`): dedupe por
 *    `groupBy(nps_response_id, role)` — N respostas distintas = N notas.
 *
 * Qualquer modelagem que fizesse 1 `NpsResponse` valer para N empresas
 * exigiria ensinar as duas réguas (e todo consumidor futuro) a entender
 * "grupo" — exatamente o que este plano PROÍBE mexer
 * (`DesempenhoScoreService::notasPorAtribuicao()`/`notasLegado()`,
 * `NpsController::notasImputadasPorDimensao()`). Com espelhos reais, do
 * ponto de vista de qualquer um dos consumidores de agregação, uma
 * resposta de grupo em N empresas é indistinguível de N clientes distintos
 * respondendo N links distintos — que é o comportamento correto (o grupo é
 * conveniência de ENVIO, não mudança da unidade "1 empresa = 1 avaliação").
 *
 * PROIBIDO modificar `NpsSnapshotService::registrar()` — é chamado 1x por
 * empresa-espelho, exatamente como se fosse uma resposta de um cliente
 * distinto. Ver docblock daquela classe para o contrato de entrada.
 *
 * @see app/Services/Nps/NpsGrupoCoberturaService.php (Plan 05 — quem é coberto e por quê)
 * @see app/Services/Nps/NpsSnapshotService.php (NÃO MODIFICAR)
 * @see app/Http/Controllers/NpsGrupoController.php (chamador único, submitResponse())
 */
class NpsGrupoReplicacaoService
{
    public function __construct(private NpsGrupoCoberturaService $cobertura)
    {
    }

    /**
     * @param  array<int|string, mixed>  $answersValidadas  já validadas pelo controller (Rule::in/obrigatoriedade), mesmo shape de submitResponseV15
     * @param  array{
     *   response_ip_address: ?string, response_user_agent: ?string,
     *   response_duration_seconds: int, is_suspicious: bool, suspicion_reasons: ?array
     * }  $rastro  rastro técnico/suspeita — o MESMO para todos os espelhos (é a mesma resposta física do cliente)
     * @return array{espelhos:int, company_ids:int[], excluidas:array}
     */
    public function replicar(
        NpsGroupSurvey $groupSurvey,
        array $answersValidadas,
        array $rastro,
        ?string $respondentName,
        ?string $comment,
    ): array {
        return DB::transaction(function () use ($groupSurvey, $answersValidadas, $rastro, $respondentName, $comment) {
            // Recalcula a cobertura AGORA — o navegador NUNCA decide quem
            // recebe a nota (T-119.1-25). Uma empresa que ganhou link
            // individual depois da prévia e antes desta resposta sai por
            // conta própria (motivo `ja_tem_link`), sem nenhum código extra
            // aqui — a régua já vive inteira em NpsGrupoCoberturaService.
            $coberturaAgora = $this->cobertura->calcular(
                $groupSurvey->grupo,
                $groupSurvey->template,
                $groupSurvey->month_reference,
            );

            $questoesPorId = $groupSurvey->template->questions->keyBy('id');

            $companyIds = [];

            foreach ($coberturaAgora['incluidas'] as $incluida) {
                $survey = NpsSurvey::create([
                    'token'           => Str::uuid()->toString(),
                    'company_id'      => $incluida['company_id'],
                    'generated_by'    => $groupSurvey->generated_by,
                    'expires_at'      => now()->endOfMonth(),
                    'status'          => 'pending',
                    'auto_generated'  => false,
                    'template_id'     => $groupSurvey->template_id,
                    'month_reference' => $groupSurvey->month_reference,
                    // Vínculo de auditoria (Plan 05) — SÓ UI/audit. Nenhuma
                    // agregação existente filtra por ele.
                    'group_survey_id' => $groupSurvey->id,
                ]);

                $response = NpsResponse::create([
                    'survey_id'       => $survey->id,
                    'respondent_name' => $respondentName,
                    'comment'         => $comment,
                    ...$rastro,
                ]);

                foreach ($answersValidadas as $qid => $answerValue) {
                    if ($answerValue === null || $answerValue === '') {
                        continue; // pergunta opcional sem resposta
                    }

                    $question = $questoesPorId[$qid] ?? null;
                    if (!$question) {
                        continue; // defensivo — Rule::in já barrou tampering
                    }

                    if ($question->tipo === NpsTemplateQuestion::TIPO_TEXTO_LIVRE) {
                        NpsResponseAnswer::create([
                            'response_id'                => $response->id,
                            'template_question_id'       => $question->id,
                            'template_option_id'         => null,
                            'question_texto_snapshot'    => $question->texto,
                            'question_dimensao_snapshot' => $question->dimensao,
                            'option_label_snapshot'      => null,
                            'option_peso_snapshot'       => null,
                            'comentario'                 => (string) $answerValue,
                        ]);

                        continue;
                    }

                    $option = $question->options->firstWhere('id', (int) $answerValue);
                    if (!$option) {
                        continue;
                    }

                    NpsResponseAnswer::create([
                        'response_id'                => $response->id,
                        'template_question_id'       => $question->id,
                        'template_option_id'         => $option->id,
                        'question_texto_snapshot'    => $question->texto,
                        'question_dimensao_snapshot' => $question->dimensao,
                        'option_label_snapshot'      => $option->label,
                        'option_peso_snapshot'       => $option->peso,
                        'comentario'                 => null,
                    ]);
                }

                // Fase 79 v16.0 — congela o snapshot (scores + covered
                // services + assignments). 1 chamada por empresa-espelho,
                // SEM modificar o serviço — é exatamente isso que faz as
                // duas réguas de dedupe darem o número certo por construção
                // (ver docblock da classe).
                app(NpsSnapshotService::class)->registrar($response);

                // Marca o espelho como completed — pode disparar 23000 se
                // esta empresa já tiver resposta completa no mês (guard da
                // Fase 68 Plan 04), tratado pelo chamador (controller).
                $survey->update(['status' => 'completed', 'completed_at' => now()]);

                $companyIds[] = $incluida['company_id'];
            }

            $groupSurvey->update([
                'status'          => 'completed',
                'completed_at'    => now(),
                'respondent_name' => $respondentName,
                'comment'         => $comment,
            ]);

            // Piso de 1 do link pendente (2026-08-26) sai AQUI, na MESMA
            // transação que cria os espelhos — os espelhos reais assumem a
            // partir de agora. Deixar para o cron abriria uma janela em que a
            // empresa contaria o piso 1 E a nota real na mesma competência.
            // Linha DEFINITIVO nunca é tocada (D2): se a competência já tinha
            // fechado, aquele 1 está congelado e uma resposta tardia não
            // reescreve competência fechada.
            NpsImputedAssignment::where('group_survey_id', $groupSurvey->id)
                ->where('status', NpsImputedAssignment::STATUS_PROVISORIO)
                ->delete();

            return [
                'espelhos'    => count($companyIds),
                'company_ids' => $companyIds,
                'excluidas'   => $coberturaAgora['excluidas'],
            ];
        });
    }
}
