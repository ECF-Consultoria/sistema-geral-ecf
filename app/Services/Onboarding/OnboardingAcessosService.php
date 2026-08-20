<?php

namespace App\Services\Onboarding;

use App\Models\Company;

/**
 * Os dois dados que a ECF configura por EMPRESA e o cliente consome no portal:
 * o e-mail que ele precisa convidar e o link do App ECF.
 *
 * ### Não existe padrão global — nem para um, nem para o outro
 * O onboarding de Polos guarda o link do App ECF como global ("serve todo
 * mundo"), e esta feature nasceu copiando aquele desenho. O negócio corrigiu:
 * aqui os dois são de cada empresa. O e-mail porque cada cliente concede acesso
 * a um endereço criado para ele; o link pelo mesmo princípio.
 *
 * A consequência que importa: um valor em branco significa **não configurado**,
 * e não "usa o padrão". O portal avisa em vez de mostrar campo vazio, porque
 * campo vazio pareceria instrução incompleta e o cliente ficaria esperando sem
 * saber o quê.
 *
 * ### Existe para as três telas lerem a mesma coisa
 * O portal do cliente e o detalhe de `/onboarding/{id}` mostram este mesmo par.
 * Duas leituras próprias divergiriam na primeira vez que alguém mexesse em uma
 * delas — que é exatamente a história que o `OnboardingSituacaoService` já
 * conta neste módulo.
 */
class OnboardingAcessosService
{
    /**
     * O que o CLIENTE desta empresa vê. `null` em qualquer um dos dois
     * significa "a ECF ainda não configurou", nunca "usa outro valor".
     *
     * @return array{app_ecf_link: ?string, email_colaborador: ?string}
     */
    public function paraEmpresa(Company $company): array
    {
        return [
            'app_ecf_link'      => $this->limpar($company->app_ecf_link),
            'email_colaborador' => $this->limpar($company->email_colaborador),
        ];
    }

    public function salvarDaEmpresa(Company $company, ?string $appEcfLink, ?string $emailColaborador): void
    {
        // Campo apagado grava `null`, nunca string vazia: `""` não é null e
        // faria o portal renderizar um link em branco em vez do aviso de "ainda
        // não configurado" — o cliente veria um botão que não leva a lugar
        // nenhum.
        $company->forceFill([
            'app_ecf_link'      => $this->limpar($appEcfLink),
            'email_colaborador' => $this->limpar($emailColaborador),
        ])->save();
    }

    /** String vazia e espaço em branco viram `null`. */
    private function limpar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
