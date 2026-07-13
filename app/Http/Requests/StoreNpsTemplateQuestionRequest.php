<?php

namespace App\Http\Requests;

use App\Models\NpsTemplateQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest de criação de NpsTemplateQuestion — Phase 70 Plan 70-02 v15.0.
 *
 * Camada dupla de defesa: authorize() confirma que o usuário é admin mesmo que
 * a rota já esteja protegida por `role:admin` no grupo de `routes/web.php`
 * (belt-and-suspenders — se alguém errar o middleware group no futuro, o
 * FormRequest ainda barra).
 *
 * Campos deliberadamente FORA do rules():
 *  - `template_id`: vem do route parameter `{template}` (route model binding);
 *  - `ordem`: o controller calcula MAX(ordem)+1 na hora da criação para inserir
 *    a pergunta nova no final da lista — admin reordena depois via `mover`.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-02-PLAN.md (Task T1)
 *  - .planning/research/v15-nps-templates-schema.md §5 (tipo = escala|opcoes)
 *  - app/Models/NpsTemplateQuestion.php (constants TIPOS + DIMENSOES)
 */
class StoreNpsTemplateQuestionRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras de validação. O default de `obrigatoria` (true) é aplicado no
     * controller — aqui só validamos o TIPO do valor quando presente.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'texto'       => 'required|string|min:3|max:500',
            'tipo'        => ['required', Rule::in(NpsTemplateQuestion::TIPOS)],
            'dimensao'    => ['required', Rule::in(NpsTemplateQuestion::DIMENSOES)],
            'obrigatoria' => 'boolean',
        ];
    }

    /**
     * Mensagens em pt-BR para o form admin exibir no toast/flash sem tradução
     * genérica do Laravel ("The texto field is required").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'texto.required'    => 'O texto da pergunta é obrigatório.',
            'texto.min'         => 'O texto precisa ter pelo menos 3 caracteres.',
            'texto.max'         => 'O texto pode ter no máximo 500 caracteres.',
            'tipo.required'     => 'O tipo da pergunta é obrigatório.',
            'tipo.in'           => 'Tipo inválido — escolha entre escala, opcoes ou texto_livre.',
            'dimensao.required' => 'A dimensão da pergunta é obrigatória.',
            'dimensao.in'       => 'Dimensão inválida — escolha entre estrategista, analista, empresa ou geral.',
        ];
    }
}
