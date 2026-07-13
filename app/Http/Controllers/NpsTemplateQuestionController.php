<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNpsTemplateQuestionRequest;
use App\Http\Requests\UpdateNpsTemplateQuestionRequest;
use App\Models\NpsTemplate;
use App\Models\NpsTemplateOption;
use App\Models\NpsTemplateQuestion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Controller CRUD das perguntas de NpsTemplate — Phase 70 Plan 70-02 v15.0.
 *
 * Expõe 4 endpoints REST nested sob `/nps/configuracao/templates/{template}/
 * perguntas/…` protegidos por `role:admin` (grupo em `routes/web.php`).
 * Todas as rotas mutativas usam `scopeBindings()` — Laravel resolve
 * `{pergunta}` via `NpsTemplateQuestion::where('template_id', $template->id)`
 * automaticamente, devolvendo 404 se a pergunta não pertencer ao template.
 * O guard `abort_if($pergunta->template_id !== $template->id, 404)` interno
 * é defesa em profundidade caso o scoped binding falhe (paranoia justificada
 * para rotas destrutivas).
 *
 * **Regras críticas travadas no plan:**
 *  1. tipo='escala' → auto-gera 5 NpsTemplateOption (labels '1'..'5',
 *     pesos 1..5, ordens 1..5) na MESMA transação da criação da pergunta —
 *     invariante "escala tem exatamente 5 opções" preservado mesmo em falha
 *     parcial (research §5, açúcar sintático).
 *  2. tipo='opcoes' → pergunta nasce SEM opções (admin adiciona via Plan 70-03).
 *  3. tipo é IMUTÁVEL após criação — UpdateNpsTemplateQuestionRequest não
 *     aceita a chave; se admin quiser mudar, apaga e recria.
 *  4. Reorder via SWAP transacional idêntico ao pattern Phase 33
 *     (NpsController::moverPerguntaExtra linhas 978-1010), mas SCOPED ao
 *     template com WHERE template_id = $template->id em ambas as queries de
 *     vizinha.
 *
 * Isolamento: ZERO mudança no `NpsController` (CRUD legado de perguntas extras
 * da Phase 33 continua funcionando em paralelo) e no `NpsTemplateController`
 * do Plan 70-01.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-02-PLAN.md
 *  - .planning/research/v15-nps-templates-schema.md §5 (tipo escala auto-5)
 *  - .planning/research/v15-nps-templates-schema.md §3 (SWAP reorder zero-deps)
 *  - app/Models/NpsTemplateQuestion.php (constants TIPOS/DIMENSOES + relations)
 *  - app/Models/NpsTemplateOption.php (fillable label/peso/ordem)
 *  - app/Http/Controllers/NpsController.php linhas 978-1010 (SWAP pattern)
 */
class NpsTemplateQuestionController extends Controller
{
    /**
     * POST /nps/configuracao/templates/{template}/perguntas — cria pergunta nova.
     *
     * Fluxo transacional obrigatório: se a criação da pergunta commitar mas o
     * loop das 5 options falhar (constraint violada, disco cheio, etc.),
     * ficaríamos com uma pergunta escala SEM opções — quebra o invariante
     * "escala tem exatamente 5 opções" e o form público renderiza um radio
     * group vazio. Rollback protege contra esse estado intermediário.
     *
     * Ordem inicial = max(ordem)+1 → pergunta nova entra no FINAL da lista
     * automaticamente. Admin reordena depois via `mover`.
     */
    public function store(StoreNpsTemplateQuestionRequest $request, NpsTemplate $template)
    {
        $data = $request->validated();

        // Injeta o template dono via route model binding (segurança — o form
        // não pode "roubar" template alheio via payload).
        $data['template_id'] = $template->id;

        // Posiciona no final da lista atual (idêntico ao pattern Phase 33).
        $data['ordem'] = ($template->questions()->max('ordem') ?? 0) + 1;

        // Default true: perguntas nascem obrigatórias — admin desmarca depois
        // via update se quiser opcional.
        $data['obrigatoria'] = $data['obrigatoria'] ?? true;

        // Transaction atômica: pergunta + (se escala) 5 options nascem juntas
        // ou nenhuma nasce. Sem estado intermediário quebrado.
        $question = DB::transaction(function () use ($data) {
            $question = NpsTemplateQuestion::create($data);

            // Açúcar sintático da UI (research §5): tipo='escala' materializa
            // 5 options fixas 1..5 no backend — form público não precisa saber
            // que "escala" existe, só renderiza options normalmente.
            if ($question->tipo === NpsTemplateQuestion::TIPO_ESCALA) {
                for ($i = 1; $i <= 5; $i++) {
                    NpsTemplateOption::create([
                        'question_id' => $question->id,
                        'label'       => (string) $i,
                        'peso'        => $i,
                        'ordem'       => $i,
                    ]);
                }
            }

            return $question;
        });

        return back()->with('success', 'Pergunta criada.');
    }

    /**
     * PUT /nps/configuracao/templates/{template}/perguntas/{pergunta} — edita.
     *
     * O UpdateNpsTemplateQuestionRequest omite deliberadamente a chave `tipo`
     * (imutável — research §5). Se admin quiser mudar tipo, apaga e recria.
     *
     * Guard interno `template_id !== $template->id` é defesa em profundidade —
     * o `scopeBindings()` da rota já deveria ter devolvido 404, mas se alguém
     * remover o `->scopeBindings()` da rota por engano no futuro, o guard
     * evita edição cross-template.
     */
    public function update(
        UpdateNpsTemplateQuestionRequest $request,
        NpsTemplate $template,
        NpsTemplateQuestion $pergunta
    ) {
        abort_if($pergunta->template_id !== $template->id, 404);

        $pergunta->update($request->validated());

        return back()->with('success', 'Pergunta atualizada.');
    }

