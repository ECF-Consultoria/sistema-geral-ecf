<?php

namespace App\Mcp\Telas;

use App\Mcp\ErroDaFerramenta;

/**
 * Transforma os dados de uma tela (props do Inertia) em algo que cabe numa
 * resposta do MCP: resumo da estrutura, navegação por caminho
 * (`empresas.0.contratos`), paginação de lista e teto de tamanho.
 *
 * Dados pessoais vêm como a tela mostra (decisão do usuário em 05/10/2026). A
 * única coisa ocultada é CREDENCIAL — senha, segredo, token de API/OAuth —,
 * que uma tela não deveria carregar e, se carregar por engano, não deve sair
 * do sistema.
 */
final class LeitorDeDados
{
    /** Props que o HandleInertiaRequests põe em TODA tela — ruído para quem consulta. */
    public const COMPARTILHADOS = [
        'auth', 'flash', 'errors', 'asset_url', 'csrf_token',
        'sugadores_pendentes', 'notificacoes_nao_lidas', 'alertas_criticos_count',
    ];

    /** Teto da resposta, em bytes de JSON. */
    public const BYTES_MAX = 60_000;

    /** Prop até este tamanho vem inteiro já no resumo. */
    private const BYTES_INLINE = 2_500;

    private const CREDENCIAL = '/^(password|senha|remember_token|secret|client_secret|access_token|refresh_token|api_key|apikey|api_token|token_secret|private_key|two_factor_secret|two_factor_recovery_codes)$|_(secret|password|api_key)$/i';

    /** @param array<string, mixed> $props */
    public function semCompartilhados(array $props): array
    {
        return array_diff_key($props, array_flip(self::COMPARTILHADOS));
    }

    public function ocultarCredenciais(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }

        foreach ($valor as $chave => $item) {
            $valor[$chave] = is_string($chave) && preg_match(self::CREDENCIAL, $chave) && $item !== null
                ? '[oculto]'
                : $this->ocultarCredenciais($item);
        }

        return $valor;
    }

    /**
     * Valor no caminho `a.b.0.c`. Erro legível com as chaves que existem.
     *
     * @param  array<string, mixed>  $dados
     */
    public function navegar(array $dados, string $caminho): mixed
    {
        $atual = $dados;
        $percorrido = [];

        foreach (explode('.', $caminho) as $parte) {
            if (! is_array($atual) || ! array_key_exists($parte, $atual)) {
                $onde = $percorrido ? implode('.', $percorrido) : 'a tela';
                $opcoes = is_array($atual)
                    ? (array_is_list($atual) ? 'índices 0 a '.(count($atual) - 1) : implode(', ', array_slice(array_map('strval', array_keys($atual)), 0, 40)))
                    : 'nenhum (é um valor simples)';

                throw new ErroDaFerramenta("Campo \"{$parte}\" não existe em {$onde}. Disponíveis: {$opcoes}.");
            }
            $atual = $atual[$parte];
            $percorrido[] = $parte;
        }

        return $atual;
    }

    /**
     * Resumo de um objeto: o que é pequeno vem inteiro, o que é grande vem
     * descrito (tipo, tamanho, chaves) para ser pedido por `campo`.
     *
     * @param  array<string, mixed>  $objeto
     * @return array{valores:array<string,mixed>, grandes:array<string,mixed>}
     */
    public function resumir(array $objeto): array
    {
        $valores = [];
        $grandes = [];
        $usado = 0;

        foreach ($objeto as $chave => $valor) {
            $bytes = $this->bytes($valor);
            if ($bytes <= self::BYTES_INLINE && $usado + $bytes <= self::BYTES_MAX / 2) {
                $valores[$chave] = $valor;
                $usado += $bytes;
            } else {
                $grandes[$chave] = $this->descrever($valor) + ['bytes' => $bytes];
            }
        }

        return ['valores' => $valores, 'grandes' => $grandes];
    }

    /** @return array<string, mixed> */
    public function descrever(mixed $valor): array
    {
        if (! is_array($valor)) {
            return ['tipo' => get_debug_type($valor)];
        }

        if (array_is_list($valor)) {
            $primeiro = $valor[0] ?? null;

            return array_filter([
                'tipo'          => 'lista',
                'itens'         => count($valor),
                'chaves_do_item' => is_array($primeiro) && ! array_is_list($primeiro) ? array_slice(array_keys($primeiro), 0, 40) : null,
            ], fn ($v) => $v !== null);
        }

        // Paginador do Laravel serializado ({data, current_page, total...}):
        // a tela pagina no servidor — a próxima página é `filtros.page`.
        if (array_key_exists('data', $valor) && array_key_exists('current_page', $valor)) {
            return [
                'tipo'          => 'lista paginada pela tela',
                'pagina'        => $valor['current_page'],
                'ultima_pagina' => $valor['last_page'] ?? null,
                'total'         => $valor['total'] ?? null,
                'itens_nesta_pagina' => is_array($valor['data']) ? count($valor['data']) : null,
                'dica'          => 'Os itens estão em "<campo>.data"; outras páginas com filtros {"page": N}.',
            ];
        }

        return ['tipo' => 'objeto', 'chaves' => array_slice(array_map('strval', array_keys($valor)), 0, 60)];
    }

    /**
     * Fatia de uma lista que caiba no teto. Se `limite` itens não couberem,
     * o tamanho da página cai até caber — e a resposta diz quanto ficou.
     *
     * @param  array<int, mixed>  $lista
     * @return array{itens:array<int,mixed>, total:int, limite_usado:int, proximo_deslocamento:?int}
     */
    public function fatiar(array $lista, int $deslocamento, int $limite): array
    {
        $total = count($lista);
        $tamanho = max(1, $limite);

        do {
            $itens = array_slice($lista, $deslocamento, $tamanho);
            $cabe = $this->bytes($itens) <= self::BYTES_MAX;
            if (! $cabe) {
                $tamanho = intdiv($tamanho, 2);
            }
        } while (! $cabe && $tamanho >= 1);

        if (! $cabe) {
            throw new ErroDaFerramenta('Um único item desta lista passa do tamanho máximo de resposta. Peça um campo mais específico, por exemplo "<lista>.'.$deslocamento.'.<chave>".');
        }

        $proximo = $deslocamento + count($itens);

        return [
            'itens'                => $itens,
            'total'                => $total,
            'limite_usado'         => count($itens),
            'proximo_deslocamento' => $proximo < $total ? $proximo : null,
        ];
    }

    public function bytes(mixed $valor): int
    {
        return strlen((string) json_encode($valor, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
    }
}
