<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da ficha da conta — a MESMA para as duas portas (link público do
 * cliente e painel interno da equipe). Um formulário só, validado num lugar só:
 * se as regras divergissem, o dado declarado pelo cliente e o declarado pela
 * equipe deixariam de ser comparáveis.
 *
 * `nullable` em tudo é proposital. As 7 perguntas incluem duas que o cliente
 * pode legitimamente não saber responder — pontuação do Full e objetivos da
 * próxima medalha são justamente as que nem o sistema consegue buscar hoje.
 * Exigir resposta travaria o onboarding numa informação que ninguém tem.
 *
 * A autorização NÃO mora aqui: a porta pública é autorizada por posse do token
 * (o controller resolve o link) e a interna pelo middleware
 * `permission:core.onboarding`. Um `authorize()` genérico aqui só poderia
 * mentir para uma das duas.
 */
class StoreOnboardingFichaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'faturamento_3_meses'       => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
            'marketplace'               => ['nullable', 'string', 'max:40'],
            'full_ativo'                => ['nullable', 'boolean'],
            'full_pontuacao'            => ['nullable', 'integer', 'min:0', 'max:65535'],
            'reputacao_verde'           => ['nullable', 'boolean'],
            'medalha_atual'             => ['nullable', 'string', 'max:60'],
            'objetivos_proxima_medalha' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'faturamento_3_meses'       => 'faturamento dos últimos 3 meses',
            'marketplace'               => 'marketplace da conta',
            'full_ativo'                => 'Full ativo',
            'full_pontuacao'            => 'pontuação do Full',
            'reputacao_verde'           => 'reputação verde',
            'medalha_atual'             => 'medalha atual',
            'objetivos_proxima_medalha' => 'objetivos para a próxima medalha',
        ];
    }
}
