<?php

namespace App\Http\Requests;

use App\Models\Company;
use App\Models\DesempenhoMetricaManual;
use App\Models\Servico;
use Carbon\Carbon;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
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
 * ### Não há mais recusa por competência consolidada (D-09 revogado 2026-08-31)
 * A checagem `CompanyScoreSnapshotWriter::competenciaConsolidada()` que ficava
 * em `withValidator()` foi removida a pedido do negócio: lançamento manual é
 * permitido em qualquer competência, congelada ou não. Não recolocar aqui sem
 * derrubar também o read-only da tela — guarda só no servidor devolveria erro
 * numa célula que a grade deixou digitar.
 *
 * @see App\Models\DesempenhoMetricaManual::METRICAS
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
            'fonte'          => ['required', Rule::in(DesempenhoMetricaManual::FONTES)],
            'mes_referencia' => ['required', 'regex:/^\d{4}-\d{2}$/'],
            'metrica'        => ['required', Rule::in(DesempenhoMetricaManual::METRICAS)],
            'tipo'           => ['nullable', Rule::in(DesempenhoMetricaManual::TIPOS)],
            // A FAIXA de `valor` depende de `tipo` e por isso vive em
            // `withValidator()`. Aqui só o que vale para os três: ser número.
            // `min:0` NÃO pode estar nesta linha — queda de faturamento é um
            // percentual negativo perfeitamente válido.
            'valor'          => ['nullable', 'numeric'],
            'ativo'          => ['required', 'boolean'],
        ];
    }

    /**
     * Regras compostas:
     *  1. `valor` obrigatório quando `ativo=true`.
     *  2. T-136-06 — empresa precisa estar `active=true`. A ferramenta é
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

            // Faixa por TIPO (2026-08-31). Só checa quando há número: a
            // reversão para auto (`ativo=false`) submete sem valor, e o erro
            // de obrigatoriedade acima já cobre o caso de ativo sem número.
            if ($ativo && is_numeric($valor)) {
                $numero = (float) $valor;

                match ($this->tipoLancamento()) {
                    // Ponto é nota de 0 a 5 — a mesma escala da régua. Sem o
                    // teto, um "50" digitado por engano viraria média 50 na
                    // carteira inteira do profissional.
                    DesempenhoMetricaManual::TIPO_PONTO => $numero < 0 || $numero > DesempenhoMetricaManual::PONTO_MAXIMO
                        ? $v->errors()->add('valor', 'O ponto precisa estar entre 0 e ' . (int) DesempenhoMetricaManual::PONTO_MAXIMO . '.')
                        : null,

                    // Percentual aceita NEGATIVO (queda) — é metade do sentido
                    // de existir. O teto largo só barra o dedo escorregado:
                    // variação de 100.000% não é dado, é acidente.
                    DesempenhoMetricaManual::TIPO_PERCENTUAL => abs($numero) > 100000
                        ? $v->errors()->add('valor', 'O percentual informado está fora de qualquer faixa plausível.')
                        : null,

                    // Valor cheio em R$ — mesma faixa da Fase 136.
                    default => $numero < 0 || $numero > 99999999.99
                        ? $v->errors()->add('valor', 'O valor deve ser um número entre 0 e 99.999.999,99.')
                        : null,
                };
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

            // A empresa precisa ATENDER o canal escolhido. Sem isto, o admin
            // poderia lançar Shopee numa conta que só opera Mercado Livre: a
            // linha seria gravada, nenhum profissional a computaria (a fonte
            // sai dos vínculos da carteira) e o número ficaria invisível —
            // parecendo lançado e silenciosamente inerte.
            //
            // Vale SÓ para ativação. Reverter (`ativo=false`) precisa continuar
            // possível mesmo que a empresa tenha deixado de atender o canal —
            // do contrário um vínculo encerrado prenderia a célula num valor
            // manual que ninguém mais consegue desfazer.
            $fonte = $this->input('fonte');
            if ($ativo && $companyId !== null && in_array($fonte, DesempenhoMetricaManual::FONTES, true)) {
                // O filtro por SETORES_FINANCEIROS é obrigatório: sem ele um
                // vínculo de 'publicacao' cairia no ramo default de
                // `fonteFinanceiraDoSetor()` e faria a empresa parecer atendida
                // no Mercado Livre. Mesmo recorte do controller da grade.
                $fontesDaEmpresa = DB::table('company_users')
                    ->join('servicos', 'servicos.id', '=', 'company_users.servico_id')
                    ->where('company_users.company_id', $companyId)
                    ->whereIn('servicos.setor', Servico::SETORES_FINANCEIROS)
                    ->pluck('servicos.setor')
                    ->map(fn (?string $setor) => Servico::fonteFinanceiraDoSetor($setor))
                    ->unique()
                    ->all();

                if (! in_array($fonte, $fontesDaEmpresa, true)) {
                    $v->errors()->add(
                        'fonte',
                        'Esta empresa não é atendida neste marketplace — o valor lançado aqui não entraria em nenhuma nota.'
                    );
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
            'tipo.in'                 => 'Tipo de lançamento inválido — use valor, percentual ou ponto.',
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

    /**
     * Tipo do lançamento, com `valor` como default. Ausência do campo é o
     * caminho da tela antiga e de qualquer integração que não conheça os
     * modos novos — ela precisa continuar significando "valor cheio", que é
     * o que essas chamadas sempre quiseram dizer.
     */
    public function tipoLancamento(): string
    {
        $tipo = $this->input('tipo');

        return in_array($tipo, DesempenhoMetricaManual::TIPOS, true)
            ? $tipo
            : DesempenhoMetricaManual::TIPO_VALOR;
    }
}
