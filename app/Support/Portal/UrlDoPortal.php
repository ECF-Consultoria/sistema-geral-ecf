<?php

namespace App\Support\Portal;

/**
 * A URL do Portal do Cliente — sempre no domínio DELE.
 *
 * ### O problema que isto resolve
 * `route('portal.inicio', $token)` monta a URL com o host da requisição. Quem
 * gera esse link é sempre alguém logado no sistema interno, então o host é o do
 * admin — e o link entregue ao cliente virava
 * `admin.ecfconsultoria.com.br/portal-cliente/…`.
 *
 * Medido em produção em 25/08/2026, com `PORTAL_CLIENTE_DOMINIO` já
 * configurado e o isolamento de domínio no ar. O efeito é que o cliente nunca
 * chegava ao domínio dele: o `RestringeDominioDoPortal` protege o endereço do
 * cliente, e o cliente estava no do admin, onde toda rota existe.
 *
 * ### Por que um helper e não `config('app.url')` em cada lugar
 * Eram quatro geradores (tela do onboarding, mensagem de link gerado,
 * `portal_url` do PPA, retorno do OAuth do Mercado Livre) e nenhum deles sabia
 * que essa distinção existia. Um quinto vai nascer, e vai nascer errado se a
 * regra estiver espalhada.
 *
 * ### Sem domínio configurado, nada muda
 * Ambiente local e qualquer instalação de endereço único seguem com a URL que
 * o `route()` produz. A troca de host é um ajuste, não um caminho paralelo.
 */
final class UrlDoPortal
{
    /**
     * @param  string  $rota    nome de rota do portal (`portal.inicio`, `portal.ppa`…)
     * @param  mixed   $params  o que a rota pede — normalmente o token
     */
    public static function para(string $rota, mixed $params = []): string
    {
        return self::noDominioDoCliente(route($rota, $params));
    }

    /**
     * Troca o host de uma URL já montada pelo domínio do cliente.
     *
     * Fica público porque há um caso que não passa por {@see para()}: o
     * middleware que redireciona quem chegou pelo host errado já tem a URL
     * pronta, com query string e tudo.
     */
    public static function noDominioDoCliente(string $url): string
    {
        $dominio = config('portal.dominio_cliente');

        if (! $dominio) {
            return $url;
        }

        // Só o host. Esquema, caminho e query string ficam como estavam — a
        // troca é de endereço, não de rota.
        return preg_replace('#^(https?://)[^/]+#', '$1'.$dominio, $url) ?? $url;
    }

    /** O host do portal está configurado e é diferente do atual? */
    public static function temDominioProprio(): bool
    {
        $dominio = config('portal.dominio_cliente');

        return $dominio && $dominio !== request()->getHost();
    }
}
