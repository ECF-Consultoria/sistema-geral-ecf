<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de criação de NpsTemplateOption — Phase 70 Plan 70-03 v15.0.
 *
 * Camada dupla de defesa: authorize() confirma que o usuário é admin mesmo que
 * a rota já esteja protegida por `role:admin` no grupo de `routes/web.php`
 * (belt-and-suspenders — se alguém errar o middleware group no futuro, o
 * FormRequest ainda barra).
 *
 * Campos deliberadamente FORA do rules():
 *  - `question_id`: vem do route parameter `{pergunta}` (route model binding);
 *  - `ordem`: o controller calcula MAX(ordem)+1 na hora da criação para inserir
 *    a opção nova no final da lista — admin reordena depois via `mover`.
 *
 * **Regra de peso 1..5 travada aqui** (research §5): o limite superior é
 * hardcoded como 5 no schema NPS (invariante da UI Plan 70-05 + do
 * NpsScoreCalculator que faz AVG(option_peso_snapshot)). FormRequest é a
 * defesa em profundidade além do UI — garante que payload cru direto no
 * endpoint não consegue burlar o limite.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-03-PLAN.md (Task T1)
 *  - .planning/research/v15-nps-templates-schema.md §5 (peso 1..5 hardcoded)
 *  - app/Models/NpsTemplateOption.php (fillable label/peso/ordem)
 */
class StoreNpsTemplateOptionRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras de validação — peso 1..5 hardcoded (research §5).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'label' => 'required|string|min:1|max:200',
            'peso'  => 'required|integer|min:1|max:5',
        ];
    }

    /**
     * Mensagens em pt-BR para o form admin exibir no toast/flash sem tradução
     * genérica do Laravel ("The label field is required").
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
