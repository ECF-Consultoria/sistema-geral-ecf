<?php

namespace App\Http\Requests;

use App\Models\TemplatePasso;
use App\Services\Onboarding\OnboardingResolverFactory;
use App\Services\Onboarding\OnboardingTemplateVersionService;
use Closure;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * FormRequest de publicação de nova versão do template de Onboarding (D-04,
 * SC-08/D-09/D-14, Fase 135).
 *
 * Camada dupla de defesa junto ao middleware `role:admin` do grupo de rotas —
 * molde direto de {@see \App\Http\Requests\UpdateBonusFaixaRequest::authorize()}
 * (linhas 42-45).
 *
 * `auto_fonte` valida contra {@see OnboardingResolverFactory::catalogo()}
 * (fonte única, D-09) — nunca uma constante duplicada localmente: se um
 * resolver sair do registry, o Select da Tela 2 e a validação morrem juntos.
 *
 * A guarda de ciclo (SC-08) roda em `withValidator()`/`after()`, reaproveitando
 * {@see OnboardingTemplateVersionService::detectarCiclo()} — o MESMO algoritmo
 * que o service usa ao publicar, nunca uma segunda implementação divergente.
 */
class StoreOnboardingTemplateRequest extends FormRequest
{
    /**
     * Camada dupla de defesa junto ao middleware role:admin (D-04).
     */
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() === true;
    }

    /**
     * Regras primárias — aplicadas antes de `withValidator`.
     *
     * `servico_id` não estava explícito no shape do plano, mas é exigido
     * pelo controller (`publicarNovaVersao(Servico $servico, ...)`) para
     * saber a qual serviço a versão nova pertence — sem ele o invariante de
     * "próxima versão disponível para o servico_id" não tem como ser
     * calculado (Rule 2 — funcionalidade crítica ausente do shape descrito).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $autoFontesValidas = array_column(
            app(OnboardingResolverFactory::class)->catalogo(),
            'chave'
        );

        return [
            'servico_id' => ['required', 'integer', 'exists:servicos,id'],

            'passos'                 => ['required', 'array', 'min:1'],
            'passos.*.chave'         => ['required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'passos.*.titulo'        => ['required', 'string', 'max:160'],
            'passos.*.descricao'     => ['nullable', 'string'],
            'passos.*.dono'          => ['required', Rule::in(TemplatePasso::DONOS)],
            'passos.*.setor_id'      => ['nullable', 'integer', 'exists:setores,id'],
            'passos.*.depende_de'    => ['nullable', 'array'],
            'passos.*.depende_de.*'  => ['string', $this->regraDependenciaValida()],
            'passos.*.sla_dias'      => ['nullable', 'integer', 'min:1', 'max:365'],
            'passos.*.auto_fonte'    => ['nullable', Rule::in($autoFontesValidas)],
            'passos.*.condicao'      => ['nullable', 'array'],
            'passos.*.condicao.tipo' => ['required_with:passos.*.condicao', Rule::in(TemplatePasso::CONDICOES)],
            'passos.*.obrigatorio'   => ['boolean'],
        ];
    }

    /**
     * Regra fechada por closure: cada item de `depende_de` precisa ser uma
     * `chave` presente no PRÓPRIO payload e diferente da chave do passo que
     * a declara. Não dá pra expressar isso com regras declarativas porque
     * depende de ler o conjunto inteiro de `passos`, não só o valor atual —
     * por isso mora numa closure, não em `withValidator` (é validação de
     * SHAPE de um único campo, não uma regra composta entre passos como a
     * guarda de ciclo).
     */
    private function regraDependenciaValida(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            if (! preg_match('/^passos\.(\d+)\.depende_de/', $attribute, $matches)) {
                return;
            }

            $passos = $this->input('passos', []);
            $indice = (int) $matches[1];
            $chavePropria = $passos[$indice]['chave'] ?? null;
            $chavesDoPayload = array_column($passos, 'chave');

            if (! in_array($value, $chavesDoPayload, true)) {
                $fail("A dependência \"{$value}\" não existe entre os passos deste template.");

                return;
            }

            if ($value === $chavePropria) {
                $fail('Um passo não pode depender de si mesmo.');
            }
        };
    }

    /**
     * Regras compostas: chaves duplicadas no payload + guarda de ciclo
     * (SC-08). Dois `after()` separados — um problema não deve mascarar a
     * checagem do outro.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $this->verificarChavesDuplicadas($v);
        });

        $validator->after(function (Validator $v) {
            $this->verificarCiclo($v);
        });
    }

    /**
     * Chave repetida dentro do payload — erro no campo do 2º passo com a
     * mesma chave, citando a chave repetida (SC-08 vizinho: ciclo só faz
     * sentido sobre um grafo com chaves únicas).
     */
    private function verificarChavesDuplicadas(Validator $v): void
    {
        $vistas = [];

        foreach ($this->input('passos', []) as $indice => $passo) {
            $chave = $passo['chave'] ?? null;
            if ($chave === null) {
                continue;
            }

            if (isset($vistas[$chave])) {
                $v->errors()->add(
                    "passos.{$indice}.chave",
                    "A chave \"{$chave}\" está repetida — cada passo precisa de uma chave única dentro do template."
                );
                continue;
            }

            $vistas[$chave] = true;
        }
    }

    /**
     * Guarda de ciclo (SC-08): erro no campo `depende_de` do primeiro passo
     * do caminho **e** um erro geral com o texto exato do UI-SPEC, com o
     * caminho por extenso — um "erro genérico" não ajuda o admin a achar 2
     * passos entre 13.
     */
    private function verificarCiclo(Validator $v): void
    {
        $passos = $this->input('passos', []);

        $caminho = app(OnboardingTemplateVersionService::class)->detectarCiclo($passos);

        if ($caminho === null) {
            return;
        }

        $primeiraChave = $caminho[0];
        $indicePrimeiroPasso = null;
        foreach ($passos as $indice => $passo) {
            if (($passo['chave'] ?? null) === $primeiraChave) {
                $indicePrimeiroPasso = $indice;
                break;
            }
        }

        if ($indicePrimeiroPasso !== null) {
            $v->errors()->add(
                "passos.{$indicePrimeiroPasso}.depende_de",
                'Este passo participa de um ciclo de dependência.'
            );
        }

        $caminhoTexto = implode(' → ', $caminho);
        $v->errors()->add(
            'passos',
            "Não foi possível publicar: ciclo de dependência entre {$caminhoTexto}. Ajuste as dependências e tente novamente."
        );
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
            'servico_id.required'       => 'O serviço é obrigatório.',
            'servico_id.exists'         => 'Serviço não encontrado.',
            'passos.required'           => 'O template precisa de pelo menos 1 passo.',
            'passos.min'                => 'O template precisa de pelo menos 1 passo.',
            'passos.*.chave.required'   => 'Todo passo precisa de uma chave.',
            'passos.*.chave.regex'      => 'A chave só pode ter letras minúsculas, números e underscore.',
            'passos.*.titulo.required'  => 'Todo passo precisa de um título.',
            'passos.*.dono.required'    => 'Selecione quem é o dono do passo.',
            'passos.*.dono.in'          => 'Dono inválido — escolha cliente, interno ou sistema (D-14).',
            'passos.*.setor_id.exists'  => 'Setor não encontrado.',
            'passos.*.auto_fonte.in'    => 'Fonte automática inválida — escolha uma opção do catálogo.',
            'passos.*.condicao.tipo.in' => 'Condição inválida — escolha uma opção do catálogo.',
        ];
    }
}
