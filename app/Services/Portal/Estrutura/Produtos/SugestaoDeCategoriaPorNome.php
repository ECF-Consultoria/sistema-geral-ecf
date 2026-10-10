<?php

namespace App\Services\Portal\Estrutura\Produtos;

use App\Services\Incubadora\Publicador\CategoriaSugestaoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Sugestão de categoria para os NOMES de categoria digitados na planilha (09/10/2026): a
 * prévia da importação agrupa os produtos pelo nome digitado (24 nomes para 56 produtos) e a
 * tela pede a sugestão de cada nome, em blocos, enquanto a pessoa já olha a prévia.
 *
 * A regra da sugestão é a MESMA do "Sugerir categorias" da lista de produtos
 * (`PortalEstruturaProdutosController::sugerirCategorias`): o preditor do catálogo, por app
 * token (dado público, nunca o token do cliente), e a primeira candidata que é folha. Nada é
 * gravado aqui: só a pessoa confirma.
 *
 * ### Limite e cache
 * Até `MAX_POR_PEDIDO` nomes por chamada (o preditor é uma consulta por nome, em série). A
 * sugestão achada fica `TTL` no cache, pela chave do nome sem caixa nem acento — a mesma
 * planilha enviada de novo, ou o mesmo nome em outra empresa, sai pronta. Nome sem sugestão
 * NÃO é guardado: na próxima vez o catálogo é consultado de novo.
 */
class SugestaoDeCategoriaPorNome
{
    public const MAX_POR_PEDIDO = 10;

    /** O tamanho que a busca de categoria aceita (o mesmo da rota de busca). */
    public const MIN_TEXTO = 2;
    public const MAX_TEXTO = 120;

    private const TTL = 604800; // 7 dias, como a leitura das categorias

    private const PREFIXO = 'portal:estrutura:categoria-por-nome:v1:';

    public function __construct(private CategoriaSugestaoService $categorias) {}

    public static function chaveDoCache(string $nome): string
    {
        return self::PREFIXO.md5(ListasDaEmpresaService::chave($nome));
    }

    /**
     * @param  list<string>  $nomes
     * @return array{sugestoes: list<array{texto: string, sugestao: ?array{id: string, nome: string, caminho_texto: string}}>, indisponivel: bool}
     */
    public function sugerir(array $nomes): array
    {
        $saida = [];
        $vistos = [];
        $falhou = false;

        foreach (array_slice($nomes, 0, self::MAX_POR_PEDIDO) as $nome) {
            $texto = mb_substr(trim((string) $nome), 0, self::MAX_TEXTO);
            $chave = ListasDaEmpresaService::chave($texto);
            if (mb_strlen($texto) < self::MIN_TEXTO || isset($vistos[$chave])) {
                continue;
            }
            $vistos[$chave] = true;

            $guardada = Cache::get(self::chaveDoCache($texto));
            if (is_array($guardada) && isset($guardada['id'], $guardada['nome'])) {
                $saida[] = ['texto' => $texto, 'sugestao' => $guardada];
                continue;
            }

            $achada = null;
            try {
                foreach ($this->categorias->sugerir($texto) as $c) {
                    if ($this->categorias->detalhe($c['id'])['folha'] ?? false) {
                        $achada = [
                            'id'            => (string) $c['id'],
                            'nome'          => (string) $c['nome'],
                            'caminho_texto' => implode(' > ', array_column($c['caminho'] ?? [], 'nome')),
                        ];
                        break;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[Estrutura Produtos] sugestão de categoria por nome falhou', ['erro' => $e->getMessage()]);
                $falhou = true;
            }

            if ($achada !== null) {
                Cache::put(self::chaveDoCache($texto), $achada, self::TTL);
            }
            $saida[] = ['texto' => $texto, 'sugestao' => $achada];
        }

        return [
            'sugestoes'    => $saida,
            'indisponivel' => $falhou || ($saida !== [] && collect($saida)->every(fn ($s) => $s['sugestao'] === null)),
        ];
    }
}