    /**
     * DELETE /nps/configuracao/templates/{template}/perguntas/{pergunta} —
     * apaga a pergunta.
     *
     * O cascade FK em `nps_template_options.question_id` (Plan 68-01) apaga
     * as opções filhas automaticamente. Snapshot congelado em
     * `nps_response_answers` (Phase 68) preserva o histórico das respostas
     * já dadas com essa pergunta, mesmo após a exclusão.
     *
     * Guard interno idem `update` — defesa em profundidade cross-template.
     */
    public function destroy(NpsTemplate $template, NpsTemplateQuestion $pergunta)
    {
        abort_if($pergunta->template_id !== $template->id, 404);

        $pergunta->delete();

        return back()->with('success', 'Pergunta removida.');
    }

    /**
     * POST /nps/configuracao/templates/{template}/perguntas/{pergunta}/duplicar —
     * clona a pergunta (texto + tipo + dimensão + obrigatoriedade) e todas as
     * suas opções (label + peso + ordem), inserindo o clone logo depois da
     * pergunta original.
     *
     * Ajuste 2026-07-13: acelera o setup de templates com várias perguntas
     * similares (só varia o enunciado ou a dimensão). Admin duplica, edita o
     * texto/dimensão do clone e pronto.
     *
     * Ordem: `original->ordem + 0.5` NÃO funciona (coluna int) — em vez disso
     * fazemos SHIFT +1 em todas as perguntas com `ordem > original->ordem` e
     * inserimos o clone em `original->ordem + 1`. Transação atômica evita
     * estado intermediário quebrado.
     */
    public function duplicar(NpsTemplate $template, NpsTemplateQuestion $pergunta)
    {
        abort_if($pergunta->template_id !== $template->id, 404);

        $pergunta->load('options');

        DB::transaction(function () use ($template, $pergunta) {
            // SHIFT +1 nas perguntas posteriores pra abrir espaço.
            NpsTemplateQuestion::where('template_id', $template->id)
                ->where('ordem', '>', $pergunta->ordem)
                ->increment('ordem');

            $novo = NpsTemplateQuestion::create([
                'template_id' => $template->id,
                'texto'       => $pergunta->texto,
                'tipo'        => $pergunta->tipo,
                'dimensao'    => $pergunta->dimensao,
                'obrigatoria' => $pergunta->obrigatoria,
                'ordem'       => $pergunta->ordem + 1,
            ]);

            // Clona todas as opções preservando label/peso/ordem — quando a
            // origem é `texto_livre` a coleção é vazia, então o foreach vira no-op.
            foreach ($pergunta->options as $opt) {
                NpsTemplateOption::create([
                    'question_id' => $novo->id,
                    'label'       => $opt->label,
                    'peso'        => $opt->peso,
                    'ordem'       => $opt->ordem,
                ]);
            }
        });

        return back()->with('success', 'Pergunta duplicada.');
    }

    /**
     * POST /nps/configuracao/templates/{template}/perguntas/{pergunta}/mover —
     * troca `ordem` com a vizinha imediata na direção informada.
     *
     * Pattern idêntico ao NpsController::moverPerguntaExtra (Phase 33 linhas
     * 978-1010), mas SCOPED ao template — as queries de vizinha filtram por
     * `template_id = $template->id` para não trocar ordem com pergunta de
     * outro template (cross-template swap seria bug catastrófico silencioso).
     *
     * Estratégia SWAP vs. "shift all rows":
     *  - O(1) updates (só 2 rows tocadas independente do tamanho da lista);
     *  - Sem race condition complexa (admin sozinho na UI — sem concorrência
     *    real);
     *  - No-op se pergunta já está no extremo (primeira/última da lista) —
     *    UI pode chamar sem check prévio.
     */
    public function mover(Request $request, NpsTemplate $template, NpsTemplateQuestion $pergunta)
    {
        abort_if($pergunta->template_id !== $template->id, 404);

        $direcao = $request->validate([
            'direcao' => 'required|in:up,down',
        ])['direcao'];

        if ($direcao === 'up') {
            // Vizinha = maior ordem que ainda é menor que a minha (ASC desc).
            $vizinha = NpsTemplateQuestion::where('template_id', $template->id)
                ->where('ordem', '<', $pergunta->ordem)
                ->orderByDesc('ordem')
                ->orderByDesc('id')
                ->first();
        } else {
            // Vizinha = menor ordem que é maior que a minha.
            $vizinha = NpsTemplateQuestion::where('template_id', $template->id)
                ->where('ordem', '>', $pergunta->ordem)
                ->orderBy('ordem')
                ->orderBy('id')
                ->first();
        }

        // No-op se já está no extremo (primeira/última da lista).
        if (! $vizinha) {
            return back();
        }

        // Swap atômico das ordens entre as duas perguntas.
        DB::transaction(function () use ($pergunta, $vizinha) {
            $ordemTmp = $pergunta->ordem;
            $pergunta->update(['ordem' => $vizinha->ordem]);
            $vizinha->update(['ordem' => $ordemTmp]);
        });

        return back();
    }
}
