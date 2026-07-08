<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de sincronização de service scopes de um NpsTemplate —
 * Phase 70 Plan 70-04 v15.0 (REQ NPS-C-05).
 *
 * Alimenta o endpoint `PUT /nps/configuracao/templates/{template}/servicos`
 * que sincroniza (atômico via BelongsToMany::sync) o pivot
 * `nps_template_service_scopes` — decide quais serviços/tipos de empresa
 * este template atende.
 *
 * Regras:
 *  - `servicos` é array (SEM `required`) — mandar `[]` é semanticamente
 *    válido e desassocia o template de todos os serviços em uma única
 *    chamada. O único caminho pra template ainda ser "usado" após
 *    desassociação total é sendo `is_default=true` (fallback do
 *    NpsTemplateService::resolveForCompany).
 *  - Cada ID: `integer|distinct|exists:servicos,id` — barra duplicatas
 *    ANTES de chegar ao sync (que já é idempotente, mas mensagem clara
 *    ajuda a UI mostrar erro específico) e valida que o serviço existe
 *    (proteção contra IDs órfãos de payloads antigos).
 *
 * Camada dupla de defesa: authorize() confirma admin mesmo que a rota
 * já esteja protegida por `role:admin` (belt-and-suspenders — se alguém
 * errar o middleware group no futuro, o FormRequest ainda barra).
 *
 * Referências:
 *  - .planning/phases/70-ui-de-configuracao-admin/70-04-PLAN.md (Task T1)
 *  - .planning/research/v15-nps-templates-schema.md §4 (precedência)
 *  - app/Models/NpsTemplate.php (relation servicos() BelongsToMany)
 */
class SyncNpsTemplateScopesRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * `servicos` sem `required` — payload `{"servicos": []}` desassocia
     * todos os serviços do template. O sync() do BelongsToMany faz a
     * limpeza atomicamente (DELETE + INSERT em transação implícita).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'servicos'   => 'array',
            'servicos.*' => 'integer|distinct|exists:servicos,id',
        ];
    }

    /**
     * Mensagens em pt-BR para o form admin exibir no toast/flash sem
     * tradução genérica do Laravel ("The servicos.0 must be an integer").
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'servicos.array'      => 'A lista de serviços deve ser um array.',
            'servicos.*.integer'  => 'Cada serviço na seleção deve ser um ID numérico.',
            'servicos.*.distinct' => 'Serviços duplicados na seleção.',
            'servicos.*.exists'   => 'Um dos serviços selecionados não existe mais.',
        ];
    }
}
