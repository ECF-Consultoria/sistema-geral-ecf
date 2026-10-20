<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Services\Publicador\PalavrasChaveService;
use Illuminate\Support\Facades\Log;

/**
 * O título de venda da ficha do produto no Portal (19/10/2026): os termos mais buscados da
 * categoria e o limite de caracteres do título.
 *
 * Os termos são os MESMOS do editor do Publicador (`PalavrasChaveService::termosDaCategoria`):
 * a lista da semana da categoria, completada com a da categoria de cima quando ela tem poucos.
 * O limite é o `max_title_length` da categoria ({@see LimiteDoTitulo}).
 *
 * Sigilo do Portal (learnings portal-do-cliente §34): a tela recebe só o que mostra — o termo, a
 * posição, o grupo, a pista de relacionado e o nome da categoria de cima. Nada de id de
 * categoria, link de busca nem a mensagem do serviço, que citam a plataforma.
 *
 * Falha na leitura dos termos NÃO é erro para o cliente: volta `indisponivel` e a ficha deixa
 * digitar o título à mão.
 */
class TituloDeVendaService
{
    public function __construct(
        private PalavrasChaveService $palavras,
        private LimiteDoTitulo $limite,
    ) {}

    /**
     * @return array{limite: int, indisponivel: bool, total?: int, categoria?: array{caminho: list<string>}, termos?: list<array{posicao: int, termo: string, grupo: ?string, relacionado: bool, origem: string}>, acima?: ?array{nome: string, total: int}}
     */
    public function termos(string $categoria, string $nome): array
    {
        $limite = $this->limite->daCategoria($categoria) ?? LimiteDoTitulo::PADRAO;

        try {
            $lido = $this->palavras->termosDaCategoria($categoria, $nome);
        } catch (\Throwable $e) {
            Log::warning('[Estrutura Produtos] termos do título indisponíveis', ['categoria' => $categoria, 'erro' => $e->getMessage()]);

            return ['limite' => $limite, 'indisponivel' => true];
        }

        return [
            'limite' => $limite,
            'indisponivel' => false,
            'total' => (int) ($lido['total'] ?? 0),
            'categoria' => ['caminho' => array_values(array_map('strval', (array) ($lido['categoria']['caminho'] ?? [])))],
            'termos' => array_values(array_map(fn (array $t) => [
                'posicao' => (int) $t['posicao'],
                'termo' => (string) $t['termo'],
                'grupo' => $t['grupo'] ?? null,
                'relacionado' => (bool) ($t['relacionado'] ?? false),
                'origem' => (string) ($t['origem'] ?? 'categoria'),
            ], (array) ($lido['termos'] ?? []))),
            'acima' => ($lido['acima'] ?? null) !== null
                ? ['nome' => (string) $lido['acima']['nome'], 'total' => (int) $lido['acima']['total']]
                : null,
        ];
    }
}
