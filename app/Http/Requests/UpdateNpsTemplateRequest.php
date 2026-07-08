<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de edição de NpsTemplate — Phase 70 Plan 70-01 v15.0.
 *
 * Todas as regras são `sometimes` para permitir PUT parcial (o form da UI
 * pode enviar apenas o subset de campos que mudou). Ausência de campo →
 * mantém o valor atual no banco.
 *
 * **Chave `is_default` deliberadamente FORA do rules()** — a UI admin nunca
 * pode virar um template em default (invariante do seed NPS Padrão). Laravel
 * ignora chaves fora do rules() no `validated()`, então mesmo que o payload
 * traga `is_default=true`, o valor não chega ao Model::update().
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-01-PLAN.md (Task T3)
 *  - app/Models/NpsTemplate.php (fillable + cast bool nas flags)
 */
class UpdateNpsTemplateRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras `sometimes` para PUT parcial. `active` entra aqui (não estava no
     * Store porque criação sempre nasce ativa); `is_default` NUNCA entra.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                    => 'sometimes|required|string|min:2|max:120',
            'descricao'               => 'sometimes|nullable|string|max:500',
            'active'                  => 'sometimes|boolean',
            'priority'                => 'sometimes|integer|min:0|max:1000',
            'envio_automatico_mensal' => 'sometimes|boolean',
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
            'nome.required' => 'O nome do template é obrigatório.',
            'nome.min'      => 'O nome do template precisa ter pelo menos 2 caracteres.',
            'nome.max'      => 'O nome pode ter no máximo 120 caracteres.',
            'descricao.max' => 'A descrição pode ter no máximo 500 caracteres.',
            'priority.min'  => 'A prioridade não pode ser negativa.',
            'priority.max'  => 'A prioridade máxima é 1000.',
        ];
    }
}
