<?php

namespace App\Mcp\Telas;

use App\Http\Middleware\HandleInertiaRequests;
use App\Mcp\ErroDaFerramenta;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Abre uma tela do Admin "como o usuário" e devolve o que a tela recebe.
 *
 * É uma navegação INTERNA ({@see NavegacaoInterna}): um GET com os cabeçalhos
 * do Inertia (o mesmo que o navegador manda ao trocar de página) passando pelo
 * kernel HTTP do próprio Laravel — mesmos middlewares, mesmo controller,
 * mesmas travas de perfil. Por isso o número é o da tela por construção: é a
 * tela respondendo.
 *
 * O resultado fica 2 minutos em cache por usuário + endereço: paginar uma
 * lista grande não re-renderiza a tela (nem re-dispara o aquecimento de cache
 * que algumas telas fazem) a cada página. Quando o usuário GRAVA algo pelo
 * MCP, {@see esquecerCacheDe()} troca a versão do cache dele — a tela lida em
 * seguida já mostra o que ele acabou de gravar.
 */
final class NavegadorDeTelas
{
    private const CACHE_SEGUNDOS = 120;

    private const MAX_REDIRECIONAMENTOS = 3;

    public function __construct(private CatalogoDeTelas $catalogo, private NavegacaoInterna $navegacao) {}

    /**
     * Descarta as telas em cache deste usuário (depois de uma gravação pelo
     * MCP). As chaves são hash, então em vez de apagar uma a uma o número de
     * versão entra na chave e é trocado aqui. Telas em cache de OUTROS
     * usuários seguem até 2 minutos.
     */
    public function esquecerCacheDe(User $usuario): void
    {
        $chave = 'mcp.tela.versao.'.$usuario->id;
        Cache::put($chave, (int) Cache::get($chave, 0) + 1, now()->addDay());
    }

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
        $versao = (int) Cache::get('mcp.tela.versao.'.$usuario->id, 0);
        $chave  = 'mcp.tela.'.sha1(implode('|', [$usuario->id, $versao, $caminho, http_build_query($consulta), (string) $componente, (string) $somente]));

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
        $cabecalhos = [
            'X-Inertia'         => 'true',
            'X-Requested-With'  => 'XMLHttpRequest',
            'Accept'            => $comoJson ? 'application/json' : 'text/html, application/xhtml+xml, application/json',
            'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request()),
        ];
        if ($componente && $somente) {
            $cabecalhos['X-Inertia-Partial-Component'] = $componente;
            $cabecalhos['X-Inertia-Partial-Data']      = $somente;
        }

        return $this->navegacao->despachar($usuario, 'GET', $caminho, $consulta, $cabecalhos)[0];
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
