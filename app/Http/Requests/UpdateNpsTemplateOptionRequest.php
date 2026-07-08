<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de edição de NpsTemplateOption — Phase 70 Plan 70-03 v15.0.
 *
 * Todas as regras são `sometimes` para permitir PUT parcial (o form da UI pode
 * enviar apenas o subset de campos que mudou — ex: só o `peso` sem re-enviar
 * o `label`). Ausência de campo → mantém o valor atual no banco.
 *
 * **Peso 1..5 continua travado aqui** — defesa em profundidade além do UI da
 * Plan 70-05. Mesmo com `sometimes`, o valor QUANDO presente é validado
 * como 1..5 integer.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-03-PLAN.md (Task T2)
 *  - .planning/research/v15-nps-templates-schema.md §5 (peso 1..5 hardcoded)
 *  - app/Models/NpsTemplateOption.php (fillable label/peso/ordem)
 */
class UpdateNpsTemplateOptionRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras `sometimes` para PUT parcial. Peso mantém o limite 1..5.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => 'sometimes|required|string|min:1|max:200',
            'peso'  => 'sometimes|required|integer|min:1|max:5',
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
            'label.required' => 'O texto da opção é obrigatório.',
            'label.max'      => 'O texto da opção pode ter no máximo 200 caracteres.',
            'peso.required'  => 'O peso é obrigatório.',
            'peso.integer'   => 'O peso deve ser um número inteiro.',
            'peso.min'       => 'O peso deve estar entre 1 e 5.',
            'peso.max'       => 'O peso deve estar entre 1 e 5.',
        ];
    }
}
