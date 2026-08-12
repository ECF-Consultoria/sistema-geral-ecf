<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Services\Desempenho\CompanyScoreSnapshotWriter;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest de lançamento manual de métrica de desempenho — Fase 136
 * Plano 02 (T-136-01/02/04/05/06).
 *
 * Camada dupla de defesa junto ao middleware `role:admin` da rota (Plano
 * 04): `authorize()` recusa qualquer usuário não-admin antes de `rules()`
 * rodar. A ferramenta é admin-global por desenho — não há filtro por
 * carteira (T-136-06 tratado por `active=true`, não por vínculo).
 *
 * Molde: `App\Http\Requests\UpdateBonusFaixaRequest`.
 *
 * @see App\Models\DesempenhoMetricaManual::METRICAS
 * @see App\Services\Desempenho\CompanyScoreSnapshotWriter::competenciaConsolidada()
 */
class StoreMetricaManualRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware `role:admin`. `false`
     * explícito para não-admin — nunca `?? false` implícito.
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Regras primárias — aplicadas antes de `withValidator`.
     *
     * `valor` é `nullable` porque a reversão para `auto` (D-02) submete
     * `ativo=false` sem valor; a regra composta "valor obrigatório quando
     * ativo=true" vive em `withValidator()`.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'company_id'     => ['required', 'integer', 'exists:companies,id'],
            'mes_referencia' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'metrica'        => ['required', Rule::in(DesempenhoMetricaManual::METRICAS)],
            'valor'          => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'ativo'          => ['required', 'boolean'],
        ];
    }

    /**
     * Regras compostas:
     *  1. `valor` obrigatório quando `ativo=true`.
     *  2. D-09 — competência já consolidada é read-only para o lançamento
     *     manual; reaproveita o MESMO sinal de `CompanyScoreSnapshotWriter`,
     *     sem flag paralela.
     *  3. T-136-06 — empresa precisa estar `active=true`. A ferramenta é
     *     admin-global por desenho, então não há filtro por carteira; mas
     *     empresa inativa não é alvo válido de lançamento.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            if ($v->errors()->has('company_id') || $v->errors()->has('mes_referencia')) {
                // Regras primárias já falharam para os campos que as
                // checagens compostas abaixo dependem — nada a validar aqui.
                return;
            }

            $ativo = $this->boolean('ativo');
            $valor = $this->input('valor');

            if ($ativo && ($valor === null || $valor === '')) {
                $v->errors()->add('valor', 'O valor é obrigatório quando a métrica está marcada como manual.');
            }

            $mesReferencia = $this->input('mes_referencia');
            if (is_string($mesReferencia) && preg_match('/^\d{4}-\d{2}$/', $mesReferencia)) {
                $mes = $this->mesReferencia();

                if (CompanyScoreSnapshotWriter::competenciaConsolidada($mes)) {
                    $v->errors()->add(
                        'mes_referencia',
                        'Competência já consolidada: o lançamento manual só vale enquanto o mês não foi congelado.'
                    );
                }
            }

            $companyId = $this->input('company_id');
            if ($companyId !== null) {
                $empresaAtiva = Company::query()
                    ->where('id', $companyId)
                    ->where('active', true)
                    ->exists();

                if (! $empresaAtiva) {
                    $v->errors()->add('company_id', 'Empresa inativa não é um alvo válido de lançamento manual.');
                }
            }
        });
    }

    /**
     * Mensagens em pt-BR das regras primárias — as compostas emitem
     * mensagens inline no `withValidator`.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'company_id.required'     => 'A empresa é obrigatória.',
            'company_id.integer'      => 'A empresa informada é inválida.',
            'company_id.exists'       => 'Empresa não encontrada.',
            'mes_referencia.required' => 'O mês de referência é obrigatório.',
            'mes_referencia.regex'    => 'O mês de referência deve estar no formato AAAA-MM.',
            'metrica.required'        => 'A métrica é obrigatória.',
            'metrica.in'              => 'Métrica fora da whitelist permitida.',
            'valor.numeric'           => 'O valor deve ser um número decimal.',
            'valor.min'               => 'O valor não pode ser negativo.',
            'valor.max'               => 'O valor excede o teto permitido para esta coluna.',
            'ativo.required'          => 'É obrigatório informar se o lançamento está ativo.',
            'ativo.boolean'           => 'O campo ativo deve ser verdadeiro ou falso.',
        ];
    }

    /**
     * Converte `mes_referencia` (formato `YYYY-MM`) para o 1º dia do mês.
     * Nunca `createFromFormat('Y-m', ...)` sozinho — armadilha já registrada
     * no padrão de `VerificarConsolidacaoDesempenho`.
     */
    public function mesReferencia(): Carbon
    {
        return Carbon::createFromFormat('Y-m-d', $this->input('mes_referencia') . '-01')->startOfMonth();
    }
}
