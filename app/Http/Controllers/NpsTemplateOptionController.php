<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNpsTemplateOptionRequest;
use App\Http\Requests\UpdateNpsTemplateOptionRequest;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller CRUD das opções de resposta das perguntas de NpsTemplate —
 * Phase 70 Plan 70-03 v15.0.
 *
 * Expõe 4 endpoints REST triplo-nested sob `/nps/configuracao/templates/
 * {template}/perguntas/{pergunta}/opcoes/…` protegidos por `role:admin`
 * (grupo em `routes/web.php`). Todas as rotas mutativas usam `scopeBindings()`
 * — Laravel resolve `{pergunta}` scoped por template e `{opcao}` scoped por
 * pergunta, devolvendo 404 direto se algum vínculo estiver quebrado.
 *
 * **Guards internos (defesa em profundidade):**
 *   - `abort_if($pergunta->template_id !== $template->id, 404)` — se alguém
 *     remover o `->scopeBindings()` da rota por engano no futuro, o guard
 *     evita edição/exclusão cross-template.
 *   - `abort_if($opcao->question_id !== $pergunta->id, 404)` — mesma coisa
 *     para o vínculo opção↔pergunta.
 *
 * **Regras críticas travadas no plan:**
 *  1. Peso 1..5 (research §5) — travado no FormRequest (StoreNps…/UpdateNps…).
 *  2. Perguntas do tipo=escala NÃO podem ficar sem opções — se `destroy` for
 *     chamado na última opção de uma escala, retorna 422 sem apagar
 *     (invariante do NpsScoreCalculator que faz AVG(option_peso_snapshot) —
 *     escala sem opções trava o form público, cliente não consegue responder).
 *  3. Perguntas do tipo=opcoes ficam sob responsabilidade do admin — se ele
 *     apagar todas, o form público avisa via UI da Phase 71 (pattern
 *     "warn but allow" — permite estado temporário durante montagem).
 *  4. Reorder via SWAP transacional idêntico ao pattern do Plan 70-02 mas
 *     SCOPED por `question_id` (não por `template_id`) — trocar ordem com
 *     opção de outra pergunta seria bug catastrófico silencioso.
 *
 * Isolamento: ZERO mudança no `NpsController`, `NpsTemplateController` (Plan
 * 70-01) ou `NpsTemplateQuestionController` (Plan 70-02) — 3 controllers de
 * templates convivem em paralelo, cada um dono do seu nível de aninhamento.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-03-PLAN.md
 *  - .planning/research/v15-nps-templates-schema.md §5 (peso 1..5, tipo escala)
 *  - .planning/research/v15-nps-templates-schema.md §1 (option_peso_snapshot)
 *  - app/Models/NpsTemplateOption.php (fillable label/peso/ordem)
 *  - app/Models/NpsTemplateQuestion.php (TIPO_ESCALA + relation options)
 *  - app/Http/Controllers/NpsTemplateQuestionController.php (pattern SWAP + guards)
 */
class NpsTemplateOptionController extends Controller
{
    /**
     * POST /nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes —
     * cria opção nova.
     *
     * Ordem inicial = max(ordem)+1 na pergunta → opção nova entra no FINAL da
     * lista automaticamente. Admin reordena depois via `mover`.
     *
     * `question_id` é injetado via route model binding — o form NÃO pode
     * "roubar" pergunta alheia via payload (segurança básica).
     */
    public function store(
        StoreNpsTemplateOptionRequest $request,
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta
    ) {
        abort_if($pergunta->template_id !== $template->id, 404);

        $data = $request->validated();

        // Injeta a pergunta dona via route model binding (segurança — o form
        // não pode criar opção órfã ou colada em pergunta alheia via payload).
        $data['question_id'] = $pergunta->id;

        // Posiciona no final da lista atual (idêntico ao pattern Plan 70-02).
        $data['ordem'] = ($pergunta->options()->max('ordem') ?? 0) + 1;

        NpsTemplateOption::create($data);

        return back()->with('success', 'Opção adicionada.');
    }

