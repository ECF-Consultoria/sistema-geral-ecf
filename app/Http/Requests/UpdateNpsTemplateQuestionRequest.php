<?php

namespace App\Http\Requests;

use App\Models\NpsTemplateQuestion;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest de edição de NpsTemplateQuestion — Phase 70 Plan 70-02 v15.0.
 *
 * Todas as regras são `sometimes` para permitir PUT parcial (o form da UI pode
 * enviar apenas o subset de campos que mudou). Ausência de campo → mantém o
 * valor atual no banco.
 *
 * **Chave `tipo` deliberadamente FORA do rules()** — o tipo da pergunta é
 * IMUTÁVEL após criação (research §5 + decisão travada no plan):
 *   1. Mudar `escala` → `opcoes` deixaria as 5 options auto-geradas órfãs;
 *   2. Mudar `opcoes` → `escala` com options manuais destruiria o trabalho do
 *      admin ao substituir por 5 defaults (destrutivo silencioso);
 *   3. Admin que quiser mudar o tipo: apagar a pergunta e recriar (poucos
 *      cliques, comportamento previsível, sem perda oculta de dados).
 *
 * Laravel ignora chaves fora do rules() no `validated()`, então mesmo que o
 * payload traga `tipo=opcoes`, o valor não chega ao Model::update().
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-02-PLAN.md (Task T2)
 *  - .planning/research/v15-nps-templates-schema.md §5 (tipo escala|opcoes)
 *  - app/Models/NpsTemplateQuestion.php (fillable + constants DIMENSOES)
 */
class UpdateNpsTemplateQuestionRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras `sometimes` para PUT parcial. `tipo` NUNCA entra (imutável).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'texto'       => 'sometimes|required|string|min:3|max:500',
            'dimensao'    => ['sometimes', 'required', Rule::in(NpsTemplateQuestion::DIMENSOES)],
            'obrigatoria' => 'sometimes|boolean',
        ];
    }

    /**
     * Mensagens em pt-BR — mesmo padrão do Store para consistência do toast.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'texto.required'    => 'O texto da pergunta é obrigatório.',
            'texto.min'         => 'O texto precisa ter pelo menos 3 caracteres.',
            'texto.max'         => 'O texto pode ter no máximo 500 caracteres.',
            'dimensao.required' => 'A dimensão da pergunta é obrigatória.',
            'dimensao.in'       => 'Dimensão inválida — escolha entre estrategista, analista, empresa ou geral.',
        ];
    }
}
