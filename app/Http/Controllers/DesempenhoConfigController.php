<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateBonusFaixaRequest;
use App\Models\BonusFaixa;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;

/**
 * Controller admin da UI de Configuração do módulo Desempenho — Phase 74
 * Plan 74-05 (D-10, D-11, DESEMP-12).
 *
 * Expõe 3 endpoints protegidos por `role:admin` (grupo em `routes/web.php`)
 * para editar a régua de bônus da equipe Performance:
 *
 *   • `GET /desempenho/configuracao` — renderiza `Desempenho/Configuracao.jsx`
 *     com todas as faixas (ativas + inativas) para admin ver e reativar.
 *   • `PATCH /desempenho/configuracao/faixas/{faixa}` — edita nome/descrição/
 *     limites/ordem com validação de sobreposição e range [0, 5]
 *     (via `UpdateBonusFaixaRequest`).
 *   • `PATCH /desempenho/configuracao/faixas/{faixa}/toggle-active` — flip
 *     `ativo` preservando a row (histórico intacto).
 *
 * Route model binding em `{faixa}` resolve `App\Models\BonusFaixa` implicitamente.
 *
 * Isolamento total do fluxo NPS/MLB: sem service próprio (só CRUD simples de 1
 * tabela), sem shared helpers. Guard `role:admin` na rota + `authorize()` no
 * FormRequest = defesa em profundidade.
 *
 * @see .planning/phases/74-.../74-CONTEXT.md §D-10, D-11
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-12
 * @see app/Http/Requests/UpdateBonusFaixaRequest.php
 * @see app/Models/BonusFaixa.php (Plan 74-02)
 * @see resources/js/Pages/Desempenho/Configuracao.jsx (Plan 74-07 — página React)
 */
class DesempenhoConfigController extends Controller
{
    /**
     * GET /desempenho/configuracao — página principal.
     *
     * Retorna TODAS as faixas (ativas + inativas) para o admin poder reativar
     * uma faixa desativada anteriormente sem perder o histórico. Ordena por
     * `ordem ASC` e depois por `nota_min ASC` como tiebreak (garante estabilidade
     * visual da tabela na UI).
     *
     * Prop única `faixas` — o React em `Desempenho/Configuracao.jsx` (Plan
     * 74-07) consome direto.
     */
    public function index()
    {
        $faixas = BonusFaixa::query()
            ->orderBy('ordem')
            ->orderBy('nota_min')
            ->get();

        return Inertia::render('Desempenho/Configuracao', [
            'faixas' => $faixas,
        ]);
    }

    /**
     * PATCH /desempenho/configuracao/faixas/{faixa} — edita nome/descrição/
     * limites/ordem de uma faixa.
     *
     * `UpdateBonusFaixaRequest` cuida da validação:
     *  - range [0, 5]
     *  - `nota_min < nota_max` (exceto na faixa `maximo` que aceita [5.00, 5.00])
     *  - sem sobreposição com outras faixas ATIVAS
     *
     * Se qualquer regra falha, Laravel devolve 422 com errors — o front recebe
     * via `usePage().props.errors` (padrão Inertia). Se passa, persiste e
     * volta com flash success em pt-BR.
     */
    public function updateFaixa(UpdateBonusFaixaRequest $request, BonusFaixa $faixa)
    {
        $faixa->update($request->validated());

        return back()->with(
            'success',
            "Faixa \"{$faixa->nome}\" atualizada com sucesso."
        );
    }

    /**
     * PATCH /desempenho/configuracao/faixas/{faixa}/toggle-active — alterna
     * flag `ativo` preservando toda a row (histórico intacto — não deletamos
     * nem soft-deletamos).
     *
     * PATCH é o verbo correto para operação idempotente de alternância de
     * estado (RFC 5789).
     *
     * Nota operacional: se o admin desativar uma faixa que cobre parte da
     * escala [0, 5], a régua pode ficar com gap — o `classificarFaixa` do
     * Service (Plan 74-03) retornaria `null` para notas nesse buraco. Não
     * bloqueamos aqui (admin sabe o que faz), apenas logamos warning para
     * auditoria pós-fato via `activity_log` + Log estruturado.
     */
    public function toggleActive(BonusFaixa $faixa)
    {
        $novoEstado = ! $faixa->ativo;

        if (! $novoEstado) {
            // Desativando — avisa via log estruturado. Pós-mortem fica no
            // activity_log (LogsActivity trait dispara no update) + Log app.
            Log::warning('[Desempenho Config] Faixa desativada — cobertura pode ficar incompleta', [
                'faixa_id'   => $faixa->id,
                'faixa_slug' => $faixa->slug,
                'nota_min'   => (string) $faixa->nota_min,
                'nota_max'   => (string) $faixa->nota_max,
                'user_id'    => $this->currentUserId(),
            ]);
        }

        $faixa->update(['ativo' => $novoEstado]);

        return back()->with(
            'success',
            $novoEstado
                ? "Faixa \"{$faixa->nome}\" reativada."
                : "Faixa \"{$faixa->nome}\" desativada."
        );
    }

    /**
     * Helper interno — id do user autenticado ou null (usado só em Log).
     */
    private function currentUserId(): ?int
    {
        return request()->user()?->id;
    }
}
