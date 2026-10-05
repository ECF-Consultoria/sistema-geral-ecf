<?php

namespace App\Mcp\Telas;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mcp\ErroDaFerramenta;
use App\Models\User;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre uma tela do Admin "como o usuário" e devolve o que a tela recebe.
 *
 * É uma navegação INTERNA: monta um GET com os cabeçalhos do Inertia (o mesmo
 * que o navegador manda ao trocar de página) e passa pelo kernel HTTP do
 * próprio Laravel — mesmos middlewares, mesmo controller, mesmas travas de
 * perfil. Por isso o número é o da tela por construção: é a tela respondendo.
 *
 * Três cuidados que não são óbvios:
 *  - **Sessão em memória.** O GET interno passa pelo StartSession do grupo
 *    `web`; com o driver padrão (`database`) cada consulta criaria uma linha em
 *    `sessions`. Durante a navegação o driver é trocado por `array`.
 *  - **Usuário no guard `web`.** A requisição do MCP entra pelo guard `api`
 *    (token); as telas leem `$request->user()` / `user('web')`. O usuário do
 *    token é posto no guard `web` só durante a navegação.
 *  - **Estado restaurado.** O kernel troca o `request` do container; no fim
 *    tudo volta ao request do MCP — senão o log de acesso gravaria o IP e a
 *    rota da tela, não os da chamada.
 *
 * O resultado fica 2 minutos em cache por usuário + endereço: paginar uma
 * lista grande não re-renderiza a tela (nem re-dispara o aquecimento de cache
 * que algumas telas fazem) a cada página.
 */
final class NavegadorDeTelas
{
    private const CACHE_SEGUNDOS = 120;

    private const MAX_REDIRECIONAMENTOS = 3;

    public function __construct(private CatalogoDeTelas $catalogo) {}

    /**
     * Recarregamento parcial (`$componente` + `$somente`): é como o navegador
     * busca prop OPCIONAL/lazy, que não vem na abertura normal. O componente
     * só se conhece depois da primeira abertura — quem chama repassa.
     *
     * @param  array<string, mixed>  $consulta  query string
     * @return array{tela:string, endereco:string, tipo:string, componente:?string, dados:mixed}
     */
    public function abrir(User $usuario, string $tela, string $caminho, array $consulta = [], ?string $componente = null, ?string $somente = null): array
    {
        $chave = 'mcp.tela.'.sha1(implode('|', [$usuario->id, $caminho, http_build_query($consulta), (string) $componente, (string) $somente]));

        return Cache::remember($chave, self::CACHE_SEGUNDOS, function () use ($usuario, $tela, $caminho, $consulta, $componente, $somente) {
            for ($salto = 0; $salto <= self::MAX_REDIRECIONAMENTOS; $salto++) {
                $resposta = $this->requisitar($usuario, $caminho, $consulta, $componente, $somente);

                // Endpoint com filtro obrigatório que faltou: a validação do
                // Laravel responde "volta para a página anterior" (redirect
                // para /), porque o pedido de tela não se declara JSON. Pedindo
                // de novo como JSON vem o 422 com os campos que faltam.
                if ($resposta->isRedirection() && $this->caminhoInterno((string) $resposta->headers->get('Location')) === '/') {
                    $resposta = $this->requisitar($usuario, $caminho, $consulta, $componente, $somente, comoJson: true);
                }

                $status = $resposta->getStatusCode();

                // Tela que só redireciona (ex.: /performance leva o não-admin
                // para o próprio /performance/{user}): segue, se o destino
                // também for tela do catálogo. Nunca segue para fora.
                if ($resposta->isRedirection()) {
                    $destino = $this->caminhoInterno((string) $resposta->headers->get('Location'));
                    $proxima = $destino ? $this->catalogo->telaDoCaminho($destino) : null;
                    if (! $proxima || ! $this->catalogo->achar($usuario, $proxima)) {
                        throw new ErroDaFerramenta('A tela redirecionou para fora do que o MCP pode abrir ('.($destino ?: 'endereço externo').'). Para o seu perfil ela provavelmente não existe.');
                    }
                    [$tela, $caminho, $consulta] = [$proxima, parse_url($destino, PHP_URL_PATH), $this->query($destino)];
                    continue;
                }

                if ($status === 403 || $status === 401) {
                    throw new ErroDaFerramenta('Seu perfil não tem acesso a esta tela ('.$caminho.').'.$this->mensagem($resposta));
                }
                if ($status === 404) {
                    throw new ErroDaFerramenta('Tela não encontrada ('.$caminho.'). Confira os parâmetros (id da empresa, do usuário...).');
                }
                if ($status === 422) {
                    throw new ErroDaFerramenta('A tela recusou os filtros:'.$this->mensagem($resposta));
                }
                if ($status >= 400) {
                    throw new ErroDaFerramenta('A tela respondeu erro '.$status.' ('.$caminho.'). Tente de novo em instantes.');
                }

                return $this->interpretar($tela, $caminho, $resposta);
            }

            throw new ErroDaFerramenta('A tela redirecionou vezes demais ('.$caminho.').');
        });
    }