    /**
     * PUT /nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}
     * — edita label/peso.
     *
     * Guard duplo interno é defesa em profundidade — o `scopeBindings()` da
     * rota já deveria ter devolvido 404, mas se alguém remover o
     * `->scopeBindings()` no futuro, os guards evitam edição cross-template
     * ou cross-question.
     *
     * Nota: mudança de `peso` NÃO afeta respostas já dadas — o snapshot em
     * `nps_response_answers.option_peso_snapshot` (Phase 68) congela o valor
     * vigente no submit (research §1). Peso alterado só vale para respostas
     * futuras.
     */
    public function update(
        UpdateNpsTemplateOptionRequest $request,
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta,
        NpsTemplateOption $opcao
    ) {
        abort_if($pergunta->template_id !== $template->id, 404);
        abort_if($opcao->question_id !== $pergunta->id, 404);

        $opcao->update($request->validated());

        return back()->with('success', 'Opção atualizada.');
    }

    /**
     * DELETE /nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}
     * — apaga opção.
     *
     * **Guard invariante escala:** se a pergunta é `tipo=escala` e ficaria
     * com <= 1 opção após esta exclusão (i.e., está tentando apagar a última
     * ou penúltima opção de uma escala), aborta com 422. Motivo: escala sem
     * opções renderiza um radio group vazio no form público — cliente não
     * consegue responder → survey trava. Este guard preserva o invariante
     * "escala tem pelo menos 1 opção" — o admin pode até editar labels/pesos
     * das 5 default, mas não pode zerar a lista.
     *
     * Perguntas `tipo=opcoes` NÃO caem neste guard — a UI da Phase 71 vai
     * renderizar aviso no form público (pattern "warn but allow") permitindo
     * ao admin apagar tudo e recomeçar do zero durante a montagem inicial.
     *
     * Snapshot congelado em `nps_response_answers` (Phase 68) preserva o
     * histórico das respostas já dadas com esta opção mesmo após a exclusão.
     */
    public function destroy(
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta,
        NpsTemplateOption $opcao
    ) {
        abort_if($pergunta->template_id !== $template->id, 404);
        abort_if($opcao->question_id !== $pergunta->id, 404);

        // Invariante escala: não permitir zerar a lista de opções. O count()
        // atual inclui a $opcao que está sendo apagada — se <= 1, apagar
        // deixaria a pergunta com 0 opções.
        if (
            $pergunta->tipo === NpsTemplateQuestion::TIPO_ESCALA
            && $pergunta->options()->count() <= 1
        ) {
            abort(422, 'Uma pergunta de escala precisa ter ao menos 1 opção.');
        }

        $opcao->delete();

        return back()->with('success', 'Opção removida.');
    }

    /**
     * POST /nps/configuracao/templates/{template}/perguntas/{pergunta}/opcoes/{opcao}/mover
     * — troca `ordem` com a vizinha imediata na direção informada.
     *
     * Pattern idêntico ao NpsTemplateQuestionController::mover (Plan 70-02),
     * mas SCOPED por `question_id` — as queries de vizinha filtram por
     * `question_id = $pergunta->id` para não trocar ordem com opção de outra
     * pergunta (cross-question swap seria bug catastrófico silencioso).
     *
     * Estratégia SWAP vs. "shift all rows":
     *  - O(1) updates (só 2 rows tocadas independente do tamanho da lista);
     *  - Sem race condition complexa (admin sozinho na UI — sem concorrência
     *    real);
     *  - No-op se opção já está no extremo (primeira/última da lista) —
     *    UI pode chamar sem check prévio.
     */
    public function mover(
        Request $request,
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta,
        NpsTemplateOption $opcao
    ) {
        abort_if($pergunta->template_id !== $template->id, 404);
        abort_if($opcao->question_id !== $pergunta->id, 404);

        $direcao = $request->validate([
            'direcao' => 'required|in:up,down',
        ])['direcao'];

        if ($direcao === 'up') {
            // Vizinha = maior ordem que ainda é menor que a minha (ASC desc).
            $vizinha = NpsTemplateOption::where('question_id', $pergunta->id)
                ->where('ordem', '<', $opcao->ordem)
                ->orderByDesc('ordem')
                ->orderByDesc('id')
                ->first();
        } else {
            // Vizinha = menor ordem que é maior que a minha.
            $vizinha = NpsTemplateOption::where('question_id', $pergunta->id)
                ->where('ordem', '>', $opcao->ordem)
                ->orderBy('ordem')
                ->orderBy('id')
                ->first();
        }

        // No-op se já está no extremo (primeira/última da lista).
        if (! $vizinha) {
            return back();
        }

        // Swap atômico das ordens entre as duas opções.
        DB::transaction(function () use ($opcao, $vizinha) {
            $ordemTmp = $opcao->ordem;
            $opcao->update(['ordem' => $vizinha->ordem]);
            $vizinha->update(['ordem' => $ordemTmp]);
        });

        return back();
    }
}
