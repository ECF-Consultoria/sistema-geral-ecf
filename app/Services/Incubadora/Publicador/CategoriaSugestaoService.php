<?php

namespace App\Services\Incubadora\Publicador;

use App\Services\Mlb\Publicacao\MlCatalogoMetaService;
use App\Services\MlColetaService;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Publicador da Incubadora — passo 1: do NOME do produto às categorias
 * candidatas do Mercado Livre, cada uma com o caminho inteiro da árvore.
 *
 * O caminho aparece ANTES de escolher porque categorias de nome parecido só
 * se distinguem pela árvore (mesma lição do Anunciar do Portal, 29/09). Cada
 * nível do caminho leva o id: a tela usa para buscar os termos de um nível
 * acima quando a folha é de nicho e traz poucos termos.
 *
 * Dados públicos → app token (`MlColetaService::getAppToken`), nunca o token
 * de uma empresa. O cache de `GET /categories/{id}` é o MESMO do
 * `MlCatalogoMetaService` (`ml_meta_categoria_{id}`, 7 dias): o que o wizard
 * admin ou o Portal já leram sai pronto daqui, e vice-versa.
 */
class CategoriaSugestaoService
{
    private const API_BASE = 'https://api.mercadolibre.com';

    // Mesma chave e TTL do MlCatalogoMetaService::categoria() — cache compartilhado.
    private const CACHE_CATEGORIA = 'ml_meta_categoria_';
    private const TTL_CATEGORIA = 604800;

    public function __construct(
        private MlCatalogoMetaService $meta,
        private MlColetaService $coleta,
    ) {}

    /**
     * Categorias candidatas para o nome do produto (preditor do ML, até 8).
     * Uma categoria cujo caminho falhou volta com `caminho` vazio — a lista
     * não cai por causa de uma.
     *
     * @return array<int, array{id: string, nome: string, dominio: ?string, caminho: array<int, array{id: string, nome: string}>}>
     */
    public function sugerir(string $produto): array
    {
        $candidatos = [];

        foreach ($this->meta->preverCategoria($produto) as $c) {
            $id = (string) ($c['category_id'] ?? '');
            if ($id === '' || isset($candidatos[$id])) {
                continue;
            }
            $candidatos[$id] = [
                'id'      => $id,
                'nome'    => (string) ($c['category_name'] ?? $id),
                'dominio' => $c['domain_name'] ?? null,
            ];
        }

        $metas = $this->categoriasEmLote(array_keys($candidatos));

        return array_values(array_map(fn (array $c) => [
            ...$c,
            'caminho' => $this->caminho($metas[$c['id']] ?? []),
        ], $candidatos));
    }

    /**
     * Uma categoria pronta para a tela: nome, caminho com id por nível e se é
     * folha (só folha recebe anúncio). Null quando o ML não devolve a categoria.
     *
     * @return array{id: string, nome: string, caminho: array<int, array{id: string, nome: string}>, folha: bool}|null
     */
    public function detalhe(string $categoriaId): ?array
    {
        $cat = $this->categoriasEmLote([$categoriaId])[$categoriaId] ?? [];
        if (! $cat) {
            return null;
        }

        return [
            'id'      => (string) ($cat['id'] ?? $categoriaId),
            'nome'    => (string) ($cat['name'] ?? $categoriaId),
            'caminho' => $this->caminho($cat),
            'folha'   => empty($cat['children_categories']),
        ];
    }

    /**
     * `detalhe()` de várias categorias numa leitura só (cache primeiro, o resto em
     * paralelo). Usado pelo cadastro de Produtos do Portal para validar as categorias
     * de um lote ANTES de abrir a transação (Fase 167, BE-WR-05). Método novo: não
     * muda `detalhe()` nem `sugerir()`.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array{id: string, nome: string, caminho: array<int, array{id: string, nome: string}>, folha: bool}|null>
     */
    public function detalhes(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $ids), fn ($id) => $id !== '')));
        if ($ids === []) {
            return [];
        }

        $saida = [];
        foreach ($this->categoriasEmLote($ids) as $id => $cat) {
            $saida[$id] = $cat ? [
                'id'      => (string) ($cat['id'] ?? $id),
                'nome'    => (string) ($cat['name'] ?? $id),
                'caminho' => $this->caminho($cat),
                'folha'   => empty($cat['children_categories']),
            ] : null;
        }

        return $saida;
    }

    /** @return array<int, array{id: string, nome: string}> */
    private function caminho(array $categoria): array
    {
        $niveis = [];
        foreach ((array) ($categoria['path_from_root'] ?? []) as $nivel) {
            $id = (string) ($nivel['id'] ?? '');
            $nome = (string) ($nivel['name'] ?? '');
            if ($id !== '' && $nome !== '') {
                $niveis[] = ['id' => $id, 'nome' => $nome];
            }
        }

        return $niveis;
    }

    /**
     * `GET /categories/{id}` de várias categorias de uma vez: o que está no
     * cache sai de lá; o resto é lido em PARALELO (`Http::pool`) e gravado no
     * cache compartilhado. Oito sugestões em série custavam ~1,5 s; em
     * paralelo, o tempo de uma.
     *
     * Falha de uma entra vazia e NÃO é cacheada. Por isso nem a leitura de
     * uma categoria só passa por `MlCatalogoMetaService::categoria()`: lá o
     * `Cache::remember` guarda o `[]` de uma falha passageira por 7 dias.
     * Pelo mesmo motivo, `[]` encontrado no cache conta como ausente.
     *
     * @param  array<int, string>  $ids
     * @return array<string, array>
     */
    private function categoriasEmLote(array $ids): array
    {
        $saida = [];
        $faltam = [];

        foreach ($ids as $id) {
            $cache = Cache::get(self::CACHE_CATEGORIA.$id);
            if (is_array($cache) && $cache) {
                $saida[$id] = $cache;
            } else {
                $faltam[] = $id;
            }
        }

        if ($faltam) {
            try {
                $token = $this->coleta->getAppToken();
                $respostas = Http::pool(fn (Pool $pool) => array_map(
                    fn ($id) => $pool->as($id)->withToken($token)->timeout(15)->get(self::API_BASE."/categories/{$id}"),
                    $faltam,
                ));
            } catch (\Throwable $e) {
                Log::warning('[Publicador Incubadora] leitura em lote de categorias falhou: '.$e->getMessage());
                $respostas = [];
            }

            foreach ($faltam as $id) {
                $r = $respostas[$id] ?? null;
                if ($r instanceof Response && $r->successful() && is_array($r->json())) {
                    $saida[$id] = (array) $r->json();
                    Cache::put(self::CACHE_CATEGORIA.$id, $saida[$id], self::TTL_CATEGORIA);
                } else {
                    $saida[$id] = [];
                }
            }
        }

        return $saida;
    }
}
