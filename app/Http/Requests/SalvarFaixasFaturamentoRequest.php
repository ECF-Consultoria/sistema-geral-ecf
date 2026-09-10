<?php

namespace App\Http\Requests;

use App\Services\Fechamento\ValidadorTabelaFaixas;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * FormRequest de validação da tabela INTEIRA de faixas de faturamento —
 * Fase 137 Plano 06 (D-04, D-13).
 *
 * Usado tanto por `FechamentoController::salvarFaixasServico` quanto por
 * `salvarFaixasEmpresa` — o payload é sempre a régua completa, nunca uma
 * linha isolada (D-13: a tabela é substituída inteira, nunca editada linha
 * a linha).
 *
 * Molde: `App\Http\Requests\UpdateBonusFaixaRequest` — `authorize()` com
 * guard duplo (`role:admin` do grupo de rotas + `isAdmin()` aqui) e
 * `withValidator()` aplicando UMA regra composta por vez, parando no
 * primeiro conflito encontrado (mesma disciplina: mensagem única, sem spam
 * de erros).
 *
 * ⚠️ Quick 260910-l7k — as regras compostas SAÍRAM daqui para
 * `App\Services\Fechamento\ValidadorTabelaFaixas` (mesmas regras, mesma ordem, mesmas mensagens).
 * Estavam presas ao FormRequest, e por isso a confirmação da leitura automática dos contratos
 * gravava tabela sem validar nada. Este `withValidator()` agora só delega e mapeia os erros.
 *
 * Regras compostas, aplicadas nesta ordem (cada uma retorna cedo se falhar):
 *  (a) `ordem` não pode repetir.
 *  (b) no máximo UMA faixa pode ficar sem `limite_superior` (a faixa "sem
 *      teto"), e ela precisa ser a de MAIOR `ordem` — é o fim da régua.
 *  (b2) `valor_e_piso` só pode ser verdadeiro na faixa sem teto — marcar
 *      "piso" numa faixa que TEM teto deixaria o valor ambíguo na cobrança.
 *  (c) os `limite_superior` preenchidos precisam ser estritamente
 *      crescentes na ordem — faixa não-crescente é sobreposição.
 *
 * ⚠️ Não existe "buraco" possível neste schema: `limite_inferior` de cada
 * faixa NUNCA é um campo de input — é DERIVADO do `limite_superior` da
 * faixa anterior em `FechamentoFaixaResolver::classificar()`. Uma régua que
 * passa na regra (c) é sempre contígua por construção; a única forma de a
 * tabela ficar "furada" seria sobreposição/inversão, já coberta acima.
 *
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-CONTEXT.md §D-04, D-13
 * @see .planning/phases/137-fechamento-mensal-faturamento-por-empresa-grupo-contra-a-tab/137-UI-SPEC.md (copy exata do erro de sobreposição)
 * @see app/Services/Fechamento/FechamentoFaixaResolver.php (quem LÊ a régua validada aqui)
 */
class SalvarFaixasFaturamentoRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware `role:admin` do grupo de
     * rotas administrativo. Retorna false explicitamente para não-admin.
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
            'faixas'                  => ['required', 'array', 'min:1'],
            'faixas.*.ordem'          => ['required', 'integer', 'min:1'],
            'faixas.*.limite_superior' => ['nullable', 'numeric', 'min:0'],
            'faixas.*.valor'          => ['required', 'numeric', 'min:0'],
            'faixas.*.valor_e_piso'   => ['nullable', 'boolean'],
        ];
    }

    /**
     * Regras compostas — a régua como um todo precisa ser coerente.
     *
     * Executa sobre `$this->input('faixas')` normalizado e ordenado por
     * `ordem`, mas os erros apontam para o ÍNDICE ORIGINAL do payload (não
     * o índice pós-ordenação), para o front conseguir destacar a linha
     * certa.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $bruto = $this->input('faixas', []);

            if (! is_array($bruto) || $bruto === []) {
                // Regra primária (`required|array|min:1`) já cobriu isso.
                return;
            }

            // As regras compostas vivem em `ValidadorTabelaFaixas` desde o quick 260910-l7k —
            // aqui só o mapeamento {campo, mensagem} -> erros do validador. O serviço já para no
            // primeiro conflito e já aponta para o índice ORIGINAL do payload.
            foreach (app(ValidadorTabelaFaixas::class)->erros($bruto) as $erro) {
                $v->errors()->add($erro['campo'], $erro['mensagem']);
            }
        });
    }

    /**
     * Mensagens em pt-BR das regras primárias — as compostas emitem
     * mensagens inline no `withValidator` (não sobrescritas aqui).
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'faixas.required'                 => 'A tabela precisa de pelo menos uma faixa.',
            'faixas.array'                     => 'A tabela de faixas está em um formato inválido.',
            'faixas.min'                        => 'A tabela precisa de pelo menos uma faixa.',
            'faixas.*.ordem.required'          => 'Toda faixa precisa de uma ordem.',
            'faixas.*.ordem.integer'           => 'A ordem da faixa deve ser um número inteiro.',
            'faixas.*.ordem.min'               => 'A ordem da faixa não pode ser menor que 1.',
            'faixas.*.limite_superior.numeric' => 'O limite superior deve ser um número.',
            'faixas.*.limite_superior.min'     => 'O limite superior não pode ser negativo.',
            'faixas.*.valor.required'          => 'Toda faixa precisa de um valor.',
            'faixas.*.valor.numeric'           => 'O valor da faixa deve ser um número.',
            'faixas.*.valor.min'               => 'O valor da faixa não pode ser negativo.',
            'faixas.*.valor_e_piso.boolean'    => 'O campo "valor é piso" deve ser verdadeiro ou falso.',
        ];
    }
}