    private function requisitar(User $usuario, string $caminho, array $consulta, ?string $componente, ?string $somente, bool $comoJson = false): Response
    {
        $app      = app();
        $original = $app->make('request');
        $guard    = auth()->getDefaultDriver();
        $sessao   = config('session.driver');

        try {
            config(['session.driver' => 'array']);
            auth()->shouldUse('web');
            auth()->guard('web')->setUser($usuario);

            $interna = Request::create($caminho, 'GET', $consulta, [], [], [
                'REMOTE_ADDR' => $original->ip(),
                'HTTP_HOST'   => $original->getHttpHost(),
                'HTTPS'       => $original->isSecure() ? 'on' : 'off',
            ]);
            $interna->headers->set('X-Inertia', 'true');
            $interna->headers->set('X-Requested-With', 'XMLHttpRequest');
            $interna->headers->set('Accept', $comoJson ? 'application/json' : 'text/html, application/xhtml+xml, application/json');
            $interna->headers->set('X-Inertia-Version', (string) app(HandleInertiaRequests::class)->version($interna));
            if ($componente && $somente) {
                $interna->headers->set('X-Inertia-Partial-Component', $componente);
                $interna->headers->set('X-Inertia-Partial-Data', $somente);
            }

            return $app->make(HttpKernel::class)->handle($interna);
        } finally {
            $app->instance('request', $original);
            Facade::clearResolvedInstance('request');
            app('url')->setRequest($original);
            config(['session.driver' => $sessao]);
            auth()->shouldUse($guard);
        }
    }

    /** @return array{tela:string, endereco:string, tipo:string, componente:?string, dados:mixed} */
    private function interpretar(string $tela, string $caminho, Response $resposta): array
    {
        $tipo   = (string) $resposta->headers->get('Content-Type');
        $corpo  = (string) $resposta->getContent();

        if ($resposta->headers->get('X-Inertia')) {
            $pagina = json_decode($corpo, true);

            return [
                'tela'       => $tela,
                'endereco'   => $caminho,
                'tipo'       => 'tela',
                'componente' => $pagina['component'] ?? null,
                'dados'      => $pagina['props'] ?? [],
            ];
        }

        if (str_contains($tipo, 'json')) {
            return [
                'tela'       => $tela,
                'endereco'   => $caminho,
                'tipo'       => 'json',
                'componente' => null,
                'dados'      => json_decode($corpo, true),
            ];
        }

        throw new ErroDaFerramenta('Este endereço não é uma tela de dados ('.$caminho.' devolveu '.($tipo ?: 'conteúdo desconhecido').').');
    }

    private function mensagem(Response $resposta): string
    {
        $json = json_decode((string) $resposta->getContent(), true);
        if (! is_array($json)) {
            return '';
        }

        // Erro de validação: o NOME do campo é o que serve — a mensagem pode
        // vir como chave de tradução crua ("validation.required").
        if (is_array($json['errors'] ?? null) && $json['errors'] !== []) {
            return ' Campos com problema: '.collect($json['errors'])
                ->map(fn ($msgs, $campo) => $campo.' ('.implode('; ', (array) $msgs).')')
                ->take(6)
                ->implode(', ').'. Mande-os em "filtros".';
        }

        $msg = $json['message'] ?? null;

        return is_string($msg) && $msg !== '' ? ' '.$msg : '';
    }

    /** Caminho interno de um Location (ou null se for outro domínio). */
    private function caminhoInterno(string $local): ?string
    {
        if ($local === '') {
            return null;
        }
        $host = parse_url($local, PHP_URL_HOST);
        if ($host && $host !== request()->getHost()) {
            return null;
        }
        $caminho = parse_url($local, PHP_URL_PATH) ?: '/';
        $query   = parse_url($local, PHP_URL_QUERY);

        return $caminho.($query ? '?'.$query : '');
    }

    /** @return array<string, mixed> */
    private function query(string $caminho): array
    {
        parse_str((string) parse_url($caminho, PHP_URL_QUERY), $q);

        return $q;
    }
}
