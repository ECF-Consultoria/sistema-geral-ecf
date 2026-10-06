<?php

namespace App\Mcp\Telas;

use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passa um pedido pelo kernel HTTP do próprio Laravel "como o usuário" —
 * mesmos middlewares, mesmo controller, mesmas travas de perfil. É a base do
 * `ler_tela` (GET de tela) e do `enviar_formulario` (POST/PUT/PATCH/DELETE).
 *
 * Três cuidados que não são óbvios:
 *  - **Sessão em memória.** O pedido passa pelo StartSession do grupo `web`;
 *    com o driver padrão (`database`) cada chamada criaria uma linha em
 *    `sessions`. Durante a navegação o driver é trocado por `array`.
 *  - **Usuário no guard `web`.** A requisição do MCP entra pelo guard `api`
 *    (token); as telas leem `$request->user()` / `user('web')`. O usuário do
 *    token é posto no guard `web` só durante a navegação.
 *  - **Estado restaurado.** O kernel troca o `request` do container; no fim
 *    tudo volta ao request do MCP — senão o log de acesso gravaria o IP e a
 *    rota da tela, não os da chamada.
 *
 * **CSRF de escrita.** O formulário passa pela checagem de CSRF de verdade,
 * sem exceção no middleware: a loja `array` do SessionManager é o MESMO objeto
 * que o StartSession usa (o manager guarda o driver criado), e o `start()` só
 * gera token novo quando a sessão não tem um. Pôr o token na loja antes e
 * mandá-lo no `X-CSRF-TOKEN` é o que o navegador faz com a sessão dele.
 */
final class NavegacaoInterna
{
    /**
     * @param  array<string, mixed>  $dados  query (GET) ou corpo do formulário
     * @param  array<string, string>  $cabecalhos
     * @return array{0: Response, 1: Store} a resposta e a sessão em memória (flash, erros)
     */
    public function despachar(User $usuario, string $metodo, string $caminho, array $dados = [], array $cabecalhos = [], bool $comCsrf = false): array
    {
        $app      = app();
        $original = $app->make('request');
        $guard    = auth()->getDefaultDriver();
        $sessao   = config('session.driver');

        try {
            config(['session.driver' => 'array']);
            auth()->shouldUse('web');
            auth()->guard('web')->setUser($usuario);

            $interna = Request::create($caminho, strtoupper($metodo), $dados, [], [], [
                'REMOTE_ADDR' => $original->ip(),
                'HTTP_HOST'   => $original->getHttpHost(),
                'HTTPS'       => $original->isSecure() ? 'on' : 'off',
            ]);
            foreach ($cabecalhos as $nome => $valor) {
                $interna->headers->set($nome, $valor);
            }

            /** @var Store $loja */
            $loja = app('session')->driver();
            if ($comCsrf) {
                // Sessão limpa: flash e erros de uma navegação anterior na
                // mesma chamada não podem ser lidos como resultado desta.
                $loja->flush();
                $loja->regenerateToken();
                $interna->headers->set('X-CSRF-TOKEN', $loja->token());
            }

            return [$app->make(HttpKernel::class)->handle($interna), $loja];
        } finally {
            $app->instance('request', $original);
            Facade::clearResolvedInstance('request');
            app('url')->setRequest($original);
            config(['session.driver' => $sessao]);
            auth()->shouldUse($guard);
        }
    }
}
