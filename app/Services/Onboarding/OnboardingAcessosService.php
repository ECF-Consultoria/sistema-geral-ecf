<?php

namespace App\Services\Onboarding;

use App\Models\Company;
use App\Models\Configuracao;

/**
 * Os dois dados que a ECF configura e o CLIENTE consome no portal: o e-mail
 * que ele precisa convidar e o link do App ECF.
 *
 * ### Global com override por empresa, e o `null` quer dizer "usa o global"
 * O precedente é o onboarding de Polos, onde o link do App ECF é global de
 * propósito ("configurado nos Padrões Globais — serve todo mundo") e o e-mail
 * de colaborador é por empresa. O negócio pediu os DOIS nos dois escopos, então
 * a régua é a mesma para ambos: valor da empresa se preenchido, senão o padrão.
 *
 * `null` na empresa significa "siga o global", nunca "sem valor". É por isso que
 * o padrão não é copiado para dentro de cada linha no momento do cadastro: no
 * dia em que o endereço mudar, a cópia que ficasse para trás mandaria um
 * cliente para um link morto, e ninguém notaria — o portal continuaria
 * mostrando um link, só que o errado.
 *
 * ### Existe para as três telas lerem a mesma coisa
 * O portal do cliente, o cockpit de `/companies` e o detalhe de
 * `/onboarding/{id}` mostram este mesmo par. Três leituras próprias divergiriam
 * na primeira vez que alguém mexesse em uma delas — que é exatamente a história
 * que o `OnboardingSituacaoService` já conta neste módulo.
 */
class OnboardingAcessosService
{
    public const CHAVE_APP_ECF = 'onboarding_app_ecf_link';
    public const CHAVE_EMAIL   = 'onboarding_email_colaborador';

    /** Padrões que valem para toda empresa que não tenha o seu. */
    public function padroes(): array
    {
        return [
            'app_ecf_link'      => Configuracao::get(self::CHAVE_APP_ECF) ?: null,
            'email_colaborador' => Configuracao::get(self::CHAVE_EMAIL) ?: null,
        ];
    }

    public function salvarPadroes(?string $appEcfLink, ?string $emailColaborador): void
    {
        Configuracao::set(self::CHAVE_APP_ECF, $this->limpar($appEcfLink));
        Configuracao::set(self::CHAVE_EMAIL, $this->limpar($emailColaborador));
    }

    /**
     * O que o CLIENTE desta empresa vê, já resolvido.
     *
     * `origem` viaja junto porque a tela interna precisa distinguir "esta
     * empresa tem link próprio" de "está usando o padrão" — sem isso, quem
     * abre o detalhe não sabe se apagar o campo muda alguma coisa.
     *
     * @return array{
     *   app_ecf_link: ?string, email_colaborador: ?string,
     *   origem: array{app_ecf_link: string, email_colaborador: string}
     * }
     */
    public function paraEmpresa(Company $company): array
    {
        $padroes = $this->padroes();

        $link = $this->limpar($company->app_ecf_link) ?? $padroes['app_ecf_link'];
        $email = $this->limpar($company->email_colaborador) ?? $padroes['email_colaborador'];

        return [
            'app_ecf_link'      => $link,
            'email_colaborador' => $email,
            'origem'            => [
                'app_ecf_link'      => $this->origem($company->app_ecf_link, $padroes['app_ecf_link']),
                'email_colaborador' => $this->origem($company->email_colaborador, $padroes['email_colaborador']),
            ],
        ];
    }

    public function salvarDaEmpresa(Company $company, ?string $appEcfLink, ?string $emailColaborador): void
    {
        // Campo apagado volta a `null` — ou seja, volta a seguir o padrão. É o
        // caminho de VOLTA que faltaria se string vazia fosse gravada como tal:
        // "" não é null e faria a empresa exibir um link em branco para sempre.
        $company->forceFill([
            'app_ecf_link'      => $this->limpar($appEcfLink),
            'email_colaborador' => $this->limpar($emailColaborador),
        ])->save();
    }

    private function origem(?string $daEmpresa, ?string $global): string
    {
        if ($this->limpar($daEmpresa) !== null) {
            return 'empresa';
        }

        return $global !== null ? 'padrao' : 'ausente';
    }

    /** String vazia e espaço em branco viram `null` — só assim "apagar" volta ao padrão. */
    private function limpar(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }
}
