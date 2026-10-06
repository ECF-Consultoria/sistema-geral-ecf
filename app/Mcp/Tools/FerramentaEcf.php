<?php

namespace App\Mcp\Tools;

use App\Mcp\ErroDaFerramenta;
use App\Models\McpAcesso;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Base das ferramentas do MCP do ECF Admin.
 *
 * Cada ferramenta espelha uma tela. Duas regras valem para as de leitura:
 *
 *  1. **Mesmo recorte da tela.** `podeUsar()` repete a régua da ROTA da tela
 *     de origem (role, permission, gate) e `consultar()` aplica o mesmo filtro
 *     de carteira que o controller aplica. O que o usuário não vê no Admin
 *     ele também não vê aqui. Ferramenta fora do perfil nem aparece na lista
 *     (`shouldRegister`), e mesmo chamada à força responde erro.
 *  2. **Nada de negócio se escreve.** Nenhuma ferramenta salva, altera status
 *     ou marca como visto; a escrita própria do MCP é a linha de log em
 *     `mcp_acessos`. A exceção aceita (decisão de 05/10/2026) é a do
 *     `ler_tela`: abrir uma tela pelo MCP equivale a abri-la no navegador,
 *     inclusive o aquecimento de cache que algumas telas disparam.
 *
 * As que GRAVAM (decisão de 06/10/2026) estendem {@see FerramentaDeEscrita} e
 * gravam só pelo formulário da própria tela.
 *
 * O `handle()` é fixo: confere o perfil, roda `consultar()`, transforma erro
 * em mensagem legível e grava o log de acesso — com ou sem erro.
 */
abstract class FerramentaEcf extends Tool
{
    public const LIMITE_PADRAO = 25;

    public const LIMITE_MAXIMO = 100;

    private const ERRO_INTERNO = 'Erro interno ao consultar o ECF Admin. Tente de novo em instantes.';

    /** Mesma régua da rota da tela de origem. */
    abstract protected function podeUsar(User $usuario): bool;

    /**
     * A consulta em si. Lança {@see ErroDaFerramenta} para erro previsto
     * ("CUST não encontrado"); qualquer outra exceção vira erro genérico.
     *
     * @return array<string, mixed>
     */
    abstract protected function consultar(Request $request, User $usuario): array;

    public function shouldRegister(Request $request): bool
    {
        $usuario = $request->user();

        return $usuario instanceof User && $this->podeUsar($usuario);
    }

    /**
     * Anotações MCP fixas: só leitura, sem efeito colateral, repetível.
     * Sobrescrito aqui porque o pacote lê os atributos só da classe concreta —
     * um `#[IsReadOnly]` nesta base não chegaria às filhas.
     *
     * @return array<string, bool>
     */
    public function annotations(): array
    {
        return [
            'readOnlyHint'    => true,
            'destructiveHint' => false,
            'idempotentHint'  => true,
            'openWorldHint'   => false,
        ];
    }

    final public function handle(Request $request): Response|ResponseFactory
    {
        $inicio  = hrtime(true);
        $usuario = $request->user();
        $erro    = null;

        try {
            if (! $usuario instanceof User || ! $this->podeUsar($usuario)) {
                throw new ErroDaFerramenta('Seu perfil no ECF Admin não tem acesso a esta consulta.');
            }

            $dados = $this->consultar($request, $usuario);

            return $this->responder($dados + ['consultado_em' => now()->toIso8601String()]);
        } catch (ErroDaFerramenta $e) {
            $erro = $e->getMessage();

            return Response::error($erro);
        } catch (ValidationException $e) {
            $erro = collect($e->errors())->flatten()->first() ?: 'Filtro inválido.';

            return Response::error($erro);
        } catch (Throwable $e) {
            report($e);
            $erro = self::ERRO_INTERNO;

            return Response::error($erro);
        } finally {
            $this->registrarAcesso($request, $usuario, $erro, $inicio);
        }
    }

    /**
     * Forma da resposta. Padrão: conteúdo estruturado (o pacote manda o JSON
     * como `structuredContent` E como texto). Ferramenta de payload grande
     * sobrescreve para mandar só o texto — senão o tamanho dobra.
     *
     * @param  array<string, mixed>  $dados
     */
    protected function responder(array $dados): Response|ResponseFactory
    {
        return Response::structured($dados);
    }

    // ═══ Paginação (limite + cursor) ═══

    /**
     * Campos `limite` e `cursor`, iguais em toda ferramenta que lista.
     *
     * @return array<string, mixed>
     */
    protected function schemaPaginacao(JsonSchema $schema): array
    {
        return [
            'limite' => $schema->integer()
                ->description('Quantos itens devolver por página (1 a '.self::LIMITE_MAXIMO.'). Padrão: '.self::LIMITE_PADRAO.'.')
                ->min(1)
                ->max(self::LIMITE_MAXIMO),
            'cursor' => $schema->string()
                ->description('Para buscar a próxima página: repita a chamada com o valor de `proximo_cursor` da resposta anterior, mantendo os mesmos filtros.'),
        ];
    }

