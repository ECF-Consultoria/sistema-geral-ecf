<?php

namespace App\Http\Requests;

use App\Models\BonusFaixa;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de edição das faixas de bônus — Phase 74 Plan 74-05 (D-13).
 *
 * Valida payload de PATCH em `/desempenho/configuracao/faixas/{faixa}`.
 * Aplicado como camada dupla de defesa junto ao middleware `role:admin` do
 * grupo de rotas (`routes/web.php`).
 *
 * Regras (spec DESEMP-12 + D-13):
 *  - `nome` string obrigatória (máx 100 chars)
 *  - `descricao` string opcional (máx 2000 chars)
 *  - `nota_min` decimal entre 0 e 5, obrigatória
 *  - `nota_max` decimal entre 0 e 5, obrigatória, >= nota_min
 *  - `ordem` inteiro >= 0
 *
 * Regras compostas (via `withValidator`):
 *  1. `nota_min < nota_max` EXCETO na faixa `maximo` (que aceita [5.00, 5.00]
 *     — invariante do seed inicial D-16).
 *  2. Sem sobreposição entre a faixa sendo editada e outras faixas `ativo=true`
 *     — algoritmo canônico de sobreposição de intervalos
 *     (`novoMin <= outraMax AND novoMax >= outraMin`).
 *
 * Mensagens em pt-BR para consistência com o resto do painel (padrão
 * `UpdateNpsTemplateRequest`).
 *
 * @see .planning/phases/74-.../74-CONTEXT.md §D-13
 * @see .planning/phases/74-.../74-SPEC.md DESEMP-12
 */
class UpdateBonusFaixaRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware `role:admin`. Retorna false
     * imediatamente para não-admin (não usa `?? false` implícito).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Regras primárias — aplicadas antes de `withValidator`.
     *
     * @return array<string, array<int, string>|string>
     */
    public function rules(): array
    {
        return [
            'nome'      => ['required', 'string', 'max:100'],
            'descricao' => ['nullable', 'string', 'max:2000'],
            'nota_min'  => ['required', 'numeric', 'between:0,5'],
            'nota_max'  => ['required', 'numeric', 'between:0,5', 'gte:nota_min'],
            'ordem'     => ['required', 'integer', 'min:0'],
        ];
    }

    /**
     * Regras compostas: min<max (exceto slug=maximo) + sobreposição.
     *
     * Executa apenas quando as regras primárias já passaram — o `after`
     * hook garante isso. Se o payload tiver `nota_min > nota_max`, o
     * `gte:nota_min` acima já barra antes de chegar aqui.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $faixaAtual = $this->route('faixa');

            // Sem faixa binding (rota mal roteada) — nada a validar aqui.
            if (! $faixaAtual instanceof BonusFaixa) {
                return;
            }

            $novoMin = (float) $this->input('nota_min');
            $novoMax = (float) $this->input('nota_max');

            // ── Regra 1: min < max, EXCETO na faixa `maximo` ─────────────────
            // A faixa `maximo` no seed inicial D-16 é `[5.00, 5.00]` — igualdade
            // é semanticamente válida SÓ para ela (representa a nota exata 5.00).
            if ($faixaAtual->slug !== 'maximo' && $novoMin >= $novoMax) {
                $v->errors()->add(
                    'nota_min',
                    'Nota mínima deve ser estritamente menor que a nota máxima nesta faixa.'
                );
                return;
            }

            // ── Regra 2: sem sobreposição com outras faixas ativas ───────────
            // Algoritmo canônico de sobreposição de intervalos fechados:
            //   [a1, a2] e [b1, b2] se sobrepõem sse (a1 <= b2 AND a2 >= b1).
            // Testa contra todas as OUTRAS faixas ATIVAS. Faixas inativas ficam
            // fora do check porque o admin pode reservá-las como rascunho.
            $outrasAtivas = BonusFaixa::query()
                ->where('id', '!=', $faixaAtual->id)
                ->where('ativo', true)
                ->get(['id', 'slug', 'nome', 'nota_min', 'nota_max']);

            foreach ($outrasAtivas as $outra) {
                $outraMin = (float) $outra->nota_min;
                $outraMax = (float) $outra->nota_max;

                if ($novoMin <= $outraMax && $novoMax >= $outraMin) {
                    $v->errors()->add(
                        'nota_min',
                        "Sobreposição com a faixa \"{$outra->nome}\" [{$outra->nota_min}, {$outra->nota_max}]."
                    );
                    // 1 sobreposição encontrada — suficiente para bloquear.
                    // Não iteramos o resto (mensagem única + evita spam).
                    return;
                }
            }
        });
    }

    /**
     * Mensagens em pt-BR das regras primárias — as compostas emitem mensagens
     * inline no `withValidator`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required'     => 'O nome da faixa é obrigatório.',
            'nome.string'       => 'O nome da faixa deve ser um texto.',
            'nome.max'          => 'O nome da faixa pode ter no máximo 100 caracteres.',
            'descricao.string'  => 'A descrição da faixa deve ser um texto.',
            'descricao.max'     => 'A descrição da faixa pode ter no máximo 2000 caracteres.',
            'nota_min.required' => 'A nota mínima é obrigatória.',
            'nota_min.numeric'  => 'A nota mínima deve ser um número decimal.',
            'nota_min.between'  => 'Nota mínima deve estar entre 0 e 5.',
            'nota_max.required' => 'A nota máxima é obrigatória.',
            'nota_max.numeric'  => 'A nota máxima deve ser um número decimal.',
            'nota_max.between'  => 'Nota máxima deve estar entre 0 e 5.',
            'nota_max.gte'      => 'Nota máxima deve ser maior ou igual à nota mínima.',
            'ordem.required'    => 'A ordem da faixa é obrigatória.',
            'ordem.integer'     => 'A ordem da faixa deve ser um número inteiro.',
            'ordem.min'         => 'A ordem da faixa não pode ser negativa.',
        ];
    }
}
