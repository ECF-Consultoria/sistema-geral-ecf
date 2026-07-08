<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de criação de NpsTemplate — Phase 70 Plan 70-01 v15.0.
 *
 * Camada dupla de defesa: authorize() confirma que o usuário é admin mesmo
 * que a rota já esteja protegida por `role:admin` (belt-and-suspenders — se
 * alguém errar o middleware group no futuro, o FormRequest ainda barra).
 *
 * Campos `is_default` e `active` NÃO são aceitos aqui — o controller força
 * ambos para valores canônicos (is_default=false, active=true) na criação.
 * Só o seed "NPS Padrão" (migration 100004) pode ter is_default=true.
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-01-PLAN.md (Task T2)
 *  - app/Models/NpsTemplate.php (fillable como fonte de campos válidos)
 */
class StoreNpsTemplateRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * Regras de validação — todas obrigatórias apenas quando fizerem sentido
     * semanticamente. `descricao` é nullable (o admin pode não querer nota).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'nome'                    => 'required|string|min:2|max:120',
            'descricao'               => 'nullable|string|max:500',
            'priority'                => 'nullable|integer|min:0|max:1000',
            'envio_automatico_mensal' => 'nullable|boolean',
        ];
    }

    /**
     * Mensagens em pt-BR para o form admin exibir no toast/flash sem tradução
     * genérica do Laravel ("The name field is required").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required'  => 'O nome do template é obrigatório.',
            'nome.min'       => 'O nome do template precisa ter pelo menos 2 caracteres.',
            'nome.max'       => 'O nome pode ter no máximo 120 caracteres.',
            'descricao.max'  => 'A descrição pode ter no máximo 500 caracteres.',
            'priority.min'   => 'A prioridade não pode ser negativa.',
            'priority.max'   => 'A prioridade máxima é 1000.',
        ];
    }
}