    /**
     * Fatia uma coleção já filtrada.
     *
     * @return array{total:int, itens:array<int, mixed>, proximo_cursor:?string}
     */
    protected function paginar(iterable $itens, Request $request): array
    {
        [$deslocamento, $limite] = $this->janela($request);
        $todos = collect($itens)->values();

        return [
            'total'          => $todos->count(),
            'itens'          => $todos->slice($deslocamento, $limite)->values()->all(),
            'proximo_cursor' => $deslocamento + $limite < $todos->count()
                ? $this->cursorPara($deslocamento + $limite)
                : null,
        ];
    }

    /**
     * Pagina no BANCO — para tabela grande, em que trazer tudo para filtrar na
     * coleção pesaria (sugadores acumulam milhares de linhas).
     *
     * @param  callable(mixed): array<string, mixed>  $linha
     * @return array{total:int, itens:array<int, mixed>, proximo_cursor:?string}
     */
    protected function paginarConsulta(Builder $consulta, Request $request, callable $linha): array
    {
        [$deslocamento, $limite] = $this->janela($request);
        $total = (clone $consulta)->toBase()->getCountForPagination();

        $itens = $consulta->offset($deslocamento)->limit($limite)->get()->map($linha)->values()->all();

        return [
            'total'          => $total,
            'itens'          => $itens,
            'proximo_cursor' => $deslocamento + $limite < $total ? $this->cursorPara($deslocamento + $limite) : null,
        ];
    }

    /** @return array{0:int, 1:int} [deslocamento, limite] */
    protected function janela(Request $request): array
    {
        $limite = (int) ($request->get('limite') ?? self::LIMITE_PADRAO);
        $limite = max(1, min(self::LIMITE_MAXIMO, $limite));

        $cursor = $request->get('cursor');
        if ($cursor === null || $cursor === '') {
            return [0, $limite];
        }

        $decodificado = base64_decode(strtr((string) $cursor, '-_', '+/'), true);
        if ($decodificado === false || ! preg_match('/^d:(\d+)$/', $decodificado, $m)) {
            throw new ErroDaFerramenta('Cursor inválido. Refaça a consulta sem `cursor` para voltar à primeira página.');
        }

        return [(int) $m[1], $limite];
    }

    protected function cursorPara(int $deslocamento): string
    {
        return rtrim(strtr(base64_encode('d:'.$deslocamento), '+/', '-_'), '=');
    }

    // ═══ Apoio ═══

    /** Texto opcional do argumento, aparado; `null` quando veio vazio. */
    protected function texto(Request $request, string $chave): ?string
    {
        $valor = $request->get($chave);
        if ($valor === null || is_array($valor)) {
            return null;
        }

        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    /** Booleano opcional do argumento (aceita true/false, 1/0, "sim"/"nao"). */
    protected function booleano(Request $request, string $chave): bool
    {
        $valor = $request->get($chave);

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN) || in_array($valor, ['sim', 's'], true);
    }

    /** Inteiro opcional do argumento; `null` quando não veio ou não é número. */
    protected function inteiro(Request $request, string $chave): ?int
    {
        $valor = $request->get($chave);

        return is_numeric($valor) ? (int) $valor : null;
    }

    /**
     * Grava o log de acesso. Falhar aqui nunca derruba a resposta — mas fica
     * no laravel.log, porque log de acesso que some calado não é log.
     */
    private function registrarAcesso(Request $request, ?object $usuario, ?string $erro, int $inicio): void
    {
        try {
            McpAcesso::create([
                'user_id'    => $usuario instanceof User ? $usuario->id : null,
                'cliente'    => $usuario instanceof User ? $this->nomeDoCliente($usuario) : null,
                'ferramenta' => $this->name(),
                'argumentos' => $request->all() ?: null,
                'sucesso'    => $erro === null,
                'erro'       => $erro === null ? null : Str::limit($erro, 495),
                'duracao_ms' => (int) round((hrtime(true) - $inicio) / 1_000_000),
                'ip'         => request()?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[MCP] falha ao gravar log de acesso', [
                'ferramenta' => $this->name(),
                'user_id'    => $usuario instanceof User ? $usuario->id : null,
                'erro'       => $e->getMessage(),
            ]);
        }
    }

    /** Nome do cliente OAuth do token em uso (claude.ai, Inspector...). */
    private function nomeDoCliente(User $usuario): ?string
    {
        try {
            $clienteId = $usuario->token()?->oauth_client_id;
            if (! $clienteId) {
                return null;
            }

            $nome = \Laravel\Passport\Passport::client()->newQuery()->whereKey($clienteId)->value('name');

            return $nome ? Str::limit((string) $nome, 115) : (string) $clienteId;
        } catch (Throwable) {
            return null;
        }
    }
}
