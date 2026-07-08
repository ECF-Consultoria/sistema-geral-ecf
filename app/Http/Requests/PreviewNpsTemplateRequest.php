<?php

namespace App\Http\Requests;

use App\Models\NpsTemplateQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest do endpoint de preview live do template — Phase 70 Plan 70-04
 * v15.0 (REQ NPS-C-06).
 *
 * Alimenta `POST /nps/configuracao/templates/preview`, que recebe o draft
 * state COMPLETO do form em edição (sem persistir) e devolve a estrutura
 * normalizada que a UI usa pra renderizar o componente `<PreviewFormulario>`
 * (Plan 70-05) — mesma shape que a Phase 71 `Respond.jsx` vai renderizar
 * de verdade quando o cliente responder.
 *
 * Payload aninhado espelha o shape editado no React:
 *   {
 *     nome: string?,
 *     descricao: string?,
 *     perguntas: [
 *       {
 *         texto: string,
 *         tipo: 'escala' | 'opcoes',
 *         dimensao: 'estrategista' | 'analista' | 'empresa' | 'geral',
 *         obrigatoria: bool,
 *         options: [
 *           { label: string, peso: 1..5 },
 *           ...
 *         ]
 *       },
 *       ...
 *     ]
 *   }
 *
 * `ordem` deliberadamente NÃO validado — a UI ordena arrays; o backend
 * apenas ecoa via `$idx + 1`. Isso mantém a rota stateless (o admin pode
 * arrastar perguntas/opções sem chamar salvar) e simplifica o payload.
 *
 * `nome` e `descricao` são nullable pra permitir preview antes do admin
 * decidir o nome do template — controller substitui por `'(sem título)'`
 * apenas na resposta (nunca no banco, já que preview é puro).
 *
 * Camada dupla de defesa: authorize() confirma admin mesmo que a rota
 * já esteja protegida por `role:admin`.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-04-PLAN.md (Task T2)
 *  - app/Models/NpsTemplateQuestion.php (constants TIPOS + DIMENSOES)
 *  - app/Http/Requests/StoreNpsTemplateQuestionRequest.php (padrão base
 *    de Rule::in em tipo/dimensao)
 */
class PreviewNpsTemplateRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras aninhadas — Laravel valida cada elemento do array `perguntas.*`
     * e depois cada `perguntas.*.options.*`. Se `options` faltar (pergunta
     * do tipo `opcoes` sem opções ainda cadastradas durante edição, ou
     * `escala` que a UI ainda vai auto-gerar 5), aceita array vazio ou null.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                        => 'nullable|string|max:120',
            'descricao'                   => 'nullable|string|max:500',
            'perguntas'                   => 'required|array|min:1',
            'perguntas.*.texto'           => 'required|string|min:3|max:500',
            'perguntas.*.tipo'            => ['required', Rule::in(NpsTemplateQuestion::TIPOS)],
            'perguntas.*.dimensao'        => ['required', Rule::in(NpsTemplateQuestion::DIMENSOES)],
            'perguntas.*.obrigatoria'     => 'boolean',
            'perguntas.*.options'         => 'nullable|array',
            'perguntas.*.options.*.label' => 'required|string|min:1|max:200',
            'perguntas.*.options.*.peso'  => 'required|integer|min:1|max:5',
        ];
    }

    /**
     * Mensagens em pt-BR — evita tradução genérica do Laravel
     * ("The perguntas.0.texto field is required").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'perguntas.required'              => 'O template precisa ter pelo menos uma pergunta.',
            'perguntas.min'                   => 'O template precisa ter pelo menos uma pergunta.',
            'perguntas.*.texto.required'      => 'O texto da pergunta é obrigatório.',
            'perguntas.*.texto.min'           => 'O texto da pergunta precisa ter pelo menos 3 caracteres.',
            'perguntas.*.tipo.required'       => 'O tipo da pergunta é obrigatório.',
            'perguntas.*.tipo.in'             => 'Tipo inválido — escolha entre escala ou opcoes.',
            'perguntas.*.dimensao.required'   => 'A dimensão da pergunta é obrigatória.',
            'perguntas.*.dimensao.in'         => 'Dimensão inválida — escolha entre estrategista, analista, empresa ou geral.',
            'perguntas.*.options.*.label.required' => 'O rótulo da opção é obrigatório.',
            'perguntas.*.options.*.peso.required'  => 'O peso da opção é obrigatório.',
            'perguntas.*.options.*.peso.integer'   => 'O peso da opção deve ser um número inteiro.',
            'perguntas.*.options.*.peso.min'       => 'O peso mínimo de uma opção é 1.',
            'perguntas.*.options.*.peso.max'       => 'O peso máximo de uma opção é 5.',
        ];
    }
}
